<?php
/**
 * Pure-PHP smoke test for transcript owner normalization.
 *
 * Run with: php tests/chat-transcript-owner-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function sanitize_text_field( string $value ): string {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) );
}

function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_current_user_id(): int {
	return 0;
}

function is_user_logged_in(): bool {
	return false;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

class WP_Error {
	private string $code;

	public function __construct( string $code ) {
		$this->code = $code;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

require_once __DIR__ . '/../inc/Abilities/Chat/ChatTranscriptOwner.php';

use DataMachine\Abilities\Chat\ChatTranscriptOwner;

$failures = array();
$passes   = 0;

$assert_true = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "FAIL: {$label}\n";
};

echo "chat-transcript-owner-smoke\n";

$user_owner = ChatTranscriptOwner::user_owner( 7 );
$assert_true( 'user' === $user_owner['owner_type'], 'user owner keeps user type' );
$assert_true( hash( 'sha256', 'user:7' ) === $user_owner['owner_key_hash'], 'user owner hashes user key' );

$browser_owner = ChatTranscriptOwner::resolve_for_request(
	array(
		'client_context' => array(
			'transcript_owner' => array(
				'type'  => 'browser',
				'key'   => 'browser-session-abc',
				'label' => 'Anonymous browser',
			),
		),
	),
	42
);
$assert_true( ! is_wp_error( $browser_owner ), 'explicit browser owner resolves' );
$assert_true( 'browser' === $browser_owner['owner_type'], 'browser owner keeps browser type' );
$assert_true( 42 === $browser_owner['user_id'], 'browser owner preserves runtime user for reporting' );
$assert_true( hash( 'sha256', 'browser:browser-session-abc' ) === $browser_owner['owner_key_hash'], 'browser owner hashes scoped key' );

$canonical_owner = ChatTranscriptOwner::resolve_for_request(
	array(
		'session_owner' => array(
			'type'  => 'browser',
			'key'   => 'browser-session-def',
			'label' => 'Canonical browser',
		),
	),
	42
);
$assert_true( ! is_wp_error( $canonical_owner ), 'canonical session_owner resolves' );
$assert_true( 'browser' === $canonical_owner['owner_type'], 'canonical session_owner keeps browser type' );
$assert_true( hash( 'sha256', 'browser:browser-session-def' ) === $canonical_owner['owner_key_hash'], 'canonical session_owner hashes scoped key' );

$public_owner = ChatTranscriptOwner::resolve_for_request(
	array(
		'transcript_owner' => array(
			'type' => 'audience',
			'key'  => 'public',
		),
	),
	42
);
$assert_true( is_wp_error( $public_owner ), 'public audience owner is rejected' );
$assert_true( 'non_isolating_transcript_owner' === $public_owner->get_error_code(), 'public audience rejection is explicit' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " transcript owner assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} transcript owner assertions passed.\n";
