<?php
/**
 * Agent-level memory bundle artifact scoping and materialization.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\FilesRepository\AgentMemory;
use DataMachine\Engine\AI\MemoryFileRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the rules that decide which bundle-carried agent memory files
 * may be planned and applied to the live agent store, and the read/write
 * mechanics for doing so.
 *
 * Authored agent identity (SOUL.md — authority tier `agent_identity`) is
 * upgradeable: a bundle is the canonical, versioned source of that file, so an
 * upgrade legitimately materializes it into the live store. Learned runtime
 * memory (MEMORY.md, WAKE.md, daily/*) is NEVER materialized from a bundle
 * even when present in the payload, because it accumulates on the live install
 * and must not be clobbered by a deploy. The bundle's USER.md template is also
 * out of scope here — it is handled separately as a create-only template.
 */
final class AgentBundleMemoryArtifact {

	public const ARTIFACT_TYPE = 'memory';

	/**
	 * Bundle-relative prefix for agent-layer memory files inside a bundle's
	 * `files` map. The map stored in `$bundle['files']` already has this prefix
	 * stripped, but artifact IDs/source paths reference the full bundle path so
	 * they line up with the manifest's `included.memory` list and the on-disk
	 * `memory/agent/<file>` layout.
	 */
	private const AGENT_PREFIX = 'agent/';

	/**
	 * Build target memory artifact rows from a bundle array.
	 *
	 * Only authored-identity files declared in the bundle are emitted. The
	 * payload is the raw file contents so the package planner can hash/diff it
	 * exactly like any other artifact.
	 *
	 * @param array<string,mixed> $bundle Bundle array (canonical import shape).
	 * @return array<int,array<string,mixed>>
	 */
	public static function target_artifacts( array $bundle ): array {
		$artifacts = array();
		foreach ( self::applicable_files( $bundle ) as $filename => $contents ) {
			$artifacts[] = self::artifact_row( $filename, (string) $contents );
		}

		return $artifacts;
	}

	/**
	 * Build current memory artifact rows for an agent from its installed ledger.
	 *
	 * Mirrors the target side: for every authored-identity memory artifact the
	 * agent has installed, project the live store's current content so the
	 * planner can classify it as no-op / needs-approval / auto-apply. Reading
	 * from the ledger (rather than enumerating every registered file) keeps the
	 * current set aligned with what the bundle actually manages, so an
	 * unmanaged live SOUL.md is never surfaced as drift.
	 *
	 * @param int                            $agent_id  Agent ID.
	 * @param array<int,array<string,mixed>> $installed Installed artifact rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function current_artifacts( int $agent_id, array $installed ): array {
		if ( $agent_id <= 0 ) {
			return array();
		}

		$artifacts = array();
		foreach ( $installed as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( self::ARTIFACT_TYPE !== (string) ( $record['artifact_type'] ?? '' ) ) {
				continue;
			}

			$artifact_id = (string) ( $record['artifact_id'] ?? '' );
			$filename    = self::filename_from_artifact_id( $artifact_id );
			if ( '' === $filename || ! self::is_authored_identity( $filename ) ) {
				continue;
			}

			$content = self::read_live( $agent_id, $filename );
			if ( null === $content ) {
				continue;
			}

			$artifacts[] = self::artifact_row( $filename, $content );
		}

		return $artifacts;
	}

	/**
	 * Read the live store payload for a memory artifact by its bundle artifact ID.
	 *
	 * @param int    $agent_id    Agent ID.
	 * @param string $artifact_id Bundle artifact ID (e.g. `agent/SOUL.md`).
	 * @return string|null Current content, or null when absent.
	 */
	public static function current_payload( int $agent_id, string $artifact_id ): ?string {
		if ( $agent_id <= 0 ) {
			return null;
		}

		$filename = self::filename_from_artifact_id( $artifact_id );
		if ( '' === $filename || ! self::is_authored_identity( $filename ) ) {
			return null;
		}

		return self::read_live( $agent_id, $filename );
	}

