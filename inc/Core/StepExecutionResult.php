<?php
/**
 * Step execution result classifier.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies execution success separately from DataPacket transport.
 */
class StepExecutionResult {

	/** @var array<string,bool> */
	private const NON_SUCCESS_PACKET_TYPES = array(
		'ai_response' => true,
	);

	/**
	 * Classify step output packets into an execution result.
	 *
	 * @param array  $data_packets Returned data packets.
	 * @param string $step_type    Step type identifier.
	 * @return array{success: bool, reason: string, packet_count: int}
	 */
	public static function classify( array $data_packets, string $step_type = '' ): array {
		$packet_count = count( $data_packets );

		if ( 0 === $packet_count ) {
			return array(
				'success'      => false,
				'reason'       => 'empty_data_packet_returned',
				'packet_count' => 0,
			);
		}

		$successful_handlers = self::collectSuccessfulHandlerTools( $data_packets );
		$has_success_packet  = false;

		foreach ( $data_packets as $packet ) {
			$metadata = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$type     = (string) ( $packet['type'] ?? '' );

			if ( array_key_exists( 'step_execution_success', $metadata ) ) {
				if ( false === (bool) $metadata['step_execution_success'] ) {
					return array(
						'success'      => false,
						'reason'       => self::sanitizeReason( $metadata['failure_reason'] ?? 'step_execution_failed' ),
						'packet_count' => $packet_count,
					);
				}

				$has_success_packet = true;
				continue;
			}

			if ( isset( $metadata['success'] ) && false === $metadata['success'] ) {
				return array(
					'success'      => false,
					'reason'       => self::sanitizeReason( $metadata['failure_reason'] ?? 'packet_failure' ),
					'packet_count' => $packet_count,
				);
			}

			if ( isset( $metadata['tool_success'] ) && false === $metadata['tool_success'] ) {
				$handler_tool = isset( $metadata['handler_tool'] ) ? (string) $metadata['handler_tool'] : '';
				if ( '' !== $handler_tool && isset( $successful_handlers[ $handler_tool ] ) ) {
					continue;
				}

				return array(
					'success'      => false,
					'reason'       => self::sanitizeReason( $metadata['failure_reason'] ?? 'tool_result_failed' ),
					'packet_count' => $packet_count,
				);
			}

			if ( ! isset( self::NON_SUCCESS_PACKET_TYPES[ $type ] ) ) {
				$has_success_packet = true;
			}
		}

		return array(
			'success'      => $has_success_packet,
			'reason'       => $has_success_packet ? 'completed' : self::fallbackReason( $step_type ),
			'packet_count' => $packet_count,
		);
	}

	/**
	 * Collect handler tools with at least one successful result packet.
	 *
	 * @param array $data_packets Returned data packets.
	 * @return array<string,bool>
	 */
	private static function collectSuccessfulHandlerTools( array $data_packets ): array {
		$handlers = array();

		foreach ( $data_packets as $packet ) {
			$metadata     = is_array( $packet['metadata'] ?? null ) ? $packet['metadata'] : array();
			$handler_tool = isset( $metadata['handler_tool'] ) ? (string) $metadata['handler_tool'] : '';

			if ( '' === $handler_tool ) {
				continue;
			}

			if ( 'ai_handler_complete' === ( $packet['type'] ?? '' ) || true === ( $metadata['tool_success'] ?? false ) ) {
				$handlers[ $handler_tool ] = true;
			}
		}

		return $handlers;
	}

	private static function fallbackReason( string $step_type ): string {
		return 'ai' === $step_type ? 'ai_response_without_tool_result' : 'step_output_not_successful';
	}

	private static function sanitizeReason( $reason ): string {
		$reason = is_scalar( $reason ) ? trim( (string) $reason ) : '';
		if ( '' === $reason ) {
			return 'step_execution_failed';
		}

		return function_exists( 'sanitize_key' ) ? sanitize_key( str_replace( ' ', '_', $reason ) ) : preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '_', $reason ) ) );
	}
}
