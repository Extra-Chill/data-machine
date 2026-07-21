<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
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
 * tests and explicitly opted-in pre-table boot can fall back to transient
 * storage by defining DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK.
 *
 * @package DataMachine\Engine\AI\Actions
 * @since   0.72.0
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;

defined( 'ABSPATH' ) || exit;

class PendingActionStore {

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
	private const TRANSIENT_PREFIX = 'datamachine_pending_action_';

	/**
	 * Agents API store contract singleton.
	 *
	 * @var WP_Agent_Pending_Action_Store|null
	 */
	private static ?WP_Agent_Pending_Action_Store $adapter = null;

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
			agent varchar(191) NULL,
			workspace_type varchar(100) NULL,
			workspace_id varchar(191) NULL,
			created_by bigint(20) unsigned NULL,
			creator varchar(191) NULL,
			context longtext NULL,
			metadata longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			resolved_at datetime NULL,
			resolved_by bigint(20) unsigned NULL,
			resolver varchar(191) NULL,
			resolution_result longtext NULL,
			resolution_error text NULL,
			resolution_metadata longtext NULL,
			PRIMARY KEY  (action_id),
			KEY workspace (workspace_type, workspace_id),
			KEY status (status),
			KEY kind (kind),
			KEY agent_id (agent_id),
			KEY agent (agent),
			KEY created_by (created_by),
			KEY creator (creator),
			KEY resolver (resolver),
			KEY expires_at (expires_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		self::ensure_workspace_columns();
	}

