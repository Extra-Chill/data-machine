<?php
/**
 * Smoke test for Data Machine boundary vocabulary.
 *
 * Run with: php tests/boundary-forbidden-names-smoke.php
 *
 * @package DataMachine\Tests
 */

$root     = dirname( __DIR__ );
$failures = array();
$passes   = 0;

echo "boundary-forbidden-names-smoke\n";

function datamachine_boundary_assert( bool $condition, string $label, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  PASS {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "  FAIL {$label}\n";
}

function datamachine_boundary_relative_path( string $root, string $path ): string {
	$relative = substr( $path, strlen( $root ) + 1 );
	return str_replace( DIRECTORY_SEPARATOR, '/', false === $relative ? $path : $relative );
}

function datamachine_boundary_is_excluded_dir( string $relative_path ): bool {
	$excluded_roots = array(
		'.git',
		'.datamachine',
		'.claude',
		'.homeboy-action',
		'build',
		'dist',
		'node_modules',
		'vendor',
	);

	foreach ( $excluded_roots as $excluded_root ) {
		if ( $excluded_root === $relative_path || str_starts_with( $relative_path, $excluded_root . '/' ) ) {
			return true;
		}
	}

	return false;
}

function datamachine_boundary_is_allowed_file( string $relative_path ): bool {
	if ( '.git' === $relative_path ) {
		return true;
	}

	$allowed_files = array(
		// Generated local agent guidance; not part of plugin runtime behavior.
		'AGENTS.md',
		// Generated release history is intentionally not hand-edited.
		'docs/CHANGELOG.md',
		// Developer/release harness config. Runtime code must remain generic.
		'.buildignore',
		'composer.json',
	);

	if ( in_array( $relative_path, $allowed_files, true ) ) {
		return true;
	}

	// CI workflow files and tests are source-control harness config, not runtime logic.
	return str_starts_with( $relative_path, '.github/workflows/' ) || str_starts_with( $relative_path, 'tests/' );
}

$forbidden_patterns = array(
	'wp-site-generator' => '/wp-site-generator/i',
	'wpsg'              => '/\bwpsg\b/i',
	'codebox'           => '/(?:wp[-_ ]?)?codebox/i',
	'homeboy'           => '/homeboy/i',
);

$violations                = array();
$production_inc_violations = array();
$iterator                  = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		function ( SplFileInfo $file ) use ( $root ): bool {
			$relative_path = datamachine_boundary_relative_path( $root, $file->getPathname() );
			return ! $file->isDir() || ! datamachine_boundary_is_excluded_dir( $relative_path );
		}
	)
);

foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
		continue;
	}

	$path          = $file->getPathname();
	$relative_path = datamachine_boundary_relative_path( $root, $path );

	if ( __FILE__ === $path || datamachine_boundary_is_allowed_file( $relative_path ) ) {
		continue;
	}

	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		continue;
	}

	foreach ( $forbidden_patterns as $label => $pattern ) {
		if ( preg_match( $pattern, $contents ) ) {
			$violations[] = "{$relative_path} contains {$label}";
			if ( 'codebox' === $label && str_starts_with( $relative_path, 'inc/' ) ) {
				$production_inc_violations[] = "{$relative_path} contains {$label}";
			}
		}
	}
}

datamachine_boundary_assert( array() === $production_inc_violations, 'production inc files have no Codebox vocabulary', $failures, $passes );
datamachine_boundary_assert( array() === $violations, 'first-party source has no downstream runtime names outside explicit harness/generated allowlists', $failures, $passes );

if ( ! empty( $production_inc_violations ) ) {
	echo "\nProduction inc boundary mentions:\n";
	foreach ( $production_inc_violations as $violation ) {
		echo "  - {$violation}\n";
	}
}

if ( ! empty( $violations ) ) {
	echo "\nForbidden boundary mentions:\n";
	foreach ( $violations as $violation ) {
		echo "  - {$violation}\n";
	}
}

if ( ! empty( $failures ) ) {
	echo "\nBoundary forbidden names smoke failed (" . count( $failures ) . " failure(s)).\n";
	exit( 1 );
}

echo "\nBoundary forbidden names smoke passed ({$passes} assertions).\n";
