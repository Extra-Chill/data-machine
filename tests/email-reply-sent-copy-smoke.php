<?php
/**
 * Pure-PHP smoke test for recipient-aware email reply Sent copies (#3045).
 *
 * Run with: php tests/email-reply-sent-copy-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failed = 0;
$total  = 0;

function email_reply_assert( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}\n";
}

function apply_filters( string $hook, $value ) {
	if ( 'datamachine_auth_providers' !== $hook ) {
		return $value;
	}

	return array(
		'email_imap' => new class() {
			public function getUser(): string {
				return ' Mailbox@Example.com ';
			}
		},
	);
}

function is_email( $email ) {
	return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

require_once dirname( __DIR__ ) . '/inc/Abilities/Email/EmailAbilities.php';

$reflection = new ReflectionClass( DataMachine\Abilities\Email\EmailAbilities::class );
$ability    = $reflection->newInstanceWithoutConstructor();
$method     = $reflection->getMethod( 'isConfiguredMailboxRecipient' );

echo "\n[1] Configured mailbox recipient detection\n";
email_reply_assert(
	'Cc identity skips the synthetic Sent copy',
	$method->invoke( $ability, array( 'person@example.net' ), array( 'mailbox@example.com' ) )
);
email_reply_assert(
	'To identity skips the synthetic Sent copy',
	$method->invoke( $ability, array( 'MAILBOX@EXAMPLE.COM' ), array() )
);
email_reply_assert(
	'external-only recipients retain the synthetic Sent copy',
	! $method->invoke( $ability, array( 'person@example.net' ), array( 'copy@example.org' ) )
);

if ( 0 === $failed ) {
	echo "\n=== email-reply-sent-copy-smoke: all {$total} assertions passed ===\n";
	exit( 0 );
}

echo "\n=== email-reply-sent-copy-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
