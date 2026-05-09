<?php
/**
 * AgentBundler::import() integration tests.
 *
 * Exercises the post-claim rollback path and the upgrade-against-local-modified path that motivated
 * issue #1801:
 *
 * 1. Silent partial failure — a fault injected after agent_id has been claimed must not leave a
 *    half-installed agent behind, and must not return success.
 * 2. Misleading "already exists" on upgrade — calling import() with `is_upgrade => true` against an
 *    agent whose live pipeline/flow have been edited (`local_modified`) must succeed and surface
 *    conflicts instead of erroring on the slug-collision guard.
 * 3. Bundle artifact registry cleanup — deleting an agent must clear any rows in the
 *    `datamachine_bundle_artifacts` table for that agent so subsequent installs are classified as
 *    fresh installs, not stale upgrades.
 *
 * @package DataMachine\Tests\Unit\Core\Agents
 */

namespace DataMachine\Tests\Unit\Core\Agents;

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store_Capabilities;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;
use DataMachine\Core\Database\Flows\Flows as FlowsRepository;
use DataMachine\Core\Database\Jobs\Jobs as JobsRepository;
use DataMachine\Core\Database\Pipelines\Pipelines as PipelinesRepository;
use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Engine\AI\Tools\Global\AgentDailyMemory;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use DataMachine\Engine\Bundle\BundleSchema;
use WP_UnitTestCase;

final class DailyMemoryImportFakeStore implements WP_Agent_Memory_Store {

	/** @var array<string,string> */
	public array $files = array();

	/** @var array<string,WP_Agent_Memory_Scope> */
	private array $scopes = array();

	public function capabilities(): WP_Agent_Memory_Store_Capabilities {
		return WP_Agent_Memory_Store_Capabilities::none();
	}

	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result {
		unset( $metadata_fields );
		if ( ! array_key_exists( $scope->key(), $this->files ) ) {
			return WP_Agent_Memory_Read_Result::not_found();
		}

		$content = $this->files[ $scope->key() ];
		return new WP_Agent_Memory_Read_Result( true, $content, sha1( $content ), strlen( $content ), 123 );
	}

	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result {
		unset( $if_match, $metadata );
		$this->files[ $scope->key() ]  = $content;
		$this->scopes[ $scope->key() ] = $scope;

		return WP_Agent_Memory_Write_Result::ok( sha1( $content ), strlen( $content ) );
	}

	public function exists( WP_Agent_Memory_Scope $scope ): bool {
		return array_key_exists( $scope->key(), $this->files );
	}

	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result {
		unset( $this->files[ $scope->key() ], $this->scopes[ $scope->key() ] );
		return WP_Agent_Memory_Write_Result::ok( '', 0 );
	}

	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $scope_query, $query );
		return array();
	}

	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array {
		unset( $query );
		$entries = array();

		foreach ( $this->files as $key => $content ) {
			$scope = $this->scopes[ $key ] ?? null;
			if ( ! $scope instanceof WP_Agent_Memory_Scope ) {
				continue;
			}
			if ( $scope->layer !== $scope_query->layer || $scope->user_id !== $scope_query->user_id || $scope->agent_id !== $scope_query->agent_id ) {
				continue;
			}
			if ( 0 !== strpos( $scope->filename, $prefix . '/' ) ) {
				continue;
			}

			$entries[] = new WP_Agent_Memory_List_Entry( $scope->filename, $scope->layer, strlen( $content ), 123 );
		}

		return $entries;
	}
}

class AgentBundlerImportTest extends WP_UnitTestCase {

	private AgentBundler $bundler;
	private AgentsRepository $agents_repo;
	private PipelinesRepository $pipelines_repo;
	private FlowsRepository $flows_repo;
	private int $owner_id;
	private $memory_store_filter = null;

