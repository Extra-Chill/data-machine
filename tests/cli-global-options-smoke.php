<?php
/**
 * CLI option collision and dispatch contracts.
 *
 * Run with: php tests/cli-global-options-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( '1' === getenv( 'DATAMACHINE_CLI_REGISTRATION_BOOT' ) || '1' === getenv( 'DATAMACHINE_CLI_SEMANTICS_BOOT' ) ) {
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

	if ( '1' === getenv( 'DATAMACHINE_CLI_SEMANTICS_BOOT' ) ) {
		function wp_get_ability( string $name ): object {
			return new class {
				public function execute( array $input ): array {
					WP_CLI::line( 'DATAMACHINE_TEST_EMAIL_CONTEXT=' . json_encode( $input['context'] ?? null ) );
					return array( 'success' => true );
				}
			};
		}

		function is_wp_error( mixed $thing ): bool {
			return false;
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/CliServiceProvider.php';
	\DataMachine\Core\Bootstrap\CliServiceProvider::register();

	if ( '1' === getenv( 'DATAMACHINE_CLI_SEMANTICS_BOOT' ) ) {
		$chat_command = ( new ReflectionClass( \DataMachine\Cli\Commands\ChatCommand::class ) )->newInstanceWithoutConstructor();
		$get_owner    = new ReflectionMethod( $chat_command, 'get_user_id' );

		WP_CLI::line( 'DATAMACHINE_TEST_CHAT_OWNER=' . $get_owner->invoke( $chat_command, array( 'owner-user' => '37' ) ) );
		( new \DataMachine\Cli\Commands\EmailCommand() )->send_queued(
			array(),
			array(
				'to'               => 'recipient@example.test',
				'subject'          => 'Test',
				'template-context' => '{"week":"2026-W14"}',
			)
		);
	}
	return;
}

$root = dirname( __DIR__ );

$provider      = (string) file_get_contents( $root . '/inc/Core/Bootstrap/CliServiceProvider.php' );
$sources       = array();
preg_match_all( '/DataMachine\\\\Cli\\\\([A-Za-z\\\\]+)::class/', $provider, $command_classes );
foreach ( $command_classes[1] as $class ) {
	$path = $root . '/inc/Cli/' . str_replace( '\\', '/', $class ) . '.php';
	$sources[ $class ] = (string) file_get_contents( $path );
}
$documentation = (string) file_get_contents( $root . '/docs/core-system/wp-cli.md' )
	. (string) file_get_contents( $root . '/docs/core-system/oauth-handlers.md' );
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


$run = static function ( array $command, array $environment = array() ): array {
	$previous_environment = array();
	foreach ( $environment as $name => $value ) {
		$previous_environment[ $name ] = getenv( $name );
		putenv( "{$name}={$value}" );
	}

	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	foreach ( $previous_environment as $name => $value ) {
		putenv( false === $value ? $name : "{$name}={$value}" );
	}
	if ( ! is_resource( $process ) ) {
		return array( 1, '', 'Failed to start WP-CLI.' );
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array( proc_close( $process ), (string) $stdout, (string) $stderr );
};


list( $wp_help_exit, $wp_help, $wp_help_stderr ) = $run( array( 'wp', '--help' ) );
$assert( 0 === $wp_help_exit, 'WP-CLI global parameter list is available' );
$assert( '' === $wp_help_stderr, 'WP-CLI global parameter lookup emits no errors' );
preg_match_all( '/^  --(?:\[no-\])?([a-z0-9-]+)/m', $wp_help, $global_matches );
$global_options = $global_matches[1];

foreach ( $sources as $command => $source ) {
	preg_match_all( '/\[--([a-z0-9-]+)(?:=<[^>]+>)?\]/', $source, $matches );
	$declared_globals = array_intersect( $global_options, $matches[1] );
	$assert( array() === $declared_globals, "registered {$command} command declares no WP-CLI global options" );
}

$auth   = $sources['Commands\\AuthCommand'];
$chat   = $sources['Commands\\ChatCommand'];
$email  = $sources['Commands\\EmailCommand'];
$memory = $sources['Commands\\MemoryCommand'];

$assert( str_contains( $auth, "\$assoc_args['target-user']" ), 'auth revoke receives --target-user' );
$assert( str_contains( $chat, "\$assoc_args['owner-user']" ), 'chat commands receive --owner-user' );
$assert( str_contains( $chat, "\$assoc_args['session-context']" ), 'chat commands receive --session-context' );
$assert( str_contains( $email, "\$assoc_args['template-context']" ) && str_contains( $email, "\$input['context'] = \$decoded" ), 'queued email maps --template-context to the internal context payload' );
$assert( ! str_contains( $memory, "get_flag_value( \$assoc_args, 'quiet'" ), 'memory compose relies on WP-CLI global quiet handling' );
$assert( str_contains( $documentation, '--target-user=42' ) && str_contains( $documentation, '--owner-user=1' ) && str_contains( $documentation, '--session-context=sidebar' ) && str_contains( $documentation, '--template-context=' ) && str_contains( $documentation, 'wp --quiet datamachine memory compose' ), 'public CLI documentation distinguishes application options from WP-CLI globals' );

$help_commands = array(
	'auth revoke'    => array( '--target-user=<id>' ),
	'chat list'      => array( '--owner-user=<id>', '--session-context=<type>' ),
	'chat get'       => array( '--owner-user=<id>' ),
	'chat create'    => array( '--owner-user=<id>', '--session-context=<type>' ),
	'email send-queued' => array( '--template-context=<json>' ),
	'memory compose' => array( '--agent=<slug>' ),
);


list( $exit_code, $stdout, $stderr ) = $run(
	array( 'wp', '--skip-wordpress', '--require=' . __FILE__, 'help', 'datamachine' ),
	array( 'DATAMACHINE_CLI_REGISTRATION_BOOT' => '1' )
);
$assert( 0 === $exit_code, 'fresh WP-CLI process registers every Data Machine command' );
$assert( ! str_contains( $stderr, 'conflicts with a global argument' ), 'full CommandRegistry registration emits no global-argument conflict warnings' );

foreach ( $help_commands as $command => $expected_options ) {
	list( $exit_code, $stdout, $stderr ) = $run(
		array_merge(
			array( 'wp', '--skip-wordpress', '--require=' . __FILE__, 'help', 'datamachine' ),
			explode( ' ', $command )
		),
		array( 'DATAMACHINE_CLI_REGISTRATION_BOOT' => '1' )
	);

	$assert( 0 === $exit_code, "fresh WP-CLI process registers datamachine {$command}" );
	foreach ( $expected_options as $option ) {
		$assert( str_contains( $stdout, $option ), "datamachine {$command} exposes {$option}" );
	}
}

list( $exit_code, $stdout, $stderr ) = $run(
	array( 'wp', '--skip-wordpress', '--require=' . __FILE__, 'help', 'datamachine' ),
	array( 'DATAMACHINE_CLI_SEMANTICS_BOOT' => '1' )
);
$assert( 0 === $exit_code, 'email option probe runs without WordPress state' );
$assert( str_contains( $stdout, 'DATAMACHINE_TEST_CHAT_OWNER=37' ), '--owner-user resolves as the chat session owner, not the WP-CLI bootstrap user' );
$assert( str_contains( $stdout, 'DATAMACHINE_TEST_EMAIL_CONTEXT={"week":"2026-W14"}' ), '--template-context resolves to the email template context payload' );
$assert( ! str_contains( $stderr, 'conflicts with a global argument' ), 'option-resolution probe emits no global-argument conflict warnings' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " CLI global option assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} CLI global option assertions passed.\n";
