<?php
/**
 * Webhook Verifier — provider-agnostic template engine.
 *
 * Verifies HMAC-family webhook signatures using a declarative config that
 * describes *how* a provider signs rather than *which* provider is signing.
 * Covers GitHub, Stripe, Slack, Shopify, Linear, Svix / Standard Webhooks,
 * Mailgun, PayPal, Clerk, and anything else in the HMAC family — with zero
 * provider-specific code in DM core.
 *
 * Non-HMAC primitives (Ed25519, x509) are handled by pluggable verifier
 * modes registered via the `datamachine_webhook_verifier_modes` filter.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 * @see https://github.com/Extra-Chill/data-machine/issues/1179
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-agnostic webhook signature verifier.
 *
 * Public entry point: {@see WebhookVerifier::verify()}.
 *
 * Configuration shape:
 *
 * ```php
 * [
 *   'mode'             => 'hmac',                           // 'hmac' (core) | anything from the mode filter registry
 *   'algo'             => 'sha256',                         // 'sha1' | 'sha256' | 'sha512'
 *   'signed_template'  => '{timestamp}.{body}',             // placeholders: {body} {timestamp} {id} {url} {header:X} {param:X}
 *   'signature_source' => [ header|param, extract, encoding ],
 *   'timestamp_source' => [ header|param, extract, format ], // optional — presence enables replay protection
 *   'id_source'        => [ header|param, extract ],         // optional
 *   'tolerance_seconds'=> 300,                              // only used when timestamp_source is set
 *   'secrets'          => [ [ 'id' => 'current', 'value' => '...' ], ... ],
 *   'max_body_bytes'   => 1048576,                           // 0 = unlimited
 * ]
 * ```
 */
class WebhookVerifier {

	/** Default max body size (1 MB). */
	const DEFAULT_MAX_BODY_BYTES = 1048576;

	/** Default replay tolerance (5 minutes). */
	const DEFAULT_TOLERANCE_SECONDS = 300;

