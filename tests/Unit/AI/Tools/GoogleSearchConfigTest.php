<?php
/**
 * Tests for GoogleSearch configuration guard logic.
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\Global\GoogleSearch;
use WP_UnitTestCase;

class GoogleSearchConfigTest extends WP_UnitTestCase {

	private GoogleSearch $tool;

	public function set_up(): void {
		parent::set_up();
		$this->tool = new GoogleSearch();
	}

	public function tear_down(): void {
		delete_site_option( 'datamachine_search_config' );
		parent::tear_down();
	}

	public function test_get_config_fields_returns_fields_for_google_search(): void {
		$fields = $this->tool->get_config_fields( [], 'google_search' );
		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'search_engine_id', $fields );
	}

	public function test_get_config_fields_returns_fields_when_tool_id_empty(): void {
		$fields = $this->tool->get_config_fields( [], '' );
		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'search_engine_id', $fields );
	}

	public function test_get_config_fields_passthrough_for_different_tool_id(): void {
		$existing = [ 'foo' => 'bar' ];
		$result = $this->tool->get_config_fields( $existing, 'image_generation' );
		$this->assertSame( $existing, $result );
	}

	public function test_check_configuration_passthrough_for_wrong_tool_id(): void {
		$this->assertFalse( $this->tool->check_configuration( false, 'image_generation' ) );
		$this->assertTrue( $this->tool->check_configuration( true, 'image_generation' ) );
	}

	public function test_check_configuration_returns_status_for_google_search(): void {
		delete_site_option( 'datamachine_search_config' );
		$this->assertFalse( $this->tool->check_configuration( true, 'google_search' ) );

		update_site_option( 'datamachine_search_config', [
			'google_search' => [
				'api_key'          => 'test-key',
				'search_engine_id' => 'test-cx',
			],
		] );
		$this->assertTrue( $this->tool->check_configuration( false, 'google_search' ) );
	}
}
