<?php
/**
 * Dependency-free smoke for the core/DMB media diagnostics boundary.
 *
 * Run with: php tests/media-diagnostics-core-boundary-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$root          = dirname( __DIR__ );
$failures      = array();
$actions       = array();
$registrations = array();
$wp_runtime    = function_exists( 'wp_get_ability' );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $priority, $accepted_args );
		$GLOBALS['media_boundary_actions'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook && ! empty( $GLOBALS['media_boundary_doing_abilities'] );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 'wp_abilities_api_init' === $hook ? (int) ( $GLOBALS['media_boundary_did_abilities'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): object {
		$GLOBALS['media_boundary_registrations'][ $name ] = $args;
		return new stdClass();
	}
}

$GLOBALS['media_boundary_actions']       = &$actions;
$GLOBALS['media_boundary_registrations'] = &$registrations;

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

require_once $root . '/inc/Abilities/AbilityRegistration.php';
require_once $root . '/inc/Abilities/Media/AltTextAbilities.php';
require_once $root . '/inc/Abilities/Media/ImageGenerationAbilities.php';
require_once $root . '/inc/Abilities/Media/ImageTemplateAbilities.php';
require_once $root . '/inc/Abilities/Media/MediaAbilities.php';

new \DataMachine\Abilities\Media\AltTextAbilities();
new \DataMachine\Abilities\Media\ImageGenerationAbilities();
new \DataMachine\Abilities\Media\ImageTemplateAbilities();
new \DataMachine\Abilities\Media\MediaAbilities();

$GLOBALS['media_boundary_doing_abilities'] = true;
$GLOBALS['media_boundary_did_abilities']   = 1;
foreach ( $actions['wp_abilities_api_init'] ?? array() as $callback ) {
	$callback();
}
$GLOBALS['media_boundary_doing_abilities'] = false;

$retained_abilities = array(
	'datamachine/generate-alt-text'       => 'inc/Abilities/Media/AltTextAbilities.php',
	'datamachine/diagnose-alt-text'       => 'inc/Abilities/Media/AltTextAbilities.php',
	'datamachine/generate-image'          => 'inc/Abilities/Media/ImageGenerationAbilities.php',
	'datamachine/render-image-template'   => 'inc/Abilities/Media/ImageTemplateAbilities.php',
	'datamachine/list-image-templates'    => 'inc/Abilities/Media/ImageTemplateAbilities.php',
	'datamachine/upload-media'            => 'inc/Abilities/Media/MediaAbilities.php',
	'datamachine/validate-media'          => 'inc/Abilities/Media/MediaAbilities.php',
	'datamachine/video-metadata'          => 'inc/Abilities/Media/MediaAbilities.php',
);
foreach ( $retained_abilities as $slug => $relative_path ) {
	$registered = isset( $registrations[ $slug ] );
	if ( $wp_runtime ) {
		$source     = (string) file_get_contents( $root . '/' . $relative_path );
		$registered = str_contains( $source, "'" . $slug . "'" );
	}
	$assert( $registered, 'Retained core ability did not register: ' . $slug );
}

$removed_abilities = array(
	'datamachine/diagnose-images',
	'datamachine/optimize-images',
	'datamachine/diagnose-broken-image-references',
);
foreach ( $removed_abilities as $slug ) {
	$registered = isset( $registrations[ $slug ] );
	if ( $wp_runtime ) {
		$provider_source = (string) file_get_contents( $root . '/inc/Core/Bootstrap/AbilityServiceProvider.php' );
		$registered      = str_contains( $provider_source, $slug );
	}
	$assert( ! $registered, 'Transferred ability still has a core registration: ' . $slug );
}

require_once $root . '/inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php';
require_once $root . '/inc/Engine/AI/System/SystemAgentServiceProvider.php';
$provider = ( new ReflectionClass( \DataMachine\Engine\AI\System\SystemAgentServiceProvider::class ) )->newInstanceWithoutConstructor();
$tasks    = $provider->getBuiltInTasks( array() );
$assert( isset( $tasks['alt_text_generation'] ), 'Retained alt text task is not registered by core' );
$assert( isset( $tasks['image_generation'] ), 'Retained image generation task is not registered by core' );
$assert( ! isset( $tasks['image_optimization'] ), 'Transferred image optimization task still has a core owner' );

$retained_files = array(
	'inc/Engine/Tasks/TaskScheduler.php',
	'inc/Core/FilesRepository/FileStorage.php',
	'inc/Core/FilesRepository/ImageValidator.php',
	'inc/Core/FilesRepository/MediaValidator.php',
	'inc/Core/FilesRepository/VideoValidator.php',
	'inc/Core/FilesRepository/VideoMetadata.php',
);
foreach ( $retained_files as $relative_path ) {
	$assert( file_exists( $root . '/' . $relative_path ), 'Retained core primitive is missing: ' . $relative_path );
}

$plugin_source = (string) file_get_contents( $root . '/data-machine.php' );
$assert( str_contains( $plugin_source, 'function datamachine_resolve_system_agent_context(): array' ), 'System-agent context resolver was removed from core' );

$removed_files = array(
	'inc/Abilities/Media/ImageOptimizationAbilities.php',
	'inc/Abilities/Media/BrokenImageReferenceAbilities.php',
	'inc/Engine/AI/System/Tasks/ImageOptimizationTask.php',
);
foreach ( $removed_files as $relative_path ) {
	$assert( ! file_exists( $root . '/' . $relative_path ), 'Transferred implementation remains in core: ' . $relative_path );
}

$extension_namespace = 'DataMachine' . 'Business\\';
$iterator            = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc' ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$source = (string) file_get_contents( $file->getPathname() );
	$assert( ! str_contains( $source, $extension_namespace ), 'Extension namespace leaked into core: ' . $file->getPathname() );
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "Media diagnostics core boundary smoke failed:\n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "Media diagnostics core boundary smoke checks passed.\n";
