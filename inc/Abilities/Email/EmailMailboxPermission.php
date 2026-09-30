<?php
/**
 * Shared mailbox-ownership preflight for email ability permission callbacks.
 *
 * Every email ability's `permission_callback` previously enforced only a flat
 * `use_tools`/`can_manage` capability floor and left mailbox ownership entirely
 * to execute()-time resolution via {@see EmailAuth::resolve_mailbox()}. That
 * left the Abilities API `permission_callback` contract — "can this call
 * proceed" — unanswered for the one thing that actually varies per call: which
 * mailbox the caller is trying to reach.
 *
 * This trait closes that gap by reusing the exact authorization EmailAuth
 * already performs (named accounts scoped by site/user/agent ownership, plus
 * per-agent operation delegations) instead of introducing a second, weaker
 * mechanism. It deliberately does NOT route through the generic
 * `PermissionHelper::owns_resource()` primitive used by jobs and agents:
 * `owns_resource()` treats `resource_user_id === 0` as "shared, accessible to
 * anyone with the capability", which is wrong for the site-scoped
 * `email_imap:default` mailbox — that ref is intentionally gated by
 * management capability via `EmailAuth::can_use_default()`, a narrower bar
 * than a bare `use_tools` floor. Reusing `resolve_mailbox()` keeps that
 * distinction intact instead of accidentally widening it.
 *
 * @package DataMachine\Abilities\Email
 */

namespace DataMachine\Abilities\Email;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

trait EmailMailboxPermission {

	/**
	 * Preflight-authorize a mailbox ref for the given operation(s).
	 *
	 * Always requires the `use_tools`/`can_manage` capability floor first —
	 * that alone still gates callers with no Data Machine tool access at all.
	 *
	 * When normalized input carries a concrete, non-empty `auth_ref`, the ref
	 * is resolved through the `email_imap` auth provider and denied when
	 * resolution fails specifically because the caller does not own or hold a
	 * delegation for that ref (`email_mailbox_forbidden`). Every other
	 * resolution failure — the ref does not exist yet, is malformed, or is
	 * ambiguous — is left to execute() to report with its specific error
	 * code; those are configuration/state problems, not authorization
	 * decisions, and denying here would just replace a helpful `WP_Error`
	 * with a generic "ability does not have necessary permission" 403.
	 *
	 * When no `auth_ref` is present at all — a tool-availability probe called
	 * with no input, or an ability whose `auth_ref` is optional and omitted —
	 * the capability floor alone is sufficient; execute() enforces whatever
	 * default/legacy-sender rule applies to that ability.
	 *
	 * @param mixed        $input     Normalized ability input (array) or null.
	 * @param string|array $operation Required mailbox operation(s) for this call.
	 * @return bool
	 */
	private function authorizeMailboxRef( $input, string|array $operation ): bool {
		if ( ! ( PermissionHelper::can( 'use_tools' ) || PermissionHelper::can_manage() ) ) {
			return false;
		}

		$input = is_array( $input ) ? $input : array();
		$ref   = isset( $input['auth_ref'] ) ? trim( (string) $input['auth_ref'] ) : '';
		if ( '' === $ref ) {
			return true;
		}

		$providers = apply_filters( 'datamachine_auth_providers', array() );
		$auth      = $providers['email_imap'] ?? null;
		if ( ! is_object( $auth ) || ! method_exists( $auth, 'resolve_mailbox' ) ) {
			// Not configured on this install — let execute() surface that specific error.
			return true;
		}

		$resolved = $auth->resolve_mailbox( $ref, $operation, array( '_skip_audit' => true ) );
		if ( ! is_wp_error( $resolved ) ) {
			return true;
		}

		return 'email_mailbox_forbidden' !== $resolved->get_error_code();
	}
}
