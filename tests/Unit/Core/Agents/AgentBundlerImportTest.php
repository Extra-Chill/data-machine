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
use DataMachine\Core\ActionScheduler\GroupRegistrar;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;
use DataMachine\Core\Database\Flows\Flows as FlowsRepository;
use DataMachine\Core\Database\Jobs\Jobs as JobsRepository;
use DataMachine\Core\Database\Pipelines\Pipelines as PipelinesRepository;
use DataMachine\Abilities\Engine\RunFlowAbility;
use DataMachine\Engine\AI\Tools\Global\AgentDailyMemory;
use DataMachine\Engine\Bundle\AgentBundleArrayAdapter;
use DataMachine\Engine\Bundle\AgentBundleArtifactState;
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
			remove_filter( 'wp_agent_memory_store', $this->memory_store_filter, 10 );
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

	public function test_import_persists_installed_artifacts_to_canonical_table(): void {
		$result = $this->bundler->import( $this->fixture_bundle( 'artifact-state-agent' ), null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Bundle import succeeds.' );

		$agent     = $this->agents_repo->get_by_slug( 'artifact-state-agent' );
		$artifacts = ( new InstalledBundleArtifacts() )->list_for_bundle( 'artifact-state-agent', (int) $agent['agent_id'] );
		$types     = array_map( static fn( AgentBundleInstalledArtifact $artifact ): string => $artifact->to_array()['artifact_type'], $artifacts );

		sort( $types );
		$this->assertSame( array( 'agent_config', 'flow', 'pipeline' ), $types, 'Importer writes installed artifact state to the canonical table.' );
	}

	public function test_import_ensures_installed_artifact_table_at_runtime(): void {
		global $wpdb;

		// Some isolated runtimes can reach bundle import before deploy-time schema
		// ensures run. The repository should self-heal instead of failing import.
		$suppress_errors = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}datamachine_bundle_artifacts" );
		$wpdb->suppress_errors( $suppress_errors );

		$result = $this->bundler->import( $this->fixture_bundle( 'artifact-table-ensure-agent' ), null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Bundle import succeeds after recreating the artifact table.' );

		$agent     = $this->agents_repo->get_by_slug( 'artifact-table-ensure-agent' );
		$artifacts = ( new InstalledBundleArtifacts() )->list_for_bundle( 'artifact-table-ensure-agent', (int) $agent['agent_id'] );

		$this->assertCount( 3, $artifacts, 'Installed artifact rows are written after runtime table ensure.' );
	}

	public function test_artifact_persistence_reports_artifact_failure_details(): void {
		$result = AgentBundleArtifactState::persist_for_agent_result(
			123,
			array(
				array(
					'bundle_slug'    => 'broken-bundle',
					'bundle_version' => '1',
					'artifact_type'  => 'not a valid type',
					'artifact_id'    => 'broken-artifact',
					'source_path'    => 'broken.json',
					'installed_hash' => 'abc123',
					'current_hash'   => 'abc123',
					'installed_at'   => '2026-05-31 00:00:00',
					'updated_at'     => '2026-05-31 00:00:00',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'datamachine_bundle_artifact_persist_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'broken-artifact', $result->get_error_message() );
		$this->assertStringContainsString( 'installed bundle artifact_type must be one of the registered bundle artifact types', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertSame( 'broken-artifact', $data['errors'][0]['artifact_id'] ?? null );
		$this->assertSame( 'broken.json', $data['errors'][0]['source_path'] ?? null );
	}

	public function test_directory_value_object_import_preserves_workflow_runtime_seed_fields(): void {
		$bundle = $this->fixture_bundle( 'directory-import-agent' );
		$bundle['flows'][0]['flow_config']['1_step-uuid_1'] = array_merge(
			$bundle['flows'][0]['flow_config']['1_step-uuid_1'],
			array(
				'step_type'          => 'fetch',
				'handler_configs'    => array(
					'mcp' => array(
						'provider' => 'mgs',
						'auth_ref' => 'mgs:default',
					),
				),
				'enabled_tools'      => array( 'datamachine/search' ),
				'disabled_tools'     => array( 'datamachine/delete-flow' ),
				'config_patch_queue' => array(
					array( 'patch' => array( 'query' => 'bundle-seed' ) ),
				),
				'queue_mode'         => 'loop',
				'enabled'            => false,
			)
		);
		$bundle['flows'][0]['scheduling_config'] = array(
			'enabled'  => true,
			'interval' => 'daily',
			'max_items' => array(
				'mcp' => 7,
			),
		);

		$directory = AgentBundleArrayAdapter::from_array_bundle( $bundle );
		$result    = $this->bundler->import_directory_object( $directory, null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Directory value-object import succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'directory-import-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$step     = reset( $flow['flow_config'] );

		$this->assertSame( 'mgs:default', $step['handler_configs']['mcp']['auth_ref'] ?? null, 'Handler config imports from directory document.' );
		$this->assertSame( array( 'datamachine/search' ), $step['enabled_tools'] ?? null, 'Enabled tools import from directory document.' );
		$this->assertSame( array( 'datamachine/delete-flow' ), $step['disabled_tools'] ?? null, 'Disabled tools import from directory document.' );
		$this->assertSame( 'bundle-seed', $step['config_patch_queue'][0]['patch']['query'] ?? null, 'Queue seed imports from directory document.' );
		$this->assertSame( 'loop', $step['queue_mode'] ?? null, 'Queue state imports from directory document.' );
		$this->assertFalse( $step['enabled'] ?? true, 'Disabled step state imports from directory document.' );
		$this->assertSame( 'daily', $flow['scheduling_config']['interval'] ?? null, 'Schedule imports from directory document.' );
		$this->assertSame( array( 'mcp' => 7 ), $flow['scheduling_config']['max_items'] ?? null, 'Schedule limits import from directory document.' );
	}

	public function test_schema_versioned_array_import_does_not_require_source_install_ids(): void {
		$bundle = AgentBundleArrayAdapter::to_array_bundle( AgentBundleArrayAdapter::from_array_bundle( $this->fixture_bundle( 'schema-array-agent' ) ) );
		$this->assertSame( BundleSchema::VERSION, $bundle['bundle_schema_version'] ?? null, 'Test fixture uses integer schema version.' );
		unset( $bundle['pipelines'][0]['original_id'], $bundle['flows'][0]['original_id'], $bundle['flows'][0]['original_pipeline_id'] );

		$result = $this->bundler->import( $bundle, null, $this->owner_id );

		$this->assertTrue( (bool) $result['success'], 'Schema-versioned array import succeeds.' );
		$this->assertSame( 1, $result['summary']['flows_imported'] ?? null, 'Schema-versioned arrays rebuild portable flow references without source install IDs.' );

		$agent    = $this->agents_repo->get_by_slug( 'schema-array-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );

		$this->assertNotEmpty( $flow, 'Flow imports from a schema-versioned array that omits source install IDs.' );
	}

	public function test_schema_versioned_array_import_preserves_abilities_manifest_for_dry_run_checks(): void {
		$bundle                       = AgentBundleArrayAdapter::to_array_bundle( AgentBundleArrayAdapter::from_array_bundle( $this->fixture_bundle( 'schema-abilities-agent' ) ) );
		$bundle['abilities_manifest'] = array( 'datamachine/test-missing-ability' );

		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$result = $this->bundler->import( $bundle, null, $this->owner_id, true );

		$this->assertTrue( (bool) $result['success'], 'Schema-versioned dry run succeeds.' );
		$this->assertSame( array( 'datamachine/test-missing-ability' ), $result['summary']['missing_abilities'] ?? null, 'Abilities manifest survives schema array canonicalization.' );
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
		$this->memory_store_filter = static fn( $default, array $context ) => $store;
		add_filter( 'wp_agent_memory_store', $this->memory_store_filter, 10, 2 );

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

	public function test_upgrade_materializes_authored_soul_without_clobbering_learned_memory(): void {
		$store                     = new DailyMemoryImportFakeStore();
		$this->memory_store_filter = static fn( $default, array $context ) => $store;
		add_filter( 'wp_agent_memory_store', $this->memory_store_filter, 10, 2 );

		// Fresh install: a flow-less, memory-bearing bundle carrying authored SOUL.
		$bundle                          = $this->fixture_bundle( 'soul-upgrade-agent' );
		$bundle['pipelines']             = array();
		$bundle['flows']                 = array();
		$bundle['files']['SOUL.md']      = "# Identity\n\noriginal soul\n";
		$bundle_dir                      = sys_get_temp_dir() . '/datamachine-soul-bundle-' . getmypid();
		$this->remove_tree( $bundle_dir );
		$this->assertTrue( $this->bundler->to_directory( $bundle, $bundle_dir ), 'Bundle directory write succeeds.' );
		$install_bundle = $this->bundler->from_directory( $bundle_dir );
		$this->assertIsArray( $install_bundle, 'Install bundle reads back from directory.' );

		$installed = $this->bundler->import( $install_bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $installed['success'], 'Fresh install of a memory-bearing bundle succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'soul-upgrade-agent' );
		$agent_id = (int) $agent['agent_id'];

		$soul = new \DataMachine\Core\FilesRepository\AgentMemory( 0, $agent_id, 'SOUL.md' );
		$this->assertTrue( $soul->read()->exists, 'Fresh install writes SOUL.md to the live store.' );
		$this->assertStringContainsString( 'original soul', $soul->read()->content, 'Fresh install seeds the bundle SOUL content.' );

		// The agent accumulates LEARNED memory after install — this must survive an upgrade.
		$learned = new \DataMachine\Core\FilesRepository\AgentMemory( 0, $agent_id, 'MEMORY.md' );
		$learned->replace_all( "# Memory\n\nlearned-sentinel\n" );

		// SOUL is tracked as an installed memory artifact so future upgrades diff cleanly.
		$installed_types = array_map(
			static fn( AgentBundleInstalledArtifact $artifact ): string => $artifact->to_array()['artifact_type'],
			( new InstalledBundleArtifacts() )->list_for_bundle( 'soul-upgrade-agent', $agent_id )
		);
		$this->assertContains( 'memory', $installed_types, 'Authored SOUL is recorded in the bundle artifact ledger.' );

		// Upgrade: ship a NEW SOUL. The bundle carries no MEMORY.md.
		$upgrade_bundle                     = $this->fixture_bundle( 'soul-upgrade-agent' );
		$upgrade_bundle['pipelines']        = array();
		$upgrade_bundle['flows']            = array();
		$upgrade_bundle['files']['SOUL.md'] = "# Identity\n\nupgraded soul\n";
		$this->remove_tree( $bundle_dir );
		$this->assertTrue( $this->bundler->to_directory( $upgrade_bundle, $bundle_dir ), 'Upgrade bundle directory write succeeds.' );
		$upgrade_bundle = $this->bundler->from_directory( $bundle_dir );
		$this->remove_tree( $bundle_dir );

		$result = $this->bundler->import( $upgrade_bundle, null, $this->owner_id, false, array( 'is_upgrade' => true ) );
		$this->assertTrue( (bool) $result['success'], 'Upgrade of a memory-bearing bundle succeeds.' );

		$soul_after = new \DataMachine\Core\FilesRepository\AgentMemory( 0, $agent_id, 'SOUL.md' );
		$this->assertStringContainsString( 'upgraded soul', $soul_after->read()->content, 'Upgrade materializes the new authored SOUL into the live store.' );

		$learned_after = new \DataMachine\Core\FilesRepository\AgentMemory( 0, $agent_id, 'MEMORY.md' );
		$this->assertStringContainsString( 'learned-sentinel', $learned_after->read()->content, 'Upgrade preserves learned runtime memory it did not ship.' );
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

		as_unschedule_all_actions( 'datamachine_run_flow_now', array( $flow_id ), GroupRegistrar::GROUP );
		$this->assertFalse( as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), GroupRegistrar::GROUP ), 'Test setup removes the scheduled action while preserving flow row scheduling.' );

		$second = $this->bundler->import(
			$bundle,
			null,
			$this->owner_id,
			false,
			array( 'is_upgrade' => true )
		);

		$this->assertTrue( (bool) $second['success'], 'Upgrade import succeeds when only the scheduled action is missing.' );
		$this->assertNotFalse( as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), GroupRegistrar::GROUP ), 'Importer re-creates the missing scheduled action for an enabled non-manual flow.' );
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

	public function test_upgrade_preserves_runtime_overlays_without_hiding_bundle_seed_drift(): void {
		$bundle = $this->fixture_bundle( 'runtime-overlay-agent' );
		$bundle['flows'][0]['flow_config']['1_step-uuid_1'] = array_merge(
			$bundle['flows'][0]['flow_config']['1_step-uuid_1'],
			array(
				'step_type'          => 'fetch',
				'handler_configs'    => array(
					'mcp' => array(
						'provider'  => 'mgs',
						'max_items' => 5,
					),
				),
				'config_patch_queue' => array( array( 'patch' => array( 'query' => 'seed' ) ) ),
				'queue_mode'         => 'loop',
			)
		);
		$bundle['flows'][0]['scheduling_config'] = array(
			'enabled'   => false,
			'interval'  => 'manual',
			'max_items' => array( 'mcp' => 5 ),
		);

		$first = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $first['success'], 'Initial install succeeds.' );

		$agent    = $this->agents_repo->get_by_slug( 'runtime-overlay-agent' );
		$pipeline = $this->pipelines_repo->get_by_portable_slug( (int) $agent['agent_id'], 'static-site-pipeline' );
		$flow     = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$config   = $flow['flow_config'];
		$step_id  = array_key_first( $config );

		$config[ $step_id ]['config_patch_queue'] = array(
			array( 'patch' => array( 'query' => 'live-a' ) ),
			array( 'patch' => array( 'query' => 'live-b' ) ),
		);
		$config[ $step_id ]['queue_mode'] = 'drain';
		$config[ $step_id ]['_queue_consume_revision'] = 'live-rev';
		$config[ $step_id ]['handler_configs']['mcp']['max_items'] = 1;
		$this->flows_repo->update_flow(
			(int) $flow['flow_id'],
			array(
				'flow_config'       => $config,
				'scheduling_config' => array(
					'enabled'   => true,
					'interval'  => 'hourly',
					'max_items' => array( 'mcp' => 1 ),
				),
			)
		);

		$upgrade = $bundle;
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['config_patch_queue'] = array(
			array( 'patch' => array( 'query' => 'target-a' ) ),
			array( 'patch' => array( 'query' => 'target-b' ) ),
		);
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['queue_mode'] = 'loop';
		$upgrade['flows'][0]['flow_config']['1_step-uuid_1']['handler_configs']['mcp']['max_items'] = 50;
		$upgrade['flows'][0]['scheduling_config'] = array(
			'enabled'   => false,
			'interval'  => 'manual',
			'max_items' => array( 'mcp' => 50 ),
		);

		$second = $this->bundler->import(
			$upgrade,
			null,
			$this->owner_id,
			false,
			array( 'is_upgrade' => true )
		);

		$this->assertTrue( (bool) $second['success'], 'Upgrade succeeds when only runtime overlays changed locally.' );
		$this->assertSame( array(), $second['summary']['conflicts'] ?? array(), 'Runtime overlay drift is not reported as package source drift.' );

		$updated_flow = $this->flows_repo->get_by_portable_slug( (int) $pipeline['pipeline_id'], 'static-site-flow' );
		$updated_step = $updated_flow['flow_config'][ $step_id ];
		$this->assertCount( 2, $updated_step['config_patch_queue'], 'Local queue entries are preserved.' );
		$this->assertSame( 'live-a', $updated_step['config_patch_queue'][0]['patch']['query'] ?? null, 'Local queue content is preserved.' );
		$this->assertSame( 'drain', $updated_step['queue_mode'] ?? null, 'Local queue mode is preserved.' );
		$this->assertSame( 'live-rev', $updated_step['_queue_consume_revision'] ?? null, 'Local consume revision is preserved.' );
		$this->assertSame( 1, $updated_step['handler_configs']['mcp']['max_items'] ?? null, 'Local burn-in max_items is preserved.' );
		$this->assertSame( 'hourly', $updated_flow['scheduling_config']['interval'] ?? null, 'Local schedule interval is preserved.' );
		$this->assertSame( array( 'mcp' => 1 ), $updated_flow['scheduling_config']['max_items'] ?? null, 'Local schedule max_items are preserved.' );
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

	public function test_upgrade_preserves_projection_excluded_agent_config_without_conflict(): void {
		$bundle = $this->fixture_bundle( 'context-agent' );
		$bundle['agent']['agent_config'] = array(
			'intelligence' => array(
				'context_servers' => array(
					'wporg' => array(
						'transport' => 'stdio',
						'command'   => 'node',
					),
				),
			),
		);

		$first = $this->bundler->import( $bundle, null, $this->owner_id );
		$this->assertTrue( (bool) $first['success'], 'Initial install succeeds.' );

		$agent = $this->agents_repo->get_by_slug( 'context-agent' );
		$this->agents_repo->update_agent(
			(int) $agent['agent_id'],
			array(
				'agent_config' => array_merge(
					$agent['agent_config'],
					array(
						'intelligence' => array(
							'context_servers' => array(
								'wporg' => array(
									'transport' => 'streamable-http',
									'url'       => 'https://example.test/mcp',
									'headers'   => array( 'Authorization' => 'Bearer local-token' ),
								),
							),
						),
					)
				),
			)
		);

		$updated_bundle = $bundle;
		$updated_bundle['agent']['agent_config']['intelligence']['context_servers']['wporg']['args'] = array( 'mcp-context-wporg/dist/index.js' );

		$second = $this->bundler->import(
			$updated_bundle,
			null,
			$this->owner_id,
			false,
			array( 'is_upgrade' => true )
		);

		$this->assertTrue( (bool) $second['success'], 'Upgrade succeeds so clean artifacts can apply.' );
		$this->assertSame( array(), $second['summary']['conflicts'], 'Projection-excluded agent config does not report a bundle conflict.' );

		$after = $this->agents_repo->get_by_slug( 'context-agent' );
		$this->assertSame(
			'streamable-http',
			$after['agent_config']['intelligence']['context_servers']['wporg']['transport'] ?? null,
			'Upgrade preserves the live context server transport.'
		);
		$this->assertSame(
			'Bearer local-token',
			$after['agent_config']['intelligence']['context_servers']['wporg']['headers']['Authorization'] ?? null,
			'Upgrade preserves the live context server authorization header.'
		);
		$this->assertArrayNotHasKey(
			'args',
			$after['agent_config']['intelligence']['context_servers']['wporg'],
			'Bundle context server args do not overwrite local runtime config without approval.'
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