	/**
	 * Verify a webhook request against a template-based auth config.
	 *
	 * @param string               $raw_body     Raw request body bytes (as signed by the sender).
	 * @param array<string,string> $headers      Request headers, keyed by lower-case name.
	 * @param array<string,mixed>  $query_params Query string parameters (for `{param:X}` in the URL).
	 * @param array<string,mixed>  $post_params  Form-encoded body params (for `{param:X}` in the body).
	 * @param string               $url          Full request URL (for `{url}` template token).
	 * @param array                $config       Auth config — see class docblock for shape.
	 * @param int|null             $now          Override "now" for deterministic tests.
	 * @return WebhookVerificationResult
	 */
	public static function verify(
		string $raw_body,
		array $headers,
		array $query_params,
		array $post_params,
		string $url,
		array $config,
		?int $now = null
	): WebhookVerificationResult {

		// Lower-case header keys once so `{header:X}` lookups are case-insensitive.
		$headers = self::lower_case_keys( $headers );
		$now     = $now ?? time();

		$mode = $config['mode'] ?? 'hmac';
		if ( 'hmac' !== $mode ) {
			$modes = apply_filters( 'datamachine_webhook_verifier_modes', array() );
			if ( isset( $modes[ $mode ] ) && is_callable( array( $modes[ $mode ], 'verify' ) ) ) {
				return call_user_func(
					array( $modes[ $mode ], 'verify' ),
					$raw_body,
					$headers,
					$query_params,
					$post_params,
					$url,
					$config,
					$now
				);
			}
			return WebhookVerificationResult::fail( WebhookVerificationResult::UNKNOWN_MODE, "mode={$mode}" );
		}

		// Payload size cap (checked before any crypto).
		$max = (int) ( $config['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES );
		if ( $max > 0 && strlen( $raw_body ) > $max ) {
			return WebhookVerificationResult::fail(
				WebhookVerificationResult::PAYLOAD_TOO_LARGE,
				sprintf( 'size=%d limit=%d', strlen( $raw_body ), $max )
			);
		}

		$secrets = self::active_secrets( $config['secrets'] ?? array(), $now );
		if ( empty( $secrets ) ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::NO_ACTIVE_SECRET );
		}

		$signature_source = $config['signature_source'] ?? null;
		if ( ! is_array( $signature_source ) ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MALFORMED_CONFIG, 'signature_source missing' );
		}

		// Extract the signature.
		$sig_read = self::read_source( $signature_source, $headers, $query_params, $post_params );
		if ( null === $sig_read['raw'] ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MISSING_HEADER, $sig_read['detail'] );
		}
		if ( '' === $sig_read['extracted'] ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MISSING_SIGNATURE, $sig_read['detail'] );
		}

		$encoding  = $signature_source['encoding'] ?? 'hex';
		$sig_bytes = self::decode_signature( $sig_read['extracted'], $encoding );
		if ( null === $sig_bytes ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::BAD_SIGNATURE, 'undecodable signature' );
		}

		// Optional timestamp extraction + replay enforcement.
		$timestamp    = null;
		$skew_seconds = null;
		if ( ! empty( $config['timestamp_source'] ) && is_array( $config['timestamp_source'] ) ) {
			$ts_read = self::read_source( $config['timestamp_source'], $headers, $query_params, $post_params );
			if ( null === $ts_read['raw'] || '' === $ts_read['extracted'] ) {
				return WebhookVerificationResult::fail( WebhookVerificationResult::MISSING_TIMESTAMP, $ts_read['detail'] );
			}
			$timestamp = self::parse_timestamp( $ts_read['extracted'], $config['timestamp_source']['format'] ?? 'unix' );
			if ( null === $timestamp ) {
				return WebhookVerificationResult::fail( WebhookVerificationResult::MISSING_TIMESTAMP, 'unparseable timestamp' );
			}
			$tolerance    = (int) ( $config['tolerance_seconds'] ?? self::DEFAULT_TOLERANCE_SECONDS );
			$skew_seconds = abs( $now - $timestamp );
			if ( $tolerance > 0 && $skew_seconds > $tolerance ) {
				return WebhookVerificationResult::fail(
					WebhookVerificationResult::STALE_TIMESTAMP,
					sprintf( 'skew=%ds tolerance=%ds', $skew_seconds, $tolerance ),
					$timestamp,
					$skew_seconds
				);
			}
		}

		// Optional id extraction (for templates that reference `{id}`).
		$id_value = null;
		if ( ! empty( $config['id_source'] ) && is_array( $config['id_source'] ) ) {
			$id_read  = self::read_source( $config['id_source'], $headers, $query_params, $post_params );
			$id_value = $id_read['extracted'];
		}

		// Render the signed message template.
		$template = $config['signed_template'] ?? '{body}';
		if ( ! is_string( $template ) || '' === $template ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MALFORMED_TEMPLATE );
		}

		$rendered = self::render_template(
			$template,
			array(
				'body'      => $raw_body,
				'timestamp' => null !== $timestamp ? (string) $timestamp : '',
				'id'        => (string) ( $id_value ?? '' ),
				'url'       => $url,
			),
			$headers,
			$query_params,
			$post_params
		);

		if ( null === $rendered ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MALFORMED_TEMPLATE );
		}

		$algo = $config['algo'] ?? 'sha256';
		if ( ! in_array( $algo, hash_hmac_algos(), true ) ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MALFORMED_CONFIG, "algo={$algo}" );
		}

		// Loop all active secrets — first match wins.
		foreach ( $secrets as $secret ) {
			$value = $secret['value'];
			if ( '' === $value ) {
				continue;
			}
			$expected = hash_hmac( $algo, $rendered, $value, true );
			if ( hash_equals( $expected, $sig_bytes ) ) {
				return WebhookVerificationResult::ok( $secret['id'], $timestamp, $skew_seconds );
			}
		}

		return WebhookVerificationResult::fail(
			WebhookVerificationResult::BAD_SIGNATURE,
			null,
			$timestamp,
			$skew_seconds
		);
	}

	/*
	=================================================================
	 * Template rendering
	 * ================================================================= */

	/**
	 * Render a signed-message template into the exact bytes to HMAC.
	 *
	 * Recognised placeholders:
	 * - `{body}`           — raw request body
	 * - `{timestamp}`      — extracted timestamp value (string)
	 * - `{id}`             — extracted id value (string)
	 * - `{url}`            — full request URL
	 * - `{header:<name>}`  — request header value (case-insensitive)
	 * - `{param:<name>}`   — query param first, then body param
	 *
	 * Unknown placeholders return null (caller responds with MALFORMED_TEMPLATE).
	 *
	 * @param string               $template
	 * @param array<string,string> $simple_tokens
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $query_params
	 * @param array<string,mixed>  $post_params
	 * @return string|null Rendered message bytes, or null on unknown placeholder.
	 */
	private static function render_template(
		string $template,
		array $simple_tokens,
		array $headers,
		array $query_params,
		array $post_params
	): ?string {
		$malformed = false;

		$rendered = preg_replace_callback(
			'/\{([a-z_]+)(?::([^}]+))?\}/i',
			function ( array $m ) use ( $simple_tokens, $headers, $query_params, $post_params, &$malformed ) {
				$kind = strtolower( $m[1] );
				$arg  = $m[2] ?? '';

				if ( '' === $arg ) {
					if ( array_key_exists( $kind, $simple_tokens ) ) {
						return $simple_tokens[ $kind ];
					}
					$malformed = true;
					return '';
				}

				switch ( $kind ) {
					case 'header':
						$value = $headers[ strtolower( $arg ) ] ?? '';
						return (string) $value;
					case 'param':
						if ( array_key_exists( $arg, $query_params ) ) {
							$value = $query_params[ $arg ];
						} elseif ( array_key_exists( $arg, $post_params ) ) {
							$value = $post_params[ $arg ];
						} else {
							return '';
						}
						return is_scalar( $value ) ? (string) $value : '';
					default:
						$malformed = true;
						return '';
				}
			},
			$template
		);

		if ( $malformed || null === $rendered ) {
			return null;
		}

		return $rendered;
	}

	/*
	=================================================================
	 * Source extraction
	 * ================================================================= */

	/**
	 * Read a source descriptor (signature_source, timestamp_source, id_source).
	 *
	 * A source either pulls from a `header` or from a `param` (query → post).
	 * The raw value can then be narrowed further by `extract`:
	 *
	 *   extract.kind = 'raw'       (default) — return value as-is
	 *   extract.kind = 'prefix'    — strip `extract.key`; reject if not present
	 *   extract.kind = 'kv_pairs'  — split by `extract.separator` into k=v pairs, return value for `extract.key`
	 *   extract.kind = 'regex'     — PCRE pattern; capture group 1 is the result
	 *
	 * Returns: [ 'raw' => null|string, 'extracted' => string, 'detail' => string|null ]
	 *   raw = null when the source itself is missing (e.g. header absent)
	 *   extracted = '' when extraction could not find the target (but raw was present)
	 *
	 * @param array                $source
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $query_params
	 * @param array<string,mixed>  $post_params
	 * @return array{raw:?string, extracted:string, detail:?string}
	 */
	private static function read_source( array $source, array $headers, array $query_params, array $post_params ): array {
		$raw    = null;
		$detail = null;

		if ( ! empty( $source['header'] ) ) {
			$name = strtolower( (string) $source['header'] );
			$raw  = $headers[ $name ] ?? null;
			if ( null === $raw ) {
				return array(
					'raw'       => null,
					'extracted' => '',
					'detail'    => "header={$source['header']}",
				);
			}
		} elseif ( ! empty( $source['param'] ) ) {
			$name = (string) $source['param'];
			if ( array_key_exists( $name, $query_params ) ) {
				$raw = is_scalar( $query_params[ $name ] ) ? (string) $query_params[ $name ] : null;
			} elseif ( array_key_exists( $name, $post_params ) ) {
				$raw = is_scalar( $post_params[ $name ] ) ? (string) $post_params[ $name ] : null;
			}
			if ( null === $raw ) {
				return array(
					'raw'       => null,
					'extracted' => '',
					'detail'    => "param={$name}",
				);
			}
		} else {
			return array(
				'raw'       => null,
				'extracted' => '',
				'detail'    => 'source missing header or param',
			);
		}

		$extracted = self::apply_extract( $raw, $source['extract'] ?? array() );
		return array(
			'raw'       => $raw,
			'extracted' => $extracted,
			'detail'    => $detail,
		);
	}

	/**
	 * Apply an `extract` rule to a raw source value.
	 *
	 * @param string $raw
	 * @param array  $rule
	 * @return string Empty string when the rule did not match.
	 */
	private static function apply_extract( string $raw, array $rule ): string {
		$kind = $rule['kind'] ?? 'raw';

		switch ( $kind ) {
			case 'raw':
				return trim( $raw );

			case 'prefix':
				$prefix = (string) ( $rule['key'] ?? '' );
				if ( '' === $prefix ) {
					return trim( $raw );
				}
				if ( 0 !== strpos( $raw, $prefix ) ) {
					return '';
				}
				return trim( substr( $raw, strlen( $prefix ) ) );

			case 'kv_pairs':
				$separator = (string) ( $rule['separator'] ?? ',' );
				$key       = (string) ( $rule['key'] ?? '' );
				if ( '' === $key ) {
					return '';
				}
				$pair_sep = (string) ( $rule['pair_separator'] ?? '=' );
				foreach ( self::split_preserve_empty( $raw, $separator ) as $piece ) {
					$piece = trim( $piece );
					if ( '' === $piece ) {
						continue;
					}
					$eq = strpos( $piece, $pair_sep );
					if ( false === $eq ) {
						continue;
					}
					$k = substr( $piece, 0, $eq );
					$v = substr( $piece, $eq + strlen( $pair_sep ) );
					if ( $k === $key ) {
						return trim( $v );
					}
				}
				return '';

			case 'regex':
				$pattern = (string) ( $rule['pattern'] ?? '' );
				if ( '' === $pattern ) {
					return '';
				}
				// User-supplied patterns can be invalid; swallow the warning and treat as no match.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
				set_error_handler( static function () {
					return true;
				} );
				$m      = array();
				$result = preg_match( $pattern, $raw, $m );
				restore_error_handler();
				if ( 1 === $result ) {
					return isset( $m[1] ) ? trim( $m[1] ) : trim( $m[0] );
				}
				return '';

			default:
				return '';
		}
	}

	/*
	=================================================================
	 * Signature decoding + timestamp parsing
	 * ================================================================= */

	/**
	 * Decode a signature string into raw bytes for binary comparison.
	 *
	 * @param string $sig
	 * @param string $encoding 'hex' | 'base64' | 'base64url'
	 * @return string|null Bytes, or null if the input is not valid for the declared encoding.
	 */
	private static function decode_signature( string $sig, string $encoding ): ?string {
		$sig = trim( $sig );
		switch ( $encoding ) {
			case 'hex':
				if ( ! ctype_xdigit( $sig ) || 0 !== strlen( $sig ) % 2 ) {
					return null;
				}
				// Already validated for even-length hex — hex2bin will not emit warnings.
				$bytes = hex2bin( $sig );
				return false === $bytes ? null : $bytes;

			case 'base64':
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$bytes = base64_decode( $sig, true );
				return false === $bytes ? null : $bytes;

			case 'base64url':
				$padded = strtr( $sig, '-_', '+/' );
				$pad    = strlen( $padded ) % 4;
				if ( $pad ) {
					$padded .= str_repeat( '=', 4 - $pad );
				}
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$bytes = base64_decode( $padded, true );
				return false === $bytes ? null : $bytes;

			default:
				return null;
		}
	}

	/**
	 * Parse an extracted timestamp value into a unix-seconds integer.
	 *
	 * @param string $value
	 * @param string $format 'unix' | 'unix_ms' | 'iso8601'
	 * @return int|null
	 */
	private static function parse_timestamp( string $value, string $format ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		switch ( $format ) {
			case 'unix':
				if ( ! preg_match( '/^-?\d+$/', $value ) ) {
					return null;
				}
				return (int) $value;

			case 'unix_ms':
				if ( ! preg_match( '/^-?\d+$/', $value ) ) {
					return null;
				}
				return (int) floor( ( (int) $value ) / 1000 );

			case 'iso8601':
				$ts = strtotime( $value );
				return false === $ts ? null : (int) $ts;

			default:
				return null;
		}
	}

	/*
	=================================================================
	 * Secret lifecycle
	 * ================================================================= */

	/**
	 * Filter + normalise the active secrets list.
	 *
	 * Accepts:
	 * - `[ [ 'id' => '...', 'value' => '...', 'expires_at' => '...' ], ... ]` (preferred)
	 * - `[ 'raw-secret-string' ]`                              (flat list — treated as id='default')
	 * - `'raw-secret-string'`                                   (single — normalised to one-element list)
	 *
	 * Secrets with `expires_at` in the past are dropped.
	 *
	 * @param mixed $secrets
	 * @param int   $now
	 * @return array<int,array{id:string,value:string}>
	 */
	private static function active_secrets( $secrets, int $now ): array {
		if ( is_string( $secrets ) ) {
			$secrets = array( $secrets );
		}
		if ( ! is_array( $secrets ) ) {
			return array();
		}

		$active = array();
		$index  = 0;
		foreach ( $secrets as $key => $entry ) {
			if ( is_string( $entry ) ) {
				$active[] = array(
					'id'    => is_string( $key ) ? $key : ( 'secret_' . $index ),
					'value' => $entry,
				);
				++$index;
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$value = (string) ( $entry['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$expires_at = $entry['expires_at'] ?? null;
			if ( ! empty( $expires_at ) ) {
				$ts = is_numeric( $expires_at ) ? (int) $expires_at : strtotime( (string) $expires_at );
				if ( false !== $ts && $ts <= $now ) {
					continue;
				}
			}
			$id = (string) ( $entry['id'] ?? ( 'secret_' . $index ) );
			if ( '' === $id ) {
				$id = 'secret_' . $index;
			}
			$active[] = array(
				'id'    => $id,
				'value' => $value,
			);
			++$index;
		}

		return $active;
	}

	/*
	=================================================================
	 * Helpers
	 * ================================================================= */

	/**
	 * Lower-case array keys (top level only).
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	private static function lower_case_keys( array $in ): array {
		$out = array();
		foreach ( $in as $k => $v ) {
			$out[ strtolower( (string) $k ) ] = $v;
		}
		return $out;
	}

	/**
	 * strlen-safe split that handles an empty separator by returning the whole string.
	 *
	 * @param string $subject
	 * @param string $separator
	 * @return array<int,string>
	 */
	private static function split_preserve_empty( string $subject, string $separator ): array {
		if ( '' === $separator ) {
			return array( $subject );
		}
		return explode( $separator, $subject );
	}
}
