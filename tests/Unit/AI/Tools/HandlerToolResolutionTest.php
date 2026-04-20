<?php
/**
 * Tests for handler tool resolution via the unified `datamachine_tools` registry.
 *
 * Covers the `_handler_callable` protocol that replaced the legacy
 * `chubes_ai_tools` filter:
 *
 *  - Exact slug matching (`'handler' => 'wp_publish'`)
 *  - Cross-cutting type matching (`'handler_types' => ['fetch', 'event_import']`)
 *  - Per-scope caching
 *  - Pipeline gather flow through ToolPolicyResolver
 *
 * @package DataMachine\Tests\Unit\AI\Tools
 */

namespace DataMachine\Tests\Unit\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

class HandlerToolResolutionTest extends WP_UnitTestCase {

	private ToolManager $tool_manager;

	public function set_up(): void {
		parent::set_up();
		datamachine_register_capabilities();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ToolManager::clearCache();
		$this->tool_manager = new ToolManager();
	}

	public function tear_down(): void {
		ToolManager::clearCache();
		parent::tear_down();
	}

	// ============================================
	// EXACT SLUG MATCHING
	// ============================================

	public function test_resolves_handler_tool_with_exact_slug_match(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_widget_publish'] = array(
					'_handler_callable' => static function ( $slug, $config, $engine ) {
						return array(
							'widget_publish' => array(
								'description' => 'Publish to widget',
								'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
								'_observed'   => array(
									'slug'   => $slug,
									'config' => $config,
									'engine' => $engine,
								),
							),
						);
					},
					'handler'           => 'widget_publish',
					'contexts'          => array( 'pipeline' ),
					'access_level'      => 'admin',
				);
				return $tools;
			}
		);

		$resolved = $this->tool_manager->resolveHandlerTools(
			'widget_publish',
			array( 'site_id' => 42 ),
			array( 'job_id' => 99 ),
			'flow_step_xyz'
		);

		$this->assertArrayHasKey( 'widget_publish', $resolved );
		$this->assertSame( 'Publish to widget', $resolved['widget_publish']['description'] );
		$this->assertSame( 'widget_publish', $resolved['widget_publish']['handler'] );
		$this->assertSame(
			array( 'pipeline' ),
			$resolved['widget_publish']['contexts']
		);
		$this->assertSame( 'admin', $resolved['widget_publish']['access_level'] );
		$this->assertSame( 'widget_publish', $resolved['widget_publish']['_observed']['slug'] );
		$this->assertSame( array( 'site_id' => 42 ), $resolved['widget_publish']['_observed']['config'] );
		$this->assertSame( array( 'job_id' => 99 ), $resolved['widget_publish']['_observed']['engine'] );
	}

	public function test_skips_handler_tools_with_non_matching_slug(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_widget_publish'] = array(
					'_handler_callable' => static fn() => array( 'widget_publish' => array( 'description' => 'x' ) ),
					'handler'           => 'widget_publish',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$resolved = $this->tool_manager->resolveHandlerTools(
			'something_else',
			array(),
			array(),
			'scope'
		);

		$this->assertSame( array(), $resolved );
	}

	public function test_callback_returning_non_array_is_silently_skipped(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_widget'] = array(
					'_handler_callable' => static fn() => null,
					'handler'           => 'widget',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$resolved = $this->tool_manager->resolveHandlerTools( 'widget', array(), array(), 'scope' );

		$this->assertSame( array(), $resolved );
	}

	// ============================================
	// HANDLER TYPE MATCHING (cross-cutting)
	// ============================================

	public function test_resolves_cross_cutting_tool_via_handler_types(): void {
		// Register a handler so the type lookup succeeds.
		add_filter(
			'datamachine_handlers',
			function ( array $handlers ): array {
				$handlers['rss_feed'] = array(
					'type'  => 'fetch',
					'class' => 'StubRssHandler',
					'label' => 'RSS',
				);
				return $handlers;
			}
		);

		// Register a tool that applies to ANY fetch-type handler.
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_skip_item_test'] = array(
					'_handler_callable' => static function ( $slug ) {
						return array(
							'skip_item_test' => array(
								'description'    => "Skip item from {$slug}",
								'_resolved_slug' => $slug,
							),
						);
					},
					'handler_types'     => array( 'fetch', 'event_import' ),
					'contexts'          => array( 'pipeline' ),
					'access_level'      => 'admin',
				);
				return $tools;
			}
		);

		$resolved = $this->tool_manager->resolveHandlerTools(
			'rss_feed',
			array(),
			array(),
			'scope'
		);

		$this->assertArrayHasKey( 'skip_item_test', $resolved );
		$this->assertSame( 'Skip item from rss_feed', $resolved['skip_item_test']['description'] );
		$this->assertSame( 'rss_feed', $resolved['skip_item_test']['handler'] );
		$this->assertSame( 'rss_feed', $resolved['skip_item_test']['_resolved_slug'] );
	}

	public function test_handler_types_skips_non_matching_handler_type(): void {
		add_filter(
			'datamachine_handlers',
			function ( array $handlers ): array {
				$handlers['twitter'] = array(
					'type'  => 'publish',
					'class' => 'StubTwitter',
				);
				return $handlers;
			}
		);

		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_skip_item_test'] = array(
					'_handler_callable' => static fn() => array( 'skip_item_test' => array( 'description' => 'x' ) ),
					'handler_types'     => array( 'fetch' ),
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$resolved = $this->tool_manager->resolveHandlerTools(
			'twitter',
			array(),
			array(),
			'scope'
		);

		$this->assertArrayNotHasKey( 'skip_item_test', $resolved );
	}

	// ============================================
	// PER-SCOPE CACHING
	// ============================================

	public function test_resolution_cached_per_scope(): void {
		$invocations = 0;
		add_filter(
			'datamachine_tools',
			function ( array $tools ) use ( &$invocations ): array {
				$tools['__handler_tools_caching'] = array(
					'_handler_callable' => function () use ( &$invocations ) {
						$invocations++;
						return array( 'caching' => array( 'description' => 'x' ) );
					},
					'handler'           => 'caching',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$this->tool_manager->resolveHandlerTools( 'caching', array(), array(), 'scope_a' );
		$this->tool_manager->resolveHandlerTools( 'caching', array(), array(), 'scope_a' );

		$this->assertSame( 1, $invocations, 'Same scope should only invoke callback once.' );

		$this->tool_manager->resolveHandlerTools( 'caching', array(), array(), 'scope_b' );

		$this->assertSame( 2, $invocations, 'Different scope must re-invoke callback.' );
	}

	public function test_no_caching_when_scope_is_empty(): void {
		$invocations = 0;
		add_filter(
			'datamachine_tools',
			function ( array $tools ) use ( &$invocations ): array {
				$tools['__handler_tools_no_cache'] = array(
					'_handler_callable' => function () use ( &$invocations ) {
						$invocations++;
						return array( 'no_cache' => array( 'description' => 'x' ) );
					},
					'handler'           => 'no_cache',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$this->tool_manager->resolveHandlerTools( 'no_cache', array(), array(), '' );
		$this->tool_manager->resolveHandlerTools( 'no_cache', array(), array(), '' );

		$this->assertSame( 2, $invocations, 'Empty scope must bypass cache.' );
	}

	// ============================================
	// REGISTRY INTROSPECTION
	// ============================================

	public function test_get_handler_tool_entries_returns_only_handler_callable_entries(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['static_global']          = array(
					'description' => 'A static tool',
					'contexts'    => array( 'chat' ),
				);
				$tools['__handler_tools_widget'] = array(
					'_handler_callable' => static fn() => array( 'widget' => array() ),
					'handler'           => 'widget',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$entries = $this->tool_manager->get_handler_tool_entries();

		$this->assertArrayHasKey( '__handler_tools_widget', $entries );
		$this->assertArrayNotHasKey( 'static_global', $entries );
	}

	// ============================================
	// PIPELINE GATHER FLOW
	// ============================================

	public function test_pipeline_resolver_surfaces_handler_tools_for_adjacent_step(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_pubtest'] = array(
					'_handler_callable' => static function ( $slug, $config ) {
						return array(
							'pubtest_publish' => array(
								'description' => 'Publish via pubtest',
								'parameters'  => array(),
								'config_seen' => $config,
							),
						);
					},
					'handler'           => 'pubtest',
					'contexts'          => array( 'pipeline' ),
					'access_level'      => 'admin',
				);
				return $tools;
			}
		);

		$resolver = new ToolPolicyResolver();
		$tools    = $resolver->resolve(
			array(
				'context'          => ToolPolicyResolver::CONTEXT_PIPELINE,
				'next_step_config' => array(
					'flow_step_id'    => 'fs_pipeline_test',
					'handler_slugs'   => array( 'pubtest' ),
					'handler_configs' => array( 'pubtest' => array( 'site' => 'example.com' ) ),
				),
			)
		);

		$this->assertArrayHasKey( 'pubtest_publish', $tools );
		$this->assertSame( 'pubtest', $tools['pubtest_publish']['handler'] );
		$this->assertSame( array( 'site' => 'example.com' ), $tools['pubtest_publish']['config_seen'] );
	}

	public function test_pipeline_resolver_omits_handler_wrappers_from_static_tools(): void {
		add_filter(
			'datamachine_tools',
			function ( array $tools ): array {
				$tools['__handler_tools_unused'] = array(
					'_handler_callable' => static fn() => array( 'unused' => array() ),
					'handler'           => 'no_match',
					'contexts'          => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$resolver = new ToolPolicyResolver();
		$tools    = $resolver->resolve(
			array(
				'context' => ToolPolicyResolver::CONTEXT_PIPELINE,
			)
		);

		$this->assertArrayNotHasKey( '__handler_tools_unused', $tools );
	}
}
