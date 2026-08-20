<?php
/**
 * ScaffoldAbilities tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\File\ScaffoldAbilities;
use WP_UnitTestCase;

class ScaffoldAbilitiesTest extends WP_UnitTestCase {

	public function test_layer_scaffolding_aggregates_per_file_failures(): void {
		$result = ScaffoldAbilities::execute( array( 'layer' => 'principal' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'scaffold_layer_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 500, $data['status'] );
		$this->assertGreaterThanOrEqual( 1, $data['failed'] );
		$this->assertSame( $data['failed'], count( $data['failures'] ) );
		$this->assertSame( 'USER_MEMORY.md', $data['failures'][0]['filename'] );
		$this->assertStringContainsString( 'Could not resolve directory', $data['failures'][0]['error'] );
	}
}
