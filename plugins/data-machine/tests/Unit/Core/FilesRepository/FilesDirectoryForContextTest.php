<?php
/**
 * Files directory resolution for flow, direct, and standalone contexts.
 *
 * @package DataMachine\Tests\Unit\Core\FilesRepository
 */

namespace DataMachine\Tests\Unit\Core\FilesRepository;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Core\FilesRepository\FileStorage;
use WP_UnitTestCase;

class FilesDirectoryForContextTest extends WP_UnitTestCase {

	private DirectoryManager $directory_manager;
	private string $base;

	public function set_up(): void {
		parent::set_up();
		$this->directory_manager = new DirectoryManager();
		$this->base              = trailingslashit( wp_upload_dir()['basedir'] ) . 'datamachine-files';
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_flow_context_resolves_to_flow_files_directory(): void {
		$context = ExecutionContext::fromConfig(
			array(
				'pipeline_id' => 7,
				'flow_id'     => 12,
			),
			'1'
		)->getFileContext();

		$this->assertSame(
			$this->directory_manager->get_flow_files_directory( 7, 12 ),
			$this->directory_manager->get_files_directory_for_context( $context )
		);
		$this->assertSame(
			"{$this->base}/pipeline-7/flow-12/flow-12-files",
			$this->directory_manager->get_files_directory_for_context( $context )
		);
	}

	public function test_direct_context_resolves_to_direct_flow_files_directory(): void {
		$context = ExecutionContext::direct()->getFileContext();

		$this->assertSame(
			"{$this->base}/pipeline-direct/flow-direct/flow-direct-files",
			$this->directory_manager->get_files_directory_for_context( $context )
		);
	}

	public function test_standalone_context_with_job_resolves_to_job_bucket(): void {
		$context = ExecutionContext::standalone( '42' )->getFileContext();

		$this->assertSame(
			"{$this->base}/standalone/job-42/files",
			$this->directory_manager->get_files_directory_for_context( $context )
		);
	}

	public function test_standalone_context_without_job_resolves_to_shared_bucket(): void {
		$context = ExecutionContext::standalone()->getFileContext();

		$this->assertSame(
			"{$this->base}/standalone/files",
			$this->directory_manager->get_files_directory_for_context( $context )
		);
	}

	public function test_standalone_download_file_stores_file(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) {
				if ( ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], 'fake-image-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			10,
			2
		);

		$result = ExecutionContext::standalone( '3592' )->downloadFile( 'https://example.com/flyer.png', 'flyer.png' );

		$this->assertIsArray( $result );
		$this->assertSame( "{$this->base}/standalone/job-3592/files/flyer.png", $result['path'] );
		$this->assertFileExists( $result['path'] );
		$this->assertSame( 'fake-image-bytes', file_get_contents( $result['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		wp_delete_file( $result['path'] );
	}

	public function test_file_storage_accepts_standalone_context(): void {
		$context = ExecutionContext::standalone( '3593' )->getFileContext();
		$source  = wp_tempnam( 'dm-standalone' );
		file_put_contents( $source, 'stored' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$storage = new FileStorage();
		$stored  = $storage->store_file( $source, 'note.txt', $context );

		$this->assertSame( "{$this->base}/standalone/job-3593/files/note.txt", $stored );
		$this->assertCount( 1, $storage->get_all_files( $context ) );
		$this->assertTrue( $storage->delete_file( 'note.txt', $context ) );

		wp_delete_file( $source );
	}
}
