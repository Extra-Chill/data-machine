<?php
/**
 * Tests for taxonomy ability WP_Error failures.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility;
use DataMachine\Abilities\Taxonomy\GetTaxonomyTermsAbility;
use DataMachine\Abilities\Taxonomy\MergeTermMetaAbility;
use DataMachine\Abilities\Taxonomy\ResolveTermAbility;
use WP_Error;
use WP_UnitTestCase;

class TaxonomyAbilityWpErrorTest extends WP_UnitTestCase {

	private const TEST_TAXONOMY = 'datamachine_wp_error_terms';

	public function set_up(): void {
		parent::set_up();

		register_taxonomy(
			self::TEST_TAXONOMY,
			array( 'post' ),
			array(
				'public'       => true,
				'hierarchical' => true,
			)
		);
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TEST_TAXONOMY );

		parent::tear_down();
	}

	public function test_create_taxonomy_term_validation_failure_returns_wp_error(): void {
		$result = ( new CreateTaxonomyTermAbility() )->execute( array( 'name' => 'Charleston' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taxonomy_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_get_taxonomy_terms_not_found_returns_wp_error(): void {
		$result = ( new GetTaxonomyTermsAbility() )->execute( array( 'taxonomy' => 'missing_taxonomy' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taxonomy_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_resolve_term_callback_failure_returns_wp_error(): void {
		$result = ( new ResolveTermAbility() )->execute(
			array(
				'identifier' => 'Missing Term',
				'taxonomy'   => self::TEST_TAXONOMY,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_resolve_term_static_helper_preserves_legacy_failure_array(): void {
		$result = ResolveTermAbility::resolve( 'Missing Term', self::TEST_TAXONOMY );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Missing Term', $result['error'] );
	}

	public function test_merge_term_meta_callback_failure_returns_wp_error(): void {
		$result = ( new MergeTermMetaAbility() )->execute(
			array(
				'term_id'   => 0,
				'taxonomy'  => self::TEST_TAXONOMY,
				'data'      => array( 'name' => 'Charleston' ),
				'field_map' => array( 'name' => 'datamachine_name' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_id_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_merge_term_meta_static_helper_preserves_legacy_failure_array(): void {
		$result = MergeTermMetaAbility::merge(
			0,
			self::TEST_TAXONOMY,
			array( 'name' => 'Charleston' ),
			array( 'name' => 'datamachine_name' )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'term_id is required', $result['error'] );
	}
}
