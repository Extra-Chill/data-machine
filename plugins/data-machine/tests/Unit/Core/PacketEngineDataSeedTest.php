<?php
/**
 * PacketEngineData seed/restore tests.
 *
 * @package DataMachine\Tests\Unit\Core
 */

namespace DataMachine\Tests\Unit\Core;

use DataMachine\Core\PacketEngineData;
use WP_UnitTestCase;

class PacketEngineDataSeedTest extends WP_UnitTestCase {

	public function test_seed_overlays_and_records_changed_keys(): void {
		$engine = PacketEngineData::applySeed(
			array( 'source_url' => 'https://parent.example', 'flow' => array( 'name' => 'F' ) ),
			array( 'title' => 'First', 'status_flag' => 'cancelled', 'source_url' => 'https://item.example' )
		);

		$this->assertSame( 'First', $engine['title'] );
		$this->assertSame( 'cancelled', $engine['status_flag'] );
		$this->assertSame( 'https://item.example', $engine['source_url'] );
		$this->assertSame( array( 'title', 'status_flag', 'source_url' ), $engine[ PacketEngineData::SEED_RESTORE_KEY ]['keys'] );
		$this->assertSame( array( 'source_url' => 'https://parent.example' ), $engine[ PacketEngineData::SEED_RESTORE_KEY ]['prior'] );
	}

	public function test_without_seed_restores_prior_values_and_drops_seed_only_keys(): void {
		$parent = array( 'source_url' => 'https://parent.example', 'flow' => array( 'name' => 'F' ) );
		$seeded = PacketEngineData::applySeed( $parent, array( 'title' => 'First', 'status_flag' => 'cancelled', 'source_url' => 'https://item.example' ) );

		$this->assertSame( $parent, PacketEngineData::withoutSeed( $seeded ) );
	}

	public function test_repeated_seed_keeps_first_prior_values(): void {
		$parent = array( 'source_url' => 'https://parent.example' );
		$once   = PacketEngineData::applySeed( $parent, array( 'source_url' => 'https://a.example', 'title' => 'A' ) );
		$twice  = PacketEngineData::applySeed( $once, array( 'source_url' => 'https://a.example', 'title' => 'A', 'extra' => 1 ) );

		$this->assertSame( $parent, PacketEngineData::withoutSeed( $twice ) );
	}

	public function test_without_seed_is_a_no_op_for_unseeded_engine_data(): void {
		$engine = array( 'title' => 'Kept' );

		$this->assertSame( $engine, PacketEngineData::withoutSeed( $engine ) );
	}
}
