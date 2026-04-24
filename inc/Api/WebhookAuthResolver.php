<?php
/**
 * Webhook auth config resolver.
 *
 * Produces a canonical verifier config for an HMAC flow from its
 * scheduling_config. Stored shape (post-migration) is always:
 *
 *   scheduling_config[ 'webhook_auth_mode' ] = 'hmac'        // or 'bearer'
 *   scheduling_config[ 'webhook_auth' ]      = <template config>
 *   scheduling_config[ 'webhook_secrets' ]   = [ [...], ... ]
 *
 * Legacy v1 flows (`webhook_auth_mode = hmac_sha256` + `webhook_signature_*`
 * + singular `webhook_secret`) are migrated **once** on first read via the
 * `migrate_legacy()` helper, after which the legacy fields are deleted.
 *
 * No provider names live in this file.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 * @see https://github.com/Extra-Chill/data-machine/issues/1179
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookAuthResolver {

	/**
	 * Resolve a scheduling_config into a canonical mode + verifier config.
	 *
	 * @param array $scheduling_config
	 * @return array{mode:string, verifier:?array, token:?string}
	 */
	public static function resolve( array $scheduling_config ): array {
		$mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';

		if ( 'bearer' === $mode ) {
			return array(
				'mode'     => 'bearer',
				'verifier' => null,
				'token'    => (string) ( $scheduling_config['webhook_token'] ?? '' ),
			);
		}

		// 'hmac' (or any non-bearer mode): require a fully-specified template.
		$verifier = $scheduling_config['webhook_auth'] ?? null;
		if ( ! is_array( $verifier ) ) {
			return array(
				'mode'     => $mode,
				'verifier' => null,
				'token'    => null,
			);
		}

		// Attach secrets from the flow if the template didn't ship its own.
		if ( empty( $verifier['secrets'] ) && ! empty( $scheduling_config['webhook_secrets'] ) ) {
			$verifier['secrets'] = $scheduling_config['webhook_secrets'];
		}

		return array(
			'mode'     => $verifier['mode'] ?? $mode,
			'verifier' => $verifier,
			'token'    => null,
		);
	}

	/**
	 * One-time migration of legacy v1 HMAC fields into the canonical v2 shape.
	 *
	 * Called by callers that own the flow row (ability + trigger handler).
	 * Returns a potentially-mutated scheduling_config. If any legacy fields
	 * were found, the caller should persist the result and the legacy fields
	 * are never seen again.
	 *
	 * Returns [ 'config' => <new>, 'migrated' => <bool> ].
	 *
	 * @param array $scheduling_config
	 * @return array{config:array,migrated:bool}
	 */
	public static function migrate_legacy( array $scheduling_config ): array {
		$legacy_mode       = $scheduling_config['webhook_auth_mode'] ?? null;
		$has_legacy_fields = isset( $scheduling_config['webhook_signature_header'] )
			|| isset( $scheduling_config['webhook_signature_format'] )
			|| isset( $scheduling_config['webhook_secret'] );

		// Only migrate when the flow is on the legacy v1 shorthand.
		if ( 'hmac_sha256' !== $legacy_mode && ! $has_legacy_fields ) {
			return array(
				'config'   => $scheduling_config,
				'migrated' => false,
			);
		}
		if ( 'hmac_sha256' !== $legacy_mode ) {
			// Orphaned legacy fields without the legacy mode — just drop them.
			unset(
				$scheduling_config['webhook_signature_header'],
				$scheduling_config['webhook_signature_format'],
				$scheduling_config['webhook_secret']
			);
			return array(
				'config'   => $scheduling_config,
				'migrated' => true,
			);
		}

		$scheduling_config['webhook_auth_mode'] = 'hmac';

		// Only synthesise a template if one wasn't already there.
		if ( empty( $scheduling_config['webhook_auth'] ) ) {
			$scheduling_config['webhook_auth'] = self::v1_template(
				(string) ( $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256' ),
				(string) ( $scheduling_config['webhook_signature_format'] ?? 'sha256=hex' )
			);
		}

		// Promote legacy single secret into the secrets roster.
		if ( empty( $scheduling_config['webhook_secrets'] ) && ! empty( $scheduling_config['webhook_secret'] ) ) {
			$scheduling_config['webhook_secrets'] = array(
				array(
					'id'    => 'current',
					'value' => (string) $scheduling_config['webhook_secret'],
				),
			);
		}

		// Drop every legacy field — they will never be read again.
		unset(
			$scheduling_config['webhook_signature_header'],
			$scheduling_config['webhook_signature_format'],
			$scheduling_config['webhook_secret']
		);

		return array(
			'config'   => $scheduling_config,
			'migrated' => true,
		);
	}

	/**
	 * Build a template config from the three legacy v1 fields.
	 *
	 * This is the ONLY place in DM core that knows about the
	 * `{sha256=hex | hex | base64}` v1 format enum. It exists solely to
	 * migrate pre-existing flows. No other code path reads these values.
	 *
	 * @internal
	 */
	private static function v1_template( string $header, string $format ): array {
		$signature_source = array(
			'header'   => $header,
			'extract'  => array( 'kind' => 'raw' ),
			'encoding' => 'hex',
		);

		switch ( $format ) {
			case 'sha256=hex':
				$signature_source['extract']  = array(
					'kind' => 'prefix',
					'key'  => 'sha256=',
				);
				$signature_source['encoding'] = 'hex';
				break;
			case 'base64':
				$signature_source['encoding'] = 'base64';
				break;
			case 'hex':
			default:
				$signature_source['encoding'] = 'hex';
				break;
		}

		return array(
			'mode'             => 'hmac',
			'algo'             => 'sha256',
			'signed_template'  => '{body}',
			'signature_source' => $signature_source,
			'max_body_bytes'   => WebhookVerifier::DEFAULT_MAX_BODY_BYTES,
		);
	}

	/**
	 * Presets are filter-registered v2 templates. Core ships zero presets.
	 * Third parties call:
	 *
	 * ```php
	 * add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
	 *     $p['<name>'] = [ full template config ];
	 *     return $p;
	 * } );
	 * ```
	 *
	 * Presets are expanded into a full `webhook_auth` block at enable-time
	 * and then the preset name is gone — the stored flow row contains only
	 * the resolved template. This guarantees preset registrations can change
	 * without silently altering already-configured flows.
	 *
	 * @return array<string,array>
	 */
	public static function get_presets(): array {
		$presets = apply_filters( 'datamachine_webhook_auth_presets', array() );
		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * Recursive array merge: overrides replace scalars, sub-arrays merge deeply.
	 *
	 * @param array $base
	 * @param array $overrides
	 * @return array
	 */
	public static function deep_merge( array $base, array $overrides ): array {
		foreach ( $overrides as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				$base[ $k ] = self::deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}
}
