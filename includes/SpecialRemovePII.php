<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Config;
use ConfigFactory;
use ExtensionRegistry;
use FormSpecialPage;
use GlobalRenameUser;
use GlobalRenameUserDatabaseUpdates;
use GlobalRenameUserLogger;
use GlobalRenameUserStatus;
use GlobalRenameUserValidator;
use Html;
use JobQueueGroup;
use MediaWiki\User\UserGroupManager;
use Status;
use User;

class SpecialRemovePII extends FormSpecialPage {
	/** @var Config */
	private $config;
	
	/** @var UserGroupManager */
	private $userGroupManager;

	/**
	 * @param ConfigFactory $configFactory
	 * @param UserGroupManager $userGroupManager
	 */
	public function __construct(
		ConfigFactory $configFactory,
		UserGroupManager $userGroupManager
	) {
		parent::__construct( 'RemovePII', 'handle-pii' );

		$this->config = $configFactory->makeConfig( 'RemovePII' );
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		$formDescriptor = [];

		if (
			$this->config->get( 'RemovePIICentralWiki' ) &&
			$this->config->get( 'DBname' ) !== $this->config->get( 'RemovePIICentralWiki' )
		) {
			$formDescriptor['disabled'] = [
				'type' => 'info',
				'help-message' => 'removepii-wiki-disabled',
			];

			return $formDescriptor;
		}
		
		$formDescriptor['warning'] = [
			'type' => 'info',
			'help' => Html::warningBox( $this->msg( 'removepii-warning-dangerous' )->parse() ),
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
				'RemovePII' => 'removepii',
				'Rename User' => 'rename'
			],
			'required' => true,
			'label-message' => 'removepii-action-label',
			'help-message' => 'removepii-action-help'
		];

		return $formDescriptor;
	}

	public function validate( array $data ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Renameuser' ) ) {
			return Status::newFatal( 'centralauth-rename-notinstalled' );
		}

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return Status::newFatal( 'removepii-centralauth-notinstalled' );
		}

		$oldUser = User::newFromName( $data['oldname'] );
		if ( !$oldUser ) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		if ( $oldUser->getName() === $this->getUser()->getName() ) {
			return Status::newFatal( 'centralauth-rename-cannotself' );
		}

		$newUser = User::newFromName( $data['newname'] );
		if ( !$newUser ) {
			return Status::newFatal( 'centralauth-rename-badusername' );
		}

		$validator = new GlobalRenameUserValidator();
		$status = $validator->validate( $oldUser, $newUser );

		return $status;
	}

	/**
	 * @param array $formData
	 * @return bool
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		if ( $formData['action'] === 'rename' ) {
			$valid = $this->validate( $formData );
			if ( !$valid->isOK() ) {
				return $valid;
			}

			$oldUser = User::newFromName( $formData['oldname'] );
			$newUser = User::newFromName( $formData['newname'], 'creatable' );

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
				new GlobalRenameUserLogger( $this->getUser() ),
				$session
			);

			$globalRenameUser->rename(
				array_merge( [
					'movepages' => true,
					'suppressredirects' => true,
					'reason' => null,
					'force' => true
				], $formData )
			);

			return true;
		} elseif ( $formData['action'] === 'removepii' ) {
			$jobParams = [
				'oldName' => $formData['oldname'],
				'newName' => $formData['newname'],
			];

			$oldCentral = CentralAuthUser::getInstanceByName( $formData['oldname'] );

			$newCentral = CentralAuthUser::getInstanceByName( $formData['newname'] );

			if ( $oldCentral->renameInProgress() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-renameuser-global-inprogress', $formData['oldname'] )->escaped()
					)
				);

				return false;
			}

			if ( $newCentral->renameInProgress() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-renameuser-global-inprogress', $formData['newname'] )->escaped()
					)
				);

				return false;
			}

			if ( !$newCentral->exists() ) {
				$out->addHTML(
					Html::errorBox(
						$this->msg( 'centralauth-admin-status-nonexistent', $formData['newname'] )->escaped()
					)
				);

				return false;
			}

			// Invalidate cache before we begin transaction
			$newCentral->invalidateCache();

			// Delay cache invalidation until we finish transaction
			$newCentral->startTransaction();

			// Run RemovePIIJob on all attached wikis
			// todo: does this include deleted wikis?
			foreach ( $newCentral->listAttached() as $database ) {
				$jobParams['database'] = $database;

				JobQueueGroup::singleton()->push(
					new RemovePIIJob(
						null,
						$jobParams,
						$this->userGroupManager,
						$this->getOutput()
					)
				);
			}

			$user = User::newFromName( $formData['newname'] );

			// Remove user email
			$user->invalidateEmail();
			$user->saveSettings();

			// Lock global account
			$newCentral->adminLock();

			// End transaction, enable cache invalidation again
			$newCentral->endTransaction();

			return true;
		}
		
		return false;
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
