<?php
/**
 * Admin families ability REST-runner tests.
 *
 * Covers the ability routes the admin consumes after the datamachine/v1
 * wrapper routes were deleted for #3456: jobs, logs, processed items,
 * settings, step types, handlers, and agent files.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use WP_REST_Request;
use WP_UnitTestCase;

class AdminFamiliesAbilityRestTest extends WP_UnitTestCase {

	private function act_as_admin(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function run_ability( string $slug, array $input = array() ): array {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'Ability run failed: ' . wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	private function run_ability_status( string $slug, array $input = array() ): int {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/datamachine/' . $slug . '/run' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ) ) );

		return rest_do_request( $request )->get_status();
	}

	public function test_rest_visible_jobs_list_and_delete_round_trip(): void {
		$this->act_as_admin();

		$jobs = $this->run_ability(
			'get-jobs',
			array(
				'orderby'       => 'job_id',
				'order'         => 'DESC',
				'per_page'      => 50,
				'offset'        => 0,
				'hide_children' => true,
			)
		);
		$this->assertTrue( $jobs['success'] );
		$this->assertArrayHasKey( 'jobs', $jobs );
		$this->assertArrayHasKey( 'total', $jobs );
		$this->assertArrayHasKey( 'per_page', $jobs );
		$this->assertArrayHasKey( 'offset', $jobs );
		$this->assertArrayHasKey( 'filters_applied', $jobs );

		// A missing job_id resolves through the ability as an empty result;
		// the wrapper's 404 mapping was retired with the route.
		$missing = $this->run_ability( 'get-jobs', array( 'job_id' => 999999 ) );
		$this->assertTrue( $missing['success'] );
		$this->assertSame( array(), $missing['jobs'] );

		$deleted = $this->run_ability( 'delete-jobs', array( 'type' => 'all' ) );
		$this->assertTrue( $deleted['success'] );
		$this->assertArrayHasKey( 'deleted_count', $deleted );
		$this->assertArrayHasKey( 'processed_items_cleaned', $deleted );
	}

	public function test_rest_visible_clear_processed_items_round_trip(): void {
		$this->act_as_admin();

		$created = $this->run_ability( 'create-pipeline', array( 'pipeline_name' => 'Admin Families Processed' ) );
		$this->assertNotEmpty( $created['pipeline_id'] );

		$cleared = $this->run_ability(
			'clear-processed-items',
			array(
				'clear_type' => 'pipeline',
				'target_id'  => (int) $created['pipeline_id'],
			)
		);
		$this->assertTrue( $cleared['success'] );
		$this->assertArrayHasKey( 'message', $cleared );
	}

	public function test_rest_visible_logs_read_metadata_and_clear(): void {
		$this->act_as_admin();

		$logs = $this->run_ability( 'read-logs', array( 'per_page' => 10, 'page' => 1 ) );
		$this->assertTrue( $logs['success'] );
		$this->assertArrayHasKey( 'items', $logs );
		$this->assertArrayHasKey( 'total', $logs );
		$this->assertArrayHasKey( 'page', $logs );
		$this->assertArrayHasKey( 'pages', $logs );

		$metadata = $this->run_ability( 'get-log-metadata' );
		$this->assertTrue( $metadata['success'] );
		$this->assertArrayHasKey( 'total_entries', $metadata );
		$this->assertArrayHasKey( 'level_counts', $metadata );

		$cleared = $this->run_ability( 'clear-logs' );
		$this->assertTrue( $cleared['success'] );
		$this->assertArrayHasKey( 'deleted', $cleared );
	}

	public function test_rest_visible_settings_read_update_and_ping_secret(): void {
		$this->act_as_admin();

		$settings = $this->run_ability( 'get-settings' );
		$this->assertTrue( $settings['success'] );
		$this->assertArrayHasKey( 'settings', $settings );
		$this->assertArrayHasKey( 'defaults', $settings );
		$this->assertArrayHasKey( 'global_tools', $settings );

		$updated = $this->run_ability(
			'update-settings',
			array( 'jobs_per_page' => 25 )
		);
		$this->assertTrue( $updated['success'] );
		$this->assertSame( 25, (int) $this->run_ability( 'get-settings' )['settings']['jobs_per_page'] );

		$secret = $this->run_ability( 'generate-ping-secret' );
		$this->assertTrue( $secret['success'] );
		$this->assertNotEmpty( $secret['secret'] );
		$this->assertSame( 32, strlen( (string) $secret['secret'] ) );
	}

	public function test_rest_visible_scheduling_intervals_tool_config_and_handler_defaults(): void {
		$this->act_as_admin();

		$intervals = $this->run_ability( 'get-scheduling-intervals' );
		$this->assertTrue( $intervals['success'] );
		$this->assertNotEmpty( $intervals['intervals'] );
		$this->assertArrayHasKey( 'value', $intervals['intervals'][0] );
		$this->assertArrayHasKey( 'label', $intervals['intervals'][0] );

		$defaults = $this->run_ability( 'get-handler-defaults' );
		$this->assertTrue( $defaults['success'] );
		$this->assertArrayHasKey( 'defaults', $defaults );

		// Unknown tool surfaces as 404 through the core runner.
		$this->assertSame( 404, $this->run_ability_status( 'get-tool-config', array( 'tool_id' => 'nonexistent_tool' ) ) );
	}

	public function test_rest_visible_step_types_round_trip(): void {
		$this->act_as_admin();

		$step_types = $this->run_ability( 'get-step-types' );
		$this->assertTrue( $step_types['success'] );
		$this->assertNotEmpty( $step_types['step_types'] );
		$this->assertArrayHasKey( 'ai', $step_types['step_types'] );
		$this->assertSame( count( $step_types['step_types'] ), $step_types['count'] );
	}

	public function test_rest_visible_handlers_list_enrichment_defaults(): void {
		$this->act_as_admin();

		$add_handler = static function ( array $handlers ): array {
			$handlers['admin_families_minimal'] = array(
				'type'  => 'fetch',
				'label' => 'Admin Families Minimal',
			);
			return $handlers;
		};
		add_filter( 'datamachine_handlers', $add_handler, 10, 2 );
		\DataMachine\Abilities\HandlerAbilities::clearCache();

		$handlers = $this->run_ability( 'get-handlers' );
		$this->assertTrue( $handlers['success'] );
		$this->assertArrayHasKey( 'admin_families_minimal', $handlers['handlers'] );

		$minimal = $handlers['handlers']['admin_families_minimal'];
		$this->assertSame( 'Admin Families Minimal', $minimal['label'] );
		// Enrichment defaults ported from the retired wrapper route.
		$this->assertFalse( $minimal['requires_auth'] );
		$this->assertNull( $minimal['auth_provider_key'] );

		remove_filter( 'datamachine_handlers', $add_handler, 10 );
		\DataMachine\Abilities\HandlerAbilities::clearCache();
	}

	public function test_rest_visible_handler_detail_and_unknown_handler(): void {
		$this->act_as_admin();

		$this->assertSame( 404, $this->run_ability_status( 'get-handler-detail', array( 'handler_slug' => 'admin_families_missing' ) ) );

		$add_handler = static function ( array $handlers ): array {
			$handlers['admin_families_detail'] = array(
				'type'        => 'fetch',
				'label'       => 'Admin Families Detail',
				'description' => 'Handler detail probe',
			);
			return $handlers;
		};
		add_filter( 'datamachine_handlers', $add_handler, 10, 2 );
		\DataMachine\Abilities\HandlerAbilities::clearCache();

		$detail = $this->run_ability( 'get-handler-detail', array( 'handler_slug' => 'admin_families_detail' ) );
		$this->assertTrue( $detail['success'] );
		$this->assertSame( 'admin_families_detail', $detail['slug'] );
		$this->assertSame( 'Admin Families Detail', $detail['info']['label'] );
		$this->assertArrayHasKey( 'settings', $detail );
		$this->assertNull( $detail['ai_tool'] );

		remove_filter( 'datamachine_handlers', $add_handler, 10 );
		\DataMachine\Abilities\HandlerAbilities::clearCache();
	}

	public function test_rest_visible_agent_files_round_trip(): void {
		$this->act_as_admin();

		$listing = $this->run_ability( 'list-agent-files' );
		$this->assertTrue( $listing['success'] );
		$this->assertArrayHasKey( 'files', $listing );
		$this->assertIsArray( $listing['files'] );

		$filename = 'admin-families-ability-test.md';
		$written  = $this->run_ability(
			'write-agent-file',
			array(
				'filename' => $filename,
				'content'  => 'Admin families test content',
			)
		);
		$this->assertTrue( $written['success'] );

		$read = $this->run_ability( 'get-agent-file', array( 'filename' => $filename ) );
		$this->assertTrue( $read['success'] );
		$this->assertSame( 'Admin families test content', $read['file']['content'] );

		$deleted = $this->run_ability( 'delete-agent-file', array( 'filename' => $filename ) );
		$this->assertTrue( $deleted['success'] );

		$this->assertSame( 404, $this->run_ability_status( 'get-agent-file', array( 'filename' => $filename ) ) );
	}
}
