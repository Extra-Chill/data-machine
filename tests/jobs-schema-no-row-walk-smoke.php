<?php
/**
 * create_table() schema migrators must not SELECT job rows.
 *
 * The 2026-09-19 outage was Jobs::backfill_status_reason_column() walking
 * 1.25M rows on every web init. ALTER TABLE may stay on that path; row
 * reads belong to MigrationRunner.
 */

$jobs = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Jobs/Jobs.php' );
if ( ! is_string( $jobs ) ) {
	fwrite( STDERR, "Jobs.php unreadable\n" );
	exit( 1 );
}

$names = array(
	'migrate_status_reason_column',
	'migrate_task_type_column',
	'migrate_handler_slug_column',
);

$starts = array();
if ( preg_match_all( '/\n\tprivate static function (migrate_[a-z_]+_column)\(/', $jobs, $matches, PREG_OFFSET_CAPTURE ) ) {
	foreach ( $matches[1] as $hit ) {
		$starts[ $hit[0] ] = $hit[1];
	}
}

$failed = 0;
$keys   = array_keys( $starts );
sort( $keys );

foreach ( $names as $name ) {
	if ( ! isset( $starts[ $name ] ) ) {
		fwrite( STDERR, "FAIL: {$name} not found\n" );
		++$failed;
		continue;
	}
	$from = $starts[ $name ];
	$to   = strlen( $jobs );
	foreach ( $starts as $other => $offset ) {
		if ( $offset > $from && $offset < $to ) {
			$to = $offset;
		}
	}
	$body = substr( $jobs, $from, $to - $from );
	if ( preg_match( '/\$wpdb->get_results\s*\(/', $body ) ) {
		fwrite( STDERR, "FAIL: {$name} still calls \$wpdb->get_results\n" );
		++$failed;
		continue;
	}
	if ( preg_match( '/SELECT\s+job_id/i', $body ) ) {
		fwrite( STDERR, "FAIL: {$name} still SELECTs job_id\n" );
		++$failed;
		continue;
	}
	echo "PASS: {$name} does not walk job rows\n";
}

if ( str_contains( $jobs, 'function backfill_status_reason_column' ) ) {
	fwrite( STDERR, "FAIL: backfill_status_reason_column is still on Jobs\n" );
	++$failed;
} else {
	echo "PASS: backfill_status_reason_column removed from Jobs\n";
}

$runner = dirname( __DIR__ ) . '/inc/Core/Database/Migrations/MigrationRunner.php';
if ( ! is_file( $runner ) ) {
	fwrite( STDERR, "FAIL: MigrationRunner.php missing\n" );
	++$failed;
} else {
	echo "PASS: MigrationRunner.php exists\n";
}

exit( $failed > 0 ? 1 : 0 );
