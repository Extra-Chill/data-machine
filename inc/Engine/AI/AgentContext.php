<?php
/**
 * Agent Context
 *
 * Runtime tracking of the current execution context.
 * Used by the logging system to route logs to the correct file
 * when agent_type is not explicitly passed in log context.
 *
 * @package DataMachine\Engine\AI
 * @since 0.7.2
 */

namespace DataMachine\Engine\AI;

if ( ! defined( 'WPINC' ) ) {
	die;
}

final class AgentContext {

	private static ?string $currentAgentType = null;

	/**
	 * Set the current execution context.
	 *
	 * @param string $agentType Context identifier (use AgentType constants)
	 */
	public static function set( string $agentType ): void {
		self::$currentAgentType = $agentType;
	}

	/**
	 * Get the current execution context.
	 *
	 * @return string|null Current context or null if not set
	 */
	public static function get(): ?string {
		return self::$currentAgentType;
	}

	/**
	 * Clear the current execution context.
	 */
	public static function clear(): void {
		self::$currentAgentType = null;
	}
}
