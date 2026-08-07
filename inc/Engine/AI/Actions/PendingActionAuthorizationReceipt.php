<?php
/**
 * Generic, handler-facing authorization receipt for an applying pending action.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

defined( 'ABSPATH' ) || exit;

final class PendingActionAuthorizationReceipt {

	private const OPTION_SECRET = 'datamachine_pending_action_receipt_secret';
	private static ?string $transient_secret = null;

	/** Issue a signed receipt from an atomically claimed action payload. */
	public static function issue( array $action, string $resolver ): array {
		$authorization = self::authorization( $action );
		$claims        = array(
			'action_id'    => (string) $action['action_id'],
			'kind'         => (string) $action['kind'],
			'operation'    => $authorization['operation'],
			'target_digest'=> self::digest( $authorization['target'] ),
			'input_digest' => self::digest( $action['apply_input'] ?? array() ),
			'subject'      => (string) ( $action['agent'] ?? $action['creator'] ?? '' ),
			'workspace'    => $action['workspace'] ?? null,
			'resolver'     => $resolver,
			'issued_at'    => time(),
			'expires_at'   => (int) ( $action['expires_at'] ?? 0 ),
			'nonce'        => (string) ( $action['receipt_nonce'] ?? '' ),
		);

		$encoded = self::base64url_encode( wp_json_encode( $claims ) );
		return array( 'token' => $encoded . '.' . self::base64url_encode( hash_hmac( 'sha256', $encoded, self::secret(), true ) ), 'claims' => $claims );
	}

	/**
	 * Validate that a receipt still authorizes this exact handler operation.
	 * A claimed action is the single-use boundary; terminal actions invalidate it.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate( $receipt, string $kind, string $operation, $target, array $input, string $subject, array $workspace ): true|\WP_Error {
		$token = is_array( $receipt ) ? (string) ( $receipt['token'] ?? '' ) : (string) $receipt;
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( self::base64url_encode( hash_hmac( 'sha256', $parts[0], self::secret(), true ) ), $parts[1] ) ) {
			return new \WP_Error( 'invalid_authorization_receipt', 'Authorization receipt is invalid.' );
		}

		$claims = json_decode( self::base64url_decode( $parts[0] ), true );
		if ( ! is_array( $claims ) || (int) ( $claims['expires_at'] ?? 0 ) <= time() ) {
			return new \WP_Error( 'authorization_receipt_expired', 'Authorization receipt has expired.' );
		}
		if ( (string) ( $claims['kind'] ?? '' ) !== $kind || (string) ( $claims['operation'] ?? '' ) !== $operation || (string) ( $claims['target_digest'] ?? '' ) !== self::digest( $target ) || (string) ( $claims['input_digest'] ?? '' ) !== self::digest( $input ) || (string) ( $claims['subject'] ?? '' ) !== $subject || self::digest( $claims['workspace'] ?? null ) !== self::digest( $workspace ) ) {
			return new \WP_Error( 'authorization_receipt_mismatch', 'Authorization receipt does not match this kind, operation, target, input, subject, or workspace.' );
		}

		$action = PendingActionStore::inspect( (string) ( $claims['action_id'] ?? '' ) );
		if ( null === $action || 'applying' !== (string) ( $action['status'] ?? '' ) || ! hash_equals( (string) ( $action['receipt_nonce'] ?? '' ), (string) ( $claims['nonce'] ?? '' ) ) || (string) ( $action['kind'] ?? '' ) !== (string) ( $claims['kind'] ?? '' ) || (string) ( $action['resolver'] ?? '' ) !== (string) ( $claims['resolver'] ?? '' ) || self::digest( $action['workspace'] ?? null ) !== self::digest( $claims['workspace'] ?? null ) || (string) ( $action['agent'] ?? $action['creator'] ?? '' ) !== (string) ( $claims['subject'] ?? '' ) ) {
			return new \WP_Error( 'authorization_receipt_consumed', 'Authorization receipt has been consumed or is no longer valid.' );
		}

		return true;
	}

	/** Normalize the generic authorization binding, including legacy rows. */
	public static function authorization( array $action ): array {
		$metadata      = is_array( $action['metadata'] ?? null ) ? $action['metadata'] : array();
		$authorization = is_array( $metadata['datamachine']['authorization'] ?? null ) ? $metadata['datamachine']['authorization'] : array();
		return array(
			'operation' => (string) ( $authorization['operation'] ?? $action['kind'] ?? '' ),
			'target'    => $authorization['target'] ?? ( $action['apply_input'] ?? array() ),
		);
	}

	public static function digest( $value ): string {
		return hash( 'sha256', wp_json_encode( self::sort_value( $value ) ) );
	}

	private static function sort_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_value( $item );
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}

	private static function secret(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			self::$transient_secret ??= bin2hex( random_bytes( 32 ) );
			return self::$transient_secret;
		}
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( self::OPTION_SECRET, $secret, false );
		return $secret;
	}

	private static function base64url_encode( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
	private static function base64url_decode( string $value ): string { return (string) base64_decode( strtr( $value, '-_', '+/' ) ); }
}
