<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Config;
use ConfigFactory;
use FormSpecialPage;
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
		$jobParams = [
			'oldName' => $formData['oldName'],
			'newName' => $formData['newName'],
		];

		$centralUser = CentralAuthUser::getInstanceByName( $formData['newName'] );

		if ( !$centralUser ) {
			return false;
		}

		// Invalidate cache before we begin transaction
		$centralUser->invalidateCache();

		// Delay cache invalidation until we finish transaction
		$centralUser->startTransaction();

		// Run RemovePIIJob on all attached wikis
		// todo: does this include deleted wikis?
		foreach ( $centralUser->listAttached() as $database ) {
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

		if ( $centralUser->getEmail() ) {
			$dbw->update(
				'globaluser',
				[
					'gu_email' => ''
				],
				[
					'gu_email' => $centralUser->getEmail(),
					'gu_name' => $centralUser->getName()
				],
				__METHOD__
			);
		}

		// Lock global account
		$centralUser->adminLock();

		// End transaction, enable cache invalidation again
		$centralUser->endTransaction();

		return true;
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
