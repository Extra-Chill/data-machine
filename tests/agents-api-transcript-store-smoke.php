<?php
/**
 * Pure-PHP smoke test for the Agents API transcript store contract boundary.
 *
 * Run with: php tests/agents-api-transcript-store-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['__agents_api_transcript_smoke_actions'] = array();

function sanitize_title( string $value ): string {
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
}

function sanitize_file_name( string $value ): string {
	return basename( $value );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $accepted_args );
	$GLOBALS['__agents_api_transcript_smoke_actions'][ $hook ][ $priority ][] = $callback;
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

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

class AgentsApiFakeTranscriptStore implements WP_Agent_Conversation_Store {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $sessions = array();

	public function create_session( ...$args ): string {
		$workspace   = $args[0];
		$user_id     = (int) ( $args[1] ?? 0 );
		$agent       = $args[2] ?? '';
		$agent_slug  = is_string( $agent ) ? $agent : '';
		$metadata    = is_array( $args[3] ?? null ) ? $args[3] : array();
		$context     = (string) ( $args[4] ?? 'chat' );
		$session_id = 'session-' . ( count( $this->sessions ) + 1 );

		$this->sessions[ $session_id ] = array(
			'session_id'     => $session_id,
			'workspace_type' => $workspace->workspace_type,
			'workspace_id'   => $workspace->workspace_id,
			'user_id'        => $user_id,
			'agent_slug'     => $agent_slug,
			'title'          => '',
			'messages'       => array(),
			'metadata'       => $metadata,
			'provider'       => '',
			'model'          => '',
			'context'        => $context,
			'mode'           => $context,
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			'last_read_at'   => null,
			'expires_at'     => null,
		);

		return $session_id;
	}

	public function get_session( string $session_id ): ?array {
		return $this->sessions[ $session_id ] ?? null;
	}

	public function list_sessions( WP_Agent_Workspace_Scope $workspace, int $user_id, array $args = array() ): array {
		$context = (string) ( $args['context'] ?? '' );

		return array_values(
			array_filter(
				$this->sessions,
				static function ( array $session ) use ( $workspace, $user_id, $context ): bool {
					return $workspace->workspace_type === $session['workspace_type']
						&& $workspace->workspace_id === $session['workspace_id']
						&& $user_id === $session['user_id']
						&& ( '' === $context || $context === $session['context'] );
				}
			)
		);
	}

	public function update_session( string $session_id, array $messages, array $metadata = array(), string $provider = '', string $model = '', ?string $provider_response_id = null ): bool {
		unset( $provider_response_id );
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}

		$this->sessions[ $session_id ]['messages']   = $messages;
		$this->sessions[ $session_id ]['metadata']   = $metadata;
		$this->sessions[ $session_id ]['provider']   = $provider;
		$this->sessions[ $session_id ]['model']      = $model;
		$this->sessions[ $session_id ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return true;
	}

	public function delete_session( string $session_id ): bool {
		unset( $this->sessions[ $session_id ] );
		return true;
	}

	public function get_recent_pending_session( \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope $workspace, int $user_id, int $seconds = 600, string $context = 'chat', ?int $token_id = null ): ?array {
		unset( $workspace, $seconds, $token_id );

		foreach ( array_reverse( $this->sessions ) as $session ) {
			if ( $user_id === $session['user_id'] && $context === $session['context'] && array() === $session['messages'] ) {
				return $session;
			}
		}

		return null;
	}

	public function update_title( string $session_id, string $title ): bool {
		if ( ! isset( $this->sessions[ $session_id ] ) ) {
			return false;
		}

		$this->sessions[ $session_id ]['title'] = $title;
		return true;
	}
}

echo "agents-api-transcript-store-smoke\n";

$assert_true( defined( 'AGENTS_API_LOADED' ), 'agents-api bootstrap loads without Data Machine product runtime' );
$assert_true( interface_exists( WP_Agent_Conversation_Store::class ), 'transcript contract is loaded by agents-api bootstrap' );
$assert_true( false === class_exists( 'DataMachine\\Core\\Database\\Chat\\Chat', false ), 'Data Machine chat table implementation is not loaded' );
$assert_true( false === interface_exists( 'DataMachine\\Core\\Database\\Chat\\ConversationStoreInterface', false ), 'Data Machine aggregate chat contract is not loaded' );

$store = new AgentsApiFakeTranscriptStore();
$assert_true( in_array( WP_Agent_Conversation_Store::class, class_implements( $store ), true ), 'fake store can implement transcript contract without chat product interfaces' );

$workspace  = WP_Agent_Workspace_Scope::from_parts( 'site', 'https://example.test' );
$session_id = $store->create_session( $workspace, 7, 'smoke-agent', array( 'source' => 'smoke' ), 'pipeline' );
$assert_true( 'session-1' === $session_id, 'fake store creates transcript session IDs' );
$assert_true( null !== $store->get_recent_pending_session( $workspace, 7, 600, 'pipeline' ), 'fake store can query pending transcript sessions' );

$updated = $store->update_session(
	$session_id,
	array(
		array(
			'role'    => 'user',
			'content' => 'Hello transcript store',
		),
	),
	array( 'source' => 'updated' ),
	'openai',
	'gpt-5.4'
);
$assert_true( true === $updated, 'fake store updates complete transcript data' );

$store->update_title( $session_id, 'Transcript Smoke' );
$session = $store->get_session( $session_id );
$assert_true( 'Transcript Smoke' === ( $session['title'] ?? '' ), 'fake store updates stored transcript title' );
$assert_true( 'gpt-5.4' === ( $session['model'] ?? '' ), 'fake store preserves provider metadata without Data Machine pipeline objects' );

$store->delete_session( $session_id );
$assert_true( null === $store->get_session( $session_id ), 'fake store deletes transcript sessions idempotently' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " Agents API transcript assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} Agents API transcript assertions passed.\n";
