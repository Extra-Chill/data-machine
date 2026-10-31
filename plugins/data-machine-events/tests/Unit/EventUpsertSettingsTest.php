<?php
/**
 * EventUpsertSettings tests.
 *
 * Covers the #792 guarantee that the dead `taxonomy_event_type_selection`
 * field never reaches the settings UI or persisted flow configs: the closed
 * `eventType` parameter owns event_type assignment, so the selection surface
 * is excluded from fields and stripped by sanitize.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Event_Type_Taxonomy;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsertSettings;

class EventUpsertSettingsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! taxonomy_exists( 'promoter' ) ) {
			Promoter_Taxonomy::register();
		}
		if ( ! taxonomy_exists( Event_Type_Taxonomy::TAXONOMY ) ) {
			Event_Type_Taxonomy::register();
			Event_Type_Taxonomy::seed_vocabulary();
		}
	}

	public function test_get_fields_excludes_event_type_while_keeping_sibling_selections(): void {
		$fields = EventUpsertSettings::get_fields();

		$this->assertArrayNotHasKey(
			'taxonomy_' . Event_Type_Taxonomy::TAXONOMY . '_selection',
			$fields,
			'The dead event_type selection must not appear in handler settings.'
		);
		$this->assertArrayNotHasKey( 'taxonomy_venue_selection', $fields, 'Venue exclusion (custom handler) must be unchanged.' );
		$this->assertArrayHasKey( 'taxonomy_promoter_selection', $fields );
	}

	public function test_sanitize_strips_persisted_event_type_selection_key(): void {
		$sanitized = EventUpsertSettings::sanitize(
			array(
				'post_status'                   => 'publish',
				'post_author'                   => 1,
				'include_images'                => true,
				'taxonomy_event_type_selection' => 'ai_decides',
				'taxonomy_promoter_selection'   => 'skip',
			)
		);

		$this->assertArrayNotHasKey(
			'taxonomy_' . Event_Type_Taxonomy::TAXONOMY . '_selection',
			$sanitized,
			'Persisted flow configs must shed the dead event_type key on their next save.'
		);
		$this->assertSame( 'skip', $sanitized['taxonomy_promoter_selection'] );
		$this->assertSame( 'publish', $sanitized['post_status'] );
	}
}
