<?php
/**
 * Pinned venue context directive tests (issue #3956).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\PinnedVenueContextDirective;
use WP_UnitTestCase;

class PinnedVenueContextDirectiveTest extends WP_UnitTestCase {

	private function payload( array $import, array $upsert ): array {
		return array(
			'engine_data' => array(
				'flow_config' => array(
					'1_step_0_9' => array( 'handler_configs' => array( 'universal_web_scraper' => $import ) ),
					'1_step_1_9' => array( 'step_type' => 'ai' ),
					'1_step_2_9' => array( 'handler_configs' => array( 'upsert_event' => $upsert ) ),
				),
			),
		);
	}

	public function test_directive_is_registered_for_pipeline_mode(): void {
		$registered = array_filter(
			apply_filters( 'datamachine_directives', array() ),
			static fn ( $d ) => PinnedVenueContextDirective::class === ( $d['class'] ?? null )
		);
		$this->assertCount( 1, $registered );
		$this->assertSame( array( 'pipeline' ), array_values( $registered )[0]['modes'] );
	}

	public function test_pinned_venue_flow_gets_location_context(): void {
		$payload = $this->payload(
			array( 'venue' => 117854, 'venue_name' => 'Stumble Inn Bar & Grill', 'venue_city' => 'Londonderry', 'venue_state' => 'NH' ),
			array( 'taxonomy_venue_selection' => '117854' )
		);

		$outputs = PinnedVenueContextDirective::get_outputs( 'openai', array( 'upsert_event' => array() ), null, $payload );

		$this->assertCount( 1, $outputs );
		$this->assertSame( 'system_text', $outputs[0]['type'] );
		$this->assertStringContainsString( 'Stumble Inn Bar & Grill (Londonderry, NH)', $outputs[0]['content'] );
		$this->assertStringContainsString( 'Never reject or defer an event from this source because of its location', $outputs[0]['content'] );
	}

	public function test_ai_decides_venue_flow_gets_nothing(): void {
		$payload = $this->payload( array( 'source_url' => 'https://example.com' ), array( 'taxonomy_venue_selection' => 'ai_decides' ) );

		$this->assertSame( array(), PinnedVenueContextDirective::get_outputs( 'openai', array( 'upsert_event' => array() ), null, $payload ) );
	}

	public function test_non_event_steps_get_nothing(): void {
		$payload = $this->payload( array( 'venue_name' => 'X' ), array( 'taxonomy_venue_selection' => '5' ) );

		$this->assertSame( array(), PinnedVenueContextDirective::get_outputs( 'openai', array( 'upsert_post' => array( 'handler' => 'upsert_post' ) ), null, $payload ) );
	}

	public function test_falls_back_to_term_name(): void {
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Fallback Hall' ) );
		$payload = $this->payload( array(), array( 'taxonomy_venue_selection' => (string) $term->term_id ) );

		$outputs = PinnedVenueContextDirective::get_outputs( 'openai', array( 'upsert_event' => array() ), null, $payload );

		$this->assertStringContainsString( 'one venue: Fallback Hall.', $outputs[0]['content'] );
	}
}
