<?php
/**
 * Replayable engine state ledger primitive.
 *
 * @package DataMachine\Core
 */

namespace DataMachine\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and replays append-only engine state events.
 */
class EngineStateLedger {

	/** Current ledger event schema version. */
	public const SCHEMA_VERSION = 1;

	/** Engine snapshot key used to store ledger events. */
	public const SNAPSHOT_KEY = '_engine_state_ledger';

	/**
	 * Append a replayable event to a snapshot and return the projected snapshot.
	 *
	 * @param array  $snapshot Current engine data snapshot.
	 * @param string $type     Event type.
	 * @param array  $patch    Engine data patch.
	 * @param array  $metadata Optional event metadata.
	 * @return array{snapshot: array, event: array}|null Projected snapshot and appended event.
	 */
	public static function append( array $snapshot, string $type, array $patch, array $metadata = array() ): ?array {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			return null;
		}

		$ledger        = self::fromSnapshot( $snapshot );
		$pre_snapshot  = self::snapshotForHashing( $snapshot );
		$projected     = array_replace_recursive( $snapshot, $patch );
		$post_snapshot = self::snapshotForHashing( $projected );
		$event         = array(
			'schema_version'     => self::SCHEMA_VERSION,
			'version'            => self::nextVersion( $ledger ),
			'type'               => $type,
			'event_type'         => $type,
			'op_id'              => self::resolveOpId( $metadata ),
			'actor'              => self::resolveStringMetadata( $metadata, 'actor' ),
			'source'             => self::resolveStringMetadata( $metadata, 'source' ),
			'recorded_at'        => gmdate( 'c' ),
			'patch'              => $patch,
			'patch_keys'         => array_keys( $patch ),
			'patch_hash'         => self::stableHash( $patch ),
			'pre_snapshot_hash'  => self::stableHash( $pre_snapshot ),
			'post_snapshot_hash' => self::stableHash( $post_snapshot ),
		);

		if ( ! empty( $metadata ) ) {
			$event['metadata'] = $metadata;
		}

		$ledger[]                        = $event;
		$projected[ self::SNAPSHOT_KEY ] = $ledger;

		return array(
			'snapshot' => $projected,
			'event'    => $event,
		);
	}

	/**
	 * Append a replayable event to a persisted job snapshot once per operation id.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $op_id    Deterministic operation id.
	 * @param string $type     Event type.
	 * @param array  $patch    Engine data patch.
	 * @param array  $metadata Optional event metadata.
	 * @return array|null Appended event, existing event for duplicate op_id, or null on failure.
	 */
	public static function appendOnce( int $job_id, string $op_id, string $type, array $patch, array $metadata = array() ): ?array {
		return EngineData::appendStateEventOnce( $job_id, $op_id, $type, $patch, $metadata );
	}

	/**
	 * Return ledger events from a snapshot.
	 *
	 * @param array $snapshot Engine data snapshot.
	 * @return array Ledger events.
	 */
	public static function fromSnapshot( array $snapshot ): array {
		return is_array( $snapshot[ self::SNAPSHOT_KEY ] ?? null ) ? $snapshot[ self::SNAPSHOT_KEY ] : array();
	}

	/**
	 * Return the first ledger event matching an operation id.
	 *
	 * @param array  $snapshot Engine data snapshot.
	 * @param string $op_id    Operation id.
	 * @return array|null Matching ledger event, or null when absent.
	 */
	public static function findByOpId( array $snapshot, string $op_id ): ?array {
		$op_id = trim( $op_id );
		if ( '' === $op_id ) {
			return null;
		}

		foreach ( self::fromSnapshot( $snapshot ) as $event ) {
			if ( is_array( $event ) && (string) ( $event['op_id'] ?? '' ) === $op_id ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * Replay event patches onto a base snapshot.
	 *
	 * @param array $events        Ledger events.
	 * @param array $base_snapshot Optional base snapshot.
	 * @return array Replayed engine data snapshot without ledger metadata.
	 */
	public static function replay( array $events, array $base_snapshot = array() ): array {
		$projection = self::snapshotForHashing( $base_snapshot );

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! is_array( $event['patch'] ?? null ) ) {
				continue;
			}

			$projection = array_replace_recursive( $projection, $event['patch'] );
		}

		return $projection;
	}

	/**
	 * Replay a snapshot's ledger onto an optional base snapshot.
	 *
	 * @param array $snapshot      Engine data snapshot containing ledger events.
	 * @param array $base_snapshot Optional base snapshot.
	 * @return array Replayed engine data snapshot without ledger metadata.
	 */
	public static function replaySnapshotLedger( array $snapshot, array $base_snapshot = array() ): array {
		return self::replay( self::fromSnapshot( $snapshot ), $base_snapshot );
	}

	/**
	 * Build a stable hash for a value.
	 *
	 * @param mixed $value Value to hash.
	 * @return string sha256 hash prefixed for readability.
	 */
	public static function stableHash( $value ): string {
		$normalized = self::sortRecursively( $value );
		$json       = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );

		if ( ! is_string( $json ) ) {
			$json = 'null';
		}

		return 'sha256:' . hash( 'sha256', $json );
	}

	/**
	 * Remove ledger metadata from a snapshot before hashing or replay comparison.
	 *
	 * @param array $snapshot Engine data snapshot.
	 * @return array Snapshot without ledger metadata.
	 */
	public static function snapshotForHashing( array $snapshot ): array {
		unset( $snapshot[ self::SNAPSHOT_KEY ] );
		return $snapshot;
	}

	/**
	 * Resolve the next monotonically increasing ledger version.
	 *
	 * @param array $ledger Existing ledger entries.
	 * @return int Next version number.
	 */
	private static function nextVersion( array $ledger ): int {
		$version = 0;
		foreach ( $ledger as $entry ) {
			if ( is_array( $entry ) && isset( $entry['version'] ) ) {
				$version = max( $version, (int) $entry['version'] );
			}
		}

		return $version + 1;
	}

	/**
	 * Resolve operation id from metadata or create a deterministic-shape fallback.
	 *
	 * @param array $metadata Event metadata.
	 * @return string Operation id.
	 */
	private static function resolveOpId( array $metadata ): string {
		$op_id = self::resolveStringMetadata( $metadata, 'op_id' );
		return '' !== $op_id ? $op_id : wp_generate_uuid4();
	}

	/**
	 * Resolve a string metadata field.
	 *
	 * @param array  $metadata Event metadata.
	 * @param string $key      Metadata key.
	 * @return string Metadata string or empty string.
	 */
	private static function resolveStringMetadata( array $metadata, string $key ): string {
		$value = $metadata[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Recursively sort associative array keys for deterministic hashing.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed Sorted value.
	 */
	private static function sortRecursively( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$sorted = array();
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sortRecursively( $item );
		}

		if ( array_keys( $sorted ) !== range( 0, count( $sorted ) - 1 ) ) {
			ksort( $sorted );
		}

		return $sorted;
	}
}
