<?php
/**
 * Signed pending-action approval URLs.
 *
 * @package DataMachine\Engine\AI\Actions
 */

namespace DataMachine\Engine\AI\Actions;

use AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision;
use AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status;
use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

final class SignPendingActionResolutionAbility {

	private const OPTION_SECRET        = 'datamachine_pending_action_resolution_secret';
	private const DEFAULT_LIFETIME     = 604800;
	private const MAX_TOKEN_LIFETIME   = 2592000;
	private const DEFAULT_RESOLVER     = 'signed_pending_action_url';
	private const TOKEN_VERSION        = 1;
	private const RESOLVE_ROUTE        = '/actions/resolve-by-token';
	private const TOKEN_QUERY_ARG      = 't';
	private const HTML_RESPONSE_FILTER = 'datamachine_pending_action_resolution_token_html';

	/** @var bool */
	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_ability();
		$this->register_rest_route();
		$this->register_html_response_sender();
		self::$registered = true;
	}

	/**
	 * Register the signing ability.
	 */
	private function register_ability(): void {
		$register = function () {
			wp_register_ability(
				'datamachine/sign-pending-action-resolution',
				array(
					'label'               => __( 'Sign Pending Action Resolution', 'data-machine' ),
					'description'         => __( 'Create signed approve and reject URLs for a pending action.', 'data-machine' ),
					'category'            => 'datamachine-actions',
					'input_schema'        => self::input_schema(),
					'output_schema'       => self::output_schema(),
					'execute_callback'    => array( self::class, 'execute' ),
					'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- External WordPress Abilities API registration hook.
		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- External WordPress Abilities API registration hook.
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- External WordPress Abilities API registration hook.
			add_action( 'wp_abilities_api_init', $register );
		}
	}

	/**
	 * Register the public resolution endpoint.
	 */
	private function register_rest_route(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core REST API registration hook.
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'datamachine/v1',
					self::RESOLVE_ROUTE,
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'handle_rest' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							self::TOKEN_QUERY_ARG => array(
								'required'          => true,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					)
				);
			}
		);
	}

	/**
	 * Serve browser confirmation responses as raw HTML instead of JSON strings.
	 */
	private function register_html_response_sender(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core REST response filter.
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, $result, \WP_REST_Request $request ): bool {
				if ( '/datamachine/v1' . self::RESOLVE_ROUTE !== $request->get_route() || ! $result instanceof \WP_REST_Response ) {
					return $served;
				}

				$data = $result->get_data();
				if ( ! is_string( $data ) ) {
					return $served;
				}

				$headers      = $result->get_headers();
				$content_type = (string) ( $headers['Content-Type'] ?? $headers['content-type'] ?? '' );
				if ( ! str_contains( strtolower( $content_type ), 'text/html' ) ) {
					return $served;
				}

				status_header( $result->get_status() );
				foreach ( $headers as $header => $value ) {
					header( $header . ': ' . $value );
				}

				echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML is assembled in html_response().
				return true;
			},
			10,
			3
		);
	}

	/**
	 * Ability callback: create signed approve/reject URLs.
	 */
	public static function execute( array $input ): array {
		$action_id = isset( $input['action_id'] ) ? sanitize_text_field( (string) $input['action_id'] ) : '';
		if ( '' === $action_id ) {
			return array(
				'success' => false,
				'error'   => 'action_id is required.',
			);
		}

		$action = PendingActionStore::get( $action_id );
		if ( null === $action ) {
			return array(
				'success'   => false,
				'error'     => 'Pending action not found or already resolved.',
				'action_id' => $action_id,
			);
		}

		$lifetime   = self::normalize_lifetime( $input['lifetime'] ?? self::DEFAULT_LIFETIME );
		$expires_at = time() + $lifetime;
		$resolver   = isset( $input['resolver'] ) ? sanitize_text_field( (string) $input['resolver'] ) : self::DEFAULT_RESOLVER;
		$resolver   = '' !== $resolver ? $resolver : self::DEFAULT_RESOLVER;

		return array(
			'success'     => true,
			'action_id'   => $action_id,
			'approve_url' => self::url_for_decision( $action_id, WP_Agent_Approval_Decision::ACCEPTED, $expires_at, $resolver ),
			'reject_url'  => self::url_for_decision( $action_id, WP_Agent_Approval_Decision::REJECTED, $expires_at, $resolver ),
			'expires_at'  => gmdate( 'c', $expires_at ),
		);
	}

	/**
	 * Public REST callback for signed URL resolution.
	 */
	public static function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$result = self::resolve_token( (string) $request->get_param( self::TOKEN_QUERY_ARG ) );
		$status = (int) ( $result['status_code'] ?? ( ! empty( $result['success'] ) ? 200 : 400 ) );

		unset( $result['status_code'] );

		if ( self::request_wants_json( $request ) ) {
			return new \WP_REST_Response( $result, $status );
		}

		$response = new \WP_REST_Response( self::html_response( $result, $status ), $status );
		$response->header( 'Content-Type', 'text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		return $response;
	}

	/**
	 * Resolve a signed token and return a structured result.
	 */
	public static function resolve_token( string $token ): array {
		$payload = self::verify_token( $token );
		if ( is_wp_error( $payload ) ) {
			return array(
				'success'     => false,
				'error'       => $payload->get_error_message(),
				'status_code' => 'expired_token' === $payload->get_error_code() ? 410 : 400,
			);
		}

		$action_id = sanitize_text_field( (string) ( $payload['action_id'] ?? '' ) );
		$decision  = sanitize_text_field( (string) ( $payload['decision'] ?? '' ) );
		$resolver  = sanitize_text_field( (string) ( $payload['resolver'] ?? self::DEFAULT_RESOLVER ) );

		$existing = PendingActionStore::inspect( $action_id );
		if ( null === $existing ) {
			return array(
				'success'     => false,
				'error'       => 'Pending action not found or expired.',
				'action_id'   => $action_id,
				'status_code' => 410,
			);
		}

		$status = (string) ( $existing['status'] ?? WP_Agent_Pending_Action_Status::PENDING );
		if ( WP_Agent_Pending_Action_Status::PENDING !== $status ) {
			if ( in_array( $status, array( WP_Agent_Approval_Decision::ACCEPTED, WP_Agent_Approval_Decision::REJECTED ), true ) ) {
				return array(
					'success'          => true,
					'already_resolved' => true,
					'decision'         => $status,
					'action_id'        => $action_id,
					'kind'             => (string) ( $existing['kind'] ?? '' ),
					'status_code'      => 200,
				);
			}

			return array(
				'success'     => false,
				'error'       => 'Pending action is no longer resolvable.',
				'action_id'   => $action_id,
				'decision'    => $status,
				'status_code' => 410,
			);
		}

		$result                = ResolvePendingActionAbility::execute(
			array(
				'action_id' => $action_id,
				'decision'  => $decision,
				'resolver'  => '' !== $resolver ? $resolver : self::DEFAULT_RESOLVER,
				'context'   => array( 'resolution_transport' => 'signed_url' ),
			)
		);
		$result['status_code'] = ! empty( $result['success'] ) ? 200 : 400;

		return $result;
	}

	/**
	 * Rotate the HMAC secret. Existing signed URLs become invalid.
	 */
	public static function rotate_secret(): string {
		$secret = self::new_secret();
		update_option( self::OPTION_SECRET, $secret, false );
		return $secret;
	}

	private static function url_for_decision( string $action_id, string $decision, int $expires_at, string $resolver ): string {
		return add_query_arg(
			self::TOKEN_QUERY_ARG,
			self::sign_payload(
				array(
					'v'          => self::TOKEN_VERSION,
					'action_id'  => $action_id,
					'decision'   => $decision,
					'expires_at' => $expires_at,
					'nonce'      => wp_generate_uuid4(),
					'resolver'   => $resolver,
				)
			),
			rest_url( 'datamachine/v1' . self::RESOLVE_ROUTE )
		);
	}

	private static function sign_payload( array $payload ): string {
		$encoded_payload = self::base64url_encode( wp_json_encode( $payload ) );
		$signature       = hash_hmac( 'sha256', $encoded_payload, self::secret(), true );

		return $encoded_payload . '.' . self::base64url_encode( $signature );
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function verify_token( string $token ) {
		$token = trim( $token );
		if ( '' === $token || ! str_contains( $token, '.' ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token is invalid.' );
		}

		list( $encoded_payload, $encoded_signature ) = explode( '.', $token, 2 );
		$expected_signature                          = self::base64url_encode( hash_hmac( 'sha256', $encoded_payload, self::secret(), true ) );
		if ( ! hash_equals( $expected_signature, $encoded_signature ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token signature is invalid.' );
		}

		$decoded = self::base64url_decode( $encoded_payload );
		$payload = json_decode( $decoded, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token payload is invalid.' );
		}

		if ( self::TOKEN_VERSION !== (int) ( $payload['v'] ?? 0 ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token version is unsupported.' );
		}

		if ( time() > (int) ( $payload['expires_at'] ?? 0 ) ) {
			return new \WP_Error( 'expired_token', 'Resolution token has expired.' );
		}

		$decision = (string) ( $payload['decision'] ?? '' );
		if ( ! in_array( $decision, array( WP_Agent_Approval_Decision::ACCEPTED, WP_Agent_Approval_Decision::REJECTED ), true ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token decision is invalid.' );
		}

		if ( '' === (string) ( $payload['action_id'] ?? '' ) ) {
			return new \WP_Error( 'invalid_token', 'Resolution token action is invalid.' );
		}

		return $payload;
	}

	private static function secret(): string {
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}

		return self::rotate_secret();
	}

	private static function new_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	private static function normalize_lifetime( $lifetime ): int {
		$lifetime = (int) $lifetime;
		if ( $lifetime <= 0 ) {
			$lifetime = self::DEFAULT_LIFETIME;
		}

		return min( $lifetime, self::MAX_TOKEN_LIFETIME );
	}

	private static function request_wants_json( \WP_REST_Request $request ): bool {
		$accept = (string) $request->get_header( 'accept' );
		return str_contains( strtolower( $accept ), 'application/json' );
	}

	private static function html_response( array $result, int $status ): string {
		$title   = ! empty( $result['success'] ) ? __( 'Pending action resolved', 'data-machine' ) : __( 'Pending action not resolved', 'data-machine' );
		$message = ! empty( $result['success'] )
			? sprintf( 'Action %s was %s.', (string) ( $result['action_id'] ?? '' ), (string) ( $result['decision'] ?? 'resolved' ) )
			: (string) ( $result['error'] ?? 'The pending action could not be resolved.' );

		$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head><body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></main></body></html>';

		return (string) apply_filters( self::HTML_RESPONSE_FILTER, $html, $result, $status );
	}

	private static function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token payloads use standard base64url encoding.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $value ): string {
		$padding = strlen( $value ) % 4;
		if ( $padding > 0 ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token payloads use standard base64url decoding.
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}

	private static function input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'action_id' ),
			'properties' => array(
				'action_id' => array(
					'type'        => 'string',
					'description' => __( 'The pending action identifier.', 'data-machine' ),
				),
				'lifetime'  => array(
					'type'        => 'integer',
					'description' => __( 'Signed URL lifetime in seconds. Defaults to 7 days and is capped at 30 days.', 'data-machine' ),
				),
				'resolver'  => array(
					'type'        => 'string',
					'description' => __( 'Resolver identifier recorded on successful resolution.', 'data-machine' ),
				),
			),
		);
	}

	private static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'     => array( 'type' => 'boolean' ),
				'action_id'   => array( 'type' => 'string' ),
				'approve_url' => array( 'type' => 'string' ),
				'reject_url'  => array( 'type' => 'string' ),
				'expires_at'  => array( 'type' => 'string' ),
				'error'       => array( 'type' => 'string' ),
			),
		);
	}
}
