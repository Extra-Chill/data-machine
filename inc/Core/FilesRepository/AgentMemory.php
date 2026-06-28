<?php
/**
 * Agent Memory Service
 *
 * Provides structured read/write operations for agent memory files.
 * Parses markdown sections and supports section-level operations
 * on any agent file (MEMORY.md, SOUL.md, USER.md, etc.).
 *
 * Persistence is delegated to an {@see WP_Agent_Memory_Store} resolved
 * via the canonical Agents API memory store resolver. The default store
 * ({@see DiskAgentMemoryStore}) preserves the byte-for-byte filesystem
 * behavior the codebase used before the store seam was introduced.
 *
 * @package DataMachine\Core\FilesRepository
 * @since 0.30.0
 * @since 0.45.0 Generalized to support any agent file via $filename parameter.
 * @since next   Whole-file IO delegated to WP_Agent_Memory_Store.
 */

namespace DataMachine\Core\FilesRepository;

use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_List_Entry;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Read_Result;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store;
use AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Write_Result;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

class AgentMemory {

	private const SECTION_BODY_START = '<!-- datamachine-section-body -->';
	private const SECTION_BODY_END   = '<!-- /datamachine-section-body -->';

	/**
	 * Recommended maximum file size for agent memory files (8KB ≈ 2K tokens).
	 *
	 * Writes are not blocked when exceeded — a warning is logged and returned
	 * so the agent and admin are aware of context window budget impact.
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 8192;

	/**
	 * @var DirectoryManager
	 */
	private DirectoryManager $directory_manager;

	/**
	 * @var WP_Agent_Memory_Scope
	 */
	private WP_Agent_Memory_Scope $scope;

	/**
	 * @var WP_Agent_Memory_Store
	 */
	private WP_Agent_Memory_Store $store;

	/**
	 * @since 0.37.0 Added $user_id parameter for multi-agent partitioning.
	 * @since 0.41.0 Added $agent_id parameter for agent-first resolution.
	 * @since 0.45.0 Added $filename parameter for any-file support.
	 * @since next   Switched whole-file IO to WP_Agent_Memory_Store.
	 * @since next   Optional $layer override for explicit-layer addressing.
	 *
	 * @param int         $user_id  WordPress user ID. 0 = legacy shared directory.
	 * @param int         $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 * @param string      $filename Target filename. Defaults to MEMORY.md for backwards compatibility.
	 * @param string|null $layer    Optional explicit layer. When null, resolved from the registry
	 *                              (agent layer for unregistered files).
	 */
	public function __construct( int $user_id = 0, int $agent_id = 0, string $filename = 'MEMORY.md', ?string $layer = null ) {
		$this->directory_manager = new DirectoryManager();
		$effective_user_id       = $this->directory_manager->get_effective_user_id( $user_id );
		$safe_filename           = $this->sanitize_filename( $filename );
		$workspace               = WordPressWorkspaceScope::current();

		$this->scope = new WP_Agent_Memory_Scope(
			$layer ?? self::resolve_layer_for( $safe_filename ),
			$workspace->workspace_type,
			$workspace->workspace_id,
			$effective_user_id,
			$agent_id,
			$safe_filename
		);
		$this->store = AgentMemoryStoreFactory::for_scope( $this->scope );

		// Self-heal: ensure agent files exist on first use.
		DirectoryManager::ensure_agent_files();
	}

	/**
	 * Resolve which layer a filename belongs to via the registry,
	 * defaulting to the agent layer for unregistered files.
	 *
	 * @since next  Static so other consumers can reuse the resolution.
	 */
	public static function resolve_layer_for( string $filename ): string {
		$registered = MemoryFileRegistry::get_layer( $filename );
		return $registered ?? MemoryFileRegistry::LAYER_AGENT;
	}

	/**
	 * Get the resolved scope for this memory file.
	 *
	 * @since next
	 * @return WP_Agent_Memory_Scope
	 */
	public function get_scope(): WP_Agent_Memory_Scope {
		return $this->scope;
	}

