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
$task    = (string) file_get_contents( $root . '/inc/Engine/AI/System/Tasks/ImageGenerationTask.php' );
$tool    = (string) file_get_contents( $root . '/inc/Engine/AI/Tools/Global/ImageGeneration.php' );
$cli     = (string) file_get_contents( $root . '/inc/Cli/Commands/ImageCommand.php' );
$deleted_adapter_path = $root . '/inc/Engine/AI/WpAiClient' . 'Adapter.php';
$deleted_adapter_name = 'WpAiClient' . 'Adapter';
$deleted_agents_wrapper_path = $root . '/agents-api/inc/AI/WpAiClient.php';

$assert( ! is_file( $deleted_adapter_path ), 'Data Machine provider adapter file is deleted' );
$assert( ! is_file( $deleted_agents_wrapper_path ), 'Agents API low-level wp-ai-client execution wrapper is deleted' );
$assert( ! str_contains( $ability, $deleted_adapter_name ), 'image ability does not reference a Data Machine adapter' );
$assert( str_contains( $ability, 'wp_ai_client_prompt( $prompt )' ), 'image ability calls wp_ai_client_prompt directly' );
$assert( ! str_contains( $ability, 'HttpClient::post' ), 'image ability does not start direct HTTP predictions' );
$assert( ! str_contains( $ability, 'api.replicate.com' ), 'image ability has no Replicate API endpoint' );
$assert( ! str_contains( $ability, 'prediction_id' ), 'image ability no longer schedules prediction ids' );
$assert( str_contains( $ability, '->generate_image()' ), 'image ability uses the public generate_image API' );
$assert( str_contains( $ability, '->is_supported_for_image_generation()' ), 'image ability checks image-generation support' );
$assert( str_contains( $ability, 'FileTypeEnum::remote()' ), 'image ability requests remote generated files when supported' );
$assert( str_contains( $ability, '->as_output_media_aspect_ratio( $aspect_ratio )' ), 'image ability uses core aspect-ratio builder directly' );
$assert( ! str_contains( $ability, 'MediaOrientationEnum::portrait()' ), 'image ability does not recreate aspect-ratio orientation mapping' );
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
