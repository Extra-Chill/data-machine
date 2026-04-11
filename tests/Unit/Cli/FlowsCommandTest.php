<?php
/**
 * Flows Command Tests
 *
 * Tests the flow operations that the CLI wraps, using the Abilities API directly.
 * The CLI layer (FlowsCommand) depends on WP-CLI runtime utilities (Formatter,
 * pick_fields) that are not available in the PHPUnit test environment.
 * These tests verify the underlying ability behavior instead.
 *
 * @package DataMachine\Tests\Unit\Cli
 */

namespace DataMachine\Tests\Unit\Cli;

use WP_UnitTestCase;

class FlowsCommandTest extends WP_UnitTestCase {

	private int $test_pipeline_id;
	private int $test_flow_id;
	private int $admin_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		$flow_ability     = wp_get_ability( 'datamachine/create-flow' );

		$pipeline = $pipeline_ability->execute( array( 'pipeline_name' => 'Test Pipeline for CLI' ) );
		$this->test_pipeline_id = $pipeline['pipeline_id'];

		$flow = $flow_ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_name'  => 'Test Flow for CLI',
			)
		);
		$this->test_flow_id = $flow['flow_id'];
	}

	public function test_flows_command_class_exists(): void {
		$this->assertTrue(
			class_exists( \DataMachine\Cli\Commands\Flows\FlowsCommand::class ),
			'FlowsCommand class should be autoloadable'
		);
	}

	public function test_list_all_flows(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'flows', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThan( 0, $result['total'] );
	}

	public function test_list_by_pipeline(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, count( $result['flows'] ) );

		foreach ( $result['flows'] as $flow ) {
			$this->assertEquals( $this->test_pipeline_id, $flow['pipeline_id'] );
		}
	}

	public function test_list_flows_returns_expected_fields(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['flows'] );

		$flow = $result['flows'][0];
		$this->assertArrayHasKey( 'flow_id', $flow );
		$this->assertArrayHasKey( 'flow_name', $flow );
		$this->assertArrayHasKey( 'pipeline_id', $flow );
	}

	public function test_json_format_structure(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'flows', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'per_page', $result );
		$this->assertArrayHasKey( 'offset', $result );
	}

	public function test_pagination(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 1,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertLessThanOrEqual( 1, count( $result['flows'] ) );
		$this->assertEquals( 1, $result['per_page'] );
		$this->assertEquals( 0, $result['offset'] );
	}

	public function test_permission_denied_for_unauthenticated(): void {
		wp_set_current_user( 0 );
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability( 'datamachine/get-flows' );
		$result  = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_per_page_high_value_accepted(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => null,
				'per_page'    => 150,
				'offset'      => 0,
			)
		);

		// Schema has minimum:0 but no maximum — large values are accepted.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_get_flow_by_id(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'per_page'    => 20,
				'offset'      => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$found = false;
		foreach ( $result['flows'] as $flow ) {
			$fid = $flow['flow_id'] ?? $flow['id'] ?? null;
			if ( (int) $fid === $this->test_flow_id ) {
				$found = true;
				$this->assertEquals( 'Test Flow for CLI', $flow['name'] ?? $flow['flow_name'] ?? '' );
				break;
			}
		}
		$this->assertTrue( $found, 'Flow should be found in pipeline results' );
	}

	public function test_get_nonexistent_flow(): void {
		$ability = wp_get_ability( 'datamachine/get-flows' );

		$result = $ability->execute(
			array(
				'flow_id'  => 999999,
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['flows'] );
	}

	public function test_create_flow(): void {
		$ability = wp_get_ability( 'datamachine/create-flow' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_name'   => 'CLI Created Flow',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'flow_id', $result );
		$this->assertIsInt( $result['flow_id'] );
	}

	public function test_delete_flow(): void {
		// Create a flow to delete
		$create_ability = wp_get_ability( 'datamachine/create-flow' );
		$created = $create_ability->execute(
			array(
				'pipeline_id' => $this->test_pipeline_id,
				'flow_name'   => 'Flow to Delete',
			)
		);

		$delete_ability = wp_get_ability( 'datamachine/delete-flow' );
		if ( ! $delete_ability ) {
			$this->markTestSkipped( 'datamachine/delete-flow ability not registered' );
			return;
		}

		$result = $delete_ability->execute( array( 'flow_id' => $created['flow_id'] ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}
}
