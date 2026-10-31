<?php
/**
 * Scope Resolver Tests
 *
 * Covers the generic, consumer-agnostic helpers that back the optional
 * in-block time-scope preset chips (#373): ScopeResolver::preset_scopes()
 * (subset/order validation) and ScopeResolver::label() (single source of
 * truth for chip copy).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.41.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;

class ScopeResolverTest extends WP_UnitTestCase {

	private $cutoff_filter;

	public function set_up(): void {
		parent::set_up();
		$this->cutoff_filter = static fn() => 5;
		add_filter( 'data_machine_events_late_night_cutoff_hour', $this->cutoff_filter );
	}

	public function tear_down(): void {
		remove_filter( 'data_machine_events_late_night_cutoff_hour', $this->cutoff_filter );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------
	// preset_scopes(): default / subset / order / sanitization
	// ---------------------------------------------------------------------

	public function test_preset_scopes_defaults_to_all_valid_scopes_in_order(): void {
		$this->assertSame(
			ScopeResolver::VALID_SCOPES,
			ScopeResolver::preset_scopes()
		);
	}

	public function test_preset_scopes_empty_array_falls_back_to_all(): void {
		$this->assertSame(
			ScopeResolver::VALID_SCOPES,
			ScopeResolver::preset_scopes( array() )
		);
	}

	public function test_preset_scopes_subsets_and_preserves_caller_order(): void {
		$this->assertSame(
			array( 'this-weekend', 'today' ),
			ScopeResolver::preset_scopes( array( 'this-weekend', 'today' ) )
		);
	}

	public function test_preset_scopes_drops_unknown_slugs(): void {
		$this->assertSame(
			array( 'today', 'tonight' ),
			ScopeResolver::preset_scopes( array( 'today', 'bogus', 'tonight' ) )
		);
	}

	public function test_preset_scopes_collapses_duplicates(): void {
		$this->assertSame(
			array( 'today', 'tonight' ),
			ScopeResolver::preset_scopes( array( 'today', 'today', 'tonight' ) )
		);
	}

	public function test_preset_scopes_never_includes_the_empty_all_scope(): void {
		$this->assertNotContains( '', ScopeResolver::preset_scopes() );
		$this->assertNotContains( 'current', ScopeResolver::preset_scopes() );
	}

	public function test_preset_scopes_all_unknown_falls_back_to_all(): void {
		$this->assertSame(
			ScopeResolver::VALID_SCOPES,
			ScopeResolver::preset_scopes( array( 'nope', 'also-nope' ) )
		);
	}

	// ---------------------------------------------------------------------
	// label(): single source of truth for chip copy
	// ---------------------------------------------------------------------

	public function test_label_empty_and_current_resolve_to_all(): void {
		$this->assertSame( 'All', ScopeResolver::label( '' ) );
		$this->assertSame( 'All', ScopeResolver::label( 'current' ) );
	}

	public function test_label_maps_each_valid_scope(): void {
		$this->assertSame( 'Today', ScopeResolver::label( 'today' ) );
		$this->assertSame( 'Tonight', ScopeResolver::label( 'tonight' ) );
		$this->assertSame( 'This Weekend', ScopeResolver::label( 'this-weekend' ) );
		$this->assertSame( 'This Week', ScopeResolver::label( 'this-week' ) );
	}

	public function test_label_returns_raw_slug_for_unknown_scope(): void {
		$this->assertSame( 'something-else', ScopeResolver::label( 'something-else' ) );
	}

	public function test_every_preset_scope_has_a_non_empty_label(): void {
		foreach ( ScopeResolver::preset_scopes() as $slug ) {
			$this->assertNotSame(
				'',
				ScopeResolver::label( $slug ),
				"Scope '{$slug}' must resolve to a human label."
			);
		}
	}

	// ---------------------------------------------------------------------
	// resolve(): raw query bounds follow the active display day
	// ---------------------------------------------------------------------

	public function test_resolve_today_retains_the_active_display_day_after_midnight(): void {
		$before_midnight = ScopeResolver::resolve( 'today', strtotime( '2026-07-10 23:59:59 UTC' ) );
		$after_midnight  = ScopeResolver::resolve( 'today', strtotime( '2026-07-11 00:00:01 UTC' ) );

		$this->assertSame(
			array(
				'date_start' => '2026-07-10',
				'date_end'   => '2026-07-11',
				'time_start' => '05:00:00',
				'time_end'   => '04:59:59',
			),
			$before_midnight
		);
		$this->assertSame( $before_midnight, $after_midnight );
	}

	public function test_resolve_tonight_retains_the_active_display_day_after_midnight(): void {
		$this->assertSame(
			array(
				'date_start' => '2026-07-10',
				'date_end'   => '2026-07-11',
				'time_start' => '17:00:00',
				'time_end'   => '04:59:59',
			),
			ScopeResolver::resolve( 'tonight', strtotime( '2026-07-11 00:00:01 UTC' ) )
		);
	}

	public function test_resolve_uses_the_new_display_day_at_the_cutoff(): void {
		$this->assertSame(
			array(
				'date_start' => '2026-07-11',
				'date_end'   => '2026-07-12',
				'time_start' => '05:00:00',
				'time_end'   => '04:59:59',
			),
			ScopeResolver::resolve( 'today', strtotime( '2026-07-11 05:00:00 UTC' ) )
		);
	}

	public function test_resolve_tonight_keeps_daytime_and_evening_behavior(): void {
		$this->assertSame(
			array(
				'date_start' => '2026-07-11',
				'date_end'   => '2026-07-12',
				'time_start' => '17:00:00',
				'time_end'   => '04:59:59',
			),
			ScopeResolver::resolve( 'tonight', strtotime( '2026-07-11 14:30:00 UTC' ) )
		);
		$this->assertSame(
			array(
				'date_start' => '2026-07-11',
				'date_end'   => '2026-07-12',
				'time_start' => '18:30:00',
				'time_end'   => '04:59:59',
			),
			ScopeResolver::resolve( 'tonight', strtotime( '2026-07-11 18:30:00 UTC' ) )
		);
	}

	public function test_resolve_uses_the_configured_cutoff_for_display_day_bounds(): void {
		remove_filter( 'data_machine_events_late_night_cutoff_hour', $this->cutoff_filter );
		$this->cutoff_filter = static fn() => 3;
		add_filter( 'data_machine_events_late_night_cutoff_hour', $this->cutoff_filter );

		$this->assertSame(
			array(
				'date_start' => '2026-07-10',
				'date_end'   => '2026-07-11',
				'time_start' => '03:00:00',
				'time_end'   => '02:59:59',
			),
			ScopeResolver::resolve( 'today', strtotime( '2026-07-11 02:59:59 UTC' ) )
		);
	}
}
