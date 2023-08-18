<?php

namespace Miraheze\RemovePII;

use Config;
use ConfigFactory;
use ExtensionRegistry;
use FormatJson;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use SpecialPage;
use Status;
use WikiMap;


class SpecialRemovePII extends FormSpecialPage {
	/** @var Config */
	private $config;

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param ConfigFactory $configFactory
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ConfigFactory $configFactory,
		HttpRequestFactory $httpRequestFactory,
		JobQueueGroupFactory $jobQueueGroupFactory,
		UserFactory $userFactory
	) {
		parent::__construct( 'RemovePII', 'handle-pii' );

		$this->config = $configFactory->makeConfig( 'RemovePII' );
		$this->httpRequestFactory = $httpRequestFactory;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
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
			$this->config->get( 'RemovePIIAllowedWikis' ) &&
			!in_array(
				WikiMap::getCurrentWikiId(),
				$this->config->get( 'RemovePIIAllowedWikis' )
			)
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
			'class' => \MediaWiki\Extension\CentralAuth\Widget\HTMLGlobalUserTextField::class,
			'required' => true,
			'label-message' => 'removepii-oldname-label',
		];

		$formDescriptor['newname'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => $this->config->get( 'RemovePIIDPAValidationEndpoint' ) ?
				'removepii-dpa_id-label' : 'removepii-newname-label',
			'validation-callback' => [ $this, 'isMatchingAssociatedDPARequest' ],
		];

		$formDescriptor['action'] = [
			'type' => 'select',
			'options' => [
				'Rename user' => 'renameuser',
				'RemovePII' => 'removepii',
			],
			'required' => true,
			'default' => 'renameuser',
			'label-message' => 'removepii-action-label',
			'help-message' => 'removepii-action-help',
		];

		return $formDescriptor;
	}

	/**
	 * @param ?string $value
	 * @param array $alldata
	 * @return bool|string
	 */
	public function isMatchingAssociatedDPARequest( ?string $value, array $alldata ) {
		if ( !$value ) {
			return Status::newFatal( 'htmlform-required' )->getMessage();
		}

		if ( !$this->config->get( 'RemovePIIDPAValidationEndpoint' ) ) {
			return true;
		}

		$value = str_replace( $this->config->get( 'RemovePIIAutoPrefix' ), '', $value );

		$url = $this->config->get( 'RemovePIIDPAValidationEndpoint' );
		$url = str_replace( '{dpa_id}', $value, $url );
		$url = str_replace( '{username}', rawurlencode( $alldata['oldname'] ), $url );

		$report = $this->httpRequestFactory->create( $url );
		$status = $report->execute();
		if ( !$status->isOK() ) {
			return Status::newFatal( 'removepii-invalid-dpa' )->getMessage();
		}

		$content = FormatJson::decode( $report->getContent(), true );
		if ( !( $content['match'] ?? false ) ) {
			return Status::newFatal( 'removepii-invalid-dpa' )->getMessage();
		}

		return true;
	}

	/**
	 * @param array $formData
	 * @return Status
	 */
	public function validateCentralAuth( array $formData ) {
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

		$oldCentral = \MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstanceByName( $formData['oldname'] );
		$canSuppress = $this->getUser() && $this->getUser()->isAllowed( 'centralauth-suppress' );

		if ( ( $oldCentral->isSuppressed() || $oldCentral->isHidden() ) &&
			!$canSuppress
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

		$globalRenameUserValidator = MediaWikiServices::getInstance()->getService(
			'CentralAuth.GlobalRenameUserValidator'
		);
		return $globalRenameUserValidator->validate( $oldUser, $newUser );
	}

	/**
	 * @param array $formData
	 * @return bool|Status
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		if ( $this->config->get( 'RemovePIIAutoPrefix' ) ) {
			$formData['newname'] = str_replace( $this->config->get( 'RemovePIIAutoPrefix' ), '', $formData['newname'] );
			$formData['newname'] = $this->config->get( 'RemovePIIAutoPrefix' ) . $formData['newname'];
		}

		if ( $formData['action'] === 'renameuser' ) {
			$validCentralAuth = $this->validateCentralAuth( $formData );
			if ( !$validCentralAuth->isOK() ) {
				return $validCentralAuth;
			}

			$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
			$newUser = $this->userFactory->newFromName( $formData['newname'], UserFactory::RIGOR_CREATABLE );

			if ( !$oldUser || !$newUser ) {
				return Status::newFatal( 'unknown-error' );
			}

			$caDbManager = MediaWikiServices::getInstance()->getService(
				'CentralAuth.CentralAuthDatabaseManager'
			);

			$session = $this->getContext()->exportSession();
			$globalRenameUser = new \MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser(
				$this->getUser(),
				$oldUser,
				\MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstance( $oldUser ),
				$newUser,
				\MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstance( $newUser ),
				new \MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus( $newUser->getName() ),
				$this->jobQueueGroupFactory,
				new \MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates( $caDbManager ),
				new \MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger( $this->getUser() ),
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
			if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
				return Status::newFatal( 'removepii-centralauth-notinstalled' );
			}

			$oldName = str_replace( '_', ' ', $formData['oldname'] );
			$newName = str_replace( '_', ' ', $formData['newname'] );

			$jobParams = [
				'oldname' => $oldName,
				'newname' => $newName,
			];

			$oldCentral = \MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstanceByName( $oldName );

			$newCentral = \MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstanceByName( $newName );

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
				$this->jobQueueGroupFactory->makeJobQueueGroup( $database )->push(
					new RemovePIIJob( $jobParams )
				);
			}

			$logEntry = new ManualLogEntry( 'removepii', 'action' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( SpecialPage::getTitleValueFor( 'RemovePII' ) );
			$logID = $logEntry->insert();
			$logEntry->publish( $logID );

			return true;
		}

		return false;
	}

	public function onSuccess() {
		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'removepii-success' )->escaped() ) );

		$this->getOutput()->addReturnTo(
			SpecialPage::getTitleValueFor( 'RemovePII' ),
			[],
			$this->msg( 'removepii' )->text()
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
