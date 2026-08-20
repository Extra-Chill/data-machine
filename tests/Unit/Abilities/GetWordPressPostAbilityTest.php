<?php
/**
 * GetWordPressPostAbility tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Fetch\GetWordPressPostAbility;
use WP_UnitTestCase;

class GetWordPressPostAbilityTest extends WP_UnitTestCase {

	public function test_missing_identifier_returns_specific_bad_request_error(): void {
		$result = ( new GetWordPressPostAbility() )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_post_identifier_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'logs', $result->get_error_data() );
	}

	public function test_invalid_source_url_returns_specific_bad_request_error(): void {
		$result = ( new GetWordPressPostAbility() )->execute(
			array( 'source_url' => 'not-a-wordpress-url' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_post_source_url_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'not-a-wordpress-url', $result->get_error_data()['source_url'] );
	}

	public function test_missing_post_returns_specific_not_found_error(): void {
		$result = ( new GetWordPressPostAbility() )->execute(
			array( 'post_id' => 999999999 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wordpress_post_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 999999999, $result->get_error_data()['post_id'] );
	}
}
