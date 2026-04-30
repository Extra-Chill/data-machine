<?php
/**
 * Agent Memory Store Factory
 *
 * Resolves the active {@see AgentMemoryStoreInterface} implementation.
 *
 * This is Data Machine's in-place resolver for a generic agent-memory
 * persistence contract. It intentionally keeps one public swap point using
 * Agents API vocabulary: the `agents_api_memory_store` filter. The previous
 * `datamachine_memory_store` name is not mirrored at runtime; consumers should
 * migrate to the new hook instead of relying on a permanent alias.
 *
 * Single resolution point so every Data Machine consumer (AgentMemory, DailyMemory,
 * AgentFileAbilities, CoreMemoryFilesDirective) gets the same swap mechanism
 * without duplicating the filter call.
 *
 * A guideline-backed store can register here when a site provides
 * `wp_guideline` (for example via Gutenberg or a plugin), but Data Machine
 * does not require that post type. The built-in disk store remains the
 * default and any implementation of AgentMemoryStoreInterface is valid.
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\AgentMemoryScope;
use AgentsAPI\Core\FilesRepository\AgentMemoryStoreInterface;

defined( 'ABSPATH' ) || exit;

class AgentMemoryStoreFactory {

	/**
	 * Resolve the store for a given scope.
	 *
	 * The filter receives a null default and the scope being acted on,
	 * letting consumers route different scopes to different backends if
	 * they ever want to. Most consumers will return a single store
	 * instance regardless of scope.
	 *
	 * @param AgentMemoryScope $scope Scope the caller is about to operate on.
	 * @return AgentMemoryStoreInterface
	 */
	public static function for_scope( AgentMemoryScope $scope ): AgentMemoryStoreInterface {
		/**
		 * Filter: swap the agent memory persistence backend.
		 *
		 * Return an {@see AgentMemoryStoreInterface} instance to short-circuit
		 * the default disk-backed store. Return null (the default) to use the
		 * built-in {@see DiskAgentMemoryStore}.
		 *
		 * This is the only runtime filter for memory-store replacement. It replaces
		 * the earlier `datamachine_memory_store` name in-place so the seam already
		 * uses Agents API vocabulary before physical extraction. Data Machine does
		 * not call both names because a dual-hook ladder would become permanent API.
		 *
		 * The disk default preserves byte-for-byte the behavior Data Machine
		 * had before this seam was introduced — self-hosted users see no
		 * change. Managed-host consumers (e.g. Intelligence on WordPress.com)
		 * can register a DB-backed implementation.
		 *
		 * @since next
		 *
		 * @param AgentMemoryStoreInterface|null $store Null to use the disk default,
		 *                                              or a swap implementation.
		 * @param AgentMemoryScope               $scope The scope being acted on.
		 */
		// @phpstan-ignore-next-line WordPress apply_filters accepts additional hook arguments.
		$store = apply_filters( 'agents_api_memory_store', null, $scope );

		if ( $store instanceof AgentMemoryStoreInterface ) {
			return $store;
		}

		return new DiskAgentMemoryStore();
	}
}
