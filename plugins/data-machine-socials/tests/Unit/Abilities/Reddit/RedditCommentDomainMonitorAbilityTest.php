<?php
/**
 * RedditCommentDomainMonitorAbility tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Reddit
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Reddit;

use DataMachineSocials\Abilities\Reddit\RedditCommentDomainMonitorAbility;
use DataMachineSocials\Tracking\RedditCommentDomainStore;
use DataMachineSocials\Tracking\RedditCommentUrlMatcher;
use WP_UnitTestCase;

class RedditCommentDomainMonitorAbilityTest extends WP_UnitTestCase {

	private RedditCommentDomainMonitorAbility $ability;

	public function set_up(): void {
		parent::set_up();
		delete_option( RedditCommentDomainStore::STATE_OPTION );
		delete_option( 'datamachine_socials_reddit_comment_db_version' );
		delete_option( 'datamachine_socials_reddit_comment_monitor_lock' );
		RedditCommentDomainStore::install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_socials_reddit_comment_mentions" );
		$this->ability = new class() extends RedditCommentDomainMonitorAbility {
			protected function getRedditAccessToken(): string|\WP_Error {
				return 'test-token';
			}
		};
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'datamachine_socials_reddit_comment_retention_days' );
		remove_all_filters( 'datamachine_socials_reddit_comment_max_records' );
		delete_option( RedditCommentDomainStore::STATE_OPTION );
		delete_option( 'datamachine_socials_reddit_comment_monitor_lock' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_socials_reddit_comment_mentions" );
		parent::tear_down();
	}

	public function test_url_parser_matches_exact_domain_without_lookalikes(): void {
		$matches = RedditCommentUrlMatcher::match(
			'Valid https://www.example.com/path?x=1 and example.com/other; invalid example.com.evil.test/path and notexample.com/x.',
			array( 'example.com' ),
			false
		);

		$this->assertSame( array( 'https://example.com/path?x=1', 'https://example.com/other' ), array_column( $matches, 'url' ) );
		$this->assertSame( array( 'example.com', 'example.com' ), array_column( $matches, 'host' ) );
	}

	public function test_url_parser_only_matches_subdomains_when_enabled(): void {
		$body = 'https://news.example.com/story https://example.com/root';

		$exact = RedditCommentUrlMatcher::match( $body, array( 'example.com' ), false );
		$with  = RedditCommentUrlMatcher::match( $body, array( 'example.com' ), true );

		$this->assertSame( array( 'example.com' ), array_column( $exact, 'host' ) );
		$this->assertSame( array( 'news.example.com', 'example.com' ), array_column( $with, 'host' ) );
	}

	public function test_url_parser_handles_markdown_punctuation_and_multiple_urls(): void {
		$matches = RedditCommentUrlMatcher::match(
			'[First](https://example.com/one), then <https://example.com/two?x=1>. Not https://example.com.evil.test/no.',
			array( 'example.com' ),
			false
		);

		$this->assertSame( array( 'https://example.com/one', 'https://example.com/two?x=1' ), array_column( $matches, 'url' ) );
	}

	public function test_initial_poll_normalizes_matches_and_establishes_checkpoint(): void {
		$this->mockPages(
			array(
				$this->page(
					array(
						$this->comment( 'newest', 'https://example.com/story', 'known_account', 12 ),
						$this->comment( 'deletedauthor', 'example.com/other', '[deleted]', 2 ),
						$this->comment( 'deletedbody', '[deleted]', 'someone', 0 ),
					),
					null
				),
			)
		);

		$result = $this->ability->executePoll( $this->pollInput( array( 'known_owners' => array( 'Known_Account' ) ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['scopes']['WordPress']['truncated'] );
		$this->assertSame( 't1_newest', $result['data']['scopes']['WordPress']['checkpoint'] );
		$records = RedditCommentDomainStore::report( array( 'limit' => 100 ) );
		$this->assertCount( 2, $records );
		$this->assertSame( 'post-newest', $records[0]['parent_post_id'] );
		$this->assertSame( 'Parent newest', $records[0]['parent_post_title'] );
		$this->assertSame( 'https://www.reddit.com/r/WordPress/comments/post-newest/x/newest/', $records[0]['permalink'] );
		$this->assertTrue( $records[0]['known_owner'] );
		$this->assertSame( '[deleted]', $records[1]['author'] );
	}

	public function test_cursor_replay_advances_to_new_head_without_duplicates(): void {
		$this->mockPages(
			array(
				$this->page( array( $this->comment( 'one', 'example.com/one' ) ), null ),
				$this->page(
					array(
						$this->comment( 'two', 'example.com/two' ),
						$this->comment( 'one', 'example.com/one' ),
					),
					null
				),
			)
		);

		$first  = $this->ability->executePoll( $this->pollInput() );
		$second = $this->ability->executePoll( $this->pollInput() );

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 1, $second['data']['scopes']['WordPress']['inserted'] );
		$this->assertSame( 't1_two', RedditCommentDomainStore::getScope( 'WordPress' )['checkpoint'] );
		$this->assertCount( 2, RedditCommentDomainStore::report( array( 'limit' => 100 ) ) );
	}

	public function test_store_suppresses_duplicate_comment_url_and_preserves_first_seen(): void {
		$first_seen           = time();
		$record               = $this->storedRecord( 'same', $first_seen );
		$first                = RedditCommentDomainStore::upsert( array( $record ) );
		$record['first_seen'] = $first_seen + 1;
		$record['last_seen']  = $first_seen + 1;
		$second               = RedditCommentDomainStore::upsert( array( $record ) );
		$stored               = RedditCommentDomainStore::report( array( 'limit' => 10 ) );

		$this->assertSame( array( 'inserted' => 1, 'updated' => 0 ), $first );
		$this->assertSame( array( 'inserted' => 0, 'updated' => 1 ), $second );
		$this->assertSame( $first_seen, $stored[0]['first_seen'] );
		$this->assertSame( $first_seen + 1, $stored[0]['last_seen'] );
	}

	public function test_truncated_initial_scan_resumes_after_cursor_before_committing_head(): void {
		$requested_after = array();
		$newer           = $this->comment( 'three', 'example.com/three' );
		$older           = $this->comment( 'two', 'example.com/two' );
		$newer['created_utc'] = time();
		$older['created_utc'] = $newer['created_utc'] - 60;
		$this->mockPages(
			array(
				$this->page( array( $newer ), 'page-two' ),
				$this->page( array( $older ), null ),
			),
			$requested_after
		);

		$first  = $this->ability->executePoll( $this->pollInput( array( 'max_pages' => 1 ) ) );
		$scope  = RedditCommentDomainStore::getScope( 'WordPress' );
		$second = $this->ability->executePoll( $this->pollInput( array( 'max_pages' => 1 ) ) );

		$this->assertTrue( $first['data']['scopes']['WordPress']['truncated'] );
		$this->assertArrayNotHasKey( 'checkpoint', $scope );
		$this->assertSame( 'page-two', $scope['pending']['after'] );
		$this->assertFalse( $second['data']['scopes']['WordPress']['truncated'] );
		$this->assertSame( 't1_three', RedditCommentDomainStore::getScope( 'WordPress' )['checkpoint'] );
		$this->assertSame( $older['created_utc'], RedditCommentDomainStore::getScope( 'WordPress' )['observed_from'] );
		$this->assertSame( $newer['created_utc'], RedditCommentDomainStore::getScope( 'WordPress' )['observed_to'] );
		$this->assertSame( array( '', 'page-two' ), $requested_after );
	}

	public function test_rate_limit_preserves_completed_checkpoint_and_safe_continuation(): void {
		$this->mockPages(
			array(
				$this->page( array( $this->comment( 'one', 'example.com/one' ) ), null ),
				$this->page( array( $this->comment( 'two', 'example.com/two' ) ), 'older-page' ),
				$this->errorResponse( 429, 'Too Many Requests', array( 'retry-after' => '60' ) ),
				$this->page( array( $this->comment( 'one', 'example.com/one' ) ), null ),
			)
		);

		$this->ability->executePoll( $this->pollInput() );
		$limited = $this->ability->executePoll( $this->pollInput( array( 'max_pages' => 2 ) ) );
		$scope   = RedditCommentDomainStore::getScope( 'WordPress' );

		$this->assertFalse( $limited['success'] );
		$this->assertSame( 429, $limited['data']['errors']['WordPress']['data']['status'] );
		$this->assertSame( '60', $limited['data']['errors']['WordPress']['data']['rate_limit']['retry_after'] );
		$this->assertSame( 't1_one', $scope['checkpoint'] );
		$this->assertSame( 'older-page', $scope['pending']['after'] );

		$recovered = $this->ability->executePoll( $this->pollInput() );
		$this->assertTrue( $recovered['success'] );
		$this->assertSame( 't1_two', RedditCommentDomainStore::getScope( 'WordPress' )['checkpoint'] );
		$this->assertCount( 2, RedditCommentDomainStore::report( array( 'limit' => 100 ) ) );
	}

	public function test_malformed_comment_does_not_advance_checkpoint(): void {
		RedditCommentDomainStore::putScope( 'WordPress', array( 'checkpoint' => 't1_old', 'last_poll_at' => time() ) );
		$this->mockPages(
			array(
				$this->page( array( array( 'body' => 'example.com/missing-id' ) ), 'next-page' ),
			)
		);

		$result = $this->ability->executePoll( $this->pollInput() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 't1_old', RedditCommentDomainStore::getScope( 'WordPress' )['checkpoint'] );
		$this->assertArrayNotHasKey( 'pending', RedditCommentDomainStore::getScope( 'WordPress' ) );
	}

	public function test_lock_is_non_autoloaded_and_only_owner_can_release_it(): void {
		$token = RedditCommentDomainStore::acquireLock();
		$this->assertIsString( $token );
		$this->assertWPError( RedditCommentDomainStore::acquireLock() );
		RedditCommentDomainStore::releaseLock( 'different-owner' );
		$this->assertWPError( RedditCommentDomainStore::acquireLock() );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'datamachine_socials_reddit_comment_monitor_lock' ) );
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );

		RedditCommentDomainStore::releaseLock( $token );
		$this->assertIsString( RedditCommentDomainStore::acquireLock() );
	}

	public function test_poll_rejects_raw_access_token_input(): void {
		$result = $this->ability->executePoll( $this->pollInput( array( 'access_token' => 'must-not-be-accepted' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
	}

	public function test_report_filters_owner_organic_date_and_engagement(): void {
		$owner                  = $this->storedRecord( 'owner', time() );
		$owner['author']        = 'team_account';
		$owner['known_owner']   = false;
		$owner['score']         = 20;
		$organic                = $this->storedRecord( 'organic', time() - DAY_IN_SECONDS );
		$organic['author']      = 'community_member';
		$organic['known_owner'] = false;
		$organic['score']       = 2;
		RedditCommentDomainStore::upsert( array( $owner, $organic ) );

		$result = $this->ability->executeReport(
			array(
				'domain'       => 'example.com',
				'ownership'    => 'known_owner',
				'known_owners' => array( 'TEAM_ACCOUNT' ),
				'min_score'    => 10,
				'date_from'    => gmdate( 'Y-m-d', time() - HOUR_IN_SECONDS ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['count'] );
		$this->assertSame( 'team_account', $result['data']['matches'][0]['author'] );
		$this->assertTrue( $result['data']['matches'][0]['known_owner'] );
		$this->assertFalse( $result['coverage']['all_reddit_search'] );
		$this->assertFalse( $result['coverage']['historical_complete'] );
	}

	public function test_writes_remove_old_observations_and_enforce_cap(): void {
		add_filter( 'datamachine_socials_reddit_comment_retention_days', static fn(): int => 7 );
		add_filter( 'datamachine_socials_reddit_comment_max_records', static fn(): int => 100 );
		$records = array();
		for ( $index = 0; $index < 101; ++$index ) {
			$records[] = $this->storedRecord( 'recent-' . $index, time() - $index );
		}
		$records[] = $this->storedRecord( 'old', time() - ( 8 * DAY_IN_SECONDS ) );
		RedditCommentDomainStore::upsert( $records );

		$result = RedditCommentDomainStore::cleanup();

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 100, $result['retained'] );
		$this->assertCount( 100, RedditCommentDomainStore::report( array( 'limit' => 500 ) ) );
	}

	/** @param array<int,array<string,mixed>> $responses */
	private function mockPages( array $responses, ?array &$requested_after = null ): void {
		$requested_after = array();
		$index           = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $responses, &$requested_after, &$index ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$requested_after[] = (string) ( $query['after'] ?? '' );
				$response          = $responses[ $index ] ?? end( $responses );
				++$index;
				return $response;
			},
			10,
			3
		);
	}

	private function page( array $comments, ?string $after ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'after'    => $after,
						'children' => array_map( static fn( array $comment ): array => array( 'kind' => 't1', 'data' => $comment ), $comments ),
					),
				)
			),
		);
	}

	private function errorResponse( int $status, string $message, array $headers ): array {
		return array(
			'response' => array( 'code' => $status, 'message' => $message ),
			'headers'  => $headers,
			'body'     => wp_json_encode( array( 'message' => $message ) ),
		);
	}

	private function comment( string $id, string $body, string $author = 'reader', int $score = 5 ): array {
		return array(
			'id'          => $id,
			'name'        => 't1_' . $id,
			'body'        => $body,
			'author'      => $author,
			'created_utc' => time(),
			'score'       => $score,
			'subreddit'   => 'WordPress',
			'link_id'     => 't3_post-' . $id,
			'link_title'  => 'Parent ' . $id,
			'permalink'   => '/r/WordPress/comments/post-' . $id . '/x/' . $id . '/',
		);
	}

	private function pollInput( array $overrides = array() ): array {
		return array_merge(
			array(
				'domains'      => array( 'example.com' ),
				'subreddits'   => array( 'WordPress' ),
				'page_size'    => 100,
				'max_pages'    => 5,
			),
			$overrides
		);
	}

	private function storedRecord( string $id, int $seen ): array {
		return array(
			'comment_id'          => $id,
			'comment_fullname'    => 't1_' . $id,
			'parent_post_id'      => 'post-' . $id,
			'parent_post_title'   => 'Parent ' . $id,
			'subreddit'           => 'WordPress',
			'author'              => 'reader',
			'comment_created_utc' => $seen,
			'score'               => 5,
			'permalink'           => 'https://www.reddit.com/comment/' . $id,
			'domain'              => 'example.com',
			'matched_url'         => 'https://example.com/' . $id,
			'matched_host'        => 'example.com',
			'known_owner'         => false,
			'first_seen'          => $seen,
			'last_seen'           => $seen,
		);
	}
}
