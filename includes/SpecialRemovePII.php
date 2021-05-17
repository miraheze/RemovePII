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
		$out = $this->getOutput();

		$out->addHTML(
			Html::warningBox(
				$this->msg( 'removepii-warning-dangerous' )
			)
		);

		$formDescriptor = [];

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

		return $formDescriptor;
	}

	public function validate( array $data ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Renameuser' ) ) {
			return Status::newFatal( 'centralauth-rename-notinstalled' );
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

		$globalRenameUser->rename( $formData );

		$jobParams = [
			'oldName' => $formData['oldname'],
			'newName' => $formData['newname'],
		];

		$oldCentral = CentralAuthUser::getInstanceByName( $formData['oldname'] );

		$newCentral = CentralAuthUser::getInstanceByName( $formData['newname'] );

		if ( $oldCentral->renameInProgress() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-renameuser-global-inprogress', $formData['oldname'] )
				)
			);

			return false;
		}

		if ( $newCentral->renameInProgress() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-renameuser-global-inprogress', $formData['newname'] )
				)
			);

			return false;
		}

		if ( !$newCentral->exists() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-admin-status-nonexistent', $formData['newname'] )
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
					$this->userGroupManager,
					$this->getOutput(),
					$jobParams
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

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
