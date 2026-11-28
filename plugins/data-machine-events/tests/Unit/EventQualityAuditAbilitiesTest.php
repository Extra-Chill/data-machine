<?php
/**
 * Event quality audit tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventQualityAuditAbilities;
use WP_UnitTestCase;

class EventQualityAuditAbilitiesTest extends WP_UnitTestCase {

	public function test_duplicate_clusters_include_showtime(): void {
		$method = new \ReflectionMethod( EventQualityAuditAbilities::class, 'buildDuplicateClusterKey' );
		$method->setAccessible( true );
		$audit = new EventQualityAuditAbilities();

		$early  = $method->invoke( $audit, 'Desi Banks', 'Vic Theater', '2026-08-22', '19:00' );
		$replay = $method->invoke( $audit, 'Desi Banks', 'Vic Theater', '2026-08-22', '19:00:00' );
		$late   = $method->invoke( $audit, 'Desi Banks', 'Vic Theater', '2026-08-22', '21:30' );

		$this->assertSame( $early, $replay );
		$this->assertNotSame( $early, $late );
	}
}
