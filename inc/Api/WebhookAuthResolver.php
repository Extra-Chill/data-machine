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
 * + singular `webhook_secret`) are normalized by the schema migration chain
 * before runtime webhook code reads them.
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
