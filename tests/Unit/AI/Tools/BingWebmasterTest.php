<?php
/**
 * Tests for BingWebmaster global tool.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\BingWebmaster;
use WP_UnitTestCase;
use WP_Error;

class BingWebmasterTest extends WP_UnitTestCase {

	private BingWebmaster $tool;

	public function set_up(): void {
		parent::set_up();

		// Ability execute() requires manage_options capability.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->tool = new BingWebmaster();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		parent::tear_down();
	}

	/**
	 * Test tool definition has required fields.
	 */
	public function test_get_tool_definition(): void {
		$def = $this->tool->getToolDefinition();

		$this->assertSame( BingWebmaster::class, $def['class'] );
		$this->assertSame( 'handle_tool_call', $def['method'] );
		$this->assertArrayHasKey( 'description', $def );
		$this->assertArrayHasKey( 'parameters', $def );
		$this->assertTrue( $def['requires_config'] );
		$this->assertArrayHasKey( 'action', $def['parameters'] );
		$this->assertTrue( $def['parameters']['action']['required'] );
		$this->assertArrayHasKey( 'site_url', $def['parameters'] );
		$this->assertFalse( $def['parameters']['site_url']['required'] );
		$this->assertArrayHasKey( 'limit', $def['parameters'] );
		$this->assertFalse( $def['parameters']['limit']['required'] );
	}

	/**
	 * Test handle_tool_call handles WP_Error from HTTP failure.
	 */
	public function test_handle_tool_call_wp_error(): void {
		$filter = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				return new WP_Error( 'http_request_failed', 'Bing API connection failed' );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_site_option( 'datamachine_bing_webmaster_config', array( 'api_key' => 'test-key' ) );

		$result = $this->tool->handle_tool_call( array( 'action' => 'query_stats' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'failed', strtolower( $result['error'] ) );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call handles error from ability result (e.g. invalid API key).
	 */
	public function test_handle_tool_call_ability_error(): void {
		$filter = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				return array(
					'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
					'body'     => wp_json_encode( array( 'Message' => 'Invalid API key' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_site_option( 'datamachine_bing_webmaster_config', array( 'api_key' => 'bad-key' ) );

		$result = $this->tool->handle_tool_call( array( 'action' => 'query_stats' ) );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test handle_tool_call delegates to ability successfully.
	 */
	public function test_handle_tool_call_success(): void {
		$filter = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'ssl.bing.com' ) ) {
				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => wp_json_encode( array(
						'd' => array(
							array(
								'Query' => 'test query 1',
								'Clicks' => 100,
								'Date' => '/Date(1700000000000)/',
							),
							array(
								'Query' => 'test query 2',
								'Clicks' => 50,
								'Date' => '/Date(1700100000000)/',
							),
						),
					) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		update_site_option( 'datamachine_bing_webmaster_config', array(
			'api_key'  => 'test-key',
			'site_url' => 'https://example.com',
		) );

		$result = $this->tool->handle_tool_call( array( 'action' => 'query_stats', 'limit' => 20 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'query_stats', $result['action'] );
		$this->assertSame( 'bing_webmaster', $result['tool_name'] );

		remove_filter( 'pre_http_request', $filter, 10 );
	}

	/**
	 * Test configuration field definitions.
	 */
	public function test_get_config_fields(): void {
		$fields = $this->tool->get_config_fields( [], 'bing_webmaster' );

		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'site_url', $fields );

		$this->assertSame( 'password', $fields['api_key']['type'] );
		$this->assertTrue( $fields['api_key']['required'] );
		$this->assertSame( 'text', $fields['site_url']['type'] );
		$this->assertFalse( $fields['site_url']['required'] );
	}

	/**
	 * Test get_config_fields passthrough for different tool_id.
	 */
	public function test_get_config_fields_passthrough(): void {
		$existing = array( 'foo' => 'bar' );
		$result = $this->tool->get_config_fields( $existing, 'image_generation' );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Test check_configuration passthrough for wrong tool_id.
	 */
	public function test_check_configuration_passthrough(): void {
		$this->assertFalse( $this->tool->check_configuration( false, 'image_generation' ) );
		$this->assertTrue( $this->tool->check_configuration( true, 'image_generation' ) );
	}

	/**
	 * Test check_configuration for bing_webmaster tool.
	 */
	public function test_check_configuration_bing_webmaster(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertFalse( $this->tool->check_configuration( true, 'bing_webmaster' ) );

		update_site_option( 'datamachine_bing_webmaster_config', array( 'api_key' => 'test-key' ) );
		$this->assertTrue( $this->tool->check_configuration( false, 'bing_webmaster' ) );
	}

	/**
	 * Test get_configuration passthrough for wrong tool_id.
	 */
	public function test_get_configuration_passthrough(): void {
		$existing = array( 'foo' => 'bar' );
		$result = $this->tool->get_configuration( $existing, 'image_generation' );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Test get_configuration for bing_webmaster tool.
	 */
	public function test_get_configuration_bing_webmaster(): void {
		$config = array( 'api_key' => 'test-key', 'site_url' => 'https://example.com' );
		update_site_option( 'datamachine_bing_webmaster_config', $config );

		$result = $this->tool->get_configuration( array(), 'bing_webmaster' );
		$this->assertSame( $config, $result );
	}

	/**
	 * Test is_configured returns false when no config.
	 */
	public function test_is_configured_false(): void {
		delete_site_option( 'datamachine_bing_webmaster_config' );
		$this->assertFalse( BingWebmaster::is_configured() );
	}

	/**
	 * Test is_configured returns true when configured.
	 */
	public function test_is_configured_true(): void {
		update_site_option( 'datamachine_bing_webmaster_config', array( 'api_key' => 'test-key' ) );
		$this->assertTrue( BingWebmaster::is_configured() );
	}
}
