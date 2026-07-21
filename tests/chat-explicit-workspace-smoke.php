<?php
/**
 * Pure-PHP integration coverage for principal-owned explicit workspaces.
 *
 * Run with: php tests/chat-explicit-workspace-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 0;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '' ) {}
	}
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once dirname( __DIR__ ) . '/inc/Abilities/Chat/ChatTranscriptOwner.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/BaseRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/ConversationSessionIndexInterface.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/ConversationReadStateInterface.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/ConversationRetentionInterface.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/ConversationReportingInterface.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/ConversationStoreInterface.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/Chat.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Chat/PrincipalChat.php';

use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;
use DataMachine\Abilities\Chat\ChatTranscriptOwner;
use DataMachine\Core\Database\Chat\PrincipalChat;

final class ExplicitWorkspaceChatStore extends PrincipalChat {
	/** @var array<string,array<string,mixed>> */
	private array $sessions = array();

	public function __construct() {}

	public function create_session( ...$args ): string {
		$workspace = $args[0];
		$metadata  = $args[3];
		$owner     = $metadata['transcript_owner'];
		$id        = 'explicit-workspace-session';

		$this->sessions[ $id ] = array(
			'session_id'     => $id,
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'user_id'        => (int) $args[1],
			'owner_type'     => $owner['owner_type'],
			'owner_key_hash' => $owner['owner_key_hash'],
			'messages'       => array(),
			'metadata'       => $metadata,
		);

		return $id;
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		unset( $user_id );
		$owner = $args['transcript_owner'] ?? array();
		return array_values(
			array_filter(
				$this->sessions,
				static fn( array $session ): bool => $session['workspace_type'] === $workspace->workspace_type
					&& $session['workspace_id'] === $workspace->workspace_id
					&& $session['owner_type'] === ( $owner['owner_type'] ?? '' )
					&& $session['owner_key_hash'] === ( $owner['owner_key_hash'] ?? '' )
			)
		);
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}
}

$failures = array();
$passes   = 0;
$assert   = static function ( bool $condition, string $label ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = $label;
	echo "FAIL: {$label}\n";
};

$store             = new ExplicitWorkspaceChatStore();
$network_workspace = WP_Agent_Workspace_Scope::from_parts( 'network', 'network:4' );
$site_workspace    = WP_Agent_Workspace_Scope::from_parts( 'site', 'https://other.example' );
$owner             = array( 'type' => 'audience', 'key' => 'principal-123' );
$session_id        = $store->create_session_for_owner( $network_workspace, $owner );

$sessions = $store->list_sessions_for_owner( $network_workspace, $owner );
$assert( 1 === count( $sessions ), 'explicit workspace create is visible through the same workspace list' );
$assert( 'network' === $sessions[0]['workspace_type'] && 'network:4' === $sessions[0]['workspace_id'], 'create preserves the supplied workspace tuple' );
$assert( array() === $store->list_sessions_for_owner( $site_workspace, $owner ), 'ambient or different workspaces cannot list the session' );
$assert( null !== $store->get_session_for_owner( $network_workspace, $owner, $session_id ), 'same owner and workspace can read the session' );
$assert( null === $store->get_session_for_owner( $site_workspace, $owner, $session_id ), 'single-session reads verify workspace ownership' );
$assert( null === $store->get_session_for_owner( $network_workspace, array( 'type' => 'audience', 'key' => 'forged' ), $session_id ), 'single-session reads preserve principal owner isolation' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " explicit workspace assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} explicit workspace assertions passed.\n";
