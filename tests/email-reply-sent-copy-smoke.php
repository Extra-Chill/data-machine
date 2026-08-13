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

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = array() ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): array {
		return $this->data;
	}
}

require_once dirname( __DIR__ ) . '/inc/Abilities/Email/EmailAbilities.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/Publish/SendEmailAbility.php';

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

echo "\n[2] Reply ability failure channel\n";
$result = $ability->executeReply(
	array(
		'to'          => 'not-an-email',
		'subject'     => 'Re: Hello',
		'body'        => 'Thanks',
		'in_reply_to' => '<message@example.com>',
	)
);
email_reply_assert( 'invalid recipient returns WP_Error', is_wp_error( $result ) );
email_reply_assert( 'invalid recipient has useful code', 'invalid_email_recipient' === $result->get_error_code() );
email_reply_assert( 'invalid recipient preserves HTTP status', 400 === ( $result->get_error_data()['status'] ?? null ) );

$reflection = new ReflectionClass( DataMachine\Abilities\Publish\SendEmailAbility::class );
$send       = $reflection->newInstanceWithoutConstructor();
$result     = $send->execute(
	array(
		'to'      => 'not-an-email',
		'subject' => 'Hello',
		'body'    => 'World',
	)
);
email_reply_assert( 'send invalid recipient returns WP_Error', is_wp_error( $result ) );
email_reply_assert( 'send invalid recipient has useful code', 'invalid_email_recipient' === $result->get_error_code() );
email_reply_assert( 'send invalid recipient preserves HTTP status', 400 === ( $result->get_error_data()['status'] ?? null ) );

if ( 0 === $failed ) {
	echo "\n=== email-reply-sent-copy-smoke: all {$total} assertions passed ===\n";
	exit( 0 );
}

echo "\n=== email-reply-sent-copy-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
