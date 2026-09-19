<?php
/**
 * EventQualityAuditAbilities entity-title rule tests.
 *
 * Covers the `entity_title` audit rule introduced for issue #844: published
 * events whose stored post_title contains HTML entity references are listed
 * for repair, and decoded titles are not flagged.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.65.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventQualityAuditAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use WP_UnitTestCase;

class EventQualityAuditEntityTitleTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	private function insert_event( string $title ): int {
		// Seed through the ingestion bounded-write suspension so the stored
		// title is exactly the requested one regardless of whether kses
		// filters are active in the test context.
		$post_id = \DataMachineEvents\Core\TextNormalization::with_kses_suspended(
			static fn(): int => (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_type'    => Event_Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
						array(
							'startDate' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
							'startTime' => '19:00',
							'venue'     => 'Entity Audit Venue',
						)
					) . ' /-->',
				)
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	public function test_entity_bearing_title_is_flagged(): void {
		$post_id = $this->insert_event( 'Jordan Igoe &amp; Friends' );

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope' => 'upcoming',
				'issue' => 'entity_title',
			)
		);

		$this->assertArrayHasKey( 'entity_title', $result );
		$this->assertGreaterThanOrEqual( 1, $result['entity_title']['count'] );
		$this->assertContains( $post_id, array_column( $result['entity_title']['events'], 'id' ) );
	}

	public function test_numeric_entity_title_is_flagged(): void {
		$post_id = $this->insert_event( 'Pilates &#038; Matcha' );

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope' => 'upcoming',
				'issue' => 'entity_title',
			)
		);

		$this->assertContains( $post_id, array_column( $result['entity_title']['events'], 'id' ) );
	}

	public function test_decoded_title_is_not_flagged(): void {
		$post_id = $this->insert_event( 'Move Wellness — Power Pilates & Matcha' );

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope' => 'upcoming',
				'issue' => 'entity_title',
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['entity_title']['events'], 'id' ) );
	}

	public function test_bare_ampersand_without_reference_is_not_flagged(): void {
		$post_id = $this->insert_event( 'R&B Night; Open Mic' );

		$result = ( new EventQualityAuditAbilities() )->executeAudit(
			array(
				'scope' => 'upcoming',
				'issue' => 'entity_title',
			)
		);

		$this->assertNotContains( $post_id, array_column( $result['entity_title']['events'], 'id' ) );
	}
}
