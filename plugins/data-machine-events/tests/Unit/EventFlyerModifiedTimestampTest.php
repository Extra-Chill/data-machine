<?php
/**
 * Regression coverage for uploaded flyer timestamps returned by FileStorage.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyer;
use ReflectionClass;
use WP_UnitTestCase;

class EventFlyerModifiedTimestampTest extends WP_UnitTestCase {

	public function test_iso_modified_timestamp_formats_uploaded_at_without_type_error(): void {
		$file = array(
			'filename' => 'flyer.jpg',
			'path'     => '/tmp/flyer.jpg',
			'size'     => 123,
			'modified' => '2025-04-03T14:25:36+00:00',
		);

		$handler    = new EventFlyer();
		$reflection = new ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'formatUploadedAt' );
		$method->setAccessible( true );
		$uploaded_at = $method->invoke( $handler, $file['modified'] );

		$this->assertSame( '2025-04-03 14:25:36', $uploaded_at );
	}
}
