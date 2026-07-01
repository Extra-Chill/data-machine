<?php
/**
 * Smoke tests for generic artifact manifest primitives.
 *
 * Run with: php tests/artifact-manifest-smoke.php
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return (string) $value;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/ArtifactManifest.php';

use DataMachine\Core\ArtifactManifest;

$failures = 0;
$passes   = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	++$failures;
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
};

$content  = "{\"ok\":true}\n";
$manifest = ArtifactManifest::create(
	array(
		'artifact_ref'  => 'datamachine://runs/123/artifacts/result',
		'artifact_type' => 'result_json',
		'content'       => $content,
		'relative_path' => 'datamachine-artifacts/runs/123/result.json',
		'local_debug'   => array(
			'path' => '/private/tmp/result.json',
		),
	)
);

$assert( 'manifest carries portable ref', 'datamachine://runs/123/artifacts/result' === ( $manifest['artifact_ref'] ?? '' ) );
$assert( 'manifest stores content sha256', hash( 'sha256', $content ) === ( $manifest['sha256'] ?? '' ) );
$assert( 'manifest stores byte count', strlen( $content ) === ( $manifest['bytes'] ?? null ) );

$resolved = ArtifactManifest::resolve( 'datamachine://runs/123/artifacts/result', array( 'result' => $manifest ) );
$assert( 'resolve finds manifest by ref', true === ( $resolved['success'] ?? false ) );
$assert( 'resolve omits local debug metadata', ! isset( $resolved['artifact']['local_debug'] ) );

$hydrated = ArtifactManifest::hydrate(
	'datamachine://runs/123/artifacts/result',
	array( 'result' => $manifest ),
	static fn() => $content
);
$assert( 'hydrate returns verified content', true === ( $hydrated['success'] ?? false ) && true === ( $hydrated['verified'] ?? false ) );
$assert( 'hydrate preserves exact bytes', $content === ( $hydrated['content'] ?? null ) );

$bad = ArtifactManifest::hydrate(
	'datamachine://runs/123/artifacts/result',
	array( 'result' => array_merge( $manifest, array( 'sha256' => str_repeat( '0', 64 ) ) ) ),
	static fn() => $content
);
$assert( 'hydrate rejects sha256 mismatch', false === ( $bad['success'] ?? true ) && str_contains( (string) ( $bad['error'] ?? '' ), 'sha256' ) );

$job_artifacts_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/JobArtifacts.php' ) ?: '';
$assert( 'existing job artifact writer uses generic manifest primitive', str_contains( $job_artifacts_source, 'ArtifactManifest::create' ) );
$assert( 'job artifact verification delegates to generic primitive', str_contains( $job_artifacts_source, 'ArtifactManifest::verify' ) );

if ( $failures > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "artifact-manifest-smoke: {$failures} failure(s), {$passes} pass(es).\n" );
	exit( 1 );
}

echo "artifact-manifest-smoke: ALL PASS ({$passes} assertions)\n";
