<?php
/**
 * Pure-PHP smoke coverage for signed pending-action resolution URLs.
 *
 * Run with: php tests/signed-pending-action-resolution-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK' ) ) {
	define( 'DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK', true );
}

$GLOBALS['__signed_filters']    = array();
$GLOBALS['__signed_transients'] = array();
$GLOBALS['__signed_options']    = array();

require_once __DIR__ . '/fixtures/rest-url-stub.php';

function datamachine_signed_assert( bool $condition, string $message, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '11111111-2222-4333-8444-555555555555';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $GLOBALS['__signed_options'][ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['__signed_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['__signed_options'] ) ) {
			return false;
		}
		$GLOBALS['__signed_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		if ( ! array_key_exists( $option, $GLOBALS['__signed_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['__signed_options'][ $option ] );
		return true;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook = '' ) {
		unset( $hook );
		return 0;
	}
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( $hook = '' ) {
		unset( $hook );
		return false;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__signed_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		ksort( $GLOBALS['__signed_filters'][ $hook ], SORT_NUMERIC );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__signed_filters'][ $hook ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['__signed_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registration ) {
				$value = call_user_func_array( $registration[0], array_slice( array_merge( array( $value ), $args ), 0, $registration[1] ) );
			}
		}

		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		apply_filters( $hook, null, ...$args );
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['__signed_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['__signed_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__signed_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/agents-api.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/PermissionHelper.php';
require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionObservers.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionStore.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionAuthorizationReceipt.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionScope.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/PendingActionResolverAdapter.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/ResolvePendingActionAbility.php';
require_once dirname( __DIR__ ) . '/inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php';

add_filter( 'wp_agent_pending_action_resolver', static fn() => \DataMachine\Engine\AI\Actions\ResolvePendingActionAbility::adapter() );

$failures = array();
$passes   = 0;

echo "signed-pending-action-resolution-smoke\n";

$action_id = 'act_signed_smoke';
$GLOBALS['__signed_applies'] = 0;
$GLOBALS['__signed_receipt'] = array();

\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	'act_fallback_atomic',
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Fallback atomic claim and consume smoke',
		'apply_input' => array( 'value' => 7 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$fallback_claim = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( 'act_fallback_atomic', 'email_approval' );
datamachine_signed_assert( is_array( $fallback_claim ), 'transient fallback atomically claims once through a unique option fence', $failures, $passes );
datamachine_signed_assert( null === \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( 'act_fallback_atomic', 'other_resolver' ), 'concurrent/double transient claim loses without changing ownership', $failures, $passes );
$fallback_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $fallback_claim, 'email_approval' );
$fallback_subject = (string) ( $fallback_claim['agent'] ?? $fallback_claim['creator'] ?? '' );
$fallback_first = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $fallback_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 7 ), $fallback_subject, $fallback_claim['workspace'] );
$fallback_second = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $fallback_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 7 ), $fallback_subject, $fallback_claim['workspace'] );
datamachine_signed_assert( true === $fallback_first, 'transient fallback first receipt consumption wins', $failures, $passes );
datamachine_signed_assert( is_wp_error( $fallback_second ) && 'authorization_receipt_consumed' === $fallback_second->get_error_code(), 'transient fallback double consumption is rejected atomically', $failures, $passes );
datamachine_signed_assert( \DataMachine\Engine\AI\Actions\PendingActionStore::complete_claim( 'act_fallback_atomic', (string) $fallback_claim['receipt_nonce'], 'accepted' ), 'terminal fallback completion succeeds after consumption and cleans its fences', $failures, $passes );
$fallback_claim_fence   = 'datamachine_pa_claim_' . hash( 'sha256', 'act_fallback_atomic' );
$fallback_consume_fence = 'datamachine_pa_consume_' . hash( 'sha256', 'act_fallback_atomic' );
datamachine_signed_assert( ! isset( $GLOBALS['__signed_options'][ $fallback_claim_fence ] ) && ! isset( $GLOBALS['__signed_options'][ $fallback_consume_fence ] ), 'normal completion removes claim and consume options', $failures, $passes );

$orphan_cleanup_action = 'act_orphan_cleanup';
\DataMachine\Engine\AI\Actions\PendingActionStore::store( $orphan_cleanup_action, array( 'kind' => 'signed_smoke', 'summary' => 'Orphan cleanup smoke', 'apply_input' => array() ) );
$orphan_cleanup_claim_fence   = 'datamachine_pa_claim_' . hash( 'sha256', $orphan_cleanup_action );
$orphan_cleanup_consume_fence = 'datamachine_pa_consume_' . hash( 'sha256', $orphan_cleanup_action );
$GLOBALS['__signed_options'][ $orphan_cleanup_claim_fence ]   = array( 'owner' => 'orphan-cleanup', 'expires_at' => time() - 1 );
$GLOBALS['__signed_options'][ $orphan_cleanup_consume_fence ] = array( 'owner' => 'orphan-cleanup', 'expires_at' => time() - 1 );
delete_transient( 'datamachine_pending_action_' . $orphan_cleanup_action );
\DataMachine\Engine\AI\Actions\PendingActionStore::delete( $orphan_cleanup_action );
datamachine_signed_assert( ! isset( $GLOBALS['__signed_options'][ $orphan_cleanup_claim_fence ] ) && ! isset( $GLOBALS['__signed_options'][ $orphan_cleanup_consume_fence ] ), 'cleanup removes expired owned fences after the paired transient is gone', $failures, $passes );

$orphan_claim_action = 'act_orphan_claim';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$orphan_claim_action,
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Orphan claim recovery smoke',
		'apply_input' => array( 'value' => 8 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$orphan_claim_fence = 'datamachine_pa_claim_' . hash( 'sha256', $orphan_claim_action );
$GLOBALS['__signed_options'][ $orphan_claim_fence ] = array( 'owner' => 'orphan-claim', 'expires_at' => time() - 1 );
$orphan_claim = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $orphan_claim_action, 'email_approval' );
datamachine_signed_assert( is_array( $orphan_claim ) && 'orphan-claim' !== ( $GLOBALS['__signed_options'][ $orphan_claim_fence ]['owner'] ?? '' ), 'expired orphan claim fence self-heals during acquisition', $failures, $passes );

$live_claim_action = 'act_live_claim';
\DataMachine\Engine\AI\Actions\PendingActionStore::store( $live_claim_action, array( 'kind' => 'signed_smoke', 'summary' => 'Live claim fence smoke', 'apply_input' => array( 'value' => 9 ) ) );
$live_claim_fence = 'datamachine_pa_claim_' . hash( 'sha256', $live_claim_action );
$live_claim_value = array( 'owner' => 'live-claim', 'expires_at' => time() + 60 );
$GLOBALS['__signed_options'][ $live_claim_fence ] = $live_claim_value;
datamachine_signed_assert( null === \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $live_claim_action, 'email_approval' ) && $live_claim_value === $GLOBALS['__signed_options'][ $live_claim_fence ], 'live claim fence blocks concurrency without losing ownership', $failures, $passes );

$orphan_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $orphan_claim, 'email_approval' );
$orphan_subject = (string) ( $orphan_claim['agent'] ?? $orphan_claim['creator'] ?? '' );
$orphan_consume_fence = 'datamachine_pa_consume_' . hash( 'sha256', $orphan_claim_action );
$GLOBALS['__signed_options'][ $orphan_consume_fence ] = array( 'owner' => 'orphan-consume', 'expires_at' => time() - 1 );
$orphan_consumed = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $orphan_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 8 ), $orphan_subject, $orphan_claim['workspace'] );
datamachine_signed_assert( true === $orphan_consumed && 'orphan-consume' !== ( $GLOBALS['__signed_options'][ $orphan_consume_fence ]['owner'] ?? '' ), 'expired orphan consume fence self-heals during acquisition', $failures, $passes );

$live_consume_action = 'act_live_consume';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$live_consume_action,
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Live consume fence smoke',
		'apply_input' => array( 'value' => 10 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$live_consume_claim = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $live_consume_action, 'email_approval' );
$live_consume_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $live_consume_claim, 'email_approval' );
$live_consume_subject = (string) ( $live_consume_claim['agent'] ?? $live_consume_claim['creator'] ?? '' );
$live_consume_fence = 'datamachine_pa_consume_' . hash( 'sha256', $live_consume_action );
$live_consume_value = array( 'owner' => 'live-consume', 'expires_at' => time() + 60 );
$GLOBALS['__signed_options'][ $live_consume_fence ] = $live_consume_value;
$live_consume_result = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $live_consume_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 10 ), $live_consume_subject, $live_consume_claim['workspace'] );
datamachine_signed_assert( is_wp_error( $live_consume_result ) && $live_consume_value === $GLOBALS['__signed_options'][ $live_consume_fence ], 'live consume fence blocks concurrency without losing ownership', $failures, $passes );
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$action_id,
	array(
		'kind'         => 'signed_smoke',
		'summary'      => 'Signed URL smoke action',
		'preview_data' => array( 'title' => 'Preview' ),
		'apply_input'  => array( 'value' => 42 ),
		'created_by'   => 0,
		'agent_id'     => 0,
		'context'      => array(),
		'metadata'     => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ): array {
		$handlers['signed_smoke'] = array(
			'apply' => static function ( array $apply_input, array $payload, array $receipt ): array {
				$GLOBALS['__signed_receipt'] = $receipt;
				$subject = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
				$workspace = $payload['workspace'] ?? array();
				$wrong_kind = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'other_kind', 'signed_smoke', array( 'action' => 'signed' ), $apply_input, $subject, $workspace );
				$wrong_target = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'other' ), $apply_input, $subject, $workspace );
				$wrong_operation = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'signed_smoke', 'other_operation', array( 'action' => 'signed' ), $apply_input, $subject, $workspace );
				$wrong_input = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 0 ), $subject, $workspace );
				$wrong_subject = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), $apply_input, 'other-subject', $workspace );
				$wrong_workspace = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), $apply_input, $subject, array( 'workspace_id' => 'other' ) );
				$valid = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), $apply_input, $subject, $workspace );
				if ( is_wp_error( $valid ) ) {
					return array( 'success' => false, 'error' => $valid->get_error_message() );
				}
				++$GLOBALS['__signed_applies'];
				return array(
					'success' => true,
					'value'   => $apply_input['value'] ?? null,
					'wrong_kind_rejected' => is_wp_error( $wrong_kind ),
					'mismatch_rejected' => is_wp_error( $wrong_target ),
					'wrong_operation_rejected' => is_wp_error( $wrong_operation ),
					'wrong_input_rejected' => is_wp_error( $wrong_input ),
					'wrong_subject_rejected' => is_wp_error( $wrong_subject ),
					'wrong_workspace_rejected' => is_wp_error( $wrong_workspace ),
				);
			},
		);
		return $handlers;
	}
);

$signed_handlers = apply_filters( 'datamachine_pending_action_handlers', array() );
$signed_apply    = $signed_handlers['signed_smoke']['apply'];
$absent_result = $signed_apply(
	array( 'value' => 42 ),
	\DataMachine\Engine\AI\Actions\PendingActionStore::get( $action_id ),
	array()
);
datamachine_signed_assert( false === ( $absent_result['success'] ?? true ), 'production handler rejects an absent receipt before its side effect', $failures, $passes );
datamachine_signed_assert( 0 === $GLOBALS['__signed_applies'], 'absent receipt causes zero side effects', $failures, $passes );

$mismatch_action_id = 'act_mismatch_smoke';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$mismatch_action_id,
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Mismatched mutator smoke action',
		'apply_input' => array( 'value' => 42 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$mismatch_claim   = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $mismatch_action_id, 'email_approval' );
$mismatch_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $mismatch_claim, 'email_approval' );
$mismatch_result  = $signed_apply( array( 'value' => 41 ), $mismatch_claim, $mismatch_receipt );
datamachine_signed_assert( false === ( $mismatch_result['success'] ?? true ), 'production handler rejects mismatched input', $failures, $passes );
datamachine_signed_assert( 0 === $GLOBALS['__signed_applies'], 'mismatched receipt causes zero side effects', $failures, $passes );
datamachine_signed_assert( false === \DataMachine\Engine\AI\Actions\PendingActionStore::complete_claim( $mismatch_action_id, (string) $mismatch_claim['receipt_nonce'], 'accepted' ), 'accepted completion requires prior mutator consumption', $failures, $passes );

$signed = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::execute(
	array(
		'action_id' => $action_id,
		'lifetime'  => 60,
		'resolver'  => 'email_approval',
	)
);

datamachine_signed_assert( true === ( $signed['success'] ?? false ), 'signing ability succeeds for pending action', $failures, $passes );
datamachine_signed_assert( str_contains( (string) ( $signed['approve_url'] ?? '' ), '/actions/resolve-by-token?t=' ), 'approve URL targets token route', $failures, $passes );
datamachine_signed_assert( str_contains( (string) ( $signed['reject_url'] ?? '' ), '/actions/resolve-by-token?t=' ), 'reject URL targets token route', $failures, $passes );
datamachine_signed_assert( ! empty( $GLOBALS['__signed_options']['datamachine_pending_action_resolution_secret'] ), 'HMAC secret is generated in wp_options', $failures, $passes );

$query = array();
parse_str( (string) parse_url( (string) $signed['approve_url'], PHP_URL_QUERY ), $query );

$resolved = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::resolve_token( (string) ( $query['t'] ?? '' ) );

datamachine_signed_assert( true === ( $resolved['success'] ?? false ), 'valid approve token resolves action', $failures, $passes );
datamachine_signed_assert( 'accepted' === ( $resolved['decision'] ?? null ), 'approve token records accepted decision', $failures, $passes );
datamachine_signed_assert( 'signed_smoke' === ( $resolved['kind'] ?? null ), 'resolution keeps pending action kind', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['wrong_kind_rejected'] ?? false ), 'receipt rejects a mismatched kind', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['mismatch_rejected'] ?? false ), 'receipt rejects a mismatched target', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['wrong_operation_rejected'] ?? false ), 'receipt rejects a mismatched operation', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['wrong_input_rejected'] ?? false ), 'receipt rejects mismatched input', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['wrong_subject_rejected'] ?? false ), 'receipt rejects a mismatched subject', $failures, $passes );
datamachine_signed_assert( true === ( $resolved['result']['wrong_workspace_rejected'] ?? false ), 'receipt rejects a mismatched workspace', $failures, $passes );
datamachine_signed_assert( 1 === $GLOBALS['__signed_applies'], 'accepted action applies exactly once', $failures, $passes );

$second = \DataMachine\Engine\AI\Actions\ResolvePendingActionAbility::execute( array( 'action_id' => $action_id, 'decision' => 'accepted', 'resolver' => 'email_approval' ) );
datamachine_signed_assert( false === ( $second['success'] ?? true ), 'a second acceptance cannot claim the action', $failures, $passes );
datamachine_signed_assert( null === \DataMachine\Engine\AI\Actions\PendingActionStore::get( $action_id ) && 'accepted' === ( \DataMachine\Engine\AI\Actions\PendingActionStore::inspect( $action_id )['status'] ?? null ), 'resolved action is retained as an accepted audit row', $failures, $passes );
datamachine_signed_assert( is_wp_error( \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $GLOBALS['__signed_receipt'], 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 42 ), '', $GLOBALS['__signed_receipt']['claims']['workspace'] ) ), 'consumed receipt cannot be reused', $failures, $passes );

$rejected_action_id = 'act_rejected_smoke';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$rejected_action_id,
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Rejected receipt smoke action',
		'apply_input' => array( 'value' => 42 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$rejected_claim = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $rejected_action_id, 'email_approval' );
$rejected_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $rejected_claim, 'email_approval' );
\DataMachine\Engine\AI\Actions\PendingActionStore::complete_claim( $rejected_action_id, (string) $rejected_claim['receipt_nonce'], 'rejected', null, null, 'email_approval' );
datamachine_signed_assert( is_wp_error( \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $rejected_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 42 ), '', $rejected_receipt['claims']['workspace'] ) ), 'rejected receipt cannot be used', $failures, $passes );

$expired_claims = $GLOBALS['__signed_receipt']['claims'];
$expired_claims['expires_at'] = time() - 1;
$expired_encoded = rtrim( strtr( base64_encode( wp_json_encode( $expired_claims ) ), '+/', '-_' ), '=' );
$expired_receipt = array( 'token' => $expired_encoded . '.' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', $expired_encoded, $GLOBALS['__signed_options']['datamachine_pending_action_receipt_secret'], true ) ), '+/', '-_' ), '=' ) );
datamachine_signed_assert( is_wp_error( \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::validate( $expired_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 42 ), '', $expired_claims['workspace'] ) ), 'expired receipt is rejected before use', $failures, $passes );
$expired_apply = $signed_apply( array( 'value' => 42 ), \DataMachine\Engine\AI\Actions\PendingActionStore::inspect( $action_id ), $expired_receipt );
datamachine_signed_assert( false === ( $expired_apply['success'] ?? true ) && 1 === $GLOBALS['__signed_applies'], 'expired receipt causes zero additional side effects in the production handler', $failures, $passes );

$crash_action_id = 'act_crash_smoke';
\DataMachine\Engine\AI\Actions\PendingActionStore::store(
	$crash_action_id,
	array(
		'kind'        => 'signed_smoke',
		'summary'     => 'Crash policy smoke action',
		'apply_input' => array( 'value' => 42 ),
		'metadata'    => array( 'datamachine' => array( 'authorization' => array( 'operation' => 'signed_smoke', 'target' => array( 'action' => 'signed' ) ) ) ),
	)
);
$crash_claim   = \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $crash_action_id, 'email_approval' );
$crash_receipt = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::issue( $crash_claim, 'email_approval' );
$crash_subject = (string) ( $crash_claim['agent'] ?? $crash_claim['creator'] ?? '' );
$crash_consumed = \DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt::consume( $crash_receipt, 'signed_smoke', 'signed_smoke', array( 'action' => 'signed' ), array( 'value' => 42 ), $crash_subject, $crash_claim['workspace'] );
datamachine_signed_assert( true === $crash_consumed, 'crash simulation consumes authorization before side effects', $failures, $passes );
$crash_retry = $signed_apply( array( 'value' => 42 ), $crash_claim, $crash_receipt );
datamachine_signed_assert( false === ( $crash_retry['success'] ?? true ) && 1 === $GLOBALS['__signed_applies'], 'crash retry is blocked with zero duplicate side effects', $failures, $passes );
datamachine_signed_assert( null === \DataMachine\Engine\AI\Actions\PendingActionStore::claim_for_resolution( $crash_action_id, 'email_approval' ), 'consumed applying action cannot be automatically reclaimed after a crash', $failures, $passes );

\DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::rotate_secret();
$after_rotation = \DataMachine\Engine\AI\Actions\SignPendingActionResolutionAbility::resolve_token( (string) ( $query['t'] ?? '' ) );
datamachine_signed_assert( false === ( $after_rotation['success'] ?? true ), 'rotating the HMAC secret invalidates existing tokens', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nSigned pending-action resolution smoke passed ({$passes} assertions).\n";
