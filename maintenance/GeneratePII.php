<?php

namespace Miraheze\RemovePII\Maintenance;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\DBQueryError;

class GeneratePII extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates personal identifiable information for a user and saves it in CSV format.' );

		$this->addOption( 'user', 'User to get PII for.', true, true );
		$this->addOption( 'directory', 'Directory to place outputted CSV file of PII in.', true, true );
		$this->addOption( 'generate', 'Only generate a database list of attached wikis for the user?' );

		$this->requireExtension( 'RemovePII' );
	}

	public function execute(): void {
		if ( $this->hasOption( 'generate' ) ) {
			$this->generateAttachedDatabaseList();
			return;
		}

		$userFactory = $this->getServiceContainer()->getUserFactory();

		$username = $this->getOption( 'user' );
		$user = $userFactory->newFromName( $username );

		if ( !$user ) {
			$this->fatalError( "User $username is not a valid name." );
		}

		$userId = $user->getId();
		if ( !$userId ) {
			$this->fatalError( "User $username ID is equal to 0." );
		}

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbr = $connectionProvider->getReplicaDatabase();
		$userActorId = $user->getActorId( $dbr );

		$tableSelections = [
			// Core
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip',
					],
					'where' => [
						'rc_actor' => $userActorId,
					],
				],
			],

			// Extensions
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip',
					],
					'where' => [
						'poll_actor' => $userActorId,
					],
				],
			],
			'Comments' => [
				[
					'fields' => [
						'Comment_IP',
					],
					'where' => [
						'Comment_actor' => $userActorId,
					],
				],
			],
			'echo_event' => [
				[
					'fields' => [
						'event_agent_ip',
					],
					'where' => [
						'event_agent_id' => $userId,
					],
				],
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip',
					],
					'where' => [
						'tree_orig_user_id' => $userId,
					],
				],
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip',
					],
					'where' => [
						'rev_user_id' => $userId,
					],
				],
				[
					'fields' => [
						'rev_mod_user_ip',
					],
					'where' => [
						'rev_mod_user_id' => $userId,
					],
				],
				[
					'fields' => [
						'rev_edit_user_ip',
					],
					'where' => [
						'rev_edit_user_id' => $userId,
					],
				],
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff',
						'mod_header_ua',
						'mod_ip',
					],
					'where' => [
						'mod_user' => $userId,
					],
				],
				[
					'fields' => [
						'mod_header_xff',
						'mod_header_ua',
						'mod_ip',
					],
					'where' => [
						'mod_user_text' => $username,
					],
				],
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip',
					],
					'where' => [
						'vote_actor' => $userActorId,
					],
				],
			],
			'wikiforum_category' => [
				[
					'fields' => [
						'wfc_added_user_ip',
					],
					'where' => [
						'wfc_added_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wfc_edited_user_ip',
					],
					'where' => [
						'wfc_edited_actor' => $userActorId,
					],
				],
			],
			'wikiforum_forums' => [
				[
					'fields' => [
						'wff_last_post_user_ip',
					],
					'where' => [
						'wff_last_post_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wff_added_user_ip',
					],
					'where' => [
						'wff_added_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wff_edited_user_ip',
					],
					'where' => [
						'wff_edited_actor' => $userActorId,
					],
				],
			],
			'wikiforum_replies' => [
				[
					'fields' => [
						'wfr_user_ip',
					],
					'where' => [
						'wfr_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wfr_edit_user_ip',
					],
					'where' => [
						'wfr_edit_actor' => $userActorId,
					],
				],
			],
			'wikiforum_threads' => [
				[
					'fields' => [
						'wft_user_ip',
					],
					'where' => [
						'wft_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_edit_user_ip',
					],
					'where' => [
						'wft_edit_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_closed_user_ip',
					],
					'where' => [
						'wft_closed_actor' => $userActorId,
					],
				],
				[
					'fields' => [
						'wft_last_post_user_ip',
					],
					'where' => [
						'wft_last_post_actor' => $userActorId,
					],
				],
			],
		];

		$dbname = $this->getConfig()->get( MainConfigNames::DBname );

		$output = [];
		foreach ( $tableSelections as $key => $value ) {
			if ( $dbr->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $fields ) {
					try {
						$res = $dbr->newSelectQueryBuilder()
							->select( $fields['fields'] )
							->from( $key )
							->where( $fields['where'] )
							->caller( __METHOD__ )
							->fetchResultSet();

						foreach ( $res as $row ) {
							foreach ( $fields['fields'] as $field ) {
								$output[] = $row->$field ? "$field: " . $row->$field . " ($dbname)" : null;
							}
						}
					} catch ( DBQueryError $e ) {
						$this->output( get_class( $e ) . ': ' . $e->getMessage() . "\n" );
						continue;
					}
				}
			}
		}

		$genderCache = $this->getServiceContainer()->getGenderCache();

		$output['email'] = $user->getEmail();
		$output['realname'] = $user->getRealName();
		$output['gender'] = $genderCache->getGenderOf( $username );

		$file = fopen( $this->getOption( 'directory' ) . "/$username.csv", 'c+' );
		$output += fgetcsv( $file, 0, "\r" ) ?: [];
		fclose( $file );

		$output = array_filter( $output );
		$file = fopen( $this->getOption( 'directory' ) . "/$username.csv", 'w' );
		foreach ( $output as $key => $field ) {
			if ( is_string( $key ) ) {
				$output[$key] = "$key: $field ($dbname)";
			}
		}

		fputcsv( $file, $output, "\r" );
		fclose( $file );
	}

	private function generateAttachedDatabaseList(): void {
		$user = $this->getOption( 'user' );
		$centralUser = CentralAuthUser::getInstanceByName( $user );

		file_put_contents(
			$this->getOption( 'directory' ) . "/$user.json",
			json_encode( [ 'combi' => array_fill_keys( $centralUser->listAttached(), [] ) ]
		), LOCK_EX );
	}
}

// @codeCoverageIgnoreStart
return GeneratePII::class;
// @codeCoverageIgnoreEnd
