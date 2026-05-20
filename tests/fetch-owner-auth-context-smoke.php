<?php
/**
 * Pure-PHP smoke test for fetch owner auth context.
 *
 * Run with: php tests/fetch-owner-auth-context-smoke.php
 *
 * @package DataMachine\Tests
 */

$root = dirname( __DIR__ );
$file = file_get_contents( $root . '/inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';

$failed = 0;
$total  = 0;

function assert_fetch_owner_auth_context( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}\n";
}

echo "=== fetch-owner-auth-context-smoke ===\n";

assert_fetch_owner_auth_context(
	'FetchStep reads owner user_id from engine job context',
	str_contains( $file, "\$owner_user_id                    = (int) ( \$job_context['user_id'] ?? 0 );" )
);

assert_fetch_owner_auth_context(
	'FetchStep passes user_id into handler settings',
	str_contains( $file, "\$handler_settings['user_id'] = \$owner_user_id;" )
);

assert_fetch_owner_auth_context(
	'FetchStep passes agent_id into handler settings',
	str_contains( $file, "\$handler_settings['agent_id'] = \$agent_id;" )
);

assert_fetch_owner_auth_context(
	'FetchStep passes principal context to auth_ref resolver',
	str_contains( $file, "'user_id'      => \$owner_user_id," ) && str_contains( $file, "'agent_id'     => \$agent_id," )
);

assert_fetch_owner_auth_context(
	'FetchStep executes handlers as owner user',
	str_contains( $file, 'private function execute_handler_as_owner' ) && str_contains( $file, 'wp_set_current_user( $owner_user_id );' )
);

assert_fetch_owner_auth_context(
	'FetchStep restores previous current user',
	str_contains( $file, 'wp_set_current_user( $previous_user_id );' )
);

if ( $failed > 0 ) {
	echo "\nfetch-owner-auth-context-smoke failed: {$failed}/{$total} assertions failed.\n";
	exit( 1 );
}

echo "\nfetch-owner-auth-context-smoke passed: {$total} assertions.\n";
