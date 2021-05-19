<?php

namespace Miraheze\RemovePII;

use CentralAuthUser;
use Exception;
use ExtensionRegistry;
use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;
use Title;
use User;
use UserProfilePage;
use WikiPage;

class RemovePIIJob extends Job implements GenericParameterJob {
	/** @var string */
	private $database;

	/** @var string */
	private $oldName;

	/** @var string */
	private $newName;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'RemovePIIJob', $params );

		$this->database = $params['database'];
		$this->oldName = $params['oldname'];
		$this->newName = $params['newname'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$newCentral = CentralAuthUser::getInstanceByName( $this->newName );

		// Invalidate cache before we begin transaction
		$newCentral->invalidateCache();

		$oldName = User::newFromName( $this->oldName );
		$newName = User::newFromName( $this->newName );

		$userOldName = $oldName->getName();
		$userNewName = $newName->getName();

		if ( !$newName ) {
			$this->setLastError( "User {$userNewName} is not a valid name" );

			return false;
		}

		$userId = $newName->getId();

		if ( !$userId ) {
			$this->setLastError( "User {$userNewName} ID equal to 0" );

			return false;
		}

		$dbw = wfGetDB( DB_MASTER, [], $this->database );

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
					} catch( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
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
			$this->setLastError( 'Invalid username' );

			return false;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		// Hide deletions from RecentChanges
		$userGroupManager->addUserToGroup( $user, 'bot', null, true );


		$dbr = wfGetDB( DB_REPLICA, [], $this->database );
		$userPageTitle = $oldName->getUserPage();

		$namespaces = [
			'NS_USER',
			'NS_USER_TALK'
		];
		
		if ( class_exists( 'UserProfilePage' ) ) {
			$namespaces += [
				'NS_USER_WIKI',
				'NS_USER_WIKI_TALK',
				'NS_USER_PROFILE',
				'NS_USER_PROFILE_TALK'
			];
		}
		
		if ( ExtensionRegistry::getInstance()->isLoaded( 'BlogPage' ) ) {
			$namespaces += [
				'NS_BLOG',
				'NS_BLOG_TALK'
			];
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'SimpleBlogPage' ) ) {
			$namespaces += [
				'NS_USER_BLOG',
				'NS_USER_BLOG_TALK'
			];
		}

		$rows = $dbr->select(
			'page', [
				'page_namespace',
				'page_title'
			], [
				'page_namespace IN (' . implode( ',', $namespaces ) . ')',
				'(page_title ' . $dbr->buildLike( $userPageTitle->getDBkey() . '/', $dbr->anyString() ) .
				' OR page_title = ' . $dbr->addQuotes( $userPageTitle->getDBkey() ) . ')'
			],
			__METHOD__
		);

		$error = '';

		foreach ( $rows as $row ) {
			$title = Title::newFromRow( $row );

			$userPage = WikiPage::factory( $title );
			$status = $userPage->doDeleteArticleReal( '', $user );

			if ( !$status->isOK() ) {
				$errorMessage = json_encode( $status->getErrorsByType( 'error' ) );
				$this->setLastError( "Failed to delete user {$userOldName} page, likely does not have a user page. Error: {$errorMessage}" );

				continue;
			}
		}

		// Lock global account
		$newCentral->adminLock();

		// Invalidate cache now
		$newCentral->invalidateCache();

		// Remove user email
		if ( $newName->getEmail() ) {
			$newName->invalidateEmail();
			$newName->saveSettings();
		}

		return true;
	}
}
