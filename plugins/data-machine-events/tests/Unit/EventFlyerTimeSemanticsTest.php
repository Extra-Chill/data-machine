<?php
/**
 * Doors/show flyer time semantics (#922).
 *
 * A flyer reading "DOORS: 8PM SHOW: 9PM" produced startTime 20:00 and
 * endTime 21:00 — the show time was stored as the moment the show ended.
 * The vision model is driven entirely by the vision prompt and the AI
 * extraction field descriptions, so this regression contract lives in those
 * strings: the show time is the start, doors never becomes an end, endTime
 * requires an explicit end or range, and doors times route to the
 * description because the event schema has no doors field.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyer;
use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyerSettings;
use WP_UnitTestCase;

class EventFlyerTimeSemanticsTest extends WP_UnitTestCase {

	/**
	 * The doors/show flyer layout from #922 — one of the most common gig
	 * flyer time formats.
	 */
	private function doorsShowFlyerText(): string {
		return 'DOORS: 8PM SHOW: 9PM';
	}

	public function test_vision_prompt_makes_show_time_the_start(): void {
		$this->assertStringContainsString(
			'the SHOW time is the event start time',
			EventFlyer::get_vision_prompt()
		);
	}

	public function test_vision_prompt_never_lets_doors_become_start_or_end(): void {
		$this->assertStringContainsString(
			'The doors time is never the start time and never the end time',
			EventFlyer::get_vision_prompt()
		);
	}

	public function test_vision_prompt_sends_doors_time_to_description(): void {
		// The event schema has no doors field, so the prompt must route the
		// doors time into the description instead of dropping it.
		$this->assertStringContainsString(
			'include it in the description',
			EventFlyer::get_vision_prompt()
		);
	}

	public function test_vision_prompt_reserves_end_time_for_explicit_ends(): void {
		$prompt = EventFlyer::get_vision_prompt();

		$this->assertStringContainsString( 'Set an end time only when the flyer states an explicit end or time range', $prompt );
		$this->assertStringContainsString( 'A doors/show pair is not a start/end range', $prompt );
	}

	public function test_vision_prompt_covers_the_doors_show_flyer_layout(): void {
		// The flyer fixture from the issue pairs the two labels the prompt's
		// time rules name explicitly.
		$this->assertStringContainsString( 'DOORS', $this->doorsShowFlyerText() );
		$this->assertStringContainsString( 'SHOW', $this->doorsShowFlyerText() );

		$prompt = EventFlyer::get_vision_prompt();
		$this->assertStringContainsString( 'DOORS', $prompt );
		$this->assertStringContainsString( 'SHOW', $prompt );
	}

	public function test_extraction_fields_define_start_time_as_show_time(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'this is the SHOW time', $fields['startTime'] );
		$this->assertStringContainsString( 'never the doors time', $fields['startTime'] );
	}

	public function test_extraction_fields_bar_doors_show_pair_from_end_time(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'Set only when the flyer states an explicit end or time range', $fields['endTime'] );
		$this->assertStringContainsString( 'a doors/show pair is not a start/end range', $fields['endTime'] );
	}

	public function test_extraction_fields_take_doors_times_in_description(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'doors times', $fields['description'] );
	}

	public function test_event_schema_has_no_doors_field(): void {
		// Why doors goes to the description: the standardized event schema —
		// and therefore the event-details block — carries no doors-time
		// field. If one is added, revisit the prompt's doors routing.
		$this->assertArrayNotHasKey( 'doorsTime', EventSchemaProvider::getAllFields() );
		$this->assertArrayNotHasKey( 'doors', EventSchemaProvider::getAllFields() );
	}

	public function test_save_tool_schema_bar_doors_show_pair_from_end_time(): void {
		// The save-event tool is where the wrong endTime was persisted, so
		// its parameter description must carry the same rule.
		$fields = EventSchemaProvider::getAllFields();

		$this->assertStringContainsString( 'A doors/show time pair is two start times, not a start/end range', $fields['endTime']['description'] );
		$this->assertStringContainsString( 'the show time is the start', $fields['endTime']['description'] );
	}

	public function test_flyer_packet_carries_the_extraction_rules_to_the_ai_step(): void {
		// The time rules only help if they reach the model. The AI step sees
		// the flyer image plus this packet, so the rules must be in it.
		$handler = new EventFlyer();
		$method  = ( new \ReflectionClass( $handler ) )->getMethod( 'buildPacketContent' );
		$method->setAccessible( true );

		$content = $method->invoke(
			$handler,
			array( 'title' => 'Nightmare On King St' ),
			array(),
			'NIGHTMARE-ON-KING-ST.png',
			EventFlyerSettings::get_ai_extraction_fields()
		);

		$this->assertSame( EventFlyer::get_vision_prompt(), $content['extraction_rules'] );
		$this->assertStringContainsString( 'the SHOW time is the event start time', $content['extraction_rules'] );
		$this->assertArrayHasKey( 'endTime', $content['ai_extraction_fields'] );
		$this->assertSame( 'event_flyer', $content['import_source'] );
	}
}
