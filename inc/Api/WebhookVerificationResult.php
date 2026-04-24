<?php
/**
 * Webhook verification result value object.
 *
 * Returned by {@see \DataMachine\Api\WebhookVerifier::verify()}. Kept as a
 * dedicated class so callers can rely on property names and IDE tooling can
 * type-check them.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 * @see https://github.com/Extra-Chill/data-machine/issues/1179
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookVerificationResult {

	/** Verification outcomes. */
	const OK                 = 'ok';
	const BAD_SIGNATURE      = 'bad_signature';
	const MISSING_HEADER     = 'missing_header';
	const MISSING_SIGNATURE  = 'missing_signature';
	const MISSING_TIMESTAMP  = 'missing_timestamp';
	const STALE_TIMESTAMP    = 'stale_timestamp';
	const NO_ACTIVE_SECRET   = 'no_active_secret';
	const PAYLOAD_TOO_LARGE  = 'payload_too_large';
	const MALFORMED_TEMPLATE = 'malformed_template';
	const MALFORMED_CONFIG   = 'malformed_config';
	const UNKNOWN_MODE       = 'unknown_mode';

	/** @var bool Whether verification succeeded. */
	public bool $ok;

	/** @var string Outcome code — see class constants. */
	public string $reason;

	/** @var string|null ID of the secret that matched (for logging / rotation audit). */
	public ?string $secret_id;

	/** @var int|null Extracted timestamp in unix seconds, if `timestamp_source` is configured. */
	public ?int $timestamp;

	/** @var int|null Absolute skew in seconds from "now" at verification time. */
	public ?int $skew_seconds;

	/** @var string|null Free-form diagnostic detail, safe for logs (no secrets/signatures). */
	public ?string $detail;

	public function __construct(
		bool $ok,
		string $reason,
		?string $secret_id = null,
		?int $timestamp = null,
		?int $skew_seconds = null,
		?string $detail = null
	) {
		$this->ok           = $ok;
		$this->reason       = $reason;
		$this->secret_id    = $secret_id;
		$this->timestamp    = $timestamp;
		$this->skew_seconds = $skew_seconds;
		$this->detail       = $detail;
	}

	public static function ok( ?string $secret_id = null, ?int $timestamp = null, ?int $skew = null ): self {
		return new self( true, self::OK, $secret_id, $timestamp, $skew );
	}

	public static function fail( string $reason, ?string $detail = null, ?int $timestamp = null, ?int $skew = null ): self {
		return new self( false, $reason, null, $timestamp, $skew, $detail );
	}
}
