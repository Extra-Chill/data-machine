<?php
/**
 * Taxonomy CLI registered ability boundary tests.
 *
 * @package DataMachine\Tests\Unit\Cli\Commands
 */

namespace DataMachine\Tests\Unit\Cli\Commands;

use DataMachine\Abilities\Taxonomy\CreateTaxonomyTermAbility;
use DataMachine\Cli\AbilityRunner;
use DataMachine\Cli\Commands\TaxonomyCommand;
use WP_UnitTestCase;

class TaxonomyCommandTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_taxonomy_command_and_registered_abilities_are_available(): void {
		$this->assertTrue( class_exists( TaxonomyCommand::class ) );
		$this->assertNotNull( wp_get_ability( CreateTaxonomyTermAbility::ABILITY_NAME ) );

		foreach ( array( 'get-taxonomy-terms', 'update-taxonomy-term', 'delete-taxonomy-term', 'resolve-term' ) as $slug ) {
			$this->assertNotNull( wp_get_ability( "datamachine/{$slug}" ) );
		}
	}

	public function test_taxonomy_mutations_execute_through_registered_abilities(): void {
		$created = AbilityRunner::execute(
			CreateTaxonomyTermAbility::ABILITY_NAME,
			array(
				'name'     => 'Taxonomy CLI Boundary',
				'taxonomy' => 'post_tag',
			)
		);
		$this->assertTrue( $created['success'] );
		$term_id = (int) $created['term_id'];

		$updated = AbilityRunner::execute(
			'datamachine/update-taxonomy-term',
			array(
				'term'     => (string) $term_id,
				'taxonomy' => 'post_tag',
				'name'     => 'Taxonomy CLI Updated',
			)
		);
		$this->assertTrue( $updated['success'] );

		$resolved = AbilityRunner::execute(
			'datamachine/resolve-term',
			array(
				'identifier' => 'Taxonomy CLI Updated',
				'taxonomy'   => 'post_tag',
				'create'     => false,
			)
		);
		$this->assertTrue( $resolved['success'] );
		$this->assertSame( $term_id, (int) $resolved['term_id'] );
		$this->assertFalse( $resolved['created'] );

		$deleted = AbilityRunner::execute(
			'datamachine/delete-taxonomy-term',
			array(
				'term'     => (string) $term_id,
				'taxonomy' => 'post_tag',
			)
		);
		$this->assertTrue( $deleted['success'], wp_json_encode( $deleted ) );
	}

	public function test_native_ability_errors_normalize_to_cli_failure_shape(): void {
		$result = AbilityRunner::execute(
			'datamachine/resolve-term',
			array(
				'identifier' => 'Missing taxonomy',
				'taxonomy'   => 'not_registered',
				'create'     => false,
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( "Taxonomy 'not_registered' does not exist", $result['error'] );
		$this->assertSame( 'taxonomy_not_found', $result['wp_error_code'] );
	}
}
