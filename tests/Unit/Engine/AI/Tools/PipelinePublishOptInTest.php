<?php

namespace DataMachine\Tests\Unit\Engine\AI\Tools;

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use WP_UnitTestCase;

/**
 * Verifies that generic content-publishing abilities are opt-in for pipeline
 * AI steps (data-machine#2852).
 *
 * The default pipeline preset must not surface free-form publish/write tools.
 * A step receives its adjacent-handler tools, research/read tools, and
 * disposition tools by default; generic publish/write tools only appear when
 * the step explicitly lists them in enabled_tools (or an allow-mode policy).
 */
class PipelinePublishOptInTest extends WP_UnitTestCase {

	private ToolPolicyResolver $resolver;

	/**
	 * Track filter callbacks registered per-test so tear_down can remove them.
	 *
	 * @var array<int, array{hook:string, callback:callable, priority:int}>
	 */
	private array $filter_callbacks = array();

	public function set_up(): void {
		parent::set_up();

		datamachine_register_capabilities();
		$registry = \WP_Abilities_Registry::get_instance();
		if ( ! $registry->is_registered( 'datamachine/test-publish' ) ) {
			wp_register_ability(
				'datamachine/test-publish',
				array(
					'label'               => 'Test Publish',
					'description'         => 'Test publishing ability fixture.',
					'category'            => 'datamachine-publishing',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static fn() => array(),
					'permission_callback' => '__return_true',
				)
			);
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ToolManager::clearCache();
		$this->resolver = new ToolPolicyResolver();
	}

	public function tear_down(): void {
		foreach ( $this->filter_callbacks as $entry ) {
			remove_filter( $entry['hook'], $entry['callback'], $entry['priority'] );
		}
		$this->filter_callbacks = array();

		ToolManager::clearCache();
		$registry = \WP_Abilities_Registry::get_instance();
		if ( $registry->is_registered( 'datamachine/test-publish' ) ) {
			$registry->unregister( 'datamachine/test-publish' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register a datamachine_tools filter callback and track it for cleanup.
	 */
	private function registerToolsFilter( callable $callback ): void {
		add_filter( 'datamachine_tools', $callback );
		$this->filter_callbacks[] = array(
			'hook'     => 'datamachine_tools',
			'callback' => $callback,
			'priority' => 10,
		);
	}

	/**
	 * Register a datamachine_handlers filter callback and track it for cleanup.
	 */
	private function registerHandlersFilter( callable $callback ): void {
		add_filter( 'datamachine_handlers', $callback );
		$this->filter_callbacks[] = array(
			'hook'     => 'datamachine_handlers',
			'callback' => $callback,
			'priority' => 10,
		);
	}

	// ============================================
	// REQUIREMENT 1: generic write tools excluded by default
	// ============================================

	public function test_pipeline_excludes_flagged_content_writing_tool_by_default(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_gated_publish'] = array(
					'label'                    => 'Test Gated Publish',
					'description'              => 'Mirrors upsert_post / insert_content declarations.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'pipeline', 'chat' ),
					'requires_pipeline_opt_in' => true,
				);
				$tools['test_read_tool'] = array(
					'label'       => 'Test Read Tool',
					'description' => 'A read-only research tool that must remain available.',
					'class'       => 'NonExistentClass',
					'method'      => 'handle_tool_call',
					'parameters'  => array(),
					'modes'       => array( 'pipeline' ),
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode' => ToolPolicyResolver::MODE_PIPELINE,
			)
		);

		$this->assertArrayNotHasKey( 'test_gated_publish', $tools, 'Flagged content-writing tool is excluded by default.' );
		$this->assertArrayHasKey( 'test_read_tool', $tools, 'Non-gated research tool remains available.' );
	}

	public function test_pipeline_excludes_publishing_category_tool_by_default(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_publishing_ability_tool'] = array(
					'label'            => 'Test Publishing Ability Tool',
					'description'      => 'Mirrors a datamachine-publishing category projection.',
					'class'            => 'NonExistentClass',
					'method'           => 'handle_tool_call',
					'parameters'       => array(),
					'modes'            => array( 'pipeline' ),
					'ability'          => 'datamachine/test-publish',
					'ability_category' => 'datamachine-publishing',
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode' => ToolPolicyResolver::MODE_PIPELINE,
			)
		);

		$this->assertArrayNotHasKey( 'test_publishing_ability_tool', $tools, 'datamachine-publishing category tool is excluded by default.' );
	}

