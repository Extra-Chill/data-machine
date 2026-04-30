<?php
/**
 * Agent Memory Store Interface
 *
 * Generic persistence contract for agent memory. The contract deliberately
 * describes agent-memory storage: not flows, pipelines, jobs, abilities,
 * scaffolding, or prompt injection.
 *
 * Implementations are responsible for:
 * - translating an {@see AgentMemoryScope} to a physical key (path, row, URL);
 * - returning a stable content hash so callers can implement
 *   compare-and-swap concurrency via the `if_match` write parameter;
 * - honoring the layer + user_id + agent_id + filename four-tuple as the
 *   identity model.
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

interface AgentMemoryStoreInterface {

	/**
	 * Read the full content of the file identified by $scope.
	 *
	 * @param AgentMemoryScope $scope Identifies the target file.
	 * @return AgentMemoryReadResult Returns ::not_found() when the file does not exist.
	 */
	public function read( AgentMemoryScope $scope ): AgentMemoryReadResult;

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
	 * @param AgentMemoryScope $scope    Identifies the target file.
	 * @param string           $content  Full content to persist.
	 * @param string|null      $if_match Optional content hash for compare-and-swap.
	 * @return AgentMemoryWriteResult
	 */
	public function write( AgentMemoryScope $scope, string $content, ?string $if_match = null ): AgentMemoryWriteResult;

	/**
	 * Check whether the file identified by $scope exists in the store.
	 *
	 * @param AgentMemoryScope $scope Identifies the target file.
	 * @return bool
	 */
	public function exists( AgentMemoryScope $scope ): bool;

	/**
	 * Delete the file identified by $scope. Idempotent: a delete on a
	 * non-existent file returns success.
	 *
	 * @param AgentMemoryScope $scope Identifies the target file.
	 * @return AgentMemoryWriteResult
	 */
	public function delete( AgentMemoryScope $scope ): AgentMemoryWriteResult;

	/**
	 * List all top-level files in a single layer for the given identity.
	 *
	 * The $scope_query's `filename` field is ignored — list operations
	 * return all files matching `(layer, user_id, agent_id)`. The
	 * `layer` field is required.
	 *
	 * Top-level only: subdirectories under the layer (e.g. `daily/`,
	 * `contexts/`) are NOT recursed into. Use {@see self::list_subtree()}
	 * to enumerate those.
	 *
	 * @param AgentMemoryScope $scope_query Layer + identity to enumerate.
	 * @return AgentMemoryListEntry[]
	 */
	public function list_layer( AgentMemoryScope $scope_query ): array;

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
	 * @param AgentMemoryScope $scope_query Layer + identity. `filename` is ignored.
	 * @param string           $prefix      Path prefix without trailing slash
	 *                                      (e.g. 'daily', 'contexts'). Required.
	 * @return AgentMemoryListEntry[]
	 */
	public function list_subtree( AgentMemoryScope $scope_query, string $prefix ): array;
}
