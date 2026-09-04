<?php
/**
 * Webhook verifier — provider-agnostic template engine.
 *
 * Verifies HMAC-family webhook signatures from a declarative config that
 * describes *how* a sender signs, not *which* sender is signing. No provider
 * names appear in this engine, ever.
 *
 * Non-HMAC primitives (Ed25519, x509, JWT, mTLS, ...) plug in via the
 * `datamachine_webhook_verifier_modes` filter — they bring their own static
 * class implementing the same verify() signature.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 * @see https://github.com/Extra-Chill/data-machine/issues/1179
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookVerifier {

	const DEFAULT_MAX_BODY_BYTES    = 1048576; // 1 MB
	const DEFAULT_TOLERANCE_SECONDS = 300;     // 5 min

	/**
	 * Verify a webhook request against a template config.
	 *
	 * Config shape (see docs for the full grammar):
	 *
	 * ```
	 * [
	 *   'mode'             => 'hmac',
	 *   'algo'             => 'sha256',
	 *   'signed_template'  => '{timestamp}.{body}',
	 *   'signature_source' => [ header|param, extract, encoding ],
	 *   'timestamp_source' => [ header|param, extract, format ],  // optional
	 *   'id_source'        => [ header|param, extract ],          // optional
	 *   'tolerance_seconds'=> 300,
	 *   'secrets'          => [ [ 'id' => '...', 'value' => '...', 'expires_at' => '...' ], ... ],
	 *   'max_body_bytes'   => 1048576,
	 * ]
	 * ```
	 *
	 * @param string               $raw_body     Raw request body bytes (as signed).
	 * @param array<string,string> $headers      Lower-case-keyed headers.
	 * @param array<string,mixed>  $query_params Query string parameters.
	 * @param array<string,mixed>  $post_params  Form-encoded body parameters.
	 * @param string               $url          Full request URL.
	 * @param array                $config       Template config.
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

		$headers = self::lower_case_keys( $headers );
		$now     = $now ?? time();
		$mode    = $config['mode'] ?? 'hmac';

		// Non-HMAC primitives dispatch to a pluggable mode class. No provider
		// names live here — the filter decides what modes exist.
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

		// Body-size cap — cheap pre-crypto check.
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

		// Optional id extraction for `{id}` placeholder.
		$id_value = '';
		if ( ! empty( $config['id_source'] ) && is_array( $config['id_source'] ) ) {
			$id_read  = self::read_source( $config['id_source'], $headers, $query_params, $post_params );
			$id_value = $id_read['extracted'];
		}

		$template = $config['signed_template'] ?? '{body}';
		if ( ! is_string( $template ) || '' === $template ) {
			return WebhookVerificationResult::fail( WebhookVerificationResult::MALFORMED_TEMPLATE );
		}

		$rendered = self::render_template(
			$template,
			array(
				'body'      => $raw_body,
				'timestamp' => null !== $timestamp ? (string) $timestamp : '',
				'id'        => $id_value,
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

		// Try every active secret — first match wins. Timing-safe via hash_equals.
		foreach ( $secrets as $secret ) {
			if ( '' === $secret['value'] ) {
				continue;
			}
			$expected = hash_hmac( $algo, $rendered, $secret['value'], true );
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
	 * Render a signed-message template. Placeholders: {body}, {timestamp},
	 * {id}, {url}, {header:<name>}, {param:<name>}. Unknown placeholders
	 * return null (caller surfaces MALFORMED_TEMPLATE).
	 *
	 * @return string|null
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
						return (string) ( $headers[ strtolower( $arg ) ] ?? '' );
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
	 * Read a source descriptor (signature / timestamp / id).
	 *
	 * extract.kind:
	 *   raw       (default) — whole value
	 *   prefix    — strip extract.key; empty if prefix absent
	 *   kv_pairs  — split on extract.separator, return value for extract.key
	 *   regex     — PCRE pattern; capture group 1 (or full match)
	 *
	 * @return array{raw:?string, extracted:string, detail:?string}
	 */
	private static function read_source( array $source, array $headers, array $query_params, array $post_params ): array {
		$raw = null;

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

		return array(
			'raw'       => $raw,
			'extracted' => self::apply_extract( $raw, $source['extract'] ?? array() ),
			'detail'    => null,
		);
	}

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
				if ( '' === $key || '' === $separator ) {
					return '';
				}
				$pair_sep = (string) ( $rule['pair_separator'] ?? '=' );
				foreach ( explode( $separator, $raw ) as $piece ) {
					$piece = trim( $piece );
					if ( '' === $piece ) {
						continue;
					}
					$eq = strpos( $piece, $pair_sep );
					if ( false === $eq ) {
						continue;
					}
					$k = substr( $piece, 0, $eq );
					if ( $k === $key ) {
						return trim( substr( $piece, $eq + strlen( $pair_sep ) ) );
					}
				}
				return '';

			case 'regex':
				$pattern = (string) ( $rule['pattern'] ?? '' );
				if ( '' === $pattern ) {
					return '';
				}
				// User patterns can be invalid — swallow warnings, treat as no match.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
				set_error_handler(
					static function () {
						return true;
					}
				);
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
	 * Decoding + timestamp parsing + secret lifecycle
	 * ================================================================= */

	private static function decode_signature( string $sig, string $encoding ): ?string {
		$sig = trim( $sig );
		switch ( $encoding ) {
			case 'hex':
				if ( ! ctype_xdigit( $sig ) || 0 !== strlen( $sig ) % 2 ) {
					return null;
				}
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

	/**
	 * Filter + normalise the active secrets list. Accepts:
	 * - `[ [ 'id' => '...', 'value' => '...', 'expires_at' => '...' ], ... ]` (canonical)
	 * - `[ 'raw-secret' ]`        (flat list; auto-id'd)
	 * - `'raw-secret'`            (single value)
	 * Entries with `expires_at` in the past are dropped.
	 *
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

	private static function lower_case_keys( array $in ): array {
		$out = array();
		foreach ( $in as $k => $v ) {
			$out[ strtolower( (string) $k ) ] = $v;
		}
		return $out;
	}
}
