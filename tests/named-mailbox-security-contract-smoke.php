<?php
/**
 * Source-level security contracts for named mailbox execution paths.
 *
 * @package DataMachine\Tests
 */

$root       = dirname( __DIR__ );
$fetch      = (string) file_get_contents( $root . '/inc/Abilities/Fetch/FetchEmailAbility.php' );
$email      = (string) file_get_contents( $root . '/inc/Abilities/Email/EmailAbilities.php' );
$api        = (string) file_get_contents( $root . '/inc/Api/Email.php' );
$cli        = (string) file_get_contents( $root . '/inc/Cli/Commands/EmailCommand.php' );
$handler    = (string) file_get_contents( $root . '/inc/Core/Steps/Fetch/Handlers/Email/Email.php' );
$queue      = (string) file_get_contents( $root . '/inc/Abilities/Publish/SendEmailQueuedAbility.php' );
$migration  = (string) file_get_contents( $root . '/inc/migrations/email-flow-auth.php' );
$failures   = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$assert( str_contains( $fetch, 'FT_UID | ( $peek ? FT_PEEK : 0 )' ), 'read-only body and attachment fetches derive FT_PEEK flags' );
$assert( substr_count( $fetch, 'imap_fetchbody( $connection' ) === substr_count( $fetch, '$fetch_flags )' ) + substr_count( $fetch, '$flags )' ), 'every IMAP body fetch uses explicit peek-aware flags' );
$assert( str_contains( $email, "array( 'organize', 'delete', 'search' )" ), 'batch move requires organize, delete, and search' );
$assert( str_contains( $email, "array( \$operation, 'search' )" ), 'batch flag requires its mutation operation and search' );
$assert( str_contains( $email, "array( 'delete', 'search' )" ), 'batch delete requires delete and search' );
$assert( str_contains( $email, "\$headers[] = 'From: ' . \$identity" ) && str_contains( $email, "\$headers[] = 'Reply-To: ' . \$identity" ), 'mailto unsubscribe uses the authorized mailbox identity' );
$assert( str_contains( $email, "0 === stripos( \$header, 'From:' )" ), 'synthetic Sent copies suppress duplicate From headers' );
$assert( substr_count( $api, '...self::mailbox_args()' ) >= 12 && str_contains( $api, "'args'                => self::mailbox_args()" ), 'all email REST routes advertise mailbox selectors' );
$assert( str_contains( $api, "'auth_ref' => array(" ) && str_contains( $api, "'mailbox'  => array(" ), 'REST schema declares auth_ref and mailbox' );
$assert( substr_count( $cli, '[--auth-ref=<ref>]' ) >= 14 && substr_count( $cli, '[--mailbox=<name>]' ) >= 14, 'all email CLI commands document both mailbox selectors' );
$assert( str_contains( $handler, "null === \$context->getAgentId() && 0 === get_current_user_id()" ), 'legacy omission compatibility is limited to unscoped system execution' );
$assert( str_contains( $handler, "'legacy_default_auth' => (string) ( \$config['_legacy_default_auth'] ?? '' )" ), 'email handler forwards only the persisted legacy marker' );
$assert( str_contains( $migration, "WHERE agent_id > 0" ) && str_contains( $migration, "! empty( \$email_config['auth_ref'] )" ), 'legacy migration targets persisted agent flows that omitted auth_ref' );
$assert( strpos( $queue, 'verifyMailboxGrant( $payload )' ) < strpos( $queue, "wp_get_ability( 'datamachine/send-email' )" ), 'queue worker verifies signed authorization before ability execution' );
$assert( strpos( $queue, 'currentIssuerAuthorized( $grant )' ) < strpos( $queue, "wp_get_ability( 'datamachine/send-email' )" ), 'queue worker revalidates explicit issuer authority before ability execution' );
$assert( str_contains( $queue, "'token_id'     => (int) \$context['token_id']" ) && str_contains( $queue, "'issuer_type'" ), 'signed queue envelope captures stable non-secret issuer identity' );

if ( $failures ) {
	exit( 1 );
}

echo 'named-mailbox-security-contract-smoke: ok' . PHP_EOL;
