<?php
/**
 * Inspect AI request ability tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\AI\InspectRequestAbility;
use WP_UnitTestCase;

class InspectRequestAbilityTest extends WP_UnitTestCase {

	public function test_invalid_job_id_returns_validation_error(): void {
		$result = ( new InspectRequestAbility() )->execute( array( 'job_id' => 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_job_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_missing_job_returns_not_found_error(): void {
		$result = ( new InspectRequestAbility() )->execute( array( 'job_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'job_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