	public function set_up(): void {
		parent::set_up();

		AgentsRepository::create_table();
		PipelinesRepository::create_table();
		FlowsRepository::create_table();
		JobsRepository::create_table();
		InstalledBundleArtifacts::create_table();

		$this->bundler        = new AgentBundler();
		$this->agents_repo    = new AgentsRepository();
		$this->pipelines_repo = new PipelinesRepository();
		$this->flows_repo     = new FlowsRepository();

		$this->owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->owner_id );
	}

	public function tear_down(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->base_prefix}datamachine_agents" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_pipelines" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_flows" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_bundle_artifacts" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		remove_all_actions( 'datamachine_bundle_import_pre_commit' );
		remove_all_actions( 'datamachine_bundle_import_post_claim_started' );
		if ( null !== $this->memory_store_filter ) {
			remove_filter( 'agents_api_memory_store', $this->memory_store_filter, 10 );
			$this->memory_store_filter = null;
		}
		PermissionHelper::clear_agent_context();

		parent::tear_down();
	}

	/**
	 * Build a minimal-but-realistic portable bundle for tests. Mirrors the shape produced by
	 * AgentBundler::export() against a single-pipeline, single-flow agent.
	 */
	private function fixture_bundle( string $slug = 'wc-static-site-agent' ): array {
		return array(
			'bundle_version' => '1',
			'bundle_slug'    => $slug,
			'agent'          => array(
				'agent_slug'   => $slug,
				'agent_name'   => 'Static Site Agent',
				'agent_config' => array(),
			),
			'pipelines'      => array(
				array(
					'original_id'     => 1,
					'pipeline_name'   => 'Static Site Pipeline',
					'portable_slug'   => 'static-site-pipeline',
					'pipeline_config' => array(
						'1_step-uuid' => array(
							'pipeline_step_id' => '1_step-uuid',
							'execution_order'  => 0,
							'step_type'        => 'fetch',
						),
					),
				),
			),
			'flows'          => array(
				array(
					'original_pipeline_id' => 1,
					'flow_name'            => 'Static Site Flow',
					'portable_slug'        => 'static-site-flow',
					'flow_config'          => array(
						'1_step-uuid_1' => array(
							'pipeline_step_id' => '1_step-uuid',
							'pipeline_id'      => 1,
							'flow_id'          => 1,
							'flow_step_id'     => '1_step-uuid_1',
							'execution_order'  => 0,
						),
					),
					'scheduling_config'    => array( 'interval' => 'manual' ),
				),
			),
		);
	}

	public function test_import_honors_scheduled_bundle_flows_on_create(): void {
		$bundle = $this->fixture_bundle( 'scheduled-agent' );
		$bundle['flows'][0]['scheduling_config'] = array(
			'enabled'  => true,
			'interval' => 'daily',
			'max_items' => array(
				'mcp' => 5,
			),
		);

		$result = $this->bundler->import( $bundle, null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Scheduled bundle import succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'scheduled-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );

		$this->assertSame( 'daily', $flow['scheduling_config']['interval'] ?? null, 'Importer preserves the bundle interval.' );
		$this->assertTrue( $flow['scheduling_config']['enabled'] ?? false, 'Importer keeps scheduled bundle flows enabled.' );
		$this->assertSame( array( 'mcp' => 5 ), $flow['scheduling_config']['max_items'] ?? null, 'Importer preserves bundle max item caps.' );
	}

	public function test_import_exposes_run_artifact_egress_policy_in_agent_and_flow_metadata(): void {
		$bundle                  = $this->fixture_bundle( 'artifact-policy-agent' );
		$bundle['run_artifacts'] = array(
			'daily_memory'          => array(
				'egress'               => array( 'bundle-file', 'pr-body', 'github-comment' ),
				'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
			),
			'completion_assertions' => array(
				'egress' => array( 'pr-body' ),
			),
			'transcript_summary'    => array(
				'egress' => array( 'artifact' ),
			),
			'unknown_future_source' => array(
				'egress' => array( 'pr-body' ),
			),
		);

		$result = $this->bundler->import( $bundle, null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Artifact policy bundle import succeeds.' );

		$expected = array(
			'completion_assertions' => array(
				'egress' => array( 'pr-body' ),
			),
			'daily_memory'          => array(
				'egress'               => array( 'bundle-file', 'pr-body' ),
				'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
			),
			'transcript_summary'    => array(
				'egress' => array( 'artifact' ),
			),
		);

		$agent    = $this->agents_repo->get_by_slug( 'artifact-policy-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );

		$this->assertSame( $expected, BundleSchema::normalize_run_artifact_egress_policy( $agent['agent_config']['datamachine_bundle']['run_artifacts'] ?? array() ), 'Agent bundle metadata exposes normalized run artifact policy.' );
		$this->assertSame( $expected, BundleSchema::normalize_run_artifact_egress_policy( $flow['scheduling_config']['run_artifacts'] ?? array() ), 'Flow runtime metadata exposes normalized run artifact policy.' );
		$this->assertSame( $expected, $result['summary']['run_artifacts'] ?? array(), 'Import summary exposes normalized run artifact policy.' );
	}

	public function test_run_flow_copies_run_artifact_egress_policy_to_job_engine_data(): void {
		$bundle                  = $this->fixture_bundle( 'artifact-runtime-agent' );
		$bundle['run_artifacts'] = array(
			'daily_memory' => array(
				'egress'               => array( 'bundle-file', 'pr-body' ),
				'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
			),
		);

		$result = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $result['success'], 'Artifact runtime policy bundle import succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'artifact-runtime-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );

		$run = ( new RunFlowAbility() )->execute( array( 'flow_id' => (int) $flow['flow_id'] ) );
		$this->assertTrue( (bool) $run['success'], 'Flow run initializes job runtime metadata.' );

		$engine_data = ( new JobsRepository() )->retrieve_engine_data( (int) $run['job_id'] );
		$expected    = array(
			'daily_memory' => array(
				'egress'               => array( 'bundle-file', 'pr-body' ),
				'bundle_relative_path' => 'memory/agent/daily/{yyyy}/{mm}/{dd}.md',
			),
		);

		$this->assertSame( $expected, $engine_data['run_artifact_egress_policy'] ?? array(), 'Job engine_data exposes run artifact egress policy.' );
		$this->assertSame( $expected, $engine_data['flow']['run_artifacts'] ?? array(), 'Flow runtime metadata exposes run artifact egress policy.' );
	}

	public function test_import_seeds_agent_daily_memory_into_runtime_store(): void {
		$store                     = new DailyMemoryImportFakeStore();
		$this->memory_store_filter = static fn( $default, WP_Agent_Memory_Scope $scope ) => $store;
		add_filter( 'agents_api_memory_store', $this->memory_store_filter, 10, 2 );

		$bundle = $this->fixture_bundle( 'daily-memory-agent' );
		$bundle['files']['daily/2026/05/09.md'] = "# Daily Memory: 2026-05-09\n\nImported bundle memory with alpha-sentinel.\n";
		$bundle_dir = sys_get_temp_dir() . '/datamachine-daily-memory-bundle-' . getmypid();
		$this->remove_tree( $bundle_dir );
		$this->assertTrue( $this->bundler->to_directory( $bundle, $bundle_dir ), 'Bundle directory write succeeds.' );
		$this->assertFileExists( $bundle_dir . '/memory/agent/daily/2026/05/09.md', 'Daily memory is represented under memory/agent/daily in the bundle directory.' );
		$bundle = $this->bundler->from_directory( $bundle_dir );
		$this->assertIsArray( $bundle, 'Bundle directory reads back into importable bundle data.' );

		$result = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->remove_tree( $bundle_dir );

		$this->assertTrue( (bool) $result['success'], 'Bundle import succeeds.' );
		$agent = $this->agents_repo->get_by_slug( 'daily-memory-agent' );
		$this->assertNotEmpty( $agent, 'Imported agent exists.' );

		PermissionHelper::set_agent_context( (int) $agent['agent_id'], $this->owner_id );
		$tool = new AgentDailyMemory();

		$read = $tool->handle_tool_call(
			array(
				'action' => 'read',
				'date'   => '2026-05-09',
			)
		);
		$this->assertTrue( (bool) ( $read['success'] ?? false ), 'Daily memory tool reads imported daily memory.' );
		$this->assertStringContainsString( 'alpha-sentinel', $read['data']['content'] ?? '' );

		$search = $tool->handle_tool_call(
			array(
				'action' => 'search',
				'query'  => 'alpha-sentinel',
			)
		);
		$this->assertTrue( (bool) ( $search['success'] ?? false ), 'Daily memory tool searches imported daily memory.' );
		$this->assertSame( 1, $search['data']['match_count'] ?? 0 );
	}

	private function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $path );
	}

	public function test_import_reenqueues_existing_scheduled_flow_when_action_is_missing(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler functions are required for schedule re-enqueue assertions.' );
		}

		$bundle = $this->fixture_bundle( 'scheduled-action-repair-agent' );
		$bundle['flows'][0]['scheduling_config'] = array(
			'enabled'  => true,
			'interval' => 'daily',
			'max_items' => array(
				'mcp' => 5,
			),
		);

		$first = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $first['success'], 'Initial scheduled import succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'scheduled-action-repair-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$flow_id  = (int) $flow['flow_id'];

		as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );
		$this->assertFalse( as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' ), 'Test setup removes the scheduled action while preserving flow row scheduling.' );

		$second = $this->bundler->import(
			$bundle,
			null,
			$this->owner_id,
			false,
			array( 'is_upgrade' => true )
		);

		$this->assertTrue( (bool) $second['success'], 'Upgrade import succeeds when only the scheduled action is missing.' );
		$this->assertNotFalse( as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' ), 'Importer re-creates the missing scheduled action for an enabled non-manual flow.' );
	}

	public function test_reconcile_runtime_replaces_local_modified_flow_queue_and_schedule(): void {
		$bundle = $this->fixture_bundle( 'runtime-reconcile-agent' );
		$bundle['flows'][0]['flow_config']['1_step-uuid_1'] = array_merge(
			$bundle['flows'][0]['flow_config']['1_step-uuid_1'],
			array(
				'step_type'          => 'fetch',
				'handler_slug'       => 'mcp',
				'handler_config'     => array(
					'max_items' => 5,
					'provider'  => 'mgs',
				),
				'handler_configs'    => array(
					'mcp' => array(
						'max_items' => 5,
						'provider'  => 'mgs',
					),
				),
				'config_patch_queue' => array(
					array( 'patch' => array( 'query' => 'initial' ) ),
				),
				'queue_mode'         => 'loop',
			)
		);
		$bundle['flows'][0]['scheduling_config'] = array(
			'enabled'  => true,
			'interval' => 'daily',
			'max_items' => array(
				'mcp' => 5,
			),
		);

		$first = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $first['success'], 'Initial install succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'runtime-reconcile-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$config   = $flow['flow_config'];
		$step_id  = array_key_first( $config );

		$config[ $step_id ]['config_patch_queue'] = array(
			array( 'patch' => array( 'query' => 'stale-a' ) ),
			array( 'patch' => array( 'query' => 'stale-b' ) ),
		);
		$config[ $step_id ]['queue_mode']         = 'drain';
		$config[ $step_id ]['handler_config']     = array(
			'max_items' => 1,
			'provider'  => 'mgs',
		);
		$config[ $step_id ]['handler_configs']    = array(
			'mcp' => array(
				'max_items' => 1,
				'provider'  => 'mgs',
			),
		);

		$this->flows_repo->update_flow(
			(int) $flow['flow_id'],
			array(
				'flow_config'       => $config,
				'scheduling_config' => array(
					'enabled'  => false,
					'interval' => 'manual',
					'max_items' => array(
						'mcp' => 1,
					),
				),
			)
		);

		$upgrade = $bundle;
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['handler_config']['max_items'] = 50;
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['handler_configs']['mcp']['max_items'] = 50;
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['config_patch_queue'] = array(
			array( 'patch' => array( 'query' => 'target-a' ) ),
			array( 'patch' => array( 'query' => 'target-b' ) ),
			array( 'patch' => array( 'query' => 'target-c' ) ),
		);
		$upgrade['flows'][0]['scheduling_config'] = array(
			'enabled'  => true,
			'interval' => 'every_5_minutes',
			'max_items' => array(
				'mcp' => 50,
			),
		);

		$second = $this->bundler->import(
			$upgrade,
			null,
			$this->owner_id,
			false,
			array(
				'is_upgrade'        => true,
				'reconcile_runtime' => true,
			)
		);

		$this->assertTrue( (bool) $second['success'], 'Runtime reconcile import succeeds against locally modified flow.' );
		$this->assertSame( array(), $second['summary']['conflicts'] ?? array(), 'Explicit runtime reconcile does not stage local-modified flow conflicts.' );

		$updated_flow   = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$updated_config = $updated_flow['flow_config'];
		$updated_step   = $updated_config[ $step_id ];

		$this->assertCount( 3, $updated_step['config_patch_queue'], 'Bundle seed queue replaces stale runtime queue.' );
		$this->assertSame( 'target-a', $updated_step['config_patch_queue'][0]['patch']['query'] ?? null, 'Reconciled queue uses target patch content.' );
		$this->assertSame( 'loop', $updated_step['queue_mode'] ?? null, 'Bundle seed queue mode replaces stale runtime mode.' );
		$this->assertSame( 50, $updated_step['handler_configs']['mcp']['max_items'] ?? null, 'Bundle handler bounds replace stale runtime bounds.' );
		$this->assertSame( 'every_5_minutes', $updated_flow['scheduling_config']['interval'] ?? null, 'Bundle schedule replaces stale runtime schedule.' );
		$this->assertTrue( $updated_flow['scheduling_config']['enabled'] ?? false, 'Bundle schedule enabled flag replaces stale runtime flag.' );
		$this->assertSame( array( 'mcp' => 50 ), $updated_flow['scheduling_config']['max_items'] ?? null, 'Bundle schedule max items replace stale runtime bounds.' );
	}

	/**
	 * The silent-partial-success regression in #1801: a failure after the agent row was claimed used
	 * to return `success: true` with a populated agent_id summary, while the row was rolled back at
	 * the SQLite layer. Now any post-claim throw must:
	 *
	 *  1. Return `success: false` with `error_code: install_post_claim_failure`.
	 *  2. Leave no agent row behind (manual rollback covers cases where the engine ignores ROLLBACK).
	 *  3. Leave no orphan pipeline / flow rows behind.
	 */
	public function test_post_claim_failure_rolls_back_and_reports_typed_error(): void {
		$bundle = $this->fixture_bundle();

		$fault_count = 0;
		add_action(
			'datamachine_bundle_import_pre_commit',
			static function () use ( &$fault_count ): void {
				++$fault_count;
				throw new \RuntimeException( 'simulated SQLite drop on commit' );
			}
		);

		$result = $this->bundler->import( $bundle, null, $this->owner_id );

		$this->assertSame( 1, $fault_count, 'Pre-commit hook fired once.' );
		$this->assertFalse( $result['success'], 'Import surfaces failure instead of silent partial success.' );
		$this->assertSame( 'install_post_claim_failure', $result['error_code'] ?? null );
		$this->assertStringContainsString( 'simulated SQLite drop on commit', (string) ( $result['error'] ?? '' ) );

		$this->assertNull(
			$this->agents_repo->get_by_slug( 'wc-static-site-agent' ),
			'No half-installed agent row remains after rollback.'
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$pipeline_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}datamachine_pipelines" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$flow_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}datamachine_flows" );

		$this->assertSame( 0, $pipeline_count, 'No orphan pipeline rows.' );
		$this->assertSame( 0, $flow_count, 'No orphan flow rows.' );
	}

	/**
	 * The "Agent slug already exists" misclassification in #1801: when an operator runs `agent
	 * upgrade` against a bundle whose live pipeline/flow have been edited (legitimate operator
	 * action), the importer used to short-circuit on the slug collision guard before the planner
	 * could surface conflicts. With `is_upgrade => true`, the importer must accept the existing
	 * row as the upgrade target and return `success: true` so the CLI can stage PendingActions.
	 */
	public function test_upgrade_against_existing_agent_does_not_error_on_slug_collision(): void {
		// First install — clean.
		$first = $this->bundler->import( $this->fixture_bundle(), null, $this->owner_id );
		$this->assertTrue( (bool) $first['success'], 'Initial install succeeds.' );

		// Edit the live pipeline so the next import would classify the artifact as `local_modified`.
		$existing_agent    = $this->agents_repo->get_by_slug( 'wc-static-site-agent' );
		$existing_pipeline = $this->pipelines_repo->get_by_portable_slug(
			(int) $existing_agent['agent_id'],
			'static-site-pipeline'
		);
		$this->pipelines_repo->update_pipeline(
			(int) $existing_pipeline['pipeline_id'],
			array( 'pipeline_name' => 'Edited Locally' )
		);

		// Without `is_upgrade`, the importer's portable-bundle path lets this through, but the
		// CLI upgrade entrypoint also passes `is_upgrade => true` to make the contract explicit and
		// keep `--slug` overrides from accidentally re-triggering the install collision check.
		$second = $this->bundler->import(
			$this->fixture_bundle(),
			null,
			$this->owner_id,
			false,
			array( 'is_upgrade' => true )
		);

		$this->assertTrue( (bool) $second['success'], 'Upgrade does not error on slug collision.' );
		$this->assertSame(
			(int) $existing_agent['agent_id'],
			(int) $second['summary']['agent_id'],
			'Upgrade reuses the existing agent_id.'
		);
	}

	/**
	 * Defensive registry cleanup: even though the importer does not currently write to
	 * `datamachine_bundle_artifacts`, extensions can. If an agent is deleted, any tracked rows for
	 * its agent_id must be wiped so the next install is classified as fresh, not as a stale upgrade.
	 */
	public function test_agent_delete_clears_bundle_artifact_registry(): void {
		InstalledBundleArtifacts::register();

		$store    = new InstalledBundleArtifacts();
		$agent_id = $this->agents_repo->create_if_missing( 'cleanup-target', 'Cleanup Target', $this->owner_id, array() );

		$manifest = new AgentBundleManifest(
			gmdate( 'c' ),
			'data-machine/test',
			'cleanup-target',
			'1',
			'',
			'',
			array(
				'slug'         => 'cleanup-target',
				'label'        => 'Cleanup Target',
				'description'  => '',
				'agent_config' => array(),
			),
			array(
				'memory'       => array(),
				'pipelines'    => array(),
				'flows'        => array(),
				'extensions'   => array(),
				'handler_auth' => 'refs',
			)
		);

		$store->record_install(
			$manifest,
			'pipeline',
			'static-site-pipeline',
			'pipelines/static-site-pipeline.json',
			array( 'pipeline_name' => 'Static Site Pipeline' ),
			$agent_id
		);

		$this->assertCount( 1, $store->list_for_agent( $agent_id ), 'Registry row inserted.' );

		do_action( 'datamachine_agent_deleted', $agent_id, 'cleanup-target' );

		$this->assertSame(
			array(),
			$store->list_for_agent( $agent_id ),
			'Registry rows cleared on `datamachine_agent_deleted`.'
		);
	}
}
