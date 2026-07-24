#!/usr/bin/env php
<?php
/**
 * Standalone real-MySQL harness for the canonical venue mutation class.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace {
	$dsn = trim( (string) getenv( 'DME_MYSQL_TEST_DSN' ) );
	if ( '' === $dsn ) {
		fwrite( STDOUT, "SKIP: DME_MYSQL_TEST_DSN is unavailable; no MySQL test endpoint was contacted.\n" );
		exit( 0 );
	}
	if ( ! extension_loaded( 'pdo_mysql' ) ) {
		fwrite( STDERR, "FAIL: pdo_mysql is required.\n" );
		exit( 1 );
	}
	if ( ! preg_match( '/(?:^|;)dbname=([^;]+)/i', $dsn, $matches ) || ! str_contains( strtolower( $matches[1] ), 'test' ) ) {
		fwrite( STDERR, "FAIL: DME_MYSQL_TEST_DSN must name an explicit test database.\n" );
		exit( 1 );
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DB_NAME', $matches[1] );
	$GLOBALS['dme_test_dsn']      = $dsn;
	$GLOBALS['dme_test_user']     = (string) getenv( 'DME_MYSQL_TEST_USER' );
	$GLOBALS['dme_test_password'] = (string) getenv( 'DME_MYSQL_TEST_PASSWORD' );
	$GLOBALS['dme_test_blog_id']  = 1;
	$GLOBALS['dme_test_filters']  = array();
	$GLOBALS['dme_test_actions']  = array();
	$GLOBALS['dme_test_table']    = 'dme_venue_mutation_' . bin2hex( random_bytes( 6 ) );

	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	class WP_Term {
		public function __construct( public int $term_id, public string $name, public string $description ) {}
	}

	class DmeTestWpdb {
		public ?\PDO $dbh;
		public string $prefix = 'wp_';
		public string $last_error = '';
		private bool $suppress_errors = false;

		public function __construct() { $this->connect(); }
		private function connect(): void {
			$this->dbh = new \PDO(
				$GLOBALS['dme_test_dsn'],
				$GLOBALS['dme_test_user'],
				$GLOBALS['dme_test_password'],
				array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION )
			);
			$this->dbh->exec( 'SET SESSION lock_wait_timeout = 5' );
		}
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : $this->dbh->quote( (string) $arg );
				$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
			}
			return $query;
		}
		public function get_var( string $query ): mixed {
			try {
				return $this->dbh->query( $query )->fetchColumn();
			} catch ( \Throwable $throwable ) {
				$this->last_error = $throwable->getMessage();
				return null;
			}
		}
		public function query( string $query ): int|false {
			try {
				$this->last_error = '';
				return $this->dbh->exec( $query );
			} catch ( \Throwable $throwable ) {
				$this->last_error = $throwable->getMessage();
				return false;
			}
		}
		public function close(): bool {
			if ( null === $this->dbh ) {
				return false;
			}
			$this->dbh = null;
			return true;
		}
		public function check_connection( bool $allow_bail = true ): bool {
			if ( null === $this->dbh ) {
				$this->connect();
			}
			return true;
		}
		public function suppress_errors( ?bool $suppress = null ): bool {
			$previous = $this->suppress_errors;
			if ( null !== $suppress ) {
				$this->suppress_errors = $suppress;
			}
			return $previous;
		}
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function get_current_blog_id(): int { return $GLOBALS['dme_test_blog_id']; }
	function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_kses_post( mixed $value ): string { return (string) $value; }
	function esc_url_raw( mixed $value ): string { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
	function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function wp_cache_delete(): bool { return true; }
	function clean_term_cache(): void {}
	function wp_term_is_shared(): bool { return false; }
	function metadata_exists( string $type, int $term_id, string $key ): bool { return array() !== get_term_meta( $term_id, $key, false ); }
	function add_filter( string $name, callable $callback ): void { $GLOBALS['dme_test_filters'][ $name ][] = $callback; }
	function add_action( string $name, callable $callback ): void { add_filter( $name, $callback ); }
	function remove_all_filters( string $name ): void { unset( $GLOBALS['dme_test_filters'][ $name ] ); }
	function apply_filters( string $name, mixed $value, mixed ...$args ): mixed {
		foreach ( $GLOBALS['dme_test_filters'][ $name ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
	function do_action( string $name, mixed ...$args ): void {
		$GLOBALS['dme_test_actions'][ $name ][] = $args;
		foreach ( $GLOBALS['dme_test_filters'][ $name ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
	function wp_die( mixed $message = '' ): never { throw new \RuntimeException( (string) $message ); }

	function dme_test_table(): string { return '`' . $GLOBALS['dme_test_table'] . '`'; }
	function dme_test_meta_table(): string { return '`' . $GLOBALS['dme_test_table'] . '_meta`'; }
	function get_term( int $term_id, string $taxonomy ): WP_Term|false {
		global $wpdb;
		$statement = $wpdb->dbh->prepare( 'SELECT term_id, name, description FROM ' . dme_test_table() . ' WHERE term_id = ?' );
		$statement->execute( array( $term_id ) );
		$row = $statement->fetch( \PDO::FETCH_ASSOC );
		return $row ? new WP_Term( (int) $row['term_id'], (string) $row['name'], (string) $row['description'] ) : false;
	}
	function get_term_meta( int $term_id, string $key = '', bool $single = false ): mixed {
		global $wpdb;
		$statement = $wpdb->dbh->prepare( 'SELECT meta_value FROM ' . dme_test_meta_table() . ' WHERE term_id = ? AND meta_key = ? ORDER BY meta_id' );
		$statement->execute( array( $term_id, $key ) );
		$values = $statement->fetchAll( \PDO::FETCH_COLUMN );
		return $single ? ( $values[0] ?? '' ) : $values;
	}
	function update_term_meta( int $term_id, string $key, mixed $value ): int|bool|WP_Error {
		global $wpdb;
		$check = apply_filters( 'update_term_metadata', null, $term_id, $key, $value, '' );
		if ( null !== $check ) {
			return (bool) $check;
		}
		$existing = get_term_meta( $term_id, $key, false );
		if ( empty( $existing ) ) {
			$statement = $wpdb->dbh->prepare( 'INSERT INTO ' . dme_test_meta_table() . ' (term_id, meta_key, meta_value) VALUES (?, ?, ?)' );
			$statement->execute( array( $term_id, $key, (string) $value ) );
			return (int) $wpdb->dbh->lastInsertId();
		}
		if ( 1 === count( $existing ) && (string) $existing[0] === (string) $value ) {
			return false;
		}
		$statement = $wpdb->dbh->prepare( 'UPDATE ' . dme_test_meta_table() . ' SET meta_value = ? WHERE term_id = ? AND meta_key = ?' );
		$statement->execute( array( (string) $value, $term_id, $key ) );
		return true;
	}
	function delete_term_meta( int $term_id, string $key ): bool {
		global $wpdb;
		$statement = $wpdb->dbh->prepare( 'DELETE FROM ' . dme_test_meta_table() . ' WHERE term_id = ? AND meta_key = ?' );
		$statement->execute( array( $term_id, $key ) );
		return $statement->rowCount() > 0;
	}
	function wp_update_term( int $term_id, string $taxonomy, array $args ): array|WP_Error {
		global $wpdb;
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term ) {
			return new WP_Error( 'invalid_term', 'Invalid term.' );
		}
		$args['parent'] = apply_filters( 'wp_update_term_parent', $args['parent'] ?? 0, $term_id, $taxonomy );
		$data           = apply_filters(
			'wp_update_term_data',
			array(
				'name'        => $args['name'] ?? $term->name,
				'description' => $args['description'] ?? $term->description,
			),
			$term_id,
			$taxonomy,
			$args
		);
		$statement      = $wpdb->dbh->prepare( 'UPDATE ' . dme_test_table() . ' SET name = ?, description = ? WHERE term_id = ?' );
		$statement->execute( array( $data['name'], $data['description'], $term_id ) );
		do_action( 'saved_' . $taxonomy, $term_id, $term_id, true, $args );
		return array( 'term_id' => $term_id, 'term_taxonomy_id' => $term_id );
	}
}

namespace DataMachineEvents\Core {
	class Venue_Taxonomy {
		public static array $meta_fields = array(
			'address' => '_venue_address', 'city' => '_venue_city', 'state' => '_venue_state', 'zip' => '_venue_zip',
			'country' => '_venue_country', 'phone' => '_venue_phone', 'website' => '_venue_website',
			'capacity' => '_venue_capacity', 'coordinates' => '_venue_coordinates', 'timezone' => '_venue_timezone',
		);
		public static function geocode_address(): ?string { return null; }
		public static function get_venue_data(): array { return array(); }
	}
	class GeoNamesService {
		public static function isConfigured(): bool { return false; }
		public static function getTimezoneFromCoordinates(): ?string { return null; }
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/inc/Core/VenueProfileMutations.php';

	use DataMachineEvents\Core\VenueProfileMutations;

	add_filter( 'wp_update_term_parent', array( VenueProfileMutations::class, 'guardNativeTermEdit' ) );
	add_filter( 'wp_update_term_data', array( VenueProfileMutations::class, 'serializeNativeTermEdit' ) );
	add_action( 'saved_venue', array( VenueProfileMutations::class, 'endNativeTermEdit' ) );

	class DmeCommitUncertain extends VenueProfileMutations {
		protected static function query( string $sql ): int|bool {
			if ( 'COMMIT' === $sql ) {
				global $wpdb;
				$wpdb->close();
				return false;
			}
			return parent::query( $sql );
		}
	}

	class DmeRollbackUncertain extends VenueProfileMutations {
		protected static function query( string $sql ): int|bool {
			if ( 'ROLLBACK' === $sql ) {
				global $wpdb;
				$wpdb->close();
				return false;
			}
			return parent::query( $sql );
		}
	}

	function dme_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}
	function dme_stage( string $stage ): void {
		fwrite( STDOUT, 'STAGE: ' . $stage . "\n" );
		fflush( STDOUT );
	}
	function dme_lock( \PDO $connection, string $operation, string $key ): int {
		$sql = 'GET_LOCK' === $operation ? 'SELECT GET_LOCK(?, 0)' : 'SELECT RELEASE_LOCK(?)';
		$statement = $connection->prepare( $sql );
		$statement->execute( array( $key ) );
		return (int) $statement->fetchColumn();
	}
	if ( 'child' === ( $argv[1] ?? '' ) ) {
		$term_id                          = (int) $argv[2];
		$GLOBALS['dme_test_table']         = (string) $argv[3];
		$result_file                       = (string) $argv[4];
		$ready_file                        = (string) $argv[5];
		$GLOBALS['wpdb']                   = new DmeTestWpdb();
		file_put_contents( $ready_file, 'ready' );
		$result = VenueProfileMutations::updateSystem( $term_id, array( 'phone' => 'stale-ingestion' ), VenueProfileMutations::STRATEGY_FILL_EMPTY );
		file_put_contents( $result_file, json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result ) );
		exit( is_wp_error( $result ) ? 1 : 0 );
	}

	$wpdb = new DmeTestWpdb();
	$GLOBALS['wpdb'] = $wpdb;
	$table      = dme_test_table();
	$meta_table = dme_test_meta_table();
	$exit_code = 0;
	$result_file = null;
	$ready_file = null;
	try {
		dme_stage( 'fixtures' );
		dme_assert( false !== $wpdb->query( "CREATE TABLE {$table} (term_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, description LONGTEXT NOT NULL) ENGINE=InnoDB" ), 'Could not create term fixture table.' );
		dme_assert( false !== $wpdb->query( "CREATE TABLE {$meta_table} (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255), meta_value LONGTEXT, INDEX term_key (term_id, meta_key)) ENGINE=InnoDB" ), 'Could not create metadata fixture table.' );
		dme_assert( false !== $wpdb->query( "INSERT INTO {$table} (name, description) VALUES ('Concurrency Venue', '')" ), 'Could not insert venue fixture.' );
		$term_id = (int) $wpdb->dbh->lastInsertId();

		$owner = new \PDO( $dsn, $GLOBALS['dme_test_user'], $GLOBALS['dme_test_password'], array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
		$lock_key = VenueProfileMutations::lockName( $term_id );
		dme_assert( 1 === dme_lock( $owner, 'GET_LOCK', $lock_key ), 'Owner did not acquire the venue lock.' );
		dme_stage( 'race-waiter' );
		$result_file = tempnam( sys_get_temp_dir(), 'dme-venue-mysql-' );
		$ready_file  = $result_file . '.ready';
		$process = proc_open(
			array( PHP_BINARY, __FILE__, 'child', (string) $term_id, $GLOBALS['dme_test_table'], $result_file, $ready_file ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		dme_assert( is_resource( $process ), 'Could not start fill-empty writer process.' );
		fclose( $pipes[0] );
		$deadline = microtime( true ) + 2;
		while ( ! file_exists( $ready_file ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		dme_assert( file_exists( $ready_file ), 'Fill-empty writer did not reach the mutation call.' );
		usleep( 100000 );
		$statement = $wpdb->dbh->prepare( "INSERT INTO {$meta_table} (term_id, meta_key, meta_value) VALUES (?, '_venue_phone', 'operator-value')" );
		$statement->execute( array( $term_id ) );
		dme_assert( 1 === dme_lock( $owner, 'RELEASE_LOCK', $lock_key ), 'Owner did not release the venue lock.' );
		$process_deadline = microtime( true ) + 15;
		do {
			$process_status = proc_get_status( $process );
			if ( ! $process_status['running'] ) {
				break;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $process_deadline );
		if ( $process_status['running'] ) {
			proc_terminate( $process );
		}
		$child_stdout = stream_get_contents( $pipes[1] );
		$child_stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$close_status = proc_close( $process );
		$child_status = -1 !== $process_status['exitcode'] ? $process_status['exitcode'] : $close_status;
		$child = json_decode( (string) file_get_contents( $result_file ), true );
		dme_assert( ! $process_status['running'], 'Fill-empty writer exceeded its 15-second completion deadline.' );
		dme_assert( 0 === $child_status && ! isset( $child['error'] ), 'Fill-empty writer failed: ' . trim( $child_stdout . ' ' . $child_stderr ) );
		dme_assert( 'operator-value' === get_term_meta( $term_id, '_venue_phone', true ), 'Fill-empty writer overwrote the operator value.' );
		dme_assert( ! in_array( 'phone', $child['updated_fields'], true ), 'Waiting fill-empty writer did not recheck after locking.' );

		dme_stage( 'duplicate-canonicalization' );
		$statement = $wpdb->dbh->prepare( "INSERT INTO {$meta_table} (term_id, meta_key, meta_value) VALUES (?, '_venue_phone', 'stale-duplicate')" );
		$statement->execute( array( $term_id ) );
		$statement = null;
		$result = VenueProfileMutations::updateSystem( $term_id, array( 'phone' => 'operator-value' ) );
		dme_assert( ! is_wp_error( $result ), 'Duplicate canonicalization failed.' );
		dme_assert( array( 'operator-value', 'operator-value' ) === get_term_meta( $term_id, '_venue_phone', false ), 'First-row-equal duplicate remained stale.' );

		dme_stage( 'transaction-ordering' );
		dme_assert( false !== $wpdb->query( 'START TRANSACTION' ), 'Could not start ordering-test transaction.' );
		dme_assert( $term_id === (int) $wpdb->get_var( "SELECT term_id FROM {$table} WHERE term_id = {$term_id} FOR UPDATE" ), 'Could not establish ordering-test row lock.' );
		$result = VenueProfileMutations::updateSystem( $term_id, array( 'website' => 'https://blocked.example' ) );
		$result_code = is_wp_error( $result ) ? $result->get_error_code() : 'success';
		dme_assert( 'venue_transaction_unsupported' === $result_code, 'Existing transaction returned ' . $result_code . ' instead of venue_transaction_unsupported.' );
		$wpdb->query( 'ROLLBACK' );

		dme_stage( 'reentrancy' );
		$nested = null;
		add_filter( 'update_term_metadata', static function ( $check, $object_id, $meta_key ) use ( $term_id, &$nested ) {
			if ( $term_id === (int) $object_id && '_venue_website' === $meta_key && null === $nested ) {
				$nested = VenueProfileMutations::updateSystem( $term_id, array( 'capacity' => '500' ) );
			}
			return $check;
		} );
		$result = VenueProfileMutations::updateSystem( $term_id, array( 'website' => 'https://venue.example' ) );
		remove_all_filters( 'update_term_metadata' );
		dme_assert( ! is_wp_error( $result ) && is_wp_error( $nested ) && 'venue_mutation_reentrant' === $nested->get_error_code(), 'Same-venue reentrancy was not rejected.' );

		dme_stage( 'native-term-serialization' );
		$original_name = get_term( $term_id, 'venue' )->name;
		dme_assert( 1 === dme_lock( $owner, 'GET_LOCK', $lock_key ), 'Owner could not acquire the native edit lock.' );
		add_filter( 'data_machine_events_venue_lock_timeout', static fn() => 0 );
		$rejected = false;
		try {
			wp_update_term( $term_id, 'venue', array( 'name' => 'Should Not Persist' ) );
		} catch ( \RuntimeException ) {
			$rejected = true;
		}
		remove_all_filters( 'data_machine_events_venue_lock_timeout' );
		dme_assert( $rejected, 'Native venue edit did not fail closed when serialization was unavailable.' );
		dme_assert( $original_name === get_term( $term_id, 'venue' )->name, 'Rejected native venue edit changed the term.' );
		dme_assert( 1 === dme_lock( $owner, 'RELEASE_LOCK', $lock_key ), 'Owner could not release the native edit lock.' );

		$result = wp_update_term( $term_id, 'venue', array( 'name' => 'Native Lock Persisted' ) );
		dme_assert( ! is_wp_error( $result ), 'Serialized native venue edit failed.' );
		dme_assert( 'Native Lock Persisted' === get_term( $term_id, 'venue' )->name, 'Serialized native venue edit did not persist.' );
		dme_assert( 1 === dme_lock( $owner, 'GET_LOCK', $lock_key ), 'Successful native edit retained its advisory lock.' );
		dme_assert( 1 === dme_lock( $owner, 'RELEASE_LOCK', $lock_key ), 'Observer could not release the post-save lock.' );

		dme_stage( 'commit-uncertainty' );
		$result = DmeCommitUncertain::updateSystem( $term_id, array( 'capacity' => '500' ) );
		dme_assert( is_wp_error( $result ) && 'venue_commit_uncertain' === $result->get_error_code(), 'Uncertain commit was not surfaced.' );
		dme_assert( true === $result->get_error_data()['connection_closed'] && true === $result->get_error_data()['connection_recovered'], 'Uncertain commit did not quarantine and recover wpdb.' );
		dme_assert( 1 === dme_lock( $owner, 'GET_LOCK', VenueProfileMutations::lockName( $term_id ) ), 'Quarantined commit session retained its advisory lock.' );
		dme_lock( $owner, 'RELEASE_LOCK', VenueProfileMutations::lockName( $term_id ) );

		dme_stage( 'rollback-uncertainty' );
		add_filter( 'update_term_metadata', static fn() => false );
		$result = DmeRollbackUncertain::updateSystem( $term_id, array( 'capacity' => '600' ) );
		remove_all_filters( 'update_term_metadata' );
		dme_assert( is_wp_error( $result ) && 'venue_rollback_uncertain' === $result->get_error_code(), 'Uncertain rollback was not surfaced.' );
		dme_assert( true === $result->get_error_data()['connection_closed'] && true === $result->get_error_data()['connection_recovered'], 'Uncertain rollback did not quarantine and recover wpdb.' );

		dme_stage( 'multisite-contention' );
		$first_lock = VenueProfileMutations::lockName( $term_id );
		$first_revision = VenueProfileMutations::read( $term_id )['revision'];
		$GLOBALS['dme_test_blog_id'] = 2;
		$wpdb->prefix = 'wp_2_';
		$second_lock = VenueProfileMutations::lockName( $term_id );
		$second_revision = VenueProfileMutations::read( $term_id )['revision'];
		dme_assert( $first_lock !== $second_lock, 'Multisite-equivalent term generated the same lock key.' );
		dme_assert( $first_revision !== $second_revision, 'Equivalent multisite values generated the same revision.' );
		$waiter = new \PDO( $dsn, $GLOBALS['dme_test_user'], $GLOBALS['dme_test_password'], array( \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ) );
		dme_assert( 1 === dme_lock( $owner, 'GET_LOCK', $first_lock ), 'Could not reacquire site-one lock.' );
		dme_assert( 1 === dme_lock( $waiter, 'GET_LOCK', $second_lock ), 'Site-two lock incorrectly contended with site one.' );
		dme_assert( 0 === dme_lock( $waiter, 'GET_LOCK', $first_lock ), 'Equivalent site-one lock did not contend.' );
		dme_lock( $waiter, 'RELEASE_LOCK', $second_lock );
		dme_lock( $owner, 'RELEASE_LOCK', $first_lock );

		dme_stage( 'complete' );
		fwrite( STDOUT, "PASS: actual venue mutation class passed race, native serialization, ordering, reentrancy, uncertainty, and multisite checks.\n" );
	} catch ( \Throwable $throwable ) {
		fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
		$exit_code = 1;
	} finally {
		if ( is_string( $result_file ) && file_exists( $result_file ) ) {
			unlink( $result_file );
		}
		if ( is_string( $ready_file ) && file_exists( $ready_file ) ) {
			unlink( $ready_file );
		}
		if ( isset( $wpdb ) && $wpdb->dbh ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . dme_test_meta_table() );
			$wpdb->query( 'DROP TABLE IF EXISTS ' . dme_test_table() );
		}
	}
	exit( $exit_code );
}
