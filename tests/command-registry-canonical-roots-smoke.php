<?php
/** Ensure command registration exposes canonical roots only. */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';

$map = \DataMachine\Cli\CommandRegistry::map();
$aliases = array_values(
	array_filter(
		array_keys( $map ),
		static fn( string $command ): bool => in_array( $command, array( 'datamachine flow', 'datamachine agent', 'datamachine setting', 'datamachine job', 'datamachine cycles', 'datamachine pipeline', 'datamachine post', 'datamachine log', 'datamachine pending-action', 'datamachine handler', 'datamachine step-type', 'datamachine processed-item', 'datamachine tracked-item', 'datamachine fetch test', 'datamachine link', 'datamachine block' ), true )
	)
);

sort( $aliases );
if ( array() !== $aliases ) {
	fwrite( STDERR, 'FAIL: registry exposes non-canonical command aliases.' . PHP_EOL );
	exit( 1 );
}
if ( ! isset( $map['datamachine flows'], $map['datamachine agents'] ) ) {
	fwrite( STDERR, 'FAIL: canonical flow and agent roots are missing.' . PHP_EOL );
	exit( 1 );
}

echo "Command registry canonical roots smoke passed.\n";
