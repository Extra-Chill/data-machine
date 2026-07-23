#!/usr/bin/env php
<?php
/**
 * Standalone two-session MySQL advisory-lock contract smoke test.
 *
 * Environment:
 * - DME_MYSQL_TEST_DSN=mysql:host=127.0.0.1;dbname=events_test
 * - DME_MYSQL_TEST_USER
 * - DME_MYSQL_TEST_PASSWORD
 *
 * @package DataMachineEvents\Tests\Integration
 */

$dsn = trim( (string) getenv( 'DME_MYSQL_TEST_DSN' ) );
if ( '' === $dsn ) {
	fwrite( STDOUT, "SKIP: DME_MYSQL_TEST_DSN is unavailable; no MySQL test endpoint was contacted.\n" );
	exit( 0 );
}

if ( ! extension_loaded( 'pdo_mysql' ) ) {
	fwrite( STDERR, "FAIL: DME_MYSQL_TEST_DSN is configured but pdo_mysql is unavailable.\n" );
	exit( 1 );
}

if ( ! preg_match( '/(?:^|;)dbname=([^;]+)/i', $dsn, $matches ) || ! str_contains( strtolower( $matches[1] ), 'test' ) ) {
	fwrite( STDERR, "FAIL: DME_MYSQL_TEST_DSN must name an explicit database containing 'test'; refusing a possible production endpoint.\n" );
	exit( 1 );
}

$user     = (string) getenv( 'DME_MYSQL_TEST_USER' );
$password = (string) getenv( 'DME_MYSQL_TEST_PASSWORD' );
$options  = array(
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
	PDO::ATTR_EMULATE_PREPARES   => false,
);
$owner    = null;
$waiter   = null;
$lock_key = 'dme-smoke:' . bin2hex( random_bytes( 12 ) );
$exit_code = 0;

$query_lock = static function ( PDO $connection, string $key, int $timeout ): int {
	$statement = $connection->prepare( 'SELECT GET_LOCK(?, ?)' );
	$statement->execute( array( $key, $timeout ) );

	return (int) $statement->fetchColumn();
};

$release_lock = static function ( PDO $connection, string $key ): int {
	$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
	$statement->execute( array( $key ) );

	return (int) $statement->fetchColumn();
};

try {
	$owner  = new PDO( $dsn, $user, $password, $options );
	$waiter = new PDO( $dsn, $user, $password, $options );

	if ( 1 !== $query_lock( $owner, $lock_key, 0 ) ) {
		throw new RuntimeException( 'Owner session did not acquire the free lock.' );
	}
	if ( 0 !== $query_lock( $waiter, $lock_key, 0 ) ) {
		throw new RuntimeException( 'Waiter session was not excluded while the owner held the lock.' );
	}
	if ( 1 !== $release_lock( $owner, $lock_key ) ) {
		throw new RuntimeException( 'Owner session did not release its lock.' );
	}
	if ( 1 !== $query_lock( $waiter, $lock_key, 0 ) ) {
		throw new RuntimeException( 'Waiter session did not acquire the lock after release.' );
	}
	if ( 1 !== $release_lock( $waiter, $lock_key ) ) {
		throw new RuntimeException( 'Waiter session did not release its lock.' );
	}

	fwrite( STDOUT, "PASS: two MySQL sessions verified GET_LOCK exclusion, release, and reacquisition.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	foreach ( array( $owner, $waiter ) as $connection ) {
		if ( ! $connection instanceof PDO ) {
			continue;
		}
		try {
			$release_lock( $connection, $lock_key );
		} catch ( Throwable ) {
			// Closing the session also releases any remaining named lock.
		}
	}
}

exit( $exit_code );
