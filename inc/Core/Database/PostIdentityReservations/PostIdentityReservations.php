<?php
/**
 * Durable identity reservations for post upserts.
 *
 * @package DataMachine\Core\Database\PostIdentityReservations
 */

namespace DataMachine\Core\Database\PostIdentityReservations;

use DataMachine\Core\Database\BaseRepository;

defined( 'ABSPATH' ) || exit;

// All identifiers and values below pass through wpdb::prepare(). WPCS does not
// recognize nested prepare() calls using WordPress's %i identifier placeholder.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class PostIdentityReservations extends BaseRepository {

	public const TABLE_NAME     = 'datamachine_post_identity_reservations';
	public const SCHEMA_VERSION = 1;
	public const LOCK_TIMEOUT   = 2;

	/** @var array<string,true> Request-local advisory lock ownership. */
	private static array $held_locks = array();

	/** @var array<string,string> Exact lock names acquired by this repository. */
	private array $acquired_locks = array();

	public static function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			identity_hash char(64) NOT NULL,
			post_type_hash char(64) NOT NULL,
			meta_key_hash char(64) NOT NULL,
			meta_value_hash char(64) NOT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			state varchar(20) NOT NULL DEFAULT 'reserved',
			attempt_count bigint(20) unsigned NOT NULL DEFAULT 1,
			last_attempt_at datetime NOT NULL,
			last_error_code varchar(64) DEFAULT NULL,
			last_error_message varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (identity_hash),
			KEY post_id (post_id)
		) ENGINE=InnoDB {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$repository = new self();
		if ( ! BaseRepository::database_table_exists( $table_name, $wpdb ) ) {
			$repository->log_failure( 'install_schema', 'reservation_table_install_failed' );
			return;
		}

		if ( ! $repository->repair_schema() ) {
			$repository->log_failure( 'repair_schema', 'reservation_table_repair_failed' );
			return;
		}

		$validation = $repository->validate_schema();
		if ( is_wp_error( $validation ) ) {
			$repository->log_failure( 'install_schema', (string) $validation->get_error_code() );
		}
	}

	/**
	 * Normalize unslashed caller input and hash length-delimited components.
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize_identity( string $post_type, array $identity ) {
		if ( ! isset( $identity['key'], $identity['value'] ) || ! is_string( $identity['key'] ) || ! is_string( $identity['value'] ) ) {
			return new \WP_Error( 'invalid_identity_meta', 'identity_meta requires a valid non-empty key and value.' );
		}

		$post_type  = sanitize_key( $post_type );
		$meta_key   = sanitize_key( $identity['key'] );
		$meta_value = sanitize_text_field( $identity['value'] );

		if ( '' === $post_type || '' === $meta_key || '' === $meta_value ) {
			return new \WP_Error( 'invalid_identity_meta', 'identity_meta requires a valid non-empty key and value.' );
		}

		$meta_value = sanitize_meta( $meta_key, $meta_value, 'post', $post_type );
		if ( ! is_scalar( $meta_value ) ) {
			return new \WP_Error( 'invalid_identity_meta', 'identity_meta value is not valid for the registered post meta field.' );
		}
		$meta_value = (string) $meta_value;
		if ( '' === $meta_value ) {
			return new \WP_Error( 'invalid_identity_meta', 'identity_meta value is empty after registered post meta sanitization.' );
		}

		$sanitized_again = sanitize_meta( $meta_key, $meta_value, 'post', $post_type );
		if ( ! is_scalar( $sanitized_again ) || (string) $sanitized_again !== $meta_value ) {
			return new \WP_Error( 'identity_meta_sanitizer_unstable', 'Registered post meta sanitization must be idempotent for identity_meta.' );
		}

		$canonical = self::canonical_component( $post_type )
			. self::canonical_component( $meta_key )
			. self::canonical_component( $meta_value );

		return array(
			'post_type'       => $post_type,
			'meta_key'        => $meta_key,
			'meta_value'      => $meta_value,
			'identity_hash'   => hash( 'sha256', $canonical ),
			'post_type_hash'  => hash( 'sha256', $post_type ),
			'meta_key_hash'   => hash( 'sha256', $meta_key ),
			'meta_value_hash' => hash( 'sha256', $meta_value ),
		);
	}

	/** Build a deterministic MySQL-safe lock name for one database/site. */
	public static function build_lock_name( string $database, string $table, string $identity_hash ): string {
		$scope = strlen( $database ) . ':' . $database
			. ';' . strlen( $table ) . ':' . $table
			. ';' . $identity_hash;

		return 'dm-post-identity:' . substr( hash( 'sha256', $scope ), 0, 47 );
	}

	/** Resolve the current site's scoped advisory lock name. */
	public function lock_name( string $identity_hash ): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		return self::build_lock_name( $database, $this->table_name, $identity_hash );
	}

	/** @return true|\WP_Error */
	public function acquire_lock( string $identity_hash ) {
		$lock_name = $this->lock_name( $identity_hash );
		if ( isset( self::$held_locks[ $lock_name ] ) ) {
			return new \WP_Error(
				'identity_lock_reentrant',
				'Recursive post identity acquisition is not allowed; retry is required.',
				array( 'retryable' => true )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT )
		);

		if ( '1' === (string) $acquired ) {
			self::$held_locks[ $lock_name ]         = true;
			$this->acquired_locks[ $identity_hash ] = $lock_name;
			return true;
		}

		$this->log_failure( 'acquire_lock', 'advisory_lock_unavailable', 'warning' );
		return new \WP_Error(
			'identity_lock_unavailable',
			'Post identity is currently locked; retry is required.',
			array( 'retryable' => true )
		);
	}

	/** Release the connection-scoped identity fence. */
	public function release_lock( string $identity_hash ): bool {
		$lock_name = $this->acquired_locks[ $identity_hash ] ?? $this->lock_name( $identity_hash );
		if ( ! isset( self::$held_locks[ $lock_name ] ) ) {
			unset( $this->acquired_locks[ $identity_hash ] );
			$this->log_failure( 'release_lock', 'advisory_lock_not_owned', 'warning' );
			return false;
		}

		$released = null;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$released = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		} finally {
			unset( self::$held_locks[ $lock_name ] );
			unset( $this->acquired_locks[ $identity_hash ] );
		}
		if ( '1' === (string) $released ) {
			return true;
		}

		$this->log_failure( 'release_lock', 'advisory_lock_release_failed', 'warning' );
		return false;
	}

	/**
	 * Validate the complete reservation schema contract for the current site.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_schema() {
		if ( ! BaseRepository::database_table_exists( $this->table_name, $this->wpdb ) ) {
			return new \WP_Error( 'identity_schema_missing', 'Post identity reservation table is missing.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engine = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$database,
				$this->table_name
			)
		);
		if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
			return new \WP_Error( 'identity_schema_engine', 'Post identity reservation table must use InnoDB.' );
		}

		$required_columns = array(
			'identity_hash'      => array(
				'type'     => 'char',
				'length'   => 64,
				'unsigned' => false,
				'nullable' => false,
			),
			'post_type_hash'     => array(
				'type'     => 'char',
				'length'   => 64,
				'unsigned' => false,
				'nullable' => false,
			),
			'meta_key_hash'      => array(
				'type'     => 'char',
				'length'   => 64,
				'unsigned' => false,
				'nullable' => false,
			),
			'meta_value_hash'    => array(
				'type'     => 'char',
				'length'   => 64,
				'unsigned' => false,
				'nullable' => false,
			),
			'post_id'            => array(
				'type'     => 'bigint',
				'unsigned' => true,
				'nullable' => true,
			),
			'state'              => array(
				'type'     => 'varchar',
				'length'   => 20,
				'unsigned' => false,
				'nullable' => false,
				'default'  => 'reserved',
			),
			'attempt_count'      => array(
				'type'     => 'bigint',
				'unsigned' => true,
				'nullable' => false,
				'default'  => '1',
			),
			'last_attempt_at'    => array(
				'type'     => 'datetime',
				'unsigned' => false,
				'nullable' => false,
			),
			'last_error_code'    => array(
				'type'     => 'varchar',
				'length'   => 64,
				'unsigned' => false,
				'nullable' => true,
			),
			'last_error_message' => array(
				'type'     => 'varchar',
				'length'   => 255,
				'unsigned' => false,
				'nullable' => true,
			),
			'created_at'         => array(
				'type'     => 'datetime',
				'unsigned' => false,
				'nullable' => false,
			),
			'updated_at'         => array(
				'type'     => 'datetime',
				'unsigned' => false,
				'nullable' => false,
			),
			'completed_at'       => array(
				'type'     => 'datetime',
				'unsigned' => false,
				'nullable' => true,
			),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table_name ), ARRAY_A );
		$columns = array_column( array_map( array( self::class, 'normalize_column_metadata' ), $columns ), null, 'name' );
		foreach ( $required_columns as $name => $expected ) {
			if ( empty( $columns[ $name ] ) || ! self::column_matches( $columns[ $name ], $expected ) ) {
				return new \WP_Error( 'identity_schema_columns', 'Post identity reservation table has an invalid required column.' );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = self::normalize_index_metadata( $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $this->table_name ), ARRAY_A ) );
		$primary = $indexes['PRIMARY'] ?? null;
		if ( ! is_array( $primary ) || $primary['non_unique'] || array( 'identity_hash' ) !== $primary['columns'] ) {
			return new \WP_Error( 'identity_schema_primary_key', 'Post identity reservation identity_hash primary key is invalid.' );
		}

		$has_nonunique_post_id = false;
		$has_unique_post_id    = false;
		foreach ( $indexes as $name => $index ) {
			if ( 'PRIMARY' === $name || array( 'post_id' ) !== $index['columns'] ) {
				continue;
			}
			if ( $index['non_unique'] ) {
				$has_nonunique_post_id = true;
			} else {
				$has_unique_post_id = true;
			}
		}
		if ( ! $has_nonunique_post_id || $has_unique_post_id ) {
			return new \WP_Error( 'identity_schema_post_index', 'Post identity reservation post_id requires a nonunique index.' );
		}

		return true;
	}

	/** Repair schema details that dbDelta does not reliably reconcile. */
	private function repair_schema(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engine = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$database,
				$this->table_name
			)
		);
		if ( 'INNODB' !== strtoupper( (string) $engine ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $this->table_name ) ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = self::normalize_index_metadata( $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $this->table_name ), ARRAY_A ) );
		foreach ( $indexes as $name => $index ) {
			if ( 'PRIMARY' === $name || $index['non_unique'] || array( 'post_id' ) !== $index['columns'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $this->table_name, $name ) ) ) {
				return false;
			}
		}

		// Re-read after drops because dbDelta can leave a same-name malformed index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = self::normalize_index_metadata( $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $this->table_name ), ARRAY_A ) );
		foreach ( $indexes as $name => $index ) {
			if ( 'PRIMARY' !== $name && $index['non_unique'] && array( 'post_id' ) === $index['columns'] ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->wpdb->query(
			$this->wpdb->prepare( 'ALTER TABLE %i ADD INDEX %i (post_id)', $this->table_name, 'datamachine_post_id_lookup' )
		);
	}

	/** Normalize SHOW COLUMNS output across MySQL and MariaDB spellings. */
	private static function normalize_column_metadata( array $column ): array {
		$type = strtolower( preg_replace( '/\s+/', ' ', trim( (string) ( $column['Type'] ?? $column['type'] ?? '' ) ) ) );
		preg_match( '/^([a-z]+)(?:\((\d+)(?:,\d+)?\))?/', $type, $matches );

		return array(
			'name'     => (string) ( $column['Field'] ?? $column['field'] ?? '' ),
			'type'     => $matches[1] ?? '',
			'length'   => isset( $matches[2] ) ? (int) $matches[2] : null,
			'unsigned' => (bool) preg_match( '/(?:^| )unsigned(?: |$)/', $type ),
			'nullable' => 'YES' === strtoupper( (string) ( $column['Null'] ?? $column['null'] ?? '' ) ),
			'default'  => $column['Default'] ?? $column['default'] ?? null,
		);
	}

	private static function column_matches( array $actual, array $expected ): bool {
		foreach ( array( 'type', 'unsigned', 'nullable' ) as $property ) {
			if ( $actual[ $property ] !== $expected[ $property ] ) {
				return false;
			}
		}
		if ( isset( $expected['length'] ) && $actual['length'] !== $expected['length'] ) {
			return false;
		}
		return ! array_key_exists( 'default', $expected ) || (string) $actual['default'] === (string) $expected['default'];
	}

	/** Group ordered SHOW INDEX rows into exact index definitions. */
	private static function normalize_index_metadata( array $rows ): array {
		$indexes = array();
		foreach ( $rows as $row ) {
			$name = (string) ( $row['Key_name'] ?? $row['key_name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array(
					'non_unique' => 1 === (int) ( $row['Non_unique'] ?? $row['non_unique'] ?? 0 ),
					'parts'      => array(),
				);
			}
			$sequence                               = (int) ( $row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0 );
			$indexes[ $name ]['parts'][ $sequence ] = (string) ( $row['Column_name'] ?? $row['column_name'] ?? '' );
		}
		foreach ( $indexes as &$index ) {
			ksort( $index['parts'], SORT_NUMERIC );
			$index['columns'] = array_values( $index['parts'] );
			unset( $index['parts'] );
		}
		unset( $index );

		return $indexes;
	}

	/**
	 * Reserve an identity and return its one durable post ID.
	 *
	 * @return array|\WP_Error
	 */
	public function reserve_and_resolve(
		string $post_type,
		array $identity,
		int $explicit_post_id = 0,
		int $slug_fallback_id = 0,
		array $shell = array()
	) {
		$identity = self::normalize_identity( $post_type, $identity );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$storage = $this->verify_transactional_storage();
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$reserved = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO %i
				(identity_hash, post_type_hash, meta_key_hash, meta_value_hash, state, attempt_count, last_attempt_at, created_at, updated_at)
				VALUES (%s, %s, %s, %s, 'reserved', 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1, last_attempt_at = VALUES(last_attempt_at), updated_at = VALUES(updated_at)",
				$this->table_name,
				$identity['identity_hash'],
				$identity['post_type_hash'],
				$identity['meta_key_hash'],
				$identity['meta_value_hash'],
				$now,
				$now,
				$now
			)
		);
		if ( false === $reserved ) {
			$this->log_failure( 'reserve', 'reservation_insert_failed' );
			return new \WP_Error( 'identity_reservation_failed', 'Could not reserve post identity.' );
		}

		$row = $this->get_reservation( $identity['identity_hash'] );
		if ( null === $row ) {
			$this->log_failure( 'reserve_reread', 'reservation_read_failed' );
			return new \WP_Error( 'identity_reservation_unavailable', 'Post identity reservation could not be read after writing.' );
		}
		if ( ! $this->components_match( $row, $identity ) ) {
			return new \WP_Error( 'identity_integrity_conflict', 'Post identity reservation component mismatch.' );
		}

		do_action( 'datamachine_post_identity_before_allocation', $identity['identity_hash'] );

		return $this->resolve_locked( $identity, $explicit_post_id, $slug_fallback_id, $shell );
	}

	/** @return true|\WP_Error */
	public function mark_complete( array $identity, int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== $identity['post_type'] ) {
			$this->record_error( $identity['identity_hash'], 'identity_link_invalid', 'Linked post is missing or has the wrong post type.' );
			return new \WP_Error( 'identity_link_invalid', 'Linked post is missing or has the wrong post type.' );
		}

		if ( (string) get_post_meta( $post_id, $identity['meta_key'], true ) !== $identity['meta_value'] ) {
			$this->record_error( $identity['identity_hash'], 'identity_meta_incomplete', 'Linked post identity metadata is incomplete.' );
			return new \WP_Error( 'identity_meta_incomplete', 'Linked post identity metadata is incomplete.' );
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET state = 'complete', completed_at = %s, updated_at = %s, last_error_code = NULL, last_error_message = NULL WHERE identity_hash = %s AND post_id = %d",
				$this->table_name,
				$now,
				$now,
				$identity['identity_hash'],
				$post_id
			)
		);

		if ( false === $updated ) {
			$this->log_failure( 'mark_complete', 'reservation_completion_failed' );
			return new \WP_Error( 'identity_completion_failed', 'Could not complete post identity reservation.' );
		}

		$row = $this->get_reservation( $identity['identity_hash'] );
		if ( null === $row || 'complete' !== $row['state'] || $post_id !== (int) $row['post_id'] ) {
			$this->log_failure( 'mark_complete_verify', 'reservation_completion_verify_failed' );
			return new \WP_Error( 'identity_completion_failed', 'Could not complete post identity reservation.' );
		}

		return true;
	}

	/** Record bounded diagnostics without persisting identity input. */
	public function record_error( string $identity_hash, string $code, string $message = 'Post upsert failed.' ): void {
		$code    = substr( sanitize_key( $code ), 0, 64 );
		$message = substr( sanitize_text_field( $message ), 0, 255 );
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET last_error_code = %s, last_error_message = %s, updated_at = %s WHERE identity_hash = %s',
				$this->table_name,
				$code,
				$message,
				$now,
				$identity_hash
			)
		);
		if ( false === $updated ) {
			$this->log_failure( 'record_error', 'reservation_error_record_failed' );
		}
	}

	public function get_reservation( string $identity_hash ): ?array {
		return $this->find_by_id( 'identity_hash', $identity_hash );
	}

	/** @return array|\WP_Error */
	protected function resolve_locked(
		array $identity,
		int $explicit_post_id = 0,
		int $slug_fallback_id = 0,
		array $shell = array()
	) {
		if ( ! $this->start_transaction() ) {
			$this->log_failure( 'start_transaction', 'transaction_start_failed' );
			$this->record_error( $identity['identity_hash'], 'identity_transaction_failed', 'Could not start post identity transaction.' );
			return new \WP_Error( 'identity_transaction_failed', 'Could not start post identity transaction.' );
		}

		$commit_attempted = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare( 'SELECT * FROM %i WHERE identity_hash = %s FOR UPDATE', $this->table_name, $identity['identity_hash'] ),
				ARRAY_A
			);
			if ( ! is_array( $row ) || ! $this->components_match( $row, $identity ) ) {
				$this->rollback_transaction();
				return new \WP_Error( 'identity_integrity_conflict', 'Post identity reservation component mismatch.' );
			}

			$post_id   = (int) ( $row['post_id'] ?? 0 );
			$allocated = false;
			$adopted   = false;
			if ( $post_id > 0 ) {
				if ( $explicit_post_id > 0 && $post_id !== $explicit_post_id ) {
					$this->set_locked_error( $identity['identity_hash'], 'identity_explicit_conflict', 'Explicit post conflicts with the linked identity reservation.' );
					$commit_attempted = true;
					if ( ! $this->commit_transaction() ) {
						return $this->commit_uncertain_error();
					}
					return new \WP_Error(
						'identity_explicit_conflict',
						'Explicit post conflicts with the linked identity reservation.',
						array(
							'linked_post_id'   => $post_id,
							'explicit_post_id' => $explicit_post_id,
						)
					);
				}

				$linked_type = $this->linked_post_type( $post_id );
				if ( null === $linked_type || $linked_type !== $identity['post_type'] ) {
					$this->set_locked_error( $identity['identity_hash'], 'identity_link_invalid', 'Linked post is missing or has the wrong post type.' );
					$commit_attempted = true;
					if ( ! $this->commit_transaction() ) {
						return $this->commit_uncertain_error();
					}
					return new \WP_Error( 'identity_link_invalid', 'Linked post is missing or has the wrong post type.', array( 'post_id' => $post_id ) );
				}
			} else {
				$candidates = array();
				if ( $explicit_post_id > 0 ) {
					$post_id = $explicit_post_id;
					$adopted = true;
				} else {
					$candidates = $this->find_legacy_candidates( $identity );
				}

				if ( $explicit_post_id <= 0 && count( $candidates ) > 1 ) {
					$bounded = array_slice( $candidates, 0, 20 );
					$message = sprintf( 'Multiple posts claim this identity (candidate IDs: %s).', implode( ',', $bounded ) );
					$this->set_locked_conflict( $identity['identity_hash'], $message );
					$commit_attempted = true;
					if ( ! $this->commit_transaction() ) {
						return $this->commit_uncertain_error();
					}
					return new \WP_Error(
						'identity_legacy_conflict',
						'Multiple posts claim this identity.',
						array(
							'candidate_count' => count( $candidates ),
							'candidate_ids'   => $bounded,
						)
					);
				}

				if ( $explicit_post_id <= 0 ) {
					if ( 1 === count( $candidates ) ) {
						$post_id = (int) $candidates[0];
						$adopted = true;
					} elseif ( $slug_fallback_id > 0 ) {
						$post_id = $slug_fallback_id;
						$adopted = true;
					} else {
						$post_id   = $this->insert_draft_shell( $identity['post_type'], $shell );
						$allocated = true;
					}
				}

				$resolved_type = $post_id > 0 ? $this->linked_post_type( $post_id ) : null;
				if ( null === $resolved_type || $resolved_type !== $identity['post_type'] ) {
					$this->rollback_transaction();
					$this->record_error( $identity['identity_hash'], 'identity_candidate_invalid', 'Resolved post is missing or has the wrong post type.' );
					return new \WP_Error( 'identity_candidate_invalid', 'Resolved post is missing or has the wrong post type.' );
				}

				if ( ! $this->link_locked_reservation( $identity['identity_hash'], $post_id ) ) {
					$this->rollback_transaction();
					$this->log_failure( 'link_reservation', 'reservation_link_failed' );
					$this->record_error( $identity['identity_hash'], 'identity_allocation_failed', 'Could not atomically allocate the post identity.' );
					return new \WP_Error( 'identity_allocation_failed', 'Could not atomically allocate the post identity.' );
				}
			}

			$commit_attempted = true;
			if ( ! $this->commit_transaction() ) {
				$this->log_failure( 'commit_allocation', 'transaction_commit_uncertain' );
				return $this->commit_uncertain_error();
			}
		} catch ( \Throwable $throwable ) {
			if ( $commit_attempted ) {
				return $this->commit_uncertain_error();
			}
			$this->rollback_transaction();
			$this->record_error( $identity['identity_hash'], 'identity_allocation_failed', 'Could not atomically allocate the post identity.' );
			return new \WP_Error( 'identity_allocation_failed', 'Could not atomically allocate the post identity.' );
		}

		clean_post_cache( $post_id );

		return array(
			'post_id'   => $post_id,
			'allocated' => $allocated,
			'adopted'   => $adopted,
			'identity'  => $identity,
			'shell'     => $shell,
		);
	}

	/** @return true|\WP_Error */
	protected function verify_transactional_storage() {
		if ( self::is_sqlite() ) {
			return new \WP_Error( 'identity_storage_unsupported', 'Identity-backed post upserts require transactional InnoDB storage.' );
		}
		$schema = $this->validate_schema();
		if ( is_wp_error( $schema ) ) {
			$this->log_failure( 'validate_schema', (string) $schema->get_error_code() );
			return $schema;
		}

		$tables = array( $this->table_name, $this->wpdb->posts );
		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				return new \WP_Error( 'identity_storage_invalid', 'Post identity storage has an invalid table name.' );
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$database_name = (string) $this->wpdb->get_var( 'SELECT DATABASE()' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$engines = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders})",
				$database_name,
				...$tables
			),
			OBJECT_K
		);

		foreach ( $tables as $table ) {
			if ( empty( $engines[ $table ] ) || 'INNODB' !== strtoupper( (string) $engines[ $table ]->ENGINE ) ) {
				$this->log_failure( 'verify_storage', 'transactional_storage_unavailable' );
				return new \WP_Error( 'identity_storage_unsupported', 'Identity-backed post upserts require transactional InnoDB storage.' );
			}
		}

		return true;
	}

	/** @return int[] */
	protected function find_legacy_candidates( array $identity ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i pm ON pm.post_id = p.ID WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY p.ID ASC LIMIT 51',
				$this->wpdb->posts,
				$this->wpdb->postmeta,
				$identity['post_type'],
				$identity['meta_key'],
				$identity['meta_value']
			)
		);

		return array_map( 'intval', $ids );
	}

	protected function insert_draft_shell( string $post_type, array $shell = array() ): int {
		$author         = absint( $shell['post_author'] ?? 0 );
		$comment_status = sanitize_key( $shell['comment_status'] ?? 'closed' );
		$ping_status    = sanitize_key( $shell['ping_status'] ?? 'closed' );
		$now_local      = (string) ( $shell['post_date'] ?? '' );
		$now_gmt        = (string) ( $shell['post_date_gmt'] ?? '' );
		$guid           = (string) ( $shell['guid'] ?? '' );
		if ( '' === $now_local || '' === $now_gmt || '' === $guid ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO %i
				(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
				VALUES (%d, %s, %s, '', '', '', 'draft', %s, %s, '', '', '', '', %s, %s, '', 0, %s, 0, %s, '', 0)",
				$this->wpdb->posts,
				$author,
				$now_local,
				$now_gmt,
				$comment_status,
				$ping_status,
				$now_local,
				$now_gmt,
				$guid,
				$post_type
			)
		);
		if ( false === $inserted ) {
			$this->log_failure( 'insert_shell', 'shell_insert_failed' );
		}

		return false === $inserted ? 0 : (int) $this->wpdb->insert_id;
	}

	protected function linked_post_type( int $post_id ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT post_type FROM %i WHERE ID = %d', $this->wpdb->posts, $post_id ) );
		return null === $value ? null : (string) $value;
	}

	protected function link_locked_reservation( string $identity_hash, int $post_id ): bool {
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET post_id = %d, state = 'linked', updated_at = %s, last_error_code = NULL, last_error_message = NULL WHERE identity_hash = %s AND post_id IS NULL",
				$this->table_name,
				$post_id,
				$now,
				$identity_hash
			)
		);
		return 1 === $result;
	}

	protected function set_locked_conflict( string $identity_hash, string $message ): void {
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET state = 'conflict', last_error_code = 'identity_legacy_conflict', last_error_message = %s, updated_at = %s WHERE identity_hash = %s",
				$this->table_name,
				substr( $message, 0, 255 ),
				$now,
				$identity_hash
			)
		);
	}

	protected function set_locked_error( string $identity_hash, string $code, string $message ): void {
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET last_error_code = %s, last_error_message = %s, updated_at = %s WHERE identity_hash = %s',
				$this->table_name,
				substr( sanitize_key( $code ), 0, 64 ),
				substr( sanitize_text_field( $message ), 0, 255 ),
				$now,
				$identity_hash
			)
		);
	}

	protected function start_transaction(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->wpdb->query( 'START TRANSACTION' );
	}

	protected function commit_transaction(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $this->wpdb->query( 'COMMIT' );
	}

	protected function rollback_transaction(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'ROLLBACK' );
	}

	private function commit_uncertain_error(): \WP_Error {
		return new \WP_Error(
			'identity_commit_uncertain',
			'Post identity transaction commit outcome is uncertain; retry is required.',
			array( 'retryable' => true )
		);
	}

	private static function canonical_component( string $value ): string {
		return strlen( $value ) . ':' . $value . ';';
	}

	private function components_match( array $row, array $identity ): bool {
		return hash_equals( (string) $row['post_type_hash'], $identity['post_type_hash'] )
			&& hash_equals( (string) $row['meta_key_hash'], $identity['meta_key_hash'] )
			&& hash_equals( (string) $row['meta_value_hash'], $identity['meta_value_hash'] );
	}

	/** Log privacy-safe database diagnostics without SQL or identity material. */
	private function log_failure( string $operation, string $fallback_error, string $level = 'error' ): void {
		$error = trim( (string) $this->wpdb->last_error );
		if ( '' === $error ) {
			$error = $fallback_error;
		}
		$error = preg_replace( '/\b[a-f0-9]{64}\b/i', '[redacted]', $error );

		do_action(
			'datamachine_log',
			$level,
			'Post identity database operation failed',
			array(
				'operation' => $operation,
				'table'     => $this->table_name,
				'error'     => substr( (string) $error, 0, 255 ),
			)
		);
	}
}
