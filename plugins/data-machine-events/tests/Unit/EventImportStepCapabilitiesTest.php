<?php
/**
 * Event import step capability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\EventImportStep;
use WP_UnitTestCase;

class EventImportStepCapabilitiesTest extends WP_UnitTestCase {

	public function test_event_import_declares_source_step_capabilities(): void {
		new EventImportStep();

		$step_types   = apply_filters( 'datamachine_step_types', array() );
		$event_import = $step_types['event_import'];

		$this->assertTrue( $event_import['source_ingestion'] );
		$this->assertTrue( $event_import['allows_empty_output'] );
		$this->assertTrue( $event_import['supports_item_disposition'] );
		$this->assertSame( 'source', $event_import['handler_category'] );
	}
}
