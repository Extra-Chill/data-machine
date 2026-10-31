<?php
/**
 * TaxonomyHandler filter hook tests.
 *
 * Covers the two extension points added in #2152:
 * - `datamachine_taxonomy_tool_parameter` — enrich the AI tool parameter
 *   definition for a taxonomy (description, enum, required, etc.).
 * - `datamachine_taxonomy_assign_value` — mutate / refuse the AI-supplied
 *   value before terms are created.
 *
 * These tests must stay generic. A taxonomy named `region` is registered
 * inside each test so DM core gains no knowledge of any specific
 * domain taxonomy name.
 *
 * @package DataMachine\Tests\Unit\Core\WordPress
 */

namespace DataMachine\Tests\Unit\Core\WordPress;

use DataMachine\Core\WordPress\TaxonomyHandler;
use WP_UnitTestCase;

class TaxonomyHandlerFiltersTest extends WP_UnitTestCase {

	/**
	 * Generic taxonomy slug used by every test in this file.
	 *
	 * Intentionally NOT named after any real Extra Chill taxonomy so the
	 * tests document the generic contract, not a vendor-specific shape.
	 */
	private const TEST_TAXONOMY = 'datamachine_test_region';

	public function set_up(): void {
		parent::set_up();

		register_taxonomy(
			self::TEST_TAXONOMY,
			array( 'post' ),
			array(
				'public'       => true,
				'hierarchical' => true,
				'label'        => 'Regions',
				'labels'       => (object) array(
					'name' => 'Regions',
				),
			)
		);
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TEST_TAXONOMY );

		remove_all_filters( 'datamachine_taxonomy_tool_parameter' );
		remove_all_filters( 'datamachine_taxonomy_assign_value' );

		parent::tear_down();
	}

	/**
	 * Default behavior (no filter hooked) must match pre-#2152 output:
	 * auto-generated description, no enum, no required flag.
	 */
	public function test_default_tool_parameter_is_unchanged_when_no_filter_hooked(): void {
		$handler_config = array(
			'taxonomy_' . self::TEST_TAXONOMY . '_selection' => 'ai_decides',
		);

		$params = TaxonomyHandler::getTaxonomyToolParameters( $handler_config, 'post' );

		$this->assertArrayHasKey( self::TEST_TAXONOMY, $params );
		$param_def = $params[ self::TEST_TAXONOMY ];

		$this->assertSame( 'string', $param_def['type'] );
		$this->assertStringContainsString( 'regions', $param_def['description'] );
		$this->assertArrayNotHasKey( 'enum', $param_def );
		$this->assertArrayNotHasKey( 'required', $param_def );
	}

	/**
	 * Hooking the filter must let a plugin replace the description and
	 * inject an `enum` constraint that downstream tool schema consumes.
	 */
	public function test_tool_parameter_filter_can_enrich_description_and_add_enum(): void {
		$captured = array();

		add_filter(
			'datamachine_taxonomy_tool_parameter',
			function ( $param_def, $taxonomy, $handler_config, $post_type ) use ( &$captured ) {
				$captured = array(
					'taxonomy_name'  => $taxonomy->name,
					'handler_config' => $handler_config,
					'post_type'      => $post_type,
				);

				if ( self::TEST_TAXONOMY !== $taxonomy->name ) {
					return $param_def;
				}

				$param_def['description'] = 'Pick from the curated list of regions. Do not invent new values.';
				$param_def['enum']        = array( 'North', 'South', 'East', 'West' );
				return $param_def;
			},
			10,
			4
		);

		$handler_config = array(
			'taxonomy_' . self::TEST_TAXONOMY . '_selection' => 'ai_decides',
		);

		$params = TaxonomyHandler::getTaxonomyToolParameters( $handler_config, 'post' );

		$this->assertArrayHasKey( self::TEST_TAXONOMY, $params );
		$param_def = $params[ self::TEST_TAXONOMY ];

		$this->assertSame(
			'Pick from the curated list of regions. Do not invent new values.',
			$param_def['description']
		);
		$this->assertSame(
			array( 'North', 'South', 'East', 'West' ),
			$param_def['enum']
		);

		// Filter receives full context.
		$this->assertSame( self::TEST_TAXONOMY, $captured['taxonomy_name'] );
		$this->assertSame( $handler_config, $captured['handler_config'] );
		$this->assertSame( 'post', $captured['post_type'] );
	}

	/**
	 * Default behavior (no filter hooked) must still create terms exactly
	 * as before #2152.
	 */
	public function test_default_assignment_creates_term_when_no_filter_hooked(): void {
		$post_id = self::factory()->post->create();
		$handler = new TaxonomyHandler();

		$result = $handler->assignTaxonomy( $post_id, self::TEST_TAXONOMY, 'Charleston' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['term_count'] );

		$term = get_term_by( 'name', 'Charleston', self::TEST_TAXONOMY );
		$this->assertNotFalse( $term );

		$assigned = wp_get_object_terms( $post_id, self::TEST_TAXONOMY, array( 'fields' => 'names' ) );
		$this->assertContains( 'Charleston', $assigned );
	}

	/**
	 * Returning an empty string from the filter must refuse the assignment:
	 * no term is created, no error is raised.
	 */
	public function test_assign_value_filter_can_refuse_ai_supplied_value(): void {
		add_filter(
			'datamachine_taxonomy_assign_value',
			function ( $taxonomy_value, $taxonomy_name, $post_id ) {
				if ( self::TEST_TAXONOMY === $taxonomy_name ) {
					// Owner refuses anything the AI picks for this taxonomy.
					return '';
				}
				return $taxonomy_value;
			},
			10,
			3
		);

		$post_id = self::factory()->post->create();
		$handler = new TaxonomyHandler();

		$refused_value = 'Some Junk Venue Name';

		$result = $handler->assignTaxonomy( $post_id, self::TEST_TAXONOMY, $refused_value );

		// No error: silent skip is the contract.
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['term_count'] );

		// No term was created.
		$term = get_term_by( 'name', $refused_value, self::TEST_TAXONOMY );
		$this->assertFalse( $term );

		// No terms are assigned to the post.
		$assigned = wp_get_object_terms( $post_id, self::TEST_TAXONOMY, array( 'fields' => 'names' ) );
		$this->assertSame( array(), $assigned );
	}

	/**
	 * Returning a mutated value from the filter must reroute term creation
	 * to that value (not the original AI-supplied value).
	 */
	public function test_assign_value_filter_can_coerce_value(): void {
		add_filter(
			'datamachine_taxonomy_assign_value',
			function ( $taxonomy_value, $taxonomy_name ) {
				if ( self::TEST_TAXONOMY === $taxonomy_name ) {
					return 'Coerced Value';
				}
				return $taxonomy_value;
			},
			10,
			2
		);

		$post_id = self::factory()->post->create();
		$handler = new TaxonomyHandler();

		$result = $handler->assignTaxonomy( $post_id, self::TEST_TAXONOMY, 'Original AI Pick' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['term_count'] );

		// Coerced value was created.
		$coerced = get_term_by( 'name', 'Coerced Value', self::TEST_TAXONOMY );
		$this->assertNotFalse( $coerced );

		// Original AI pick was NOT created.
		$original = get_term_by( 'name', 'Original AI Pick', self::TEST_TAXONOMY );
		$this->assertFalse( $original );
	}
}
