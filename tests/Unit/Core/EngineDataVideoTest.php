<?php
/**
 * Tests for EngineData video support.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\EngineData;
use WP_UnitTestCase;

class EngineDataVideoTest extends WP_UnitTestCase {

	public function test_get_video_path_returns_null_when_absent(): void {
		$engine = new EngineData( array() );
		$this->assertNull( $engine->getVideoPath() );
	}

	public function test_get_video_path_returns_value(): void {
		$engine = new EngineData( array( 'video_file_path' => '/tmp/video.mp4' ) );
		$this->assertSame( '/tmp/video.mp4', $engine->getVideoPath() );
	}

	public function test_get_video_path_independent_of_image_path(): void {
		$engine = new EngineData( array(
			'image_file_path' => '/tmp/image.jpg',
			'video_file_path' => '/tmp/video.mp4',
		) );
		$this->assertSame( '/tmp/image.jpg', $engine->getImagePath() );
		$this->assertSame( '/tmp/video.mp4', $engine->getVideoPath() );
	}

	public function test_set_video_path(): void {
		$engine = new EngineData( array() );
		$engine->set( 'video_file_path', '/tmp/new-video.mp4' );
		$this->assertSame( '/tmp/new-video.mp4', $engine->getVideoPath() );
	}
}
