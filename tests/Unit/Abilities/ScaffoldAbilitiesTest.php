<?php
/**
 * ScaffoldAbilities tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\File\ScaffoldAbilities;
use DataMachine\Core\FilesRepository\FilesystemHelper;
use WP_UnitTestCase;

class ScaffoldAbilitiesTest extends WP_UnitTestCase {

	public function test_existing_file_is_idempotent_but_write_failure_is_error(): void {
		$directory = get_temp_dir() . 'datamachine-scaffold-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $directory ) );
		$target = $directory . '/TEST.md';
		$this->assertNotFalse( file_put_contents( $target, "existing\n" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.

		$existing = ScaffoldAbilities::execute(
			array(
				'filename' => 'TEST.md',
				'filepath' => $target,
			)
		);
		$this->assertIsArray( $existing );
		$this->assertTrue( $existing['success'] );
		$this->assertFalse( $existing['created'] );
		$this->assertSame( "existing\n", file_get_contents( $target ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture assertion.
		unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture transition.

		$generator = static function ( string $content, string $filename ): string {
			return 'TEST.md' === $filename ? '# Test' : $content;
		};
		add_filter( 'datamachine_scaffold_content', $generator, 999, 2 );

		global $wp_filesystem;
		$original_filesystem = FilesystemHelper::get();
		$this->assertNotNull( $original_filesystem );
		$wp_filesystem = new class($target) extends \WP_Filesystem_Direct {
			private string $failed_path;

			public array $writes = array();

			public function __construct( string $failed_path ) {
				parent::__construct( null );
				$this->failed_path = $failed_path;
			}

			public function put_contents( $file, $contents, $mode = false ) {
				$this->writes[] = $file;
				if ( $this->failed_path === $file ) {
					return false;
				}

				return parent::put_contents( $file, $contents, $mode );
			}
		};

		$success_logs = 0;
		$logger       = static function ( string $level, string $message ) use ( &$success_logs ): void {
			if ( 'info' === $level && str_starts_with( $message, 'Scaffolded ' ) ) {
				++$success_logs;
			}
		};
		add_action( 'datamachine_log', $logger, 999, 2 );

		try {
			$failed = ScaffoldAbilities::execute(
				array(
					'filename' => 'TEST.md',
					'filepath' => $target,
				)
			);
			$this->assertWPError( $failed );
			$this->assertSame( 'scaffold_failed', $failed->get_error_code() );
			$this->assertStringContainsString( 'Failed to write TEST.md', $failed->get_error_message() );
			$this->assertFileDoesNotExist( $target );
			$this->assertSame( array( $directory . '/index.php', $target ), $wp_filesystem->writes );
			$this->assertSame( 0, $success_logs );
		} finally {
			remove_filter( 'datamachine_scaffold_content', $generator, 999 );
			remove_action( 'datamachine_log', $logger, 999 );
			$wp_filesystem = $original_filesystem;
			if ( file_exists( $directory . '/index.php' ) ) {
				unlink( $directory . '/index.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		}
	}

	public function test_index_protection_write_failure_does_not_create_target(): void {
		$directory = get_temp_dir() . 'datamachine-scaffold-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $directory ) );
		$target     = $directory . '/TEST.md';
		$index_path = $directory . '/index.php';
		$generator  = static function ( string $content, string $filename ): string {
			return 'TEST.md' === $filename ? '# Test' : $content;
		};
		add_filter( 'datamachine_scaffold_content', $generator, 999, 2 );

		global $wp_filesystem;
		$original_filesystem = FilesystemHelper::get();
		$this->assertNotNull( $original_filesystem );
		$wp_filesystem = new class($index_path) extends \WP_Filesystem_Direct {
			private string $failed_path;

			public array $writes = array();

			public function __construct( string $failed_path ) {
				parent::__construct( null );
				$this->failed_path = $failed_path;
			}

			public function put_contents( $file, $contents, $mode = false ) {
				$this->writes[] = $file;
				return $this->failed_path === $file ? false : parent::put_contents( $file, $contents, $mode );
			}
		};

		try {
			$failed = ScaffoldAbilities::execute(
				array(
					'filename' => 'TEST.md',
					'filepath' => $target,
				)
			);
			$this->assertWPError( $failed );
			$this->assertSame( 'scaffold_failed', $failed->get_error_code() );
			$this->assertFileDoesNotExist( $target );
			$this->assertSame( array( $index_path ), $wp_filesystem->writes );
		} finally {
			remove_filter( 'datamachine_scaffold_content', $generator, 999 );
			$wp_filesystem = $original_filesystem;
			rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
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
