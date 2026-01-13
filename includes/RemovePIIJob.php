<?php

namespace Miraheze\RemovePII;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MWCryptRand;
use UserProfilePage;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IDatabase;

class RemovePIIJob extends Job implements GenericParameterJob {

	private readonly string $oldName;
	private readonly string $newName;

	public function __construct( array $params ) {
		parent::__construct( 'RemovePIIJob', $params );

		$this->oldName = $params['oldname'];
		$this->newName = $params['newname'];
	}

	/** @inheritDoc */
	public function run(): bool {
		$newCentral = CentralAuthUser::getInstanceByName( $this->newName );

		// Invalidate cache before we begin
		$newCentral->invalidateCache();

		// Set a random password to the account and log them out
		$randomPassword = MWCryptRand::generateHex( 32 );
		$newCentral->setPassword( $randomPassword, true );

		// If they're a global rights holder (sounds familiar), remove their groups
		$groups = $newCentral->getGlobalGroups();

		if ( $groups !== null ) {
			foreach ( $groups as $group ) {
				$newCentral->removeFromGlobalGroups( $group );
			}
		}

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$oldName = $userFactory->newFromName( $this->oldName );
		$newName = $userFactory->newFromName( $this->newName );

		$userOldName = $oldName->getName();
		$userNewName = $newName->getName();

		if ( !$newName ) {
			$this->setLastError( "User $userNewName is not a valid name." );
			return false;
		}

		$userId = $newName->getId();

		if ( !$userId ) {
			$this->setLastError( "User $userNewName ID is equal to 0." );
			return false;
		}

		$dbw = $lbFactory->getMainLB()->getMaintenanceConnectionRef( DB_PRIMARY );
		$userActorId = $newName->getActorId( $dbw );

		$titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
		$logTitle = $titleFactory->newFromText( 'CentralAuth', NS_SPECIAL )->getSubpage( $userNewName );

		// TODO: Migrate to config and add extension hook support for this

		$tableDeletions = [
			// Core
			'block' => [
				[
					'where' => [
						'bl_by_actor' => $userActorId,
					],
				],
			],
			'block_target' => [
				[
					'where' => [
						'bt_user' => $userId,
					],
				],
			],
			'user_groups' => [
				[
					'where' => [
						'ug_user' => $userId,
					],
				],
			],

			// Extensions
			'cu_changes' => [
				[
					'where' => [
						'cuc_actor' => $userActorId,
					],
				],
			],
			'cu_log' => [
				[
					'where' => [
						'cul_target_id' => $userId,
						'cul_type' => [ 'useredits', 'userips' ],
					],
				],
				[
					'where' => [
						'cul_actor' => $userActorId,
					],
				],
			],
			'user_board' => [
				[
					'where' => [
						'ub_actor' => $userActorId,
					],
				],
				[
					'where' => [
						'ub_actor_from' => $userActorId,
					],
				],
			],
			'user_profile' => [
				[
					'where' => [
						'up_actor' => $userActorId,
					],
				],
			],

			// Core
			'logging' => [
				[
					'where' => [
						'log_action' => 'rename',
						'log_title' => $logTitle->getDBkey(),
						'log_type' => 'gblrename',
					],
				],
				[
					'where' => [
						'log_action' => 'renameuser',
						'log_title' => $oldName->getTitleKey(),
						'log_type' => 'renameuser',
					],
				],
			],
			'recentchanges' => [
				[
					'where' => [
						'rc_log_action' => 'rename',
						'rc_title' => $logTitle->getDBkey(),
						'rc_log_type' => 'gblrename',
					],
				],
				[
					'where' => [
						'rc_log_action' => 'renameuser',
						'rc_title' => $oldName->getTitleKey(),
						'rc_log_type' => 'renameuser',
					],
				],
			],

		];

		$tableUpdates = [
			// Core
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip' => '0.0.0.0',
					],
					'where' => [
						'rc_actor' => $userActorId,
					],
				],
			],

			// Extensions
			'abuse_filter_log' => [
				[
					'fields' => [
						'afl_user_text' => $userNewName,
					],
					'where' => [
						'afl_user_text' => $userOldName,
					],
				],
			],
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip' => '0.0.0.0',
					],
					'where' => [
						'poll_actor' => $userActorId,
					],
				],
			],
			'cw_requests' => [
				[
					'fields' => [
						'cw_status' => 'declined',
					],
					'where' => [
						'cw_status' => [ 'inreview', 'onhold', 'needsmoredetails' ],
						'cw_user' => $userId,
					],
				],
			],
			'echo_event' => [
				[
					'fields' => [
						'event_agent_ip' => null,
					],
					'where' => [
						'event_agent_id' => $userId,
					],
				],
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip' => null,
					],
					'where' => [
						'tree_orig_user_id' => $userId,
					],
				],
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip' => null,
					],
					'where' => [
						'rev_user_id' => $userId,
					],
				],
				[
					'fields' => [
						'rev_mod_user_ip' => null,
					],
					'where' => [
						'rev_mod_user_id' => $userId,
					],
				],
				[
					'fields' => [
						'rev_edit_user_ip' => null,
					],
					'where' => [
						'rev_edit_user_id' => $userId,
					],
				],
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
					],
					'where' => [
						'mod_user' => $userId,
					],
				],
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
						'mod_user_text' => $userNewName,
					],
					'where' => [
						'mod_user_text' => $userOldName,
					],
				],
			],
			'report_reports' => [
				[
					'fields' => [
						'report_user_text' => $userNewName,
					],
					'where' => [
						'report_user_text' => $userOldName,
					],
				],
				[
					'fields' => [
						'report_handled_by_text' => $userNewName,
					],
					'where' => [
						'report_handled_by_text' => $userOldName,
					],
				],
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip' => '0.0.0.0',
					],
					'where' => [
						'vote_actor' => $userActorId,
					],
				],
			],
			'wikiforum_category' => [
				[
					'fields' => [
						'wfc_added_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfc_added_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wfc_edited_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfc_edited_actor' => $userActorId,
					],
				],
			],
			'wikiforum_forums' => [
				[
					'fields' => [
						'wff_last_post_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wff_last_post_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wff_added_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wff_added_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wff_edited_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wff_edited_actor' => $userActorId,
					],
				],
			],
			'wikiforum_replies' => [
				[
					'fields' => [
						'wfr_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfr_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wfr_edit_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfr_edit_actor' => $userActorId,
					],
				],
			],
			'wikiforum_threads' => [
				[
					'fields' => [
						'wft_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wft_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_edit_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wft_edit_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_closed_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wft_closed_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_last_post_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wft_last_post_actor' => $userActorId,
					],
				],
			],
		];

		foreach ( $tableDeletions as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $fields ) {
					try {
						$method = __METHOD__;
						$dbw->doAtomicSection( $method,
							static function () use ( $dbw, $key, $fields, $method ) {
								$dbw->newDeleteQueryBuilder()
									->deleteFrom( $key )
									->where( $fields['where'] )
									->caller( $method )
									->execute();
							},
							IDatabase::ATOMIC_CANCELABLE
						);

						$lbFactory->waitForReplication();
					} catch ( DBQueryError $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
						continue;
					}
				}
			}
		}

		foreach ( $tableUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $fields ) {
					try {
						$method = __METHOD__;
						$dbw->doAtomicSection( $method,
							static function () use ( $dbw, $key, $fields, $method ) {
								$dbw->newUpdateQueryBuilder()
									->update( $key )
									->set( $fields['fields'] )
									->where( $fields['where'] )
									->caller( $method )
									->execute();
							},
							IDatabase::ATOMIC_CANCELABLE
						);

						$lbFactory->waitForReplication();
					} catch ( DBQueryError $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );
						continue;
					}
				}
			}
		}

		$user = User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );
		if ( !$user ) {
			$this->setLastError( 'Invalid username.' );
			return false;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		// Hide deletions from RecentChanges
		$userGroupManager->addUserToGroup( $user, 'bot', null, true );
		$userPageTitle = $oldName->getUserPage();

		$namespaces = [
			NS_USER,
			NS_USER_TALK,
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

		$rows = $dbw->newSelectQueryBuilder()
			->table( 'page' )
			->fields( [
				'page_namespace',
				'page_title',
			] )
			->where( [
				'page_namespace IN (' . implode( ',', $namespaces ) . ')',
				'(page_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR page_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')',
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$deletePageFactory = MediaWikiServices::getInstance()->getDeletePageFactory();
		foreach ( $rows as $row ) {
			$title = $titleFactory->newFromRow( $row );
			$deletePage = $deletePageFactory->newDeletePage(
				$wikiPageFactory->newFromTitle( $title ),
				$user
			);

			$status = $deletePage->setSuppress( true )->forceImmediate( true )->deleteUnsafe( '' );
			if ( !$status->isOK() ) {
				$statusMessage = $status->getMessages( 'error' );
				$errorMessage = json_encode( $statusMessage );
				$this->setLastError( "Failed to delete user $userOldName page. Error: $errorMessage" );
			}
		}

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'archive' )
			->where( [
				'ar_namespace IN (' . implode( ',', $namespaces ) . ')',
				'(ar_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR ar_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')',
			] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'logging' )
			->where( [
				'(log_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR log_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')',
			] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'recentchanges' )
			->where( [
				'(rc_title ' . $dbw->buildLike( $userPageTitle->getDBkey() . '/', $dbw->anyString() ) .
				' OR rc_title = ' . $dbw->addQuotes( $userPageTitle->getDBkey() ) . ')',
			] )
			->caller( __METHOD__ )
			->execute();

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
