<?php
/**
 * Agent Memory Store Factory
 *
 * Resolves the active {@see WP_Agent_Memory_Store} implementation.
 *
 * Data Machine delegates host-provided store discovery to the canonical
 * Agents API resolver/filter and falls back to the built-in disk store when
 * no host store is available.
 *
 * Single resolution point so every Data Machine consumer (AgentMemory, DailyMemory,
 * AgentFileAbilities, CoreMemoryFilesDirective) gets the same swap mechanism
 * without duplicating the filter call.
 *
 * A guideline-backed store can register here when a site provides
 * `wp_guideline` (for example via Gutenberg or a plugin), but Data Machine
 * does not require that post type. The built-in disk store remains the
 * default and any implementation of WP_Agent_Memory_Store is valid.
 *
 * @package DataMachine\Core\FilesRepository
 * @since   next
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Stores;

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
	 * @param WP_Agent_Memory_Scope $scope Scope the caller is about to operate on.
	 * @return WP_Agent_Memory_Store
	 */
	public static function for_scope( WP_Agent_Memory_Scope $scope ): WP_Agent_Memory_Store {
		$store = WP_Agent_Memory_Stores::get_store(
			array(
				'scope' => $scope,
			)
		);

		if ( $store instanceof WP_Agent_Memory_Store ) {
			return $store;
		}

		return new DiskAgentMemoryStore();
	}
}
