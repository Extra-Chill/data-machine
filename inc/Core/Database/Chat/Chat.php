<?php
/**
 * Chat Database Operations
 *
 * Unified database component for chat session management including
 * table creation and CRUD operations for persistent conversation storage.
 *
 * @package DataMachine\Core\Database\Chat
 * @since 0.2.0
 */

namespace DataMachine\Core\Database\Chat;

use DataMachine\Core\Admin\DateFormatter;
use DataMachine\Abilities\Chat\ChatTranscriptOwner;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\LifecycleStateTransition;
use DataMachine\Core\Database\TransactionScope;
use AgentsAPI\AI\WP_Agent_Execution_Principal;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Core\Workspace\WordPressWorkspaceScope;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Database Manager
 *
 * Implements {@see ConversationStoreInterface} so the conversation
 * storage backend can be swapped via the `datamachine_conversation_store`
 * filter. Resolve via {@see ConversationStoreFactory::get()} rather than
 * instantiating this class directly.
 */
class Chat extends BaseRepository implements ConversationStoreInterface {
	private const MIGRATION_BATCH_SIZE            = 100;
	private const MIGRATION_COLLISION_PROBE_LIMIT = 100;
	private const MIGRATION_DEADLOCK_ATTEMPTS     = 5;
	private const MIGRATION_DEADLOCK_BACKOFF_US   = 20000;
	private const MIGRATION_NUMERIC_COLUMNS       = array( 'user_id', 'agent_id' );

	/**
	 * Table name (without prefix)
	 */
	const TABLE_NAME = 'datamachine_chat_sessions';

