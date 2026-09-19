<?php
/**
 * Agent Memory Validator Interface
 *
 * Generic contract for re-checking memory against a current workspace
 * substrate, such as options, theme settings, source content, repo state,
 * documents, channels, or other caller-owned facts.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Memory_Validator {

	/**
	 * Stable validator identifier stored in WP_Agent_Memory_Metadata::$validator.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Re-check a memory record against the caller-provided workspace context.
	 *
	 * @param WP_Agent_Memory_Scope    $scope             Memory identity.
	 * @param string              $content           Memory content.
	 * @param WP_Agent_Memory_Metadata $metadata          Memory metadata.
	 * @param array<string,mixed> $workspace_context Current substrate facts supplied by the consumer.
	 * @return WP_Agent_Memory_Validation_Result
	 */
	public function validate( WP_Agent_Memory_Scope $scope, string $content, WP_Agent_Memory_Metadata $metadata, array $workspace_context = array() ): WP_Agent_Memory_Validation_Result;
}
