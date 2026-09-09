<?php
/**
 * CLI option collision and dispatch contracts.
 *
 * Run with: php tests/cli-global-options-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( '1' === getenv( 'DATAMACHINE_CLI_REGISTRATION_BOOT' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );

	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'DataMachine\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$path = dirname( __DIR__ ) . '/inc/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
	);

	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/CliServiceProvider.php';
	\DataMachine\Core\Bootstrap\CliServiceProvider::register();
	return;
}

$root = dirname( __DIR__ );

$sources = array(
	'auth revoke'        => (string) file_get_contents( $root . '/inc/Cli/Commands/AuthCommand.php' ),
	'chat'               => (string) file_get_contents( $root . '/inc/Cli/Commands/ChatCommand.php' ),
	'email send-queued'  => (string) file_get_contents( $root . '/inc/Cli/Commands/EmailCommand.php' ),
	'memory compose'     => (string) file_get_contents( $root . '/inc/Cli/Commands/MemoryCommand.php' ),
);
$documentation = (string) file_get_contents( $root . '/docs/core-system/wp-cli.md' )
	. (string) file_get_contents( $root . '/docs/core-system/oauth-handlers.md' );
$global_options = array( 'user', 'context', 'quiet' );
$failures       = array();
$passes         = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$run = static function ( array $command ): array {
	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		return array( 1, '', 'Failed to start WP-CLI.' );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array( proc_close( $process ), (string) $stdout, (string) $stderr );
};

foreach ( $sources as $command => $source ) {
	preg_match_all( '/\[--([a-z0-9-]+)(?:=<[^>]+>)?\]/', $source, $matches );
	$declared_globals = array_intersect( $global_options, $matches[1] );
	$assert( array() === $declared_globals, "{$command} declares no WP-CLI global options" );
}

$auth  = $sources['auth revoke'];
$chat  = $sources['chat'];
$email = $sources['email send-queued'];
$memory = $sources['memory compose'];

$assert( str_contains( $auth, "\$assoc_args['target-user']" ), 'auth revoke receives --target-user' );
$assert( str_contains( $chat, "\$assoc_args['owner-user']" ), 'chat commands receive --owner-user' );
$assert( str_contains( $chat, "\$assoc_args['session-context']" ), 'chat commands receive --session-context' );
$assert( str_contains( $email, "\$assoc_args['template-context']" ) && str_contains( $email, "\$input['context'] = \$decoded" ), 'queued email maps --template-context to the internal context payload' );
$assert( ! str_contains( $memory, "get_flag_value( \$assoc_args, 'quiet'" ), 'memory compose relies on WP-CLI global quiet handling' );
$assert( str_contains( $documentation, '--target-user=42' ) && str_contains( $documentation, '--owner-user=1' ) && str_contains( $documentation, '--session-context=sidebar' ), 'public CLI documentation uses renamed domain options' );

$help_commands = array(
	'auth revoke'    => array( '--target-user=<id>' ),
	'chat list'      => array( '--owner-user=<id>', '--session-context=<type>' ),
	'chat get'       => array( '--owner-user=<id>' ),
	'chat create'    => array( '--owner-user=<id>', '--session-context=<type>' ),
	'memory compose' => array( '--agent=<slug>' ),
);

putenv( 'DATAMACHINE_CLI_REGISTRATION_BOOT=1' );
try {
	foreach ( $help_commands as $command => $expected_options ) {
		list( $exit_code, $stdout, $stderr ) = $run(
			array_merge(
				array( 'wp', '--skip-wordpress', '--require=' . __FILE__, 'help', 'datamachine' ),
				explode( ' ', $command )
			)
		);

		$assert( 0 === $exit_code, "fresh WP-CLI process registers datamachine {$command}" );
		foreach ( $expected_options as $option ) {
			$assert( str_contains( $stdout, $option ), "datamachine {$command} exposes {$option}" );
		}
		$assert( ! str_contains( $stderr, 'conflicts with a global argument' ), "fresh WP-CLI registration for {$command} emits no global-argument conflict warnings" );
	}
} finally {
	putenv( 'DATAMACHINE_CLI_REGISTRATION_BOOT' );
}

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " CLI global option assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} CLI global option assertions passed.\n";
