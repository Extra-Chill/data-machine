<?php
/**
 * Core Memory Files Directive - Priority 20
 *
 * Loads memory files from the MemoryFileRegistry and injects them into
 * every AI call. Files are resolved to their layer directories:
 *   shared → agents/{slug} → users/{id} → network/
 *
 * The registry is the single source of truth for which files exist,
 * what layer they belong to, and what order they load in.
 *
 * Priority Order in Directive System:
 * 1. Priority 10 - Plugin Core Directive (agent identity)
 * 2. Priority 20 - Core Memory Files (THIS CLASS)
 * 3. Priority 40 - Pipeline Memory Files (per-pipeline selectable)
 * 4. Priority 50 - Pipeline System Prompt (pipeline instructions)
 * 5. Priority 60 - Pipeline Context Files
 * 6. Priority 70 - Tool Definitions (available tools and workflow)
 * 7. Priority 80 - Site Context (WordPress metadata)
 *
 * @package DataMachine\Engine\AI\Directives
 * @since   0.30.0
 * @since   0.42.0 Driven entirely by MemoryFileRegistry with layer resolution.
 */

namespace DataMachine\Engine\AI\Directives;

use AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind;
use AgentsAPI\AI\Context\WP_Agent_Default_Context_Conflict_Resolver;
use AgentsAPI\AI\Context\WP_Agent_Context_Item;
use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\AI\ComposableFileGenerator;
use DataMachine\Engine\AI\Memory\MemoryPolicyResolver;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class CoreMemoryFilesDirective implements DirectiveInterface {

	/**
	 * Get directive outputs for all registered memory files.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Available tools.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Additional payload data.
	 * @return array Directive outputs.
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		// Self-heal: ensure agent files exist before reading.
		DirectoryManager::ensure_agent_files();

		$directory_manager = new DirectoryManager();
		$user_id           = $directory_manager->get_effective_user_id( (int) ( $payload['user_id'] ?? 0 ) );
		$agent_id          = (int) ( $payload['agent_id'] ?? 0 );

		// Auto-scaffold missing user-layer files (e.g. USER.md) on first chat.
		$scaffold_ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $user_id > 0 && $scaffold_ability ) {
			$scaffold_ability->execute(
				array(
					'layer'   => MemoryFileRegistry::LAYER_USER,
					'user_id' => $user_id,
				)
			);
		}

		$items = array();

		// Load registered files applicable to the current agent modes,
		// filtered through the per-agent MemoryPolicy.
		$modes      = self::normalizeModes( $payload['agent_modes'] ?? array() );
		$resolver   = new MemoryPolicyResolver();
		$mode_files = $resolver->resolveRegistered(
			array(
				'modes'    => $modes,
				'agent_id' => $agent_id,
			)
		);

		foreach ( $mode_files as $filename => $meta ) {
			$layer  = $meta['layer'] ?? MemoryFileRegistry::LAYER_AGENT;
			$memory = new AgentMemory( $user_id, $agent_id, $filename, $layer );
			$read   = $memory->read();

			if ( ! $read->exists && self::maybe_regenerate_composable_file( $filename, $meta, $user_id, $agent_id ) ) {
				$read = $memory->read();
			}

			if ( ! $read->exists ) {
				continue;
			}

			$content = self::normalize_for_injection( $read->content, $read->bytes, $filename, $read->updated_at );
			if ( null === $content ) {
				continue;
			}

			$items[] = new WP_Agent_Context_Item(
				$content,
				array(
					'filename' => $filename,
					'layer'    => $layer,
				),
				$meta['authority_tier'] ?? MemoryFileRegistry::get( $filename )['authority_tier'] ?? \AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::AGENT_MEMORY,
				$meta['provenance'] ?? array( 'source_ref' => $filename ),
				$meta['conflict_kind'] ?? WP_Agent_Context_Conflict_Kind::AUTHORITATIVE_FACT,
				is_string( $meta['conflict_key'] ?? null ) ? $meta['conflict_key'] : null,
				array(
					'priority' => (int) ( $meta['priority'] ?? 50 ),
				)
			);
		}

		return self::items_to_outputs( self::resolve_context_conflicts( $items, $payload ) );
	}

	/**
	 * Regenerate a missing composable file just-in-time for prompt injection.
	 *
	 * Ephemeral runtimes can miss the normal invalidation hooks that write files
	 * like SITE.md to disk. A first-read repair keeps composable context available
	 * without regenerating existing files on every AI call.
	 *
	 * @param string $filename Memory filename.
	 * @param array  $meta     Memory registry metadata.
	 * @param int    $user_id  Effective user ID.
	 * @param int    $agent_id Agent ID.
	 * @return bool True when regeneration succeeded and callers should retry the read.
	 */
	private static function maybe_regenerate_composable_file( string $filename, array $meta, int $user_id, int $agent_id ): bool {
		if ( empty( $meta['composable'] ) || ! class_exists( ComposableFileGenerator::class ) ) {
			return false;
		}

		$result = ComposableFileGenerator::regenerate(
			$filename,
			array(
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		return ! empty( $result['success'] );
	}

	/**
	 * Apply the Agents API authority cascade to retrieved memory context items.
	 *
	 * Items without a conflict key are never removed. Items sharing a key are
	 * resolved by the generic conflict resolver, so product/support/workspace
	 * authority can beat lower-scope agent memory when both assert the same fact.
	 *
	 * @param WP_Agent_Context_Item[] $items   Retrieved memory context items.
	 * @param array                  $payload Runtime payload.
	 * @return WP_Agent_Context_Item[] Items that should be injected, preserving original order.
	 */
	private static function resolve_context_conflicts( array $items, array $payload ): array {
		$items = apply_filters( 'datamachine_retrieved_memory_context_items', $items, $payload );
		$items = array_values( array_filter( $items, static fn( $item ): bool => $item instanceof WP_Agent_Context_Item ) );

		$resolver    = apply_filters( 'datamachine_context_conflict_resolver', new WP_Agent_Default_Context_Conflict_Resolver(), $payload );
		$resolutions = $resolver instanceof \AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Resolver
			? $resolver->resolve( $items, $payload )
			: array();

		$rejected = array();
		foreach ( $resolutions as $resolution ) {
			foreach ( $resolution->rejected_items as $item ) {
				$rejected[] = spl_object_id( $item );
			}
		}

		return array_values(
			array_filter(
				$items,
				static fn( WP_Agent_Context_Item $item ): bool => ! in_array( spl_object_id( $item ), $rejected, true )
			)
		);
	}

	/**
	 * @param WP_Agent_Context_Item[] $items Context items to inject.
	 * @return array<int, array{type: string, content: string}>
	 */
	private static function items_to_outputs( array $items ): array {
		return array_map(
			static fn( WP_Agent_Context_Item $item ): array => array(
				'type'    => 'system_text',
				'content' => $item->content,
			),
			$items
		);
	}

	/**
	 * Normalize file content for context injection.
	 *
	 * Logs a size-budget warning, runs the `datamachine_memory_file_content`
	 * filter, and trims. Returns null when the content is effectively
	 * empty so callers can skip the directive entirely.
	 *
	 * @since next  Renamed from get_file_content_for_output and switched
	 *              to operate on already-read content from the store.
	 *
	 * @param string $content  Raw file content (already loaded by caller).
	 * @param int    $bytes    Content length in bytes (already known by caller).
	 * @param string $filename Filename for logs and the content filter.
	 * @param int|null $updated_at File mtime (Unix timestamp) or null when unknown.
	 * @return string|null
	 */
	private static function normalize_for_injection( string $content, int $bytes, string $filename, ?int $updated_at = null ): ?string {
		if ( $bytes > AgentMemory::MAX_FILE_SIZE ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf(
					'Memory file %s exceeds recommended size for context injection: %s (threshold %s)',
					$filename,
					size_format( $bytes ),
					size_format( AgentMemory::MAX_FILE_SIZE )
				),
				array(
					'filename' => $filename,
					'size'     => $bytes,
					'max'      => AgentMemory::MAX_FILE_SIZE,
				)
			);
		}

		if ( empty( trim( $content ) ) ) {
			return null;
		}

		$content = trim( $content );

		/**
		 * Filter memory file content at read time.
		 *
		 * Allows plugins to contribute to or modify ANY memory file
		 * before it is injected into the AI context. Fires for every
		 * file read, not just composable ones.
		 *
		 * @since 0.66.0
		 * @since next  Added $updated_at (file mtime) as 4th param.
		 *
		 * @param string     $content    File content.
		 * @param string     $filename   Filename (e.g. 'SOUL.md', 'MEMORY.md').
		 * @param array|null $meta       Registry metadata, or null if unregistered.
		 * @param int|null   $updated_at File mtime (Unix timestamp) or null.
		 */
		$content = apply_filters(
			'datamachine_memory_file_content',
			$content,
			$filename,
			MemoryFileRegistry::get( $filename ),
			$updated_at
		);

		if ( empty( trim( $content ) ) ) {
			return null;
		}

		return trim( $content );
	}

	/** @return array<int,string> */
	private static function normalizeModes( mixed $modes ): array {
		if ( is_string( $modes ) ) {
			$modes = array( $modes );
		}
		if ( ! is_array( $modes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $modes as $mode ) {
			if ( ! is_scalar( $mode ) ) {
				continue;
			}
			$mode = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $mode ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $mode ) ?? '' );
			if ( '' !== $mode ) {
				$normalized[] = $mode;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}

// Self-register in the directive system (Priority 20 = core memory files for all AI agents).
add_filter(
	'datamachine_directives',
	function ( $directives ) {
		$directives[] = array(
			'class'    => CoreMemoryFilesDirective::class,
			'priority' => 20,
			'modes'    => array( 'all' ),
		);
		return $directives;
	}
);
