<?php

namespace DataMachine\Engine\AI\Tools;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Universal utility for finding AI tool execution results in data packets.
 *
 * Part of the engine infrastructure, providing reusable data packet interpretation
 * for all step types that participate in AI tool calling.
 *
 * @package DataMachine\Engine\AI\Tools
 * @since   0.2.1
 */
class ToolResultFinder {


	/**
	 * Find AI tool execution result by exact handler match.
	 *
	 * Searches data packet for tool_result or ai_handler_complete entries
	 * matching the specified handler slug. Logs error when no match found.
	 *
	 * @param  array  $dataPackets  Data packet array from pipeline execution
	 * @param  string $handler      Handler slug to match
	 * @param  string $flow_step_id Flow step ID for error logging context
	 * @param  bool   $log_error_on_missing Whether to log an error when no match is found.
	 * @return array|null Tool result entry or null if no match found
	 */
	public static function findHandlerResult( array $dataPackets, string $handler, string $flow_step_id, bool $log_error_on_missing = true ): ?array {
		foreach ( $dataPackets as $entry ) {
			$entry_type = $entry['type'] ?? '';

			// Only match successful handler completions.
			// 'ai_handler_complete' entries are already filtered for success during creation.
			// 'tool_result' entries must be checked for tool_success to avoid treating
			// failed tool calls as successful publish completions.
			if ( 'ai_handler_complete' === $entry_type ) {
				if ( self::handlerMatches( $entry, $handler ) && self::toolSucceeded( $entry ) ) {
					return $entry;
				}
			}

			if ( 'tool_result' === $entry_type ) {
				if ( self::handlerMatches( $entry, $handler ) && self::toolSucceeded( $entry ) ) {
					return $entry;
				}
			}
		}

		if ( $log_error_on_missing ) {
			do_action(
				'datamachine_log',
				'error',
				'AI did not execute handler tool',
				array(
					'handler'      => $handler,
					'flow_step_id' => $flow_step_id,
				)
			);
		}

		return null;
	}

	/**
	 * Find ALL handler results matching any of the given handler slugs.
	 *
	 * @param  array  $dataPackets   Data packets from pipeline
	 * @param  array  $handler_slugs Handler slugs to match
	 * @param  string $flow_step_id  Flow step ID for logging
	 * @return array Array of matching tool result entries
	 */
	public static function findAllHandlerResults( array $dataPackets, array $handler_slugs, string $flow_step_id ): array {
		$results = array();

		foreach ( $handler_slugs as $slug ) {
			foreach ( $dataPackets as $entry ) {
				$entry_type = $entry['type'] ?? '';

				if ( 'ai_handler_complete' === $entry_type && self::handlerMatches( $entry, $slug ) && self::toolSucceeded( $entry ) ) {
					$results[] = $entry;
				} elseif ( 'tool_result' === $entry_type && self::handlerMatches( $entry, $slug ) && self::toolSucceeded( $entry ) ) {
					$results[] = $entry;
				}
			}
		}

		if ( empty($results) ) {
			do_action(
				'datamachine_log',
				'error',
				'AI did not execute any handler tools',
				array(
					'handlers'     => $handler_slugs,
					'flow_step_id' => $flow_step_id,
				)
			);
		}

		return $results;
	}

	/**
	 * Check whether a packet belongs to the requested handler slug.
	 *
	 * @param array  $entry   Data packet.
	 * @param string $handler Handler slug.
	 * @return bool
	 */
	private static function handlerMatches( array $entry, string $handler ): bool {
		$metadata = is_array( $entry['metadata'] ?? null ) ? $entry['metadata'] : array();

		foreach ( array( 'handler_tool', 'tool_name' ) as $key ) {
			if ( $handler === (string) ( $metadata[ $key ] ?? '' ) ) {
				return true;
			}
		}

		$envelope = self::toolResultEnvelope( $entry );
		foreach ( array( 'handler_tool', 'tool_name' ) as $key ) {
			if ( $handler === (string) ( $envelope[ $key ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether the packet represents a successful tool execution.
	 *
	 * @param array $entry Data packet.
	 * @return bool
	 */
	private static function toolSucceeded( array $entry ): bool {
		$metadata = is_array( $entry['metadata'] ?? null ) ? $entry['metadata'] : array();
		if ( array_key_exists( 'tool_success', $metadata ) ) {
			return true === $metadata['tool_success'];
		}

		$envelope = self::toolResultEnvelope( $entry );
		if ( array_key_exists( 'success', $envelope ) ) {
			return true === $envelope['success'];
		}

		return 'ai_handler_complete' === ( $entry['type'] ?? '' );
	}

	/**
	 * Read the normalized tool result envelope from canonical or legacy metadata.
	 *
	 * @param array $entry Data packet.
	 * @return array<string,mixed>
	 */
	private static function toolResultEnvelope( array $entry ): array {
		$metadata = is_array( $entry['metadata'] ?? null ) ? $entry['metadata'] : array();

		if ( is_array( $metadata['tool_result_envelope'] ?? null ) ) {
			return $metadata['tool_result_envelope'];
		}

		if ( 'envelope' === ( $metadata['tool_result_shape'] ?? '' ) && is_array( $metadata['tool_result'] ?? null ) ) {
			return $metadata['tool_result'];
		}

		return array();
	}
}
