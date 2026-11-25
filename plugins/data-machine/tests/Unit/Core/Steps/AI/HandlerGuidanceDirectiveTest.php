<?php
/**
 * Tests for handler-contributed AI guidance (HandlerGuidanceDirective).
 *
 * @package DataMachine\Tests\Unit\Core\Steps\AI
 */

namespace DataMachine\Tests\Unit\Core\Steps\AI;

use DataMachine\Abilities\HandlerAbilities;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\AI\Directives\HandlerGuidanceDirective;
use WP_UnitTestCase;

class HandlerGuidanceDirectiveTest extends WP_UnitTestCase {

	/**
	 * Handler config captured by the callable guidance.
	 *
	 * @var array|null
	 */
	private ?array $captured_config = null;

	/**
	 * Registered test filter.
	 *
	 * @var callable|null
	 */
	private $handlers_filter = null;

	public function set_up(): void {
		parent::set_up();
		$this->captured_config = null;

		$this->handlers_filter = function ( $handlers, $step_type = null ) {
			if ( null !== $step_type && 'fetch' !== $step_type ) {
				return $handlers;
			}

			$handlers['guided_source'] = array(
				'type'  => 'fetch',
				'label' => 'Guided Source',
				'meta'  => array(
					'ai_guidance' => 'Read the primary post first, then context posts.',
				),
			);

			$handlers['callable_source'] = array(
				'type'  => 'fetch',
				'label' => 'Callable Source',
				'meta'  => array(
					'ai_guidance' => function ( array $handler_config ): string {
						$this->captured_config = $handler_config;
						return 'Account: ' . ( $handler_config['account'] ?? '' );
					},
				),
			);

			$handlers['plain_source'] = array(
				'type'  => 'fetch',
				'label' => 'Plain Source',
				'meta'  => array(),
			);

			return $handlers;
		};

		add_filter( 'datamachine_handlers', $this->handlers_filter, 99, 2 );
		HandlerAbilities::clearCache();
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_handlers', $this->handlers_filter, 99 );
		HandlerAbilities::clearCache();
		parent::tear_down();
	}

	private function payload( string $handler_slug, array $handler_config = array(), ?array $data = null ): array {
		$flow_config = array(
			'1_fetch_1' => array(
				'step_type'       => 'fetch',
				'execution_order' => 0,
				'handler_slugs'   => array( $handler_slug ),
				'handler_configs' => array( $handler_slug => $handler_config ),
			),
			'1_ai_1'    => array(
				'step_type'       => 'ai',
				'execution_order' => 1,
			),
		);

		return array(
			'job_id'       => 0,
			'flow_step_id' => '1_ai_1',
			'engine'       => new EngineData( array( 'flow_config' => $flow_config ) ),
			'data'         => $data ?? array(
				array( 'data' => array( 'body' => 'item one' ) ),
				array( 'data' => array( 'body' => 'item two' ) ),
				array( 'data' => array( 'body' => 'item three' ) ),
			),
		);
	}

	public function test_injects_string_guidance_once_across_multiple_items(): void {
		$outputs = HandlerGuidanceDirective::get_outputs( 'test', array(), 'ai_1', $this->payload( 'guided_source' ) );

		$this->assertCount( 1, $outputs );
		$this->assertSame( 'system_text', $outputs[0]['type'] );
		$this->assertStringContainsString( 'Guided Source', $outputs[0]['content'] );
		$this->assertStringContainsString( 'Read the primary post first, then context posts.', $outputs[0]['content'] );
	}

	public function test_callable_guidance_receives_handler_config(): void {
		$outputs = HandlerGuidanceDirective::get_outputs(
			'test',
			array(),
			'ai_1',
			$this->payload( 'callable_source', array( 'account' => 'example_venue' ) )
		);

		$this->assertSame( array( 'account' => 'example_venue' ), $this->captured_config );
		$this->assertCount( 1, $outputs );
		$this->assertStringContainsString( 'Account: example_venue', $outputs[0]['content'] );
	}

	public function test_no_output_when_handler_declares_no_guidance(): void {
		$outputs = HandlerGuidanceDirective::get_outputs( 'test', array(), 'ai_1', $this->payload( 'plain_source' ) );

		$this->assertSame( array(), $outputs );
	}

	public function test_no_output_without_input_packets(): void {
		$outputs = HandlerGuidanceDirective::get_outputs( 'test', array(), 'ai_1', $this->payload( 'guided_source', array(), array() ) );

		$this->assertSame( array(), $outputs );
	}

	public function test_no_output_when_no_source_step_precedes_ai_step(): void {
		$payload           = $this->payload( 'guided_source' );
		$payload['engine'] = new EngineData(
			array(
				'flow_config' => array(
					'1_ai_1' => array(
						'step_type'       => 'ai',
						'execution_order' => 0,
					),
				),
			)
		);

		$this->assertSame( array(), HandlerGuidanceDirective::get_outputs( 'test', array(), 'ai_1', $payload ) );
	}

	public function test_directive_registered_after_pipeline_system_prompt(): void {
		$directives = apply_filters( 'datamachine_directives', array() );
		$priorities = array();
		foreach ( $directives as $directive ) {
			$priorities[ $directive['class'] ] = $directive['priority'];
		}

		$this->assertArrayHasKey( HandlerGuidanceDirective::class, $priorities );
		$this->assertGreaterThan(
			$priorities['DataMachine\\Core\\Steps\\AI\\Directives\\PipelineSystemPromptDirective'],
			$priorities[ HandlerGuidanceDirective::class ]
		);
	}
}
