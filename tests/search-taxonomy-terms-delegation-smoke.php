<?php
/**
 * Pure-PHP smoke test for search_taxonomy_terms ability delegation.
 *
 * Run with: php tests/search-taxonomy-terms-delegation-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	define( 'ABSPATH', __DIR__ );

	class WP_Error {
		public function __construct(
			private string $code,
			private string $message
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function add_filter( ...$args ): void {}

	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/BaseTool.php';
	require_once dirname( __DIR__ ) . '/inc/Api/Chat/Tools/SearchTaxonomyTerms.php';
}

namespace DataMachine\Api\Chat\Tools {
	final class SearchTaxonomyTermsTestAbility {
		public int $calls = 0;
		public array $input = array();
		public mixed $result;

		public function __construct() {
			$this->result = array(
				'success' => true,
				'terms'   => array(
					array(
						'term_id'    => 9,
						'name'       => 'Child',
						'slug'       => 'child',
						'count'      => 12,
						'parent'     => 4,
						'term_group' => 0,
					),
				),
				'total'   => 1,
			);
		}

		public function execute( array $input ): mixed {
			++$this->calls;
			$this->input = $input;
			return $this->result;
		}
	}

	$GLOBALS['search_taxonomy_terms_test_ability'] = new SearchTaxonomyTermsTestAbility();

	function wp_get_ability( string $name ): ?SearchTaxonomyTermsTestAbility {
		return 'datamachine/get-taxonomy-terms' === $name ? $GLOBALS['search_taxonomy_terms_test_ability'] : null;
	}

	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) );
	}

	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	function get_taxonomy( string $taxonomy ): object {
		return (object) array(
			'label'        => 'Genres',
			'hierarchical' => true,
		);
	}

	function get_term( int $term_id, string $taxonomy ): object {
		return (object) array( 'name' => 'Parent' );
	}

	function wp_count_terms( array $args ): int {
		return 37;
	}

	$failed = 0;
	$total  = 0;

	function assert_search_taxonomy_terms( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}
		echo "  FAIL: {$name}\n";
		++$failed;
	}

	echo "=== Search Taxonomy Terms Delegation Smoke ===\n";

	$tool    = new SearchTaxonomyTerms();
	$ability = $GLOBALS['search_taxonomy_terms_test_ability'];
	$result  = $tool->handle_tool_call(
		array(
			'taxonomy' => 'Genre!',
			'search'   => '  <b>rock</b>  ',
		)
	);

	assert_search_taxonomy_terms( 'registered ability executes exactly once', 1 === $ability->calls );
	assert_search_taxonomy_terms(
		'default query input is canonical and bounded',
		array(
			'taxonomy'   => 'genre',
			'search'     => 'rock',
			'hide_empty' => false,
			'number'     => 20,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) === $ability->input
	);
	assert_search_taxonomy_terms( 'taxonomy metadata is preserved', 'Genres' === $result['data']['taxonomy_label'] && true === $result['data']['hierarchical'] );
	assert_search_taxonomy_terms( 'parent name enriches canonical row', 'Parent' === $result['data']['terms'][0]['parent'] );
	assert_search_taxonomy_terms( 'tool row schema excludes ability-only fields', array( 'term_id', 'name', 'slug', 'count', 'parent' ) === array_keys( $result['data']['terms'][0] ) );
	assert_search_taxonomy_terms( 'tool counts and search envelope are preserved', 37 === $result['data']['total_terms'] && 1 === $result['data']['returned_count'] && 'rock' === $result['data']['search_query'] );

	$ability->calls = 0;
	$tool->handle_tool_call( array( 'taxonomy' => 'genre', 'limit' => 999 ) );
	assert_search_taxonomy_terms( 'maximum limit remains 100', 1 === $ability->calls && 100 === $ability->input['number'] );

	$ability->calls = 0;
	$result         = $tool->handle_tool_call( array( 'taxonomy' => '!!!' ) );
	assert_search_taxonomy_terms( 'empty sanitized taxonomy retains validation error', 0 === $ability->calls && 'taxonomy is required and must be a non-empty string' === $result['error'] );

	$ability->result = new \WP_Error( 'taxonomy_not_accessible', "Taxonomy 'nav_menu' is a system taxonomy and cannot be accessed" );
	$result          = $tool->handle_tool_call( array( 'taxonomy' => 'nav_menu' ) );
	assert_search_taxonomy_terms( 'protected taxonomy error remains model-facing compatible', "Taxonomy 'nav_menu' is a system taxonomy and cannot be queried" === $result['error'] );

	$ability->result = new \WP_Error( 'term_query_failed', 'Canonical query failed' );
	$result          = $tool->handle_tool_call( array( 'taxonomy' => 'genre' ) );
	assert_search_taxonomy_terms( 'ability WP_Error maps to stable tool failure', false === $result['success'] && 'Canonical query failed' === $result['error'] );

	$ability->result = array( 'success' => false, 'error' => 'Legacy query failed' );
	$result          = $tool->handle_tool_call( array( 'taxonomy' => 'genre' ) );
	assert_search_taxonomy_terms( 'legacy failure maps to stable tool failure', false === $result['success'] && 'Legacy query failed' === $result['error'] );

	$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Api/Chat/Tools/SearchTaxonomyTerms.php' );
	assert_search_taxonomy_terms( 'tool has no direct get_terms query', false === strpos( $source, 'get_terms(' ) );

	echo "\n";
	if ( 0 === $failed ) {
		echo "=== search-taxonomy-terms-delegation-smoke: ALL PASS ({$total}) ===\n";
		exit( 0 );
	}

	echo "=== search-taxonomy-terms-delegation-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}
