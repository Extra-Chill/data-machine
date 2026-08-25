<?php
/** Ensure command registration exposes canonical roots only. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );

final class WP_CLI {
	/** @var array<string, class-string> */
	public static array $commands = array();

	public static function add_command( string $command, string $class ): void {
		self::$commands[ $command ] = $class;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/CliServiceProvider.php';

\DataMachine\Core\Bootstrap\CliServiceProvider::register();

$map     = WP_CLI::$commands;
$aliases = array_values(
	array_filter(
		array_keys( $map ),
		static fn( string $command ): bool => in_array( $command, array( 'datamachine flow', 'datamachine agent', 'datamachine setting', 'datamachine job', 'datamachine cycles', 'datamachine pipeline', 'datamachine post', 'datamachine log', 'datamachine pending-action', 'datamachine handler', 'datamachine step-type', 'datamachine processed-item', 'datamachine tracked-item', 'datamachine fetch test', 'datamachine link', 'datamachine block' ), true )
	)
);

sort( $aliases );
if ( array() !== $aliases ) {
	fwrite( STDERR, 'FAIL: provider exposes non-canonical command aliases.' . PHP_EOL );
	exit( 1 );
}
if ( 31 !== count( $map ) ) {
	fwrite( STDERR, 'FAIL: provider does not register exactly 31 commands.' . PHP_EOL );
	exit( 1 );
}
if ( ! isset( $map['datamachine flows'], $map['datamachine agents'] ) ) {
	fwrite( STDERR, 'FAIL: canonical flow and agent roots are missing.' . PHP_EOL );
	exit( 1 );
}

$registered = $map;
\DataMachine\Core\Bootstrap\CliServiceProvider::register();
if ( $registered !== WP_CLI::$commands ) {
	fwrite( STDERR, 'FAIL: repeated provider registration changed the command map.' . PHP_EOL );
	exit( 1 );
}

echo "CLI provider canonical roots smoke passed.\n";
