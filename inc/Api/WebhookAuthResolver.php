<?php
/**
 * Webhook auth config resolver.
 *
 * Converts a flow's `scheduling_config` (any of v1 fields, v2 `webhook_auth`,
 * or a preset name) into a single canonical config shape consumable by
 * {@see WebhookVerifier}. Provider-agnostic: DM core never learns the name
 * "Stripe" or "Slack" — presets are discovered via the
 * `datamachine_webhook_auth_presets` filter.
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
	 * Resolve a flow's scheduling_config to a canonical mode + verifier config.
	 *
	 * Resolution order (highest to lowest precedence):
	 * 1. v2 `webhook_auth` block on the flow.
	 * 2. v2 `webhook_auth_preset` (filter-registered template) merged with overrides.
	 * 3. v1 `webhook_auth_mode` = 'hmac_sha256' with v1 signature fields.
	 * 4. v1 `webhook_auth_mode` = 'bearer' (default).
	 *
	 * Returns [ 'mode' => 'bearer'|'hmac', 'verifier' => ?array, 'token' => ?string ]:
	 * - 'bearer' mode uses the legacy token comparison path (no verifier).
	 * - 'hmac'   mode exposes a fully resolved verifier config.
	 *
	 * @param array $scheduling_config
	 * @return array{mode:string, verifier:?array, token:?string, preset:?string}
	 */
	public static function resolve( array $scheduling_config ): array {
		// v2 — explicit webhook_auth wins.
		$auth = $scheduling_config['webhook_auth'] ?? null;

		// v2 — preset shorthand expands into a webhook_auth merged with overrides.
		$preset_name = null;
		if ( ! is_array( $auth ) && ! empty( $scheduling_config['webhook_auth_preset'] ) ) {
			$preset_name = (string) $scheduling_config['webhook_auth_preset'];
			$presets     = self::get_presets();
			if ( isset( $presets[ $preset_name ] ) ) {
				$overrides = $scheduling_config['webhook_auth_overrides'] ?? array();
				$auth      = self::deep_merge( $presets[ $preset_name ], is_array( $overrides ) ? $overrides : array() );
			}
		}

		if ( is_array( $auth ) ) {
			$mode = $auth['mode'] ?? 'hmac';
			if ( 'bearer' === $mode ) {
				return array(
					'mode'     => 'bearer',
					'verifier' => null,
					'token'    => (string) ( $scheduling_config['webhook_token'] ?? '' ),
					'preset'   => $preset_name,
				);
			}
			// HMAC (or pluggable mode) — attach secrets from the flow if the v2 block didn't ship its own.
			if ( empty( $auth['secrets'] ) && ! empty( $scheduling_config['webhook_secrets'] ) ) {
				$auth['secrets'] = $scheduling_config['webhook_secrets'];
			}
			if ( empty( $auth['secrets'] ) && ! empty( $scheduling_config['webhook_secret'] ) ) {
				$auth['secrets'] = array(
					array(
						'id'    => 'current',
						'value' => (string) $scheduling_config['webhook_secret'],
					),
				);
			}
			return array(
				'mode'     => $auth['mode'] ?? 'hmac',
				'verifier' => $auth,
				'token'    => null,
				'preset'   => $preset_name,
			);
		}

		// v1 — hmac_sha256 shorthand.
		$v1_mode = $scheduling_config['webhook_auth_mode'] ?? 'bearer';
		if ( 'hmac_sha256' === $v1_mode ) {
			$header = (string) ( $scheduling_config['webhook_signature_header'] ?? 'X-Hub-Signature-256' );
			$format = (string) ( $scheduling_config['webhook_signature_format'] ?? 'sha256=hex' );

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
				case 'hex':
					$signature_source['encoding'] = 'hex';
					break;
				case 'base64':
					$signature_source['encoding'] = 'base64';
					break;
			}

			$secrets = array();
			if ( ! empty( $scheduling_config['webhook_secrets'] ) && is_array( $scheduling_config['webhook_secrets'] ) ) {
				$secrets = $scheduling_config['webhook_secrets'];
			} elseif ( ! empty( $scheduling_config['webhook_secret'] ) ) {
				$secrets = array(
					array(
						'id'    => 'current',
						'value' => (string) $scheduling_config['webhook_secret'],
					),
				);
			}

			return array(
				'mode'     => 'hmac',
				'verifier' => array(
					'mode'             => 'hmac',
					'algo'             => 'sha256',
					'signed_template'  => '{body}',
					'signature_source' => $signature_source,
					'secrets'          => $secrets,
					'max_body_bytes'   => (int) ( $scheduling_config['webhook_max_body_bytes']
						?? WebhookVerifier::DEFAULT_MAX_BODY_BYTES ),
				),
				'token'    => null,
				'preset'   => null,
			);
		}

		// v1 bearer (default).
		return array(
			'mode'     => 'bearer',
			'verifier' => null,
			'token'    => (string) ( $scheduling_config['webhook_token'] ?? '' ),
			'preset'   => null,
		);
	}

	/**
	 * Discover filter-registered presets.
	 *
	 * Core ships zero presets; third parties (including chubes.net itself, or a
	 * companion `data-machine-webhook-presets` package) add presets via this
	 * filter:
	 *
	 * ```php
	 * add_filter( 'datamachine_webhook_auth_presets', function ( $presets ) {
	 *     $presets['stripe'] = [
	 *         'mode'             => 'hmac',
	 *         'algo'             => 'sha256',
	 *         'signed_template'  => '{timestamp}.{body}',
	 *         'signature_source' => [ 'header' => 'Stripe-Signature',
	 *             'extract' => [ 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ],
	 *             'encoding' => 'hex' ],
	 *         'timestamp_source' => [ 'header' => 'Stripe-Signature',
	 *             'extract' => [ 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ],
	 *             'format'  => 'unix' ],
	 *         'tolerance_seconds' => 300,
	 *     ];
	 *     return $presets;
	 * } );
	 * ```
	 *
	 * @return array<string,array>
	 */
	public static function get_presets(): array {
		$presets = apply_filters( 'datamachine_webhook_auth_presets', array() );
		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * Recursive array merge where associative keys from $overrides replace
	 * $base (not concat), but deep sub-arrays merge one level at a time.
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
