<?php

namespace Miraheze\RemovePII;

use Exception;
use GenericParameterJob;
use Job;
use MediaWiki\MediaWikiServices;

class RemovePIIJob extends Job implements GenericParameterJob {
	/** @var string */
	private $username;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'RemovePIIJob', $params );

		$this->username = $params['username'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$user = $userFactory->newFromName( $this->username );

		$username = $user->getName();

		if ( !$user ) {
			$this->setLastError( "User {$username} is not a valid name" );

			return false;
		}

		$userId = $user->getId();

		if ( !$userId ) {
			$this->setLastError( "User {$username} ID equal to 0" );

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
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$output[$key] = $dbw->select(
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

		$output['email'] = $user->getEmail();
		file_put_contents( "/srv/mediawiki/cache/RemovePII/{$GLOBALS['DBname']}", $output );

		return true;
	}
}
