<?php
/**
 * Regression smoke for ImageOptimizationAbilities bootstrap registration.
 *
 * Run with: php tests/image-optimization-ability-registration-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$assertions = 0;
$failures   = array();

$assert = static function ( bool $condition, string $message ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$root      = dirname( __DIR__ );
$bootstrap = (string) file_get_contents( $root . '/data-machine.php' );
$test      = (string) file_get_contents( $root . '/tests/Unit/Abilities/AllAbilitiesRegisteredTest.php' );

$assert(
	str_contains( $bootstrap, "require_once __DIR__ . '/inc/Abilities/Media/ImageOptimizationAbilities.php';" ),
	'data-machine.php loads ImageOptimizationAbilities'
);

$assert(
	str_contains( $bootstrap, 'new \\DataMachine\\Abilities\\Media\\ImageOptimizationAbilities();' ),
	'data-machine.php instantiates ImageOptimizationAbilities during ability bootstrap'
);

$assert(
	str_contains( $test, "'datamachine/diagnose-images'" ),
	'AllAbilitiesRegisteredTest expects datamachine/diagnose-images'
);

$assert(
	str_contains( $test, "'datamachine/optimize-images'" ),
	'AllAbilitiesRegisteredTest expects datamachine/optimize-images'
);

echo "\n{$assertions} assertions, " . count( $failures ) . " failures\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}
