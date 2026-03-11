<?php
/**
 * Tests for AIStep AI payload sanitization.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\AI
 */

namespace DataMachine\Tests\Unit\Core\Steps\AI;

use DataMachine\Core\Steps\AI\AIStep;
use PHPUnit\Framework\TestCase;

class AIStepTest extends TestCase {

	public function test_sanitize_data_packets_for_ai_removes_file_path_but_keeps_other_file_info(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title'     => 'Test post',
					'body'      => 'Body',
					'file_info' => array(
						'file_path' => '/var/www/extrachill.com/wp-content/uploads/dm-files/test.jpg',
						'file_name' => 'test.jpg',
						'mime_type' => 'image/jpeg',
						'file_size' => 12345,
					),
				),
				'metadata' => array(),
			),
		);

		$sanitized = AIStep::sanitizeDataPacketsForAi( $data_packets );

		$this->assertArrayNotHasKey( 'file_path', $sanitized[0]['data']['file_info'] );
		$this->assertSame( 'test.jpg', $sanitized[0]['data']['file_info']['file_name'] );
		$this->assertSame( 'image/jpeg', $sanitized[0]['data']['file_info']['mime_type'] );
		$this->assertSame( 12345, $sanitized[0]['data']['file_info']['file_size'] );

		// Original packet remains unchanged for runtime behavior.
		$this->assertSame(
			'/var/www/extrachill.com/wp-content/uploads/dm-files/test.jpg',
			$data_packets[0]['data']['file_info']['file_path']
		);
	}

	public function test_sanitize_data_packets_for_ai_drops_empty_file_info_after_redaction(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'file_info' => array(
						'file_path' => '/tmp/only-path.png',
					),
				),
				'metadata' => array(),
			),
		);

		$sanitized = AIStep::sanitizeDataPacketsForAi( $data_packets );

		$this->assertArrayNotHasKey( 'file_info', $sanitized[0]['data'] );
	}

	public function test_sanitize_data_packets_for_ai_leaves_packets_without_file_info_unchanged(): void {
		$data_packets = array(
			array(
				'type'     => 'fetch',
				'data'     => array(
					'title' => 'No file info',
					'body'  => 'Still here',
				),
				'metadata' => array( 'source_type' => 'rss' ),
			),
		);

		$this->assertSame( $data_packets, AIStep::sanitizeDataPacketsForAi( $data_packets ) );
	}
}
