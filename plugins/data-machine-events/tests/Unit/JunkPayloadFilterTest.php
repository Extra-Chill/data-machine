<?php
/**
 * JunkPayloadFilter Tests
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.14.1
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\JunkPayloadFilter;

class JunkPayloadFilterTest extends WP_UnitTestCase {

	private JunkPayloadFilter $filter;

	public function setUp(): void {
		parent::setUp();
		$this->filter = new JunkPayloadFilter();
	}

	public function test_explicit_test_flag_drops_payload() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id'        => 'Z5x98mNormal',
					'title'            => 'Real Concert',
					'is_explicit_test' => true,
				),
				'ticketmaster'
			)
		);
	}

	public function test_explicit_test_flag_false_does_not_drop() {
		$this->assertFalse(
			$this->filter->is_junk(
				array(
					'source_id'        => 'Z5x98mNormal',
					'title'            => 'Real Concert',
					'is_explicit_test' => false,
				),
				'ticketmaster'
			)
		);
	}

	public function test_absent_test_flag_does_not_drop_by_default() {
		$this->assertFalse(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mNormal',
					'title'     => 'Real Concert',
				),
				'ticketmaster'
			)
		);
	}

	public function test_unknown_source_type_has_no_patterns() {
		// No handler registered junk patterns for 'dice', so nothing matches.
		$this->assertFalse(
			$this->filter->is_junk(
				array(
					'source_id' => 'CCPER-2756',
					'title'     => 'Upcoming Event CCPER-2756',
				),
				'dice'
			)
		);
	}

	public function test_ccper_id_pattern_drops_ticketmaster_payload() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'CCPER-2756',
					'title'     => 'Some Event',
				),
				'ticketmaster'
			)
		);
	}

	public function test_ccper_in_title_drops_ticketmaster_payload() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mWhatever',
					'title'     => 'Upcoming Event CCPER-2756',
				),
				'ticketmaster'
			)
		);
	}

	public function test_standalone_upsell_title_drops_payload() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mWhatever',
					'title'     => 'Upcoming Event with Standalone Upsell',
				),
				'ticketmaster'
			)
		);
	}

	public function test_test_event_title_drops_payload() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mWhatever',
					'title'     => 'Test Event 2026',
				),
				'ticketmaster'
			)
		);
	}

	public function test_upcoming_event_prefix_drops_when_no_artist() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mWhatever',
					'title'     => 'Upcoming Event',
					'artist'    => '',
				),
				'ticketmaster'
			)
		);
	}

	public function test_upcoming_event_prefix_keeps_when_artist_present() {
		$this->assertFalse(
			$this->filter->is_junk(
				array(
					'source_id' => 'Z5x98mWhatever',
					'title'     => 'Upcoming Event Featuring Phish',
					'artist'    => 'Phish',
				),
				'ticketmaster'
			)
		);
	}

	public function test_normal_event_passes_through() {
		$this->assertFalse(
			$this->filter->is_junk(
				array(
					'source_id' => 'vvG1hZwAd_k-p',
					'title'     => 'Phish - Summer Tour 2026',
					'artist'    => 'Phish',
				),
				'ticketmaster'
			)
		);
	}

	public function test_patterns_are_filterable() {
		$callback = function ( array $patterns, string $source_type ) {
			if ( 'ticketmaster' !== $source_type ) {
				return $patterns;
			}
			$patterns['title'][] = 'QA SANDBOX';
			return $patterns;
		};
		add_filter( 'data_machine_events_junk_payload_patterns', $callback, 10, 2 );

		$result = $this->filter->is_junk(
			array(
				'source_id' => 'Z5xNormal',
				'title'     => 'QA Sandbox Preview',
			),
			'ticketmaster'
		);

		remove_filter( 'data_machine_events_junk_payload_patterns', $callback, 10 );

		$this->assertTrue( $result );
	}

	public function test_patterns_filterable_to_remove_default() {
		$callback = function ( array $patterns, string $source_type ) {
			if ( 'ticketmaster' !== $source_type ) {
				return $patterns;
			}
			// Strip the CCPER- title pattern so the ID is the only CCPER signal.
			$patterns['title'] = array_values( array_diff( $patterns['title'], array( 'CCPER-' ) ) );
			return $patterns;
		};
		add_filter( 'data_machine_events_junk_payload_patterns', $callback, 10, 2 );

		// Title carries CCPER- but source_id does not, and the title pattern was removed.
		$result = $this->filter->is_junk(
			array(
				'source_id' => 'Z5xNormal',
				'title'     => 'Upcoming Event CCPER-2756',
				'artist'    => '',
			),
			'ticketmaster'
		);

		remove_filter( 'data_machine_events_junk_payload_patterns', $callback, 10 );

		// Still dropped: title starts with "Upcoming Event" and has no artist.
		$this->assertTrue( $result );
	}

	public function test_matching_is_case_insensitive() {
		$this->assertTrue(
			$this->filter->is_junk(
				array(
					'source_id' => 'ccper-2756',
					'title'     => 'some event',
				),
				'ticketmaster'
			)
		);
	}
}
