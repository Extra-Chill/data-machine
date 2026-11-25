<?php
/**
 * Tests for HandlerUrlResolver and datamachine/resolve-source-url.
 *
 * @package DataMachine\Tests\Unit\Core\Steps
 * @see https://github.com/Extra-Chill/data-machine/issues/4052
 */

namespace DataMachine\Tests\Unit\Core\Steps;

use DataMachine\Abilities\Handler\ResolveSourceUrlAbility;
use DataMachine\Core\Steps\HandlerUrlResolver;
use WP_UnitTestCase;

class HandlerUrlResolverTest extends WP_UnitTestCase {

	/**
	 * Registered fixture filter callback.
	 *
	 * @var callable|null
	 */
	private $filter = null;

	/**
	 * Number of times any fixture claim ran.
	 *
	 * @var int
	 */
	private int $claim_calls = 0;

	public function tear_down(): void {
		if ( $this->filter ) {
			remove_filter( 'datamachine_handlers', $this->filter, 10 );
			$this->filter = null;
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register fixture handlers. Each entry: slug => [ type, meta ].
	 *
	 * @param array<string, array{0: string, 1: array}> $fixtures Fixtures.
	 */
	private function register_fixtures( array $fixtures ): void {
		$this->filter = static function ( $handlers, $step_type = null ) use ( $fixtures ) {
			foreach ( $fixtures as $slug => [ $type, $meta ] ) {
				if ( null === $step_type || $step_type === $type ) {
					$handlers[ $slug ] = array(
						'type'  => $type,
						'label' => $slug,
						'meta'  => $meta,
					);
				}
			}
			return $handlers;
		};
		add_filter( 'datamachine_handlers', $this->filter, 10, 2 );
	}

	private function host_claim( string $host ): callable {
		return function ( string $url ) use ( $host ): ?array {
			++$this->claim_calls;
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				return null;
			}
			return array(
				'source_host' => $host,
				'path'        => (string) wp_parse_url( $url, PHP_URL_PATH ),
			);
		};
	}

	private function catch_all_claim(): callable {
		return function ( string $url ): ?array {
			++$this->claim_calls;
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			return in_array( $scheme, array( 'http', 'https' ), true ) ? array( 'source_url' => $url ) : null;
		};
	}

	private function standard_fixtures(): void {
		$this->register_fixtures(
			array(
				'fixture_catch_all' => array(
					'fixture_fetch',
					array(
						'url_claim'          => $this->catch_all_claim(),
						'url_claim_priority' => 100,
					),
				),
				'fixture_specific'  => array(
					'fixture_fetch',
					array( 'url_claim' => $this->host_claim( 'social.example.com' ) ),
				),
				'fixture_other_step' => array(
					'fixture_publish',
					array(
						'url_claim'          => $this->host_claim( 'social.example.com' ),
						'url_claim_priority' => 1,
					),
				),
				'fixture_no_claim'  => array( 'fixture_fetch', array() ),
			)
		);
	}

	public function test_lower_priority_wins_over_registration_order(): void {
		$this->standard_fixtures();

		$result = HandlerUrlResolver::resolve( 'https://social.example.com/some-account', 'fixture_fetch' );

		$this->assertIsArray( $result );
		$this->assertSame( 'fixture_specific', $result['handler_slug'] );
		$this->assertSame( 10, $result['priority'] );
		$this->assertSame(
			array(
				'source_host' => 'social.example.com',
				'path'        => '/some-account',
			),
			$result['handler_config']
		);

		$candidates = HandlerUrlResolver::candidates( 'https://social.example.com/some-account', 'fixture_fetch' );
		$this->assertSame( array( 'fixture_specific', 'fixture_catch_all' ), array_column( $candidates, 'handler_slug' ) );
	}

	public function test_equal_priority_falls_back_to_registration_order(): void {
		$this->register_fixtures(
			array(
				'fixture_first'  => array( 'fixture_fetch', array( 'url_claim' => $this->catch_all_claim() ) ),
				'fixture_second' => array( 'fixture_fetch', array( 'url_claim' => $this->catch_all_claim() ) ),
			)
		);

		$candidates = HandlerUrlResolver::candidates( 'https://example.com/', 'fixture_fetch' );
		$this->assertSame( array( 'fixture_first', 'fixture_second' ), array_column( $candidates, 'handler_slug' ) );
	}

	public function test_step_type_filters_visible_handlers(): void {
		$this->standard_fixtures();

		$fetch = HandlerUrlResolver::candidates( 'https://social.example.com/a', 'fixture_fetch' );
		$this->assertNotContains( 'fixture_other_step', array_column( $fetch, 'handler_slug' ) );

		$publish = HandlerUrlResolver::resolve( 'https://social.example.com/a', 'fixture_publish' );
		$this->assertSame( 'fixture_other_step', $publish['handler_slug'] );

		// Without a step type every handler is visible, so the priority-1 claim wins.
		$any = HandlerUrlResolver::resolve( 'https://social.example.com/a' );
		$this->assertSame( 'fixture_other_step', $any['handler_slug'] );
	}

	public function test_null_claim_is_skipped(): void {
		$this->standard_fixtures();

		$result = HandlerUrlResolver::resolve( 'https://unrelated.example.org/page', 'fixture_fetch' );
		$this->assertSame( 'fixture_catch_all', $result['handler_slug'] );
		$this->assertSame( 100, $result['priority'] );

		$this->assertNull( HandlerUrlResolver::resolve( 'ftp://unrelated.example.org/file', 'fixture_fetch' ) );
		$this->assertSame( array(), HandlerUrlResolver::candidates( 'ftp://unrelated.example.org/file', 'fixture_fetch' ) );
	}

	public function test_throwing_claim_does_not_break_resolution(): void {
		$this->register_fixtures(
			array(
				'fixture_broken' => array(
					'fixture_fetch',
					array(
						'url_claim'          => static function (): ?array {
							throw new \RuntimeException( 'broken claim' );
						},
						'url_claim_priority' => 1,
					),
				),
				'fixture_ok'     => array( 'fixture_fetch', array( 'url_claim' => $this->catch_all_claim() ) ),
			)
		);

		$this->assertSame( 'fixture_ok', HandlerUrlResolver::resolve( 'https://example.com/', 'fixture_fetch' )['handler_slug'] );
	}

	public function test_malformed_url_resolves_to_nothing_without_calling_claims(): void {
		$this->standard_fixtures();

		foreach ( array( '', '   ', 'not a url', 'example.com/no-scheme', 'https://', 'http:///path-only' ) as $url ) {
			$this->assertNull( HandlerUrlResolver::resolve( $url, 'fixture_fetch' ), $url );
			$this->assertSame( array(), HandlerUrlResolver::candidates( $url ), $url );
		}

		$this->assertSame( 0, $this->claim_calls );
	}

	public function test_ability_returns_best_claim_and_candidates(): void {
		$this->standard_fixtures();
		new ResolveSourceUrlAbility();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$ability = wp_get_ability( 'datamachine/resolve-source-url' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'url'       => 'https://social.example.com/some-account',
				'step_type' => 'fixture_fetch',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'fixture_specific', $result['handler_slug'] );
		$this->assertSame( 10, $result['priority'] );
		$this->assertCount( 2, $result['candidates'] );

		$error = $ability->execute( array( 'url' => 'not a url' ) );
		$this->assertWPError( $error );
	}

	public function test_ability_requires_logged_in_user(): void {
		wp_set_current_user( 0 );
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->assertFalse( ResolveSourceUrlAbility::can_execute() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( ResolveSourceUrlAbility::can_execute() );
	}
}
