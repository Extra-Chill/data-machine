<?php
/**
 * DataPacket prompt projection.
 *
 * @package DataMachine\Engine\AI
 */

namespace DataMachine\Engine\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Builds prompt-facing packet copies without changing canonical packets.
 */
class DataPacketPromptProjector {

	/**
	 * Project canonical DataPackets for AI prompt serialization.
	 *
	 * Data Machine's default projection is intentionally source-agnostic. Source
	 * integrations that understand handler-specific packet shapes can replace or
	 * compact the prompt-facing packet with the datamachine_ai_project_data_packet
	 * filter while canonical storage/engine packets remain unchanged.
	 *
	 * @param array $data_packets Canonical packets from storage/engine state.
	 * @param array $context      Source-agnostic runtime context for projection filters.
	 * @return array Prompt-facing packet copies.
	 */
	public static function project( array $data_packets, array $context = array() ): array {
		$projected_packets = array();

		foreach ( $data_packets as $packet ) {
			if ( ! is_array( $packet ) ) {
				$projected_packets[] = $packet;
				continue;
			}

			$projected_packets[] = self::projectPacket( $packet, $context );
		}

		return $projected_packets;
	}

	/**
	 * Project one packet using the generic default and filter extension point.
	 *
	 * @param array $packet  Canonical packet.
	 * @param array $context Source-agnostic runtime context for projection filters.
	 * @return array Prompt-facing packet.
	 */
	private static function projectPacket( array $packet, array $context ): array {
		$projected = $packet;
		if ( isset( $projected['data'] ) && is_array( $projected['data'] ) ) {
			$projected['data'] = self::sanitizePacketData( $projected['data'] );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'datamachine_ai_project_data_packet', $projected, $packet, $context );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}

		return $projected;
	}

	/**
	 * Remove internal fields from prompt-facing data.
	 *
	 * @param array $packet_data Packet data.
	 * @return array Sanitized packet data.
	 */
	private static function sanitizePacketData( array $packet_data ): array {
		if ( ! isset( $packet_data['file_info'] ) || ! is_array( $packet_data['file_info'] ) ) {
			return $packet_data;
		}

		$sanitized_file_info = $packet_data['file_info'];
		unset( $sanitized_file_info['file_path'] );

		if ( empty( $sanitized_file_info ) ) {
			unset( $packet_data['file_info'] );
			return $packet_data;
		}

		$packet_data['file_info'] = $sanitized_file_info;
		return $packet_data;
	}
}
