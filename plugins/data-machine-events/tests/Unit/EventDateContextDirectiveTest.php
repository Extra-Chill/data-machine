<?php
/**
 * Event date context directive tests (issue #3882).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventDateContextDirective;
use WP_UnitTestCase;

class EventDateContextDirectiveTest extends WP_UnitTestCase {

	public function test_directive_is_registered_for_pipeline_mode(): void {
		$registered = array_filter(
			apply_filters( 'datamachine_directives', array() ),
			static fn ( $d ) => EventDateContextDirective::class === ( $d['class'] ?? null )
		);
		$this->assertCount( 1, $registered );
		$this->assertSame( array( 'pipeline' ), array_values( $registered )[0]['modes'] );
	}

	public function test_only_event_upsert_steps_get_date_context(): void {
		$this->assertSame( array(), EventDateContextDirective::get_outputs( 'openai', array( 'upsert_post' => array( 'handler' => 'upsert_post' ) ) ) );

		$by_handler = EventDateContextDirective::get_outputs( 'openai', array( 'publish' => array( 'handler' => 'upsert_event' ) ) );
		$by_name    = EventDateContextDirective::get_outputs( 'openai', array( 'upsert_event' => array() ) );

		$this->assertCount( 1, $by_handler );
		$this->assertSame( 'system_text', $by_handler[0]['type'] );
		$this->assertCount( 1, $by_name );
	}

	public function test_output_uses_site_timezone_today(): void {
		$outputs = EventDateContextDirective::get_outputs( 'openai', array( 'upsert_event' => array() ) );
		$this->assertStringContainsString( current_datetime()->format( 'Y-m-d' ), $outputs[0]['content'] );
	}

	public function test_content_states_year_rule_with_grace_and_weekday(): void {
		$now     = new \DateTimeImmutable( '2026-11-01 18:00:00', new \DateTimeZone( 'America/New_York' ) );
		$content = EventDateContextDirective::content( $now );

		$this->assertStringContainsString( 'Today is Sunday, November 1, 2026 (2026-11-01, America/New_York)', $content );
		$this->assertStringContainsString( 'never a reason to defer', $content );
		$this->assertStringContainsString( 'use 2026. Only if that date is more than 14 days before today, use 2027', $content );
		$this->assertStringContainsString( 'whichever of 2026 or 2027', $content );
		$this->assertStringContainsString( 'past event: do not create it', $content );
	}
}
