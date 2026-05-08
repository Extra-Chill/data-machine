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

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents as AgentsRepository;
use DataMachine\Core\Database\BundleArtifacts\InstalledBundleArtifacts;
use DataMachine\Core\Database\Flows\Flows as FlowsRepository;
use DataMachine\Core\Database\Pipelines\Pipelines as PipelinesRepository;
use DataMachine\Engine\Bundle\AgentBundleInstalledArtifact;
use DataMachine\Engine\Bundle\AgentBundleManifest;
use WP_UnitTestCase;

class AgentBundlerImportTest extends WP_UnitTestCase {

	private AgentBundler $bundler;
	private AgentsRepository $agents_repo;
	private PipelinesRepository $pipelines_repo;
	private FlowsRepository $flows_repo;
	private int $owner_id;

	public function set_up(): void {
		parent::set_up();

		AgentsRepository::create_table();
		PipelinesRepository::create_table();
		FlowsRepository::create_table();
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}datamachine_bundle_artifacts" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		remove_all_actions( 'datamachine_bundle_import_pre_commit' );
		remove_all_actions( 'datamachine_bundle_import_post_claim_started' );

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