	/**
	 * Materialize one bundle-carried agent memory artifact into the live store.
	 *
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param int                 $agent_id Agent ID.
	 * @return array<string,mixed>|\WP_Error|null Result, WP_Error on failure, null when not applicable.
	 */
	public static function apply( array $artifact, int $agent_id ): array|\WP_Error|null {
		if ( self::ARTIFACT_TYPE !== (string) ( $artifact['artifact_type'] ?? '' ) || $agent_id <= 0 ) {
			return null;
		}

		$artifact_id = (string) ( $artifact['artifact_id'] ?? '' );
		$filename    = self::filename_from_artifact_id( $artifact_id );
		if ( '' === $filename ) {
			return null;
		}

		// Hard guard: never let a bundle clobber learned runtime memory, even if
		// the bundle declared it. Only authored identity is materializable.
		if ( ! self::is_authored_identity( $filename ) ) {
			return new \WP_Error(
				'datamachine_bundle_memory_not_authored',
				sprintf( 'Refusing to materialize learned memory file "%s" from a bundle; only authored identity is upgradeable.', $filename )
			);
		}

		$payload = $artifact['payload'] ?? null;
		if ( ! is_string( $payload ) ) {
			return null;
		}

		$memory = new AgentMemory( 0, $agent_id, $filename );
		$result = $memory->replace_all( $payload );
		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'datamachine_bundle_memory_write_failed',
				sprintf( 'Failed to write agent memory file "%s": %s', $filename, (string) ( $result['message'] ?? 'unknown error' ) )
			);
		}

		return array(
			'artifact_type' => self::ARTIFACT_TYPE,
			'artifact_id'   => $artifact_id,
			'agent_id'      => $agent_id,
		);
	}

	/**
	 * Whether a bundle-carried agent-layer file may be materialized on upgrade.
	 *
	 * Public entry point for the importer's upgrade-time guard so the
	 * authored-identity-vs-learned-memory rule lives in exactly one place.
	 *
	 * @param string $relative_path Agent-layer relative path (no `agent/` prefix).
	 */
	public static function is_upgradeable_agent_file( string $relative_path ): bool {
		$filename = self::normalize_filename( $relative_path );

		return '' !== $filename && self::is_authored_identity( $filename );
	}

	/**
	 * Filter a bundle's agent-layer memory map down to applicable files.
	 *
	 * @param array<string,mixed> $bundle Bundle array.
	 * @return array<string,string> filename => contents (filename has no prefix).
	 */
	private static function applicable_files( array $bundle ): array {
		$files = is_array( $bundle['files'] ?? null ) ? $bundle['files'] : array();

		$applicable = array();
		foreach ( $files as $relative_path => $contents ) {
			$filename = self::normalize_filename( (string) $relative_path );
			if ( '' === $filename || ! self::is_authored_identity( $filename ) ) {
				continue;
			}
			$applicable[ $filename ] = (string) $contents;
		}

		return $applicable;
	}

	/**
	 * Whether a memory filename is authored agent identity that a bundle owns.
	 *
	 * Authority tier `agent_identity` (SOUL.md by default) is the authored,
	 * versioned identity. Everything else in the agent layer (MEMORY.md,
	 * WAKE.md, daily/*) is learned runtime memory and must not be overwritten.
	 */
	private static function is_authored_identity( string $filename ): bool {
		// daily/* and any nested learned memory is never authored identity.
		if ( str_contains( $filename, '/' ) ) {
			return false;
		}

		$meta = MemoryFileRegistry::get( $filename );
		if ( is_array( $meta ) ) {
			$tier = (string) ( $meta['authority_tier'] ?? '' );
			if ( '' !== $tier ) {
				return 'agent_identity' === $tier;
			}
		}

		// Unregistered files: fall back to the canonical authored-identity file.
		return 'SOUL.md' === $filename;
	}

	/**
	 * Read the live store content for an agent memory file.
	 *
	 * @return string|null Content, or null when the file does not exist.
	 */
	private static function read_live( int $agent_id, string $filename ): ?string {
		$memory = new AgentMemory( 0, $agent_id, $filename );
		$result = $memory->read();

		return $result->exists ? (string) $result->content : null;
	}

	/** @return array<string,mixed> */
	private static function artifact_row( string $filename, string $contents ): array {
		return array(
			'artifact_type' => self::ARTIFACT_TYPE,
			'artifact_id'   => self::AGENT_PREFIX . $filename,
			'source_path'   => BundleSchema::MEMORY_DIR . '/' . self::AGENT_PREFIX . $filename,
			'payload'       => $contents,
		);
	}

	private static function filename_from_artifact_id( string $artifact_id ): string {
		$artifact_id = str_replace( '\\', '/', trim( $artifact_id ) );
		if ( str_starts_with( $artifact_id, self::AGENT_PREFIX ) ) {
			$artifact_id = substr( $artifact_id, strlen( self::AGENT_PREFIX ) );
		}

		return self::normalize_filename( $artifact_id );
	}

	private static function normalize_filename( string $relative_path ): string {
		$relative_path = str_replace( '\\', '/', trim( $relative_path ) );
		$relative_path = ltrim( $relative_path, '/' );
		if ( '' === $relative_path || str_contains( $relative_path, '..' ) ) {
			return '';
		}

		return $relative_path;
	}
}
