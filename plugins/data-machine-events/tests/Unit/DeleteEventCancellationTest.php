<?php
/**
 * Cancelled events must not return while the source still lists them (#3927).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\DeleteEventAbilities;
use DataMachineEvents\Core\EventOwnership;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

require_once __DIR__ . '/EventUpsertTestCase.php';

class DeleteEventCancellationTest extends EventUpsertTestCase {

	public function test_cancellation_protects_legacy_event_without_source_identity(): void {
		$this->assertCancellationSurvivesImport( true );
	}

	public function test_cancellation_protects_event_with_source_identity(): void {
		$this->assertCancellationSurvivesImport( false );
	}

	private function assertCancellationSurvivesImport( bool $legacy ): void {
		$params  = $this->parameters();
		$created = $this->invoke_upsert( $params );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$id = (int) $created['data']['post_id'];
		if ( $legacy ) {
			foreach ( array( EventUpsert::SOURCE_IDENTITY_META_KEY, EventUpsert::SOURCE_NAME_META_KEY, EventUpsert::SOURCE_ID_META_KEY ) as $key ) {
				delete_post_meta( $id, $key );
			}
		}
		$this->assertFalse( EventOwnership::isHumanOwned( $id ) );
		$ability = new DeleteEventAbilities();
		$deleted = $ability->execute(
			array(
				'event'       => $id,
				'reason_type' => 'cancelled',
				'reason'      => 'Venue confirmed cancellation.',
			)
		);
		$this->assertSame( 1, $deleted['summary']['deleted'] );
		$this->assertSame( 'cancelled', $deleted['reason_type'] );
		$this->assertTrue( EventOwnership::isHumanOwned( $id ) );
		$links = get_post_meta( $id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false );
		// Upgrading an already trashed cancellation must be idempotent.
		$ability->execute(
			array(
				'event'       => $id,
				'reason_type' => 'cancelled',
			)
		);
		$this->assertSame( $links, get_post_meta( $id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false ) );

		$before = (array) wp_count_posts( 'data_machine_events' );
		foreach ( array( $params['item_identifier'], EventIdentifierGenerator::generateLegacy( $params['title'], $params['startDate'], $params['venue'] ) ) as $identifier ) {
			$params['item_identifier'] = $identifier;
			$result                    = $this->invoke_upsert( $params );
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			$this->assertSame( $id, $result['data']['post_id'] );
			$this->assertSame( 'no_change', $result['data']['action'] );
			$this->assertSame( 'trash', get_post_status( $id ) );
		}
		$this->assertSame( $before, (array) wp_count_posts( 'data_machine_events' ) );
	}

	public function test_ordinary_trash_still_restores_on_import(): void {
		$params  = $this->parameters();
		$created = $this->invoke_upsert( $params );
		$id      = (int) $created['data']['post_id'];
		$ability = new DeleteEventAbilities();
		$ability->execute(
			array(
				'event'       => $id,
				'reason_type' => 'duplicate',
			)
		);
		$this->assertFalse( EventOwnership::isHumanOwned( $id ) );
		$this->assertSame( array(), get_post_meta( $id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false ) );
		$result = $this->invoke_upsert( $params );
		$this->assertSame( $id, $result['data']['post_id'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_unknown_reason_type_is_rejected(): void {
		$ability = new DeleteEventAbilities();
		$this->assertWPError(
			$ability->execute(
				array(
					'event'       => 1,
					'reason_type' => 'typo',
				)
			)
		);
	}

	private function parameters(): array {
		$title = 'Cancelled Dice Show ' . uniqid();
		$venue = 'Metro Baltimore ' . uniqid();
		$date  = self::future_year() . '-11-09';
		return array(
			'title'           => $title,
			'venue'           => $venue,
			'startDate'       => $date,
			'startTime'       => '20:00',
			'description'     => 'Still listed on sale at Dice.',
			'source_type'     => 'dice_fm',
			'item_identifier' => EventIdentifierGenerator::generate( $title, $date, $venue, '20:00' ),
		);
	}
}
