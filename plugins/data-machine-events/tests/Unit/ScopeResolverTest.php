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
}
