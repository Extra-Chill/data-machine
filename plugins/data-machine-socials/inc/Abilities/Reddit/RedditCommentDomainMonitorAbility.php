<?php
/**
 * Bounded incremental Reddit comment-domain monitoring ability.
 *
 * @package DataMachineSocials
 * @subpackage Abilities\Reddit
 */

namespace DataMachineSocials\Abilities\Reddit;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachineSocials\Abilities\AbstractSocialAbility;
use DataMachineSocials\Tracking\RedditCommentDomainStore;
use DataMachineSocials\Tracking\RedditCommentUrlMatcher;

defined( 'ABSPATH' ) || exit;

class RedditCommentDomainMonitorAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function (): void {
			wp_register_ability(
				'datamachine/reddit-comment-mentions-poll',
				array(
					'label'               => __( 'Poll Reddit Comment Mentions', 'data-machine-socials' ),
					'description'         => __( 'Poll explicitly bounded subreddit comment listings and persist matching domain observations. This mutates checkpoints and retained observations.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'                 => 'object',
						'required'             => array( 'domains', 'subreddits' ),
						'additionalProperties' => false,
						'properties'           => array(
							'domains'            => array(
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => 25,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => 'string',
									'minLength' => 4,
									'maxLength' => 253,
								),
							),
							'subreddits'         => array(
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => 25,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => 'string',
									'minLength' => 2,
									'maxLength' => 24,
								),
							),
							'include_subdomains' => array(
								'type'    => 'boolean',
								'default' => false,
							),
							'known_owners'       => array(
								'type'        => 'array',
								'maxItems'    => 100,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => 'string',
									'minLength' => 3,
									'maxLength' => 23,
								),
							),
							'page_size'          => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 100,
							),
							'max_pages'          => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 10,
								'default' => 5,
							),
						),
					),
					'output_schema'       => $this->pollOutputSchema(),
					'execute_callback'    => array( $this, 'executePoll' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);

			wp_register_ability(
				'datamachine/reddit-comment-mentions-report',
				array(
					'label'               => __( 'Report Reddit Comment Mentions', 'data-machine-socials' ),
					'description'         => __( 'Read retained observations from explicitly monitored subreddit comment listings, with bounded coverage metadata.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'domain'       => array(
								'type'      => 'string',
								'maxLength' => 253,
							),
							'subreddit'    => array(
								'type'      => 'string',
								'maxLength' => 24,
							),
							'date_from'    => array(
								'type'      => 'string',
								'maxLength' => 32,
							),
							'date_to'      => array(
								'type'      => 'string',
								'maxLength' => 32,
							),
							'ownership'    => array(
								'type'    => 'string',
								'enum'    => array( 'all', 'known_owner', 'organic' ),
								'default' => 'all',
							),
							'known_owners' => array(
								'type'        => 'array',
								'maxItems'    => 100,
								'uniqueItems' => true,
								'items'       => array(
									'type'      => 'string',
									'minLength' => 3,
									'maxLength' => 23,
								),
							),
							'min_score'    => array( 'type' => 'integer' ),
							'limit'        => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 500,
								'default' => 100,
							),
						),
					),
					'output_schema'       => $this->reportOutputSchema(),
					'execute_callback'    => array( $this, 'executeReport' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);

			wp_register_ability(
				'datamachine/reddit-comment-mentions-cleanup',
				array(
					'label'               => __( 'Clean Up Reddit Comment Mentions', 'data-machine-socials' ),
					'description'         => __( 'Delete expired Reddit comment observations and enforce the hard site-local record cap.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(),
					),
					'output_schema'       => $this->cleanupOutputSchema(),
					'execute_callback'    => array( $this, 'executeCleanup' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => true,
						),
					),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/** Poll each explicitly configured subreddit scope. */
	public function executePoll( array $input ): array|\WP_Error {
		$allowed = array( 'domains', 'subreddits', 'include_subdomains', 'known_owners', 'page_size', 'max_pages' );
		if ( array_diff( array_keys( $input ), $allowed ) || ! is_array( $input['domains'] ?? null ) || ! is_array( $input['subreddits'] ?? null ) || ( isset( $input['known_owners'] ) && ! is_array( $input['known_owners'] ) ) ) {
			return new \WP_Error( 'invalid_param', 'Poll input contains an unknown property or invalid list.', array( 'status' => 400 ) );
		}
		$domains    = $this->normalizeDomains( $input['domains'] ?? array() );
		$subreddits = $this->normalizeSubreddits( $input['subreddits'] ?? array() );
		$owners     = $this->normalizeOwners( $input['known_owners'] ?? array() );
		if ( empty( $domains ) || empty( $subreddits ) ) {
			return new \WP_Error( 'missing_param', 'Poll requires at least one valid domain and subreddit.', array( 'status' => 400 ) );
		}
		if ( count( $domains ) > 25 || count( $subreddits ) > 25 || count( $owners ) > 100 ) {
			return new \WP_Error( 'invalid_param', 'Poll supports at most 25 domains, 25 subreddits, and 100 known owners.', array( 'status' => 400 ) );
		}

		$token = $this->getRedditAccessToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$page_size = $input['page_size'] ?? 100;
		$max_pages = $input['max_pages'] ?? 5;
		if ( ! is_int( $page_size ) || $page_size < 1 || $page_size > 100 || ! is_int( $max_pages ) || $max_pages < 1 || $max_pages > 10 || ( isset( $input['include_subdomains'] ) && ! is_bool( $input['include_subdomains'] ) ) ) {
			return new \WP_Error( 'invalid_param', 'page_size, max_pages, or include_subdomains is invalid.', array( 'status' => 400 ) );
		}
		$include_subdomains = (bool) ( $input['include_subdomains'] ?? false );
		$lock               = RedditCommentDomainStore::acquireLock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$results = array();
		$errors  = array();
		try {
			foreach ( $subreddits as $subreddit ) {
				$result = $this->pollScope( $subreddit, $domains, $include_subdomains, $owners, $token, $page_size, $max_pages, $lock );
				if ( is_wp_error( $result ) ) {
					$errors[ $subreddit ] = array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
						'data'    => $result->get_error_data(),
					);
					if ( 429 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
						break;
					}
					continue;
				}
				$results[ $subreddit ] = $result;
			}
		} finally {
			RedditCommentDomainStore::releaseLock( $lock );
		}

		return array(
			'success'  => empty( $errors ),
			'action'   => 'poll',
			'data'     => array(
				'scopes' => $results,
				'errors' => $errors,
			),
			'coverage' => $this->coverage( $subreddits ),
		);
	}

	/** Poll one subreddit with resumable traversal from a captured head. */
	private function pollScope( string $subreddit, array $domains, bool $include_subdomains, array $owners, string $token, int $page_size, int $max_pages, string $lock ): array|\WP_Error {
		$scope            = RedditCommentDomainStore::getScope( $subreddit );
		$config_signature = hash( 'sha256', wp_json_encode( array( $domains, $include_subdomains, $owners ) ) );
		$pending          = is_array( $scope['pending'] ?? null ) ? $scope['pending'] : array();
		if ( ! empty( $pending ) && ( $pending['config_signature'] ?? '' ) !== $config_signature ) {
			$pending = array();
			unset( $scope['pending'] );
		}

		$checkpoint          = (string) ( $scope['checkpoint'] ?? '' );
		$after               = (string) ( $pending['after'] ?? '' );
		$target_head         = (string) ( $pending['head'] ?? '' );
		$target_head_created = (int) ( $pending['head_created_utc'] ?? 0 );
		$oldest_seen         = (int) ( $pending['oldest_created_utc'] ?? 0 );
		$pages               = 0;
		$checked             = 0;
		$inserted            = 0;
		$updated             = 0;
		$reached_checkpoint  = false;
		$source_exhausted    = false;
		$now                 = time();

		while ( $pages < $max_pages ) {
			if ( ! RedditCommentDomainStore::refreshLock( $lock ) ) {
				return new \WP_Error( 'reddit_comment_monitor_lock_lost', 'The Reddit comment-domain poll lock was lost before the next page.', array( 'status' => 409 ) );
			}
			$response = $this->fetchCommentPage( $subreddit, $token, $page_size, $after );
			if ( is_wp_error( $response ) ) {
				$scope['last_poll_at'] = $now;
				$scope['last_status']  = 'error';
				$scope['last_error']   = $response->get_error_message();
				RedditCommentDomainStore::putScope( $subreddit, $scope );
				return $response;
			}

			++$pages;
			$children = $response['data']['children'] ?? array();
			if ( empty( $children ) ) {
				$source_exhausted = true;
				break;
			}
			if ( ! is_array( $children ) ) {
				return new \WP_Error( 'reddit_api_error', 'Reddit returned malformed comment-listing children; the cursor was not advanced.', array( 'status' => 502 ) );
			}

			$page_records = array();
			foreach ( $children as $wrapper ) {
				if ( ! is_array( $wrapper ) || 't1' !== (string) ( $wrapper['kind'] ?? '' ) || ! is_array( $wrapper['data'] ?? null ) ) {
					return new \WP_Error( 'reddit_api_error', 'Reddit returned a malformed comment entry; the cursor was not advanced.', array( 'status' => 502 ) );
				}
				$comment  = $wrapper['data'];
				$fullname = (string) ( $comment['name'] ?? ( ! empty( $comment['id'] ) ? 't1_' . $comment['id'] : '' ) );
				if ( ! preg_match( '/^t1_[a-z0-9]+$/i', $fullname ) || empty( $comment['id'] ) || ! isset( $comment['created_utc'] ) ) {
					return new \WP_Error( 'reddit_api_error', 'Reddit returned a comment without a valid ID or timestamp; the cursor was not advanced.', array( 'status' => 502 ) );
				}
				if ( '' === $target_head ) {
					$target_head         = $fullname;
					$target_head_created = (int) ( $comment['created_utc'] ?? 0 );
				}
				if ( '' !== $checkpoint && $fullname === $checkpoint ) {
					$reached_checkpoint = true;
					break;
				}

				++$checked;
				$created_utc = (int) $comment['created_utc'];
				$oldest_seen = 0 === $oldest_seen ? $created_utc : min( $oldest_seen, $created_utc );
				foreach ( RedditCommentUrlMatcher::match( (string) ( $comment['body'] ?? '' ), $domains, $include_subdomains ) as $match ) {
					$author         = trim( (string) ( $comment['author'] ?? '[deleted]' ) );
					$author         = '' !== $author ? $author : '[deleted]';
					$comment_id     = (string) ( $comment['id'] ?? preg_replace( '/^t1_/', '', $fullname ) );
					$parent_post_id = preg_replace( '/^t3_/', '', (string) ( $comment['link_id'] ?? '' ) );
					$page_records[] = array(
						'comment_id'          => $comment_id,
						'comment_fullname'    => $fullname,
						'parent_post_id'      => $parent_post_id,
						'parent_post_title'   => (string) ( $comment['link_title'] ?? '' ),
						'subreddit'           => (string) ( $comment['subreddit'] ?? $subreddit ),
						'author'              => $author,
						'comment_created_utc' => (int) ( $comment['created_utc'] ?? 0 ),
						'score'               => (int) ( $comment['score'] ?? 0 ),
						'permalink'           => $this->redditPermalink( (string) ( $comment['permalink'] ?? '' ) ),
						'domain'              => $match['domain'],
						'matched_url'         => $match['url'],
						'matched_host'        => $match['host'],
						'known_owner'         => in_array( strtolower( $author ), $owners, true ),
						'first_seen'          => $now,
						'last_seen'           => $now,
					);
				}
			}

			$stored = RedditCommentDomainStore::upsert( $page_records );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$inserted += $stored['inserted'];
			$updated  += $stored['updated'];

			$next_after = (string) ( $response['data']['after'] ?? '' );
			if ( $reached_checkpoint ) {
				break;
			}
			if ( '' === $next_after ) {
				$source_exhausted = true;
				break;
			}

			$after                       = $next_after;
			$scope['pending']            = array(
				'head'               => $target_head,
				'head_created_utc'   => $target_head_created,
				'oldest_created_utc' => $oldest_seen,
				'after'              => $after,
				'started_at'         => (int) ( $pending['started_at'] ?? $now ),
				'config_signature'   => $config_signature,
			);
			$scope['last_poll_at']       = $now;
			$scope['last_status']        = 'truncated';
			$scope['configured_domains'] = $domains;
			$scope['include_subdomains'] = $include_subdomains;
			RedditCommentDomainStore::putScope( $subreddit, $scope );
		}

		$complete = $reached_checkpoint || ( '' === $checkpoint && $source_exhausted );
		if ( $complete && '' !== $target_head ) {
			$scope['checkpoint']             = $target_head;
			$scope['checkpoint_created_utc'] = $target_head_created;
			$scope['observed_from']          = 0 === (int) ( $scope['observed_from'] ?? 0 )
				? $oldest_seen
				: min( (int) $scope['observed_from'], $oldest_seen );
			$scope['observed_to']            = max( (int) ( $scope['observed_to'] ?? 0 ), $target_head_created );
			unset( $scope['pending'] );
			$scope['last_status'] = 'complete';
			$scope['last_error']  = '';
		} elseif ( '' !== $checkpoint && $source_exhausted && ! $reached_checkpoint ) {
			unset( $scope['pending'] );
			$scope['last_status'] = 'checkpoint_unavailable';
			$scope['last_error']  = 'The prior checkpoint was not present in Reddit\'s bounded recent-comment listing; the checkpoint was not advanced.';
		}

		$scope['last_poll_at']       = $now;
		$scope['configured_domains'] = $domains;
		$scope['include_subdomains'] = $include_subdomains;
		RedditCommentDomainStore::putScope( $subreddit, $scope );

		return array(
			'checked'            => $checked,
			'inserted'           => $inserted,
			'updated'            => $updated,
			'pages'              => $pages,
			'checkpoint_reached' => $reached_checkpoint,
			'source_exhausted'   => $source_exhausted,
			'truncated'          => ! $complete,
			'checkpoint'         => (string) ( $scope['checkpoint'] ?? '' ),
			'continuation'       => (string) ( $scope['pending']['after'] ?? '' ),
		);
	}

	/** Fetch one subreddit recent-comment listing page. */
	private function fetchCommentPage( string $subreddit, string $token, int $limit, string $after ): array|\WP_Error {
		$params = array(
			'limit'    => $limit,
			'raw_json' => 1,
		);
		if ( '' !== $after ) {
			$params['after'] = $after;
		}
		$url      = 'https://oauth.reddit.com/r/' . rawurlencode( $subreddit ) . '/comments.json?' . http_build_query( $params );
		$response = HttpClient::get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'context' => 'Reddit Comment Domain Monitor',
			)
		);
		if ( empty( $response['success'] ) ) {
			$status = (int) ( $response['status_code'] ?? 500 );
			$data   = array( 'status' => $status );
			foreach ( array( 'retry-after', 'x-ratelimit-remaining', 'x-ratelimit-reset' ) as $header ) {
				$value = isset( $response['response'] ) ? wp_remote_retrieve_header( $response['response'], $header ) : '';
				if ( '' !== (string) $value ) {
					$data['rate_limit'][ str_replace( array( 'x-ratelimit-', '-' ), array( '', '_' ), $header ) ] = (string) $value;
				}
			}
			return new \WP_Error( 429 === $status ? 'reddit_rate_limited' : 'reddit_api_error', (string) ( $response['error'] ?? 'Reddit comment request failed.' ), $data );
		}

		$decoded = json_decode( (string) ( $response['data'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ! is_array( $decoded['data'] ?? null ) || ! array_key_exists( 'children', $decoded['data'] ) || ! array_key_exists( 'after', $decoded['data'] ) ) {
			return new \WP_Error( 'reddit_api_error', 'Reddit returned an invalid comment listing.', array( 'status' => 502 ) );
		}
		return $decoded;
	}

	/** Report retained matches and explicit bounded coverage metadata. */
	public function executeReport( array $input ): array|\WP_Error {
		$allowed = array( 'domain', 'subreddit', 'date_from', 'date_to', 'ownership', 'known_owners', 'min_score', 'limit' );
		if ( array_diff( array_keys( $input ), $allowed ) || ( isset( $input['known_owners'] ) && ! is_array( $input['known_owners'] ) ) ) {
			return new \WP_Error( 'invalid_param', 'Report input contains an unknown property or invalid owner list.', array( 'status' => 400 ) );
		}
		$domain    = RedditCommentUrlMatcher::normalizeDomain( (string) ( $input['domain'] ?? '' ) );
		$subreddit = $this->normalizeSubreddits( array( $input['subreddit'] ?? '' ) );
		$ownership = (string) ( $input['ownership'] ?? 'all' );
		$owners    = $this->normalizeOwners( $input['known_owners'] ?? array() );
		$date_from = (string) ( $input['date_from'] ?? '' );
		$date_to   = (string) ( $input['date_to'] ?? '' );
		$limit     = $input['limit'] ?? 100;
		if ( '' !== trim( (string) ( $input['domain'] ?? '' ) ) && '' === $domain ) {
			return new \WP_Error( 'invalid_param', 'domain must be a valid hostname or HTTP(S) URL.', array( 'status' => 400 ) );
		}
		if ( '' !== trim( (string) ( $input['subreddit'] ?? '' ) ) && empty( $subreddit ) ) {
			return new \WP_Error( 'invalid_param', 'subreddit must be a valid subreddit name.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $ownership, array( 'all', 'known_owner', 'organic' ), true ) || count( $owners ) > 100 || ! is_int( $limit ) || $limit < 1 || $limit > 500 || ( isset( $input['min_score'] ) && ! is_int( $input['min_score'] ) ) ) {
			return new \WP_Error( 'invalid_param', 'Invalid ownership, owner count, or limit.', array( 'status' => 400 ) );
		}
		foreach ( array( $date_from, $date_to ) as $date ) {
			if ( '' !== trim( $date ) && false === strtotime( $date ) ) {
				return new \WP_Error( 'invalid_param', 'date_from and date_to must be valid dates.', array( 'status' => 400 ) );
			}
		}
		$subreddits = empty( $subreddit ) ? array() : $subreddit;
		$records    = RedditCommentDomainStore::report(
			array(
				'domain'       => $domain,
				'subreddit'    => $subreddit[0] ?? '',
				'date_from'    => $date_from,
				'date_to'      => $date_to,
				'ownership'    => $ownership,
				'min_score'    => (int) ( $input['min_score'] ?? PHP_INT_MIN ),
				'known_owners' => $owners,
				'limit'        => $limit,
			)
		);

		return array(
			'success'  => true,
			'action'   => 'report',
			'data'     => array(
				'matches' => $records,
				'count'   => count( $records ),
			),
			'coverage' => $this->coverage( $subreddits ),
		);
	}

	/** Apply retention to the current site's observation table. */
	public function executeCleanup( array $input ): array|\WP_Error {
		if ( ! empty( $input ) ) {
			return new \WP_Error( 'invalid_param', 'Cleanup does not accept input properties.', array( 'status' => 400 ) );
		}
		return array(
			'success' => true,
			'data'    => RedditCommentDomainStore::cleanup(),
		);
	}

	/** Build coverage metadata that cannot be mistaken for global search. */
	private function coverage( array $subreddits ): array {
		$scopes    = RedditCommentDomainStore::getScopes( $subreddits );
		$truncated = false;
		foreach ( $scopes as $scope ) {
			if ( ! empty( $scope['pending'] ) || in_array( $scope['last_status'] ?? '', array( 'truncated', 'checkpoint_unavailable', 'error' ), true ) ) {
				$truncated = true;
				break;
			}
		}

		return array(
			'kind'                => 'bounded_incremental_subreddit_comment_streams',
			'all_reddit_search'   => false,
			'historical_complete' => false,
			'configured_scopes'   => array_keys( $scopes ),
			'scopes'              => $scopes,
			'truncated'           => $truncated,
			'limitation'          => 'Reddit provides bounded recent-comment listings, not complete global historical comment search. Results cover only configured subreddit scopes since their retained checkpoints.',
		);
	}

	private function pollOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success', 'action', 'data', 'coverage' ),
			'additionalProperties' => false,
			'properties'           => array(
				'success'  => array( 'type' => 'boolean' ),
				'action'   => array(
					'type' => 'string',
					'enum' => array( 'poll' ),
				),
				'data'     => array(
					'type'                 => 'object',
					'required'             => array( 'scopes', 'errors' ),
					'additionalProperties' => false,
					'properties'           => array(
						'scopes' => array(
							'type'                 => 'object',
							'additionalProperties' => $this->pollScopeSchema(),
						),
						'errors' => array(
							'type'                 => 'object',
							'additionalProperties' => $this->errorSchema(),
						),
					),
				),
				'coverage' => $this->coverageSchema(),
			),
		);
	}

	private function reportOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success', 'action', 'data', 'coverage' ),
			'additionalProperties' => false,
			'properties'           => array(
				'success'  => array( 'type' => 'boolean' ),
				'action'   => array(
					'type' => 'string',
					'enum' => array( 'report' ),
				),
				'data'     => array(
					'type'                 => 'object',
					'required'             => array( 'matches', 'count' ),
					'additionalProperties' => false,
					'properties'           => array(
						'matches' => array(
							'type'  => 'array',
							'items' => $this->recordSchema(),
						),
						'count'   => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 500,
						),
					),
				),
				'coverage' => $this->coverageSchema(),
			),
		);
	}

	private function cleanupOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'success', 'data' ),
			'additionalProperties' => false,
			'properties'           => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'retained', 'retention_days', 'max_records' ),
					'additionalProperties' => false,
					'properties'           => array(
						'deleted'        => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'retained'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 10000,
						),
						'retention_days' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 365,
						),
						'max_records'    => array(
							'type'    => 'integer',
							'minimum' => 100,
							'maximum' => 10000,
						),
					),
				),
			),
		);
	}

	private function coverageSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'kind', 'all_reddit_search', 'historical_complete', 'configured_scopes', 'scopes', 'truncated', 'limitation' ),
			'additionalProperties' => false,
			'properties'           => array(
				'kind'                => array( 'type' => 'string' ),
				'all_reddit_search'   => array( 'type' => 'boolean' ),
				'historical_complete' => array( 'type' => 'boolean' ),
				'configured_scopes'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'scopes'              => array(
					'type'                 => 'object',
					'additionalProperties' => $this->coverageScopeSchema(),
				),
				'truncated'           => array( 'type' => 'boolean' ),
				'limitation'          => array( 'type' => 'string' ),
			),
		);
	}

	private function recordSchema(): array {
		$strings    = array( 'comment_id', 'comment_fullname', 'parent_post_id', 'parent_post_title', 'subreddit', 'author', 'permalink', 'domain', 'matched_url', 'matched_host' );
		$properties = array();
		foreach ( $strings as $field ) {
			$properties[ $field ] = array( 'type' => 'string' );
		}
		foreach ( array( 'comment_created_utc', 'score', 'first_seen', 'last_seen' ) as $field ) {
			$properties[ $field ] = array( 'type' => 'integer' );
		}
		$properties['known_owner'] = array( 'type' => 'boolean' );
		return array(
			'type'                 => 'object',
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	private function pollScopeSchema(): array {
		$properties = array(
			'checked'            => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'inserted'           => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'updated'            => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'pages'              => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 10,
			),
			'checkpoint_reached' => array( 'type' => 'boolean' ),
			'source_exhausted'   => array( 'type' => 'boolean' ),
			'truncated'          => array( 'type' => 'boolean' ),
			'checkpoint'         => array( 'type' => 'string' ),
			'continuation'       => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	private function errorSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code', 'message', 'data' ),
			'additionalProperties' => false,
			'properties'           => array(
				'code'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array( 'type' => array( 'object', 'array', 'null' ) ),
			),
		);
	}

	private function coverageScopeSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'checkpoint'             => array( 'type' => 'string' ),
				'checkpoint_created_utc' => array( 'type' => 'integer' ),
				'observed_from'          => array( 'type' => 'integer' ),
				'observed_to'            => array( 'type' => 'integer' ),
				'pending'                => array( 'type' => 'object' ),
				'last_poll_at'           => array( 'type' => 'integer' ),
				'last_status'            => array( 'type' => 'string' ),
				'last_error'             => array( 'type' => 'string' ),
				'configured_domains'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'include_subdomains'     => array( 'type' => 'boolean' ),
			),
		);
	}

	private function normalizeDomains( mixed $domains ): array {
		$domains = is_array( $domains ) ? $domains : array();
		return array_values( array_filter( array_unique( array_map( array( RedditCommentUrlMatcher::class, 'normalizeDomain' ), array_map( 'strval', $domains ) ) ) ) );
	}

	private function normalizeSubreddits( mixed $subreddits ): array {
		$subreddits = is_array( $subreddits ) ? $subreddits : array();
		$normalized = array();
		foreach ( $subreddits as $subreddit ) {
			$subreddit = preg_replace( '~^/?r/~i', '', trim( (string) $subreddit ) );
			if ( preg_match( '/^[a-z0-9_]{2,21}$/i', $subreddit ) ) {
				$normalized[] = $subreddit;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private function normalizeOwners( mixed $owners ): array {
		$owners     = is_array( $owners ) ? $owners : array();
		$normalized = array();
		foreach ( $owners as $owner ) {
			$owner = strtolower( trim( (string) $owner ) );
			$owner = preg_replace( '~^/?u/~i', '', $owner );
			if ( preg_match( '/^[a-z0-9_-]{3,20}$/', $owner ) ) {
				$normalized[] = $owner;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private function redditPermalink( string $permalink ): string {
		if ( '' === $permalink ) {
			return '';
		}
		return str_starts_with( $permalink, 'http' ) ? esc_url_raw( $permalink ) : 'https://www.reddit.com' . $permalink;
	}

	/** Resolve and refresh credentials entirely server-side. */
	protected function getRedditAccessToken(): string|\WP_Error {
		$provider = $this->resolveProvider( 'reddit', 'Reddit' );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$token = $provider->get_valid_access_token();
		return empty( $token ) ? new \WP_Error( 'missing_auth', 'Could not obtain a valid Reddit access token.', array( 'status' => 401 ) ) : $token;
	}
}