	public function test_pipeline_excludes_write_tools_but_keeps_adjacent_and_disposition_tools(): void {
		// Adjacent upsert handler (next step) — produces the upsert_event tool.
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['__handler_tools_test_upsert_event'] = array(
					'_handler_callable' => static function () {
						return array(
							'upsert_event' => array(
								'description' => 'Upsert event handler tool.',
								'handler'     => 'upsert_event',
								'parameters'  => array(),
							),
						);
					},
					'handler'           => 'upsert_event',
					'modes'             => array( 'pipeline' ),
				);

				// Cross-cutting fetch disposition tools (previous step), matched by handler type.
				$tools['__handler_tools_test_fetch_dispositions'] = array(
					'_handler_callable' => static function () {
						return array(
							'reject_source' => array(
								'description'  => 'Reject fetched source item.',
								'handler'      => 'test_fetch',
								'disposition'  => 'reject_source',
								'parameters'   => array(),
							),
							'defer_item'    => array(
								'description'  => 'Defer fetched source item.',
								'handler'      => 'test_fetch',
								'disposition'  => 'defer_item',
								'parameters'   => array(),
							),
						);
					},
					'handler_types'     => array( 'fetch' ),
					'modes'             => array( 'pipeline' ),
				);

				// Generic content-writing tool that must NOT leak into the step.
				$tools['test_generic_publish'] = array(
					'label'                    => 'Generic Publish',
					'description'              => 'Free-form publish tool.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'pipeline' ),
					'requires_pipeline_opt_in' => true,
				);
				return $tools;
			}
		);

		$this->registerHandlersFilter(
			static function () {
				return array( 'test_fetch' => array( 'type' => 'fetch' ) );
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'previous_step_config' => array(
					'flow_step_id'    => 'flow_fetch_1',
					'step_type'       => 'fetch',
					'handler_slugs'   => array( 'test_fetch' ),
					'handler_configs' => array( 'test_fetch' => array() ),
				),
				'next_step_config'     => array(
					'flow_step_id'    => 'flow_upsert_1',
					'step_type'       => 'upsert',
					'handler_slugs'   => array( 'upsert_event' ),
					'handler_configs' => array( 'upsert_event' => array() ),
				),
			)
		);

		// Generic publish tool excluded.
		$this->assertArrayNotHasKey( 'test_generic_publish', $tools, 'Generic content-writing tool is excluded.' );

		// Adjacent upsert handler tool preserved (flow plumbing).
		$this->assertArrayHasKey( 'upsert_event', $tools, 'Adjacent upsert_event handler tool is preserved.' );

		// Fetch disposition tools preserved (flow plumbing).
		$this->assertArrayHasKey( 'reject_source', $tools, 'reject_source disposition tool is preserved.' );
		$this->assertArrayHasKey( 'defer_item', $tools, 'defer_item disposition tool is preserved.' );
	}

	// ============================================
	// REQUIREMENT 2: adjacent publish handler preserved (no regression)
	// ============================================

	public function test_pipeline_keeps_adjacent_publish_handler_tool(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				// Mirrors the real wordpress_publish handler-tool registration
				// (Core/Steps/Publish/Handlers/WordPress/WordPress.php), which
				// uses the identical _handler_callable path.
				$tools['__handler_tools_test_wp_publish'] = array(
					'_handler_callable' => static function () {
						return array(
							'test_wp_publish' => array(
								'class'                   => 'NonExistentClass',
								'method'                  => 'handle_tool_call',
								'client_context_bindings' => array( 'job_id' ),
								'handler'                 => 'test_wp_publish',
								'description'             => 'Create WordPress posts.',
								'parameters'              => array(),
							),
						);
					},
					'handler'           => 'test_wp_publish',
					'modes'             => array( 'pipeline' ),
				);

				// A gated generic tool that must remain excluded even when a real
				// publish handler is adjacent.
				$tools['test_generic_publish'] = array(
					'label'                    => 'Generic Publish',
					'description'              => 'Free-form publish tool.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'pipeline' ),
					'requires_pipeline_opt_in' => true,
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode'             => ToolPolicyResolver::MODE_PIPELINE,
				'next_step_config' => array(
					'flow_step_id'    => 'flow_publish_1',
					'step_type'       => 'publish',
					'handler_slugs'   => array( 'test_wp_publish' ),
					'handler_configs' => array( 'test_wp_publish' => array() ),
				),
			)
		);

		$this->assertArrayHasKey( 'test_wp_publish', $tools, 'Adjacent publish handler tool is preserved (flow plumbing).' );
		$this->assertArrayNotHasKey( 'test_generic_publish', $tools, 'Generic publish tool still excluded when a publish handler is adjacent.' );
	}

	// ============================================
	// REQUIREMENT 3: explicit opt-in restores gated tools
	// ============================================

	public function test_pipeline_explicit_allow_restores_flagged_tool(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_gated_publish'] = array(
					'label'                    => 'Test Gated Publish',
					'description'              => 'Opt-in publish tool.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'pipeline' ),
					'requires_pipeline_opt_in' => true,
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'allow_only'           => array( 'test_gated_publish' ),
				'allow_only_explicit'  => true,
			)
		);

		$this->assertArrayHasKey( 'test_gated_publish', $tools, 'Explicit enabled_tools restores the gated tool.' );
	}

	public function test_pipeline_explicit_allow_restores_category_tool(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_publishing_ability_tool'] = array(
					'label'            => 'Test Publishing Ability Tool',
					'description'      => 'Opt-in publishing tool.',
					'class'            => 'NonExistentClass',
					'method'           => 'handle_tool_call',
					'parameters'       => array(),
					'modes'            => array( 'pipeline' ),
					'ability'          => 'datamachine/test-publish',
					'ability_category' => 'datamachine-publishing',
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'allow_only'           => array( 'test_publishing_ability_tool' ),
				'allow_only_explicit'  => true,
			)
		);

		$this->assertArrayHasKey( 'test_publishing_ability_tool', $tools, 'Explicit enabled_tools restores the publishing-category tool.' );
	}

	public function test_pipeline_allow_mode_tool_policy_restores_flagged_tool(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_gated_publish'] = array(
					'label'                    => 'Test Gated Publish',
					'description'              => 'Opt-in publish tool.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'pipeline' ),
					'requires_pipeline_opt_in' => true,
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode'        => ToolPolicyResolver::MODE_PIPELINE,
				'tool_policy' => array(
					'mode'  => 'allow',
					'tools' => array( 'test_gated_publish' ),
				),
			)
		);

		$this->assertArrayHasKey( 'test_gated_publish', $tools, 'Allow-mode tool_policy restores the gated tool.' );
	}

	// ============================================
	// NON-REGRESSION: chat/system modes unaffected
	// ============================================

	public function test_chat_mode_not_gated(): void {
		$this->registerToolsFilter(
			function ( array $tools ): array {
				$tools['test_gated_publish'] = array(
					'label'                    => 'Test Gated Publish',
					'description'              => 'Available in chat.',
					'class'                    => 'NonExistentClass',
					'method'                   => 'handle_tool_call',
					'parameters'               => array(),
					'modes'                    => array( 'chat' ),
					'requires_pipeline_opt_in' => true,
				);
				return $tools;
			}
		);

		$tools = $this->resolver->resolve(
			array(
				'mode' => ToolPolicyResolver::MODE_CHAT,
			)
		);

		$this->assertArrayHasKey( 'test_gated_publish', $tools, 'Pipeline opt-in flag does not affect chat mode.' );
	}
}
