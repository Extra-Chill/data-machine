<?php
/**
 * PendingActionStore — server-side storage for tool invocations awaiting user resolution.
 *
 * When a tool runs in preview mode (see ActionPolicyResolver), the pending
 * invocation is stored here instead of being applied immediately. The
 * datamachine/resolve-pending-action ability later retrieves the stored
 * payload and either replays it (`accepted`) or discards it (`rejected`).
 *
 * The store is kind-agnostic: any tool that opts into the preview/approve
 * workflow can stage here — content edits (`edit_post_blocks`,
 * `replace_post_blocks`, `insert_content`), socials publishes, destructive
 * ops, account mutations, etc. Each kind is dispatched via the
 * `datamachine_pending_action_handlers` filter at resolution time.
 *
 * Payload shape:
 *
 *   array(
 *       'kind'          => 'socials_publish_instagram',  // handler dispatch key
 *       'summary'       => 'Post to Instagram: "NEW EP 🎸"',
 *       'preview_data'  => array( ... ),  // UI-oriented preview payload
 *       'apply_input'   => array( ... ),  // replayable handler input
 *       'created_by'    => 123,            // user_id (or 0 if anonymous)
 *       'agent_id'      => 7,              // acting agent, if any
 *       'context'       => array( ... ),   // free-form (session_id, bridge_app, etc.)
 *   )
 *
	 * Uses durable WordPress database storage in normal runtime. Pure-PHP smoke
	 * tests and pre-table boot can still fall back to the legacy transient path.
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

defined( 'ABSPATH' ) || exit;

class PendingActionStore {

	/**
	 * Pending status values.
	 */
	public const STATUS_PENDING  = 'pending';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_DELETED  = 'deleted';

	/**
	 * Database table name without WordPress prefix.
	 */
	private const TABLE_NAME = 'datamachine_pending_actions';

	/**
	 * Default TTL for unattended exception queues.
	 */
	private const DEFAULT_TTL = 604800;

	/**
	 * Minimum TTL in seconds.
	 */
	private const MIN_TTL = 3600;

	/**
	 * Transient fallback key prefix for pure-PHP smoke tests and pre-table boot.
	 */
	private const TRANSIENT_PREFIX = 'dm_pa_';

	/**
	 * Agents API store adapter singleton.
	 *
	 * @var PendingActionStoreAdapter|null
	 */
	private static ?PendingActionStoreAdapter $adapter = null;

	/**
	 * Create or update the durable pending-actions table.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			action_id varchar(191) NOT NULL,
			kind varchar(100) NOT NULL,
			summary text NOT NULL,
			preview_data longtext NULL,
			apply_input longtext NULL,
			agent_id bigint(20) unsigned NULL,
			created_by bigint(20) unsigned NULL,
			context longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			resolved_at datetime NULL,
			resolved_by bigint(20) unsigned NULL,
			resolution_result longtext NULL,
			resolution_error text NULL,
			PRIMARY KEY  (action_id),
			KEY status (status),
			KEY kind (kind),
			KEY agent_id (agent_id),
			KEY created_by (created_by),
			KEY expires_at (expires_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Get the prefixed pending-actions table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Return the Agents API store adapter when the contract is available.
	 */
	public static function adapter(): PendingActionStoreAdapter {
		if ( null === self::$adapter ) {
			self::$adapter = new PendingActionStoreAdapter();
		}

		return self::$adapter;
	}

	/**
	 * Store a pending action.
	 *
	 * The caller is responsible for building a well-formed payload. The
	 * store stamps a created_at timestamp and the action_id before persisting.
	 *
	 * @param string $action_id Unique action identifier.
	 * @param array  $payload   Pending action payload (see class docblock).
	 * @return bool Whether the transient was written.
	 */
	public static function store( string $action_id, array $payload ): bool {
		global $wpdb;

		if ( ! self::has_database() ) {
			$payload['created_at'] = time();
			$payload['expires_at'] = time() + self::resolve_ttl( $payload );
			$payload['action_id']  = $action_id;
			$payload['status']     = self::STATUS_PENDING;

			return set_transient( self::TRANSIENT_PREFIX . $action_id, $payload, self::resolve_ttl( $payload ) );
		}

		$now        = time();
		$ttl        = self::resolve_ttl( $payload );
		$expires_at = isset( $payload['expires_at'] ) ? self::normalize_timestamp( $payload['expires_at'] ) : ( $now + $ttl );
		$created_at = isset( $payload['created_at'] ) ? self::normalize_timestamp( $payload['created_at'] ) : $now;

		$payload['created_at'] = $created_at;
		$payload['expires_at'] = $expires_at;
		$payload['action_id']  = $action_id;
		$payload['status']     = self::STATUS_PENDING;

		$row = array(
			'action_id'         => $action_id,
			'kind'              => sanitize_key( (string) ( $payload['kind'] ?? '' ) ),
			'summary'           => (string) ( $payload['summary'] ?? '' ),
			'preview_data'      => self::encode_json( $payload['preview_data'] ?? array() ),
			'apply_input'       => self::encode_json( $payload['apply_input'] ?? array() ),
			'agent_id'          => self::nullable_positive_int( $payload['agent_id'] ?? null ),
			'created_by'        => self::nullable_positive_int( $payload['created_by'] ?? null ),
			'context'           => self::encode_json( $payload['context'] ?? array() ),
			'status'            => self::STATUS_PENDING,
			'created_at'        => gmdate( 'Y-m-d H:i:s', $created_at ),
			'expires_at'        => $expires_at > 0 ? gmdate( 'Y-m-d H:i:s', $expires_at ) : null,
			'resolved_at'       => null,
			'resolved_by'       => null,
			'resolution_result' => null,
			'resolution_error'  => null,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$stored = $wpdb->replace( self::get_table_name(), $row, $formats );

		return false !== $stored;
	}

	/**
	 * Retrieve a pending action payload.
	 *
	 * @param string $action_id Action identifier.
	 * @return array|null The payload, or null if not found / expired.
	 */
	public static function get( string $action_id, bool $include_resolved = false ): ?array {
		if ( ! self::has_database() ) {
			$data = get_transient( self::TRANSIENT_PREFIX . $action_id );
			return is_array( $data ) ? $data : null;
		}

		$row = self::get_row( $action_id );

		if ( null === $row ) {
			return null;
		}

		if ( ! $include_resolved && self::STATUS_PENDING !== $row['status'] ) {
			return null;
		}

		if ( self::STATUS_PENDING === $row['status'] && self::is_expired_row( $row ) ) {
			self::record_resolution( $action_id, self::STATUS_EXPIRED, null, 'Pending action expired.' );
			if ( ! $include_resolved ) {
				return null;
			}

			$row = self::get_row( $action_id );
			if ( null === $row ) {
				return null;
			}
		}

		$payload = self::row_to_payload( $row );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Delete a pending action (called after resolution).
	 *
	 * @param string $action_id Action identifier.
	 * @return bool Whether the transient was deleted.
	 */
	public static function delete( string $action_id ): bool {
		if ( ! self::has_database() ) {
			return delete_transient( self::TRANSIENT_PREFIX . $action_id );
		}

		return self::record_resolution( $action_id, self::STATUS_DELETED, null, 'Pending action deleted.' );
	}

	/**
	 * Record a terminal resolution while retaining the row for audit/listing.
	 *
	 * @param string      $action_id Action identifier.
	 * @param string      $decision  Terminal status.
	 * @param mixed|null  $result    Resolution result.
	 * @param string|null $error     Resolution error.
	 * @return bool Whether the row was updated.
	 */
	public static function record_resolution( string $action_id, string $decision, $result = null, ?string $error = null ): bool {
		global $wpdb;

		if ( ! self::has_database() ) {
			return delete_transient( self::TRANSIENT_PREFIX . $action_id );
		}

		$status = self::normalize_status( $decision );
		if ( '' === $status ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'status'            => $status,
				'resolved_at'       => current_time( 'mysql', true ),
				'resolved_by'       => get_current_user_id(),
				'resolution_result' => self::encode_json( $result ),
				'resolution_error'  => $error,
			),
			array( 'action_id' => $action_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * List pending-action rows for operator and agent inspection.
	 *
	 * @param array $filters Query filters.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( array $filters = array() ): array {
		if ( ! self::has_database() ) {
			return array();
		}

		self::expire_due_actions();

		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		self::add_filter_clause( $where, $args, $filters, 'status', 'status', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'kind', 'kind', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'agent_id', 'agent_id', '%d' );
		self::add_filter_clause( $where, $args, $filters, 'created_by', 'created_by', '%d' );

		if ( ! empty( $filters['context'] ) && is_array( $filters['context'] ) ) {
			foreach ( $filters['context'] as $key => $value ) {
				$where[] = 'context LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( '"' . (string) $key . '"' ) . '%' . $wpdb->esc_like( (string) $value ) . '%';
			}
		}

		if ( ! empty( $filters['created_after'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', self::normalize_timestamp( $filters['created_after'] ) );
		}

		if ( ! empty( $filters['created_before'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = gmdate( 'Y-m-d H:i:s', self::normalize_timestamp( $filters['created_before'] ) );
		}

		$limit  = isset( $filters['limit'] ) ? max( 1, min( 200, (int) $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		$sql = sprintf(
			'SELECT * FROM %%i WHERE %s ORDER BY created_at DESC LIMIT %%d OFFSET %%d',
			implode( ' AND ', $where )
		);

		$prepare_args = array_merge( array( self::get_table_name() ), $args, array( $limit, $offset ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_filter( array_map( array( self::class, 'row_to_payload' ), (array) $rows ) ) );
	}

	/**
	 * Fetch a single action for inspection, including resolved rows.
	 */
	public static function inspect( string $action_id ): ?array {
		return self::get( $action_id, true );
	}

	/**
	 * Summarize pending actions by status, kind, agent, and context key.
	 *
	 * @param array $filters Query filters.
	 * @return array<string,mixed>
	 */
	public static function summary( array $filters = array() ): array {
		$rows = self::list( array_merge( $filters, array( 'limit' => $filters['limit'] ?? 200 ) ) );

		$summary = array(
			'total'       => count( $rows ),
			'by_status'   => array(),
			'by_kind'     => array(),
			'by_agent_id' => array(),
			'by_context'  => array(),
		);

		foreach ( $rows as $row ) {
			self::increment_summary_bucket( $summary['by_status'], (string) ( $row['status'] ?? '' ) );
			self::increment_summary_bucket( $summary['by_kind'], (string) ( $row['kind'] ?? '' ) );
			self::increment_summary_bucket( $summary['by_agent_id'], (string) ( $row['agent_id'] ?? '0' ) );

			$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
			foreach ( $context as $key => $value ) {
				$bucket = (string) $key . ':' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
				self::increment_summary_bucket( $summary['by_context'], $bucket );
			}
		}

		return $summary;
	}

	/**
	 * Mark currently expired pending actions.
	 *
	 * @return int Number of rows updated.
	 */
	public static function expire_due_actions(): int {
		global $wpdb;

		if ( ! self::has_database() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolved_at = %s, resolution_error = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
				self::get_table_name(),
				self::STATUS_EXPIRED,
				current_time( 'mysql', true ),
				'Pending action expired.',
				self::STATUS_PENDING,
				current_time( 'mysql', true )
			)
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Generate a unique action identifier.
	 *
	 * @return string A namespaced UUID.
	 */
	public static function generate_id(): string {
		return 'act_' . wp_generate_uuid4();
	}

	/**
	 * Get a raw row by action ID.
	 */
	private static function get_row( string $action_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE action_id = %s', self::get_table_name(), $action_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Convert a DB row to the legacy plus durable public payload shape.
	 */
	private static function row_to_payload( array $row ): array {
		$created_at = self::mysql_to_timestamp( (string) ( $row['created_at'] ?? '' ) );
		$expires_at = self::mysql_to_timestamp( (string) ( $row['expires_at'] ?? '' ) );

		return array(
			'action_id'         => (string) ( $row['action_id'] ?? '' ),
			'kind'              => (string) ( $row['kind'] ?? '' ),
			'summary'           => (string) ( $row['summary'] ?? '' ),
			'preview_data'      => self::decode_json( $row['preview_data'] ?? null ),
			'preview'           => self::decode_json( $row['preview_data'] ?? null ),
			'apply_input'       => self::decode_json( $row['apply_input'] ?? null ),
			'agent_id'          => isset( $row['agent_id'] ) ? (int) $row['agent_id'] : 0,
			'created_by'        => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'context'           => self::decode_json( $row['context'] ?? null ),
			'status'            => (string) ( $row['status'] ?? self::STATUS_PENDING ),
			'created_at'        => $created_at,
			'created_at_iso'    => $created_at > 0 ? gmdate( 'c', $created_at ) : null,
			'expires_at'        => $expires_at,
			'expires_at_iso'    => $expires_at > 0 ? gmdate( 'c', $expires_at ) : null,
			'resolved_at'       => self::mysql_to_timestamp( (string) ( $row['resolved_at'] ?? '' ) ),
			'resolved_by'       => isset( $row['resolved_by'] ) ? (int) $row['resolved_by'] : 0,
			'resolution_result' => self::decode_json( $row['resolution_result'] ?? null ),
			'resolution_error'  => isset( $row['resolution_error'] ) ? (string) $row['resolution_error'] : null,
		);
	}

	/**
	 * Add a scalar SQL filter clause.
	 */
	private static function add_filter_clause( array &$where, array &$args, array $filters, string $filter_key, string $column, string $format ): void {
		if ( ! array_key_exists( $filter_key, $filters ) || '' === $filters[ $filter_key ] || null === $filters[ $filter_key ] ) {
			return;
		}

		$where[] = $column . ' = ' . $format;
		$args[]  = '%d' === $format ? (int) $filters[ $filter_key ] : (string) $filters[ $filter_key ];
	}

	/**
	 * Resolve the configured TTL in seconds.
	 */
	private static function resolve_ttl( array $payload ): int {
		$ttl = isset( $payload['ttl'] ) ? (int) $payload['ttl'] : self::DEFAULT_TTL;

		/**
		 * Filter pending-action TTL in seconds.
		 *
		 * @param int   $ttl     TTL in seconds.
		 * @param array $payload Pending action payload.
		 */
		$ttl = (int) apply_filters( 'datamachine_pending_action_ttl', $ttl, $payload );

		return max( self::MIN_TTL, $ttl );
	}

	/**
	 * Check whether a real wpdb object is available.
	 */
	private static function has_database(): bool {
		global $wpdb;
		return is_object( $wpdb ) && method_exists( $wpdb, 'replace' ) && method_exists( $wpdb, 'get_row' );
	}

	/**
	 * Normalize mixed timestamp input to a Unix timestamp.
	 */
	private static function normalize_timestamp( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$timestamp = strtotime( $value );
			return false === $timestamp ? 0 : $timestamp;
		}

		return 0;
	}

	/**
	 * Convert MySQL datetime to Unix timestamp.
	 */
	private static function mysql_to_timestamp( string $value ): int {
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Encode JSON with WordPress flags.
	 */
	private static function encode_json( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * Decode JSON array/value data.
	 */
	private static function decode_json( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}

		$decoded = json_decode( (string) $value, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : array();
	}

	/**
	 * Normalize nullable IDs.
	 */
	private static function nullable_positive_int( $value ): ?int {
		$value = (int) $value;
		return $value > 0 ? $value : null;
	}

	/**
	 * Normalize terminal status values.
	 */
	private static function normalize_status( string $status ): string {
		$allowed = array( self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_EXPIRED, self::STATUS_DELETED );
		return in_array( $status, $allowed, true ) ? $status : '';
	}

	/**
	 * Determine if a pending row is expired.
	 */
	private static function is_expired_row( array $row ): bool {
		$expires_at = self::mysql_to_timestamp( (string) ( $row['expires_at'] ?? '' ) );
		return $expires_at > 0 && $expires_at <= time();
	}

	/**
	 * Increment a summary bucket.
	 */
	private static function increment_summary_bucket( array &$bucket, string $key ): void {
		$key = '' === $key ? '(none)' : $key;
		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = 0;
		}
		++$bucket[ $key ];
	}
}
