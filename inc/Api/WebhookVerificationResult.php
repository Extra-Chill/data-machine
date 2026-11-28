<?php
/**
 * Webhook verification result.
 *
 * Plain value object returned by {@see \DataMachine\Api\WebhookVerifier::verify()}.
 *
 * @package DataMachine\Api
 * @since 0.79.0
 */

namespace DataMachine\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhookVerificationResult {

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

	public bool $ok;
	public string $reason;
	public ?string $secret_id;
	public ?int $timestamp;
	public ?int $skew_seconds;
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
