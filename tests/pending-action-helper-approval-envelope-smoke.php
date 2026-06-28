<?php
/**
 * Smoke coverage for PendingActionHelper's approval-required result shape.
 *
 * Run with: php tests/pending-action-helper-approval-envelope-smoke.php
 *
 * Contract:
 *   - Successful stage() returns the canonical Agents API approval_required
 *     envelope plus two top-level convenience fields: staged=true and action_id.
 *   - The envelope payload is the canonical flat pending-action shape (action_id,
 *     kind, summary, preview, status, expires_at, …) plus the standardized
 *     `instruction` and `grants` slots — no nested pending_action/resolve_with
 *     wrapper.
 *   - Failure stage() returns array( 'staged' => false, 'error' => ... ).
 *
 * @package DataMachine\Tests
 */

// Pure-PHP smoke runs without a $wpdb, so let PendingActionStore fall back to
// the transient store (matches the sibling pending-action smokes).
if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

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

// The host-smoke backend provides a real $wpdb but does not run the plugin's
// deferred migrations, so the durable pending-actions table is absent while
// has_database() still reports true. Create the table when a real database is
// present; pure-PHP runs (no real $wpdb) keep using the transient fallback.
global $wpdb;
if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	PendingActionStore::create_table();
}

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

// Canonical flat payload: the pending-action fields live directly on the
// payload, not under a nested `pending_action` key. There is no longer a
// `pending_action`, `resolve_with`, or `resolve_params` wrapper.
$payload = $result['payload'];
datamachine_pending_action_helper_assert( ! array_key_exists( 'pending_action', $payload ), 'Payload no longer nests a pending_action wrapper.' );
datamachine_pending_action_helper_assert( ! array_key_exists( 'resolve_with', $payload ), 'Payload no longer carries a resolve_with directive.' );
datamachine_pending_action_helper_assert( ! array_key_exists( 'resolve_params', $payload ), 'Payload no longer carries a resolve_params directive.' );

datamachine_pending_action_helper_assert( $result['action_id'] === $payload['action_id'], 'Payload carries the staged action_id at the top level.' );
datamachine_pending_action_helper_assert( 'wiki_upsert' === $payload['kind'], 'Payload carries the product handler kind.' );
datamachine_pending_action_helper_assert( 'Update Wiki Page' === $payload['summary'], 'Payload carries the sanitized summary.' );
datamachine_pending_action_helper_assert( array( 'diff' => "- old\n+ new" ) === $payload['preview'], 'Payload carries preview data.' );
datamachine_pending_action_helper_assert( array( 'title' => 'Demo', 'content' => 'Updated content.' ) === $payload['apply_input'], 'Payload carries apply_input.' );
datamachine_pending_action_helper_assert( 'pending' === $payload['status'], 'Payload carries the canonical pending status.' );
datamachine_pending_action_helper_assert( 'user:123' === $payload['creator'], 'Payload records creator identity.' );
datamachine_pending_action_helper_assert( 'agent:456' === $payload['agent'], 'Payload records agent identity.' );
datamachine_pending_action_helper_assert( 123 === $payload['metadata']['datamachine']['created_by'], 'Data Machine created_by audit field is retained in payload metadata.' );
datamachine_pending_action_helper_assert( 456 === $payload['metadata']['datamachine']['agent_id'], 'Data Machine agent_id audit field is retained in payload metadata.' );
datamachine_pending_action_helper_assert( isset( $payload['created_at'] ) && is_string( $payload['created_at'] ), 'Payload carries an ISO creation timestamp.' );
datamachine_pending_action_helper_assert( array_key_exists( 'expires_at', $payload ), 'Payload carries an expires_at field.' );

// Standardized orchestration slots: instruction is always present; grants is
// omitted when the caller staged no resolver grants.
datamachine_pending_action_helper_assert( is_string( $payload['instruction'] ?? null ) && '' !== $payload['instruction'], 'Payload carries the operator instruction slot.' );
datamachine_pending_action_helper_assert( str_contains( $payload['instruction'], $result['action_id'] ), 'Instruction references the action_id to resolve.' );
datamachine_pending_action_helper_assert( ! array_key_exists( 'grants', $payload ), 'Grants slot is omitted when no resolver grants were staged.' );

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

// Resolver grants flow into the canonical `grants` slot.
$granted = PendingActionHelper::stage(
	array(
		'kind'            => 'wiki_upsert',
		'summary'         => 'Grant-carrying stage.',
		'apply_input'     => array( 'title' => 'Demo' ),
		'resolver_grants' => array(
			array( 'resolver' => 'service:publisher', 'scope' => 'publish' ),
		),
	)
);
datamachine_pending_action_helper_assert( true === $granted['staged'], 'Grant-carrying stage succeeds.' );
datamachine_pending_action_helper_assert(
	array( array( 'resolver' => 'service:publisher', 'scope' => 'publish' ) ) === $granted['payload']['grants'],
	'Resolver grants flow into the canonical payload grants slot.'
);

// Failure shape: missing kind/apply_input still returns staged=false.
$failure = PendingActionHelper::stage( array() );
datamachine_pending_action_helper_assert( false === $failure['staged'], 'Missing kind returns staged=false.' );
datamachine_pending_action_helper_assert( 'invalid_kind' === ( $failure['error_code'] ?? '' ), 'Missing kind reports invalid_kind.' );

echo "PendingActionHelper approval envelope smoke passed.\n";
