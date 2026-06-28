<?php
/**
 * Agent Authorization Flow
 *
 * Browser-based OAuth-style flow for agents to obtain bearer tokens.
 * Works like GitHub's device authorization — the agent opens a URL,
 * the human approves, and the token is returned via redirect.
 *
 * Flow:
 * 1. Agent opens: /wp-json/datamachine/v1/agent/authorize?agent_slug=X&redirect_uri=Y
 * 2. If user not logged in → redirect to wp-login → back to authorize
 * 3. User sees consent screen: "Agent X wants access"
 * 4. User clicks Authorize → token minted → redirect to redirect_uri?token=Z
 * 5. User clicks Deny → redirect to redirect_uri?error=denied
 *
 * Security:
 * - User must be logged in (WordPress session/cookie)
 * - User must have access to the agent (owner or granted access)
 * - CSRF protection via WordPress nonce
 * - redirect_uri validated against allowlist or localhost
 *
 * @package DataMachine\Core\Auth
 * @since 0.56.0
 */

namespace DataMachine\Core\Auth;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Agents\AgentAccess;
use DataMachine\Core\Database\Agents\AgentTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AgentAuthorize {

	/**
	 * Nonce action for authorize form.
	 */
	const NONCE_ACTION = 'datamachine_agent_authorize';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes for the authorize flow.
	 */
	public function register_routes(): void {
		// GET: Show consent screen (or redirect to login).
		register_rest_route(
			'datamachine/v1',
			'/agent/authorize',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_authorize_get' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'agent_slug'            => array(
						'required' => true,
						'type'     => 'string',
					),
					'redirect_uri'          => array(
						'required' => true,
						'type'     => 'string',
					),
					'label'                 => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'code_challenge'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'code_challenge_method' => array(
						'required' => false,
						'type'     => 'string',
					),
					'state'                 => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		// POST: Handle authorize/deny submission.
		register_rest_route(
			'datamachine/v1',
			'/agent/authorize',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_authorize_post' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'agent_slug'            => array(
						'required' => true,
						'type'     => 'string',
					),
					'redirect_uri'          => array(
						'required' => true,
						'type'     => 'string',
					),
					'label'                 => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'action'                => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'authorize', 'deny' ),
					),
					'scope_preset'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'_authorize_nonce'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'code_challenge'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'code_challenge_method' => array(
						'required' => false,
						'type'     => 'string',
					),
					'state'                 => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Handle GET request — show consent screen or redirect to login.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|void Response or redirect.
	 */
	public function handle_authorize_get( \WP_REST_Request $request ) {
		// This is a browser-facing endpoint (user opens it in their browser),
		// not a typical REST API call. WordPress REST API doesn't validate
		// cookies without an X-WP-Nonce header, so we manually validate
		// the logged-in cookie to detect the user's session.
		if ( ! is_user_logged_in() && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$logged_in_cookie = sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) );
			$user_id          = wp_validate_auth_cookie( $logged_in_cookie, 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		$agent_slug            = sanitize_text_field( $request->get_param( 'agent_slug' ) );
		$redirect_uri          = esc_url_raw( $request->get_param( 'redirect_uri' ) );
		$label                 = sanitize_text_field( $request->get_param( 'label' ) );
		$code_challenge        = sanitize_text_field( $request->get_param( 'code_challenge' ) );
		$code_challenge_method = sanitize_text_field( $request->get_param( 'code_challenge_method' ) );
		$state                 = sanitize_text_field( $request->get_param( 'state' ) );

		// Look up the agent first — we need it for redirect URI validation.
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $agent_slug );

		if ( ! $agent ) {
			return new \WP_Error(
				'agent_not_found',
				sprintf( 'Agent "%s" not found.', $agent_slug ),
				array( 'status' => 404 )
			);
		}

		// Validate redirect_uri against agent's allowed URIs.
		$uri_error = $this->validate_redirect_uri( $redirect_uri, $agent );
		if ( $uri_error ) {
			return $uri_error;
		}

		// If not logged in, redirect to wp-login with return URL.
		if ( ! is_user_logged_in() ) {
			$authorize_url = rest_url( 'datamachine/v1/agent/authorize' );
			$query_args    = array(
				'agent_slug'            => $agent_slug,
				'redirect_uri'          => rawurlencode( $redirect_uri ),
				'label'                 => $label,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'state'                 => $state,
			);
			// Remove empty PKCE params so the URL stays clean for non-PKCE flows.
			$query_args    = array_filter( $query_args, function ( $v ) {
				return '' !== $v;
			} );
			$authorize_url = add_query_arg( $query_args, $authorize_url );

			$login_url = wp_login_url( $authorize_url );

			header( 'Location: ' . $login_url );
			exit;
		}

		// Check user has access to this agent.
		$user_id = get_current_user_id();

		if ( ! $this->user_can_authorize( $user_id, $agent ) ) {
			return new \WP_Error(
				'access_denied',
				'You do not have access to this agent.',
				array( 'status' => 403 )
			);
		}

		// Render consent screen.
		$this->render_consent_screen( $agent, $redirect_uri, $label, $code_challenge, $code_challenge_method, $state );
		exit;
	}

	/**
	 * Handle POST request — process authorize or deny.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return void Redirects to redirect_uri.
	 */
	public function handle_authorize_post( \WP_REST_Request $request ) {
		// Same browser cookie validation as the GET handler.
		if ( ! is_user_logged_in() && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			$logged_in_cookie = sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) );
			$user_id          = wp_validate_auth_cookie( $logged_in_cookie, 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		$agent_slug   = sanitize_text_field( $request->get_param( 'agent_slug' ) );
		$redirect_uri = esc_url_raw( $request->get_param( 'redirect_uri' ) );
		$label        = sanitize_text_field( $request->get_param( 'label' ) );
		$action       = sanitize_text_field( $request->get_param( 'action' ) );
		$scope_key    = sanitize_key( (string) $request->get_param( 'scope_preset' ) );
		$nonce        = $request->get_param( '_authorize_nonce' );

		// Look up agent for redirect URI validation.
		$agents_repo   = new Agents();
		$agent_for_uri = $agents_repo->get_by_slug( $agent_slug );

		if ( $agent_for_uri ) {
			$uri_error = $this->validate_redirect_uri( $redirect_uri, $agent_for_uri );
			if ( $uri_error ) {
				return $uri_error;
			}
		}

		// Must be logged in.
		if ( ! is_user_logged_in() ) {
			header( 'Location: ' . add_query_arg( 'error', 'not_authenticated', $redirect_uri ) );
			exit;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			header( 'Location: ' . add_query_arg( 'error', 'invalid_nonce', $redirect_uri ) );
			exit;
		}

		// Handle deny.
		if ( 'deny' === $action ) {
			header( 'Location: ' . add_query_arg( 'error', 'access_denied', $redirect_uri ) );
			exit;
		}

		// Look up agent.
		$agents_repo = new Agents();
		$agent       = $agents_repo->get_by_slug( $agent_slug );

		if ( ! $agent ) {
			header( 'Location: ' . add_query_arg( 'error', 'agent_not_found', $redirect_uri ) );
			exit;
		}

		// Verify access.
		$user_id = get_current_user_id();
		if ( ! $this->user_can_authorize( $user_id, $agent ) ) {
			header( 'Location: ' . add_query_arg( 'error', 'access_denied', $redirect_uri ) );
			exit;
		}

		$scope_presets = $this->get_scope_presets( $agent, $redirect_uri );
		if ( '' === $scope_key ) {
			$scope_key = $this->get_default_scope_key( $agent, $redirect_uri, $scope_presets );
		}

		if ( ! isset( $scope_presets[ $scope_key ] ) ) {
			header( 'Location: ' . add_query_arg( 'error', 'invalid_scope', $redirect_uri ) );
			exit;
		}

		$scope_payload = $this->scope_payload_from_preset( $scope_key, $scope_presets[ $scope_key ] );

		/**
		 * Filter: Allow extensions to intercept the authorize flow before token minting.
		 *
		 * Return null to proceed with default token minting. Return a WP_Error to
		 * abort. Return a string URL to redirect immediately (for PKCE auth code flow).
		 *
		 * @since 0.64.0
		 *
		 * @param string|null       $redirect_url  null to proceed, or URL to redirect to.
		 * @param array             $agent         Agent row (agent_id, agent_slug, agent_name, owner_id, agent_config).
		 * @param int               $user_id       Authorizing WordPress user ID.
		 * @param string            $redirect_uri  The redirect_uri from the request.
		 * @param string            $label         Token label.
		 * @param \WP_REST_Request  $request       Full request object (access to all POST params).
		 */
		$redirect_override = apply_filters(
			'datamachine_agent_authorize_pre_token',
			null,
			$agent,
			$user_id,
			$redirect_uri,
			$label,
			$request
		);

		if ( null !== $redirect_override ) {
			if ( is_wp_error( $redirect_override ) ) {
				header( 'Location: ' . add_query_arg( 'error', $redirect_override->get_error_code(), $redirect_uri ) );
				exit;
			}
			header( 'Location: ' . $redirect_override );
			exit;
		}

		// Mint token.
		$tokens_repo = new AgentTokens();
		$token_label = ! empty( $label ) ? $label : 'authorize-flow-' . gmdate( 'Y-m-d' );

		$result = $tokens_repo->create_bearer_token(
			(int) $agent['agent_id'],
			$agent['agent_slug'],
			$token_label,
			$scope_payload,
			null  // No expiry.
		);

		if ( ! $result ) {
			header( 'Location: ' . add_query_arg( 'error', 'token_creation_failed', $redirect_uri ) );
			exit;
		}

		do_action(
			'datamachine_log',
			'info',
			'Agent token issued via authorize flow',
			array(
				'agent_id'   => (int) $agent['agent_id'],
				'agent_slug' => $agent['agent_slug'],
				'user_id'    => $user_id,
				'token_id'   => $result['token_id'],
				'label'      => $token_label,
				'scope'      => $scope_key,
			)
		);

		// Redirect with token.
		$callback_url = add_query_arg(
			array(
				'token'      => $result['raw_token'],
				'agent_slug' => $agent['agent_slug'],
				'agent_id'   => (int) $agent['agent_id'],
			),
			$redirect_uri
		);

		header( 'Location: ' . $callback_url );
		exit;
	}

	/**
	 * Check if a user can authorize token creation for an agent.
	 *
	 * User must be the owner OR have admin-level access grant.
	 *
	 * @param int   $user_id User ID.
	 * @param array $agent   Agent row.
	 * @return bool
	 */
	private function user_can_authorize( int $user_id, array $agent ): bool {
		// Owner can always authorize.
		if ( (int) $agent['owner_id'] === $user_id ) {
			return true;
		}

		// Check access grants.
		$access_repo = new AgentAccess();
		$grant       = $access_repo->get_access( (string) (int) $agent['agent_id'], $user_id );
		return $grant instanceof \WP_Agent_Access_Grant && $grant->role_meets( \WP_Agent_Access_Grant::ROLE_ADMIN );
	}

	/**
	 * Validate redirect_uri against the agent's allowed URIs.
	 *
	 * Always allows: localhost (any port), 127.0.0.1, same-site URLs.
	 * External domains must be registered in the agent's config:
	 * agent_config.allowed_redirect_uris = ["https://example.com/*"]
	 *
	 * This scopes the blast radius per-agent — a compromised agent can only
	 * redirect to its own registered domains, not arbitrary URLs.
	 *
	 * @param string     $uri   Redirect URI.
	 * @param array|null $agent Agent row (with decoded agent_config).
	 * @return \WP_Error|null Error or null if valid.
	 */
	private function validate_redirect_uri( string $uri, ?array $agent = null ): ?\WP_Error {
		if ( empty( $uri ) ) {
			return new \WP_Error(
				'missing_redirect_uri',
				'redirect_uri is required.',
				array( 'status' => 400 )
			);
		}

		$parsed = wp_parse_url( $uri );
		$host   = $parsed['host'] ?? '';

		// Always allow localhost (local agent development).
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return null;
		}

		// Always allow same network (*.extrachill.com or the network domain).
		$site_host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) {
			return null;
		}

		// Check agent's allowed_redirect_uris in agent_config.
		if ( $agent ) {
			$config       = $agent['agent_config'] ?? array();
			$allowed_uris = $config['allowed_redirect_uris'] ?? array();

			foreach ( $allowed_uris as $pattern ) {
				if ( $this->uri_matches_pattern( $uri, $pattern ) ) {
					return null;
				}
			}
		}

		return new \WP_Error(
			'invalid_redirect_uri',
			sprintf(
				'redirect_uri host "%s" is not allowed for agent "%s". Register it in the agent\'s allowed_redirect_uris config.',
				$host,
				$agent['agent_slug'] ?? 'unknown'
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Check if a URI matches an allowed pattern.
	 *
	 * Supports:
	 * - Exact match: "https://example.com/callback"
	 * - Wildcard path: "https://example.com/*"
	 * - Domain-only: "example.com" (matches any path on that domain)
	 *
	 * @param string $uri     The redirect URI to check.
	 * @param string $pattern The allowed pattern.
	 * @return bool
	 */
	private function uri_matches_pattern( string $uri, string $pattern ): bool {
		// Domain-only pattern (no scheme).
		if ( ! str_contains( $pattern, '://' ) ) {
			$parsed = wp_parse_url( $uri );
			$host   = $parsed['host'] ?? '';
			return $host === $pattern || str_ends_with( $host, '.' . $pattern );
		}

		// Wildcard path pattern.
		if ( str_ends_with( $pattern, '/*' ) ) {
			$base = rtrim( substr( $pattern, 0, -2 ), '/' );
			return str_starts_with( rtrim( $uri, '/' ), $base );
		}

		// Exact match.
		return rtrim( $uri, '/' ) === rtrim( $pattern, '/' );
	}

	/**
	 * Return filterable approve-time scope presets.
	 *
	 * Null capabilities on the full preset intentionally preserve the existing
	 * owner-ceiling behavior for users who do not change the selection.
	 *
	 * @param array  $agent        Agent row.
	 * @param string $redirect_uri Redirect URI being authorized.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_scope_presets( array $agent, string $redirect_uri ): array {
		$presets = array(
			'read_only'            => array(
				'label'              => __( 'Read-only', 'data-machine' ),
				'description'        => __( 'Can read content and use read-oriented agent tools. Cannot create, edit, publish, delete, or change settings.', 'data-machine' ),
				'ability_categories' => array( 'datamachine-agent', 'datamachine-content', 'datamachine-memory' ),
				'ability_allow'      => array(),
				'ability_deny'       => array(),
				'capabilities'       => array( 'read', 'datamachine_chat', 'datamachine_use_tools' ),
			),
			'content_collaborator' => array(
				'label'              => __( 'Content collaborator', 'data-machine' ),
				'description'        => __( 'Can draft, edit, and publish owned content through content and memory tools. Cannot manage agents, flows, settings, or logs.', 'data-machine' ),
				'ability_categories' => array( 'datamachine-agent', 'datamachine-content', 'datamachine-memory', 'datamachine-publishing', 'datamachine-media', 'datamachine-seo' ),
				'ability_allow'      => array(),
				'ability_deny'       => array( 'datamachine/delete-flow', 'datamachine/delete-pipeline' ),
				'capabilities'       => array( 'read', 'edit_posts', 'publish_posts', 'upload_files', 'datamachine_chat', 'datamachine_use_tools' ),
			),
			'publisher'            => array(
				'label'              => __( 'Publisher', 'data-machine' ),
				'description'        => __( 'Can perform editor-level publishing work. Cannot manage Data Machine settings, flows, or agent credentials.', 'data-machine' ),
				'ability_categories' => array( 'datamachine-agent', 'datamachine-content', 'datamachine-memory', 'datamachine-publishing', 'datamachine-media', 'datamachine-seo', 'datamachine-taxonomy' ),
				'ability_allow'      => array(),
				'ability_deny'       => array( 'datamachine/delete-flow', 'datamachine/delete-pipeline' ),
				'capabilities'       => array( 'read', 'edit_posts', 'publish_posts', 'edit_others_posts', 'edit_published_posts', 'upload_files', 'datamachine_chat', 'datamachine_use_tools' ),
			),
			'full_owner_ceiling'   => array(
				'label'              => __( 'Full owner ceiling', 'data-machine' ),
				'description'        => __( 'Current behavior: this token can use anything the authorizing user can use. Choose only for trusted runtimes.', 'data-machine' ),
				'ability_categories' => array(),
				'ability_allow'      => array(),
				'ability_deny'       => array(),
				'capabilities'       => null,
			),
		);

		/**
		 * Filter approve-time agent token scope presets.
		 *
		 * Each preset may define label, description, ability_categories,
		 * ability_allow, ability_deny, and capabilities. A null capabilities value
		 * means unrestricted owner ceiling for backwards compatibility.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array<string,mixed>> $presets      Scope presets keyed by slug.
		 * @param array                            $agent        Agent row.
		 * @param string                           $redirect_uri Redirect URI being authorized.
		 */
		$presets = apply_filters( 'datamachine_agent_scope_presets', $presets, $agent, $redirect_uri );

		$normalized = array();
		foreach ( (array) $presets as $key => $preset ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $preset ) ) {
				continue;
			}

			$normalized[ $key ] = array(
				'label'              => sanitize_text_field( (string) ( $preset['label'] ?? $key ) ),
				'description'        => sanitize_text_field( (string) ( $preset['description'] ?? '' ) ),
				'ability_categories' => $this->normalize_string_list( $preset['ability_categories'] ?? array() ),
				'ability_allow'      => $this->normalize_string_list( $preset['ability_allow'] ?? array() ),
				'ability_deny'       => $this->normalize_string_list( $preset['ability_deny'] ?? array() ),
				'capabilities'       => array_key_exists( 'capabilities', $preset ) && null === $preset['capabilities'] ? null : $this->normalize_string_list( $preset['capabilities'] ?? array() ),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<string,array<string,mixed>> $presets Scope presets.
	 */
	private function get_default_scope_key( array $agent, string $redirect_uri, array $presets ): string {
		$config  = $agent['agent_config'] ?? array();
		$default = is_array( $config ) ? sanitize_key( (string) ( $config['default_scope'] ?? '' ) ) : '';

		if ( '' !== $default && isset( $presets[ $default ] ) ) {
			return $default;
		}

		return isset( $presets['full_owner_ceiling'] ) ? 'full_owner_ceiling' : (string) array_key_first( $presets );
	}

	/**
	 * @param array<string,mixed> $preset Scope preset.
	 */
	private function scope_payload_from_preset( string $scope_key, array $preset ): ?array {
		if ( array_key_exists( 'capabilities', $preset ) && null === $preset['capabilities'] ) {
			return null;
		}

		return array(
			'scope'              => $scope_key,
			'label'              => (string) ( $preset['label'] ?? $scope_key ),
			'ability_categories' => $this->normalize_string_list( $preset['ability_categories'] ?? array() ),
			'ability_allow'      => $this->normalize_string_list( $preset['ability_allow'] ?? array() ),
			'ability_deny'       => $this->normalize_string_list( $preset['ability_deny'] ?? array() ),
			'capabilities'       => $this->normalize_string_list( $preset['capabilities'] ?? array() ),
		);
	}

	/**
	 * @param mixed $value Raw list-like value.
	 * @return array<int,string>
	 */
	private function normalize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Render the consent screen HTML.
	 *
	 * Minimal, self-contained page — no admin chrome needed.
	 *
	 * @param array  $agent                  Agent row.
	 * @param string $redirect_uri           Callback URI.
	 * @param string $label                  Optional token label.
	 * @param string $code_challenge         PKCE code challenge (empty if not PKCE).
	 * @param string $code_challenge_method  PKCE method (e.g. 'S256').
	 * @param string $state                  PKCE state parameter.
	 */
	private function render_consent_screen( array $agent, string $redirect_uri, string $label, string $code_challenge = '', string $code_challenge_method = '', string $state = '' ): void {
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$user       = wp_get_current_user();
		$action_url = rest_url( 'datamachine/v1/agent/authorize' );

		$site_name = get_bloginfo( 'name' );

		$agent_name = esc_html( $agent['agent_name'] );
		$agent_slug = esc_html( $agent['agent_slug'] );
		$owner      = get_userdata( (int) $agent['owner_id'] );
		$owner_name = $owner ? esc_html( $owner->display_name ) : 'Unknown';
		$user_name  = esc_html( $user->display_name );

		$parsed_uri  = wp_parse_url( $redirect_uri );
		$uri_display = esc_html( ( $parsed_uri['host'] ?? '' ) . ( isset( $parsed_uri['port'] ) ? ':' . $parsed_uri['port'] : '' ) );
		$presets     = $this->get_scope_presets( $agent, $redirect_uri );
		$default_key = $this->get_default_scope_key( $agent, $redirect_uri, $presets );

		$scope_options = '';
		foreach ( $presets as $key => $preset ) {
			$scope_options .= sprintf(
				'<label class="scope-option"><input type="radio" name="scope_preset" value="%1$s" %2$s><span><strong>%3$s</strong><small>%4$s</small></span></label>',
				esc_attr( $key ),
				checked( $default_key, $key, false ),
				esc_html( (string) ( $preset['label'] ?? $key ) ),
				esc_html( (string) ( $preset['description'] ?? '' ) )
			);
		}

		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Authorize Agent &mdash; ' . esc_html( $site_name ) . '</title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			color: #1d2327;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 2rem;
		}
		.auth-container {
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 1px 3px rgba(0,0,0,0.1);
			max-width: 480px;
			width: 100%;
			padding: 2rem;
		}
		.auth-header {
			text-align: center;
			margin-bottom: 1.5rem;
		}
		.auth-header h1 {
			font-size: 1.25rem;
			font-weight: 600;
			margin-bottom: 0.5rem;
		}
		.auth-header .site-name {
			font-size: 0.875rem;
			color: #646970;
		}
		.agent-info {
			background: #f6f7f7;
			border-radius: 6px;
			padding: 1rem;
			margin-bottom: 1.5rem;
		}
		.agent-info dt {
			font-size: 0.75rem;
			color: #646970;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			margin-bottom: 0.25rem;
		}
		.agent-info dd {
			font-size: 0.9375rem;
			font-weight: 500;
			margin-bottom: 0.75rem;
		}
		.agent-info dd:last-child { margin-bottom: 0; }
		.auth-notice {
			font-size: 0.8125rem;
			color: #646970;
			line-height: 1.5;
			margin-bottom: 1.5rem;
			padding: 0.75rem;
			background: #fcf9e8;
			border-left: 4px solid #dba617;
			border-radius: 0 4px 4px 0;
		}
		.scope-options {
			border: 1px solid #dcdcde;
			border-radius: 6px;
			margin-bottom: 1.5rem;
			overflow: hidden;
		}
		.scope-options legend {
			font-size: 0.75rem;
			font-weight: 700;
			color: #50575e;
			padding: 0 0.5rem;
			margin-left: 0.75rem;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}
		.scope-option {
			display: flex;
			gap: 0.75rem;
			padding: 0.875rem 1rem;
			border-top: 1px solid #dcdcde;
			cursor: pointer;
		}
		.scope-option:first-of-type { border-top: 0; }
		.scope-option:hover { background: #f6f7f7; }
		.scope-option input { margin-top: 0.2rem; }
		.scope-option strong { display: block; font-size: 0.9375rem; margin-bottom: 0.25rem; }
		.scope-option small { display: block; color: #646970; line-height: 1.4; }
		.auth-actions {
			display: flex;
			gap: 0.75rem;
		}
		.auth-actions button {
			flex: 1;
			padding: 0.625rem 1rem;
			border-radius: 6px;
			font-size: 0.9375rem;
			font-weight: 500;
			cursor: pointer;
			border: 1px solid transparent;
			transition: background 0.15s;
		}
		.btn-authorize {
			background: #2271b1;
			color: #fff;
			border-color: #2271b1;
		}
		.btn-authorize:hover { background: #135e96; }
		.btn-deny {
			background: #f6f7f7;
			color: #50575e;
			border-color: #c3c4c7;
		}
		.btn-deny:hover { background: #dcdcde; }
		.auth-user {
			text-align: center;
			font-size: 0.8125rem;
			color: #646970;
			margin-top: 1rem;
		}
	</style>
</head>
<body>
	<div class="auth-container">
		<div class="auth-header">
			<h1>Authorize Agent</h1>
			<div class="site-name">' . esc_html( $site_name ) . '</div>
		</div>

		<dl class="agent-info">
			<dt>Agent</dt>
			<dd>' . esc_html( $agent_name ) . ' <code style="font-size:0.8125rem;color:#646970">(' . esc_html( $agent_slug ) . ')</code></dd>
			<dt>Owner</dt>
			<dd>' . esc_html( $owner_name ) . '</dd>
			<dt>Redirect</dt>
			<dd><code style="font-size:0.8125rem">' . esc_html( $uri_display ) . '</code></dd>
		</dl>

		<div class="auth-notice">
			This will create a bearer token for <strong>' . esc_html( $agent_name ) . '</strong>. Choose the least privilege this runtime needs. The token does not expire.
		</div>

		<form method="POST" action="' . esc_url( $action_url ) . '">
			<input type="hidden" name="agent_slug" value="' . esc_attr( $agent_slug ) . '">
			<input type="hidden" name="redirect_uri" value="' . esc_attr( $redirect_uri ) . '">
			<input type="hidden" name="label" value="' . esc_attr( $label ) . '">
			<input type="hidden" name="_authorize_nonce" value="' . esc_attr( $nonce ) . '">' .
			( ! empty( $code_challenge ) ? '
			<input type="hidden" name="code_challenge" value="' . esc_attr( $code_challenge ) . '">' : '' ) .
			( ! empty( $code_challenge_method ) ? '
			<input type="hidden" name="code_challenge_method" value="' . esc_attr( $code_challenge_method ) . '">' : '' ) .
			( ! empty( $state ) ? '
			<input type="hidden" name="state" value="' . esc_attr( $state ) . '">' : '' ) . '

			<fieldset class="scope-options">
				<legend>Access scope</legend>
				' . wp_kses(
					$scope_options,
					array(
						'label'  => array( 'class' => true ),
						'input'  => array(
							'type'    => true,
							'name'    => true,
							'value'   => true,
							'checked' => true,
						),
						'span'   => array(),
						'strong' => array(),
						'small'  => array(),
					)
				) . '
			</fieldset>

			<div class="auth-actions">
				<button type="submit" name="action" value="authorize" class="btn-authorize">Authorize</button>
				<button type="submit" name="action" value="deny" class="btn-deny">Deny</button>
			</div>
		</form>

		<div class="auth-user">Signed in as <strong>' . esc_html( $user_name ) . '</strong></div>
	</div>
</body>
</html>';
	}
}
