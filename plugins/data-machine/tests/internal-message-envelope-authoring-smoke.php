<?php
/**
 * Smoke test for canonical internal message authoring.
 *
 * Run with: php tests/internal-message-envelope-authoring-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

$production_files = array(
	'inc/Core/Steps/AI/AIStep.php',
	'inc/Engine/AI/RequestInspector.php',
	'inc/Engine/AI/Directives/DirectiveRenderer.php',
	'inc/Engine/AI/System/Tasks/AltTextTask.php',
	'inc/Engine/AI/System/Tasks/MetaDescriptionTask.php',
	'inc/Engine/AI/System/Tasks/DailyMemoryTask.php',
	'inc/Engine/AI/System/Tasks/InternalLinkingTask.php',
	'inc/Abilities/SystemAbilities.php',
	'inc/Abilities/Media/ImageGenerationAbilities.php',
);

$failures   = array();
$assertions = 0;

foreach ( $production_files as $relative_path ) {
	$path    = $root . '/' . $relative_path;
	$content = file_get_contents( $path );

	if ( false === $content ) {
		$failures[] = "Could not read {$relative_path}";
		continue;
	}

	++$assertions;
	if ( preg_match( "/'role'\s*=>\s*'(user|assistant|system)'/", $content ) ) {
		$failures[] = "{$relative_path} still authors a bare runtime message role";
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo "FAIL: {$failure}\n";
	}
	exit( 1 );
}

echo "Internal message envelope authoring smoke passed ({$assertions} assertions).\n";