	/**
	 * Get the full path to the target file (disk-only convenience).
	 *
	 * Preserved for backward compatibility with callers that still need
	 * to reason about an on-disk path. The path is computed from the
	 * registry + DirectoryManager regardless of which store is active —
	 * useful for self-hosted CLI/debug inspectors. Non-disk stores
	 * persist content elsewhere; the returned path may not exist in
	 * those environments. Prefer the store interface for new callers.
	 *
	 * @return string
	 */
	public function get_file_path(): string {
		$layer_dir = $this->resolve_layer_directory();
		return MemoryFileRegistry::resolve_filepath( $this->scope->filename, $layer_dir )
			?? $layer_dir . '/' . $this->scope->filename;
	}

	/**
	 * Get the target filename.
	 *
	 * @since 0.45.0
	 * @return string
	 */
	public function get_filename(): string {
		return $this->scope->filename;
	}

	/**
	 * Read the full file content.
	 *
	 * @return array{success: bool, content?: string, file?: string, message?: string}
	 */
	public function get_all(): array {
		$result = $this->store->read( $this->scope );

		if ( ! $result->exists ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File %s does not exist.', $this->scope->filename ),
			);
		}

		return array(
			'success' => true,
			'file'    => $this->scope->filename,
			'content' => $result->content,
		);
	}

	/**
	 * Low-level read returning the raw store result.
	 *
	 * Exposes the underlying {@see WP_Agent_Memory_Read_Result} (content, hash,
	 * bytes, updated_at, exists) for consumers that need richer metadata
	 * than {@see self::get_all()}'s human-shaped response — e.g. directives
	 * that want byte counts for size budgeting, the React UI's whole-file
	 * GET that needs modified-at, or anything that wants the content hash
	 * for compare-and-swap upstream.
	 *
	 * @since next
	 * @return WP_Agent_Memory_Read_Result
	 */
	public function read(): WP_Agent_Memory_Read_Result {
		return $this->store->read( $this->scope );
	}

	/**
	 * Whether the underlying file exists in the store.
	 *
	 * @since next
	 * @return bool
	 */
	public function exists(): bool {
		return $this->store->exists( $this->scope );
	}

	/**
	 * Delete this file from the store. Idempotent — deleting a missing
	 * file returns success.
	 *
	 * @since next
	 * @return array{success: bool, message: string}
	 */
	public function delete(): array {
		$result = $this->store->delete( $this->scope );

		if ( ! $result->success ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to delete %s (%s).', $this->scope->filename, $result->error ?? 'unknown' ),
			);
		}

		$this->emit_deleted_event();

		return array(
			'success' => true,
			'message' => sprintf( '%s deleted.', $this->scope->filename ),
		);
	}

	/**
	 * List all files in a single layer for the given identity.
	 *
	 * Static facade over {@see WP_Agent_Memory_Store::list_layer()}
	 * so directory enumeration goes through the same swap point as
	 * single-file IO. Callers receive a list of {@see WP_Agent_Memory_List_Entry}
	 * value objects.
	 *
	 * @since next
	 *
	 * @param string $layer    Layer identifier (shared|agent|user|network).
	 * @param int    $user_id  WordPress user ID. 0 = default agent.
	 * @param int    $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 * @return WP_Agent_Memory_List_Entry[]
	 */
	public static function list_layer( string $layer, int $user_id = 0, int $agent_id = 0 ): array {
		$dm                = new DirectoryManager();
		$effective_user_id = $dm->get_effective_user_id( $user_id );
		$workspace         = WordPressWorkspaceScope::current();
		$scope_query       = new WP_Agent_Memory_Scope( $layer, $workspace->workspace_type, $workspace->workspace_id, $effective_user_id, $agent_id, '' );
		$store             = AgentMemoryStoreFactory::for_scope( $scope_query );

		return $store->list_layer( $scope_query );
	}

	/**
	 * List all files under a path prefix within a layer.
	 *
	 * Static facade over {@see WP_Agent_Memory_Store::list_subtree()}.
	 * Recursive — entries' filenames are full relative paths from the
	 * layer root (e.g. `daily/2026/04/17.md`, `contexts/chat.md`).
	 *
	 * Used for path-namespaced file families that don't fit the
	 * top-level layer model: daily memory under `daily/YYYY/MM/`, context
	 * files under `contexts/`, future plugin-added subtrees.
	 *
	 * @since next
	 *
	 * @param string $layer    Layer identifier (shared|agent|user|network).
	 * @param int    $user_id  WordPress user ID. 0 = default agent.
	 * @param int    $agent_id Agent ID for direct resolution. 0 = resolve from user_id.
	 * @param string $prefix   Path prefix without trailing slash (e.g. 'daily', 'contexts').
	 * @return WP_Agent_Memory_List_Entry[]
	 */
	public static function list_subtree( string $layer, int $user_id, int $agent_id, string $prefix ): array {
		$dm                = new DirectoryManager();
		$effective_user_id = $dm->get_effective_user_id( $user_id );
		$workspace         = WordPressWorkspaceScope::current();
		$scope_query       = new WP_Agent_Memory_Scope( $layer, $workspace->workspace_type, $workspace->workspace_id, $effective_user_id, $agent_id, '' );
		$store             = AgentMemoryStoreFactory::for_scope( $scope_query );

		return $store->list_subtree( $scope_query, $prefix );
	}

	/**
	 * List all section headers in the file.
	 *
	 * Sections are defined by markdown ## headers.
	 *
	 * @return array{success: bool, sections?: string[], file?: string, message?: string}
	 */
	public function get_sections(): array {
		$result = $this->store->read( $this->scope );

		if ( ! $result->exists ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File %s does not exist.', $this->scope->filename ),
			);
		}

		return array(
			'success'  => true,
			'file'     => $this->scope->filename,
			'sections' => $this->parse_section_headers( $result->content ),
		);
	}

	/**
	 * Get the content of a specific section.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @return array{success: bool, section?: string, content?: string, message?: string}
	 */
	public function get_section( string $section_name ): array {
		$result = $this->store->read( $this->scope );

		if ( ! $result->exists ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File %s does not exist.', $this->scope->filename ),
			);
		}

		$parsed = $this->parse_section( $result->content, $section_name );

		if ( null === $parsed ) {
			return array(
				'success'            => false,
				'message'            => sprintf( 'Section "%s" not found.', $section_name ),
				'available_sections' => $this->parse_section_headers( $result->content ),
			);
		}

		return array(
			'success' => true,
			'section' => $section_name,
			'content' => $parsed,
		);
	}

	/**
	 * Replace the entire file content. Creates the file if missing.
	 *
	 * Used by full-file rewrites (e.g. daily memory cleanup) that
	 * already have the final content composed in PHP and don't need
	 * section-level merging.
	 *
	 * @since next
	 * @param string                   $content  New full file content.
	 * @param WP_Agent_Memory_Metadata|null $metadata Optional Agents API provenance/trust metadata.
	 * @return array{success: bool, message: string, file_size?: int, warning?: string}
	 */
	public function replace_all( string $content, ?WP_Agent_Memory_Metadata $metadata = null ): array {
		$write = $this->store->write( $this->scope, $content, null, $metadata );

		if ( ! $write->success ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to write %s (%s).', $this->scope->filename, $write->error ?? 'unknown' ),
			);
		}

		$this->emit_updated_event( $content, $write );

		$result = array(
			'success'   => true,
			'message'   => sprintf( '%s written.', $this->scope->filename ),
			'file_size' => $write->bytes,
		);

		if ( $write->bytes > self::MAX_FILE_SIZE ) {
			$result['warning'] = self::size_warning( $write->bytes );
			$this->log_size_warning( $write->bytes );
		}

		return $result;
	}

	/**
	 * Set (replace) the content of a specific section.
	 *
	 * Creates the section if it doesn't exist.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @param string $content New content for the section.
	 * @return array{success: bool, message: string}
	 */
	public function set_section( string $section_name, string $content ): array {
		$this->ensure_file_exists();

		$current      = $this->store->read( $this->scope );
		$file_content = $current->exists ? $current->content : '';
		$section_pos  = $this->find_section_position( $file_content, $section_name );
		$body         = $this->format_section_body( $content );

		if ( null === $section_pos ) {
			$file_content .= "\n## {$section_name}\n{$body}\n";
		} else {
			$file_content = $this->replace_section_content( $file_content, $section_pos, $body );
		}

		$write = $this->store->write( $this->scope, $file_content, $current->exists ? $current->hash : null );

		if ( ! $write->success ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to update section "%s" (%s).', $section_name, $write->error ?? 'unknown' ),
			);
		}

		$this->emit_updated_event( $file_content, $write );

		$result = array(
			'success'   => true,
			'message'   => sprintf( 'Section "%s" updated.', $section_name ),
			'file_size' => $write->bytes,
		);

		if ( $write->bytes > self::MAX_FILE_SIZE ) {
			$result['warning'] = self::size_warning( $write->bytes );
			$this->log_size_warning( $write->bytes );
		}

		return $result;
	}

	/**
	 * Append content to a specific section.
	 *
	 * Creates the section if it doesn't exist.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @param string $content Content to append.
	 * @return array{success: bool, message: string}
	 */
	public function append_to_section( string $section_name, string $content ): array {
		$this->ensure_file_exists();

		$current      = $this->store->read( $this->scope );
		$file_content = $current->exists ? $current->content : '';
		$section_pos  = $this->find_section_position( $file_content, $section_name );

		if ( null === $section_pos ) {
			$file_content .= "\n## {$section_name}\n" . $this->format_section_body( $content ) . "\n";
		} else {
			$existing     = $this->parse_section( $file_content, $section_name ) ?? '';
			$merged       = rtrim( $existing ) . "\n" . $content . "\n";
			$file_content = $this->replace_section_content( $file_content, $section_pos, $this->format_section_body( $merged ) );
		}

		$write = $this->store->write( $this->scope, $file_content, $current->exists ? $current->hash : null );

		if ( ! $write->success ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to append to section "%s" (%s).', $section_name, $write->error ?? 'unknown' ),
			);
		}

		$this->emit_updated_event( $file_content, $write );

		$result = array(
			'success'   => true,
			'message'   => sprintf( 'Content appended to section "%s".', $section_name ),
			'file_size' => $write->bytes,
		);

		if ( $write->bytes > self::MAX_FILE_SIZE ) {
			$result['warning'] = self::size_warning( $write->bytes );
			$this->log_size_warning( $write->bytes );
		}

		return $result;
	}

	/**
	 * Delete a specific section and its body.
	 *
	 * @param string $section_name Section header text (without ##).
	 * @return array{success: bool, message: string, file_size?: int}
	 */
	public function delete_section( string $section_name ): array {
		$current = $this->store->read( $this->scope );

		if ( ! $current->exists ) {
			return array(
				'success' => false,
				'message' => sprintf( 'File %s does not exist.', $this->scope->filename ),
			);
		}

		$section_pos = $this->find_section_position( $current->content, $section_name );
		if ( null === $section_pos ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Section "%s" not found.', $section_name ),
			);
		}

		$file_content = substr( $current->content, 0, $section_pos['start'] ) . substr( $current->content, $section_pos['end'] );
		$file_content = preg_replace( "/\n{3,}/", "\n\n", $file_content ) ?? $file_content;
		$file_content = rtrim( $file_content ) . "\n";

		$write = $this->store->write( $this->scope, $file_content, $current->hash );
		if ( ! $write->success ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Failed to delete section "%s" (%s).', $section_name, $write->error ?? 'unknown' ),
			);
		}

		$this->emit_updated_event( $file_content, $write );

		return array(
			'success'   => true,
			'message'   => sprintf( 'Section "%s" deleted.', $section_name ),
			'file_size' => $write->bytes,
		);
	}

	/**
	 * Build a size warning message with actionable recommendations.
	 *
	 * @param int $file_size Current file size in bytes.
	 * @return string Warning message with specific remediation steps.
	 */
	private static function size_warning( int $file_size ): string {
		return sprintf(
			'Memory file size (%s) exceeds recommended threshold (%s). '
			. 'This file is injected into every AI context window. To reduce token usage: '
			. '(1) Condense verbose sections — tighten bullet points, remove redundancy. '
			. '(2) Move historical/temporal content to daily memory (wp datamachine memory daily append). '
			. '(3) Split domain-specific knowledge into dedicated memory files that are only loaded by relevant pipelines.',
			size_format( $file_size ),
			size_format( self::MAX_FILE_SIZE )
		);
	}

	/**
	 * Search memory file content for a query string.
	 *
	 * Case-insensitive substring search with surrounding context lines.
	 * Results are grouped by section and capped at 50 matches.
	 *
	 * @param string      $query         Search term.
	 * @param string|null $section       Optional section filter (exact name without ##).
	 * @param int         $context_lines Number of context lines above/below each match.
	 * @return array{success: bool, query: string, matches: array, match_count: int, message?: string}
	 */
	public function search( string $query, ?string $section = null, int $context_lines = 2 ): array {
		$result = $this->store->read( $this->scope );

		if ( ! $result->exists ) {
			return array(
				'success'     => false,
				'query'       => $query,
				'message'     => sprintf( 'File %s does not exist.', $this->scope->filename ),
				'matches'     => array(),
				'match_count' => 0,
			);
		}

		$content         = $result->content;
		$lines           = explode( "\n", $content );
		$matches         = array();
		$current_section = null;
		$query_lower     = mb_strtolower( $query );
		$line_count      = count( $lines );
		$in_marked_body  = false;

		foreach ( $lines as $index => $line ) {
			$trimmed = trim( $line );
			if ( self::SECTION_BODY_START === $trimmed ) {
				$in_marked_body = true;
				continue;
			}

			if ( self::SECTION_BODY_END === $trimmed ) {
				$in_marked_body = false;
				continue;
			}

			if ( ! $in_marked_body && preg_match( '/^## (.+)$/', $line, $header_match ) ) {
				$current_section = trim( $header_match[1] );
			}

			// Skip if section filter is set and doesn't match.
			if ( null !== $section && $current_section !== $section ) {
				continue;
			}

			if ( false !== mb_strpos( mb_strtolower( $line ), $query_lower ) ) {
				$ctx_start = max( 0, $index - $context_lines );
				$ctx_end   = min( $line_count - 1, $index + $context_lines );
				$context   = array_slice( $lines, $ctx_start, $ctx_end - $ctx_start + 1 );

				$matches[] = array(
					'section' => $current_section ?? '(top-level)',
					'line'    => $index + 1,
					'content' => $line,
					'context' => implode( "\n", $context ),
				);
			}
		}

		return array(
			'success'     => true,
			'query'       => $query,
			'matches'     => array_slice( $matches, 0, 50 ),
			'match_count' => count( $matches ),
		);
	}

	// =========================================================================
	// Internal Parsing
	// =========================================================================

	/**
	 * Parse all ## section headers from markdown content.
	 *
	 * @param string $content Markdown content.
	 * @return string[] List of section names.
	 */
	private function parse_section_headers( string $content ): array {
		$sections       = array();
		$in_marked_body = false;

		foreach ( explode( "\n", $content ) as $line ) {
			$trimmed = trim( $line );
			if ( self::SECTION_BODY_START === $trimmed ) {
				$in_marked_body = true;
				continue;
			}

			if ( self::SECTION_BODY_END === $trimmed ) {
				$in_marked_body = false;
				continue;
			}

			if ( ! $in_marked_body && preg_match( '/^## (.+)$/', $line, $match ) ) {
				$sections[] = trim( $match[1] );
			}
		}

		return $sections;
	}

	/**
	 * Extract the body content of a named section.
	 *
	 * @param string $content Full file content.
	 * @param string $section_name Section header to find.
	 * @return string|null Section body or null if not found.
	 */
	private function parse_section( string $content, string $section_name ): ?string {
		$section_pos = $this->find_section_position( $content, $section_name );
		if ( null === $section_pos ) {
			return null;
		}

		$body = substr( $content, $section_pos['header_end'], $section_pos['end'] - $section_pos['header_end'] );
		return trim( $this->unwrap_section_body( $body ) );
	}

	/**
	 * Find the position of a section header in the file content.
	 *
	 * @param string $content File content.
	 * @param string $section_name Section header to find.
	 * @return array{start: int, header_end: int, end: int}|null Position data or null.
	 */
	private function find_section_position( string $content, string $section_name ): ?array {
		$escaped = preg_quote( $section_name, '/' );
		$pattern = '/(^|\n)(## ' . $escaped . '[ \t]*\n)/';

		if ( preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE ) ) {
			$prefix      = $match[1][0];
			$full_start  = $match[0][1] + strlen( $prefix );
			$header      = $match[2][0];
			$header_end  = $full_start + strlen( $header );
			$body        = substr( $content, $header_end );
			$section_end = strlen( $content );

			if ( str_starts_with( $body, self::SECTION_BODY_START . "\n" ) ) {
				$end_marker_pos = strpos( $body, "\n" . self::SECTION_BODY_END );
				if ( false !== $end_marker_pos ) {
					$section_end = $header_end + $end_marker_pos + 1 + strlen( self::SECTION_BODY_END );
					if ( "\n" === substr( $content, $section_end, 1 ) ) {
						++$section_end;
					}
				}
			} elseif ( preg_match( '/\n## /', $body, $next_match, PREG_OFFSET_CAPTURE ) ) {
				$section_end = $header_end + $next_match[0][1];
			}

			return array(
				'start'      => $full_start,
				'header_end' => $header_end,
				'end'        => $section_end,
			);
		}

		return null;
	}

	/**
	 * Replace the body content of a section at the given position.
	 *
	 * @param string $file_content Full file content.
	 * @param array  $position Position data from find_section_position.
	 * @param string $new_content New body content.
	 * @return string Updated file content.
	 */
	private function replace_section_content( string $file_content, array $position, string $new_content ): string {
		$before = substr( $file_content, 0, $position['header_end'] );
		$after  = substr( $file_content, $position['end'] );

		return $before . $new_content . "\n" . $after;
	}

	/**
	 * Marker-wrap section bodies that contain top-level markdown headings.
	 *
	 * Plain markdown cannot otherwise distinguish a body `##` heading from the
	 * next agent-memory section. The markers are stripped from section reads and
	 * ignored by section listing.
	 */
	private function format_section_body( string $content ): string {
		$content = rtrim( $content );
		if ( ! preg_match( '/^## .+$/m', $content ) ) {
			return $content;
		}

		return self::SECTION_BODY_START . "\n" . $content . "\n" . self::SECTION_BODY_END;
	}

	/**
	 * Remove section body boundary markers from a parsed body.
	 */
	private function unwrap_section_body( string $body ): string {
		$body = trim( $body );
		if ( ! str_starts_with( $body, self::SECTION_BODY_START ) ) {
			return $body;
		}

		$body = preg_replace( '/^' . preg_quote( self::SECTION_BODY_START, '/' ) . '\s*\n?/', '', $body ) ?? $body;
		$body = preg_replace( '/\n?' . preg_quote( self::SECTION_BODY_END, '/' ) . '$/', '', $body ) ?? $body;
		return $body;
	}

	/**
	 * Emit a successful memory update event for external projectors.
	 *
	 * @since next
	 *
	 * @param string                 $content Persisted full-file content.
	 * @param WP_Agent_Memory_Write_Result $write   Successful store write result.
	 */
	private function emit_updated_event( string $content, WP_Agent_Memory_Write_Result $write ): void {
		do_action(
			'datamachine_agent_memory_updated',
			$this->scope,
			$content,
			$this->event_metadata( $write )
		);
	}

	/**
	 * Emit a successful memory delete event for external projectors.
	 *
	 * @since next
	 */
	private function emit_deleted_event(): void {
		do_action( 'datamachine_agent_memory_deleted', $this->scope );
	}

	/**
	 * Build JSON-friendly metadata for memory change events.
	 *
	 * The WP_Agent_Memory_Scope object remains the first event argument for typed PHP
	 * consumers; duplicated scalar identity fields let queue/log/projector code
	 * persist the event without inspecting the value object.
	 *
	 * @since next
	 *
	 * @param WP_Agent_Memory_Write_Result $write Successful store write result.
	 * @return array{layer: string, user_id: int, agent_id: int, filename: string, key: string, hash: string, bytes: int, metadata?: array, unsupported_metadata_fields?: string[]}
	 */
	private function event_metadata( WP_Agent_Memory_Write_Result $write ): array {
		$metadata = array(
			'layer'    => $this->scope->layer,
			'user_id'  => $this->scope->user_id,
			'agent_id' => $this->scope->agent_id,
			'filename' => $this->scope->filename,
			'key'      => $this->scope->key(),
			'hash'     => $write->hash,
			'bytes'    => $write->bytes,
		);

		if ( null !== $write->metadata ) {
			$metadata['metadata'] = $write->metadata->to_array();
		}

		if ( array() !== $write->unsupported_metadata_fields ) {
			$metadata['unsupported_metadata_fields'] = $write->unsupported_metadata_fields;
		}

		return $metadata;
	}

	/**
	 * Ensure the target file exists in the store.
	 *
	 * Uses scaffold defaults when available so a recreated file includes
	 * the standard sections. When no scaffold template exists, writes a
	 * minimal stub so subsequent section operations have something to
	 * read–modify–write.
	 */
	private function ensure_file_exists(): void {
		if ( $this->store->exists( $this->scope ) ) {
			return;
		}

		$ability = \DataMachine\Abilities\File\ScaffoldAbilities::get_ability();
		if ( $ability ) {
			$ability->execute( array(
				'filename' => $this->scope->filename,
				'user_id'  => $this->scope->user_id,
			) );
		}

		// If scaffold didn't create it (no template for this file), create a stub.
		$current = $this->store->read( $this->scope );
		if ( ! $current->exists ) {
			$this->store->write( $this->scope, "# {$this->scope->filename}\n", null );
		}
	}

	/**
	 * Sanitize a filename to prevent directory traversal.
	 *
	 * Allows forward-slashes so callers can address relative paths within
	 * a layer (e.g. 'contexts/chat.md', 'daily/2026/04/17.md'); each
	 * segment is run through basename's allow-list.
	 *
	 * @since 0.45.0
	 * @param string $filename Raw filename or relative path.
	 * @return string Sanitized filename.
	 */
	private function sanitize_filename( string $filename ): string {
		$segments = array_filter( explode( '/', $filename ), static fn( string $segment ): bool => '' !== $segment );
		$clean    = array();
		foreach ( $segments as $segment ) {
			$clean[] = preg_replace( '/[^a-zA-Z0-9._-]/', '', basename( $segment ) );
		}
		return implode( '/', $clean );
	}

	/**
	 * Resolve the on-disk directory for the current scope's layer.
	 *
	 * Used only by get_file_path() to keep backward compatibility with
	 * callers that need a filesystem path.
	 */
	private function resolve_layer_directory(): string {
		switch ( $this->scope->layer ) {
			case MemoryFileRegistry::LAYER_SHARED:
				return $this->directory_manager->get_shared_directory();
			case MemoryFileRegistry::LAYER_USER:
				return $this->directory_manager->get_user_directory( $this->scope->user_id );
			case MemoryFileRegistry::LAYER_NETWORK:
				return $this->directory_manager->get_network_directory();
			case MemoryFileRegistry::LAYER_AGENT:
			default:
				return $this->directory_manager->resolve_agent_directory( array(
					'agent_id' => $this->scope->agent_id,
					'user_id'  => $this->scope->user_id,
				) );
		}
	}

	/**
	 * Emit the size warning log entry. Side-effect-only.
	 */
	private function log_size_warning( int $size ): void {
		do_action(
			'datamachine_log',
			'warning',
			sprintf(
				'Agent memory file exceeds recommended size: %s (%s, threshold %s)',
				$this->scope->filename,
				size_format( $size ),
				size_format( self::MAX_FILE_SIZE )
			),
			array(
				'file' => $this->scope->filename,
				'size' => $size,
				'max'  => self::MAX_FILE_SIZE,
			)
		);
	}
}
