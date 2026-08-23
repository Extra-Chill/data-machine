<?php
/**
 * RedditDomainMentionsAbility tests.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Reddit
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Reddit;

use DataMachineSocials\Abilities\Reddit\RedditDomainMentionsAbility;
use WP_UnitTestCase;

class RedditDomainMentionsAbilityTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_registered_contract_keeps_oauth_server_side_and_describes_report_rows(): void {
		new RedditDomainMentionsAbility();
		$ability = wp_get_ability( 'datamachine/reddit-domain-mentions' );

		$this->assertNotNull( $ability );
		$input_schema = $ability->get_input_schema();
		$this->assertSame( array( 'domain' ), $input_schema['required'] );
		$this->assertArrayNotHasKey( 'access_token', $input_schema['properties'] );

		$output_schema = $ability->get_output_schema();
		$this->assertSame( array( 'success', 'report' ), $output_schema['required'] );
		$row_schema = $output_schema['properties']['report']['properties']['rows']['items'];
		$this->assertContains( 'matched_target_url', $row_schema['required'] );
		$this->assertContains( 'ownership', $row_schema['required'] );
	}

	public function test_reports_direct_and_self_text_mentions_with_dedupe_owners_and_subdomains(): void {
		$direct_post = $this->redditPost(
			'direct-1',
			array(
				'author'  => 'Own_User',
				'is_self' => false,
				'url'     => 'https://news.example.org/story',
			)
		);

		$this->mockSearches(
			array(
				'url:example.org' => array( $direct_post ),
				'example.org'     => array(
					$direct_post,
					$this->redditPost(
						'text-1',
						array(
							'author'   => 'community_member',
							'selftext' => 'Calendar: https://events.example.org/upcoming',
						)
					),
					$this->redditPost(
						'lookalike',
						array( 'selftext' => 'Not a match: https://example.org.evil.test/page' )
					),
				),
			)
		);

		$result = $this->ability()->execute(
			array(
				'domain' => 'https://Example.org/',
				'owners' => array( 'u/own_user' ),
				'limit'  => 10,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$report = $result['report'];
		$this->assertSame( 'example.org', $report['domain'] );
		$this->assertSame( array( 'total' => 2, 'owned' => 1, 'organic' => 1 ), $report['totals'] );
		$this->assertSame( array( 'news.example.org' => 1, 'events.example.org' => 1 ), $report['breakdowns']['matched_host'] );
		$this->assertSame( array( 'direct-1', 'text-1' ), array_column( $report['rows'], 'item_id' ) );
		$this->assertSame( array( 'direct_link', 'self_text' ), array_column( $report['rows'], 'match_type' ) );
		$this->assertSame( 'https://news.example.org/story', $report['rows'][0]['matched_target_url'] );
		$this->assertSame( 'https://events.example.org/upcoming', $report['rows'][1]['matched_target_url'] );
		$this->assertFalse( $report['truncated'] );
	}

	public function test_matches_bare_domain_in_self_text(): void {
		$this->mockSearches(
			array(
				'url:example.org' => array(),
				'example.org'     => array(
					$this->redditPost( 'bare', array( 'selftext' => 'Browse guides.example.org for details.' ) ),
				),
			)
		);

		$result = $this->ability()->execute( $this->input() );

		$this->assertSame( 1, $result['report']['totals']['total'] );
		$this->assertSame( 'guides.example.org', $result['report']['rows'][0]['matched_host'] );
		$this->assertSame( '', $result['report']['rows'][0]['matched_target_url'] );
	}

	public function test_empty_searches_return_an_empty_report(): void {
		$this->mockSearches(
			array(
				'url:example.org' => array(),
				'example.org'     => array(),
			)
		);

		$result = $this->ability()->execute( $this->input() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 'total' => 0, 'owned' => 0, 'organic' => 0 ), $result['report']['totals'] );
		$this->assertSame( array(), $result['report']['rows'] );
		$this->assertFalse( $result['report']['truncated'] );
	}

	/**
	 * @dataProvider malformedDomainProvider
	 */
	public function test_rejects_malformed_domains( string $domain ): void {
		$result = $this->ability()->execute(
			array(
				'domain' => $domain,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_domain', $result->get_error_code() );
	}

	public function malformedDomainProvider(): array {
		return array(
			'path'        => array( 'example.org/articles' ),
			'credentials' => array( 'https://user:pass@example.org/' ),
			'query'       => array( 'https://example.org/?source=reddit' ),
			'port'        => array( 'example.org:8080' ),
			'not public'  => array( 'localhost' ),
		);
	}

	public function test_marks_report_truncated_when_deduplicated_rows_exceed_the_limit(): void {
		$this->mockSearches(
			array(
				'url:example.org' => array(
					$this->redditPost(
						'direct-1',
						array(
							'is_self' => false,
							'url'     => 'https://example.org/story',
						)
					),
				),
				'example.org'     => array(
					$this->redditPost( 'text-1', array( 'selftext' => 'Read example.org for more.' ) ),
				),
			)
		);

		$result = $this->ability()->execute( $this->input( array( 'limit' => 1 ) ) );

		$this->assertSame( 1, $result['report']['totals']['total'] );
		$this->assertTrue( $result['report']['truncated'] );
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $searches
	 */
	private function mockSearches( array $searches ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $searches ): array {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
				$posts = $searches[ $params['q'] ?? '' ] ?? array();

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'after'    => null,
								'children' => array_map(
									static fn( array $post ): array => array(
										'kind' => 't3',
										'data' => $post,
									),
									$posts
								),
							),
						)
					),
				);
			},
			10,
			3
		);
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'domain' => 'example.org',
				'limit'  => 10,
			),
			$overrides
		);
	}

	private function ability(): RedditDomainMentionsAbility {
		$provider = new class() {
			public function get_valid_access_token(): string {
				return 'reddit-token';
			}
		};

		return new class( $provider ) extends RedditDomainMentionsAbility {
			private object $provider;

			public function __construct( object $provider ) {
				$this->provider = $provider;
				parent::__construct();
			}

			protected function resolveProvider( string $platform, string $label, bool $require_authenticated = true ) {
				return $this->provider;
			}
		};
	}

	private function redditPost( string $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => $id,
				'title'        => 'Post ' . $id,
				'selftext'     => '',
				'created_utc'  => 1704067200,
				'score'        => 10,
				'num_comments' => 3,
				'permalink'    => '/r/testing/comments/' . $id . '/',
				'url'          => 'https://www.reddit.com/r/testing/comments/' . $id . '/',
				'subreddit'    => 'testing',
				'author'       => 'reddit_user',
				'is_self'      => true,
			),
			$overrides
		);
	}
}
