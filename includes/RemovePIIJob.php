<?php

namespace Miraheze\RemovePII;

use Exception;
use ExtensionRegistry;
use GenericParameterJob;
use Job;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use UserProfilePage;

class RemovePIIJob extends Job implements GenericParameterJob {

	/** @var string */
	private $oldName;

	/** @var string */
	private $newName;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'RemovePIIJob', $params );

		$this->oldName = $params['oldname'];
		$this->newName = $params['newname'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$newCentral = CentralAuthUser::getInstanceByName( $this->newName );

		// Invalidate cache before we begin
		$newCentral->invalidateCache();

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$oldName = $userFactory->newFromName( $this->oldName );
		$newName = $userFactory->newFromName( $this->newName );

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

		$dbw = $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );

		$userActorId = $newName->getActorId( $dbw );

		// TODO: Migrate to config and add extension hook support for this

		$tableDeletions = [
			// Extensions
			'cu_changes' => [
				[
					'where' => [
						'cuc_user' => $userId
					]
				]
			],
			'cu_log' => [
				[
					'where' => [
						'cul_target_id' => $userId,
						'cul_type' => [ 'useredits', 'userips' ]
					]
				],
				[
					'where' => [
						'cul_user' => $userId
					]
				]
			],
			'user_board' => [
				[
					'where' => [
						'ub_actor' => $userActorId
					]
				],
				[
					'where' => [
						'ub_actor_from' => $userActorId
					]
				]
			],
			'user_profile' => [
				[
					'where' => [
						'up_actor' => $userActorId
					]
				]
			],
		];

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
						'event_agent_ip' => null
					],
					'where' => [
						'event_agent_id' => $userId
					]
				]
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip' => null
					],
					'where' => [
						'tree_orig_user_id' => $userId
					]
				]
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip' => null
					],
					'where' => [
						'rev_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_mod_user_ip' => null
					],
					'where' => [
						'rev_mod_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_edit_user_ip' => null
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

		foreach ( $tableDeletions as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$dbw->delete(
							$key,
							$fields['where'],
							__METHOD__
						);

						$lbFactory->waitForReplication();
					} catch ( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
					}
				}
			}
		}

		foreach ( $tableUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$dbw->update(
							$key,
							$fields['fields'],
							$fields['where'],
							__METHOD__
						);

						$lbFactory->waitForReplication();
					} catch ( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
					}
				}
			}
		}

		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();

		$logTitle = $titleFactory->newFromText( 'CentralAuth', NS_SPECIAL )->getSubpage( $userNewName );
		$dbw->delete(
			'logging', [
				'log_action' => 'rename',
				'log_title' => $logTitle->getDBkey(),
				'log_type' => 'gblrename'
			],
			__METHOD__
		);

		$dbw->delete(
			'logging', [
				'log_action' => 'renameuser',
				'log_title' => $oldName->getTitleKey(),
				'log_type' => 'renameuser'
			],
			__METHOD__
		);

		$dbw->delete(
			'recentchanges', [
				'rc_log_action' => 'rename',
				'rc_title' => $logTitle->getDBkey(),
				'rc_log_type' => 'gblrename'
			],
			__METHOD__
		);

		$dbw->delete(
			'recentchanges', [
				'rc_log_action' => 'renameuser',
				'rc_title' => $oldName->getTitleKey(),
				'rc_log_type' => 'renameuser'
			],
			__METHOD__
		);

		$user = User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );

		if ( !$user ) {
			$this->setLastError( 'Invalid username' );

			return false;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		// Hide deletions from RecentChanges
		$userGroupManager->addUserToGroup( $user, 'bot', null, true );

		$userPageTitle = $oldName->getUserPage();

		$namespaces = [
			NS_USER,
			NS_USER_TALK
		];

		if ( class_exists( UserProfilePage::class ) ) {
			array_push( $namespaces,
				NS_USER_WIKI,
				NS_USER_WIKI_TALK,
				NS_USER_PROFILE,
				NS_USER_PROFILE_TALK
			);
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'SimpleBlogPage' ) ) {
			array_push( $namespaces,
				NS_USER_BLOG,
				NS_USER_BLOG_TALK
			);
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'BlogPage' ) ) {
			/* NS_BLOG and NS_BLOG_TALK */
			array_push( $namespaces,
				500,
				501
			);
		}

		$rows = $dbw->select(
			'page', [
				'page_namespace',
				'page_title'
			], [
				'page_namespace IN (' . implode( ',', $namespaces ) . ')',
				'(page_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR page_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')'
			],
			__METHOD__
		);

		foreach ( $rows as $row ) {
			$title = $titleFactory->newFromRow( $row );

			$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
			$deletePageFactory = MediaWikiServices::getInstance()->getDeletePageFactory();
			$deletePage = $deletePageFactory->newDeletePage(
				$wikiPageFactory->newFromTitle( $title ),
				$user
			);

			$status = $deletePage->setSuppress( true )->forceImmediate( true )->deleteUnsafe( '' );

			if ( !$status->isOK() ) {
				$errorMessage = json_encode( $status->getErrorsByType( 'error' ) );
				$this->setLastError( "Failed to delete user {$userOldName} page. Error: {$errorMessage}" );
			}
		}

		$dbw->delete(
			'archive', [
				'ar_namespace IN (' . implode( ',', $namespaces ) . ')',
				'(ar_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR ar_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')'
			],
			__METHOD__
		);

		$dbw->delete(
			'logging', [
				'(log_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR log_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')'
			],
			__METHOD__
		);

		$dbw->delete(
			'recentchanges', [
				'(rc_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR rc_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')'
			],
			__METHOD__
		);

		// Lock global account
		$newCentral->adminLock();

		// Invalidate cache now
		$newCentral->invalidateCache();

		// Remove user email and real name
		$userLatest = $newName->getInstanceForUpdate();

		if ( $userLatest->getEmail() ) {
			$userLatest->invalidateEmail();
		}

		if ( $userLatest->getRealName() ) {
			$userLatest->setRealName( '' );
		}

		$userLatest->saveSettings();

		return true;
	}
}
