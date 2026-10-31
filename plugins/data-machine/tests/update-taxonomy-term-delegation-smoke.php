<?php
/**
 * Pure-PHP smoke test for update_taxonomy_term ability delegation.
 *
 * Run with: php tests/update-taxonomy-term-delegation-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Taxonomy {
	class ResolveTermAbility {
		public static function resolve( string $identifier, string $taxonomy, bool $create = false ): array {
			unset( $taxonomy, $create );
			$terms = $GLOBALS['taxonomy_terms'];

			return isset( $terms[ $identifier ] )
				? array( 'success' => true, 'term_id' => $terms[ $identifier ]->term_id )
				: array( 'success' => false, 'error' => "Term '{$identifier}' not found" );
		}
	}
}

namespace DataMachine\Core\WordPress {
	class TaxonomyHandler {
		public static function shouldSkipTaxonomy( string $taxonomy ): bool {
			unset( $taxonomy );
			return false;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	class WP_Error {
		public function __construct( private string $message ) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class WP_Term {
		public function __construct(
			public int $term_id,
			public string $name,
			public string $slug,
			public string $description = '',
			public int $parent = 0
		) {}
	}

	class UpdateTaxonomyTermTestAbility {
		public function execute( array $input ) {
			$GLOBALS['ability_inputs'][] = $input;
			$GLOBALS['sequence'][]       = 'core';
			$result                      = $GLOBALS['ability_result'];

			if ( is_callable( $result ) ) {
				return $result( $input );
			}

			return $result;
		}
	}

	function add_filter(): void {}
	function taxonomy_exists(): bool { return true; }
	function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function get_term( $term_id, $taxonomy ) {
		unset( $taxonomy );
		return $GLOBALS['taxonomy_terms'][ (string) $term_id ] ?? null;
	}
	function get_taxonomy(): object { return (object) array( 'hierarchical' => $GLOBALS['hierarchical'] ); }
	function wp_get_ability(): ?UpdateTaxonomyTermTestAbility { return $GLOBALS['ability_available'] ? new UpdateTaxonomyTermTestAbility() : null; }
	function update_term_meta( int $term_id, string $key, $value ): bool {
		$GLOBALS['meta_writes'][] = compact( 'term_id', 'key', 'value' );
		$GLOBALS['sequence'][]    = 'meta';
		return true;
	}

	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/BaseTool.php';
	require_once dirname( __DIR__ ) . '/inc/Api/Chat/Tools/UpdateTaxonomyTerm.php';

	use DataMachine\Api\Chat\Tools\UpdateTaxonomyTerm;

	$failed = 0;
	$total  = 0;

	function assert_update_term( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}
		echo "  FAIL: {$name}\n";
		++$failed;
	}

	function reset_update_term_test(): void {
		$term = new WP_Term( 10, 'Old Name', 'old-name' );
		$GLOBALS['taxonomy_terms'] = array(
			'10'          => $term,
			'Old Name'    => $term,
			'old-name'    => $term,
			'20'          => new WP_Term( 20, 'Parent Name', 'parent-slug' ),
			'Parent Name' => new WP_Term( 20, 'Parent Name', 'parent-slug' ),
			'parent-slug' => new WP_Term( 20, 'Parent Name', 'parent-slug' ),
		);
		$GLOBALS['ability_inputs']    = array();
		$GLOBALS['meta_writes']       = array();
		$GLOBALS['sequence']          = array();
		$GLOBALS['ability_available'] = true;
		$GLOBALS['ability_result']    = array(
			'success' => true,
			'changes' => array( 'name' => array( 'from' => 'Old Name', 'to' => 'New Name' ) ),
		);
		$GLOBALS['hierarchical']      = true;
	}

	$tool = new UpdateTaxonomyTerm();

	echo "=== Update Taxonomy Term Delegation Smoke ===\n";

	reset_update_term_test();
	$result = $tool->handle_tool_call( array( 'term' => 'old-name', 'taxonomy' => 'category', 'name' => 'New Name' ) );
	assert_update_term( 'core-only update succeeds', true === $result['success'] );
	assert_update_term( 'registered ability executes exactly once', 1 === count( $GLOBALS['ability_inputs'] ) );
	assert_update_term( 'ability receives flexible resolved term ID', '10' === $GLOBALS['ability_inputs'][0]['term'] );
	assert_update_term( 'updated_fields comes from canonical changes', array( 'name' ) === $result['data']['updated_fields'] );

	foreach ( array( '20', 'Parent Name', 'parent-slug' ) as $parent_identifier ) {
		reset_update_term_test();
		$GLOBALS['ability_result']['changes'] = array( 'parent' => array( 'from' => 0, 'to' => 20 ) );
		$result = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'parent' => $parent_identifier ) );
		assert_update_term( "parent {$parent_identifier} maps to ID", true === $result['success'] && 20 === $GLOBALS['ability_inputs'][0]['parent'] );
	}

	reset_update_term_test();
	$result = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'parent' => 'old-name' ) );
	assert_update_term( 'self-parent remains rejected', false === $result['success'] && 'A term cannot be its own parent' === $result['error'] );
	assert_update_term( 'self-parent rejection skips ability', array() === $GLOBALS['ability_inputs'] );

	reset_update_term_test();
	$result = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'meta' => array( 'empty' => '', 'null' => null, 'capacity' => 500 ) ) );
	assert_update_term( 'meta-only update skips core ability', true === $result['success'] && array() === $GLOBALS['ability_inputs'] );
	assert_update_term( 'empty meta values remain no-ops', array( 'capacity' ) === $result['data']['updated_meta'] );

	reset_update_term_test();
	$result = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'meta' => array( '_private' => 'blocked' ) ) );
	assert_update_term( 'protected meta remains blocked', false === $result['success'] && array() === $GLOBALS['meta_writes'] );

	foreach ( array( new WP_Error( 'Core exploded' ), array( 'success' => false, 'error' => 'Legacy failure' ), false ) as $failure ) {
		reset_update_term_test();
		$GLOBALS['ability_result'] = $failure;
		$result                    = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'name' => 'New Name', 'meta' => array( 'capacity' => 500 ) ) );
		assert_update_term( 'ability failure maps to stable tool failure', false === $result['success'] && isset( $result['error'] ) );
		assert_update_term( 'ability failure prevents meta writes', array() === $GLOBALS['meta_writes'] );
	}

	reset_update_term_test();
	$result = $tool->handle_tool_call( array( 'term' => '10', 'taxonomy' => 'category', 'name' => 'New Name', 'meta' => array( 'capacity' => 500 ) ) );
	assert_update_term( 'mixed update executes core before meta', true === $result['success'] && array( 'core', 'meta' ) === $GLOBALS['sequence'] );

	echo "\n";
	if ( 0 === $failed ) {
		echo "=== update-taxonomy-term-delegation-smoke: ALL PASS ({$total}) ===\n";
		exit( 0 );
	}

	echo "=== update-taxonomy-term-delegation-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}
