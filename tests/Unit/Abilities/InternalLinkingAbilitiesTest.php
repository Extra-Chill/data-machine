<?php
/**
 * InternalLinkingAbilities Tests
 *
 * Covers the link-graph extensibility primitives introduced in 0.72.0:
 * - `datamachine_link_extractors` filter registration + dispatch.
 * - `datamachine_link_resolvers` filter registration + dispatch.
 * - Typed queries (`types` parameter on audit/backlinks/orphans).
 * - Backward compatibility (no external extractors = current behavior).
 * - Edge typing in graph storage (`outbound[source][target]` shape).
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\InternalLinkingAbilities;
use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

class InternalLinkingAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Post IDs created during a single test, keyed for convenient access.
	 *
	 * @var array<string, int>
	 */
	private array $posts = array();

	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Ensure a stale cache doesn't leak across tests.
		delete_transient( InternalLinkingAbilities::GRAPH_TRANSIENT_KEY );
	}

	public function tear_down(): void {
		delete_transient( InternalLinkingAbilities::GRAPH_TRANSIENT_KEY );
		$this->posts = array();
		parent::tear_down();
	}

	/**
	 * Create three interlinked posts: A links to B, B links to C, C has no outbound.
	 * Returns [post_a_id, post_b_id, post_c_id].
	 */
	private function create_html_anchor_corpus(): array {
		$post_a = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Post A',
				'post_name'   => 'post-a',
			)
		);
		$post_b = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Post B',
				'post_name'   => 'post-b',
			)
		);
		$post_c = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Post C',
				'post_name'   => 'post-c',
			)
		);

		$permalink_b = get_permalink( $post_b );
		$permalink_c = get_permalink( $post_c );

		wp_update_post(
			array(
				'ID'           => $post_a,
				'post_content' => '<p>See <a href="' . esc_url( $permalink_b ) . '">Post B</a> for details.</p>',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_b,
				'post_content' => '<p>Continue reading <a href="' . esc_url( $permalink_c ) . '">Post C</a>.</p>',
			)
		);
		// Post C is an orphan from the html_anchor perspective of this corpus
		// only if nothing links to it; here B does. C itself has no outbound.
		wp_update_post(
			array(
				'ID'           => $post_c,
				'post_content' => '<p>No outbound links here.</p>',
			)
		);

		$this->posts = array(
			'a' => $post_a,
			'b' => $post_b,
			'c' => $post_c,
		);

		return array( $post_a, $post_b, $post_c );
	}

	public function test_audit_internal_links_ability_registered(): void {
		$ability = wp_get_ability( 'datamachine/audit-internal-links' );
		$this->assertNotNull( $ability );
		$this->assertSame( 'datamachine/audit-internal-links', $ability->get_name() );
	}

	public function test_html_anchor_extractor_registered_against_filter(): void {
		$extractors = InternalLinkingAbilities::getExtractors();
		$this->assertArrayHasKey( 'html_anchor', $extractors );
		$this->assertArrayHasKey( 'callback', $extractors['html_anchor'] );
		$this->assertIsCallable( $extractors['html_anchor']['callback'] );
	}

	public function test_html_anchor_resolver_registered_against_filter(): void {
		$resolvers = InternalLinkingAbilities::getResolvers();
		$this->assertArrayHasKey( 'html_anchor', $resolvers );
		$this->assertArrayHasKey( 'callback', $resolvers['html_anchor'] );
		$this->assertIsCallable( $resolvers['html_anchor']['callback'] );
	}

	public function test_default_audit_behavior_matches_pre_filter(): void {
		$this->create_html_anchor_corpus();

		$result = InternalLinkingAbilities::auditInternalLinks( array( 'force' => true ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['total_scanned'] );
		// A→B and B→C = 2 resolved outbound edges in this corpus.
		$this->assertGreaterThanOrEqual( 2, $result['total_links'] );

		// Every edge in the graph should be typed as html_anchor.
		$this->assertArrayHasKey( '_all_links', $result );
		foreach ( $result['_all_links'] as $edge ) {
			$this->assertArrayHasKey( 'edge_type', $edge );
			$this->assertSame( 'html_anchor', $edge['edge_type'] );
		}
	}

	public function test_graph_outbound_uses_new_shape_with_count_and_types(): void {
		list( $post_a, $post_b, $post_c ) = $this->create_html_anchor_corpus();

		$result = InternalLinkingAbilities::auditInternalLinks( array( 'force' => true ) );

		$this->assertArrayHasKey( 'outbound', $result );
		$outbound = $result['outbound'];

		// A → B edge.
		$this->assertArrayHasKey( $post_a, $outbound );
		$this->assertArrayHasKey( $post_b, $outbound[ $post_a ] );
		$this->assertArrayHasKey( 'count', $outbound[ $post_a ][ $post_b ] );
		$this->assertArrayHasKey( 'types', $outbound[ $post_a ][ $post_b ] );
		$this->assertSame( 1, $outbound[ $post_a ][ $post_b ]['count'] );
		$this->assertArrayHasKey( 'html_anchor', $outbound[ $post_a ][ $post_b ]['types'] );
		$this->assertSame( 1, $outbound[ $post_a ][ $post_b ]['types']['html_anchor'] );

		// B → C edge.
		$this->assertArrayHasKey( $post_b, $outbound );
		$this->assertArrayHasKey( $post_c, $outbound[ $post_b ] );
		$this->assertSame( 1, $outbound[ $post_b ][ $post_c ]['count'] );
	}

	public function test_extractor_filter_dispatch_surfaces_custom_edges(): void {
		list( $post_a, $post_b, $post_c ) = $this->create_html_anchor_corpus();

		// Register a test-only extractor that emits a synthetic edge from post A → post C.
		$extractor = static function ( $content, $context ) use ( $post_a, $post_c ) {
			if ( (int) ( $context['post_id'] ?? 0 ) !== $post_a ) {
				return array();
			}
			return array(
				array(
					'target_hint' => 'post-c',
					'edge_type'   => 'test_type',
					'display'     => 'Post C',
					'location'    => 'body',
				),
			);
		};
		$register_extractor = static function ( $extractors ) use ( $extractor ) {
			$extractors['test_type'] = array(
				'label'       => 'Test type',
				'description' => 'Synthetic edge type used in PHPUnit.',
				'callback'    => $extractor,
			);
			return $extractors;
		};
		add_filter( 'datamachine_link_extractors', $register_extractor );

		// Register a test-only resolver that maps the slug → post ID.
		$resolver = static function ( $target_hint ) use ( $post_c ) {
			if ( 'post-c' === $target_hint ) {
				return $post_c;
			}
			return null;
		};
		$register_resolver = static function ( $resolvers ) use ( $resolver ) {
			$resolvers['test_type'] = array( 'callback' => $resolver );
			return $resolvers;
		};
		add_filter( 'datamachine_link_resolvers', $register_resolver );

		try {
			$result = InternalLinkingAbilities::auditInternalLinks( array( 'force' => true ) );

			$this->assertTrue( $result['success'] );

			// Find the synthetic edge in _all_links and assert type + resolution.
			$found = false;
			foreach ( $result['_all_links'] as $edge ) {
				if ( 'test_type' === ( $edge['edge_type'] ?? '' ) ) {
					$found = true;
					$this->assertSame( $post_a, $edge['source_id'] );
					$this->assertSame( $post_c, $edge['target_id'] );
					$this->assertTrue( $edge['resolved'] );
					$this->assertSame( 'Post C', $edge['display'] );
					$this->assertSame( 'body', $edge['location'] );
					break;
				}
			}
			$this->assertTrue( $found, 'Expected a synthetic test_type edge in the graph.' );

			// Outbound structure should reflect both html_anchor and test_type counts for A → C.
			$outbound_a_c = $result['outbound'][ $post_a ][ $post_c ] ?? null;
			$this->assertIsArray( $outbound_a_c );
			$this->assertArrayHasKey( 'test_type', $outbound_a_c['types'] );
			$this->assertSame( 1, $outbound_a_c['types']['test_type'] );
		} finally {
			remove_filter( 'datamachine_link_extractors', $register_extractor );
			remove_filter( 'datamachine_link_resolvers', $register_resolver );
		}
	}

	public function test_resolver_filter_maps_target_hint_to_post_id(): void {
		list( , , $post_c ) = $this->create_html_anchor_corpus();

		$resolver_called_with = array();
		$resolver             = static function ( $target_hint, $context ) use ( $post_c, &$resolver_called_with ) {
			$resolver_called_with[] = $target_hint;
			return 'post-c' === $target_hint ? $post_c : null;
		};
		$register_resolver = static function ( $resolvers ) use ( $resolver ) {
			$resolvers['test_type'] = array( 'callback' => $resolver );
			return $resolvers;
		};
		add_filter( 'datamachine_link_resolvers', $register_resolver );

		try {
			$resolved = InternalLinkingAbilities::dispatchResolver( 'post-c', 'test_type', array( 'post_id' => 1 ) );
			$this->assertSame( $post_c, $resolved );

			$unresolved = InternalLinkingAbilities::dispatchResolver( 'does-not-exist', 'test_type', array() );
			$this->assertNull( $unresolved );

			// Unknown edge_type returns null (no resolver registered).
			$unknown = InternalLinkingAbilities::dispatchResolver( 'whatever', 'unknown_type', array() );
			$this->assertNull( $unknown );

			$this->assertSame( array( 'post-c', 'does-not-exist' ), $resolver_called_with );
		} finally {
			remove_filter( 'datamachine_link_resolvers', $register_resolver );
		}
	}

	public function test_backlinks_types_filter_scopes_to_single_edge_type(): void {
		list( $post_a, $post_b, $post_c ) = $this->create_html_anchor_corpus();

		// Register a synthetic extractor + resolver pointing A → C with edge_type test_type.
		$extractor = static function ( $content, $context ) use ( $post_a ) {
			if ( (int) ( $context['post_id'] ?? 0 ) !== $post_a ) {
				return array();
			}
			return array(
				array(
					'target_hint' => 'post-c',
					'edge_type'   => 'test_type',
				),
			);
		};
		$resolver = static function ( $target_hint ) use ( $post_c ) {
			return 'post-c' === $target_hint ? $post_c : null;
		};
		$reg_extractor = static function ( $extractors ) use ( $extractor ) {
			$extractors['test_type'] = array( 'callback' => $extractor );
			return $extractors;
		};
		$reg_resolver = static function ( $resolvers ) use ( $resolver ) {
			$resolvers['test_type'] = array( 'callback' => $resolver );
			return $resolvers;
		};
		add_filter( 'datamachine_link_extractors', $reg_extractor );
		add_filter( 'datamachine_link_resolvers', $reg_resolver );

		try {
			// Prime the cache by running a full audit.
			InternalLinkingAbilities::auditInternalLinks( array( 'force' => true ) );

			// Without types filter: C has two inbound (B via html_anchor, A via test_type).
			$all_types = InternalLinkingAbilities::getBacklinks( array( 'post_id' => $post_c ) );
			$this->assertTrue( $all_types['success'] );
			$source_ids_all = array_map( static fn( $bl ) => $bl['source_id'], $all_types['backlinks'] );
			sort( $source_ids_all );
			$expected_all = array( $post_a, $post_b );
			sort( $expected_all );
			$this->assertSame( $expected_all, $source_ids_all );

			// Scoped to test_type only: just A.
			$scoped = InternalLinkingAbilities::getBacklinks(
				array(
					'post_id' => $post_c,
					'types'   => array( 'test_type' ),
				)
			);
			$this->assertTrue( $scoped['success'] );
			$this->assertSame( 1, $scoped['backlink_count'] );
			$this->assertSame( $post_a, $scoped['backlinks'][0]['source_id'] );

			// Scoped to html_anchor only: just B.
			$scoped_html = InternalLinkingAbilities::getBacklinks(
				array(
					'post_id' => $post_c,
					'types'   => array( 'html_anchor' ),
				)
			);
			$this->assertTrue( $scoped_html['success'] );
			$this->assertSame( 1, $scoped_html['backlink_count'] );
			$this->assertSame( $post_b, $scoped_html['backlinks'][0]['source_id'] );
		} finally {
			remove_filter( 'datamachine_link_extractors', $reg_extractor );
			remove_filter( 'datamachine_link_resolvers', $reg_resolver );
		}
	}

	public function test_audit_types_filter_recomputes_aggregates(): void {
		list( $post_a, $post_b, $post_c ) = $this->create_html_anchor_corpus();

		// Register a test_type extractor that links A → C.
		$reg_extractor = static function ( $extractors ) use ( $post_a ) {
			$extractors['test_type'] = array(
				'callback' => static function ( $content, $context ) use ( $post_a ) {
					if ( (int) ( $context['post_id'] ?? 0 ) !== $post_a ) {
						return array();
					}
					return array( array( 'target_hint' => 'post-c', 'edge_type' => 'test_type' ) );
				},
			);
			return $extractors;
		};
		$reg_resolver = static function ( $resolvers ) use ( $post_c ) {
			$resolvers['test_type'] = array(
				'callback' => static function ( $target_hint ) use ( $post_c ) {
					return 'post-c' === $target_hint ? $post_c : null;
				},
			);
			return $resolvers;
		};
		add_filter( 'datamachine_link_extractors', $reg_extractor );
		add_filter( 'datamachine_link_resolvers', $reg_resolver );

		try {
			// All-types audit: total_links = html_anchor (2) + test_type (1) = 3.
			$full = InternalLinkingAbilities::auditInternalLinks( array( 'force' => true ) );
			$this->assertGreaterThanOrEqual( 3, $full['total_links'] );

			// Scoped to test_type only: total_links in the returned view drops to 1.
			$scoped = InternalLinkingAbilities::auditInternalLinks( array( 'types' => array( 'test_type' ) ) );
			$this->assertSame( 1, $scoped['total_links'] );

			// The scoped outbound map should only contain A → C (not B → C from html_anchor).
			$this->assertArrayHasKey( $post_a, $scoped['outbound'] );
			$this->assertArrayHasKey( $post_c, $scoped['outbound'][ $post_a ] );
			$this->assertArrayNotHasKey( $post_b, $scoped['outbound'] );
		} finally {
			remove_filter( 'datamachine_link_extractors', $reg_extractor );
			remove_filter( 'datamachine_link_resolvers', $reg_resolver );
		}
	}

	public function test_cache_key_bumped_to_v2(): void {
		// Ensures existing installs rebuild cleanly on upgrade.
		$this->assertSame( 'datamachine_link_graph_v2', InternalLinkingAbilities::GRAPH_TRANSIENT_KEY );
	}

	public function test_extractor_stamps_edge_type_from_registration_key(): void {
		// Extractor that returns edges WITHOUT an edge_type — dispatch should stamp the key.
		$reg = static function ( $extractors ) {
			$extractors['stamped_type'] = array(
				'callback' => static function () {
					return array( array( 'target_hint' => 'whatever' ) );
				},
			);
			return $extractors;
		};
		add_filter( 'datamachine_link_extractors', $reg );

		try {
			$edges = InternalLinkingAbilities::dispatchExtractors( '<p>ignored</p>', array( 'post_id' => 1, 'post_type' => 'post' ) );
			$found = false;
			foreach ( $edges as $edge ) {
				if ( 'whatever' === ( $edge['target_hint'] ?? '' ) ) {
					$this->assertSame( 'stamped_type', $edge['edge_type'] );
					$found = true;
				}
			}
			$this->assertTrue( $found );
		} finally {
			remove_filter( 'datamachine_link_extractors', $reg );
		}
	}
}
