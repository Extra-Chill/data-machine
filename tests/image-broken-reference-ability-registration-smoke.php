<?php
/**
 * Regression smoke for BrokenImageReferenceAbilities bootstrap registration.
 *
 * Run with: php tests/image-broken-reference-ability-registration-smoke.php
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
	str_contains( $bootstrap, "require_once __DIR__ . '/inc/Abilities/Media/BrokenImageReferenceAbilities.php';" ),
	'data-machine.php loads BrokenImageReferenceAbilities'
);

$assert(
	str_contains( $bootstrap, 'new \\DataMachine\\Abilities\\Media\\BrokenImageReferenceAbilities();' ),
	'data-machine.php instantiates BrokenImageReferenceAbilities during ability bootstrap'
);

$assert(
	str_contains( $test, "'datamachine/diagnose-broken-image-references'" ),
	'AllAbilitiesRegisteredTest expects datamachine/diagnose-broken-image-references'
);

echo "\n{$assertions} assertions, " . count( $failures ) . " failures\n";

if ( ! empty( $failures ) ) {
	exit( 1 );
}
