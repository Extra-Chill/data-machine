<?php
/**
 * Smoke coverage for PendingActionHelper's approval-required result shape.
 *
 * Run with: php tests/pending-action-helper-approval-envelope-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\AgentMessageEnvelope;
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

datamachine_pending_action_helper_assert( AgentMessageEnvelope::SCHEMA === $result['schema'], 'Result uses the Agents API envelope schema.' );
datamachine_pending_action_helper_assert( AgentMessageEnvelope::TYPE_APPROVAL_REQUIRED === $result['type'], 'Result is an approval_required envelope.' );
datamachine_pending_action_helper_assert( 'tool' === $result['role'], 'Approval-required envelope uses the tool role.' );

datamachine_pending_action_helper_assert( true === $result['staged'], 'Legacy top-level staged flag is preserved.' );
datamachine_pending_action_helper_assert( true === $result['payload']['staged'], 'Payload staged flag is available to envelope-aware clients.' );
datamachine_pending_action_helper_assert( $result['action_id'] === $result['payload']['action_id'], 'Legacy action_id is mirrored in the envelope payload.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $result['kind'], 'Legacy Data Machine action kind is preserved at top level.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $result['payload']['kind'], 'Legacy Data Machine action kind is preserved in payload.' );
datamachine_pending_action_helper_assert( 'resolve_pending_action' === $result['resolve_with'], 'Legacy resolver tool name is preserved at top level.' );
datamachine_pending_action_helper_assert( 'resolve_pending_action' === $result['payload']['resolve_with'], 'Legacy resolver tool name is preserved in payload.' );
datamachine_pending_action_helper_assert( 'Update Wiki Page' === $result['summary'], 'Summary is sanitized for legacy clients.' );
datamachine_pending_action_helper_assert( 'Update Wiki Page' === $result['content'], 'Envelope content carries the human summary.' );

$pending_action = $result['payload']['pending_action'];
datamachine_pending_action_helper_assert( $result['action_id'] === $pending_action['action_id'], 'Agents API pending action carries the staged action ID.' );
datamachine_pending_action_helper_assert( 'tool_action' === $pending_action['kind'], 'Agents API pending action keeps a generic action kind.' );
datamachine_pending_action_helper_assert( array( 'diff' => "- old\n+ new" ) === $pending_action['preview'], 'Agents API pending action carries preview data.' );
datamachine_pending_action_helper_assert( '123' === $pending_action['creator'], 'Agents API pending action records creator identity as a string.' );
datamachine_pending_action_helper_assert( '456' === $pending_action['agent'], 'Agents API pending action records agent identity as a string.' );
datamachine_pending_action_helper_assert( isset( $pending_action['created_at'] ) && is_string( $pending_action['created_at'] ), 'Agents API pending action carries an ISO creation timestamp.' );
datamachine_pending_action_helper_assert( isset( $pending_action['expires_at'] ) && is_string( $pending_action['expires_at'] ), 'Agents API pending action carries an ISO expiration timestamp.' );

datamachine_pending_action_helper_assert( 'data-machine' === $result['metadata']['adapter'], 'Data Machine is identified as adapter metadata.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $result['metadata']['datamachine']['kind'], 'Data Machine handler kind lives in adapter metadata.' );
datamachine_pending_action_helper_assert( 'resolve_pending_action' === $result['metadata']['datamachine']['resolve_with'], 'Data Machine resolver name lives in adapter metadata.' );

$stored = PendingActionStore::get( $result['action_id'] );
datamachine_pending_action_helper_assert( is_array( $stored ), 'Pending action is still stored through the existing store.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $stored['kind'], 'Stored Data Machine resolver kind is unchanged.' );
datamachine_pending_action_helper_assert( array( 'title' => 'Demo', 'content' => 'Updated content.' ) === $stored['apply_input'], 'Stored apply input is unchanged.' );

$normalized = AgentMessageEnvelope::normalize( $result );
datamachine_pending_action_helper_assert( AgentMessageEnvelope::TYPE_APPROVAL_REQUIRED === $normalized['type'], 'Result normalizes as an approval_required envelope.' );
datamachine_pending_action_helper_assert( $result['payload'] === $normalized['payload'], 'Envelope payload survives normalization.' );

echo "PendingActionHelper approval envelope smoke passed.\n";
