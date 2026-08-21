<?php
/** Keep only CLI aliases directly consumed by deployed Intelligence. */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/inc/Cli/CommandRegistry.php';

$map = \DataMachine\Cli\CommandRegistry::map();
$expected_aliases = array( 'datamachine flow', 'datamachine agent' );
$aliases = array_values(
	array_filter(
		array_keys( $map ),
		static fn( string $command ): bool => in_array( $command, array( 'datamachine flow', 'datamachine agent', 'datamachine setting', 'datamachine job', 'datamachine cycles', 'datamachine pipeline', 'datamachine post', 'datamachine log', 'datamachine pending-action', 'datamachine handler', 'datamachine step-type', 'datamachine processed-item', 'datamachine tracked-item', 'datamachine fetch test', 'datamachine link', 'datamachine block' ), true )
	)
);

sort( $aliases );
sort( $expected_aliases );
if ( $expected_aliases !== $aliases ) {
	fwrite( STDERR, 'FAIL: registry aliases do not match deployed Intelligence consumers.' . PHP_EOL );
	exit( 1 );
}
if ( $map['datamachine flow'] !== $map['datamachine flows'] || $map['datamachine agent'] !== $map['datamachine agents'] ) {
	fwrite( STDERR, 'FAIL: retained aliases do not target their canonical implementations.' . PHP_EOL );
	exit( 1 );
}

echo "Command registry Intelligence aliases smoke passed.\n";
