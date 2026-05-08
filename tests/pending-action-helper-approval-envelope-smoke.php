<?php
/**
 * Smoke coverage for PendingActionHelper's approval-required result shape.
 *
 * Run with: php tests/pending-action-helper-approval-envelope-smoke.php
 *
 * Contract:
 *   - Successful stage() returns the Agents API approval_required envelope
 *     plus two top-level convenience fields: staged=true and action_id.
 *   - Every other detail (kind, summary, preview, resolve_with, etc.) lives
 *     inside envelope.payload / envelope.payload.pending_action.
 *   - Failure stage() returns array( 'staged' => false, 'error' => ... ).
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\WP_Agent_Message;
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionStore;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 123;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $_hook, $value, ...$_args ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $_hook, ...$_args ): void {}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000001';
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $_expiration = 0 ): bool {
		$GLOBALS['datamachine_pending_action_helper_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['datamachine_pending_action_helper_transients'][ $key ] ?? false;
	}
}

function datamachine_pending_action_helper_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['datamachine_pending_action_helper_transients'] = array();

$result = PendingActionHelper::stage(
	array(
		'kind'         => 'wiki_upsert',
		'summary'      => '<strong>Update Wiki Page</strong>',
		'apply_input'  => array(
			'title'   => 'Demo',
			'content' => 'Updated content.',
		),
		'preview_data' => array(
			'diff' => '- old' . "\n" . '+ new',
		),
		'agent_id'     => 456,
		'context'      => array(
			'tool_name'  => 'wiki_upsert',
			'session_id' => 'session_123',
		),
	)
);

datamachine_pending_action_helper_assert( WP_Agent_Message::SCHEMA === $result['schema'], 'Result uses the Agents API envelope schema.' );
datamachine_pending_action_helper_assert( WP_Agent_Message::TYPE_APPROVAL_REQUIRED === $result['type'], 'Result is an approval_required envelope.' );
datamachine_pending_action_helper_assert( 'tool' === $result['role'], 'Approval-required envelope uses the tool role.' );
datamachine_pending_action_helper_assert( 'Update Wiki Page' === $result['content'], 'Envelope content carries the human summary.' );

datamachine_pending_action_helper_assert( true === $result['staged'], 'Successful stage exposes a top-level staged=true.' );
datamachine_pending_action_helper_assert( ! empty( $result['action_id'] ) && str_starts_with( $result['action_id'], 'act_' ), 'Successful stage exposes a top-level action_id.' );

datamachine_pending_action_helper_assert( ! isset( $result['kind'] ), 'Top level no longer mirrors kind.' );
datamachine_pending_action_helper_assert( ! isset( $result['summary'] ), 'Top level no longer mirrors summary.' );
datamachine_pending_action_helper_assert( ! isset( $result['preview'] ), 'Top level no longer mirrors preview.' );
datamachine_pending_action_helper_assert( ! isset( $result['resolve_with'] ), 'Top level no longer mirrors resolve_with.' );
datamachine_pending_action_helper_assert( ! isset( $result['resolve_params'] ), 'Top level no longer mirrors resolve_params.' );
datamachine_pending_action_helper_assert( ! isset( $result['instruction'] ), 'Top level no longer mirrors instruction.' );
datamachine_pending_action_helper_assert( ! isset( $result['expires_at'] ), 'Top level no longer mirrors expires_at.' );

$payload = $result['payload'];
datamachine_pending_action_helper_assert( 'resolve_pending_action' === $payload['resolve_with'], 'Envelope payload carries resolve_with.' );
datamachine_pending_action_helper_assert( $result['action_id'] === $payload['resolve_params']['action_id'], 'Envelope payload resolve_params carries the action_id.' );
datamachine_pending_action_helper_assert( '<accepted|rejected>' === $payload['resolve_params']['decision'], 'Envelope payload resolve_params describes the decision shape.' );
datamachine_pending_action_helper_assert( is_string( $payload['instruction'] ?? null ) && '' !== $payload['instruction'], 'Envelope payload carries the operator instruction.' );

$pending_action = $payload['pending_action'];
datamachine_pending_action_helper_assert( $result['action_id'] === $pending_action['action_id'], 'pending_action carries the staged action_id.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $pending_action['kind'], 'pending_action carries the product handler kind.' );
datamachine_pending_action_helper_assert( 'Update Wiki Page' === $pending_action['summary'], 'pending_action carries the sanitized summary.' );
datamachine_pending_action_helper_assert( array( 'diff' => "- old\n+ new" ) === $pending_action['preview'], 'pending_action carries preview data.' );
datamachine_pending_action_helper_assert( 'user:123' === $pending_action['creator'], 'pending_action records creator identity.' );
datamachine_pending_action_helper_assert( 'agent:456' === $pending_action['agent'], 'pending_action records agent identity.' );
datamachine_pending_action_helper_assert( 123 === $pending_action['metadata']['datamachine']['created_by'], 'Data Machine created_by audit field is retained in pending_action metadata.' );
datamachine_pending_action_helper_assert( 456 === $pending_action['metadata']['datamachine']['agent_id'], 'Data Machine agent_id audit field is retained in pending_action metadata.' );
datamachine_pending_action_helper_assert( isset( $pending_action['created_at'] ) && is_string( $pending_action['created_at'] ), 'pending_action carries an ISO creation timestamp.' );
datamachine_pending_action_helper_assert( isset( $pending_action['expires_at'] ) && is_string( $pending_action['expires_at'] ), 'pending_action carries an ISO expiration timestamp.' );

datamachine_pending_action_helper_assert( 'data-machine' === $result['metadata']['adapter'], 'Data Machine is identified as adapter metadata.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $result['metadata']['datamachine']['kind'], 'Data Machine handler kind lives in adapter metadata.' );
datamachine_pending_action_helper_assert( 'resolve_pending_action' === $result['metadata']['datamachine']['resolve_with'], 'Data Machine resolver name lives in adapter metadata.' );

$stored = PendingActionStore::get( $result['action_id'] );
datamachine_pending_action_helper_assert( is_array( $stored ), 'Pending action is still stored through the existing store.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $stored['kind'], 'Stored Data Machine resolver kind is unchanged.' );
datamachine_pending_action_helper_assert( array( 'title' => 'Demo', 'content' => 'Updated content.' ) === $stored['apply_input'], 'Stored apply input is unchanged.' );
datamachine_pending_action_helper_assert( ! array_key_exists( 'preview', $stored ), 'Store no longer mirrors a legacy preview key on payload.' );

$normalized = WP_Agent_Message::normalize( $result );
datamachine_pending_action_helper_assert( WP_Agent_Message::TYPE_APPROVAL_REQUIRED === $normalized['type'], 'Result normalizes as an approval_required envelope.' );
datamachine_pending_action_helper_assert( $result['payload'] === $normalized['payload'], 'Envelope payload survives normalization.' );

// Failure shape: missing kind/apply_input still returns staged=false.
$failure = PendingActionHelper::stage( array() );
datamachine_pending_action_helper_assert( false === $failure['staged'], 'Missing kind returns staged=false.' );
datamachine_pending_action_helper_assert( 'invalid_kind' === ( $failure['error_code'] ?? '' ), 'Missing kind reports invalid_kind.' );

echo "PendingActionHelper approval envelope smoke passed.\n";
