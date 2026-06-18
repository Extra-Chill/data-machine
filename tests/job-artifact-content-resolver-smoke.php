<?php
/**
 * Smoke tests for verified job artifact content hydration.
 *
 * Run with: php tests/job-artifact-content-resolver-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$filters = $GLOBALS['datamachine_test_filters'][ $hook ] ?? array();
		foreach ( $filters as $filter ) {
			$value = $filter( $value, ...$args );
		}
		return $value;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/datamachine-job-artifact-content-resolver-smoke';
		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.test/uploads',
		);
	}
}

use DataMachine\Core\JobArtifacts;

$failures = array();
$passes   = 0;

$assert_true = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	$failures[] = $label;
};

$upload_dir = wp_upload_dir();
$base_dir   = (string) $upload_dir['basedir'];
$artifact_dir = trailingslashit( $base_dir ) . 'datamachine-artifacts/jobs/77';
if ( ! is_dir( $artifact_dir ) ) {
	mkdir( $artifact_dir, 0775, true );
}

$content       = "{\"ok\":true}\n";
$relative_path = 'datamachine-artifacts/jobs/77/result.json';
$file_path     = trailingslashit( $base_dir ) . $relative_path;
file_put_contents( $file_path, $content );

$artifact_ref = 'datamachine://jobs/77/artifacts/result';
$engine_data  = array(
	'artifact_files' => array(
		'result' => array(
			'artifact_ref'  => $artifact_ref,
			'type'          => 'result',
			'sha256'        => hash( 'sha256', $content ),
			'bytes'         => strlen( $content ),
			'relative_path' => $relative_path,
			'local_debug'   => array(
				'path' => '/private/internal/path/result.json',
				'url'  => 'https://internal.test/result.json',
			),
		),
	),
);

$artifacts = new JobArtifacts();
$hydrated  = $artifacts->hydrate_artifact_ref( $artifact_ref, $engine_data );

$assert_true( true === ( $hydrated['success'] ?? false ), 'hydrates artifact content from uploads-relative storage' );
$assert_true( $content === ( $hydrated['content'] ?? null ), 'returns exact content bytes' );
$assert_true( strlen( $content ) === ( $hydrated['bytes'] ?? null ), 'returns verified byte count' );
$assert_true( hash( 'sha256', $content ) === ( $hydrated['sha256'] ?? null ), 'returns verified sha256' );
$assert_true( true === ( $hydrated['verified'] ?? false ), 'marks content as verified' );
$assert_true( ! isset( $hydrated['artifact']['local_debug'] ), 'does not expose local_debug metadata' );

$streamed = '';
$stream_result = $artifacts->stream_artifact_ref(
	$artifact_ref,
	static function ( string $chunk ) use ( &$streamed ): void {
		$streamed .= $chunk;
	},
	$engine_data
);
$assert_true( true === ( $stream_result['success'] ?? false ) && $content === $streamed, 'streams verified content through callback' );
$assert_true( ! isset( $stream_result['content'] ), 'stream result omits hydrated content copy' );

$bad_hash = $engine_data;
$bad_hash['artifact_files']['result']['sha256'] = str_repeat( '0', 64 );
$hash_result = $artifacts->hydrate_artifact_ref( $artifact_ref, $bad_hash );
$assert_true( false === ( $hash_result['success'] ?? true ) && str_contains( (string) ( $hash_result['error'] ?? '' ), 'sha256' ), 'rejects sha256 mismatches' );

$bad_bytes = $engine_data;
$bad_bytes['artifact_files']['result']['bytes'] = strlen( $content ) + 1;
$bytes_result = $artifacts->hydrate_artifact_ref( $artifact_ref, $bad_bytes );
$assert_true( false === ( $bytes_result['success'] ?? true ) && str_contains( (string) ( $bytes_result['error'] ?? '' ), 'byte count' ), 'rejects byte count mismatches' );

$without_storage = $engine_data;
$without_storage['artifact_files']['result']['relative_path'] = '../outside.json';
$missing_result = $artifacts->hydrate_artifact_ref( $artifact_ref, $without_storage );
$assert_true( false === ( $missing_result['success'] ?? true ), 'rejects traversal-shaped storage paths' );

$filtered_content = "filter-storage\n";
$filter_callback = static function ( $value, array $args ) use ( $artifact_ref, $filtered_content ) {
	return $artifact_ref === ( $args['artifact_ref'] ?? '' ) ? $filtered_content : $value;
};
$GLOBALS['datamachine_test_filters']['datamachine_job_artifact_ref_content'] = array( $filter_callback );
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'datamachine_job_artifact_ref_content', $filter_callback, 10, 2 );
}
$filter_engine_data = array(
	'artifact_files' => array(
		'filtered' => array(
			'artifact_ref' => $artifact_ref,
			'type'         => 'filtered',
			'sha256'       => hash( 'sha256', $filtered_content ),
			'bytes'        => strlen( $filtered_content ),
		),
	),
);
$filter_result = $artifacts->hydrate_artifact_ref( $artifact_ref, $filter_engine_data );
$assert_true( true === ( $filter_result['success'] ?? false ) && $filtered_content === ( $filter_result['content'] ?? null ), 'filter-backed storage can hydrate without paths or URLs' );

$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/HydrateJobArtifactAbility.php' ) ?: '';
$cli_source     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$bootstrap      = file_get_contents( __DIR__ . '/../data-machine.php' ) ?: '';
$assert_true( str_contains( $ability_source, "wp_register_ability(\n\t\t\t\t'datamachine/hydrate-job-artifact'" ), 'registers public hydrate-job-artifact ability' );
$assert_true( str_contains( $ability_source, 'content_base64' ), 'ability returns JSON-safe content payloads' );
$assert_true( str_contains( $bootstrap, 'new \\DataMachine\\Abilities\\Job\\HydrateJobArtifactAbility();' ), 'plugin bootstrap registers hydrate job artifact ability' );
$assert_true( str_contains( $cli_source, '@subcommand artifact-content' ), 'jobs CLI exposes artifact-content command' );

if ( $failures ) {
	echo "=== job-artifact-content-resolver-smoke: " . count( $failures ) . " FAILURE(S) ===\n";
	foreach ( $failures as $failure ) {
		echo "  [FAIL] {$failure}\n";
	}
	exit( 1 );
}

echo "=== job-artifact-content-resolver-smoke: ALL PASS ({$passes} assertions) ===\n";
