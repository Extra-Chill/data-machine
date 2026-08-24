<?php
/**
 * Create Taxonomy Term Tool Tests.
 *
 * @package DataMachine\Tests\Unit\Api\Chat\Tools
 */

namespace DataMachine\Tests\Unit\Api\Chat\Tools;

use DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility;
use DataMachine\Api\Chat\Tools\CreateTaxonomyTerm;
use WP_Ability;
use WP_Abilities_Registry;
use WP_Error;
use WP_UnitTestCase;

class CreateTaxonomyTermTest extends WP_UnitTestCase {

	private const TAXONOMY     = 'datamachine_chat_create_terms';

	private CreateTaxonomyTerm $tool;
	private WP_Ability $original_ability;
	private CountingCreateTaxonomyTermAbility $ability;

	public function set_up(): void {
		parent::set_up();

		register_taxonomy(
			self::TAXONOMY,
			array( 'post' ),
			array( 'hierarchical' => true )
		);

		$this->original_ability = wp_get_ability( CreateTaxonomyTermAbility::ABILITY_NAME );
		$this->ability          = new CountingCreateTaxonomyTermAbility( CreateTaxonomyTermAbility::ABILITY_NAME );
		$this->replaceAbility( $this->ability );
		$this->tool = new CreateTaxonomyTerm();
	}

	public function tear_down(): void {
		$this->replaceAbility( $this->original_ability );
		unregister_taxonomy( self::TAXONOMY );

		parent::tear_down();
	}

	public function test_delegates_new_term_creation_exactly_once(): void {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Charleston',
			)
		);
		$this->ability->result = $this->abilityResult( $term, true, false );

		$result = $this->tool->handle_tool_call(
			array(
				'taxonomy'    => self::TAXONOMY,
				'name'        => 'Charleston',
				'description' => 'South Carolina music scene',
			)
		);

		$this->assertSame( 1, $this->ability->calls );
		$this->assertSame(
			array(
				'taxonomy'    => self::TAXONOMY,
				'name'        => 'Charleston',
				'parent'      => 0,
				'description' => 'South Carolina music scene',
			),
			$this->ability->input
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( $term->term_taxonomy_id, $result['data']['term_taxonomy_id'] );
		$this->assertArrayNotHasKey( 'already_exists', $result['data'] );
		$this->assertSame( "Created term 'Charleston' in taxonomy '" . self::TAXONOMY . "'.", $result['data']['message'] );
	}

	public function test_existing_term_remains_successful_no_op(): void {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Existing Term',
			)
		);
		$this->ability->result = $this->abilityResult( $term, false, true );

		$result = $this->tool->handle_tool_call(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Existing Term',
			)
		);

		$this->assertSame( 1, $this->ability->calls );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['already_exists'] );
		$this->assertSame( "Term 'Existing Term' already exists in taxonomy '" . self::TAXONOMY . "'.", $result['data']['message'] );
	}

	/**
	 * @dataProvider parentIdentifierProvider
	 */
	public function test_maps_parent_identifier_to_ability_parent_id( callable $identifier ): void {
		$parent = self::factory()->term->create_and_get(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Parent Term',
				'slug'     => 'parent-term',
			)
		);
		$child = self::factory()->term->create_and_get(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Child Term',
				'parent'   => $parent->term_id,
			)
		);
		$this->ability->result = $this->abilityResult( $child, true, false );

		$result = $this->tool->handle_tool_call(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Child Term',
				'parent'   => $identifier( $parent ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $this->ability->calls );
		$this->assertSame( $parent->term_id, $this->ability->input['parent'] );
	}

	public function parentIdentifierProvider(): array {
		return array(
			'ID'   => array( static fn( $term ) => (string) $term->term_id ),
			'name' => array( static fn( $term ) => $term->name ),
			'slug' => array( static fn( $term ) => $term->slug ),
		);
	}

	public function test_wp_error_becomes_stable_tool_failure(): void {
		$this->ability->result = new WP_Error( 'term_create_failed', 'Could not create term' );

		$result = $this->tool->handle_tool_call(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Broken Term',
			)
		);

		$this->assertSame( 1, $this->ability->calls );
		$this->assertSame(
			array(
				'success'   => false,
				'error'     => 'Could not create term',
				'tool_name' => 'create_taxonomy_term',
			),
			$result
		);
	}

	public function test_legacy_failure_result_becomes_stable_tool_failure(): void {
		$this->ability->result = array(
			'success' => false,
			'error'   => 'Legacy taxonomy failure',
		);

		$result = $this->tool->handle_tool_call(
			array(
				'taxonomy' => self::TAXONOMY,
				'name'     => 'Broken Term',
			)
		);

		$this->assertSame( 1, $this->ability->calls );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Legacy taxonomy failure', $result['error'] );
		$this->assertSame( 'create_taxonomy_term', $result['tool_name'] );
	}

	public function test_tool_has_no_direct_term_persistence_path(): void {
		$reflection = new \ReflectionClass( CreateTaxonomyTerm::class );
		$source     = file_get_contents( $reflection->getFileName() );

		$this->assertStringNotContainsString( 'wp_insert_term(', $source );
	}

	private function abilityResult( \WP_Term $term, bool $created, bool $existed ): array {
		return array(
			'success'   => true,
			'term_id'   => $term->term_id,
			'term_name' => $term->name,
			'term_slug' => $term->slug,
			'taxonomy'  => $term->taxonomy,
			'created'   => $created,
			'existed'   => $existed,
		);
	}

	private function replaceAbility( WP_Ability $ability ): void {
		$registry   = WP_Abilities_Registry::get_instance();
		$reflection = new \ReflectionProperty( $registry, 'registered_abilities' );
		$abilities  = $reflection->getValue( $registry );

		$abilities[ CreateTaxonomyTermAbility::ABILITY_NAME ] = $ability;
		$reflection->setValue( $registry, $abilities );
	}
}

class CountingCreateTaxonomyTermAbility extends WP_Ability {

	public int $calls = 0;
	public array $input = array();
	public $result = array();

	public function __construct( string $name ) {
		parent::__construct(
			$name,
			array(
				'label'               => 'Counting Create Taxonomy Term',
				'description'         => 'Captures taxonomy create delegation for tests.',
				'category'            => 'datamachine-taxonomy',
				'execute_callback'    => '__return_empty_array',
				'permission_callback' => '__return_true',
			)
		);
	}

	public function execute( $input = null ) {
		++$this->calls;
		$this->input = $input;

		return $this->result;
	}
}
