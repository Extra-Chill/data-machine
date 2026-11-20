<?php
/** Native MySQL schema/backfill and plan probe; uses a connection-local temporary table. */
$dsn = getenv( 'DME_MYSQL_TEST_DSN' );
if ( ! $dsn ) {
	echo "SKIP: DME_MYSQL_TEST_DSN unavailable.\n";
	exit( 0 );
}
$db = new PDO( $dsn, getenv( 'DME_MYSQL_TEST_USER' ) ?: '', getenv( 'DME_MYSQL_TEST_PASSWORD' ) ?: '', array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
$db->exec( "CREATE TEMPORARY TABLE dme_effective_end_probe (
	post_id BIGINT UNSIGNED PRIMARY KEY, start_datetime DATETIME NOT NULL,
	end_datetime DATETIME NULL, post_status VARCHAR(20) NOT NULL,
	KEY start_datetime (start_datetime), KEY end_datetime (end_datetime),
	KEY status_start (post_status, start_datetime)
) ENGINE=InnoDB" );
$insert = $db->prepare( 'INSERT INTO dme_effective_end_probe VALUES (?, ?, ?, ?)' );
foreach ( array(
	array( 1, '2099-01-03 12:00:00', null, 'publish' ),
	array( 2, '2099-01-01 12:00:00', null, 'publish' ),
	array( 3, '2099-01-01 12:00:00', '2099-01-03 12:00:00', 'publish' ),
	array( 4, '2099-01-02 11:00:00', '2099-01-02 13:00:00', 'publish' ),
	array( 5, '2099-01-02 11:00:00', '2099-01-02 12:00:00', 'publish' ),
	array( 6, '2099-01-01 11:00:00', '2099-01-01 13:00:00', 'publish' ),
	array( 7, '2099-01-03 12:00:00', null, 'draft' ),
) as $row ) {
	$insert->execute( $row );
}
// Production-sized historical volume, generated without touching persistent data.
$db->exec( 'CREATE TEMPORARY TABLE dme_probe_digits (n INT)' );
$db->exec( 'INSERT INTO dme_probe_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)' );
$db->exec( "INSERT INTO dme_effective_end_probe
	SELECT 100 + a.n + 10*b.n + 100*c.n + 1000*d.n + 10000*e.n + 100000*f.n,
	'2020-01-01 12:00:00', '2020-01-01 14:00:00', 'publish'
	FROM dme_probe_digits a CROSS JOIN dme_probe_digits b CROSS JOIN dme_probe_digits c
	CROSS JOIN dme_probe_digits d CROSS JOIN dme_probe_digits e CROSS JOIN dme_probe_digits f
	WHERE a.n + 10*b.n + 100*c.n + 1000*d.n + 10000*e.n + 100000*f.n < 188926" );
$old = "SELECT post_id FROM dme_effective_end_probe WHERE post_status='publish' AND (start_datetime >= '2099-01-02 12:00:00' OR end_datetime >= '2099-01-02 12:00:00') ORDER BY post_id";
$old_count = "SELECT COUNT(*) FROM dme_effective_end_probe WHERE post_status='publish' AND (start_datetime >= '2099-01-02 12:00:00' OR end_datetime >= '2099-01-02 12:00:00')";
echo 'BEFORE COUNT: ' . json_encode( $db->query( 'EXPLAIN ' . $old_count )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";
$started = microtime( true );
$db->query( $old_count )->fetchColumn();
printf( "BEFORE COUNT seconds: %.6f\n", microtime( true ) - $started );
$db->exec( 'ALTER TABLE dme_effective_end_probe ADD effective_end DATETIME GENERATED ALWAYS AS (COALESCE(end_datetime, start_datetime)) STORED, ADD KEY status_effective_end (post_status, effective_end, post_id)' );
$new = "SELECT post_id FROM dme_effective_end_probe WHERE post_status='publish' AND effective_end >= '2099-01-02 12:00:00' ORDER BY post_id";
$expected = array( 1, 3, 4, 5 );
foreach ( array( $old, $new ) as $sql ) {
	$actual = array_map( 'intval', $db->query( $sql )->fetchAll( PDO::FETCH_COLUMN ) );
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Upcoming predicate/backfill mismatch: ' . json_encode( $actual ) );
	}
}
$new_count = "SELECT COUNT(*) FROM dme_effective_end_probe WHERE post_status='publish' AND effective_end >= '2099-01-02 12:00:00'";
echo 'AFTER COUNT: ' . json_encode( $db->query( 'EXPLAIN ' . $new_count )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";
$started = microtime( true );
$db->query( $new_count )->fetchColumn();
printf( "AFTER COUNT seconds: %.6f\n", microtime( true ) - $started );
$min = "SELECT MIN(effective_end) FROM dme_effective_end_probe WHERE post_status='publish' AND effective_end >= '2099-01-02 12:00:00'";
echo 'TRANSITION: ' . json_encode( $db->query( 'EXPLAIN ' . $min )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";
if ( '2099-01-02 12:00:00' !== $db->query( $min )->fetchColumn() ) {
	throw new RuntimeException( 'Incorrect transition' );
}
$db->exec( "UPDATE dme_effective_end_probe SET end_datetime='2099-01-04 12:00:00' WHERE post_id=1" );
if ( '2099-01-04 12:00:00' !== $db->query( 'SELECT effective_end FROM dme_effective_end_probe WHERE post_id=1' )->fetchColumn() ) {
	throw new RuntimeException( 'Generated column did not track writer' );
}
echo "PASS: legacy backfill, NULL end, ongoing/multiday, inclusive boundary, status, writer maintenance and transition.\n";
