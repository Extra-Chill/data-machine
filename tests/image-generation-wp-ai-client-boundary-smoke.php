<?php
/**
 * Pure-PHP smoke test for the image-generation wp-ai-client boundary.
 *
 * Run with: php tests/image-generation-wp-ai-client-boundary-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$assertions = 0;
$failures   = array();

$assert = function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $message;
		echo "FAIL: {$message}\n";
		return;
	}

	echo "PASS: {$message}\n";
};

$root    = dirname( __DIR__ );
$ability = (string) file_get_contents( $root . '/inc/Abilities/Media/ImageGenerationAbilities.php' );
$adapter = (string) file_get_contents( $root . '/inc/Engine/AI/WpAiClientAdapter.php' );
$task    = (string) file_get_contents( $root . '/inc/Engine/AI/System/Tasks/ImageGenerationTask.php' );
$tool    = (string) file_get_contents( $root . '/inc/Engine/AI/Tools/Global/ImageGeneration.php' );
$cli     = (string) file_get_contents( $root . '/inc/Cli/Commands/ImageCommand.php' );

$assert( str_contains( $ability, 'WpAiClientAdapter::generateImage' ), 'image ability dispatches through WpAiClientAdapter::generateImage' );
$assert( ! str_contains( $ability, 'HttpClient::post' ), 'image ability does not start direct HTTP predictions' );
$assert( ! str_contains( $ability, 'api.replicate.com' ), 'image ability has no Replicate API endpoint' );
$assert( ! str_contains( $ability, 'prediction_id' ), 'image ability no longer schedules prediction ids' );
$assert( str_contains( $adapter, "'generate_image'" ), 'wp-ai-client adapter uses the public generate_image API' );
$assert( str_contains( $adapter, "'is_supported_for_image_generation'" ), 'wp-ai-client adapter checks image-generation support' );
$assert( str_contains( $adapter, 'FileTypeEnum::remote()' ), 'wp-ai-client adapter requests remote generated files when supported' );
$assert( str_contains( $adapter, 'MediaOrientationEnum::portrait()' ), 'wp-ai-client adapter maps portrait aspect ratios to orientation' );
$assert( str_contains( $task, 'image_url' ), 'system task consumes generated image URLs' );
$assert( str_contains( $task, 'image_data_uri' ), 'system task consumes inline generated image data' );
$assert( ! str_contains( $task, 'HttpClient::get' ), 'system task no longer polls direct provider HTTP status' );
$assert( ! str_contains( $task, 'api.replicate.com' ), 'system task has no Replicate API endpoint' );
$assert( str_contains( $tool, 'default_provider' ), 'tool settings collect provider id instead of provider API key' );
$assert( ! str_contains( $tool, 'Replicate API Key' ), 'tool settings no longer ask for a Replicate key' );
$assert( str_contains( $cli, '--provider=<provider>' ), 'CLI exposes provider override' );
$assert( ! str_contains( $cli, 'prediction_id' ), 'CLI output no longer exposes prediction ids' );

echo "\n{$assertions} assertions, " . count( $failures ) . " failures\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}
