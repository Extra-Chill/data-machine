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
	 * @return array|null Tool result entry or null if no match found
	 */
	public static function findHandlerResult( array $dataPackets, string $handler, string $flow_step_id ): ?array {
		foreach ( $dataPackets as $entry ) {
			$entry_type = $entry['type'] ?? '';

			// Only match successful handler completions.
			// 'ai_handler_complete' entries are already filtered for success during creation.
			// 'tool_result' entries must be checked for tool_success to avoid treating
			// failed tool calls as successful publish completions.
			if ( 'ai_handler_complete' === $entry_type ) {
				$handler_tool = $entry['metadata']['handler_tool'] ?? '';
				if ( $handler_tool === $handler ) {
					return $entry;
				}
			}

			if ( 'tool_result' === $entry_type ) {
				$handler_tool = $entry['metadata']['handler_tool'] ?? '';
				$tool_success = $entry['metadata']['tool_success'] ?? false;
				if ( $handler_tool === $handler && $tool_success ) {
					return $entry;
				}
			}
		}

		// Log error when not found
		do_action(
			'datamachine_log',
			'error',
			'AI did not execute handler tool',
			array(
				'handler'      => $handler,
				'flow_step_id' => $flow_step_id,
			)
		);

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
				$entry_type   = $entry['type'] ?? '';
				$handler_tool = $entry['metadata']['handler_tool'] ?? '';

				if ( 'ai_handler_complete' === $entry_type && $handler_tool === $slug ) {
					$results[] = $entry;
				} elseif ( 'tool_result' === $entry_type && $handler_tool === $slug && ( $entry['metadata']['tool_success'] ?? false ) ) {
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
}
