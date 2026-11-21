<?php
/**
 * Agent Memory Store Interface
 *
 * Generic persistence contract for agent memory. The contract deliberately
 * describes agent-memory storage: not flows, pipelines, jobs, abilities,
 * scaffolding, or prompt injection.
 *
 * Implementations are responsible for:
 * - translating an {@see WP_Agent_Memory_Scope} to a physical key (path, row, URL);
 * - returning a stable content hash so callers can implement
 *   compare-and-swap concurrency via the `if_match` write parameter;
	 * - honoring the layer + workspace_type + workspace_id + user_id + agent_id
	 *   + filename identity model;
	 * - declaring which provenance, confidence, validator, and authority
	 *   metadata fields it can persist/read/filter/rank.
 *
 * Section parsing, scaffold/default-file creation, editability gating,
 * ability permissions, prompt-injection policy, and registry-driven
 * convention-path semantics all stay in higher-level callers. The store is
 * the persistence layer underneath.
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Memory_Store {

	/**
	 * Declare memory metadata support for this store.
	 *
	 * @return WP_Agent_Memory_Store_Capabilities
	 */
	public function capabilities(): WP_Agent_Memory_Store_Capabilities;

	/**
	 * Read the full content of the file identified by $scope.
	 *
	 * @param WP_Agent_Memory_Scope $scope           Identifies the target file.
	 * @param string[]         $metadata_fields Metadata fields the caller wants returned.
	 * @return WP_Agent_Memory_Read_Result Returns ::not_found() when the file does not exist.
	 */
	public function read( WP_Agent_Memory_Scope $scope, array $metadata_fields = WP_Agent_Memory_Metadata::FIELDS ): WP_Agent_Memory_Read_Result;

	/**
	 * Write the full content of the file identified by $scope.
	 *
	 * Implementations that support concurrency MUST honor $if_match: when
	 * non-null, the write succeeds only if the current stored content has
	 * a matching hash. On hash mismatch, return a failure result with
	 * error = 'conflict'.
	 *
	 * Implementations without concurrency support MAY ignore $if_match.
	 *
	 * @param WP_Agent_Memory_Scope         $scope    Identifies the target file.
	 * @param string                   $content  Full content to persist.
	 * @param string|null              $if_match Optional content hash for compare-and-swap.
	 * @param WP_Agent_Memory_Metadata|null $metadata Optional provenance/trust metadata to persist.
	 * @return WP_Agent_Memory_Write_Result
	 */
	public function write( WP_Agent_Memory_Scope $scope, string $content, ?string $if_match = null, ?WP_Agent_Memory_Metadata $metadata = null ): WP_Agent_Memory_Write_Result;

	/**
	 * Check whether the file identified by $scope exists in the store.
	 *
	 * @param WP_Agent_Memory_Scope $scope Identifies the target file.
	 * @return bool
	 */
	public function exists( WP_Agent_Memory_Scope $scope ): bool;

	/**
	 * Delete the file identified by $scope. Idempotent: a delete on a
	 * non-existent file returns success.
	 *
	 * @param WP_Agent_Memory_Scope $scope Identifies the target file.
	 * @return WP_Agent_Memory_Write_Result
	 */
	public function delete( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Write_Result;

	/**
	 * List all top-level files in a single layer for the given identity.
	 *
	 * The $scope_query's `filename` field is ignored — list operations
	 * return all files matching `(layer, workspace_type, workspace_id, user_id, agent_id)`. The
	 * `layer` field is required.
	 *
	 * Top-level only: subdirectories under the layer (e.g. `daily/`,
	 * `contexts/`) are NOT recursed into. Use {@see self::list_subtree()}
	 * to enumerate those.
	 *
	 * @param WP_Agent_Memory_Scope      $scope_query Layer + identity to enumerate.
	 * @param WP_Agent_Memory_Query|null $query       Optional metadata filters/ranking hints.
	 * @return WP_Agent_Memory_List_Entry[]
	 */
	public function list_layer( WP_Agent_Memory_Scope $scope_query, ?WP_Agent_Memory_Query $query = null ): array;

	/**
	 * List all files under a path prefix within a layer.
	 *
	 * Recursive — descends into all subdirectories beneath the prefix.
	 * Filenames in the returned entries include the full relative path
	 * (e.g. when listing prefix `daily`, an entry's `filename` is
	 * `daily/2026/04/17.md`, not `2026/04/17.md`).
	 *
	 * Used for path-namespaced file families like daily memory
	 * (`daily/YYYY/MM/DD.md`) and context files (`contexts/<slug>.md`).
	 *
	 * @since next
	 *
	 * @param WP_Agent_Memory_Scope      $scope_query Layer + identity. `filename` is ignored.
	 * @param string                $prefix      Path prefix without trailing slash
	 *                                           (e.g. 'daily', 'contexts'). Required.
	 * @param WP_Agent_Memory_Query|null $query       Optional metadata filters/ranking hints.
	 * @return WP_Agent_Memory_List_Entry[]
	 */
	public function list_subtree( WP_Agent_Memory_Scope $scope_query, string $prefix, ?WP_Agent_Memory_Query $query = null ): array;
}
