<?php
/**
 * CheckOrphanPipelinesCommand Tests
 *
 * Covers Extra-Chill/data-machine-events#363: a pipeline whose
 * pipeline_config got wiped to an empty string while flows kept running
 * against it (the "Pipeline step not found in pipeline config" error
 * storm). The command should detect such pipelines and, with
 * --apply --rebuild-config, reconstruct pipeline_config from the
 * surviving flow_config.
 *
 * Tables are built at setUp time, mirroring the pattern
 * CheckOrphanVenuesCommandTest / VenueMergeHelperTest already use.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.41.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Cli\Check\CheckOrphanPipelinesCommand;

class CheckOrphanPipelinesCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->ensure_tables();
	}

	private function ensure_tables(): void {
		global $wpdb;

		$flows     = $wpdb->prefix . 'datamachine_flows';
		$pipelines = $wpdb->prefix . 'datamachine_pipelines';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $flows ) ) === $flows ) {
			$wpdb->query( "TRUNCATE TABLE {$flows}" );
		} else {
			$wpdb->query(
				"CREATE TABLE {$flows} (
					flow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					pipeline_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
					flow_name VARCHAR(255) NOT NULL DEFAULT '',
					flow_config LONGTEXT NOT NULL,
					PRIMARY KEY (flow_id)
				)"
			);
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pipelines ) ) === $pipelines ) {
			$wpdb->query( "TRUNCATE TABLE {$pipelines}" );
		} else {
			$wpdb->query(
				"CREATE TABLE {$pipelines} (
					pipeline_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					pipeline_name VARCHAR(255) NOT NULL DEFAULT '',
					pipeline_config LONGTEXT NULL,
					PRIMARY KEY (pipeline_id)
				)"
			);
		}
	}

	private function insert_pipeline( int $pipeline_id, string $name, ?string $config ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'datamachine_pipelines',
			array(
				'pipeline_id'     => $pipeline_id,
				'pipeline_name'   => $name,
				'pipeline_config' => $config,
			),
			array( '%d', '%s', '%s' )
		);
	}

	private function insert_flow( int $pipeline_id, string $flow_config_json ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'datamachine_flows',
			array(
				'pipeline_id' => $pipeline_id,
				'flow_name'   => 'test flow',
				'flow_config' => $flow_config_json,
			),
			array( '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private function get_pipeline_config_raw( int $pipeline_id ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pipeline_config FROM {$wpdb->prefix}datamachine_pipelines WHERE pipeline_id = %d",
				$pipeline_id
			)
		);
	}

	/**
	 * A realistic 3-step flow_config (event_import -> ai -> upsert), the
	 * exact shape observed on pipeline 20's flows in #363.
	 */
	private function sample_flow_config( int $pipeline_id, int $flow_id ): string {
		$import = "{$pipeline_id}_aaaa1111-aaaa-1111-aaaa-111111111111";
		$ai     = "{$pipeline_id}_bbbb2222-bbbb-2222-bbbb-222222222222";
		$upsert = "{$pipeline_id}_cccc3333-cccc-3333-cccc-333333333333";

		return (string) wp_json_encode(
			array(
				"{$import}_{$flow_id}" => array(
					'flow_step_id'     => "{$import}_{$flow_id}",
					'step_type'        => 'event_import',
					'pipeline_step_id' => $import,
					'execution_order'  => 0,
					'flow_id'          => $flow_id,
					'settings'         => array( 'source' => 'ticketmaster' ),
				),
				"{$ai}_{$flow_id}"     => array(
					'flow_step_id'     => "{$ai}_{$flow_id}",
					'step_type'        => 'ai',
					'pipeline_step_id' => $ai,
					'execution_order'  => 1,
					'flow_id'          => $flow_id,
				),
				"{$upsert}_{$flow_id}" => array(
					'flow_step_id'     => "{$upsert}_{$flow_id}",
					'step_type'        => 'upsert',
					'pipeline_step_id' => $upsert,
					'execution_order'  => 2,
					'flow_id'          => $flow_id,
				),
			)
		);
	}

	private function run_command( array $assoc_args ): void {
		$cmd = new CheckOrphanPipelinesCommand();
		ob_start();
		$cmd( array(), $assoc_args );
		ob_end_clean();
	}

	// ---------------------------------------------------------------------

	public function test_dry_run_does_not_write_config(): void {
		$this->insert_pipeline( 20, 'Nashville Events', '' );
		$this->insert_flow( 20, $this->sample_flow_config( 20, 128 ) );

		$this->run_command( array( 'dry-run' => true ) );

		// Config must remain empty after a dry run.
		$this->assertSame( '', (string) $this->get_pipeline_config_raw( 20 ) );
	}

	public function test_apply_without_rebuild_flag_does_not_write(): void {
		$this->insert_pipeline( 20, 'Nashville Events', '' );
		$this->insert_flow( 20, $this->sample_flow_config( 20, 128 ) );

		// --apply alone must not perform the opt-in rebuild.
		$this->run_command( array( 'apply' => true ) );

		$this->assertSame( '', (string) $this->get_pipeline_config_raw( 20 ) );
	}

	public function test_rebuild_reconstructs_config_from_flow_config(): void {
		$this->insert_pipeline( 20, 'Nashville Events', '' );
		$this->insert_flow( 20, $this->sample_flow_config( 20, 128 ) );
		$this->insert_flow( 20, $this->sample_flow_config( 20, 129 ) );

		$this->run_command(
			array(
				'apply'          => true,
				'rebuild-config' => true,
			)
		);

		$raw = $this->get_pipeline_config_raw( 20 );
		$this->assertNotEmpty( $raw );

		$config = json_decode( (string) $raw, true );
		$this->assertIsArray( $config );

		// Three distinct pipeline-level steps, keyed by pipeline_step_id.
		$this->assertCount( 3, $config );
		$this->assertArrayHasKey( '20_aaaa1111-aaaa-1111-aaaa-111111111111', $config );
		$this->assertArrayHasKey( '20_bbbb2222-bbbb-2222-bbbb-222222222222', $config );
		$this->assertArrayHasKey( '20_cccc3333-cccc-3333-cccc-333333333333', $config );

		// Step types preserved.
		$this->assertSame( 'event_import', $config['20_aaaa1111-aaaa-1111-aaaa-111111111111']['step_type'] );
		$this->assertSame( 'ai', $config['20_bbbb2222-bbbb-2222-bbbb-222222222222']['step_type'] );
		$this->assertSame( 'upsert', $config['20_cccc3333-cccc-3333-cccc-333333333333']['step_type'] );

		// Flow-scoped fields stripped from the pipeline-level config.
		foreach ( $config as $step ) {
			$this->assertArrayNotHasKey( 'flow_step_id', $step );
			$this->assertArrayNotHasKey( 'flow_id', $step );
		}

		// pipeline_step_id retained on each entry.
		$this->assertSame(
			'20_bbbb2222-bbbb-2222-bbbb-222222222222',
			$config['20_bbbb2222-bbbb-2222-bbbb-222222222222']['pipeline_step_id']
		);
	}

	public function test_healthy_pipeline_is_not_touched(): void {
		// Valid JSON config + a flow => not a candidate.
		$valid = (string) wp_json_encode(
			array(
				'18_step' => array(
					'step_type'        => 'ai',
					'pipeline_step_id' => '18_step',
					'execution_order'  => 0,
				),
			)
		);
		$this->insert_pipeline( 18, 'Asheville Events', $valid );
		$this->insert_flow( 18, $this->sample_flow_config( 18, 50 ) );

		$this->run_command(
			array(
				'apply'          => true,
				'rebuild-config' => true,
			)
		);

		// Unchanged.
		$this->assertSame( $valid, (string) $this->get_pipeline_config_raw( 18 ) );
	}

	public function test_empty_pipeline_without_flows_is_ignored(): void {
		// Empty config but NO flows => not a candidate (nothing erroring).
		$this->insert_pipeline( 99, 'Abandoned', '' );

		$this->run_command(
			array(
				'apply'          => true,
				'rebuild-config' => true,
			)
		);

		// Still empty — we only repair pipelines with active flows.
		$this->assertSame( '', (string) $this->get_pipeline_config_raw( 99 ) );
	}
}
