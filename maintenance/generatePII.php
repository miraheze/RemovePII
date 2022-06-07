<?php

namespace Miraheze\RemovePII\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Exception;
use Maintenance;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;

class GeneratePII extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = 'Generates personal identifiable information for a user and saves it in CSV format.';

		$this->addOption( 'user', 'User to get PII for.', true, true );
		$this->addOption( 'directory', 'Directory to place outputted JSON file of PII in.', true, true );
		$this->addOption( 'generate', 'Only generate a database list of attached wikis for the user?' );

		$this->requireExtension( 'RemovePII' );
	}

	/**
	 * @return bool
	 */
	public function execute() {
		if ( $this->hasOption( 'generate' ) ) {
			return $this->generateAttachedDatabaseList();
		}

		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'RemovePII' );
		$dbName = $config->get( 'DBname' );

		$username = $this->getOption( 'user' );
		$user = $userFactory->newFromName( $username );

		if ( !$user ) {
			$this->output( "User {$username} is not a valid name" );

			return false;
		}

		$userId = $user->getId();

		if ( !$userId ) {
			$this->output( "User {$username} ID equal to 0" );

			return false;
		}

		$dbr = $lbFactory->getMainLB()->getConnection( DB_REPLICA );

		$userActorId = $user->getActorId( $dbr );

		$tableSelections = [
			// Core
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip'
					],
					'where' => [
						'rc_actor' => $userActorId
					]
				]
			],

			// Extensions
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip'
					],
					'where' => [
						'poll_actor' => $userActorId
					]
				]
			],
			'Comments' => [
				[
					'fields' => [
						'Comment_IP'
					],
					'where' => [
						'Comment_actor' => $userActorId
					]
				],
			],
			'echo_event' => [
				[
					'fields' => [
						'event_agent_ip'
					],
					'where' => [
						'event_agent_id' => $userId
					]
				]
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip'
					],
					'where' => [
						'tree_orig_user_id' => $userId
					]
				]
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip'
					],
					'where' => [
						'rev_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_mod_user_ip'
					],
					'where' => [
						'rev_mod_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_edit_user_ip'
					],
					'where' => [
						'rev_edit_user_id' => $userId
					]
				]
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff',
						'mod_header_ua',
						'mod_ip'
					],
					'where' => [
						'mod_user' => $userId
					]
				],
				[
					'fields' => [
						'mod_header_xff',
						'mod_header_ua',
						'mod_ip',
					],
					'where' => [
						'mod_user_text' => $username
					]
				]
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip',
					],
					'where' => [
						'vote_actor' => $userActorId
					]
				]
			],
			'wikiforum_category' => [
				[
					'fields' => [
						'wfc_added_user_ip',
					],
					'where' => [
						'wfc_added_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wfc_edited_user_ip',
					],
					'where' => [
						'wfc_edited_actor' => $userActorId
					]
				],
			],
			'wikiforum_forums' => [
				[
					'fields' => [
						'wff_last_post_user_ip',
					],
					'where' => [
						'wff_last_post_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wff_added_user_ip',
					],
					'where' => [
						'wff_added_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wff_edited_user_ip'
					],
					'where' => [
						'wff_edited_actor' => $userActorId
					]
				],
			],
			'wikiforum_replies' => [
				[
					'fields' => [
						'wfr_user_ip'
					],
					'where' => [
						'wfr_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wfr_edit_user_ip'
					],
					'where' => [
						'wfr_edit_actor' => $userActorId
					]
				],
			],
			'wikiforum_threads' => [
				[
					'fields' => [
						'wft_user_ip'
					],
					'where' => [
						'wft_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_edit_user_ip'
					],
					'where' => [
						'wft_edit_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_closed_user_ip'
					],
					'where' => [
						'wft_closed_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_last_post_user_ip'
					],
					'where' => [
						'wft_last_post_actor' => $userActorId
					]
				]
			],
		];

		$output = [];
		foreach ( $tableSelections as $key => $value ) {
			if ( $dbr->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$res = $dbr->select(
							$key,
							$fields['fields'],
							$fields['where'],
							__METHOD__
						);

						foreach ( $res as $row ) {
							foreach ( $fields['fields'] as $field ) {
								$output[] = $row->$field ? "{$field}: " . $row->$field . " ({$dbName})" : null;
							}
						}

						$lbFactory->waitForReplication();
					} catch ( Exception $e ) {
						$this->output( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
					}
				}
			}
		}

		$genderCache = MediaWikiServices::getInstance()->getGenderCache();

		$output['email'] = $user->getEmail();
		$output['realname'] = $user->getRealName();
		$output['gender'] = $genderCache->getGenderOf( $username );

		$file = fopen( $this->getOption( 'directory' ) . "/{$username}.csv", 'c+' );
		$output += fgetcsv( $file, 0, "\r" ) ?: [];
		fclose( $file );

		$output = array_filter( $output );

		$file = fopen( $this->getOption( 'directory' ) . "/{$username}.csv", 'w' );

		foreach ( $output as $key => $field ) {
			if ( is_string( $key ) ) {
				$output[$key] = "{$key}: {$field} ({$dbName})";
			}
		}

		fputcsv( $file, $output, "\r" );

		fclose( $file );

		return true;
	}

	/**
	 * @return bool
	 */
	public function generateAttachedDatabaseList() {
		$user = $this->getOption( 'user' );
		$centralUser = CentralAuthUser::getInstanceByName( $user );

		file_put_contents(
			$this->getOption( 'directory' ) . "/{$user}.json",
			json_encode( [ 'combi' => array_fill_keys( $centralUser->listAttached(), [] ) ]
		), LOCK_EX );

		return true;
	}
}

$maintClass = GeneratePII::class;
require_once RUN_MAINTENANCE_IF_MAIN;