	/**
	 * Use network-level prefix so chat sessions are shared across the multisite network.
	 *
	 * A user's chat history follows them to every subsite — consistent with the
	 * agent identity, tokens, and access tables, which already use base_prefix.
	 *
	 * @return string
	 */
	protected static function get_table_prefix(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Create chat sessions table
	 *
	 * Uses dbDelta for safe table creation/updates
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_escaped_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			session_id VARCHAR(50) NOT NULL,
			workspace_type VARCHAR(50) NOT NULL,
			workspace_id VARCHAR(191) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			owner_type VARCHAR(40) NOT NULL DEFAULT 'user',
			owner_key_hash VARCHAR(64) NOT NULL,
			owner_label VARCHAR(191) NULL,
			agent_id BIGINT(20) UNSIGNED NULL,
			title VARCHAR(100) NULL,
			messages LONGTEXT NOT NULL,
			metadata LONGTEXT NULL,
			provider VARCHAR(50) NULL,
			model VARCHAR(100) NULL,
			provider_response_id VARCHAR(191) NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'chat',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			last_read_at DATETIME NULL,
			expires_at DATETIME NULL,
			transcript_lock_token VARCHAR(64) NULL,
			transcript_lock_expires_at DATETIME NULL,
			PRIMARY KEY  (session_id),
			KEY workspace (workspace_type, workspace_id),
			KEY user_id (user_id),
			KEY owner (owner_type, owner_key_hash),
			KEY agent_id (agent_id),
			KEY mode (mode),
			KEY user_mode (user_id, mode),
			KEY created_at (created_at),
			KEY updated_at (updated_at),
			KEY expires_at (expires_at),
			KEY transcript_lock_expires_at (transcript_lock_expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Converge legacy per-site chat session tables into the network table.
	 *
	 * Source tables remain intact. Re-running recovers sessions stranded by a
	 * failed or interrupted earlier copy. Legacy rows are normalized into the
	 * canonical workspace/owner shape, and completion requires scoped parity.
	 *
	 * @return array{success:bool,copied:int,missing:int,error:string} Convergence result.
	 */
	public static function migrate_per_site_tables_to_network(): array {
		global $wpdb;

		$convergence = array(
			'success' => true,
			'copied'  => 0,
			'missing' => 0,
			'error'   => '',
		);

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			return $convergence;
		}

		if ( ! self::table_exists() ) {
			$convergence['success'] = false;
			$convergence['error']   = 'The network chat sessions table does not exist.';
			return $convergence;
		}

		$network_table = self::get_prefixed_table_name();
		$blog_ids      = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		$target_columns  = self::migration_table_columns( $network_table );
		$required_target = array( 'session_id', 'workspace_type', 'workspace_id', 'user_id', 'owner_type', 'owner_key_hash', 'messages', 'metadata', 'mode' );
		if ( array_diff( $required_target, $target_columns ) ) {
			$convergence['success'] = false;
			$convergence['error']   = 'The network chat sessions table lacks canonical scope columns.';
			return $convergence;
		}

		foreach ( $blog_ids as $blog_id ) {
			$site_prefix = $wpdb->get_blog_prefix( (int) $blog_id );
			$site_table  = self::sanitize_table_name( $site_prefix . self::TABLE_NAME );
			if ( $site_table === $network_table ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $site_table ) );
			if ( $exists !== $site_table ) {
				continue;
			}

			$source_columns = self::migration_table_columns( $site_table );
			if ( array_diff( array( 'session_id', 'user_id', 'messages' ), $source_columns ) ) {
				$convergence['success'] = false;
				$convergence['error']   = sprintf( 'Chat session source table %s lacks required legacy columns.', $site_table );
				return $convergence;
			}

			$cursor = '';
			do {
				// The source table is immutable during convergence, so a source-key
				// cursor cannot be disturbed by writes to the network target.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id > %s ORDER BY session_id ASC LIMIT %d', $site_table, $cursor, self::MIGRATION_BATCH_SIZE ), ARRAY_A );
				if ( ! is_array( $rows ) ) {
					$convergence['success'] = false;
					$convergence['error']   = sprintf( 'Failed reading chat sessions from %s: %s', $site_table, (string) $wpdb->last_error );
					return $convergence;
				}
				$row_count = count( $rows );

				foreach ( $rows as $row ) {
					$cursor    = (string) ( $row['session_id'] ?? '' );
					$canonical = self::canonicalize_migration_row( $row, (int) $blog_id, $target_columns );
					if ( null === $canonical ) {
						$convergence['success'] = false;
						$convergence['error']   = sprintf( 'Chat session %s in %s cannot be mapped to a canonical workspace and owner.', (string) ( $row['session_id'] ?? '' ), $site_table );
						return $convergence;
					}

					$source_session_id = $canonical['session_id'];
					$canonical         = self::converge_migration_row( $network_table, $canonical, (int) $blog_id, $source_session_id, $target_columns, $convergence['copied'] );
					if ( null === $canonical ) {
						$convergence['success'] = false;
						$convergence['error']   = sprintf( 'Failed converging chat session %s from %s: %s', $source_session_id, $site_table, (string) $wpdb->last_error );
						return $convergence;
					}

					$verified = self::migration_target_row( $network_table, $canonical['session_id'] );
					if ( ! is_array( $verified ) || ! self::migration_rows_match( $verified, $canonical ) || ! self::migration_has_provenance( $verified, (int) $blog_id, $source_session_id ) ) {
						++$convergence['missing'];
					}
				}
			} while ( self::MIGRATION_BATCH_SIZE === $row_count );
		}

		if ( 0 === $convergence['missing'] && ! self::claim_unscoped_network_rows_for_main_site( $network_table, $target_columns, $convergence ) ) {
			return $convergence;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$unscoped = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE workspace_type IS NULL OR workspace_type = %s OR workspace_id IS NULL OR workspace_id = %s', $network_table, '', '' ) );
		if ( $unscoped > 0 ) {
			$convergence['success'] = false;
			$convergence['missing'] = $unscoped;
			$convergence['error']   = sprintf( '%d canonical chat sessions remain unscoped.', $unscoped );
			return $convergence;
		}

		if ( $convergence['missing'] > 0 ) {
			$convergence['success'] = false;
			$convergence['error']   = sprintf( '%d per-site chat sessions are still absent from the network table.', $convergence['missing'] );
		}

		return $convergence;
	}

	/**
	 * Read a table's column names for bounded migration normalization.
	 *
	 * Different installs created the chat table at different schema versions, so
	 * migration normalization first inventories each live shape.
	 *
	 * @return string[] Column names.
	 */
	private static function migration_table_columns( string $table_name ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ), ARRAY_A );
		return array_values( array_filter( array_map( static fn( array $column ): string => (string) ( $column['Field'] ?? $column['field'] ?? '' ), is_array( $columns ) ? $columns : array() ) ) );
	}

	/**
	 * Read one candidate target row during convergence.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function migration_target_row( string $table_name, string $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %s', $table_name, $session_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Converge one immutable source snapshot without overwriting a changed target.
	 *
	 * @param int $copied Updated when this call inserts a canonical row.
	 * @return array<string,mixed>|null Persisted canonical candidate, or null on failure.
	 */
	private static function converge_migration_row( string $table_name, array $source, int $blog_id, string $source_session_id, array $target_columns, int &$copied ): ?array {
		global $wpdb;

		for ( $retry = 0; $retry < 3; ++$retry ) {
			$canonical = $source;
			$existing  = self::migration_target_row( $table_name, $source_session_id );
			$observed  = $existing;

			if ( is_array( $existing ) && self::migration_row_is_unscoped( $existing ) ) {
				$normalized_existing = self::canonicalize_migration_row( $existing, $blog_id, $target_columns );
				if ( is_array( $normalized_existing ) && self::migration_rows_match( $normalized_existing, $canonical ) ) {
					$existing = $normalized_existing;
				}
			}

			if ( is_array( $existing ) && ! self::migration_rows_match( $existing, $canonical ) ) {
				$preferred_version = self::migration_preferred_logical_session_version( $existing, $canonical );
				if ( 'target' === $preferred_version ) {
					$canonical = self::canonicalize_migration_row( $existing, $blog_id, $target_columns );
				}

				if ( '' === $preferred_version ) {
					$collision_available = false;
					for ( $collision_attempt = 0; $collision_attempt < self::MIGRATION_COLLISION_PROBE_LIMIT; ++$collision_attempt ) {
						$canonical['session_id'] = self::migration_collision_session_id( $canonical, $blog_id, $source_session_id, $collision_attempt );
						$existing                = self::migration_target_row( $table_name, $canonical['session_id'] );
						if ( ! is_array( $existing ) || self::migration_rows_match( $existing, $canonical ) ) {
							$collision_available = true;
							break;
						}
					}
					if ( ! $collision_available ) {
						$wpdb->last_error = sprintf( 'Exhausted %d deterministic collision IDs for chat session %s.', self::MIGRATION_COLLISION_PROBE_LIMIT, $source_session_id );
						return null;
					}
					$observed = $existing;
				}
			}

			if ( null === $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( false !== $wpdb->insert( $table_name, $canonical ) ) {
					++$copied;
					return $canonical;
				}

				// A competing insert is safe to re-evaluate; a missing row is a real SQL failure.
				if ( null === self::migration_target_row( $table_name, (string) $canonical['session_id'] ) ) {
					return null;
				}
				continue;
			}

			if ( self::migration_rows_match( $observed, $canonical ) && self::migration_has_provenance( $observed, $blog_id, $source_session_id ) ) {
				return $canonical;
			}

			$updated = self::update_migration_target( $table_name, $canonical, $observed );
			if ( false === $updated ) {
				return null;
			}

			$persisted = self::migration_target_row( $table_name, (string) $canonical['session_id'] );
			if ( is_array( $persisted ) && self::migration_rows_match( $persisted, $canonical ) && self::migration_has_provenance( $persisted, $blog_id, $source_session_id ) ) {
				return $canonical;
			}
		}

		return null;
	}

	/** Update changed columns only while holding an exact lock on the observed row. */
	private static function update_migration_target( string $table_name, array $canonical, array $observed ) {
		global $wpdb;

		for ( $attempt = 1; $attempt <= self::MIGRATION_DEADLOCK_ATTEMPTS; ++$attempt ) {
			$previous_suppression = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$scope                = TransactionScope::begin( $wpdb );
			$updated              = false;
			$db_error             = null === $scope ? (string) $wpdb->last_error : '';

			if ( null !== $scope ) {
				$locked = self::migration_target_row_for_update( $table_name, (string) ( $canonical['session_id'] ?? '' ) );
				if ( ! is_array( $locked ) ) {
					$db_error = (string) $wpdb->last_error;
					$scope->rollback();
					$updated = '' !== $db_error ? false : 0;
				} elseif ( ! self::migration_observation_matches( $locked, $observed ) ) {
					$scope->rollback();
					$updated = 0;
				} else {
					$changes = array_filter(
						$canonical,
						static fn( $value, string $column ): bool => ! self::migration_values_match( $locked[ $column ] ?? null, $value, $column ),
						ARRAY_FILTER_USE_BOTH
					);
					if ( ! empty( $changes ) && array_key_exists( 'updated_at', $canonical ) && ! array_key_exists( 'updated_at', $changes ) ) {
						// Prevent the table's ON UPDATE clause from replacing the canonical source version.
						$changes['updated_at'] = $canonical['updated_at'];
					}

					if ( empty( $changes ) ) {
						$updated  = $scope->commit() ? 0 : false;
						$db_error = false === $updated ? (string) $wpdb->last_error : '';
					} else {
						$set       = array();
						$arguments = array( $table_name );
						foreach ( $changes as $column => $value ) {
							if ( null === $value ) {
								$set[]       = '%i = NULL';
								$arguments[] = $column;
							} elseif ( in_array( $column, self::MIGRATION_NUMERIC_COLUMNS, true ) ) {
								$set[]       = '%i = %d';
								$arguments[] = $column;
								$arguments[] = (int) $value;
							} else {
								$set[]       = '%i = %s';
								$arguments[] = $column;
								$arguments[] = (string) $value;
							}
						}
						$arguments[] = 'session_id';
						$arguments[] = (string) $canonical['session_id'];
						$sql         = 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE %i = %s';
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
						$updated = $wpdb->query( $wpdb->prepare( $sql, ...$arguments ) );
						if ( false === $updated ) {
							$db_error = (string) $wpdb->last_error;
							$scope->rollback();
						} elseif ( ! $scope->commit() ) {
							$db_error = (string) $wpdb->last_error;
							$scope->rollback();
							$updated = false;
						}
					}
				}
			}

			if ( null !== $previous_suppression ) {
				$wpdb->suppress_errors( $previous_suppression );
			}

			if ( false !== $updated || ! LifecycleStateTransition::is_deadlock_error( $db_error ) || $attempt >= self::MIGRATION_DEADLOCK_ATTEMPTS ) {
				if ( false === $updated && '' !== $db_error ) {
					$wpdb->last_error = $db_error;
				}
				return $updated;
			}

			do_action(
				'datamachine_log',
				'warning',
				'Chat session migration update deadlocked; retrying',
				array(
					'session_id'   => (string) ( $canonical['session_id'] ?? '' ),
					'attempt'      => $attempt,
					'max_attempts' => self::MIGRATION_DEADLOCK_ATTEMPTS,
					'db_error'     => $db_error,
				)
			);

			usleep( self::MIGRATION_DEADLOCK_BACKOFF_US * $attempt + random_int( 0, self::MIGRATION_DEADLOCK_BACKOFF_US ) );
		}

		return false;
	}

	/** Read and lock one migration target for an atomic compare-and-update. */
	private static function migration_target_row_for_update( string $table_name, string $session_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %s FOR UPDATE', $table_name, $session_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Confirm the locked row is still the exact snapshot observed before locking. */
	private static function migration_observation_matches( array $locked, array $observed ): bool {
		foreach ( $observed as $column => $value ) {
			if ( ! array_key_exists( $column, $locked ) || ! self::migration_values_match( $locked[ $column ], $value, $column ) ) {
				return false;
			}
		}

		return true;
	}

	private static function migration_values_match( $stored, $expected, string $column ): bool {
		if ( null === $stored || null === $expected ) {
			return null === $stored && null === $expected;
		}
		if ( in_array( $column, self::MIGRATION_NUMERIC_COLUMNS, true ) ) {
			return (int) $stored === (int) $expected;
		}

		return (string) $stored === (string) $expected;
	}

	private static function migration_row_is_unscoped( array $row ): bool {
		return '' === trim( (string) ( $row['workspace_type'] ?? '' ) ) || '' === trim( (string) ( $row['workspace_id'] ?? '' ) );
	}

	private static function migration_has_provenance( array $row, int $blog_id, string $session_id ): bool {
		$metadata = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		$source   = is_array( $metadata ) && is_array( $metadata['migration_source'] ?? null ) ? $metadata['migration_source'] : array();
		return (int) ( $source['blog_id'] ?? 0 ) === $blog_id && (string) ( $source['session_id'] ?? '' ) === $session_id;
	}

	/** Compare persisted payload while treating migration provenance as enrichment. */
	private static function migration_rows_match( array $stored, array $canonical ): bool {
		foreach ( $canonical as $column => $value ) {
			$stored_value = $stored[ $column ] ?? null;
			if ( in_array( $column, array( 'messages', 'metadata' ), true ) ) {
				$stored_json    = json_decode( (string) $stored_value, true );
				$canonical_json = json_decode( (string) $value, true );
				if ( is_array( $stored_json ) && is_array( $canonical_json ) ) {
					if ( 'metadata' === $column ) {
						unset( $stored_json['migration_source'], $canonical_json['migration_source'] );
					}
					if ( $stored_json !== $canonical_json ) {
						return false;
					}
					continue;
				}
			}

			if ( (string) $stored_value !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Select a newer snapshot only when both rows prove the same logical session.
	 *
	 * @return string "source", "target", or an empty string when precedence is unknown.
	 */
	private static function migration_preferred_logical_session_version( array $target, array $source ): string {
		foreach ( array( 'session_id', 'workspace_type', 'workspace_id', 'owner_type', 'owner_key_hash' ) as $identity_column ) {
			if ( (string) ( $target[ $identity_column ] ?? '' ) !== (string) ( $source[ $identity_column ] ?? '' ) ) {
				return '';
			}
		}

		$target_created = self::migration_reliable_timestamp( $target['created_at'] ?? null );
		$source_created = self::migration_reliable_timestamp( $source['created_at'] ?? null );
		if ( null === $target_created || $target_created !== $source_created ) {
			return '';
		}

		$target_updated = self::migration_reliable_timestamp( $target['updated_at'] ?? null );
		$source_updated = self::migration_reliable_timestamp( $source['updated_at'] ?? null );
		if ( null === $target_updated || null === $source_updated || $target_updated === $source_updated ) {
			return '';
		}

		return $target_updated > $source_updated ? 'target' : 'source';
	}

	/** Return a normalized database timestamp only when its value is trustworthy. */
	private static function migration_reliable_timestamp( $value ): ?string {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value, $parts ) ) {
			return null;
		}

		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) {
			return null;
		}

		return $value;
	}

	/** Build a stable, VARCHAR(50)-safe ID for a conflicting legacy session. */
	private static function migration_collision_session_id( array $canonical, int $blog_id, string $source_session_id, int $attempt = 0 ): string {
		$payload = $canonical;
		unset( $payload['session_id'] );

		return 'dm-' . substr( hash( 'sha256', $blog_id . "\n" . $source_session_id . "\n" . $attempt . "\n" . wp_json_encode( $payload ) ), 0, 47 );
	}

	/** Assign rows left unclaimed by non-main sources to the main-site workspace. */
	private static function claim_unscoped_network_rows_for_main_site( string $network_table, array $target_columns, array &$convergence ): bool {
		global $wpdb;

		$main_blog_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
		$cursor       = '';
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id > %s AND (workspace_type IS NULL OR workspace_type = %s OR workspace_id IS NULL OR workspace_id = %s) ORDER BY session_id ASC LIMIT %d', $network_table, $cursor, '', '', self::MIGRATION_BATCH_SIZE ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				$convergence['success'] = false;
				$convergence['error']   = sprintf( 'Failed reading unscoped network chat sessions: %s', (string) $wpdb->last_error );
				return false;
			}

			foreach ( $rows as $row ) {
				$cursor  = (string) ( $row['session_id'] ?? '' );
				$claimed = false;
				for ( $retry = 0; $retry < 3; ++$retry ) {
					$observed = self::migration_target_row( $network_table, $cursor );
					if ( ! is_array( $observed ) || ! self::migration_row_is_unscoped( $observed ) ) {
						$claimed = is_array( $observed );
						break;
					}

					$canonical = self::canonicalize_migration_row( $observed, $main_blog_id, $target_columns );
					if ( null === $canonical ) {
						break;
					}

					$updated   = self::update_migration_target( $network_table, $canonical, $observed );
					$persisted = self::migration_target_row( $network_table, $cursor );
					if ( false === $updated ) {
						break;
					}
					if ( is_array( $persisted ) && ! self::migration_row_is_unscoped( $persisted ) ) {
						$claimed = true;
						break;
					}
				}

				if ( ! $claimed ) {
					$convergence['success'] = false;
					$convergence['error']   = sprintf( 'Failed assigning network chat session %s to the main workspace: %s', $cursor, (string) $wpdb->last_error );
					return false;
				}
			}
			$row_count = count( $rows );
		} while ( self::MIGRATION_BATCH_SIZE === $row_count );

		return true;
	}

	/**
	 * Convert one legacy site row into the canonical network-table shape.
	 *
	 * @param array    $row            Legacy row.
	 * @param int      $blog_id        Source site ID.
	 * @param string[] $target_columns Canonical table columns.
	 * @return array|null Canonical insert row, or null when scope cannot be proven.
	 */
	private static function canonicalize_migration_row( array $row, int $blog_id, array $target_columns ): ?array {
		$session_id = trim( (string) ( $row['session_id'] ?? '' ) );
		if ( '' === $session_id || ! array_key_exists( 'user_id', $row ) || ! array_key_exists( 'messages', $row ) ) {
			return null;
		}

		$metadata       = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		$metadata       = is_array( $metadata ) ? $metadata : array();
		$meta_workspace = is_array( $metadata['workspace'] ?? null ) ? $metadata['workspace'] : array();
		$workspace_type = trim( (string) ( $row['workspace_type'] ?? '' ) );
		if ( '' === $workspace_type ) {
			$workspace_type = trim( (string) ( $meta_workspace['workspace_type'] ?? '' ) );
		}
		if ( '' === $workspace_type ) {
			$workspace_type = trim( (string) ( $metadata['workspace_type'] ?? '' ) );
		}

		$workspace_id = trim( (string) ( $row['workspace_id'] ?? '' ) );
		if ( '' === $workspace_id ) {
			$workspace_id = trim( (string) ( $meta_workspace['workspace_id'] ?? '' ) );
		}
		if ( '' === $workspace_id ) {
			$workspace_id = trim( (string) ( $metadata['workspace_id'] ?? '' ) );
		}
		if ( '' === $workspace_type || '' === $workspace_id ) {
			$workspace_type = 'site';
			$workspace_id   = function_exists( 'get_home_url' ) ? untrailingslashit( (string) get_home_url( $blog_id, '/' ) ) : '';
		}
		if ( '' === $workspace_type || '' === $workspace_id ) {
			return null;
		}

		$user_id     = absint( $row['user_id'] );
		$owner_type  = sanitize_key( (string) ( $row['owner_type'] ?? '' ) );
		$owner_hash  = strtolower( (string) ( $row['owner_key_hash'] ?? '' ) );
		$owner_label = sanitize_text_field( (string) ( $row['owner_label'] ?? '' ) );
		$meta_owner  = is_array( $metadata['transcript_owner'] ?? null ) ? $metadata['transcript_owner'] : array();
		if ( '' === $owner_type || 1 !== preg_match( '/^[a-f0-9]{64}$/', $owner_hash ) ) {
			$owner_type = sanitize_key( (string) ( $meta_owner['owner_type'] ?? '' ) );
			$owner_hash = strtolower( (string) ( $meta_owner['owner_key_hash'] ?? '' ) );
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $owner_hash ) && ! empty( $meta_owner['owner_key'] ) ) {
				$owner_hash = ChatTranscriptOwner::hash_owner_key( (string) $meta_owner['owner_key'] );
			}
			$owner_label = sanitize_text_field( (string) ( $meta_owner['owner_label'] ?? '' ) );
		}
		if ( '' === $owner_type || 1 !== preg_match( '/^[a-f0-9]{64}$/', $owner_hash ) ) {
			$owner       = ChatTranscriptOwner::user_owner( $user_id );
			$owner_type  = $owner['owner_type'];
			$owner_hash  = $owner['owner_key_hash'];
			$owner_label = $owner['owner_label'];
		}

		$metadata['workspace']        = array(
			'workspace_type' => $workspace_type,
			'workspace_id'   => $workspace_id,
		);
		$metadata['workspace_type']   = $workspace_type;
		$metadata['workspace_id']     = $workspace_id;
		$metadata['transcript_owner'] = array(
			'owner_type'     => $owner_type,
			'owner_key_hash' => $owner_hash,
			'owner_label'    => $owner_label,
		);
		$metadata['migration_source'] = array(
			'blog_id'    => $blog_id,
			'session_id' => $session_id,
		);

		$mode      = trim( (string) ( $row['mode'] ?? $row['context'] ?? 'chat' ) );
		$canonical = array(
			'session_id'     => $session_id,
			'workspace_type' => $workspace_type,
			'workspace_id'   => $workspace_id,
			'user_id'        => $user_id,
			'owner_type'     => $owner_type,
			'owner_key_hash' => $owner_hash,
			'owner_label'    => $owner_label,
			'messages'       => (string) $row['messages'],
			'metadata'       => wp_json_encode( $metadata ),
			'mode'           => '' !== $mode ? $mode : 'chat',
		);

		foreach ( array( 'agent_id', 'title', 'provider', 'model', 'provider_response_id', 'created_at', 'updated_at', 'last_read_at', 'expires_at', 'transcript_lock_token', 'transcript_lock_expires_at' ) as $column ) {
			if ( in_array( $column, $target_columns, true ) && array_key_exists( $column, $row ) ) {
				$canonical[ $column ] = $row[ $column ];
			}
		}

		return array_intersect_key( $canonical, array_flip( $target_columns ) );
	}

	/**
	 * Ensure transcript owner columns exist and migrate legacy rows to user ownership.
	 *
	 * @return void
	 */
	public static function ensure_owner_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'owner_type', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_type VARCHAR(40) NOT NULL DEFAULT %s', $table_name, 'user' ) );
		}

		if ( ! self::column_exists( $table_name, 'owner_key_hash', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_key_hash VARCHAR(64) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'owner_label', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN owner_label VARCHAR(191) NULL', $table_name ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET owner_type = %s WHERE owner_type IS NULL OR owner_type = %s',
				$table_name,
				'user',
				''
			)
		);

		// Backfill user-owned hashes row-by-row because the value is intentionally
		// derived through PHP's hash() to stay engine-independent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT session_id, user_id FROM %i WHERE owner_key_hash IS NULL OR owner_key_hash = %s', $table_name, '' ), ARRAY_A );
		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$owner   = ChatTranscriptOwner::user_owner( $user_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table_name,
				array(
					'owner_type'     => $owner['owner_type'],
					'owner_key_hash' => $owner['owner_key_hash'],
					'owner_label'    => $owner['owner_label'],
				),
				array( 'session_id' => (string) $row['session_id'] ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Ensure workspace columns exist for Agents API-scoped transcript rows.
	 *
	 * Existing rows are stamped with the current site scope because the prefixed
	 * WordPress table was already site-local before this generic boundary existed.
	 *
	 * @return void
	 */
	public static function ensure_workspace_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$workspace  = WordPressWorkspaceScope::current();

		if ( ! self::column_exists( $table_name, 'workspace_type', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_type VARCHAR(50) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'workspace_id', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN workspace_id VARCHAR(191) NULL', $table_name ) );
		}

		// Multisite rows can only be scoped after legacy per-site sources have had
		// the opportunity to claim old INSERT IGNORE copies in the network table.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return;
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
	 * Ensure agent_id column exists for layered architecture migration.
	 *
	 * dbDelta can miss edge cases on existing installs, so we perform an explicit
	 * column check and ALTER as a safety net.
	 *
	 * @since 0.36.1
	 * @return void
	 */
	public static function ensure_agent_id_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( self::column_exists( $table_name, 'agent_id', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
		// is cosmetic — both engines accept the bare ADD COLUMN form.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN agent_id BIGINT(20) UNSIGNED NULL', $table_name ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY agent_id (agent_id)', $table_name ) );
	}

	/**
	 * Ensure the mode column and indexes exist.
	 *
	 * @return void
	 */
	public static function ensure_mode_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'mode', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
			// is cosmetic — both engines accept the bare ADD COLUMN form.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT %s', $table_name, 'chat' ) );
		}

		// Idempotent index normalization: add current indexes when needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$indexes       = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ) );
		$existing_keys = array_unique( array_column( $indexes, 'Key_name' ) );

		if ( ! in_array( 'mode', $existing_keys, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY mode (mode)', $table_name ) );
		}
		if ( ! in_array( 'user_mode', $existing_keys, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY user_mode (user_id, mode)', $table_name ) );
		}
	}

	/**
	 * Ensure last_read_at column exists for unread message tracking.
	 *
	 * dbDelta can miss edge cases on existing installs, so we perform an explicit
	 * column check and ALTER as a safety net.
	 *
	 * @since 0.62.0
	 * @return void
	 */
	public static function ensure_last_read_at_column(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( self::column_exists( $table_name, 'last_read_at', $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		// `AFTER <col>` is MySQL-only; SQLite (Studio) rejects it. Column position
		// is cosmetic — both engines accept the bare ADD COLUMN form.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN last_read_at DATETIME NULL', $table_name ) );
	}

	/**
	 * Ensure transcript lock columns exist for Agents API single-writer locking.
	 *
	 * @return void
	 */
	public static function ensure_transcript_lock_columns(): void {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		if ( ! self::column_exists( $table_name, 'transcript_lock_token', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN transcript_lock_token VARCHAR(64) NULL', $table_name ) );
		}

		if ( ! self::column_exists( $table_name, 'transcript_lock_expires_at', $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN transcript_lock_expires_at DATETIME NULL', $table_name ) );
		}
	}

	/**
	 * Check if table exists
	 *
	 * @return bool True if table exists
	 */
	public static function table_exists( string $table_name = '', ?\wpdb $wpdb = null ): bool {
		if ( '' === $table_name ) {
			$table_name = self::get_prefixed_table_name();
		}

		return parent::database_table_exists( $table_name, $wpdb );
	}

	/**
	 * Get table name with prefix (static context).
	 *
	 * @return string Full table name
	 */
	public static function get_prefixed_table_name(): string {
		return self::sanitize_table_name( self::get_table_prefix() . self::TABLE_NAME );
	}

	/**
	 * Sanitize table name to alphanumeric and underscore.
	 */
	private static function sanitize_table_name( string $table_name ): string {
		return preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );
	}

	/**
	 * Get sanitized table name for queries.
	 */
	private static function get_escaped_table_name(): string {
		return esc_sql( self::get_prefixed_table_name() );
	}


	/**
	 * Create new chat session
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param string              $agent_slug Registered agent slug.
	 * @param array               $metadata  Optional session metadata.
	 * @param string              $context   Execution context (chat, pipeline, system).
	 * @return string Session ID (UUID)
	 */
	public function create_session( ...$args ): string {
		global $wpdb;

		list( $workspace, $user_id, $agent, $metadata, $context ) = self::normalize_create_session_args( $args );
		$owner = self::normalize_owner_from_metadata( $metadata, $user_id );

		try {
			$identity   = self::resolve_agent_identity_for_session( $agent );
			$agent_id   = $identity['agent_id'];
			$agent_slug = $identity['agent_slug'];
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to resolve transcript session agent identity',
				array(
					'user_id' => $user_id,
					'agent'   => is_scalar( $agent ) ? (string) $agent : gettype( $agent ),
					'error'   => $e->getMessage(),
					'mode'    => $context,
				)
			);
			return '';
		}

		$session_id = wp_generate_uuid4();
		$table_name = self::get_prefixed_table_name();
		$metadata   = array_merge(
			$metadata,
			array(
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
			)
		);
		if ( '' !== $agent_slug ) {
			$metadata['agent_slug'] = $agent_slug;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'session_id'     => $session_id,
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
				'user_id'        => $user_id,
				'owner_type'     => $owner['owner_type'],
				'owner_key_hash' => $owner['owner_key_hash'],
				'owner_label'    => $owner['owner_label'],
				'agent_id'       => $agent_id > 0 ? $agent_id : null,
				'messages'       => wp_json_encode( array() ),
				'metadata'       => wp_json_encode( $metadata ),
				'provider'       => null,
				'model'          => null,
				'mode'           => $context,
				'expires_at'     => null,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create chat session',
				array(
					'user_id' => $user_id,
					'error'   => $wpdb->last_error,
					'mode'    => $context,
				)
			);
			return '';
		}

		do_action(
			'datamachine_log',
			'debug',
			'Chat session created',
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'agent_id'   => $agent_id,
				'mode'       => $context,
			)
		);

		return $session_id;
	}

	/**
	 * Create a new chat session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner      Canonical principal owner.
	 * @param string                        $agent_slug Registered agent slug.
	 * @param array                         $metadata   Optional session metadata.
	 * @param string                        $context    Execution context.
	 * @return string Session ID (UUID), or empty string on failure.
	 */
	public function create_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $agent_slug = '', array $metadata = array(), string $context = 'chat' ): string {
		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return '';
		}

		$metadata['transcript_owner'] = $transcript_owner;
		return $this->create_session( $workspace, (int) $transcript_owner['user_id'], $agent_slug, $metadata, $context );
	}

	/**
	 * Normalize create-session arguments across current and workspace-aware contracts.
	 *
	 * @param array $args Raw method arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int|string,3:array,4:string}
	 */
	private static function normalize_create_session_args( array $args ): array {
		if ( isset( $args[0] ) && $args[0] instanceof WP_Agent_Workspace_Scope ) {
			return array(
				$args[0],
				(int) ( $args[1] ?? 0 ),
				is_string( $args[2] ?? null ) ? (string) $args[2] : (int) ( $args[2] ?? 0 ),
				is_array( $args[3] ?? null ) ? $args[3] : array(),
				(string) ( $args[4] ?? 'chat' ),
			);
		}

		return array(
			WordPressWorkspaceScope::current(),
			(int) ( $args[0] ?? 0 ),
			is_string( $args[1] ?? null ) ? (string) $args[1] : (int) ( $args[1] ?? 0 ),
			is_array( $args[2] ?? null ) ? $args[2] : array(),
			(string) ( $args[3] ?? 'chat' ),
		);
	}

	/**
	 * Normalize transcript owner metadata for storage.
	 *
	 * @param array $metadata Session metadata.
	 * @param int   $user_id  Compatibility user ID.
	 * @return array{owner_type:string,owner_key_hash:string,owner_label:string}
	 */
	private static function normalize_owner_from_metadata( array &$metadata, int $user_id ): array {
		$owner = is_array( $metadata['transcript_owner'] ?? null ) ? $metadata['transcript_owner'] : ChatTranscriptOwner::user_owner( $user_id );

		if ( empty( $owner['owner_key_hash'] ) && ! empty( $owner['owner_key'] ) ) {
			$owner['owner_key_hash'] = ChatTranscriptOwner::hash_owner_key( (string) $owner['owner_key'] );
		}

		$normalized = array(
			'owner_type'     => sanitize_key( (string) ( $owner['owner_type'] ?? 'user' ) ),
			'owner_key_hash' => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $owner['owner_key_hash'] ?? '' ) ) ),
			'owner_label'    => mb_substr( sanitize_text_field( (string) ( $owner['owner_label'] ?? '' ) ), 0, 191 ),
		);

		if ( '' === $normalized['owner_type'] || '' === $normalized['owner_key_hash'] ) {
			$fallback   = ChatTranscriptOwner::user_owner( $user_id );
			$normalized = array(
				'owner_type'     => $fallback['owner_type'],
				'owner_key_hash' => $fallback['owner_key_hash'],
				'owner_label'    => $fallback['owner_label'],
			);
		}

		$metadata['transcript_owner'] = $normalized;

		return $normalized;
	}

	/**
	 * Add owner constraints to a WHERE fragment.
	 *
	 * @param array      $where      WHERE fragments.
	 * @param array      $query_args Query args.
	 * @param array|null $owner      Optional owner array.
	 * @return void
	 */
	private static function append_owner_where( array &$where, array &$query_args, ?array $owner ): void {
		if ( empty( $owner['owner_type'] ) || empty( $owner['owner_key_hash'] ) ) {
			return;
		}

		$where[]      = 'owner_type = %s';
		$query_args[] = sanitize_key( (string) $owner['owner_type'] );
		$where[]      = 'owner_key_hash = %s';
		$query_args[] = (string) $owner['owner_key_hash'];
	}

	/**
	 * Check whether a loaded session row belongs to an owner.
	 *
	 * @param array $session Session row.
	 * @param array $owner   Owner array.
	 * @return bool
	 */
	public function session_matches_owner( array $session, array $owner ): bool {
		$session_owner_type = (string) ( $session['owner_type'] ?? '' );
		$session_owner_hash = (string) ( $session['owner_key_hash'] ?? '' );

		if ( '' === $session_owner_type || '' === $session_owner_hash ) {
			$legacy             = ChatTranscriptOwner::user_owner( absint( $session['user_id'] ?? 0 ) );
			$session_owner_type = $legacy['owner_type'];
			$session_owner_hash = $legacy['owner_key_hash'];
		}

		return (string) ( $owner['owner_type'] ?? '' ) === $session_owner_type
			&& (string) ( $owner['owner_key_hash'] ?? '' ) === $session_owner_hash;
	}

	/**
	 * Resolve the generic transcript agent slug to Data Machine's stored agent ID.
	 *
	 * Integer input is retained as a Data Machine-internal compatibility path for
	 * callers that have not crossed the generic Agents API boundary.
	 *
	 * @param int|string $agent Agent slug, agent ID, or empty value.
	 * @return array{agent_id:int,agent_slug:string}
	 */
	private static function resolve_agent_identity_for_session( int|string $agent ): array {
		if ( is_int( $agent ) || is_numeric( $agent ) ) {
			$agent_id = (int) $agent;
			if ( $agent_id <= 0 ) {
				return array(
					'agent_id'   => 0,
					'agent_slug' => '',
				);
			}

			$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $agent_id );
			return array(
				'agent_id'   => $identity->agent_id,
				'agent_slug' => $identity->agent_slug,
			);
		}

		$agent_slug = AgentIdentityResolver::normalize_agent_slug( $agent );
		if ( '' === $agent_slug ) {
			return array(
				'agent_id'   => 0,
				'agent_slug' => '',
			);
		}

		$identity = ( new AgentIdentityResolver() )->resolve_agent_identity( $agent_slug );
		return array(
			'agent_id'   => $identity->agent_id,
			'agent_slug' => $identity->agent_slug,
		);
	}

	/**
	 * Normalize pending-session arguments across current and workspace-aware contracts.
	 *
	 * @param array $args Raw method arguments.
	 * @return array{0:WP_Agent_Workspace_Scope,1:int,2:int,3:string,4:int|null,5:array|null,6:bool}
	 */
	private static function normalize_recent_pending_session_args( array $args ): array {
		if ( isset( $args[0] ) && $args[0] instanceof WP_Agent_Workspace_Scope ) {
			return array(
				$args[0],
				(int) ( $args[1] ?? 0 ),
				(int) ( $args[2] ?? 600 ),
				(string) ( $args[3] ?? 'chat' ),
				isset( $args[4] ) ? (int) $args[4] : null,
				is_array( $args[5] ?? null ) ? $args[5] : null,
				! empty( $args[6] ),
			);
		}

		return array(
			WordPressWorkspaceScope::current(),
			(int) ( $args[0] ?? 0 ),
			(int) ( $args[1] ?? 600 ),
			(string) ( $args[2] ?? 'chat' ),
			isset( $args[3] ) ? (int) $args[3] : null,
			is_array( $args[4] ?? null ) ? $args[4] : null,
			! empty( $args[5] ),
		);
	}

	/**
	 * Retrieve session data
	 *
	 * @param string $session_id Session UUID
	 * @return array|null Session data or null if not found
	 */
	public function get_session( string $session_id ): ?array {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE session_id = %s',
				$table_name,
				$session_id
			),
			ARRAY_A
		);

		if ( ! $session ) {
			return null;
		}

		$session['messages']   = self::normalize_messages( json_decode( $session['messages'], true ) ?? array() );
		$session['metadata']   = json_decode( $session['metadata'], true ) ?? array();
		$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );
		$session['title']      = is_string( $session['title'] ?? null ) ? $session['title'] : '';
		$session['provider']   = is_string( $session['provider'] ?? null ) ? $session['provider'] : '';
		$session['model']      = is_string( $session['model'] ?? null ) ? $session['model'] : '';

		return $session;
	}

	/**
	 * List transcript sessions for a workspace/user pair.
	 *
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the sessions.
	 * @param int                      $user_id   WordPress user ID owning the sessions.
	 * @param array                    $args      Optional filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		global $wpdb;

		$table_name       = self::get_prefixed_table_name();
		$include_messages = (bool) ( $args['include_messages'] ?? true );
		$limit            = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset           = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$owner_only       = ! empty( $args['owner_only'] ) && is_array( $args['transcript_owner'] ?? null );
		$where            = array(
			'workspace_type = %s',
			'workspace_id = %s',
		);
		$query_args       = array(
			$table_name,
			$workspace->workspace_type,
			$workspace->workspace_id,
		);

		if ( ! $owner_only ) {
			$where[]      = 'user_id = %d';
			$query_args[] = $user_id;
		}

		if ( is_string( $args['context'] ?? null ) && '' !== $args['context'] ) {
			$where[]      = 'mode = %s';
			$query_args[] = $args['context'];
		}

		self::append_owner_where( $where, $query_args, is_array( $args['transcript_owner'] ?? null ) ? $args['transcript_owner'] : null );

		if ( is_string( $args['agent_slug'] ?? null ) && '' !== $args['agent_slug'] ) {
			try {
				$identity = self::resolve_agent_identity_for_session( $args['agent_slug'] );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
				return array();
			}

			$where[]      = 'agent_id = %d';
			$query_args[] = $identity['agent_id'];
		}

		$select       = $include_messages ? '*' : 'session_id, workspace_type, workspace_id, user_id, owner_type, owner_key_hash, owner_label, agent_id, title, metadata, provider, model, provider_response_id, mode, created_at, updated_at, last_read_at, expires_at';
		$query_args[] = $limit;
		$query_args[] = $offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed predicates append matching values to $query_args.
		$sessions = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . $select . ' FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d', ...$query_args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! $sessions ) {
			return array();
		}

		foreach ( $sessions as &$session ) {
			if ( $include_messages ) {
				$session['messages'] = self::normalize_messages( json_decode( $session['messages'] ?? '[]', true ) ?? array() );
			}
			$session['metadata']   = json_decode( $session['metadata'] ?? '[]', true ) ?? array();
			$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );
			$session['title']      = is_string( $session['title'] ?? null ) ? $session['title'] : '';
			$session['provider']   = is_string( $session['provider'] ?? null ) ? $session['provider'] : '';
			$session['model']      = is_string( $session['model'] ?? null ) ? $session['model'] : '';
		}
		unset( $session );

		return $sessions;
	}

	/**
	 * List chat sessions for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace Workspace owning the sessions.
	 * @param array{type:string,key:string} $owner     Canonical principal owner.
	 * @param array                         $args      Optional filters/pagination.
	 * @return array<int,array<string,mixed>> Session rows.
	 */
	public function list_sessions_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, array $args = array() ): array {
		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return array();
		}

		$args['transcript_owner'] = $transcript_owner;
		$args['owner_only']       = true;
		return $this->list_sessions( $workspace, (int) $transcript_owner['user_id'], $args );
	}

	/**
	 * Read one transcript session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace  Workspace owning the session.
	 * @param array{type:string,key:string} $owner      Canonical principal owner.
	 * @param string                        $session_id Session ID.
	 * @return array<string,mixed>|null Session row, or null when missing/not owned.
	 */
	public function get_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, string $session_id ): ?array {
		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return null;
		}

		$session = $this->get_session( $session_id );
		if ( ! is_array( $session )
			|| (string) ( $session['workspace_type'] ?? '' ) !== $workspace->workspace_type
			|| (string) ( $session['workspace_id'] ?? '' ) !== $workspace->workspace_id
			|| ! self::session_matches_owner( $session, $transcript_owner ) ) {
			return null;
		}

		return $session;
	}

	/** Read one ability-facing session within both workspace and transcript-owner scope. */
	public function get_session_for_transcript_owner( WP_Agent_Workspace_Scope $workspace, int $user_id, array $owner, string $session_id ): ?array {
		$session = $this->get_session( $session_id );
		if ( ! is_array( $session )
			|| (string) ( $session['workspace_type'] ?? '' ) !== $workspace->workspace_type
			|| (string) ( $session['workspace_id'] ?? '' ) !== $workspace->workspace_id
			|| (int) ( $session['user_id'] ?? 0 ) !== $user_id
			|| ! self::session_matches_owner( $session, $owner ) ) {
			return null;
		}

		return $session;
	}

	/** Delete one ability-facing session within workspace and transcript-owner scope. */
	public function delete_session_for_transcript_owner( WP_Agent_Workspace_Scope $workspace, int $user_id, array $owner, string $session_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			self::get_prefixed_table_name(),
			array(
				'session_id'     => $session_id,
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
				'user_id'        => $user_id,
				'owner_type'     => sanitize_key( (string) ( $owner['owner_type'] ?? '' ) ),
				'owner_key_hash' => (string) ( $owner['owner_key_hash'] ?? '' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return 1 === (int) $result;
	}

	/**
	 * Resolve the generic transcript agent slug from a stored session row.
	 *
	 * @param array<string,mixed> $session Stored session row.
	 * @return string|null Agent slug when resolvable.
	 */
	private static function resolve_agent_slug_from_session_row( array $session ): ?string {
		$agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
		if ( $agent_id <= 0 ) {
			return null;
		}

		try {
			return ( new AgentIdentityResolver() )->resolve_agent_slug( $agent_id );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Update session with new messages and metadata
	 *
	 * @param string $session_id Session UUID
	 * @param array  $messages   Complete messages array
	 * @param array  $metadata   Updated metadata
	 * @param string $provider   AI provider
	 * @param string $model      AI model
	 * @param string|null $provider_response_id Provider-side response/state ID.
	 * @return bool Success
	 */
	public function update_session(
		string $session_id,
		array $messages,
		array $metadata = array(),
		string $provider = '',
		string $model = '',
		?string $provider_response_id = null
	): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		try {
			$normalized_messages = WP_Agent_Message::normalize_many( $messages );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to normalize chat session messages for update',
				array(
					'session_id' => $session_id,
					'error'      => $e->getMessage(),
					'mode'       => 'chat',
				)
			);
			return false;
		}

		$update_data = array(
			'messages' => wp_json_encode( $normalized_messages ),
			'metadata' => wp_json_encode( $metadata ),
		);

		$update_format = array( '%s', '%s' );

		if ( ! empty( $provider ) ) {
			$update_data['provider'] = $provider;
			$update_format[]         = '%s';
		}

		if ( ! empty( $model ) ) {
			$update_data['model'] = $model;
			$update_format[]      = '%s';
		}

		if ( null !== $provider_response_id ) {
			$update_data['provider_response_id'] = $provider_response_id;
			$update_format[]                     = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'session_id' => $session_id ),
			$update_format,
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update chat session',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Acquire an advisory transcript lock for a chat session.
	 *
	 * @param string $session_id   Session UUID.
	 * @param int    $ttl_seconds  Lock TTL in seconds.
	 * @return string|null Lock token, or null when another active writer owns it.
	 */
	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$token      = $this->generate_lock_token();
		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + max( 1, $ttl_seconds ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET transcript_lock_token = %s, transcript_lock_expires_at = %s
				WHERE session_id = %s
				AND (
					transcript_lock_token IS NULL
					OR transcript_lock_token = %s
					OR transcript_lock_expires_at IS NULL
					OR transcript_lock_expires_at <= %s
				)',
				$table_name,
				$token,
				$expires_at,
				$session_id,
				'',
				$now
			)
		);

		if ( 1 !== (int) $result ) {
			return null;
		}

		return $token;
	}

	/**
	 * Release an advisory transcript lock if the active token matches.
	 *
	 * @param string $session_id  Session UUID.
	 * @param string $lock_token  Token returned by acquire_session_lock().
	 * @return bool True when the active lock was released.
	 */
	public function release_session_lock( string $session_id, string $lock_token ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET transcript_lock_token = NULL, transcript_lock_expires_at = NULL
				WHERE session_id = %s AND transcript_lock_token = %s',
				$table_name,
				$session_id,
				$lock_token
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Generate an opaque lock ownership token.
	 *
	 * @return string
	 */
	private function generate_lock_token(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			return str_replace( '-', '', wp_generate_uuid4() ) . str_replace( '.', '', uniqid( '', true ) );
		}
	}

	/**
	 * Delete session
	 *
	 * @param string $session_id Session UUID
	 * @return bool Success
	 */
	public function delete_session( string $session_id ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table_name,
			array( 'session_id' => $session_id ),
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to delete chat session',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Chat session deleted',
			array(
				'session_id' => $session_id,
				'mode'       => 'chat',
			)
		);

		return true;
	}

	/**
	 * Cleanup expired sessions
	 *
	 * @return int Number of deleted sessions
	 */
	public function cleanup_expired_sessions(): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at < %s',
				$table_name,
				current_time( 'mysql', true )
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up expired chat sessions',
				array(
					'deleted_count' => $deleted,
					'mode'          => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Get all sessions for a user
	 *
	 * @param int         $user_id  WordPress user ID
	 * @param int         $limit    Maximum sessions to return
	 * @param int         $offset   Pagination offset
	 * @param string|null $mode  Optional mode filter
	 * @param int|null    $agent_id Optional agent ID filter (null = no filter)
	 * @return array Array of session data
	 */
	public function get_user_sessions(
		int $user_id,
		int $limit = 20,
		int $offset = 0,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null,
		?WP_Agent_Workspace_Scope $workspace = null
	): array {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$where      = array( 'user_id = %d' );
		$params     = array( $table_name, $user_id );
		if ( null !== $workspace ) {
			$where[]  = 'workspace_type = %s';
			$where[]  = 'workspace_id = %s';
			$params[] = $workspace->workspace_type;
			$params[] = $workspace->workspace_id;
		}

		if ( null !== $mode && '' !== $mode ) {
			$where[]  = 'mode = %s';
			$params[] = $mode;
		}

		if ( null !== $agent_id ) {
			$where[]  = 'agent_id = %d';
			$params[] = $agent_id;
		}

		self::append_owner_where( $where, $params, $transcript_owner );

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed predicates append matching values to $params.
		$sessions = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d', ...$params ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! $sessions ) {
			return array();
		}

		// Batch-load the agents referenced by these sessions so each row can
		// expose agent_name/agent_slug without an N+1 query inside the loop.
		$agent_ids_in_sessions = array();
		foreach ( $sessions as $session ) {
			$session_agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
			if ( $session_agent_id > 0 ) {
				$agent_ids_in_sessions[] = $session_agent_id;
			}
		}

		$agents_by_id = array();
		if ( ! empty( $agent_ids_in_sessions ) ) {
			$agents_repo = new \DataMachine\Core\Database\Agents\Agents();
			foreach ( $agents_repo->get_agents_by_ids( $agent_ids_in_sessions ) as $agent_row ) {
				$agents_by_id[ (int) $agent_row['agent_id'] ] = $agent_row;
			}
		}

		$result = array();
		foreach ( $sessions as $session ) {
			$messages      = self::normalize_messages( json_decode( $session['messages'] ?? '[]', true ) ?? array() );
			$first_message = '';
			foreach ( $messages as $msg ) {
				if ( ( $msg['role'] ?? '' ) === 'user' ) {
					$first_message = self::message_content_text( $msg );
					break;
				}
			}

			$last_read_at     = $session['last_read_at'] ?? null;
			$session_agent_id = isset( $session['agent_id'] ) ? (int) $session['agent_id'] : 0;
			$agent_row        = $session_agent_id > 0 ? ( $agents_by_id[ $session_agent_id ] ?? null ) : null;

			$result[] = array(
				'session_id'     => $session['session_id'],
				'workspace_type' => (string) ( $session['workspace_type'] ?? '' ),
				'workspace_id'   => (string) ( $session['workspace_id'] ?? '' ),
				'title'          => $session['title'] ?? null,
				'mode'           => $session['mode'] ?? 'chat',
				'first_message'  => mb_substr( $first_message, 0, 100 ),
				'message_count'  => count( $messages ),
				'unread_count'   => $this->count_unread( $messages, $last_read_at ),
				'agent_id'       => $session_agent_id > 0 ? $session_agent_id : null,
				'agent_slug'     => $agent_row ? (string) $agent_row['agent_slug'] : null,
				'agent_name'     => $agent_row ? (string) $agent_row['agent_name'] : null,
				'created_at'     => DateFormatter::format_for_api( $session['created_at'] ?? null ),
				'updated_at'     => DateFormatter::format_for_api( $session['updated_at'] ?? $session['created_at'] ?? null ),
			);
		}

		return $result;
	}

	/** List ability-facing session summaries within workspace and owner scope. */
	public function get_user_sessions_for_workspace(
		WP_Agent_Workspace_Scope $workspace,
		int $user_id,
		int $limit = 20,
		int $offset = 0,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null
	): array {
		return $this->get_user_sessions( $user_id, $limit, $offset, $mode, $agent_id, $transcript_owner, $workspace );
	}

	/**
	 * Get total session count for a user
	 *
	 * @param int         $user_id  WordPress user ID
	 * @param string|null $mode  Optional mode filter
	 * @param int|null    $agent_id Optional agent ID filter (null = no filter)
	 * @return int Total session count
	 */
	public function get_user_session_count(
		int $user_id,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null
	): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$where      = array( 'user_id = %d' );
		$params     = array( $table_name, $user_id );

		if ( null !== $mode && '' !== $mode ) {
			$where[]  = 'mode = %s';
			$params[] = $mode;
		}

		if ( null !== $agent_id ) {
			$where[]  = 'agent_id = %d';
			$params[] = $agent_id;
		}

		self::append_owner_where( $where, $params, $transcript_owner );

		$sql = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );

		return (int) $count;
	}

	/** Count ability-facing sessions within workspace and owner scope. */
	public function get_user_session_count_for_workspace(
		WP_Agent_Workspace_Scope $workspace,
		int $user_id,
		?string $mode = null,
		?int $agent_id = null,
		?array $transcript_owner = null
	): int {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();
		$where      = array( 'workspace_type = %s', 'workspace_id = %s', 'user_id = %d' );
		$params     = array( $table_name, $workspace->workspace_type, $workspace->workspace_id, $user_id );
		if ( null !== $mode && '' !== $mode ) {
			$where[]  = 'mode = %s';
			$params[] = $mode;
		}
		if ( null !== $agent_id ) {
			$where[]  = 'agent_id = %d';
			$params[] = $agent_id;
		}
		self::append_owner_where( $where, $params, $transcript_owner );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where ), ...$params ) );
	}

	/**
	 * Find a recent pending session for deduplication
	 *
	 * Returns the most recent session that:
	 * - Belongs to this user
	 * - Was created within the threshold (default 10 minutes)
	 * - Has 0 messages OR is actively processing (user message added but no AI response)
	 * - Matches the specified mode
	 *
	 * This prevents duplicate sessions when requests timeout at Cloudflare
	 * but PHP continues executing. On retry, we reuse the pending session
	 * instead of creating a new one.
	 *
	 * @since 0.9.8
	 * @param WP_Agent_Workspace_Scope $workspace Workspace owning the session.
	 * @param int                 $user_id   WordPress user ID.
	 * @param int                 $seconds   Lookback window in seconds (default 600 = 10 minutes).
	 * @param string              $context   Context filter.
	 * @param int|null            $token_id  Optional token ID for login-scoped deduplication.
	 * @return array|null Session data or null if none found
	 */
	public function get_recent_pending_session( ...$args ): ?array {
		global $wpdb;

		list( $workspace, $user_id, $seconds, $context, $token_id, $transcript_owner, $owner_only ) = self::normalize_recent_pending_session_args( $args );

		$table_name  = self::get_prefixed_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		$query  = "SELECT * FROM %i
				WHERE workspace_type = %s
				AND workspace_id = %s
				AND mode = %s
				AND created_at >= %s
				AND (
					(messages = '[]' OR messages = '' OR messages IS NULL)
					OR (metadata LIKE %s)
				)";
		$params = array(
			$table_name,
			$workspace->workspace_type,
			$workspace->workspace_id,
			$context,
			$cutoff_time,
			'%"status":"processing"%',
		);

		if ( ! $owner_only ) {
			$query   .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		$query   .= ' AND metadata LIKE %s AND metadata LIKE %s';
		$params[] = '%"workspace_type":"' . $wpdb->esc_like( $workspace->workspace_type ) . '"%';
		$params[] = '%"workspace_id":"' . $wpdb->esc_like( $workspace->workspace_id ) . '"%';

		if ( null !== $token_id ) {
			$query   .= ' AND metadata LIKE %s';
			$params[] = '%"token_id":' . (int) $token_id . '%';
		}

		if ( is_array( $transcript_owner ) && ! empty( $transcript_owner['owner_type'] ) && ! empty( $transcript_owner['owner_key_hash'] ) ) {
			$query   .= ' AND owner_type = %s AND owner_key_hash = %s';
			$params[] = sanitize_key( (string) $transcript_owner['owner_type'] );
			$params[] = (string) $transcript_owner['owner_key_hash'];
		}

		$query .= ' ORDER BY created_at DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$session = $wpdb->get_row(
			$wpdb->prepare( $query, $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( ! $session ) {
			return null;
		}

		$session['messages']   = self::normalize_messages( json_decode( $session['messages'], true ) ?? array() );
		$session['metadata']   = json_decode( $session['metadata'], true ) ?? array();
		$session['agent_slug'] = self::resolve_agent_slug_from_session_row( $session );

		return $session;
	}

	/**
	 * Find a recent pending session for a canonical Agents API principal owner.
	 *
	 * @param WP_Agent_Workspace_Scope      $workspace Workspace owning the session.
	 * @param array{type:string,key:string} $owner     Canonical principal owner.
	 * @param int                           $seconds   Lookback window in seconds.
	 * @param string                        $context   Context filter.
	 * @param int|null                      $token_id  Optional token ID for token-scoped deduplication.
	 * @return array|null Session data or null if none found.
	 */
	public function get_recent_pending_session_for_owner( WP_Agent_Workspace_Scope $workspace, array $owner, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		$transcript_owner = self::principal_owner_to_transcript_owner( $owner );
		if ( null === $transcript_owner ) {
			return null;
		}

		return $this->get_recent_pending_session( $workspace, (int) $transcript_owner['user_id'], $seconds, $context, $token_id, $transcript_owner, true );
	}

	/**
	 * Convert an Agents API principal owner into Data Machine's stored owner shape.
	 *
	 * @param array{type:string,key:string} $owner Canonical principal owner.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}|null
	 */
	private static function principal_owner_to_transcript_owner( array $owner ): ?array {
		$transcript_owner = ChatTranscriptOwner::resolve_for_request(
			array(
				'transcript_owner' => array(
					'type' => (string) ( $owner['type'] ?? '' ),
					'key'  => (string) ( $owner['key'] ?? '' ),
				),
			),
			WP_Agent_Execution_Principal::OWNER_TYPE_USER === (string) ( $owner['type'] ?? '' ) ? absint( $owner['key'] ?? 0 ) : 0
		);

		return $transcript_owner instanceof WP_Error ? null : $transcript_owner;
	}

	/**
	 * Update session title
	 *
	 * @param string $session_id Session UUID
	 * @param string $title New title
	 * @return bool Success
	 */
	public function update_title( string $session_id, string $title ): bool {
		global $wpdb;

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array( 'title' => $title ),
			array( 'session_id' => $session_id ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to update chat session title',
				array(
					'session_id' => $session_id,
					'error'      => $wpdb->last_error,
					'mode'       => 'chat',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Count unread assistant messages in a session.
	 *
	 * Counts assistant messages whose metadata.timestamp is newer than
	 * the given last_read_at value. If last_read_at is NULL, all assistant
	 * messages are considered unread.
	 *
	 * @since 0.62.0
	 *
	 * @param array       $messages    Decoded messages array from the session.
	 * @param string|null $last_read_at ISO 8601 or MySQL datetime string, or null if never read.
	 * @return int Number of unread assistant messages.
	 */
	public function count_unread( array $messages, ?string $last_read_at ): int {
		$count = 0;

		foreach ( $messages as $msg ) {
			$msg = WP_Agent_Message::normalize( $msg );
			if ( ( $msg['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}

			// Skip tool call/result messages — only count visible assistant responses.
			$type = $msg['type'] ?? WP_Agent_Message::TYPE_TEXT;
			if ( WP_Agent_Message::TYPE_TOOL_CALL === $type || WP_Agent_Message::TYPE_TOOL_RESULT === $type ) {
				continue;
			}

			if ( null === $last_read_at ) {
				++$count;
				continue;
			}

			$timestamp = $msg['metadata']['timestamp'] ?? null;
			if ( $timestamp && strtotime( $timestamp ) > strtotime( $last_read_at ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Normalize a decoded message list to the canonical Data Machine envelope.
	 *
	 * @param array $messages Decoded messages.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_messages( array $messages ): array {
		try {
			return WP_Agent_Message::normalize_many( $messages );
		} catch ( \InvalidArgumentException $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'Chat: Failed to normalize stored messages',
				array( 'error' => $e->getMessage() )
			);
			return array();
		}
	}

	/**
	 * Render envelope content to a summary-safe string.
	 *
	 * @param array $message Message envelope.
	 * @return string Summary text.
	 */
	private static function message_content_text( array $message ): string {
		$content = $message['content'] ?? '';
		if ( is_string( $content ) ) {
			return $content;
		}

		return (string) wp_json_encode( $content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Mark a session as read by setting last_read_at to the current time.
	 *
	 * @since 0.62.0
	 *
	 * @param string $session_id Session UUID.
	 * @param int    $user_id    User ID for ownership verification.
	 * @return string|false The new last_read_at value on success, false on failure.
	 */
	public function mark_session_read( string $session_id, int $user_id, ?array $transcript_owner = null ) {
		global $wpdb;

		$table_name   = self::get_prefixed_table_name();
		$last_read_at = (string) current_time( 'mysql', true );
		$where        = array(
			'session_id' => $session_id,
			'user_id'    => $user_id,
		);
		$where_format = array( '%s', '%d' );

		if ( is_array( $transcript_owner ) && ! empty( $transcript_owner['owner_type'] ) && ! empty( $transcript_owner['owner_key_hash'] ) ) {
			$where['owner_type']     = sanitize_key( (string) $transcript_owner['owner_type'] );
			$where['owner_key_hash'] = (string) $transcript_owner['owner_key_hash'];
			$where_format[]          = '%s';
			$where_format[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array( 'last_read_at' => $last_read_at ),
			$where,
			array( '%s' ),
			$where_format
		);

		if ( false === $result ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to mark chat session as read',
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'error'      => $wpdb->last_error,
				)
			);
			return false;
		}

		return $last_read_at;
	}

	/** Mark an ability-facing session read within workspace and owner scope. */
	public function mark_session_read_for_workspace( WP_Agent_Workspace_Scope $workspace, string $session_id, int $user_id, array $transcript_owner ) {
		global $wpdb;

		$last_read_at = (string) current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			self::get_prefixed_table_name(),
			array( 'last_read_at' => $last_read_at ),
			array(
				'session_id'     => $session_id,
				'workspace_type' => $workspace->workspace_type,
				'workspace_id'   => $workspace->workspace_id,
				'user_id'        => $user_id,
				'owner_type'     => sanitize_key( (string) ( $transcript_owner['owner_type'] ?? '' ) ),
				'owner_key_hash' => (string) ( $transcript_owner['owner_key_hash'] ?? '' ),
			),
			array( '%s' ),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}
		if ( 0 === (int) $result ) {
			// A repeated mark in the same database-second is an idempotent success,
			// but only after re-verifying the complete ability-facing scope.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$owned_session = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT session_id FROM %i WHERE session_id = %s AND workspace_type = %s AND workspace_id = %s AND user_id = %d AND owner_type = %s AND owner_key_hash = %s',
					self::get_prefixed_table_name(),
					$session_id,
					$workspace->workspace_type,
					$workspace->workspace_id,
					$user_id,
					sanitize_key( (string) ( $transcript_owner['owner_type'] ?? '' ) ),
					(string) ( $transcript_owner['owner_key_hash'] ?? '' )
				)
			);
			if ( $session_id !== $owned_session ) {
				return false;
			}
		}

		return $last_read_at;
	}

	/**
	 * Count old sessions based on retention period.
	 *
	 * @param int  $retention_days               Days to retain sessions.
	 * @param bool $exclude_pipeline_transcripts Whether pipeline transcripts are counted separately.
	 * @return int Number of matching sessions.
	 */
	public function count_old_sessions( int $retention_days, bool $exclude_pipeline_transcripts = false ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		if ( $exclude_pipeline_transcripts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					WHERE updated_at < %s
					AND NOT (mode = %s AND metadata LIKE %s)',
					$table_name,
					$cutoff_date,
					'pipeline',
					'%"source":"pipeline_transcript"%'
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE updated_at < %s',
				$table_name,
				$cutoff_date
			)
		);
	}

	/**
	 * Cleanup old sessions based on retention period
	 *
	 * @param int $retention_days Days to retain sessions
	 * @return int Number of deleted sessions
	 */
	public function cleanup_old_sessions( int $retention_days ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// Pipeline transcripts have their own retention window and must never be
		// deleted by the human-session policy.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE updated_at < %s
				AND NOT (mode = %s AND metadata LIKE %s)',
				$table_name,
				$cutoff_date,
				'pipeline',
				'%"source":"pipeline_transcript"%'
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up old chat sessions',
				array(
					'deleted_count'  => $deleted,
					'retention_days' => $retention_days,
					'cutoff_date'    => $cutoff_date,
					'mode'           => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Count pipeline transcript sessions older than the retention window.
	 *
	 * @since next
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of matching transcript sessions.
	 */
	public function count_old_pipeline_transcripts( int $retention_days ): int {
		global $wpdb;

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE mode = %s
				AND metadata LIKE %s
				AND updated_at < %s',
				$table_name,
				'pipeline',
				'%"source":"pipeline_transcript"%',
				$cutoff_date
			)
		);
	}

	/**
	 * Cleanup pipeline transcript sessions older than the retention window.
	 *
	 * Pipeline transcripts are written by AIConversationLoop when persistence
	 * is enabled. They live in the same chat_sessions table with
	 * `mode='pipeline'` and `metadata.source='pipeline_transcript'`. This
	 * cleanup is independent from the human chat retention so transcripts
	 * can have a tighter TTL (default 30 days) without shortening human
	 * chat retention (default 90 days).
	 *
	 * Idempotent. Safe to call from a recurring action.
	 *
	 * @since next
	 * @param int $retention_days Days to retain pipeline transcripts.
	 * @return int Number of deleted transcript sessions.
	 */
	public function cleanup_pipeline_transcripts( int $retention_days ): int {
		global $wpdb;

		if ( $retention_days <= 0 ) {
			return 0;
		}

		$table_name  = self::get_prefixed_table_name();
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE mode = %s
				AND metadata LIKE %s
				AND updated_at < %s',
				$table_name,
				'pipeline',
				'%"source":"pipeline_transcript"%',
				$cutoff_date
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up old pipeline transcript sessions',
				array(
					'deleted_count'  => $deleted,
					'retention_days' => $retention_days,
					'cutoff_date'    => $cutoff_date,
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * Cleanup orphaned sessions from timeout failures
	 *
	 * Deletes sessions that:
	 * - Are older than the threshold (default 1 hour)
	 * - Have 0 messages (empty - orphaned from request timeouts)
	 *
	 * These sessions were created when requests timed out at Cloudflare
	 * before the AI could respond. They serve no purpose and clutter the UI.
	 *
	 * @since 0.9.8
	 * @param int $hours Hours threshold for orphaned sessions (default 1)
	 * @return int Number of deleted sessions
	 */
	public function cleanup_orphaned_sessions( int $hours = 1 ): int {
		global $wpdb;

		$table_name  = self::get_prefixed_table_name();
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i 
				WHERE created_at < %s 
				AND (messages = '[]' OR messages = '' OR messages IS NULL)",
				$table_name,
				$cutoff_time
			)
		);

		if ( $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Cleaned up orphaned chat sessions',
				array(
					'deleted_count'   => $deleted,
					'hours_threshold' => $hours,
					'cutoff_time'     => $cutoff_time,
					'mode'            => 'chat',
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * List lightweight session summaries for a single calendar day.
	 *
	 * Used by the Daily Memory Task so it can summarize "today's chats"
	 * without loading the full messages blob for every row.
	 *
	 * @param string $date Date string in `Y-m-d` format.
	 * @return array<int, array{session_id: string, title: string|null, mode: string, created_at: string}>
	 */
	public function list_sessions_for_day( string $date ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table_name = self::get_prefixed_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT session_id, title, mode, created_at
				 FROM %i
				 WHERE DATE(created_at) = %s
				 ORDER BY created_at ASC',
				$table_name,
				$date
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$result[] = array(
				'session_id' => (string) $row['session_id'],
				'title'      => isset( $row['title'] ) ? (string) $row['title'] : null,
				'mode'       => isset($row['mode']) ? (string) $row['mode'] : 'chat',
				'created_at' => (string) $row['created_at'],
			);
		}

		return $result;
	}

	/**
	 * Storage metrics for the retention CLI.
	 *
	 * Returns the row count and on-disk size for the MySQL-backed chat
	 * sessions table. SQLite installs report rows but cannot compute
	 * table size, so `size_mb` is `'0.0'` there.
	 *
	 * @return array{rows: int, size_mb: string}|null
	 */
	public function get_storage_metrics(): ?array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		$table_name = self::get_prefixed_table_name();

		if ( self::is_sqlite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
			);
			return array(
				'rows'    => $count,
				'size_mb' => '0.0',
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT table_rows,
					ROUND((data_length + index_length) / 1024 / 1024, 1) AS size_mb
				FROM information_schema.tables
				WHERE table_schema = DATABASE()
				AND table_name = %s',
				$table_name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		return array(
			'rows'    => (int) $row['table_rows'],
			'size_mb' => (string) $row['size_mb'],
		);
	}
}
