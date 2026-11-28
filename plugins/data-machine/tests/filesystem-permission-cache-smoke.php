<?php
/**
 * Permission helpers must expose current metadata, including on PHP 8.2.
 *
 * Run with: php tests/filesystem-permission-cache-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Core\FilesRepository\FilesystemHelper;

$permission_fixture = tempnam( sys_get_temp_dir(), 'datamachine-permissions-' );
if ( false === $permission_fixture ) {
	throw new RuntimeException( 'Could not create permission fixture.' );
}

try {
	chmod( $permission_fixture, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Tests native filesystem metadata.
	clearstatcache( true, $permission_fixture );
	if ( 0600 !== ( fileperms( $permission_fixture ) & 0777 ) ) {
		throw new RuntimeException( 'Fixture must prime the restrictive mode in the stat cache.' );
	}

	if ( ! FilesystemHelper::make_group_writable( $permission_fixture ) ) {
		throw new RuntimeException( 'Permission helper failed for an owned file.' );
	}

	// Do not clear the cache here: callers must immediately see the new mode.
	if ( 0060 !== ( fileperms( $permission_fixture ) & 0060 ) ) {
		throw new RuntimeException( 'Permission helper left stale metadata after chmod.' );
	}

	// Independent stat verifies the helper changed the file, not just its cache.
	clearstatcache( true, $permission_fixture );
	if ( 0060 !== ( fileperms( $permission_fixture ) & 0060 ) ) {
		throw new RuntimeException( 'File on disk is not group-readable and writable.' );
	}

	if ( ! FilesystemHelper::make_group_writable( $permission_fixture ) ) {
		throw new RuntimeException( 'Already shared file should remain successful.' );
	}
} finally {
	unlink( $permission_fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes native filesystem fixture.
}

echo "Filesystem permission cache smoke passed.\n";
