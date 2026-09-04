<?php
/**
 * Option-backed run-control store.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( WP_Agent_Atomic_Workspace_Run_Control_Store::class ) ) {
	require_once __DIR__ . '/interface-wp-agent-atomic-workspace-run-control-store.php';
}

/**
 * Persists run-control state in WordPress options.
 */
class WP_Agent_Option_Run_Control_Store implements WP_Agent_Atomic_Workspace_Run_Control_Store {

	private const LOCK_PREFIX       = '_agents_api_run_lock_';
	private const LOCK_WAIT_SECONDS = 5.0;
	private const LOCK_TTL_SECONDS  = 15.0;

	private ?\wpdb $database;

	public function __construct( ?\wpdb $database = null ) {
		$this->database = $database;
	}

	/**
	 * @param string $store_key Store key.
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_state( string $store_key ): array {
		$state = function_exists( 'get_option' ) ? get_option( $store_key, array() ) : array();
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/**
	 * @param string $store_key Store key.
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_state( string $store_key, array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $store_key, $state, false );
		}
	}

	public function mutate_state( string $store_key, callable $mutation ): mixed {
		$db         = $this->database();
		$table      = $db->options;
		$lock_key   = $this->lock_option_key( 'site:' . $table . ':' . $store_key );
		$lock_value = $this->acquire_lock( $db, $table, $lock_key );

		try {
			$state   = $this->read_option_state( $db, $table, $store_key );
			$mutated = $this->mutation_result( $mutation( $state ) );
			$lock_value = $this->commit_under_lock(
				$db,
				$table,
				$lock_key,
				$lock_value,
				fn() => $this->write_option_state( $db, $table, $store_key, $mutated['state'] )
			);
			$this->refresh_site_cache( $store_key, $mutated['state'] );
			return $mutated['result'];
		} finally {
			$this->release_lock( $db, $table, $lock_key, $lock_value );
		}
	}

	/**
	 * Read explicit workspace state from the network-wide option table.
	 *
	 * Omitted workspaces continue through get_state() and remain site-local.
	 *
	 * @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>}
	 */
	public function get_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace ): array {
		if ( ! function_exists( 'get_site_option' ) ) {
			throw new \RuntimeException( 'The run-control store cannot share explicit workspace state.' );
		}

		$state = get_site_option( $this->workspace_option_key( $store_key, $workspace ), array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/**
	 * Save explicit workspace state in the network-wide option table.
	 *
	 * @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state State envelope.
	 */
	public function save_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, array $state ): void {
		if ( ! function_exists( 'update_site_option' ) ) {
			throw new \RuntimeException( 'The run-control store cannot share explicit workspace state.' );
		}

		update_site_option( $this->workspace_option_key( $store_key, $workspace ), $state );
	}

	public function mutate_workspace_state( string $store_key, WP_Agent_Workspace_Scope $workspace, callable $mutation ): mixed {
		$db         = $this->database();
		$option_key = $this->workspace_option_key( $store_key, $workspace );
		if ( function_exists( 'is_multisite' ) && ! is_multisite() ) {
			return $this->mutate_state( $option_key, $mutation );
		}
		$lock_table = $this->main_options_table( $db );
		$lock_key   = $this->lock_option_key( 'workspace:' . $db->sitemeta . ':' . $this->network_id( $db ) . ':' . $option_key );
		$lock_value = $this->acquire_lock( $db, $lock_table, $lock_key );

		try {
			$state   = $this->read_workspace_state( $db, $option_key );
			$mutated = $this->mutation_result( $mutation( $state ) );
			$lock_value = $this->commit_under_lock(
				$db,
				$lock_table,
				$lock_key,
				$lock_value,
				fn() => $this->write_workspace_state( $db, $option_key, $mutated['state'] )
			);
			$this->refresh_workspace_cache( $this->network_id( $db ), $option_key, $mutated['state'] );
			return $mutated['result'];
		} finally {
			$this->release_lock( $db, $lock_table, $lock_key, $lock_value );
		}
	}

	private function workspace_option_key( string $store_key, WP_Agent_Workspace_Scope $workspace ): string {
		return $store_key . '_workspace_' . hash( 'sha256', $workspace->key() );
	}

	private function database(): \wpdb {
		if ( $this->database instanceof \wpdb ) {
			return $this->database;
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'Atomic run-control mutation requires a WordPress database connection.' );
		}

		return $wpdb;
	}

	private function main_options_table( \wpdb $db ): string {
		$network_id   = $this->network_id( $db );
		$main_site_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id( $network_id ) : 1;
		return $db->get_blog_prefix( max( 1, $main_site_id ) ) . 'options';
	}

	private function network_id( \wpdb $db ): int {
		return max( 1, (int) $db->siteid );
	}

	private function lock_option_key( string $scope ): string {
		return self::LOCK_PREFIX . hash( 'sha256', $scope );
	}

	private function acquire_lock( \wpdb $db, string $table, string $lock_key ): string {
		$token    = $this->lock_token();
		$deadline = microtime( true ) + self::LOCK_WAIT_SECONDS;

		do {
			$lock_value = $this->lock_value( $token );
			$created    = $this->lock_query( $db, $db->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $table, $lock_key, $lock_value ) );
			if ( 1 === $created ) {
				return $lock_value;
			}
			if ( false === $created ) {
				usleep( 50000 );
				continue;
			}

			$current = $this->get_var( $db, $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, $lock_key ) );
			if ( is_string( $current ) && $this->lock_expired( $current ) ) {
				$replaced = $this->lock_query( $db, $db->prepare( "UPDATE %i SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s", $table, $lock_value, $lock_key, $current ) );
				if ( 1 === $replaced ) {
					return $lock_value;
				}
				if ( false === $replaced ) {
					usleep( 50000 );
					continue;
				}
			}

			usleep( 50000 );
		} while ( microtime( true ) < $deadline );

		throw new \RuntimeException( 'Atomic run-control lock acquisition timed out.' );
	}

	private function release_lock( \wpdb $db, string $table, string $lock_key, string $lock_value ): void {
		$released = $this->query( $db, $db->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $table, $lock_key, $lock_value ) );
		if ( false === $released ) {
			throw new \RuntimeException( 'Atomic run-control lock release failed.' );
		}
	}

	private function commit_under_lock( \wpdb $db, string $table, string $lock_key, string $lock_value, callable $write ): string {
		$decoded = json_decode( $lock_value, true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['token'] ?? null ) ) {
			throw new \RuntimeException( 'Atomic run-control lock token is invalid.' );
		}
		if ( false === $this->query( $db, 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Atomic run-control transaction could not start.' );
		}
		try {
			$current = $this->get_var( $db, $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $table, $lock_key ) );
			if ( ! is_string( $current ) || ! hash_equals( $lock_value, $current ) ) {
				throw new \RuntimeException( 'Atomic run-control lock ownership was lost before state write.' );
			}
			$refreshed = $this->lock_value( $decoded['token'] );
			$updated   = $this->query( $db, $db->prepare( "UPDATE %i SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s", $table, $refreshed, $lock_key, $lock_value ) );
			if ( 1 !== $updated ) {
				throw new \RuntimeException( 'Atomic run-control lock ownership was lost before state write.' );
			}
			$write();
			if ( false === $this->query( $db, 'COMMIT' ) ) {
				throw new \RuntimeException( 'Atomic run-control transaction could not commit.' );
			}
			return $refreshed;
		} catch ( \Throwable $error ) {
			$this->query( $db, 'ROLLBACK' );
			throw $error;
		}
	}

	private function lock_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return hash( 'sha256', uniqid( '', true ) );
		}
	}

	private function lock_value( string $token ): string {
		$value = json_encode(
			array(
				'token'      => $token,
				'expires_at' => microtime( true ) + self::LOCK_TTL_SECONDS,
			)
		);
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException( 'Atomic run-control lock could not be encoded.' );
		}
		return $value;
	}

	private function lock_expired( string $lock_value ): bool {
		$decoded = json_decode( $lock_value, true );
		return ! is_array( $decoded ) || ! is_numeric( $decoded['expires_at'] ?? null ) || (float) $decoded['expires_at'] <= microtime( true );
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function read_option_state( \wpdb $db, string $table, string $store_key ): array {
		$value = $this->get_var( $db, $db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, $store_key ) );
		return $this->decode_state( $value );
	}

	/** @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state */
	private function write_option_state( \wpdb $db, string $table, string $store_key, array $state ): void {
		$written = $this->query( $db, $db->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'", $table, $store_key, $this->serialize_value( $state ) ) );
		if ( false === $written ) {
			throw new \RuntimeException( 'Atomic run-control state write failed.' );
		}
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function read_workspace_state( \wpdb $db, string $option_key ): array {
		$value = $this->get_var( $db, $db->prepare( 'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1', $db->sitemeta, $this->network_id( $db ), $option_key ) );
		return $this->decode_state( $value );
	}

	/** @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state */
	private function write_workspace_state( \wpdb $db, string $option_key, array $state ): void {
		$value   = $this->serialize_value( $state );
		$updated = $this->query( $db, $db->prepare( 'UPDATE %i SET meta_value = %s WHERE site_id = %d AND meta_key = %s', $db->sitemeta, $value, $this->network_id( $db ), $option_key ) );
		if ( false === $updated ) {
			throw new \RuntimeException( 'Atomic workspace run-control state write failed.' );
		}
		if ( 0 === $updated ) {
			$existing = $this->get_var( $db, $db->prepare( 'SELECT meta_id FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 1', $db->sitemeta, $this->network_id( $db ), $option_key ) );
			if ( null !== $existing ) {
				return;
			}
			$inserted = $this->query( $db, $db->prepare( 'INSERT INTO %i (site_id, meta_key, meta_value) VALUES (%d, %s, %s)', $db->sitemeta, $this->network_id( $db ), $option_key, $value ) );
			if ( 1 !== $inserted ) {
				throw new \RuntimeException( 'Atomic workspace run-control state creation failed.' );
			}
		}
	}

	private function serialize_value( mixed $value ): string {
		$serialized = function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
		return is_string( $serialized ) ? $serialized : serialize( $serialized );
	}

	private function unserialize_value( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return array();
		}
		return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : @unserialize( $value );
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function decode_state( mixed $value ): array {
		if ( null === $value ) {
			return $this->normalize_state( array() );
		}
		$state = $this->unserialize_value( $value );
		if ( ! is_array( $state ) || ! $this->valid_state_shape( $state ) ) {
			throw new \RuntimeException( 'Atomic run-control state is corrupt.' );
		}
		return $this->normalize_state( $state );
	}

	/** @param array<mixed> $state */
	private function valid_state_shape( array $state ): bool {
		$runs   = $state['runs'] ?? array();
		$queues = $state['queues'] ?? array();
		$events = $state['events'] ?? array();
		if ( ! is_array( $runs ) || ! is_array( $queues ) || ! is_array( $events ) ) {
			return false;
		}
		foreach ( $runs as $run_id => $run ) {
			if ( ! is_string( $run_id ) || ! is_array( $run ) ) {
				return false;
			}
		}
		foreach ( array( $queues, $events ) as $collection ) {
			foreach ( $collection as $scope => $items ) {
				if ( ! is_string( $scope ) || ! is_array( $items ) ) {
					return false;
				}
				foreach ( $items as $item ) {
					if ( ! is_array( $item ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/** @return array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} */
	private function normalize_state( mixed $state ): array {
		$state = is_array( $state ) ? $state : array();
		return array(
			'runs'   => $this->stored_runs( $state['runs'] ?? array() ),
			'queues' => $this->stored_queues( $state['queues'] ?? array() ),
			'events' => $this->stored_queues( $state['events'] ?? array() ),
		);
	}

	/** @return array{state:array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>},result:mixed} */
	private function mutation_result( mixed $mutation ): array {
		if ( ! is_array( $mutation ) || ! is_array( $mutation['state'] ?? null ) || ! array_key_exists( 'result', $mutation ) ) {
			throw new \RuntimeException( 'Atomic run-control mutations must return state and result.' );
		}
		return array(
			'state'  => $this->normalize_state( $mutation['state'] ),
			'result' => $mutation['result'],
		);
	}

	/** @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state */
	private function refresh_site_cache( string $store_key, array $state ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $store_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $store_key, $state, 'options' );
		}
	}

	/** @param array{runs:array<string,array<string,mixed>>,queues:array<string,array<int,array<string,mixed>>>,events:array<string,array<int,array<string,mixed>>>} $state */
	private function refresh_workspace_cache( int $network_id, string $option_key, array $state ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $network_id . ':' . $option_key, 'site-options' );
			wp_cache_delete( $network_id . ':notoptions', 'site-options' );
		}
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $network_id . ':' . $option_key, $state, 'site-options' );
		}
	}

	/** @return int|bool */
	private function query( \wpdb $db, ?string $query ) {
		if ( ! is_string( $query ) ) {
			throw new \RuntimeException( 'Atomic run-control query preparation failed.' );
		}
		return $db->query( $query );
	}

	private function get_var( \wpdb $db, ?string $query ): mixed {
		if ( ! is_string( $query ) ) {
			throw new \RuntimeException( 'Atomic run-control query preparation failed.' );
		}
		$value = $db->get_var( $query );
		if ( '' !== $db->last_error ) {
			throw new \RuntimeException( 'Atomic run-control state read failed: ' . $db->last_error );
		}
		return $value;
	}

	/** @return int|bool */
	private function lock_query( \wpdb $db, ?string $query ) {
		$previous = $db->suppress_errors( true );
		try {
			return $this->query( $db, $query );
		} finally {
			$db->suppress_errors( $previous );
		}
	}

	/**
	 * @param mixed $runs Raw stored runs.
	 * @return array<string,array<string,mixed>>
	 */
	private function stored_runs( mixed $runs ): array {
		if ( ! is_array( $runs ) ) {
			return array();
		}

		$stored = array();
		foreach ( $runs as $run_id => $run ) {
			if ( is_string( $run_id ) && is_array( $run ) ) {
				$stored[ $run_id ] = $this->assoc_array( $run );
			}
		}

		return $stored;
	}

	/**
	 * @param mixed $queues Raw stored queues.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function stored_queues( mixed $queues ): array {
		if ( ! is_array( $queues ) ) {
			return array();
		}

		$stored = array();
		foreach ( $queues as $scope => $items ) {
			if ( ! is_string( $scope ) || ! is_array( $items ) ) {
				continue;
			}

			$stored[ $scope ] = array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					$stored[ $scope ][] = $this->assoc_array( $item );
				}
			}
		}

		return $stored;
	}

	/**
	 * @param array<mixed> $value Raw array.
	 * @return array<string,mixed>
	 */
	private function assoc_array( array $value ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$result[ $key ] = $item;
			}
		}
		return $result;
	}
}
