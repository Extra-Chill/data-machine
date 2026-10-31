<?php
/** Run: php tests/send-email-approval-smoke.php */
define( 'DATAMACHINE_RESOLVER_FIXTURE_ONLY', true );
$GLOBALS['email_user'] = 123;
$GLOBALS['email_sent'] = array();
function get_current_user_id() { return $GLOBALS['email_user']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function current_user_can( $cap, ...$args ) { return user_can( get_current_user_id(), $cap ); }
function user_can( $id, $cap, ...$args ) { return in_array( (int) $id, array( 123, 456 ), true ); }
function get_userdata( $id ) { return $id > 0 ? (object) array( 'ID' => $id ) : false; }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function get_bloginfo( $key ) { return 'Test site'; }
function wp_date( $format ) { return '2026-01-01'; }
function wp_salt( $scheme ) { return 'test-salt'; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) ); }
function wp_mail( $to, $subject, $message, $headers, $attachments ) {
	return apply_filters( 'pre_wp_mail', null, compact( 'to', 'subject', 'message', 'headers', 'attachments' ) );
}
require __DIR__ . '/pending-action-resolver-contract-smoke.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/Publish/SendEmailAbility.php';

use DataMachine\Abilities\Publish\SendEmailAbility;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;

function email_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
}
add_filter( 'pre_wp_mail', static function ( $pre, $mail ) {
	$GLOBALS['email_sent'][] = $mail;
	return true;
}, 10, 2 );
add_filter( 'wp_agent_pending_action_resolver', static fn() => ResolvePendingActionAbility::adapter() );
$ability = new SendEmailAbility();
$input = array( 'to' => 'recipient@example.com', 'subject' => 'Approval test', 'body' => 'Approved body' );
$GLOBALS['email_user'] = 456;
$staged = $ability->execute( $input + array( 'on_behalf_of' => 123 ) );
$GLOBALS['email_user'] = 123;
email_assert( ! empty( $staged['staged'] ) && empty( $GLOBALS['email_sent'] ), 'staging sends nothing' );
email_assert( 123 === PendingActionStore::get( $staged['action_id'] )['apply_input']['on_behalf_of'], 'staging persists the principal and action' );
$result = ResolvePendingActionAbility::execute( array( 'action_id' => $staged['action_id'], 'decision' => 'accepted' ) );
email_assert( ! is_wp_error( $result ) && ! empty( $result['success'] ) && count( $GLOBALS['email_sent'] ) === 1, 'principal acceptance replays send without restaging' );
email_assert( 'Approved body' === $GLOBALS['email_sent'][0]['message'], 'approved mail keeps its body' );
$replay = ResolvePendingActionAbility::execute( array( 'action_id' => $staged['action_id'], 'decision' => 'accepted' ) );
email_assert( ( is_wp_error( $replay ) || empty( $replay['success'] ) ) && count( $GLOBALS['email_sent'] ) === 1, 'resolved send cannot be replayed twice' );
$staged = $ability->execute( $input + array( 'on_behalf_of' => 123 ) );
foreach ( array( 789, 456 ) as $other ) {
	$GLOBALS['email_user'] = $other;
	$result = ResolvePendingActionAbility::execute( array( 'action_id' => $staged['action_id'], 'decision' => 'accepted', 'operator_wide' => 456 === $other ) );
	email_assert( ( is_wp_error( $result ) || empty( $result['success'] ) ) && count( $GLOBALS['email_sent'] ) === 1, 'different user refused, including administrator with operator-wide access: ' . $other );
}
$GLOBALS['email_user'] = 123;
$result = $ability->execute( $input );
email_assert( ! is_wp_error( $result ) && ! empty( $result['success'] ) && count( $GLOBALS['email_sent'] ) === 2, 'without on_behalf_of sends immediately' );
