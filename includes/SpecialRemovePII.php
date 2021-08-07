<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Config;
use ConfigFactory;
use ExtensionRegistry;
use FormSpecialPage;
use GlobalRenameUser;
use GlobalRenameUserDatabaseUpdates;
use GlobalRenameUserStatus;
use GlobalRenameUserValidator;
use Html;
use JobQueueGroup;
use ManualLogEntry;
use MediaWiki\User\UserFactory;
use Status;
use TitleFactory;
use WikiMap;

class SpecialRemovePII extends FormSpecialPage {
	/** @var Config */
	private $config;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ConfigFactory $configFactory
	 * @param TitleFactory $titleFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		TitleFactory $titleFactory,
		UserFactory $userFactory
	) {
		parent::__construct( 'RemovePII', 'handle-pii' );

		$this->config = $configFactory->makeConfig( 'RemovePII' );
		$this->titleFactory = $titleFactory;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 * @return string
	 */
	public function execute( $par ) {
		$this->getOutput()->disallowUserJs();
		$this->checkPermissions();

		if (
			$this->config->get( 'RemovePIICentralWiki' ) &&
			!WikiMap::isCurrentWikiId( $this->config->get( 'RemovePIICentralWiki' ) )
		) {
			return $this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'removepii-wiki-disabled' )->escaped() )
			);
		}

		parent::execute( $par );
	}

	/**
	 * @return array|string
	 */
	protected function getFormFields() {
		$formDescriptor = [];
		
		$formDescriptor['warning'] = [
			'type' => 'info',
			'help' => Html::warningBox( $this->msg( 'removepii-warning-irreversible' )->parse() ),
			'raw-help' => true,
			'hide-if' => [ '!==', 'wpaction', 'removepii' ]
		];

		$formDescriptor['oldname'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'removepii-oldname-label'
		];

		$formDescriptor['newname'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'removepii-newname-label'
		];
		
		$formDescriptor['action'] = [
			'type' => 'select',
			'options' => [
				'Rename user' => 'renameuser',
				'RemovePII' => 'removepii'
			],
			'required' => true,
			'default' => 'renameuser',
			'label-message' => 'removepii-action-label',
			'help-message' => 'removepii-action-help'
		];

		return $formDescriptor;
	}

	/**
	 * @param array $formData
	 * @return Status
	 */
	public function validate( array $formData ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return Status::newFatal( 'removepii-centralauth-notinstalled' );
		}

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Renameuser' ) ) {
			return Status::newFatal( 'centralauth-rename-notinstalled' );
		}

		$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
		if ( !$oldUser ) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		$oldCentral = CentralAuthUser::getMasterInstanceByName( $formData['oldname'] );
		$canOversight = $this->getUser() && $this->getUser()->isAllowed( 'centralauth-oversight' );

		if ( ( $oldCentral->isOversighted() || $oldCentral->isHidden() ) &&
			!$canOversight
		) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		if ( $oldUser->getName() === $this->getUser()->getName() ) {
			return Status::newFatal( 'centralauth-rename-cannotself' );
		}

		$newUser = $this->userFactory->newFromName( $formData['newname'] );
		if ( !$newUser ) {
			return Status::newFatal( 'centralauth-rename-badusername' );
		}

		$validator = new GlobalRenameUserValidator();
		return $validator->validate( $oldUser, $newUser );
	}

	/**
	 * @param array $formData
	 * @return bool|Status
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		if ( $formData['action'] === 'renameuser' ) {
			$valid = $this->validate( $formData );
			if ( !$valid->isOK() ) {
				return $valid;
			}

			$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
			$newUser = $this->userFactory->newFromName( $formData['newname'], UserFactory::RIGOR_CREATABLE );

			$session = $this->getContext()->exportSession();
			$globalRenameUser = new GlobalRenameUser(
				$this->getUser(),
				$oldUser,
				CentralAuthUser::getInstance( $oldUser ),
				$newUser,
				CentralAuthUser::getInstance( $newUser ),
				new GlobalRenameUserStatus( $newUser->getName() ),
				'JobQueueGroup::singleton',
				new GlobalRenameUserDatabaseUpdates(),
				new RemovePIIGlobalRenameUserLogger( $this->getUser() ),
				$session
			);

			$globalRenameUser->rename(
				array_merge( [
					'movepages' => false,
					'suppressredirects' => true,
					'reason' => null,
					'force' => true
				], $formData )
			);

			return true;
		} elseif ( $formData['action'] === 'removepii' ) {
			$oldName = str_replace( '_', ' ', $formData['oldname'] );
			$newName = str_replace( '_', ' ', $formData['newname'] );

			$jobParams = [
				'oldname' => $oldName,
				'newname' => $newName,
			];

			$oldCentral = CentralAuthUser::getInstanceByName( $oldName );

			$newCentral = CentralAuthUser::getInstanceByName( $newName );

			if ( $oldCentral->renameInProgress() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-renameuser-global-inprogress', $formData['oldname'] )->parse()
					)
				);

				return false;
			}

			if ( $newCentral->renameInProgress() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-renameuser-global-inprogress', $formData['newname'] )->parse()
					)
				);

				return false;
			}

			if ( !$newCentral->exists() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-admin-status-nonexistent', $formData['newname'] )->parse()
					)
				);

				return false;
			}

			// Run RemovePIIJob on all attached wikis
			foreach ( $newCentral->listAttached() as $database ) {
				JobQueueGroup::singleton( $database )->push(
					new RemovePIIJob( $jobParams )
				);
			}

			$logEntry = new ManualLogEntry( 'removepii', 'action' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $this->titleFactory->newFromText( 'RemovePII', NS_SPECIAL ) );
			$logID = $logEntry->insert();
			$logEntry->publish( $logID );

			return true;
		}
		
		return false;
	}

	public function onSuccess() {
		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'removepii-success' )->escaped() ) );

		$this->getOutput()->addReturnTo(
			$this->titleFactory->newFromText( 'RemovePII', NS_SPECIAL ),
			[],
			$this->msg( 'removepii' )->escaped()
		);
	}

	/**
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @return bool
	 */
	public function isListed() {
		return $this->userCanExecute( $this->getUser() );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikimanage';
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
