<?php
/**
 * Shared bundle artifact definitions.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Central definition map for first-class bundle artifact files.
 */
final class AgentBundleArtifactDefinitions {

	/**
	 * Bundle file artifact definitions keyed by bundle directory.
	 *
	 * @return array<string,array{artifact_type:string,package_type:string}>
	 */
	public static function file_artifacts(): array {
		return array(
			BundleSchema::PROMPTS_DIR       => array(
				'artifact_type' => 'prompt',
				'package_type'  => 'datamachine/prompt',
			),
			BundleSchema::RUBRICS_DIR       => array(
				'artifact_type' => 'rubric',
				'package_type'  => 'datamachine/rubric',
			),
			BundleSchema::TOOL_POLICIES_DIR => array(
				'artifact_type' => 'tool_policy',
				'package_type'  => 'datamachine/tool-policy',
			),
			BundleSchema::AUTH_REFS_DIR     => array(
				'artifact_type' => 'auth_ref',
				'package_type'  => 'datamachine/auth-ref',
			),
			BundleSchema::SEED_QUEUES_DIR   => array(
				'artifact_type' => 'seed_queue',
				'package_type'  => 'datamachine/queue-seed',
			),
		);
	}

	/** @return string[] */
	public static function file_artifact_directories(): array {
		return array_keys( self::file_artifacts() );
	}

	/** @return string[] */
	public static function file_artifact_types(): array {
		return array_values( array_map( static fn( array $definition ): string => $definition['artifact_type'], self::file_artifacts() ) );
	}

	/**
	 * Return the decoded file map for a first-class artifact directory.
	 *
	 * @return array<string,array|string>
	 */
	public static function files_from_directory( AgentBundleDirectory $directory, string $artifact_directory ): array {
		return match ( $artifact_directory ) {
			BundleSchema::PROMPTS_DIR       => $directory->prompts(),
			BundleSchema::RUBRICS_DIR       => $directory->rubrics(),
			BundleSchema::TOOL_POLICIES_DIR => $directory->tool_policies(),
			BundleSchema::AUTH_REFS_DIR     => $directory->auth_refs(),
			BundleSchema::SEED_QUEUES_DIR   => $directory->seed_queues(),
			default                         => array(),
		};
	}
}
