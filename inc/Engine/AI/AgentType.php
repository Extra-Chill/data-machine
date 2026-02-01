<?php
/**
 * Agent Type Registry
 *
 * Defines agent type constants and provides a filterable registry
 * for discovering available agent types throughout the system.
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
	 * Get all registered agent types.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function getAll(): array {
		return apply_filters(
			'datamachine_agent_types',
			array(
				self::PIPELINE => array(
					'label'       => __( 'Pipeline', 'data-machine' ),
					'description' => __( 'Automated workflow execution', 'data-machine' ),
				),
				self::CHAT     => array(
					'label'       => __( 'Chat', 'data-machine' ),
					'description' => __( 'Conversational interface', 'data-machine' ),
				),
				self::SYSTEM   => array(
					'label'       => __( 'System', 'data-machine' ),
					'description' => __( 'Infrastructure and system operations', 'data-machine' ),
				),
			)
		);
	}

	/**
	 * Check if a given agent type is valid.
	 *
	 * @param string $type Agent type to validate
	 * @return bool
	 */
	public static function isValid( string $type ): bool {
		return array_key_exists( $type, self::getAll() );
	}

	/**
	 * Get the log filename for a given agent type.
	 *
	 * @param string $type Agent type
	 * @return string Filename without path (e.g., 'datamachine-pipeline.log')
	 */
	public static function getLogFilename( string $type ): string {
		if ( ! self::isValid( $type ) ) {
			$type = self::PIPELINE;
		}
		return "datamachine-{$type}.log";
	}
}
