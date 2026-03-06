<?php
/**
 * DataMachineFilters Tests
 *
 * Tests for utility filters in Engine/Filters/DataMachineFilters.php.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use WP_UnitTestCase;

class DataMachineFiltersTest extends WP_UnitTestCase {

	private string $option_name = 'chubes_ai_http_shared_api_keys';

	public function set_up(): void {
		parent::set_up();
		delete_site_option( $this->option_name );
	}

	public function tear_down(): void {
		delete_site_option( $this->option_name );
		parent::tear_down();
	}

	public function test_provider_key_filter_normalizes_serialized_option_string(): void {
		$serialized = serialize(
			array(
				'openai' => 'sk-test',
				'gemini' => '',
			)
		);

		update_site_option( $this->option_name, $serialized );

		$normalized = apply_filters( 'chubes_ai_provider_api_keys', get_site_option( $this->option_name ) );

		$this->assertIsArray( $normalized );
		$this->assertSame( 'sk-test', $normalized['openai'] );
		$this->assertSame( '', $normalized['gemini'] );
		$this->assertIsArray( get_site_option( $this->option_name ) );
	}

	public function test_provider_key_filter_leaves_normal_arrays_unchanged(): void {
		$keys = array(
			'openai' => 'sk-test',
			'grok'   => '',
		);

		update_site_option( $this->option_name, $keys );

		$result = apply_filters( 'chubes_ai_provider_api_keys', $keys );

		$this->assertSame( $keys, $result );
		$this->assertSame( $keys, get_site_option( $this->option_name ) );
	}
}
