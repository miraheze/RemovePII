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
use Status;
use User;

class SpecialRemovePII extends FormSpecialPage {
	/** @var Config */
	private $config;

	/**
	 * @param ConfigFactory $configFactory
	 */
	public function __construct( ConfigFactory $configFactory ) {
		parent::__construct( 'RemovePII', 'handle-pii' );

		$this->config = $configFactory->makeConfig( 'RemovePII' );
	}

	/**
	 * @return array|string
	 */
	protected function getFormFields() {
		if (
			$this->config->get( 'RemovePIICentralWiki' ) &&
			$this->config->get( 'DBname' ) !== $this->config->get( 'RemovePIICentralWiki' )
		) {
			return $this->msg( 'removepii-wiki-disabled' )->escaped();
		}

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

		$oldUser = User::newFromName( $formData['oldname'] );
		if ( !$oldUser ) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		if ( $oldUser->getName() === $this->getUser()->getName() ) {
			return Status::newFatal( 'centralauth-rename-cannotself' );
		}

		$newUser = User::newFromName( $formData['newname'] );
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

		if ( $formData['action'] === 'renameuser' ) {
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
				'oldname' => $formData['oldname'],
				'newname' => $formData['newname'],
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

			JobQueueGroup::singleton()->push(
				new RemovePIIInjectionJob( $jobParams )
			);

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
