<?php
/**
 * Agent Memory Store Factory
 *
 * Resolves the active {@see AgentMemoryStoreInterface} implementation.
 *
 * This is Data Machine's current resolver for a generic agent-memory
 * persistence contract. It intentionally keeps one public swap point: the
 * existing `datamachine_memory_store` filter, until an Agents API extraction
 * can introduce its own vocabulary and migration path. No caller should branch
 * on the concrete backend.
 *
 * Single resolution point so every consumer (AgentMemory, DailyMemory,
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
		 * This remains the only runtime filter in Data Machine. A future extracted
		 * Agents API can add a neutral filter name when it owns the resolver; adding
		 * a second alias here would create a permanent compatibility surface without
		 * moving ownership.
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
		$store = apply_filters( 'datamachine_memory_store', null, $scope );

		if ( $store instanceof AgentMemoryStoreInterface ) {
			return $store;
		}

		return new DiskAgentMemoryStore();
	}
}
