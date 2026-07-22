<?php
/** Autoload smoke test for the jobs liveness command dependencies. */

define( 'ABSPATH', __DIR__ . '/' );

class WP_CLI_Command {}

require_once dirname( __DIR__ ) . '/vendor/composer/ClassLoader.php';

$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4( 'DataMachine\\', dirname( __DIR__ ) . '/inc/' );
$loader->register();

use DataMachine\Cli\Commands\JobsCommand;
use DataMachine\Cli\JobLivenessClassifier;

$failures = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$label}\n";
};

$assert( 'classifier autoloads from its declared namespace', class_exists( JobLivenessClassifier::class ) );
$assert( 'jobs command autoloads with the classifier import', class_exists( JobsCommand::class ) );
$assert( 'real jobs command can be instantiated', new JobsCommand() instanceof JobsCommand );

exit( $failures > 0 ? 1 : 0 );
