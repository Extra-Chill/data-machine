<?php
/**
 * Agent Context Registry
 *
 * Defines context constants and provides a filterable registry
 * for discovering available execution contexts throughout the system.
 *
 * @package DataMachine\Engine\AI
 * @since 0.7.2
 */

namespace DataMachine\Engine\AI;

if ( ! defined( 'WPINC' ) ) {
	die;
}

final class AgentType {

	public const PIPELINE = 'pipeline';
	public const CHAT     = 'chat';
	public const SYSTEM   = 'system';
	public const ALL      = 'all';

	/**
	 * Get all registered execution contexts.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function getAll(): array {
		return apply_filters(
			'datamachine_agent_types',
			array(
				self::PIPELINE => array(
					'label'       => __( 'Pipeline Context', 'data-machine' ),
					'description' => __( 'Automated workflow execution context', 'data-machine' ),
				),
				self::CHAT     => array(
					'label'       => __( 'Chat Context', 'data-machine' ),
					'description' => __( 'Conversational execution context', 'data-machine' ),
				),
				self::SYSTEM   => array(
					'label'       => __( 'System Context', 'data-machine' ),
					'description' => __( 'Infrastructure and background operations context', 'data-machine' ),
				),
			)
		);
	}

	/**
	 * Check if a given context is valid.
	 *
	 * @param string $type Context to validate
	 * @return bool
	 */
	public static function isValid( string $type ): bool {
		return array_key_exists( $type, self::getAll() );
	}

	/**
	 * Get the log filename for a given context.
	 *
	 * @param string $type Context identifier
	 * @return string Filename without path (e.g., 'datamachine-pipeline.log')
	 */
	public static function getLogFilename( string $type ): string {
		if ( ! self::isValid( $type ) ) {
			$type = self::PIPELINE;
		}
		return "datamachine-{$type}.log";
	}
}
