<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Config;
use ConfigFactory;
use FormSpecialPage;
use Html;
use JobQueueGroup;
use MediaWiki\User\UserGroupManager;

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

		$formDescriptor['oldName'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'removepii-oldname-label'
		];

		$formDescriptor['newName'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'removepii-newname-label'
		];

		return $formDescriptor;
	}

	/**
	 * @param array $formData
	 * @return bool
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		$jobParams = [
			'oldName' => $formData['oldName'],
			'newName' => $formData['newName'],
		];

		$oldCentral = CentralAuthUser::getInstanceByName( $formData['oldName'] );

		$newCentral = CentralAuthUser::getInstanceByName( $formData['newName'] );

		if ( $oldCentral->renameInProgress() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-renameuser-global-inprogress', $formData['oldName'] )
				)
			);

			return false;
		}

		if ( $newCentral->renameInProgress() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-renameuser-global-inprogress', $formData['newName'] )
				)
			);

			return false;
		}

		if ( !$newCentral->exists() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'centralauth-admin-status-nonexistent', $formData['newName'] )
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

		$dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'CentralAuthDatabase' ) );

		if ( $newCentral->getEmail() ) {
			$dbw->update(
				'globaluser',
				[
					'gu_email' => ''
				],
				[
					'gu_email' => $newCentral->getEmail(),
					'gu_name' => $newCentral->getName()
				],
				__METHOD__
			);
		}

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
