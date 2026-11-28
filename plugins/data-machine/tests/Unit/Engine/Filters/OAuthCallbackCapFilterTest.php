<?php
/**
 * Tests for the datamachine_oauth_can_handle_callback filter.
 *
 * @package DataMachine\Tests\Unit\Engine\Filters
 */

namespace DataMachine\Tests\Unit\Engine\Filters;

use WP_UnitTestCase;

class OAuthCallbackCapFilterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'datamachine_oauth_can_handle_callback' );
		parent::tear_down();
	}

	/**
	 * Default behavior: admin user passes the cap check.
	 */
	public function test_default_allows_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Simulate the filter invocation as it appears in OAuth.php.
		$result = apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'instagram',
			array( 'code' => 'abc123', 'state' => 'xyz' )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Default behavior: subscriber is rejected by the default manage_options check.
	 */
	public function test_default_rejects_subscriber(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'instagram',
			array( 'code' => 'abc123' )
		);

		$this->assertFalse( $result );
	}

	/**
	 * Custom filter can grant access to non-admin users for specific providers.
	 */
	public function test_custom_filter_can_grant_access(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		add_filter( 'datamachine_oauth_can_handle_callback', function ( $can, $slug, $params ) {
			if ( 'artist-instagram-42' === $slug ) {
				return true;
			}
			return $can;
		}, 10, 3 );

		$result = apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'artist-instagram-42',
			array( 'code' => 'abc123' )
		);

		$this->assertTrue( $result );
	}

	/**
	 * Custom filter can deny access even for admin users.
	 */
	public function test_custom_filter_can_deny_access(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		add_filter( 'datamachine_oauth_can_handle_callback', function ( $can, $slug, $params ) {
			if ( 'disabled-provider' === $slug ) {
				return false;
			}
			return $can;
		}, 10, 3 );

		$result = apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'disabled-provider',
			array()
		);

		$this->assertFalse( $result );
	}

	/**
	 * Filter receives the correct arguments: provider slug and request params.
	 */
	public function test_filter_receives_correct_arguments(): void {
		$captured_slug   = null;
		$captured_params = null;
		$captured_can    = null;

		add_filter( 'datamachine_oauth_can_handle_callback', function ( $can, $slug, $params ) use ( &$captured_can, &$captured_slug, &$captured_params ) {
			$captured_can    = $can;
			$captured_slug   = $slug;
			$captured_params = $params;
			return $can;
		}, 10, 3 );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$test_params = array(
			'code'  => 'oauth_code_123',
			'state' => 'nonce_abc',
			'error' => '',
		);

		apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'my-custom-provider',
			$test_params
		);

		$this->assertTrue( $captured_can, 'Default $can should be true for admin' );
		$this->assertSame( 'my-custom-provider', $captured_slug );
		$this->assertSame( $test_params, $captured_params );
	}

	/**
	 * Without any custom filter, non-admin users are denied (preserves BC).
	 */
	public function test_no_filter_preserves_default_behavior_for_non_admin(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$result = apply_filters(
			'datamachine_oauth_can_handle_callback',
			current_user_can( 'manage_options' ),
			'twitter',
			array()
		);

		$this->assertFalse( $result, 'Editor should not pass manage_options check' );
	}

	/**
	 * Unknown providers are resolved before the capability filter runs.
	 */
	public function test_provider_lookup_happens_before_cap_filter(): void {
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/Engine/Filters/OAuth.php' );

		$this->assertIsString( $source );
		$this->assertLessThan(
			strpos( $source, 'datamachine_oauth_can_handle_callback' ),
			strpos( $source, '$auth_instance  = $auth_abilities->getProvider( $provider );' ),
			'Provider lookup must run before the callback cap filter so unknown providers 404 instead of 403.'
		);
	}
}
