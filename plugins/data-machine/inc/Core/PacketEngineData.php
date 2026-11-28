<?php
/**
 * Per-item engine data carried by DataPacket metadata.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

class PacketEngineData {

	/**
	 * Remove keys reserved for canonical job runtime context.
	 *
	 * @param array $engine_data Packet-provided engine data.
	 * @param int   $job_id      Job ID for logging context.
	 * @return array Sanitized per-item engine data.
	 */
	public static function sanitize( array $engine_data, int $job_id ): array {
		$reserved = array();

		foreach ( array_keys( $engine_data ) as $key ) {
			if ( self::isReservedKey( (string) $key ) ) {
				$reserved[] = (string) $key;
				unset( $engine_data[ $key ] );
			}
		}

		if ( $reserved ) {
			do_action(
				'datamachine_log',
				'warning',
				'Dropped packet engine_data keys reserved for job runtime context',
				array(
					'job_id' => $job_id,
					'keys'   => $reserved,
				)
			);
		}

		return $engine_data;
	}

	/**
	 * Engine key recording what a packet seed changed on a parent job.
	 *
	 * Shape: array{keys: string[], prior: array<string, mixed>}. `keys` are the
	 * top-level keys the seed set; `prior` holds the values those keys had
	 * before seeding (absent when the key did not exist).
	 */
	public const SEED_RESTORE_KEY = 'packet_seed_restore';

	/**
	 * Seed a job's engine data from its first packet, remembering what changed.
	 *
	 * A source step's job is seeded with the first packet's engine data so a
	 * single-packet run has its item context. When the step fans out, each
	 * child starts from a copy of this parent, so withoutSeed() must be able to
	 * take the first packet's values back out; otherwise every child inherits
	 * them wherever its own packet has no value for the same key.
	 *
	 * Repeated seeding (retries) keeps the first-recorded prior values.
	 *
	 * @param array $engine    Job engine data.
	 * @param array $seed_data Sanitized packet engine data.
	 * @return array Seeded engine data.
	 */
	public static function applySeed( array $engine, array $seed_data ): array {
		$restore = is_array( $engine[ self::SEED_RESTORE_KEY ] ?? null ) ? $engine[ self::SEED_RESTORE_KEY ] : array();
		$keys    = is_array( $restore['keys'] ?? null ) ? $restore['keys'] : array();
		$prior   = is_array( $restore['prior'] ?? null ) ? $restore['prior'] : array();

		foreach ( array_keys( $seed_data ) as $key ) {
			$key = (string) $key;
			if ( 'packet_fanout_transfer' === $key || self::SEED_RESTORE_KEY === $key || in_array( $key, $keys, true ) ) {
				continue;
			}
			$keys[] = $key;
			if ( array_key_exists( $key, $engine ) ) {
				$prior[ $key ] = $engine[ $key ];
			}
		}

		unset( $seed_data[ self::SEED_RESTORE_KEY ] );
		$engine = array_replace_recursive( $engine, $seed_data );
		if ( $keys ) {
			$engine[ self::SEED_RESTORE_KEY ] = array(
				'keys'  => $keys,
				'prior' => $prior,
			);
		}

		return $engine;
	}

	/**
	 * Undo applySeed(): restore pre-seed values and drop seed-only keys.
	 *
	 * @param array $engine Engine data copied from a seeded parent.
	 * @return array Engine data without the first packet's item context.
	 */
	public static function withoutSeed( array $engine ): array {
		$restore = $engine[ self::SEED_RESTORE_KEY ] ?? null;
		unset( $engine[ self::SEED_RESTORE_KEY ] );
		if ( ! is_array( $restore ) ) {
			return $engine;
		}

		$prior = is_array( $restore['prior'] ?? null ) ? $restore['prior'] : array();
		foreach ( (array) ( $restore['keys'] ?? array() ) as $key ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $prior ) ) {
				$engine[ $key ] = $prior[ $key ];
			} else {
				unset( $engine[ $key ] );
			}
		}

		return $engine;
	}

	/**
	 * Check whether a packet-provided engine data key is reserved.
	 *
	 * @param string $key Engine data key.
	 * @return bool
	 */
	private static function isReservedKey( string $key ): bool {
		if ( str_starts_with( $key, 'batch' ) ) {
			return true;
		}

		return in_array(
			$key,
			array( 'job', 'flow', 'pipeline', 'flow_config', 'pipeline_config' ),
			true
		);
	}
}
