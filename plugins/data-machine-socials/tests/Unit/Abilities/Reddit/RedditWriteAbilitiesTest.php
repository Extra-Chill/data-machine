<?php
/**
 * Reddit reply/submit/vote ability tests.
 *
 * Covers the server-side OAuth contract: the abilities never accept an
 * access token through input and resolve it from the Reddit auth provider.
 *
 * @package DataMachineSocials\Tests\Unit\Abilities\Reddit
 */

namespace DataMachineSocials\Tests\Unit\Abilities\Reddit;

use DataMachine\Abilities\AuthAbilities;
use DataMachineSocials\Abilities\Reddit\ReplyRedditAbility;
use DataMachineSocials\Abilities\Reddit\SubmitRedditAbility;
use DataMachineSocials\Abilities\Reddit\VoteRedditAbility;
use WP_UnitTestCase;

class RedditWriteAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Stub Reddit auth provider filter callback.
	 *
	 * @var callable|null
	 */
	private $provider_filter = null;

	/**
	 * Captured outbound HTTP requests.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests = array();
		$this->stubRedditProvider( true );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				$body = str_ends_with( $url, '/api/vote' )
					? array()
					: array(
						'json' => array(
							'errors' => array(),
							'data'   => array(
								'id'     => 'new123',
								'name'   => 't3_new123',
								'url'    => 'https://www.reddit.com/r/test/comments/new123/',
								'things' => array(
									array(
										'data' => array(
											'id'        => 'c1',
											'permalink' => '/r/test/comments/abc/_/c1/',
											'author'    => 'bot',
										),
									),
								),
							),
						),
					);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
				);
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		if ( null !== $this->provider_filter ) {
			remove_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		}
		AuthAbilities::clearCache();
		parent::tear_down();
	}

	/**
	 * @dataProvider abilityProvider
	 */
	public function test_input_schema_does_not_accept_access_token( string $class, string $ability_name, array $input ): void {
		new $class();
		$schema = wp_get_ability( $ability_name )->get_input_schema();

		$this->assertArrayNotHasKey( 'access_token', $schema['properties'] );
		$this->assertNotContains( 'access_token', $schema['required'] );
	}

	/**
	 * @dataProvider abilityProvider
	 */
	public function test_executes_without_token_input_using_provider_token( string $class, string $ability_name, array $input ): void {
		$result = ( new $class() )->execute( $input + array( 'access_token' => 'caller-supplied' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'Bearer provider-token', $this->requests[0]['args']['headers']['Authorization'] );
	}

	/**
	 * @dataProvider abilityProvider
	 */
	public function test_returns_missing_auth_when_provider_is_unauthenticated( string $class, string $ability_name, array $input ): void {
		$this->stubRedditProvider( false );

		$result = ( new $class() )->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_auth', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function abilityProvider(): array {
		return array(
			'reply'  => array(
				ReplyRedditAbility::class,
				'datamachine/reply-reddit',
				array(
					'thing_id' => 't3_abc123',
					'text'     => 'Thanks for sharing!',
				),
			),
			'submit' => array(
				SubmitRedditAbility::class,
				'datamachine/submit-reddit',
				array(
					'subreddit' => 'test',
					'title'     => 'Hello',
					'text'      => 'Body',
				),
			),
			'vote'   => array(
				VoteRedditAbility::class,
				'datamachine/vote-reddit',
				array(
					'thing_id'  => 't1_def456',
					'direction' => 1,
				),
			),
		);
	}

	private function stubRedditProvider( bool $authenticated ): void {
		if ( null !== $this->provider_filter ) {
			remove_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		}

		$provider = new class( $authenticated ) {
			public function __construct( private bool $authenticated ) {}

			public function is_authenticated(): bool {
				return $this->authenticated;
			}

			public function get_valid_access_token(): ?string {
				return 'provider-token';
			}
		};

		$this->provider_filter = static function ( $providers ) use ( $provider ) {
			$providers['reddit'] = $provider;
			return $providers;
		};
		add_filter( 'datamachine_auth_providers', $this->provider_filter, PHP_INT_MAX );
		AuthAbilities::clearCache();
	}
}
