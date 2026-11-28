<?php
/**
 * A job carrying its own flyer must never process another bucket file (#892).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyer;
use ReflectionClass;
use WP_UnitTestCase;

class EventFlyerJobImageSelectionTest extends WP_UnitTestCase {

	private function select( array $files, ?string $job_image ): array {
		$handler = new EventFlyer();
		$method  = ( new ReflectionClass( $handler ) )->getMethod( 'selectCandidateFiles' );
		$method->setAccessible( true );
		return $method->invoke( $handler, $files, $job_image );
	}

	private function files(): array {
		return array(
			array( 'filename' => 'someone-else.jpg', 'path' => '/bucket/someone-else.jpg', 'size' => 1, 'modified' => '2026-09-27T15:57:00+00:00' ),
			array( 'filename' => 'mine.png', 'path' => '/bucket/mine.png', 'size' => 2, 'modified' => '2026-10-02T16:57:00+00:00' ),
		);
	}

	public function test_job_image_selects_only_that_file(): void {
		$selected = $this->select( $this->files(), '/bucket/mine.png' );
		$this->assertCount( 1, $selected );
		$this->assertSame( 'mine.png', $selected[0]['filename'] );
	}

	public function test_job_image_missing_from_bucket_selects_nothing(): void {
		$this->assertSame( array(), $this->select( $this->files(), '/elsewhere/missing.png' ) );
	}

	public function test_no_job_image_keeps_bucket_enumeration(): void {
		$this->assertCount( 2, $this->select( $this->files(), null ) );
		$this->assertCount( 2, $this->select( $this->files(), '' ) );
	}
}
