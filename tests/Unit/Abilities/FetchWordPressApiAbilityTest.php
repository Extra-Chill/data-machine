<?php
/**
 * FetchWordPressApiAbility tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Fetch\FetchWordPressApiAbility;
use WP_UnitTestCase;

class FetchWordPressApiAbilityTest extends WP_UnitTestCase {

	public function test_missing_endpoint_returns_specific_bad_request_error(): void {
		$result = ( new FetchWordPressApiAbility() )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_api_endpoint_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'logs', $result->get_error_data() );
	}

	public function test_invalid_endpoint_returns_specific_bad_request_error(): void {
		$result = ( new FetchWordPressApiAbility() )->execute(
			array( 'endpoint_url' => 'not-a-url' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_api_endpoint_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'not-a-url', $result->get_error_data()['endpoint_url'] );
	}

	public function test_invalid_upstream_json_returns_specific_bad_gateway_error(): void {
		$intercept = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{invalid',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $intercept );
		try {
			$result = ( new FetchWordPressApiAbility() )->execute(
				array( 'endpoint_url' => 'https://example.test/wp-json/wp/v2/posts' )
			);
		} finally {
			remove_filter( 'pre_http_request', $intercept );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_api_invalid_response', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 'https://example.test/wp-json/wp/v2/posts', $result->get_error_data()['endpoint_url'] );
		$this->assertArrayHasKey( 'json_error', $result->get_error_data() );
	}

	public function test_upstream_http_error_preserves_code_status_and_data(): void {
		$intercept = static function () {
			return array(
				'headers'  => array(),
				'body'     => 'Missing upstream resource.',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $intercept );
		try {
			$result = ( new FetchWordPressApiAbility() )->execute(
				array( 'endpoint_url' => 'https://example.test/wp-json/wp/v2/posts/404' )
			);
		} finally {
			remove_filter( 'pre_http_request', $intercept );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'fetch_http_status', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 404, $result->get_error_data()['status_code'] );
		$this->assertSame( 'Missing upstream resource.', $result->get_error_data()['body'] );
		$this->assertArrayHasKey( 'logs', $result->get_error_data() );
	}
}
