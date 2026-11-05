<?php
/**
 * Regression coverage for the offline PHPUnit policy (#3901).
 *
 * @package DataMachineEvents\Tests
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;

class OfflineHttpTest extends WP_UnitTestCase {
	public function test_unmocked_http_is_blocked(): void {
		$response = wp_remote_get( 'https://example.org/dme-unmocked' );
		$this->assertWPError( $response );
		$this->assertSame( 'http_request_not_executed', $response->get_error_code() );
	}

	public function test_explicit_http_fixtures_still_work(): void {
		$mock = static function () {
			return array( 'body' => 'offline fixture', 'response' => array( 'code' => 200 ) );
		};
		add_filter( 'pre_http_request', $mock );
		try {
			$this->assertSame( 'offline fixture', wp_remote_retrieve_body( wp_remote_get( 'https://example.org/dme-mocked' ) ) );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
		$this->assertWPError( wp_remote_get( 'https://example.org/dme-after-mock' ) );
	}
}
