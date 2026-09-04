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
