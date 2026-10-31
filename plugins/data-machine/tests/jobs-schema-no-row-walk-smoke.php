<?php
/**
 * Jobs::create_table() must not ALTER, SELECT, or UPDATE existing job rows.
 *
 * The 2026-09-19 outage was Jobs::backfill_status_reason_column() walking
 * 1.25M rows on every web init. Schema repairs belong to MigrationRunner
 * and operator CLI, not create_table().
 */

$jobs_path = dirname( __DIR__ ) . '/inc/Core/Database/Jobs/Jobs.php';
$jobs      = file_get_contents( $jobs_path );
if ( ! is_string( $jobs ) ) {
	fwrite( STDERR, "Jobs.php unreadable\n" );
	exit( 1 );
}

$failed = 0;

if ( preg_match( '/\n\t(?:public|private|protected)(?: static)? function migrate_/', $jobs ) ) {
	fwrite( STDERR, "FAIL: Jobs.php still defines a migrate_* function\n" );
	++$failed;
} else {
	echo "PASS: Jobs.php has no migrate_* functions\n";
}

if ( str_contains( $jobs, 'function backfill_status_reason_column' ) ) {
	fwrite( STDERR, "FAIL: backfill_status_reason_column is still on Jobs\n" );
	++$failed;
} else {
	echo "PASS: backfill_status_reason_column removed from Jobs\n";
}

if ( preg_match( '/create_table\(\)[^{]*\{.*ALTER TABLE/s', $jobs ) ) {
	fwrite( STDERR, "FAIL: Jobs::create_table still contains ALTER TABLE\n" );
	++$failed;
} else {
	echo "PASS: Jobs::create_table has no ALTER TABLE\n";
}

$runner = dirname( __DIR__ ) . '/inc/Core/Database/Migrations/MigrationRunner.php';
if ( ! is_file( $runner ) ) {
	fwrite( STDERR, "FAIL: MigrationRunner.php missing\n" );
	++$failed;
} else {
	echo "PASS: MigrationRunner.php exists\n";
}

exit( $failed > 0 ? 1 : 0 );
