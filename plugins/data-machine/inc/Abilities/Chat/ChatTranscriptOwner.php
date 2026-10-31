<?php
/**
 * Transcript owner adapter for chat sessions.
 *
 * @package DataMachine\Abilities\Chat
 */

namespace DataMachine\Abilities\Chat;

use DataMachine\Abilities\PermissionHelper;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the transcript owner separately from the runtime execution user.
 *
 * This is intentionally a Data Machine-local adapter while Agents API issue
 * #174 is open. The only non-user prototype shape accepted here is an explicit
 * browser/principal key supplied as `transcript_owner` or
 * `client_context.transcript_owner`.
 */
class ChatTranscriptOwner {

	/**
	 * Resolve a transcript owner for the current request.
	 *
	 * @param array $input Canonical/chat input or options.
	 * @param int   $fallback_user_id Runtime user fallback for logged-in compatibility.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}|WP_Error
	 */
	public static function resolve_for_request( array $input = array(), int $fallback_user_id = 0 ): array|WP_Error {
		$explicit = self::extract_explicit_owner( $input );
		if ( null !== $explicit ) {
			return self::normalize_explicit_owner( $explicit, $fallback_user_id );
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			return self::user_owner( $current_user_id );
		}

		$acting_user_id = PermissionHelper::acting_user_id();
		if ( $acting_user_id > 0 && $acting_user_id === $fallback_user_id && is_user_logged_in() ) {
			return self::user_owner( $acting_user_id );
		}

		return new WP_Error(
			'transcript_owner_required',
			__( 'A logged-in user or explicit browser transcript owner is required for persistent chat sessions.', 'data-machine' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build owner columns from a stored user ID for migration/back-compat.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}
	 */
	public static function user_owner( int $user_id ): array {
		$user_id = absint( $user_id );

		return self::from_key( 'user', 'user:' . $user_id, 'User ' . $user_id, $user_id );
	}

	/**
	 * Hash a stable owner key for storage.
	 *
	 * @param string $owner_key Stable owner key.
	 * @return string
	 */
	public static function hash_owner_key( string $owner_key ): string {
		return hash( 'sha256', $owner_key );
	}

	/**
	 * Extract a prototype owner from supported local input positions.
	 *
	 * @param array $input Request input.
	 * @return array|null
	 */
	private static function extract_explicit_owner( array $input ): ?array {
		if ( isset( $input['transcript_owner'] ) && is_array( $input['transcript_owner'] ) ) {
			return $input['transcript_owner'];
		}

		if ( isset( $input['session_owner'] ) && is_array( $input['session_owner'] ) ) {
			return $input['session_owner'];
		}

		$client_context = is_array( $input['client_context'] ?? null ) ? $input['client_context'] : array();
		if ( isset( $client_context['transcript_owner'] ) && is_array( $client_context['transcript_owner'] ) ) {
			return $client_context['transcript_owner'];
		}

		if ( isset( $client_context['session_owner'] ) && is_array( $client_context['session_owner'] ) ) {
			return $client_context['session_owner'];
		}

		return null;
	}

	/**
	 * Normalize a local prototype owner shape.
	 *
	 * @param array $owner            Explicit owner input.
	 * @param int   $fallback_user_id Runtime user fallback.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}|WP_Error
	 */
	private static function normalize_explicit_owner( array $owner, int $fallback_user_id ): array|WP_Error {
		$type  = sanitize_key( (string) ( $owner['owner_type'] ?? $owner['type'] ?? '' ) );
		$key   = trim( (string) ( $owner['owner_key'] ?? $owner['key'] ?? $owner['principal_key'] ?? '' ) );
		$label = sanitize_text_field( (string) ( $owner['owner_label'] ?? $owner['label'] ?? '' ) );

		if ( 'user' === $type ) {
			$user_id = absint( $owner['user_id'] ?? preg_replace( '/^user:/', '', $key ) );
			return $user_id > 0 ? self::user_owner( $user_id ) : new WP_Error( 'invalid_transcript_owner', __( 'Invalid user transcript owner.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( '' === $type || '' === $key ) {
			return new WP_Error( 'invalid_transcript_owner', __( 'Transcript owner type and key are required.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( 'audience' === $type && in_array( $key, array( 'public', 'audience:public' ), true ) ) {
			return new WP_Error( 'non_isolating_transcript_owner', __( 'Public audience is not an isolating transcript owner.', 'data-machine' ), array( 'status' => 400 ) );
		}

		if ( '' === $label ) {
			$label = $type;
		}

		return self::from_key( $type, $type . ':' . $key, $label, $fallback_user_id );
	}

	/**
	 * Build a normalized owner array from a stable key.
	 *
	 * @param string $type    Owner type.
	 * @param string $key     Stable owner key.
	 * @param string $label   Human-readable label.
	 * @param int    $user_id Compatibility/reporting user ID.
	 * @return array{owner_type:string,owner_key:string,owner_key_hash:string,owner_label:string,user_id:int}
	 */
	private static function from_key( string $type, string $key, string $label, int $user_id ): array {
		return array(
			'owner_type'     => sanitize_key( $type ),
			'owner_key'      => $key,
			'owner_key_hash' => self::hash_owner_key( $key ),
			'owner_label'    => mb_substr( sanitize_text_field( $label ), 0, 191 ),
			'user_id'        => absint( $user_id ),
		);
	}
}
