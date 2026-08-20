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

	public function test_existing_file_is_idempotent_but_write_failure_is_error(): void {
		$parent = wp_tempnam( 'datamachine-scaffold' );
		$this->assertNotFalse( $parent );

		$existing = ScaffoldAbilities::execute(
			array(
				'filename' => 'TEST.md',
				'filepath' => $parent,
			)
		);
		$this->assertIsArray( $existing );
		$this->assertTrue( $existing['success'] );
		$this->assertFalse( $existing['created'] );

		$generator = static function ( string $content, string $filename ): string {
			return 'TEST.md' === $filename ? '# Test' : $content;
		};
		add_filter( 'datamachine_scaffold_content', $generator, 999, 2 );

		try {
			$failed = ScaffoldAbilities::execute(
				array(
					'filename' => 'TEST.md',
					'filepath' => $parent . '/TEST.md',
				)
			);
			$this->assertWPError( $failed );
			$this->assertSame( 'scaffold_failed', $failed->get_error_code() );
			$this->assertStringContainsString( 'Failed to write TEST.md', $failed->get_error_message() );
		} finally {
			remove_filter( 'datamachine_scaffold_content', $generator, 999 );
			unlink( $parent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		}
	}

	public function test_layer_scaffolding_skips_machine_managed_files(): void {
		$result = ScaffoldAbilities::execute(
			array(
				'layer'      => 'agent',
				'agent_slug' => 'scaffold-machine-managed-' . wp_generate_uuid4(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$wake = array_values( array_filter( $result['files'], static fn( array $file ): bool => 'WAKE.md' === $file['filename'] ) );
		$this->assertCount( 1, $wake );
		$this->assertTrue( $wake[0]['skipped'] );
		$this->assertFalse( $wake[0]['created'] );
	}

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
