<?php
/** Multisite chat-session canonical convergence regression. */

declare( strict_types=1 );

namespace DataMachine\Core\Database {
	abstract class BaseRepository {
		public static function database_table_exists( string $table, $wpdb = null ): bool { unset( $wpdb ); return isset( $GLOBALS['chat_tables'][ $table ] ); }
		public static function column_exists( string $table, string $column, $wpdb = null ): bool { unset( $wpdb ); return in_array( $column, $GLOBALS['chat_columns'][ $table ] ?? array(), true ); }
	}
}

namespace AgentsAPI\Core\Workspace {
	class WP_Agent_Workspace_Scope {
		public function __construct( public string $workspace_type, public string $workspace_id ) {}
		public static function from_parts( string $type, string $id ): self { return new self( $type, $id ); }
	}
}

namespace DataMachine\Core\Workspace {
	class WordPressWorkspaceScope {
		public static function current(): \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope {
			return \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'site', 'https://current-subsite.example' );
		}
	}
}

namespace DataMachine\Core\Database\Chat {
	interface ConversationStoreInterface {}
}

namespace DataMachine\Abilities\Chat {
	class ChatTranscriptOwner {
		public static function hash_owner_key( string $key ): string { return hash( 'sha256', $key ); }
		public static function user_owner( int $user_id ): array {
			return array(
				'owner_type' => 'user', 'owner_key_hash' => self::hash_owner_key( 'user:' . $user_id ),
				'owner_label' => 'User ' . $user_id,
			);
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$canonical_columns = array( 'session_id', 'workspace_type', 'workspace_id', 'user_id', 'owner_type', 'owner_key_hash', 'owner_label', 'agent_id', 'title', 'messages', 'metadata', 'provider', 'model', 'provider_response_id', 'mode', 'created_at', 'updated_at', 'last_read_at', 'expires_at', 'transcript_lock_token', 'transcript_lock_expires_at' );
	$legacy_columns    = array( 'session_id', 'user_id', 'agent_id', 'title', 'messages', 'metadata', 'provider', 'model', 'context', 'created_at', 'updated_at' );
	$GLOBALS['chat_columns'] = array(
		'wp_datamachine_chat_sessions'   => $canonical_columns,
		'wp_2_datamachine_chat_sessions' => $legacy_columns,
		'wp_7_datamachine_chat_sessions' => $legacy_columns,
		'wp_8_datamachine_chat_sessions' => $legacy_columns,
	);
	$GLOBALS['chat_tables'] = array(
		'wp_datamachine_chat_sessions' => array(
			'main-legacy' => array(
				'session_id' => 'main-legacy', 'workspace_type' => '', 'workspace_id' => '', 'user_id' => 8,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:8' ), 'messages' => '[]', 'metadata' => '{}', 'mode' => 'chat',
			),
			'legacy-user' => array(
				'session_id' => 'legacy-user', 'workspace_type' => '', 'workspace_id' => '', 'user_id' => 42,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:42' ), 'owner_label' => 'User 42', 'agent_id' => 9,
				'messages' => '[]', 'metadata' => '{"message_count":1}', 'mode' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'metadata-scope' => array(
				'session_id' => 'metadata-scope', 'workspace_type' => '', 'workspace_id' => '', 'user_id' => 12,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:12' ), 'owner_label' => 'User 12', 'messages' => '[]',
				'metadata' => '{"workspace":{"workspace_type":"project"},"workspace_type":"ignored-top-level-type","workspace_id":"metadata-workspace"}',
				'mode' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'legacy-provenance' => array(
				'session_id' => 'legacy-provenance', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 11,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:11' ), 'owner_label' => 'User 11', 'messages' => '[]',
				'metadata' => '{"workspace":{"workspace_type":"site","workspace_id":"https://community.example"},"workspace_type":"site","workspace_id":"https://community.example","transcript_owner":{"owner_type":"user","owner_key_hash":"' . hash( 'sha256', 'user:11' ) . '","owner_label":"User 11"}}',
				'mode' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'shared-id' => array(
				'session_id' => 'shared-id', 'workspace_type' => 'site', 'workspace_id' => 'https://main.example', 'user_id' => 7,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:7' ), 'owner_label' => 'User 7', 'messages' => '[{"role":"user","content":"main"}]', 'metadata' => '{}', 'mode' => 'chat',
			),
			'network-newer' => array(
				'session_id' => 'network-newer', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 21,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:21' ), 'owner_label' => 'User 21', 'messages' => '[{"role":"assistant","content":"network-newer"}]', 'metadata' => '{"snapshot":"network"}', 'mode' => 'chat',
				'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 02:00:00',
			),
			'source-newer' => array(
				'session_id' => 'source-newer', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 22,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:22' ), 'owner_label' => 'User 22', 'messages' => '[{"role":"assistant","content":"network-older"}]', 'metadata' => '{}', 'mode' => 'chat',
				'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 01:00:00',
			),
			'equal-divergent' => array(
				'session_id' => 'equal-divergent', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 23,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:23' ), 'owner_label' => 'User 23', 'messages' => '[{"role":"assistant","content":"network-version"}]', 'metadata' => '{}', 'mode' => 'chat',
				'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 01:00:00',
			),
			'concurrent-newer' => array(
				'session_id' => 'concurrent-newer', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 24,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:24' ), 'owner_label' => 'User 24', 'messages' => '[{"role":"assistant","content":"target-old"}]', 'metadata' => '{}', 'mode' => 'chat',
				'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 01:00:00',
			),
			'concurrent-same-second' => array(
				'session_id' => 'concurrent-same-second', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 25,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:25' ), 'owner_label' => 'User 25', 'messages' => '[{"role":"assistant","content":"Case"}]',
				'metadata' => '{"workspace":{"workspace_type":"site","workspace_id":"https://community.example"},"workspace_type":"site","workspace_id":"https://community.example","transcript_owner":{"owner_type":"user","owner_key_hash":"' . hash( 'sha256', 'user:25' ) . '","owner_label":"User 25"}}', 'mode' => 'chat',
				'created_at' => '2026-01-06 00:00:00', 'updated_at' => '2026-01-06 01:00:00',
			),
			'concurrent-trailing-space' => array(
				'session_id' => 'concurrent-trailing-space', 'workspace_type' => 'site', 'workspace_id' => 'https://community.example', 'user_id' => 26,
				'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:26' ), 'owner_label' => 'User 26', 'title' => 'Same title', 'messages' => '[]',
				'metadata' => '{"workspace":{"workspace_type":"site","workspace_id":"https://community.example"},"workspace_type":"site","workspace_id":"https://community.example","transcript_owner":{"owner_type":"user","owner_key_hash":"' . hash( 'sha256', 'user:26' ) . '","owner_label":"User 26"}}', 'mode' => 'chat',
				'created_at' => '2026-01-07 00:00:00', 'updated_at' => '2026-01-07 01:00:00', 'agent_id' => null,
			),
		),
		'wp_2_datamachine_chat_sessions' => array(
			'legacy-user' => array(
				'session_id' => 'legacy-user', 'user_id' => 42, 'agent_id' => 9, 'messages' => '[]',
				'metadata' => '{"message_count":1}', 'context' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'legacy-system' => array(
				'session_id' => 'legacy-system', 'user_id' => 0, 'agent_id' => 3, 'messages' => '[]',
				'metadata' => '{"workspace":{"workspace_type":"site","workspace_id":"https://community.example"}}', 'context' => 'pipeline', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'metadata-scope' => array(
				'session_id' => 'metadata-scope', 'user_id' => 12, 'messages' => '[]',
				'metadata' => '{"workspace":{"workspace_type":"project"},"workspace_type":"ignored-top-level-type","workspace_id":"metadata-workspace"}',
				'context' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'shared-id' => array(
				'session_id' => 'shared-id', 'user_id' => 7, 'agent_id' => 3, 'messages' => '[{"role":"user","content":"community"}]',
				'metadata' => '{}', 'context' => 'chat', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'legacy-provenance' => array(
				'session_id' => 'legacy-provenance', 'user_id' => 11, 'messages' => '[]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
			'network-newer' => array(
				'session_id' => 'network-newer', 'user_id' => 21, 'messages' => '[{"role":"assistant","content":"source-older"}]', 'metadata' => '{"snapshot":"source"}', 'context' => 'chat',
				'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 01:00:00',
			),
			'source-newer' => array(
				'session_id' => 'source-newer', 'user_id' => 22, 'messages' => '[{"role":"assistant","content":"source-newer"}]', 'metadata' => '{"snapshot":"source"}', 'context' => 'chat',
				'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 02:00:00',
			),
			'equal-divergent' => array(
				'session_id' => 'equal-divergent', 'user_id' => 23, 'messages' => '[{"role":"assistant","content":"source-version"}]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 01:00:00',
			),
			'concurrent-newer' => array(
				'session_id' => 'concurrent-newer', 'user_id' => 24, 'messages' => '[{"role":"assistant","content":"source-newer"}]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-05 00:00:00', 'updated_at' => '2026-01-05 02:00:00',
			),
			'concurrent-same-second' => array(
				'session_id' => 'concurrent-same-second', 'user_id' => 25, 'messages' => '[{"role":"assistant","content":"Case"}]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-06 00:00:00', 'updated_at' => '2026-01-06 01:00:00',
			),
			'concurrent-trailing-space' => array(
				'session_id' => 'concurrent-trailing-space', 'user_id' => 26, 'agent_id' => null, 'title' => 'Same title', 'messages' => '[]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-07 00:00:00', 'updated_at' => '2026-01-07 01:00:00',
			),
		),
		'wp_7_datamachine_chat_sessions' => array(),
		'wp_8_datamachine_chat_sessions' => array(
			'retained-inactive-site' => array(
				'session_id' => 'retained-inactive-site', 'user_id' => 17, 'messages' => '[]', 'metadata' => '{}', 'context' => 'chat',
				'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			),
		),
	);
	$GLOBALS['chat_site_options'] = array();
	$GLOBALS['chat_logs']         = array();
	$GLOBALS['chat_sites']        = array(
		array( 'blog_id' => 1, 'archived' => 0, 'deleted' => 0, 'spam' => 0 ),
		array( 'blog_id' => 2, 'archived' => 0, 'deleted' => 0, 'spam' => 0 ),
		array( 'blog_id' => 7, 'archived' => 0, 'deleted' => 0, 'spam' => 0 ),
		array( 'blog_id' => 8, 'archived' => 1, 'deleted' => 1, 'spam' => 0 ),
	);
	$GLOBALS['chat_get_sites_args'] = array();
	$GLOBALS['chat_home_urls']      = array( 1 => 'https://main.example/', 2 => 'https://community.example/', 7 => 'https://events.example/', 8 => 'https://retained.example/' );
	for ( $index = 0; $index < 105; ++$index ) {
		$session_id = sprintf( 'leftover-%03d', $index );
		$GLOBALS['chat_tables']['wp_datamachine_chat_sessions'][ $session_id ] = array(
			'session_id' => $session_id, 'workspace_type' => '', 'workspace_id' => '', 'user_id' => 200 + $index,
			'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:' . ( 200 + $index ) ), 'messages' => '[]', 'metadata' => '{}', 'mode' => 'chat',
		);
	}
	for ( $index = 0; $index < 105; ++$index ) {
		$session_id = sprintf( 'batch-%03d', $index );
		$GLOBALS['chat_tables']['wp_2_datamachine_chat_sessions'][ $session_id ] = array(
			'session_id' => $session_id, 'user_id' => 50 + $index, 'messages' => '[]', 'metadata' => '{}', 'context' => 'chat',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		);
	}
	$GLOBALS['chat_batch_reads'] = array();
	$GLOBALS['chat_cas_prepares'] = array();
	$GLOBALS['chat_collision_probe_reads'] = 0;
	$GLOBALS['chat_force_collision_probe_exhaustion'] = false;
	$GLOBALS['chat_concurrent_updates'] = array(
		'concurrent-newer' => array(
			'messages'   => '[{"role":"assistant","content":"target-concurrent-newest"}]',
			'updated_at' => '2026-01-05 03:00:00',
		),
		'concurrent-same-second' => array(
			'messages'   => '[{"role":"assistant","content":"case"}]',
			'updated_at' => '2026-01-06 01:00:00',
		),
		'concurrent-trailing-space' => array(
			'title'      => 'Same title ',
			'updated_at' => '2026-01-07 01:00:00',
		),
	);

	class ChatConvergenceWpdb {
		public string $base_prefix = 'wp_';
		public string $last_error = '';
		public bool $fail_insert = false;
		public bool $force_update_zero = false;
		public bool $errors_suppressed = false;
		public int $leaked_errors = 0;
		public array $deadlocks_remaining = array();
		public array $migration_update_queries = array();
		public function suppress_errors( bool $suppress = true ): bool { $previous = $this->errors_suppressed; $this->errors_suppressed = $suppress; return $previous; }
		public function get_blog_prefix( int $blog_id ): string { return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_'; }
		public function prepare( string $query, ...$args ): string {
			$prepared_query = $query;
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$prepared_query = preg_replace( '/%[isd]/', $replacement, $prepared_query, 1 );
			}
			if ( str_starts_with( $query, 'UPDATE %i SET ' ) && str_contains( $query, 'BINARY %i = BINARY %s' ) ) {
				$GLOBALS['chat_cas_prepares'][] = array( 'template' => $query, 'query' => $prepared_query );
			}
			return $prepared_query;
		}
		private function unquote( string $value ): string { return str_replace( "''", "'", trim( $value, "'" ) ); }
		public function get_results( string $query, $format = null ): array {
			unset( $format );
			if ( preg_match( "/SHOW COLUMNS FROM '([^']+)'/", $query, $matches ) ) {
				return array_map( static fn( string $column ): array => array( 'Field' => $column ), $GLOBALS['chat_columns'][ $matches[1] ] ?? array() );
			}
			if ( preg_match( "/SELECT \\* FROM '([^']+)' WHERE session_id > ('(?:''|[^'])*')(.*?) ORDER BY session_id ASC LIMIT ([0-9]+)/", $query, $matches ) ) {
				$table  = $matches[1];
				$cursor = $this->unquote( $matches[2] );
				$rows   = array_filter(
					$GLOBALS['chat_tables'][ $table ] ?? array(),
					static fn( array $row ): bool => (string) $row['session_id'] > $cursor
				);
				if ( str_contains( $matches[3], 'workspace_type IS NULL' ) ) {
					$rows = array_filter( $rows, static fn( array $row ): bool => empty( $row['workspace_type'] ) || empty( $row['workspace_id'] ) );
				}
				ksort( $rows, SORT_STRING );
				$rows = array_slice( array_values( $rows ), 0, (int) $matches[4] );
				$GLOBALS['chat_batch_reads'][] = array( 'table' => $table, 'count' => count( $rows ) );
				return $rows;
			}
			return array();
		}
		public function get_row( string $query, $format = null ): ?array {
			unset( $format );
			if ( preg_match( "/SELECT \\* FROM '([^']+)' WHERE session_id = ('(?:''|[^'])*')/", $query, $matches ) ) {
				$session_id = $this->unquote( $matches[2] );
				if ( isset( $GLOBALS['chat_tables'][ $matches[1] ][ $session_id ] ) ) {
					return $GLOBALS['chat_tables'][ $matches[1] ][ $session_id ];
				}
				if ( $GLOBALS['chat_force_collision_probe_exhaustion'] && str_starts_with( $session_id, 'dm-' ) ) {
					++$GLOBALS['chat_collision_probe_reads'];
					return array( 'session_id' => $session_id, 'messages' => '[{"collision":true}]' );
				}
				return null;
			}
			return null;
		}
		public function get_var( string $query ) {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) {
				return isset( $GLOBALS['chat_tables'][ $matches[1] ] ) ? $matches[1] : null;
			}
			if ( preg_match( "/SELECT COUNT\(\*\) FROM '([^']+)' WHERE workspace_type IS NULL/", $query, $matches ) ) {
				return count( array_filter( $GLOBALS['chat_tables'][ $matches[1] ] ?? array(), static fn( array $row ): bool => empty( $row['workspace_type'] ) || empty( $row['workspace_id'] ) ) );
			}
			if ( preg_match( "/SELECT session_id FROM '([^']+)' WHERE session_id = ('(?:''|[^'])*')(.*)$/", $query, $matches ) ) {
				$table = $matches[1];
				$id    = $this->unquote( $matches[2] );
				$row   = $GLOBALS['chat_tables'][ $table ][ $id ] ?? null;
				if ( ! is_array( $row ) ) { return null; }
				if ( '' !== trim( $matches[3] ) && preg_match_all( "/AND ([a-z_]+) = ('(?:''|[^'])*')/", $matches[3], $conditions, PREG_SET_ORDER ) ) {
					foreach ( $conditions as $condition ) {
						if ( (string) ( $row[ $condition[1] ] ?? '' ) !== $this->unquote( $condition[2] ) ) { return null; }
					}
				}
				return $id;
			}
			return null;
		}
		public function insert( string $table, array $row ) {
			if ( $this->fail_insert ) { $this->last_error = 'simulated insert failure'; return false; }
			$GLOBALS['chat_tables'][ $table ][ $row['session_id'] ] = $row;
			return 1;
		}
		public function update( string $table, array $data, array $where ) {
			$id = (string) ( $where['session_id'] ?? '' );
			if ( ! isset( $GLOBALS['chat_tables'][ $table ][ $id ] ) ) { return 0; }
			if ( isset( $GLOBALS['chat_concurrent_updates'][ $id ] ) ) {
				$GLOBALS['chat_tables'][ $table ][ $id ] = array_merge( $GLOBALS['chat_tables'][ $table ][ $id ], $GLOBALS['chat_concurrent_updates'][ $id ] );
				unset( $GLOBALS['chat_concurrent_updates'][ $id ] );
			}
			foreach ( $where as $column => $value ) {
				if ( (string) ( $GLOBALS['chat_tables'][ $table ][ $id ][ $column ] ?? '' ) !== (string) $value ) { return 0; }
			}
			if ( $this->force_update_zero ) { return 0; }
			$changed = array_diff_assoc( $data, $GLOBALS['chat_tables'][ $table ][ $id ] );
			$GLOBALS['chat_tables'][ $table ][ $id ] = array_merge( $GLOBALS['chat_tables'][ $table ][ $id ], $data );
			return empty( $changed ) ? 0 : 1;
		}
		public function query( string $query ): int|false {
			if ( preg_match( "/^UPDATE '([^']+)' SET (.*) WHERE (.*)$/", $query, $matches ) && str_contains( $matches[3], 'BINARY ' ) ) {
				$table = $matches[1];
				preg_match( "/BINARY 'session_id' = BINARY ('(?:''|[^'])*')/", $matches[3], $id_match );
				$id = isset( $id_match[1] ) ? $this->unquote( $id_match[1] ) : '';
				$this->migration_update_queries[] = $query;
				if ( 0 < ( $this->deadlocks_remaining[ $id ] ?? 0 ) ) {
					--$this->deadlocks_remaining[ $id ];
					$this->last_error = 'Deadlock found when trying to get lock; try restarting transaction';
					if ( ! $this->errors_suppressed ) { ++$this->leaked_errors; }
					return false;
				}
				$this->last_error = '';
				if ( ! isset( $GLOBALS['chat_tables'][ $table ][ $id ] ) ) { return 0; }
				if ( isset( $GLOBALS['chat_concurrent_updates'][ $id ] ) ) {
					$GLOBALS['chat_tables'][ $table ][ $id ] = array_merge( $GLOBALS['chat_tables'][ $table ][ $id ], $GLOBALS['chat_concurrent_updates'][ $id ] );
					unset( $GLOBALS['chat_concurrent_updates'][ $id ] );
				}
				$row = $GLOBALS['chat_tables'][ $table ][ $id ];
				preg_match_all( "/BINARY '([a-z_]+)' = BINARY ('(?:''|[^'])*')/", $matches[3], $binary_conditions, PREG_SET_ORDER );
				foreach ( $binary_conditions as $condition ) {
					if ( (string) ( $row[ $condition[1] ] ?? '' ) !== $this->unquote( $condition[2] ) ) { return 0; }
				}
				preg_match_all( "/(?:^| AND )'([a-z_]+)' = (-?[0-9]+)/", $matches[3], $numeric_conditions, PREG_SET_ORDER );
				foreach ( $numeric_conditions as $condition ) {
					if ( (int) ( $row[ $condition[1] ] ?? 0 ) !== (int) $condition[2] ) { return 0; }
				}
				preg_match_all( "/(?:^| AND )'([a-z_]+)' IS NULL/", $matches[3], $null_conditions, PREG_SET_ORDER );
				foreach ( $null_conditions as $condition ) {
					if ( null !== ( $row[ $condition[1] ] ?? null ) ) { return 0; }
				}
				if ( $this->force_update_zero ) { return 0; }
				$data = array();
				preg_match_all( "/'([a-z_]+)' = (NULL|-?[0-9]+|'(?:''|[^'])*')/", $matches[2], $assignments, PREG_SET_ORDER );
				foreach ( $assignments as $assignment ) {
					$data[ $assignment[1] ] = 'NULL' === $assignment[2] ? null : ( "'" === $assignment[2][0] ? $this->unquote( $assignment[2] ) : (int) $assignment[2] );
				}
				$changed = array_diff_assoc( $data, $row );
				$GLOBALS['chat_tables'][ $table ][ $id ] = array_merge( $row, $data );
				return empty( $changed ) ? 0 : 1;
			}
			if ( preg_match( "/UPDATE '([^']+)' SET workspace_type = ('(?:''|[^'])*'), workspace_id = ('(?:''|[^'])*')/", $query, $matches ) ) {
				foreach ( $GLOBALS['chat_tables'][ $matches[1] ] as &$row ) {
					if ( empty( $row['workspace_type'] ) || empty( $row['workspace_id'] ) ) {
						$row['workspace_type'] = $this->unquote( $matches[2] );
						$row['workspace_id']   = $this->unquote( $matches[3] );
					}
				}
				unset( $row );
			}
			return 1;
		}
	}

	$GLOBALS['wpdb'] = new ChatConvergenceWpdb();
	function is_multisite(): bool { return true; }
	function get_sites( array $args ): array {
		$GLOBALS['chat_get_sites_args'][] = $args;
		$sites = array_filter(
			$GLOBALS['chat_sites'],
			static function ( array $site ) use ( $args ): bool {
				foreach ( array( 'archived', 'deleted', 'spam' ) as $flag ) {
					if ( isset( $args[ $flag ] ) && (int) $args[ $flag ] !== $site[ $flag ] ) {
						return false;
					}
				}
				return true;
			}
		);
		return array_values( array_map( static fn( array $site ): int => $site['blog_id'], $sites ) );
	}
	function get_main_site_id(): int { return 1; }
	function get_home_url( int $blog_id, string $path = '' ): string { unset( $path ); return $GLOBALS['chat_home_urls'][ $blog_id ] ?? ''; }
	function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
	function absint( $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function wp_json_encode( $value ): string { return (string) json_encode( $value ); }
	function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-01-01 00:00:00'; }
	function get_site_option( string $key, $default = false ) { return $GLOBALS['chat_site_options'][ $key ] ?? $default; }
	function update_site_option( string $key, $value ): bool { $GLOBALS['chat_site_options'][ $key ] = $value; return true; }
	function delete_site_option( string $key ): bool { unset( $GLOBALS['chat_site_options'][ $key ] ); return true; }
	function do_action( string $hook, ...$args ): void { $GLOBALS['chat_logs'][] = array( $hook, $args ); }

	require_once dirname( __DIR__ ) . '/inc/Core/Database/LifecycleStateTransition.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/Chat.php';
	require_once dirname( __DIR__ ) . '/inc/setup/chat-sessions-network.php';

	$failures = 0;
	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) { ++$failures; fwrite( STDERR, "FAIL: {$message}\n" ); }
	};

	\DataMachine\Core\Database\Chat\Chat::ensure_workspace_columns();
	$assert( '' === ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['main-legacy']['workspace_id'] ?? '' ), 'early schema gate leaves network rows unscoped for source-aware reconciliation' );
	$assert( datamachine_converge_chat_sessions_to_network(), 'legacy convergence succeeds' );
	$site_query_args = $GLOBALS['chat_get_sites_args'][0] ?? array();
	$user_row = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['legacy-user'] ?? array();
	$system_row = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['legacy-system'] ?? array();
	$metadata_row = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['metadata-scope'] ?? array();
	$retained_site_row = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['retained-inactive-site'] ?? array();
	$assert( ! isset( $site_query_args['archived'], $site_query_args['deleted'], $site_query_args['spam'] ), 'site enumeration leaves WordPress active-state filters unconstrained' );
	$assert( 'https://retained.example' === ( $retained_site_row['workspace_id'] ?? '' ), 'retained archived/deleted site table is converged' );
	$assert( 'site' === ( $user_row['workspace_type'] ?? '' ) && 'https://community.example' === ( $user_row['workspace_id'] ?? '' ), 'legacy row derives source-site workspace' );
	$assert( 1 === count( array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'legacy-user' === ( $row['session_id'] ?? '' ) ) ), 'old unscoped INSERT IGNORE copy is claimed without duplication' );
	$assert( 'project' === ( $metadata_row['workspace_type'] ?? '' ) && 'metadata-workspace' === ( $metadata_row['workspace_id'] ?? '' ), 'empty canonical scope resolves nested then top-level metadata before source fallback' );
	$assert( 1 === count( array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'metadata-scope' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? ( $row['session_id'] ?? '' ) ) ) ), 'metadata-scoped prior network copy is claimed without duplication' );
	$assert( 'https://main.example' === ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['main-legacy']['workspace_id'] ?? '' ), 'unclaimed network leftover maps to main workspace after subsite reconciliation' );
	$assert( 1 === count( array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'legacy-provenance' === ( $row['session_id'] ?? '' ) ) ), 'provenance-only difference does not duplicate a matching transcript' );
	$provenance = json_decode( (string) ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['legacy-provenance']['metadata'] ?? '' ), true );
	$assert( 2 === (int) ( $provenance['migration_source']['blog_id'] ?? 0 ), 'matching row is enriched with source provenance' );
	$assert( isset( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['batch-104'] ), 'bounded convergence reaches later source batches' );
	$assert( count( array_filter( $GLOBALS['chat_batch_reads'], static fn( array $read ): bool => 'wp_2_datamachine_chat_sessions' === $read['table'] ) ) >= 2, 'source history is read in multiple bounded batches' );
	$assert( count( array_filter( $GLOBALS['chat_batch_reads'], static fn( array $read ): bool => 'wp_datamachine_chat_sessions' === $read['table'] ) ) >= 2, 'unscoped target leftovers are claimed in multiple bounded batches' );
	$assert( 0 === count( array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => empty( $row['workspace_type'] ) || empty( $row['workspace_id'] ) ) ), 'no unscoped canonical rows remain before marker advancement' );
	$assert( hash( 'sha256', 'user:42' ) === ( $user_row['owner_key_hash'] ?? '' ), 'legacy user row derives canonical owner hash' );
	$assert( hash( 'sha256', 'user:0' ) === ( $system_row['owner_key_hash'] ?? '' ), 'system transcript follows current user:0 owner logic' );
	$assert( 'pipeline' === ( $system_row['mode'] ?? '' ), 'legacy context canonicalizes to mode' );
	$assert( str_contains( (string) ( $user_row['metadata'] ?? '' ), 'workspace_type' ), 'canonical metadata carries workspace for pending-session scoped reads' );
	$assert( true === ( $GLOBALS['chat_site_options']['datamachine_chat_sessions_network_migrated']['verified'] ?? false ), 'marker records scoped parity' );
	$collision_rows = array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'shared-id' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? '' ) );
	$assert( 1 === count( $collision_rows ), 'same-ID different payload receives one collision-safe canonical row' );
	$collision_row = reset( $collision_rows );
	$assert( 'https://community.example' === ( $collision_row['workspace_id'] ?? '' ) && str_starts_with( (string) ( $collision_row['session_id'] ?? '' ), 'dm-' ), 'collision transcript remains scoped and retrievable separately' );
	$assert( '[{"role":"user","content":"main"}]' === ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['shared-id']['messages'] ?? '' ) && '[{"role":"user","content":"community"}]' === ( $collision_row['messages'] ?? '' ), 'both same-ID transcripts preserve their distinct payloads' );
	$network_newer = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['network-newer'] ?? array();
	$assert( '[{"role":"assistant","content":"network-newer"}]' === ( $network_newer['messages'] ?? '' ), 'newer canonical snapshot is not regressed by a stale logical-session source' );
	$assert( 2 === (int) ( json_decode( (string) ( $network_newer['metadata'] ?? '' ), true )['migration_source']['blog_id'] ?? 0 ), 'newer canonical snapshot is enriched with source provenance' );
	$assert( 0 === count( array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'network-newer' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? '' ) && 'network-newer' !== ( $row['session_id'] ?? '' ) ) ), 'network-newer logical session does not create a stale duplicate' );
	$assert( '[{"role":"assistant","content":"source-newer"}]' === ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['source-newer']['messages'] ?? '' ), 'newer source snapshot is promoted over stale canonical content' );
	$equal_collisions = array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'equal-divergent' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? '' ) && 'equal-divergent' !== ( $row['session_id'] ?? '' ) );
	$assert( 1 === count( $equal_collisions ), 'equal-version divergent logical sessions preserve both payloads through collision handling' );
	$equal_collision = reset( $equal_collisions );
	$assert( '[{"role":"assistant","content":"network-version"}]' === ( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['equal-divergent']['messages'] ?? '' ) && '[{"role":"assistant","content":"source-version"}]' === ( $equal_collision['messages'] ?? '' ), 'equal-version collision requires parity for both divergent snapshots' );
	$concurrent_newer = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['concurrent-newer'] ?? array();
	$assert( '[{"role":"assistant","content":"target-concurrent-newest"}]' === ( $concurrent_newer['messages'] ?? '' ), 'concurrent newer target content is preserved after a failed promotion CAS' );
	$assert( 2 === (int) ( json_decode( (string) ( $concurrent_newer['metadata'] ?? '' ), true )['migration_source']['blog_id'] ?? 0 ), 'refetched newer target still receives provenance enrichment' );
	$same_second_target = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['concurrent-same-second'] ?? array();
	$same_second_collisions = array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'concurrent-same-second' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? '' ) && 'concurrent-same-second' !== ( $row['session_id'] ?? '' ) );
	$assert( '[{"role":"assistant","content":"case"}]' === ( $same_second_target['messages'] ?? '' ), 'same-second case-only target mutation is detected by byte-exact content CAS and never overwritten' );
	$assert( 1 === count( $same_second_collisions ), 'same-second divergent source is preserved through deterministic collision handling' );
	$trailing_space_target = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['concurrent-trailing-space'] ?? array();
	$trailing_space_collisions = array_filter( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'], static fn( array $row ): bool => 'concurrent-trailing-space' === ( json_decode( (string) ( $row['metadata'] ?? '' ), true )['migration_source']['session_id'] ?? '' ) && 'concurrent-trailing-space' !== ( $row['session_id'] ?? '' ) );
	$assert( 'Same title ' === ( $trailing_space_target['title'] ?? '' ), 'same-second trailing-space target mutation is detected by byte-exact content CAS and never overwritten' );
	$assert( 1 === count( $trailing_space_collisions ), 'trailing-space divergent source is preserved through deterministic collision handling' );
	$cas_templates = array_column( $GLOBALS['chat_cas_prepares'], 'template' );
	$cas_queries   = array_column( $GLOBALS['chat_cas_prepares'], 'query' );
	$assert( count( array_filter( $cas_templates, static fn( string $query ): bool => str_contains( $query, 'BINARY %i = BINARY %s' ) ) ) === count( $cas_templates ), 'all textual CAS predicates are explicitly byte-exact' );
	$assert( 0 < count( array_filter( $cas_queries, static fn( string $query ): bool => str_contains( $query, 'BINARY \'messages\' = BINARY \'[{"role":"assistant","content":"Case"}]\'' ) ) ), 'prepared CAS renders a byte-exact messages predicate for case-sensitive observation' );
	$assert( 0 < count( array_filter( $cas_queries, static fn( string $query ): bool => str_contains( $query, 'BINARY \'title\' = BINARY \'Same title\'' ) ) ), 'prepared CAS renders a byte-exact title predicate for trailing-space-sensitive observation' );
	$assert( 0 < count( array_filter( $cas_templates, static fn( string $query ): bool => str_contains( $query, '%i = %d' ) && str_contains( $query, '%i IS NULL' ) ) ), 'CAS preparation retains numeric and null predicate handling' );
	$row_count = count( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'] );
	$assert( datamachine_converge_chat_sessions_to_network(), 'convergence rerun is idempotent' );
	$assert( $row_count === count( $GLOBALS['chat_tables']['wp_datamachine_chat_sessions'] ), 'rerun does not duplicate canonical or collision rows' );
	$GLOBALS['wpdb']->force_update_zero = true;
	$chat = new \DataMachine\Core\Database\Chat\Chat();
	$read_at = $chat->mark_session_read_for_workspace(
		\AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts( 'site', 'https://community.example' ),
		'legacy-user',
		42,
		array( 'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:42' ) )
	);
	$assert( '2026-01-01 00:00:00' === $read_at, 'same-second scoped mark-read is an idempotent success after ownership verification' );
	$GLOBALS['wpdb']->force_update_zero = false;

	$update_target = new \ReflectionMethod( \DataMachine\Core\Database\Chat\Chat::class, 'update_migration_target' );
	$observed      = $GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['legacy-user'];
	$query_offset  = count( $GLOBALS['wpdb']->migration_update_queries );
	$GLOBALS['wpdb']->deadlocks_remaining['legacy-user'] = 2;
	$updated = $update_target->invoke( null, 'wp_datamachine_chat_sessions', $observed, $observed );
	$retry_queries = array_slice( $GLOBALS['wpdb']->migration_update_queries, $query_offset );
	$assert( 0 === $updated && 3 === count( $retry_queries ), 'transient migration deadlocks retry until the exact CAS succeeds' );
	$assert( 1 === count( array_unique( $retry_queries ) ), 'deadlock retries preserve the complete optimistic row-match predicate' );
	$assert( 0 === $GLOBALS['wpdb']->leaked_errors && ! $GLOBALS['wpdb']->errors_suppressed, 'recovered deadlocks do not leak database output or alter caller suppression state' );

	$query_offset = count( $GLOBALS['wpdb']->migration_update_queries );
	$GLOBALS['wpdb']->deadlocks_remaining['legacy-user'] = 5;
	$updated = $update_target->invoke( null, 'wp_datamachine_chat_sessions', $observed, $observed );
	$retry_queries = array_slice( $GLOBALS['wpdb']->migration_update_queries, $query_offset );
	$assert( false === $updated && 5 === count( $retry_queries ), 'persistent migration deadlocks stop after the bounded attempt budget' );
	$assert( 1 === count( array_unique( $retry_queries ) ) && str_contains( $GLOBALS['wpdb']->last_error, 'Deadlock found' ), 'exhaustion retains the exact CAS and database error for convergence reporting' );
	$assert( 0 === $GLOBALS['wpdb']->leaked_errors && ! $GLOBALS['wpdb']->errors_suppressed, 'exhausted retries remain contained and restore caller suppression state' );

	$GLOBALS['chat_tables']['wp_7_datamachine_chat_sessions']['unmappable'] = array( 'session_id' => 'unmappable', 'user_id' => 1, 'messages' => '[]', 'metadata' => '{}', 'context' => 'chat' );
	$GLOBALS['chat_home_urls'][7] = '';
	$assert( ! datamachine_converge_chat_sessions_to_network(), 'unmappable source row fails parity' );
	$assert( ! isset( $GLOBALS['chat_site_options']['datamachine_chat_sessions_network_migrated'] ), 'unmappable row clears rather than advances marker' );

	$GLOBALS['chat_home_urls'][7] = 'https://events.example/';
	$assert( datamachine_converge_chat_sessions_to_network(), 'safe source-site fallback retries successfully' );

	$GLOBALS['chat_tables']['wp_2_datamachine_chat_sessions']['probe-exhaustion'] = array(
		'session_id' => 'probe-exhaustion', 'user_id' => 27, 'messages' => '[{"source":true}]', 'metadata' => '{}', 'context' => 'chat',
		'created_at' => '2026-01-08 00:00:00', 'updated_at' => '2026-01-08 01:00:00',
	);
	$GLOBALS['chat_tables']['wp_datamachine_chat_sessions']['probe-exhaustion'] = array(
		'session_id' => 'probe-exhaustion', 'workspace_type' => 'site', 'workspace_id' => 'https://main.example', 'user_id' => 27,
		'owner_type' => 'user', 'owner_key_hash' => hash( 'sha256', 'user:27' ), 'messages' => '[{"target":true}]', 'metadata' => '{}', 'mode' => 'chat',
	);
	$GLOBALS['chat_force_collision_probe_exhaustion'] = true;
	$assert( ! datamachine_converge_chat_sessions_to_network(), 'collision-probe exhaustion fails convergence closed' );
	$assert( 100 === $GLOBALS['chat_collision_probe_reads'], 'collision probing stops at its named finite limit' );
	$assert( ! isset( $GLOBALS['chat_site_options']['datamachine_chat_sessions_network_migrated'] ), 'collision-probe exhaustion prevents marker advancement' );
	$last_log = end( $GLOBALS['chat_logs'] );
	$assert( str_contains( (string) ( $last_log[1][2]['error'] ?? '' ), 'Exhausted 100 deterministic collision IDs for chat session probe-exhaustion' ), 'collision-probe exhaustion reports a clear convergence error' );

	if ( $failures ) { exit( 1 ); }
	echo "Chat session network convergence smoke passed.\n";
}
