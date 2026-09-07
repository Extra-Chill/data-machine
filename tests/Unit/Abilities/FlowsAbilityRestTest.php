<?php
/**
 * Flows ability REST-runner tests.
 *
 * Covers the ability routes the admin consumes after the datamachine/v1
 * wrapper routes were deleted for #3456: flow CRUD, flow memory files,
 * flow steps, and the prompt queue.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use WP_REST_Request;
use WP_UnitTestCase;

class FlowsAbilityRestTest extends WP_UnitTestCase {

	private function act_as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function run_ability( string $slug, array $input ): array {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Ability run failed: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Create a pipeline with one AI step and return its default flow plus
	 * the AI step's flow_step_id.
	 */
	private function create_flow_with_ai_step(): array {
		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Ability Flows Pipeline' ) );
		$this->assertNotEmpty( $created['pipeline_id'] );
		$pipeline_id = (int) $created['pipeline_id'];

		// Add the step before creating the flow so the flow config is
		// materialized against it (create-pipeline only creates a default
		// flow when flow_config/workflow input is supplied).
		$added = $this->run_ability(
			'add-pipeline-step',
			array(
				'pipeline_id' => $pipeline_id,
				'step_type'   => 'ai',
			)
		);
		$this->assertNotEmpty( $added['pipeline_step_id'] );

		$flow = $this->run_ability(
			'create-flow',
			array(
				'pipeline_id' => $pipeline_id,
				'flow_name'   => 'Ability Flows Flow',
			)
		);
		$this->assertNotEmpty( $flow['flow_id'] );
		$flow_id = (int) $flow['flow_id'];

		$flow_step_id = apply_filters( 'datamachine_generate_flow_step_id', '', $added['pipeline_step_id'], $flow_id );
		$this->assertNotEmpty( $flow_step_id );

		return array(
			'pipeline_id'      => $pipeline_id,
			'flow_id'          => $flow_id,
			'flow_step_id'     => (string) $flow_step_id,
			'pipeline_step_id' => (string) $added['pipeline_step_id'],
		);
	}

	public function test_rest_visible_flow_crud_round_trip(): void {
		$this->act_as_admin();

		$context = $this->create_flow_with_ai_step();

		$single = $this->run_ability( 'get-flows', array( 'flow_id' => $context['flow_id'] ) );
		$this->assertTrue( $single['success'] );
		$this->assertSame( $context['flow_id'], (int) $single['flows'][0]['flow_id'] );

		$updated = $this->run_ability(
			'update-flow',
			array(
				'flow_id'   => $context['flow_id'],
				'flow_name' => 'Ability Flows Renamed',
			)
		);
		$this->assertTrue( $updated['success'] );
		$this->assertSame( 'Ability Flows Renamed', $updated['flow_name'] );

		$deleted = $this->run_ability( 'delete-flow', array( 'flow_id' => $context['flow_id'] ) );
		$this->assertTrue( $deleted['success'] );

		$gone = $this->run_ability( 'get-flows', array( 'flow_id' => $context['flow_id'] ) );
		$this->assertSame( array(), $gone['flows'] );
	}

	public function test_rest_visible_flow_steps_read_and_update(): void {
		$this->act_as_admin();

		$context = $this->create_flow_with_ai_step();

		$steps = $this->run_ability( 'get-flow-steps', array( 'flow_id' => $context['flow_id'] ) );
		$this->assertTrue( $steps['success'] );
		$this->assertContains( $context['flow_step_id'], array_column( $steps['steps'], 'flow_step_id' ) );

		$updated = $this->run_ability(
			'update-flow-step',
			array(
				'flow_step_id' => $context['flow_step_id'],
				'user_message' => 'Ability user message',
			)
		);
		$this->assertTrue( $updated['success'] );

		$reread = $this->run_ability( 'get-flow-steps', array( 'flow_step_id' => $context['flow_step_id'] ) );
		$this->assertTrue( $reread['success'] );
		$this->assertSame( 'Ability user message', $reread['steps'][0]['user_message'] );
	}

	public function test_rest_visible_queue_round_trip(): void {
		$this->act_as_admin();

		$context = $this->create_flow_with_ai_step();
		$queue   = array(
			'flow_id'      => $context['flow_id'],
			'flow_step_id' => $context['flow_step_id'],
		);

		$added = $this->run_ability(
			'queue-add',
			array_merge( $queue, array( 'prompt' => 'Ability queue prompt' ) )
		);
		$this->assertTrue( $added['success'] );
		$this->assertSame( 1, $added['queue_length'] );

		$listed = $this->run_ability( 'queue-list', $queue );
		$this->assertTrue( $listed['success'] );
		$this->assertSame( 1, $listed['count'] );
		$this->assertSame( 'Ability queue prompt', $listed['queue'][0]['prompt'] );

		$mode = $this->run_ability(
			'queue-mode',
			array_merge( $queue, array( 'mode' => 'loop' ) )
		);
		$this->assertTrue( $mode['success'] );
		$this->assertSame( 'loop', $mode['queue_mode'] );

		$removed = $this->run_ability(
			'queue-remove',
			array_merge( $queue, array( 'index' => 0 ) )
		);
		$this->assertTrue( $removed['success'] );
		$this->assertSame( 0, $removed['queue_length'] );
	}

	public function test_rest_visible_flow_memory_files_round_trip(): void {
		$this->act_as_admin();

		$context = $this->create_flow_with_ai_step();

		$empty = $this->run_ability( 'get-flow-memory-files', array( 'flow_id' => $context['flow_id'] ) );
		$this->assertSame( array(), $empty['memory_files'] );

		$updated = $this->run_ability(
			'update-flow-memory-files',
			array(
				'flow_id'      => $context['flow_id'],
				'memory_files' => array( 'notes.md', 'style.md' ),
			)
		);

		$this->assertTrue( $updated['success'] );
		$this->assertSame( array( 'notes.md', 'style.md' ), $updated['memory_files'] );
		$this->assertSame(
			array( 'notes.md', 'style.md' ),
			$this->run_ability( 'get-flow-memory-files', array( 'flow_id' => $context['flow_id'] ) )['memory_files']
		);
	}

	public function test_rest_visible_flow_memory_files_update_sanitizes_filenames(): void {
		$this->act_as_admin();

		$context = $this->create_flow_with_ai_step();

		$updated = $this->run_ability(
			'update-flow-memory-files',
			array(
				'flow_id'      => $context['flow_id'],
				'memory_files' => array( '../evil.md', '', 'kept.md' ),
			)
		);

		$this->assertTrue( $updated['success'] );
		$this->assertSame( array( 'evil.md', 'kept.md' ), $updated['memory_files'] );
	}

	public function test_rest_visible_flow_memory_files_update_rejects_missing_flow(): void {
		$this->act_as_admin();

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/update-flow-memory-files/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'input' => array(
						'flow_id'      => 999999,
						'memory_files' => array( 'notes.md' ),
					),
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_visible_flow_memory_files_rejects_invalid_input(): void {
		$this->act_as_admin();

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/update-flow-memory-files/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => array( 'flow_id' => 1 ) ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
