<?php
/**
 * Flow config compare-and-swap database tests.
 *
 * @package DataMachine\Tests\Unit\Core\Database\Flows
 */

namespace DataMachine\Tests\Unit\Core\Database\Flows;

use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use WP_UnitTestCase;

class FlowsCompareAndSwapTest extends WP_UnitTestCase {

	private Flows $flows;
	private int $flow_id;

	public function set_up(): void {
		parent::set_up();

		$pipelines   = new Pipelines();
		$this->flows = new Flows();
		$pipeline_id = $pipelines->create_pipeline(
			array(
				'pipeline_name'   => 'Byte-exact CAS Pipeline',
				'pipeline_config' => array(),
			)
		);
		$this->flow_id = $this->flows->create_flow(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => 'Byte-exact CAS Flow',
				'flow_config'       => array(
					'step' => array( 'value' => 'Alpha' ),
				),
				'scheduling_config' => array( 'interval' => 'manual' ),
			)
		);
	}

	public function test_case_only_concurrent_change_rejects_stale_snapshot(): void {
		$expected   = $this->get_raw_config();
		$concurrent = str_replace( 'Alpha', 'alpha', $expected );
		$this->write_raw_config( $concurrent );

		$updated = $this->flows->compare_and_swap_flow_config(
			$this->flow_id,
			$expected,
			array( 'step' => array( 'value' => 'Replacement' ) )
		);

		$this->assertFalse( $updated );
		$this->assertSame( $concurrent, $this->flows->get_flow_config_json( $this->flow_id ) );
	}

	public function test_trailing_space_concurrent_change_rejects_stale_snapshot(): void {
		$expected   = $this->get_raw_config();
		$concurrent = $expected . ' ';
		$this->write_raw_config( $concurrent );

		$updated = $this->flows->compare_and_swap_flow_config(
			$this->flow_id,
			$expected,
			array( 'step' => array( 'value' => 'Replacement' ) )
		);

		$this->assertFalse( $updated );
		$this->assertSame( $concurrent, $this->flows->get_flow_config_json( $this->flow_id ) );
	}

	public function test_exact_snapshot_updates_successfully(): void {
		$updated = $this->flows->compare_and_swap_flow_config(
			$this->flow_id,
			$this->get_raw_config(),
			array( 'step' => array( 'value' => 'Replacement' ) )
		);

		$this->assertTrue( $updated );
		$this->assertSame(
			array( 'step' => array( 'value' => 'Replacement' ) ),
			json_decode( $this->get_raw_config(), true )
		);
	}

	private function get_raw_config(): string {
		$config = $this->flows->get_flow_config_json( $this->flow_id );

		$this->assertIsString( $config );
		return $config;
	}

	private function write_raw_config( string $config ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . Flows::TABLE_NAME,
			array( 'flow_config' => $config ),
			array( 'flow_id' => $this->flow_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame( 1, $result );
	}
}
