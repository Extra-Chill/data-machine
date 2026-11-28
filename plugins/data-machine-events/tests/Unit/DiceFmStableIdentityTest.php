<?php
/**
 * Dice provider identity tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFm;
use DataMachineEvents\Utilities\EventSourceIdentity;
use WP_UnitTestCase;

class DiceFmStableIdentityTest extends WP_UnitTestCase {

	public function test_provider_id_survives_conversion_and_content_edits(): void {
		$handler = new DiceFm();
		$method  = new \ReflectionMethod( $handler, 'convert_dice_fm_event' );
		$context = ExecutionContext::fromFlow( 219, 219, 'dice-stable-test', null, 'dice_fm' );

		foreach ( array( 'id', '_id' ) as $id_field ) {
			$raw = array(
				$id_field  => 'dice-123',
				'name'     => 'Original Dice title',
				'date'     => '2027-09-09T20:00:00Z',
				'timezone' => 'America/New_York',
				'venue'    => 'Dice test venue',
			);
			$event = $method->invoke( $handler, $raw );
			$this->assertSame( 'dice-123', $event['source_item_id'] );
			$first = EventSourceIdentity::resolve( $event, $context );
			$raw['name'] = 'Completely renamed show';
			$raw['date'] = '2027-09-09T23:00:00Z';
			$edited = EventSourceIdentity::resolve( $method->invoke( $handler, $raw ), $context );
			$this->assertSame( $first['item_identifier'], $edited['item_identifier'] );
			$this->assertNotSame( $first['event_identifier'], $edited['event_identifier'] );
		}
	}
}
