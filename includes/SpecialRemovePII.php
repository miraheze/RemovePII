<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Config;
use ConfigFactory;
use FormSpecialPage;
use Html;
use MediaWiki\User\UserGroupManager;
use Title;
use User;
use WikiPage;

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

		$formDescriptor['OldName'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'removepii-oldname-label'
		];

		$formDescriptor['NewName'] = [
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

		foreach ( $this->config->get( 'LocalDatabases' ) as $database ) {
			$oldName = User::newFromName( $formData['OldName'] );
			$newName = User::newFromName( $formData['NewName'] );
		
			$userOldName = $oldName->getName();
			$userNewName = $newName->getName();
		
			if ( !$newName ) {
				$out->addHTML( Html::errorBox( "User {$userNewName} is not a valid name" ) );
				continue;
			}

			$userId = $newName->getId();

			if ( !$userId ) {
				$out->addHTML( Html::errorBox( "User {$userNewName} id equal to 0" ) );
				continue;
			}

			$dbw = wfGetDB( DB_MASTER, [], $database );

			$userActorId = $newName->getActorId( $dbw );

			$tableUpdates = [
				// Core
				'recentchanges' => [
					[
						'fields' => [
							'rc_ip' => '0.0.0.0'
						],
						'where' => [
							'rc_actor' => $userActorId
						]
					]
				],
				'user' => [
					[
						'fields' => [
							'user_email' => '',
							'user_real_name' => ''
						],
						'where' => [
							'user_name' => $userNewName
						]
					]
				],
				// Extensions
				'abuse_filter_log' => [
					[
						'fields' => [
							'afl_user_text' => $userNewName
						],
						'where' => [
							'afl_user_text' => $userOldName
						]
					]
				],
				'ajaxpoll_vote' => [
					[
						'fields' => [
							'poll_ip' => '0.0.0.0'
						],
						'where' => [
							'poll_actor' => $userActorId
						]
					]
				],
				'Comments' => [
					[
						'fields' => [
							'Comment_IP' => '0.0.0.0'
						],
						'where' => [
							'Comment_actor' => $userActorId
						]
					],
				],
				'echo_event' => [
					[
						'fields' => [
							'event_agent_ip' => NULL
						],
						'where' => [
							'event_agent_id' => $userId
						]
					]
				],
				'flow_tree_revision' => [
					[
						'fields' => [
							'tree_orig_user_ip' => NULL
						],
						'where' => [
							'tree_orig_user_id' => $userId
						]
					]
				],
				'flow_revision' => [
					[
						'fields' => [
							'rev_user_ip' => NULL
						],
						'where' => [
							'rev_user_id' => $userId
						]
					],
					[
						'fields' => [
							'rev_mod_user_ip' => NULL
						],
						'where' => [
							'rev_mod_user_id' => $userId
						]
					],
					[
						'fields' => [
							'rev_edit_user_ip' => NULL
						],
						'where' => [
							'rev_edit_user_id' => $userId
						]
					]
				],
				'moderation' => [
					[
						'fields' => [
							'mod_header_xff' => '',
							'mod_header_ua' => '',
							'mod_ip' => '0.0.0.0'
						],
						'where' => [
							'mod_user' => $userId
						]
					],
					[
						'fields' => [
							'mod_header_xff' => '',
							'mod_header_ua' => '',
							'mod_ip' => '0.0.0.0',
							'mod_user_text' => $userNewName
						],
						'where' => [
							'mod_user_text' => $userOldName
						]
					]
				],
				'report_reports' => [
					[
						'fields' => [
							'report_user_text' => $userNewName
						],
						'where' => [
							'report_user_text' => $userOldName
						]
					],
					[
						'fields' => [
							'report_handled_by_text' => $userNewName
						],
						'where' => [
							'report_handled_by_text' => $userOldName
						]
					]
				],
				'Vote' => [
					[
						'fields' => [
							'vote_ip' => '0.0.0.0',
						],
						'where' => [
							'vote_actor' => $userActorId
						]
					]
				],
				'wikiforum_category' => [
					[
						'fields' => [
							'wfc_added_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wfc_added_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wfc_edited_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wfc_edited_actor' => $userActorId
						]
					],
				],
				'wikiforum_forums' => [
					[
						'fields' => [
							'wff_last_post_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wff_last_post_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wff_added_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wff_added_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wff_edited_user_ip' => '0.0.0.0'
						],
						'where' => [
							'wff_edited_actor' => $userActorId
						]
					],
				],
				'wikiforum_replies' => [
					[
						'fields' => [
							'wfr_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wfr_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wfr_edit_user_ip' => '0.0.0.0',
						],
						'where' => [
							'wfr_edit_actor' => $userActorId
						]
					],
				],
				'wikiforum_threads' => [
					[
						'fields' => [
							'wft_user_ip' => '0.0.0.0'
						],
						'where' => [
							'wft_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wft_edit_user_ip' => '0.0.0.0'
						],
						'where' => [
							'wft_edit_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wft_closed_user_ip' => '0.0.0.0'
						],
						'where' => [
							'wft_closed_actor' => $userActorId
						]
					],
					[
						'fields' => [
							'wft_last_post_user_ip' => '0.0.0.0'
						],
						'where' => [
							'wft_last_post_actor' => $userActorId
						]
					]
				],
			];

			if ( $dbw->tableExists( 'user_profile' ) ) {
				$dbw->delete(
					'user_profile',
					[
						'up_actor' => $userActorId
					]
				);
			}

			foreach ( $tableUpdates as $key => $value ) {
				if ( $dbw->tableExists( $key ) ) {
					foreach ( $value as $name => $fields ) {
						try {
							$dbw->update(
								$key,
								$fields['fields'],
								$fields['where'],
								__METHOD__
							);
						} catch( Exception $ex ) {
							$out->addHTML( Html::errorBox( "Table {$key} either does not exist or the update failed." ) );
						}
					}
				}
			}

			$logTitle = Title::newFromText( 'CentralAuth', NS_SPECIAL )->getSubpage( $userNewName );
			$dbw->delete(
				'logging',
				[
					'log_action' => 'rename',
					'log_title' => $logTitle->getDBkey(),
					'log_type' => 'gblrename'
				]
			);

			$dbw->delete(
				'logging',
				[
					'log_action' => 'renameuser',
					'log_title' => $oldName->getTitleKey(),
					'log_type' => 'renameuser'
				]
			);

			$dbw->delete(
				'recentchanges',
				[
					'rc_log_action' => 'rename',
					'rc_title' => $logTitle->getDBkey(),
					'rc_log_type' => 'gblrename'
				]
			);

			$dbw->delete(
				'recentchanges',
				[
					'rc_log_action' => 'renameuser',
					'rc_title' => $oldName->getTitleKey(),
					'rc_log_type' => 'renameuser'
				]
			);

			$user = User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );

			if ( !$user ) {
				$out->addHTML( Html::errorBox( 'Invalid username' ) );
				return false;
			}

			// Hide deletions from RecentChanges
			$userGroupManager->addUserToGroup( $user, 'bot', null, true );

			$error = '';
			$title = Title::newFromText( $oldName->getTitleKey(), NS_USER );
			$userPage = WikiPage::factory( $title );
			$status = $userPage->doDeleteArticleReal( '', $user );

			if ( !$status->isOK() ) {
				$errorMessage = json_encode( $status->getErrorsByType( 'error' ) );
				$out->addHTML( Html::errorBox( "Failed to delete user {$userOldName} page, likely does not have a user page. Error: {$errorMessage}" ) );
			}
		}

		$dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'CentralAuthDatabase' ) );
		$centralUser = CentralAuthUser::getInstance( $newName );

		if ( !$centralUser ) {
			return false;
		}

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

		$centralUser->adminLock();

		return true;
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
