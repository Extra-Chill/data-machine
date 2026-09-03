<?php
/**
 * CLI option collision and dispatch contracts.
 *
 * Run with: php tests/cli-global-options-smoke.php
 *
 * @package DataMachine\Tests
 */

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

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " CLI global option assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} CLI global option assertions passed.\n";