	/**
	 * Ensure workspace columns exist on previously-created audit tables.
	 *
	 * @return void
	 */
	public static function ensure_workspace_columns(): void {
		global $wpdb;

		if ( ! self::has_database() ) {
			return;
		}

		$table_name = self::get_table_name();
		$workspace  = WordPressWorkspaceScope::current();

		if ( ! self::column_exists( $table_name, 'workspace_type' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_type varchar(50) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'workspace_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_id varchar(191) NULL', $table_name ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET workspace_type = %s, workspace_id = %s WHERE workspace_type IS NULL OR workspace_type = %s OR workspace_id IS NULL OR workspace_id = %s',
				$table_name,
				$workspace->workspace_type,
				$workspace->workspace_id,
				'',
				''
			)
		);
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
	public static function adapter(): WP_Agent_Pending_Action_Store {
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

		$workspace            = isset( $payload['workspace'] ) && is_array( $payload['workspace'] )
			? \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_array( $payload['workspace'] )
			: WordPressWorkspaceScope::current();
		$payload['workspace'] = $workspace->to_array();
		$context              = is_array( $payload['context'] ?? null ) ? $payload['context'] : array();
		$context['wordpress'] = $context['wordpress'] ?? WordPressWorkspaceScope::metadata();
		$payload['context']   = $context;
		$metadata             = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$datamachine          = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
		$datamachine_context  = is_array( $datamachine['context'] ?? null ) ? $datamachine['context'] : array();
		$datamachine['context'] = array_replace_recursive( $datamachine_context, $context );
		$metadata['datamachine'] = $datamachine;
		$payload['metadata']     = $metadata;

		if ( ! self::has_database() ) {
			if ( ! self::allows_transient_fallback() ) {
				self::warn_database_unavailable( 'store' );
				return false;
			}

			$payload['created_at'] = time();
			$payload['expires_at'] = time() + self::resolve_ttl( $payload );
			$payload['action_id']  = $action_id;
			$payload['status']     = WP_Agent_Pending_Action_Status::PENDING;

			$stored = set_transient( self::TRANSIENT_PREFIX . $action_id, $payload, self::resolve_ttl( $payload ) );
			if ( $stored ) {
				self::dispatch_stored_payload( $payload );
			}

			return $stored;
		}

		$now        = time();
		$ttl        = self::resolve_ttl( $payload );
		$expires_at = isset( $payload['expires_at'] ) ? self::normalize_timestamp( $payload['expires_at'] ) : ( $now + $ttl );
		$created_at = isset( $payload['created_at'] ) ? self::normalize_timestamp( $payload['created_at'] ) : $now;

		$payload['created_at'] = $created_at;
		$payload['expires_at'] = $expires_at;
		$payload['action_id']  = $action_id;
		$payload['status']     = WP_Agent_Pending_Action_Status::PENDING;

		$row = array(
			'action_id'           => $action_id,
			'kind'                => sanitize_key( (string) ( $payload['kind'] ?? '' ) ),
			'summary'             => (string) ( $payload['summary'] ?? '' ),
			'preview_data'        => self::encode_json( $payload['preview_data'] ?? array() ),
			'apply_input'         => self::encode_json( $payload['apply_input'] ?? array() ),
			'agent_id'            => self::nullable_positive_int( $payload['agent_id'] ?? null ),
			'agent'               => self::nullable_string( $payload['agent'] ?? ( isset( $payload['agent_id'] ) && (int) $payload['agent_id'] > 0 ? 'agent:' . (int) $payload['agent_id'] : null ) ),
			'workspace_type'      => self::nullable_string( $payload['workspace']['workspace_type'] ?? null ),
			'workspace_id'        => self::nullable_string( $payload['workspace']['workspace_id'] ?? null ),
			'created_by'          => self::nullable_positive_int( $payload['created_by'] ?? null ),
			'creator'             => self::nullable_string( $payload['creator'] ?? ( isset( $payload['created_by'] ) && (int) $payload['created_by'] > 0 ? 'user:' . (int) $payload['created_by'] : null ) ),
			'context'             => self::encode_json( $payload['context'] ?? array() ),
			'metadata'            => self::encode_json( $payload['metadata'] ?? array() ),
			'status'              => WP_Agent_Pending_Action_Status::PENDING,
			'created_at'          => gmdate( 'Y-m-d H:i:s', $created_at ),
			'expires_at'          => $expires_at > 0 ? gmdate( 'Y-m-d H:i:s', $expires_at ) : null,
			'resolved_at'         => null,
			'resolved_by'         => null,
			'resolver'            => null,
			'resolution_result'   => null,
			'resolution_error'    => null,
			'resolution_metadata' => null,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$stored = $wpdb->replace( self::get_table_name(), $row, $formats );
		if ( false !== $stored ) {
			self::dispatch_stored_payload( $payload );
		}

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
			if ( ! self::allows_transient_fallback() ) {
				self::warn_database_unavailable( 'get' );
				return null;
			}

			$data = get_transient( self::TRANSIENT_PREFIX . $action_id );
			return is_array( $data ) ? $data : null;
		}

		$row = self::get_row( $action_id );

		if ( null === $row ) {
			return null;
		}

		if ( ! $include_resolved && WP_Agent_Pending_Action_Status::PENDING !== $row['status'] ) {
			return null;
		}

		if ( WP_Agent_Pending_Action_Status::PENDING === $row['status'] && self::is_expired_row( $row ) ) {
			self::record_resolution( $action_id, WP_Agent_Pending_Action_Status::EXPIRED, null, 'Pending action expired.', 'system:expiration' );
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
			if ( ! self::allows_transient_fallback() ) {
				self::warn_database_unavailable( 'delete' );
				return false;
			}

			return delete_transient( self::TRANSIENT_PREFIX . $action_id );
		}

		return self::record_resolution( $action_id, WP_Agent_Pending_Action_Status::DELETED, null, 'Pending action deleted.', self::current_resolver() );
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
	public static function record_resolution( string $action_id, string $decision, $result = null, ?string $error = null, ?string $resolver = null, array $metadata = array() ): bool {
		global $wpdb;

		if ( ! self::has_database() ) {
			if ( ! self::allows_transient_fallback() ) {
				self::warn_database_unavailable( 'record_resolution' );
				return false;
			}

			$payload = get_transient( self::TRANSIENT_PREFIX . $action_id );
			$action  = is_array( $payload ) ? self::action_from_payload( $payload ) : null;
			$deleted = delete_transient( self::TRANSIENT_PREFIX . $action_id );
			if ( $deleted && null !== $action ) {
				self::dispatch_resolution( $action, $decision, $resolver ?? self::current_resolver() );
			}

			return $deleted;
		}

		$status = self::normalize_status( $decision );
		if ( '' === $status ) {
			return false;
		}

		$row    = self::get_row( $action_id );
		$action = is_array( $row ) ? self::action_from_payload( self::row_to_payload( $row ) ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update(
			self::get_table_name(),
			array(
				'status'              => $status,
				'resolved_at'         => current_time( 'mysql', true ),
				'resolved_by'         => get_current_user_id(),
				'resolver'            => self::nullable_string( $resolver ?? self::current_resolver() ),
				'resolution_result'   => self::encode_json( $result ),
				'resolution_error'    => $error,
				'resolution_metadata' => self::encode_json( $metadata ),
			),
			array( 'action_id' => $action_id ),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false !== $updated && null !== $action ) {
			$resolved_action = self::get_action( $action_id, true ) ?? $action;
			self::dispatch_resolution( $resolved_action, $status, $resolver ?? self::current_resolver() );
		}

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
		self::add_filter_clause( $where, $args, $filters, 'workspace_type', 'workspace_type', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'workspace_id', 'workspace_id', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'kind', 'kind', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'agent_id', 'agent_id', '%d' );
		self::add_filter_clause( $where, $args, $filters, 'agent', 'agent', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'created_by', 'created_by', '%d' );
		self::add_filter_clause( $where, $args, $filters, 'creator', 'creator', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'resolver', 'resolver', '%s' );
		self::add_owner_scope_clause( $where, $args, $filters );
		self::add_agent_scope_clause( $where, $args, $filters );

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
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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
		if ( ! self::has_database() ) {
			return array(
				'total'                    => 0,
				'by_status'                => array(),
				'by_kind'                  => array(),
				'by_agent_id'              => array(),
				'by_context'               => array(),
				'by_context_total'         => 0,
				'by_context_shown'         => 0,
				'by_context_omitted'       => 0,
				'context_limit'            => self::normalize_context_summary_limit( $filters ),
				'context_detail_truncated' => false,
			);
		}

		self::expire_due_actions();

		global $wpdb;

		$where = array( '1=1' );
		$args  = array();

		self::add_filter_clause( $where, $args, $filters, 'status', 'status', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'workspace_type', 'workspace_type', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'workspace_id', 'workspace_id', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'kind', 'kind', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'agent_id', 'agent_id', '%d' );
		self::add_filter_clause( $where, $args, $filters, 'agent', 'agent', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'created_by', 'created_by', '%d' );
		self::add_filter_clause( $where, $args, $filters, 'creator', 'creator', '%s' );
		self::add_filter_clause( $where, $args, $filters, 'resolver', 'resolver', '%s' );
		self::add_owner_scope_clause( $where, $args, $filters );
		self::add_agent_scope_clause( $where, $args, $filters );

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

		$sql = sprintf(
			'SELECT status, kind, agent_id, context FROM %%i WHERE %s',
			implode( ' AND ', $where )
		);

		$prepare_args = array_merge( array( self::get_table_name() ), $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$context_limit = self::normalize_context_summary_limit( $filters );

		$summary = array(
			'total'                    => count( (array) $rows ),
			'by_status'                => array(),
			'by_kind'                  => array(),
			'by_agent_id'              => array(),
			'by_context'               => array(),
			'by_context_total'         => 0,
			'by_context_shown'         => 0,
			'by_context_omitted'       => 0,
			'context_limit'            => $context_limit,
			'context_detail_truncated' => false,
		);

		foreach ( (array) $rows as $row ) {
			self::increment_summary_bucket( $summary['by_status'], (string) ( $row['status'] ?? '' ) );
			self::increment_summary_bucket( $summary['by_kind'], (string) ( $row['kind'] ?? '' ) );
			self::increment_summary_bucket( $summary['by_agent_id'], (string) ( $row['agent_id'] ?? '0' ) );

			$context = self::decode_json( $row['context'] ?? null );
			$context = is_array( $context ) ? $context : array();
			foreach ( $context as $key => $value ) {
				$bucket = (string) $key . ':' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
				self::increment_summary_bucket( $summary['by_context'], $bucket );
			}
		}

		self::sort_summary_bucket( $summary['by_status'] );
		self::sort_summary_bucket( $summary['by_kind'] );
		self::sort_summary_bucket( $summary['by_agent_id'] );
		self::sort_summary_bucket( $summary['by_context'] );

		$context_total                       = count( $summary['by_context'] );
		$summary['by_context_total']         = $context_total;
		$summary['by_context']               = self::limit_summary_bucket( $summary['by_context'], $context_limit );
		$summary['by_context_shown']         = count( $summary['by_context'] );
		$summary['by_context_omitted']       = max( 0, $context_total - $summary['by_context_shown'] );
		$summary['context_detail_truncated'] = $summary['by_context_omitted'] > 0;

		return $summary;
	}

	/**
	 * Normalize context bucket output limit for summaries.
	 *
	 * @param array $filters Query filters.
	 * @return int Zero means unbounded.
	 */
	private static function normalize_context_summary_limit( array $filters ): int {
		if ( ! empty( $filters['include_context_details'] ) ) {
			return 0;
		}

		if ( array_key_exists( 'context_limit', $filters ) ) {
			$limit = (int) $filters['context_limit'];
			return 0 === $limit ? 0 : max( 1, min( 200, $limit ) );
		}

		return 25;
	}

	/**
	 * Sort a summary bucket by count descending, then key ascending.
	 *
	 * @param array<string,int> $bucket Bucket to sort.
	 */
	private static function sort_summary_bucket( array &$bucket ): void {
		uksort(
			$bucket,
			static function ( string $left, string $right ) use ( $bucket ): int {
				$count_compare = ( $bucket[ $right ] ?? 0 ) <=> ( $bucket[ $left ] ?? 0 );
				return 0 !== $count_compare ? $count_compare : strcmp( $left, $right );
			}
		);
	}

	/**
	 * Return the top summary bucket rows according to the requested limit.
	 *
	 * @param array<string,int> $bucket Bucket to limit.
	 * @param int               $limit  Zero means unbounded.
	 * @return array<string,int>
	 */
	private static function limit_summary_bucket( array $bucket, int $limit ): array {
		if ( 0 === $limit ) {
			return $bucket;
		}

		return array_slice( $bucket, 0, $limit, true );
	}

	/**
	 * Mark currently expired pending actions.
	 *
	 * @return int Number of rows updated.
	 */
	public static function expire_due_actions( ?string $before = null ): int {
		global $wpdb;

		if ( ! self::has_database() ) {
			return 0;
		}

		$boundary = null !== $before ? gmdate( 'Y-m-d H:i:s', self::normalize_timestamp( $before ) ) : current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$action_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT action_id FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
				self::get_table_name(),
				WP_Agent_Pending_Action_Status::PENDING,
				$boundary
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$expired = 0;
		foreach ( (array) $action_ids as $action_id ) {
			if ( self::record_resolution( (string) $action_id, WP_Agent_Pending_Action_Status::EXPIRED, null, 'Pending action expired.', 'system:expiration' ) ) {
				++$expired;
			}
		}

		return $expired;
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
	 * Store an Agents API pending-action value object.
	 */
	public static function store_action( WP_Agent_Pending_Action $action ): bool {
		return self::store( $action->get_action_id(), self::action_to_payload( $action ) );
	}

	/**
	 * Fetch a pending action as an Agents API value object.
	 */
	public static function get_action( string $action_id, bool $include_resolved = false ): ?WP_Agent_Pending_Action {
		$payload = self::get( $action_id, $include_resolved );
		if ( null === $payload ) {
			return null;
		}

		try {
			return WP_Agent_Pending_Action::from_array( self::payload_to_action_array( $payload ) );
		} catch ( \InvalidArgumentException $error ) {
			return null;
		}
	}

	/**
	 * List pending actions as Agents API value objects.
	 *
	 * @return array<int,WP_Agent_Pending_Action>
	 */
	public static function list_actions( array $filters = array() ): array {
		$actions = array();
		foreach ( self::list( $filters ) as $payload ) {
			try {
				$actions[] = WP_Agent_Pending_Action::from_array( self::payload_to_action_array( $payload ) );
			} catch ( \InvalidArgumentException $error ) {
				continue;
			}
		}

		return $actions;
	}

	/**
	 * Record a resolution from the Agents API store contract.
	 */
	public static function record_action_resolution( string $action_id, WP_Agent_Approval_Decision $decision, string $resolver, $result = null, ?string $error = null, array $metadata = array() ): bool {
		return self::record_resolution( $action_id, $decision->value(), $result, $error, $resolver, $metadata );
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
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Convert a DB row to the public pending-action payload shape.
	 */
	private static function row_to_payload( array $row ): array {
		$created_at = self::mysql_to_timestamp( (string) ( $row['created_at'] ?? '' ) );
		$expires_at = self::mysql_to_timestamp( (string) ( $row['expires_at'] ?? '' ) );
		$metadata   = self::decode_json( $row['metadata'] ?? null );
		$metadata   = is_array( $metadata ) ? $metadata : array();
		$grants     = isset( $metadata['datamachine']['resolver_grants'] ) && is_array( $metadata['datamachine']['resolver_grants'] ) ? $metadata['datamachine']['resolver_grants'] : array();

		return array(
			'action_id'           => (string) ( $row['action_id'] ?? '' ),
			'kind'                => (string) ( $row['kind'] ?? '' ),
			'summary'             => (string) ( $row['summary'] ?? '' ),
			'preview_data'        => self::decode_json( $row['preview_data'] ?? null ),
			'apply_input'         => self::decode_json( $row['apply_input'] ?? null ),
			'resolver_grants'     => $grants,
			'agent_id'            => isset( $row['agent_id'] ) ? (int) $row['agent_id'] : 0,
			'agent'               => isset( $row['agent'] ) ? (string) $row['agent'] : null,
			'workspace'           => ! empty( $row['workspace_type'] ) && ! empty( $row['workspace_id'] ) ? array(
				'workspace_type' => (string) $row['workspace_type'],
				'workspace_id'   => (string) $row['workspace_id'],
			) : null,
			'created_by'          => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'creator'             => isset( $row['creator'] ) ? (string) $row['creator'] : null,
			'context'             => self::decode_json( $row['context'] ?? null ),
			'metadata'            => $metadata,
			'status'              => (string) ( $row['status'] ?? WP_Agent_Pending_Action_Status::PENDING ),
			'created_at'          => $created_at,
			'created_at_iso'      => $created_at > 0 ? gmdate( 'c', $created_at ) : null,
			'expires_at'          => $expires_at,
			'expires_at_iso'      => $expires_at > 0 ? gmdate( 'c', $expires_at ) : null,
			'resolved_at'         => self::mysql_to_timestamp( (string) ( $row['resolved_at'] ?? '' ) ),
			'resolved_at_iso'     => self::mysql_to_timestamp( (string) ( $row['resolved_at'] ?? '' ) ) > 0 ? gmdate( 'c', self::mysql_to_timestamp( (string) ( $row['resolved_at'] ?? '' ) ) ) : null,
			'resolved_by'         => isset( $row['resolved_by'] ) ? (int) $row['resolved_by'] : 0,
			'resolver'            => isset( $row['resolver'] ) ? (string) $row['resolver'] : null,
			'resolution_result'   => self::decode_json( $row['resolution_result'] ?? null ),
			'resolution_error'    => isset( $row['resolution_error'] ) ? (string) $row['resolution_error'] : null,
			'resolution_metadata' => self::decode_json( $row['resolution_metadata'] ?? null ),
		);
	}

	/**
	 * Convert an Agents API pending action to Data Machine's persisted payload.
	 */
	private static function action_to_payload( WP_Agent_Pending_Action $action ): array {
		$data     = $action->to_array();
		$metadata = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();

		return array(
			'action_id'           => $data['action_id'],
			'kind'                => $data['kind'],
			'summary'             => $data['summary'],
			'preview_data'        => $data['preview'],
			'apply_input'         => $data['apply_input'],
			'workspace'           => $data['workspace'],
			'agent'               => $data['agent'],
			'creator'             => $data['creator'],
			'agent_id'            => $metadata['datamachine']['agent_id'] ?? null,
			'created_by'          => $metadata['datamachine']['created_by'] ?? null,
			'context'             => $metadata['datamachine']['context'] ?? array(),
			'resolver_grants'     => $metadata['datamachine']['resolver_grants'] ?? array(),
			'metadata'            => $metadata,
			'status'              => $data['status'],
			'created_at'          => $data['created_at'],
			'expires_at'          => $data['expires_at'],
			'resolver'            => $data['resolver'],
			'resolution_result'   => $data['resolution_result'],
			'resolution_error'    => $data['resolution_error'],
			'resolution_metadata' => $data['resolution_metadata'],
		);
	}

	/**
	 * Convert Data Machine's row payload to the canonical Agents API value shape.
	 */
	private static function payload_to_action_array( array $payload ): array {
		$created_at  = isset( $payload['created_at_iso'] ) ? $payload['created_at_iso'] : gmdate( 'c', (int) ( $payload['created_at'] ?? time() ) );
		$expires_at  = isset( $payload['expires_at_iso'] ) ? $payload['expires_at_iso'] : ( ! empty( $payload['expires_at'] ) ? gmdate( 'c', (int) $payload['expires_at'] ) : null );
		$resolved_at = isset( $payload['resolved_at_iso'] ) ? $payload['resolved_at_iso'] : ( ! empty( $payload['resolved_at'] ) ? gmdate( 'c', (int) $payload['resolved_at'] ) : null );
		$metadata    = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();

		$metadata['datamachine'] = array_merge(
			isset( $metadata['datamachine'] ) && is_array( $metadata['datamachine'] ) ? $metadata['datamachine'] : array(),
			array(
				'agent_id'        => $payload['agent_id'] ?? 0,
				'created_by'      => $payload['created_by'] ?? 0,
				'context'         => $payload['context'] ?? array(),
				'resolver_grants' => $payload['resolver_grants'] ?? array(),
			)
		);

		return array(
			'action_id'           => (string) ( $payload['action_id'] ?? '' ),
			'kind'                => (string) ( $payload['kind'] ?? '' ),
			'summary'             => (string) ( $payload['summary'] ?? '' ),
			'preview'             => $payload['preview'] ?? $payload['preview_data'] ?? array(),
			'apply_input'         => $payload['apply_input'] ?? array(),
			'workspace'           => $payload['workspace'] ?? null,
			'agent'               => $payload['agent'] ?? ( ! empty( $payload['agent_id'] ) ? 'agent:' . (int) $payload['agent_id'] : null ),
			'creator'             => $payload['creator'] ?? ( ! empty( $payload['created_by'] ) ? 'user:' . (int) $payload['created_by'] : null ),
			'status'              => (string) ( $payload['status'] ?? WP_Agent_Pending_Action_Status::PENDING ),
			'created_at'          => $created_at,
			'expires_at'          => $expires_at,
			'resolved_at'         => $resolved_at,
			'resolver'            => $payload['resolver'] ?? null,
			'resolution_result'   => $payload['resolution_result'] ?? null,
			'resolution_error'    => $payload['resolution_error'] ?? null,
			'resolution_metadata' => isset( $payload['resolution_metadata'] ) && is_array( $payload['resolution_metadata'] ) ? $payload['resolution_metadata'] : array(),
			'metadata'            => $metadata,
		);
	}

	/**
	 * Dispatch a stored lifecycle event from a Data Machine payload.
	 */
	private static function dispatch_stored_payload( array $payload ): void {
		$action = self::action_from_payload( $payload );
		if ( null !== $action ) {
			PendingActionObservers::dispatch_stored( $action );
		}
	}

	/**
	 * Dispatch terminal lifecycle events that have observer contracts.
	 */
	private static function dispatch_resolution( WP_Agent_Pending_Action $action, string $status, string $resolver ): void {
		if ( WP_Agent_Pending_Action_Status::EXPIRED === $status ) {
			PendingActionObservers::dispatch_expired( $action );
			return;
		}

		if ( ! in_array( $status, array( WP_Agent_Approval_Decision::ACCEPTED, WP_Agent_Approval_Decision::REJECTED ), true ) ) {
			return;
		}

		try {
			PendingActionObservers::dispatch_resolved( $action, WP_Agent_Approval_Decision::from_string( $status ), $resolver );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
		}
	}

	/**
	 * Convert a payload to an observer-safe Agents API value object.
	 */
	private static function action_from_payload( array $payload ): ?WP_Agent_Pending_Action {
		try {
			return WP_Agent_Pending_Action::from_array( self::payload_to_action_array( $payload ) );
		} catch ( \InvalidArgumentException $error ) {
			unset( $error );
			return null;
		}
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
	 * Add an owner scope that matches either legacy numeric or canonical principal columns.
	 *
	 * @param array<int,string> $where   SQL where clauses.
	 * @param array<int,mixed>  $args    SQL prepare args.
	 * @param array<string,mixed> $filters Query filters.
	 */
	private static function add_owner_scope_clause( array &$where, array &$args, array $filters ): void {
		if ( empty( $filters['owner_user_id'] ) ) {
			return;
		}

		$owner_user_id = (int) $filters['owner_user_id'];
		if ( $owner_user_id <= 0 ) {
			return;
		}

		$where[] = '(created_by = %d OR creator = %s)';
		$args[]  = $owner_user_id;
		$args[]  = 'user:' . $owner_user_id;
	}

	/**
	 * Add an agent scope that matches either legacy numeric or canonical principal columns.
	 *
	 * @param array<int,string> $where   SQL where clauses.
	 * @param array<int,mixed>  $args    SQL prepare args.
	 * @param array<string,mixed> $filters Query filters.
	 */
	private static function add_agent_scope_clause( array &$where, array &$args, array $filters ): void {
		if ( empty( $filters['agent_scope'] ) || ! is_array( $filters['agent_scope'] ) ) {
			return;
		}

		$agent_id = isset( $filters['agent_scope']['agent_id'] ) ? (int) $filters['agent_scope']['agent_id'] : 0;
		$agent    = isset( $filters['agent_scope']['agent'] ) ? trim( (string) $filters['agent_scope']['agent'] ) : '';

		if ( $agent_id > 0 && '' !== $agent ) {
			$where[] = '(agent_id = %d OR agent = %s)';
			$args[]  = $agent_id;
			$args[]  = $agent;
			return;
		}

		if ( $agent_id > 0 ) {
			$where[] = 'agent_id = %d';
			$args[]  = $agent_id;
			return;
		}

		if ( '' !== $agent ) {
			$where[] = 'agent = %s';
			$args[]  = $agent;
		}
	}

	/**
	 * Check whether a pending-action table column exists.
	 */
	private static function column_exists( string $table_name, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $column ) );

		return null !== $result;
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
	 * Check whether the non-durable transient fallback has been explicitly enabled.
	 */
	private static function allows_transient_fallback(): bool {
		return defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' )
			&& true === DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK;
	}

	/**
	 * Report attempts to use pending actions without durable storage.
	 */
	private static function warn_database_unavailable( string $operation ): void {
		$message = sprintf(
			'PendingActionStore::%s() requires the durable pending-actions table; define DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK only for pure-PHP smoke tests or explicit pre-table boot.',
			$operation
		);

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( esc_html( __CLASS__ . '::' . $operation ), esc_html( $message ), '1.0.0' );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'datamachine_pending_action_store_unavailable', $operation, $message );
		}
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
		try {
			return WP_Agent_Pending_Action_Status::normalize( $status );
		} catch ( \InvalidArgumentException $error ) {
			return '';
		}
	}

	/**
	 * Normalize nullable strings.
	 */
	private static function nullable_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Return the current resolver audit identifier.
	 */
	private static function current_resolver(): string {
		$user_id = get_current_user_id();
		return $user_id > 0 ? 'user:' . $user_id : 'system:anonymous';
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
