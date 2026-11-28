<?php
/** Read-only native ability checks: wp --user=ID eval-file tests/email-mailbox-runtime-smoke.php. */

defined( 'ABSPATH' ) || exit;

$root = dirname( __DIR__ );
require_once $root . '/inc/Abilities/Email/MailboxAbilities.php';
require_once $root . '/inc/Abilities/Email/ForwardEmailAbility.php';
new \DataMachine\Abilities\Email\MailboxAbilities();
new \DataMachine\Abilities\Email\ForwardEmailAbility();

foreach ( array( 'email-mailboxes', 'email-mailbox-connect', 'email-mailbox-grant', 'email-forward' ) as $name ) {
	if ( ! wp_get_ability( 'datamachine/' . $name ) ) {
		throw new RuntimeException( 'Missing native ability: ' . $name );
	}
	WP_CLI::log( 'PASS: native WordPress registers ' . $name );
}

$result = wp_get_ability( 'datamachine/email-mailboxes' )->execute( array() );
if ( is_wp_error( $result ) || empty( $result['success'] ) || ! is_array( $result['mailboxes'] ) ) {
	throw new RuntimeException( 'Authenticated mailbox discovery did not execute.' );
}
foreach ( $result['mailboxes'] as $mailbox ) {
	if ( isset( $mailbox['credentials'] ) || isset( $mailbox['imap_password'] ) || isset( $mailbox['smtp_password'] ) ) {
		throw new RuntimeException( 'Mailbox discovery exposed credentials.' );
	}
}
WP_CLI::log( 'PASS: native mailbox discovery executes without exposing credentials' );

$result = wp_get_ability( 'datamachine/email-mailbox-connect' )->execute( array( 'name' => 'default', 'credentials' => array() ) );
if ( ! is_wp_error( $result ) ) {
	throw new RuntimeException( 'Invalid mailbox configuration was not rejected.' );
}
WP_CLI::log( 'PASS: native schema rejects incomplete mailbox configuration without writes' );
WP_CLI::success( 'Mailbox abilities verified without connection changes or external email.' );
