<?php
/**
 * Cloudflare Turnstile abilities.
 *
 * A home for Cloudflare provider integrations, starting with Turnstile
 * widget read + hostname management (issue #143). DNS, cache purge, WAF, and
 * zone settings are out of scope until a real need appears — see the issue
 * for the premature-consolidation rationale.
 *
 * Credentials ({api_token, account_id}) live in the datamachine_cloudflare_config
 * network option, never in code, and are resolvable through a filter so
 * deployments can inject secrets without persisting them.
 *
 * Abilities:
 *  - datamachine/cloudflare-turnstile-list-widgets        List all Turnstile widgets (read).
 *  - datamachine/cloudflare-turnstile-get-widget           Get one widget by sitekey (read).
 *  - datamachine/cloudflare-turnstile-update-widget-domains Add/remove hostnames (read-modify-write, dry-run by default).
 *
 * @package DataMachineBusiness\Abilities\Cloudflare
 */

namespace DataMachineBusiness\Abilities\Cloudflare;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;

defined( 'ABSPATH' ) || exit;

class CloudflareTurnstileAbilities {

	/**
	 * Network option holding {api_token, account_id}.
	 *
	 * @var string
	 */
	public const CONFIG_OPTION = 'datamachine_cloudflare_config';

	/**
	 * Cloudflare API v4 base URL.
	 *
	 * @var string
	 */
	public const API_BASE = 'https://api.cloudflare.com/client/v4';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		self::$registered = true;
	}

	private function register_abilities(): void {
		AbilityRegistration::on_abilities_api_init(
			function (): void {
				$this->register_list_widgets_ability();
				$this->register_get_widget_ability();
				$this->register_update_widget_domains_ability();
			}
		);
	}

	private function register_list_widgets_ability(): void {
		wp_register_ability(
			'datamachine/cloudflare-turnstile-list-widgets',
			array(
				'label'               => __( 'Cloudflare Turnstile: List Widgets', 'data-machine-business' ),
				'description'         => __( 'List all Cloudflare Turnstile widgets on the configured account.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'widgets' => array( 'type' => 'array' ),
						'count'   => array( 'type' => 'integer' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_list_widgets' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function register_get_widget_ability(): void {
		wp_register_ability(
			'datamachine/cloudflare-turnstile-get-widget',
			array(
				'label'               => __( 'Cloudflare Turnstile: Get Widget', 'data-machine-business' ),
				'description'         => __( 'Get one Cloudflare Turnstile widget by sitekey.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'sitekey' ),
					'properties' => array(
						'sitekey' => array(
							'type'        => 'string',
							'description' => __( 'The Turnstile widget sitekey (public; rendered in page HTML).', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'widget'  => array( 'type' => 'object' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_get_widget' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	private function register_update_widget_domains_ability(): void {
		wp_register_ability(
			'datamachine/cloudflare-turnstile-update-widget-domains',
			array(
				'label'               => __( 'Cloudflare Turnstile: Update Widget Domains', 'data-machine-business' ),
				'description'         => __( 'Add and/or remove hostnames from a Turnstile widget\'s allowed domains. Read-modify-write: preserves the widget\'s name, mode, and every existing domain. Dry-run by default; pass apply=true to write the change.', 'data-machine-business' ),
				'category'            => 'datamachine-system',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'sitekey' ),
					'properties' => array(
						'sitekey' => array(
							'type'        => 'string',
							'description' => __( 'The Turnstile widget sitekey to update.', 'data-machine-business' ),
						),
						'add'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Hostnames to add (no scheme, no path, no wildcard).', 'data-machine-business' ),
						),
						'remove'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Hostnames to remove.', 'data-machine-business' ),
						),
						'apply'   => array(
							'type'        => 'boolean',
							'description' => __( 'Write the change to Cloudflare. Without apply=true, returns a dry-run preview.', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'dry_run'        => array( 'type' => 'boolean' ),
						'no_op'          => array( 'type' => 'boolean' ),
						'sitekey'        => array( 'type' => 'string' ),
						'name'           => array( 'type' => 'string' ),
						'mode'           => array( 'type' => 'string' ),
						'domains_before' => array( 'type' => 'array' ),
						'domains_after'  => array( 'type' => 'array' ),
						'added'          => array( 'type' => 'array' ),
						'removed'        => array( 'type' => 'array' ),
						'message'        => array( 'type' => 'string' ),
						'error'          => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute_update_widget_domains' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	public static function execute_list_widgets( array $input ): array {
		unset( $input );
		return self::list_widgets();
	}

	public static function execute_get_widget( array $input ): array {
		$sitekey = sanitize_text_field( $input['sitekey'] ?? '' );
		if ( '' === $sitekey ) {
			return array(
				'success' => false,
				'error'   => 'sitekey is required.',
			);
		}

		return self::get_widget( $sitekey );
	}

	public static function execute_update_widget_domains( array $input ): array {
		$sitekey = sanitize_text_field( $input['sitekey'] ?? '' );
		if ( '' === $sitekey ) {
			return array(
				'success' => false,
				'error'   => 'sitekey is required.',
			);
		}

		$add    = is_array( $input['add'] ?? null ) ? $input['add'] : array();
		$remove = is_array( $input['remove'] ?? null ) ? $input['remove'] : array();
		$apply  = ! empty( $input['apply'] );

		return self::update_widget_domains( $sitekey, $add, $remove, $apply );
	}

	/**
	 * List all Turnstile widgets on the configured account.
	 *
	 * @return array<string,mixed>
	 */
	public static function list_widgets(): array {
		$response = self::api_get( '/challenges/widgets' );
		if ( ! $response['success'] ) {
			return $response;
		}

		$widgets = is_array( $response['result'] ) ? $response['result'] : array();

		return array(
			'success' => true,
			'widgets' => $widgets,
			'count'   => count( $widgets ),
		);
	}

	/**
	 * Get one widget by sitekey.
	 *
	 * @param string $sitekey Turnstile widget sitekey.
	 * @return array<string,mixed>
	 */
	public static function get_widget( string $sitekey ): array {
		$sitekey = sanitize_text_field( $sitekey );
		if ( '' === $sitekey ) {
			return array(
				'success' => false,
				'error'   => 'sitekey is required.',
			);
		}

		$response = self::api_get( '/challenges/widgets/' . rawurlencode( $sitekey ) );
		if ( ! $response['success'] ) {
			return $response;
		}

		return array(
			'success' => true,
			'widget'  => $response['result'],
		);
	}

	/**
	 * Add and/or remove hostnames from a widget's allowed domains.
	 *
	 * Read-modify-write: fetches the current widget first and preserves its
	 * name, mode, and every existing domain, because Cloudflare's PUT
	 * replaces the whole config. Idempotent (adding a domain that is already
	 * present, or removing one that is already absent, is reported as a
	 * no-op and never issues a PUT). Dry-run unless $apply is true.
	 *
	 * @param string   $sitekey Turnstile widget sitekey.
	 * @param string[] $add     Hostnames to add.
	 * @param string[] $remove  Hostnames to remove.
	 * @param bool     $apply   Write the change to Cloudflare.
	 * @return array<string,mixed>
	 */
	public static function update_widget_domains( string $sitekey, array $add, array $remove, bool $apply ): array {
		$sitekey = sanitize_text_field( $sitekey );
		if ( '' === $sitekey ) {
			return array(
				'success' => false,
				'error'   => 'sitekey is required.',
			);
		}

		$add_normalized = self::normalize_hostnames( $add );
		if ( isset( $add_normalized['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $add_normalized['error'],
			);
		}

		$remove_normalized = self::normalize_hostnames( $remove );
		if ( isset( $remove_normalized['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $remove_normalized['error'],
			);
		}

		$add    = $add_normalized['hostnames'];
		$remove = $remove_normalized['hostnames'];

		if ( empty( $add ) && empty( $remove ) ) {
			return array(
				'success' => false,
				'error'   => 'At least one hostname to add or remove is required.',
			);
		}

		$current = self::get_widget( $sitekey );
		if ( ! $current['success'] ) {
			return $current;
		}

		$widget = $current['widget'];
		if ( ! is_array( $widget ) ) {
			return array(
				'success' => false,
				'error'   => 'Cloudflare returned an unexpected widget shape.',
			);
		}

		$existing_domains = array_values(
			array_map(
				'strtolower',
				array_filter( is_array( $widget['domains'] ?? null ) ? $widget['domains'] : array(), 'is_string' )
			)
		);
		$name = (string) ( $widget['name'] ?? '' );
		$mode = (string) ( $widget['mode'] ?? '' );

		$final_domains = $existing_domains;
		$added         = array();
		$removed       = array();

		foreach ( $add as $hostname ) {
			if ( ! in_array( $hostname, $final_domains, true ) ) {
				$final_domains[] = $hostname;
				$added[]         = $hostname;
			}
		}

		foreach ( $remove as $hostname ) {
			$index = array_search( $hostname, $final_domains, true );
			if ( false !== $index ) {
				array_splice( $final_domains, $index, 1 );
				$removed[] = $hostname;
			}
		}

		$final_domains = array_values( array_unique( $final_domains ) );
		$no_op         = empty( $added ) && empty( $removed );

		$response_base = array(
			'sitekey'        => $sitekey,
			'name'           => $name,
			'mode'           => $mode,
			'domains_before' => $existing_domains,
			'domains_after'  => $final_domains,
			'added'          => $added,
			'removed'        => $removed,
			'no_op'          => $no_op,
		);

		if ( $no_op ) {
			return array_merge(
				array(
					'success' => true,
					'dry_run' => ! $apply,
					'message' => 'No changes: the requested hostnames already reflect the current state.',
				),
				$response_base
			);
		}

		if ( ! $apply ) {
			return array_merge(
				array(
					'success' => true,
					'dry_run' => true,
					'message' => 'Dry run — no changes written. Pass apply=true (or --apply) to write these changes to Cloudflare.',
				),
				$response_base
			);
		}

		$put_response = self::api_put(
			'/challenges/widgets/' . rawurlencode( $sitekey ),
			array(
				'name'    => $name,
				'mode'    => $mode,
				'domains' => $final_domains,
			)
		);

		if ( ! $put_response['success'] ) {
			return $put_response;
		}

		return array_merge(
			array(
				'success' => true,
				'dry_run' => false,
				'message' => 'Updated widget domains on Cloudflare.',
			),
			$response_base
		);
	}

	public static function is_configured(): bool {
		$config = self::get_config();
		return ! empty( $config['api_token'] ) && ! empty( $config['account_id'] );
	}

	/**
	 * Get stored configuration, filterable so deployments can inject secrets
	 * without persisting them in the network option.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_config(): array {
		$config = get_site_option( self::CONFIG_OPTION, array() );
		$config = is_array( $config ) ? $config : array();

		/**
		 * Filter the canonical Cloudflare connection configuration.
		 *
		 * @param array $config Keys: api_token, account_id.
		 */
		return apply_filters( 'datamachine_cloudflare_config', $config );
	}

	/**
	 * GET request against the Cloudflare API.
	 *
	 * @param string $path Path appended to the account-scoped base URL.
	 * @return array<string,mixed>
	 */
	private static function api_get( string $path ): array {
		return self::api_request( 'GET', $path, null );
	}

	/**
	 * PUT request against the Cloudflare API.
	 *
	 * @param string               $path Path appended to the account-scoped base URL.
	 * @param array<string,mixed> $body Request body, encoded as JSON.
	 * @return array<string,mixed>
	 */
	private static function api_put( string $path, array $body ): array {
		return self::api_request( 'PUT', $path, $body );
	}

	/**
	 * Perform a Cloudflare API request and normalize the response.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path appended to the account-scoped base URL.
	 * @param array<string,mixed>|null $body   Request body for PUT, encoded as JSON.
	 * @return array<string,mixed> {success:true, result:mixed, status_code:int}
	 *                              or {success:false, error:string, status_code?:int}.
	 */
	private static function api_request( string $method, string $path, ?array $body ): array {
		$config = self::get_config();

		if ( empty( $config['api_token'] ) || empty( $config['account_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Cloudflare is not configured. Set the "' . self::CONFIG_OPTION . '" network option with api_token and account_id.',
			);
		}

		$url = self::API_BASE . '/accounts/' . rawurlencode( (string) $config['account_id'] ) . $path;

		$options = array(
			'auth'    => array(
				'type'  => 'bearer',
				'token' => (string) $config['api_token'],
			),
			'timeout' => 20,
			'context' => 'Cloudflare Turnstile API',
		);

		if ( null !== $body ) {
			$options['headers'] = array( 'Content-Type' => 'application/json' );
			$options['body']    = wp_json_encode( $body );
		}

		$result = 'GET' === $method
			? HttpClient::get( $url, $options )
			: HttpClient::put( $url, $options );

		$decoded = isset( $result['data'] ) && is_string( $result['data'] ) ? json_decode( $result['data'], true ) : null;

		if ( ! $result['success'] ) {
			$cloudflare_error = self::extract_cloudflare_errors( $decoded );

			return array(
				'success'     => false,
				'error'       => '' !== $cloudflare_error ? $cloudflare_error : ( $result['error'] ?? 'Cloudflare API request failed.' ),
				'status_code' => $result['status_code'] ?? 0,
			);
		}

		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'error'   => 'Cloudflare API returned an unparseable response.',
			);
		}

		if ( empty( $decoded['success'] ) ) {
			$cloudflare_error = self::extract_cloudflare_errors( $decoded );

			return array(
				'success'     => false,
				'error'       => '' !== $cloudflare_error ? $cloudflare_error : 'Cloudflare API reported failure.',
				'status_code' => $result['status_code'] ?? 0,
			);
		}

		return array(
			'success'     => true,
			'result'      => $decoded['result'] ?? null,
			'status_code' => $result['status_code'] ?? 0,
		);
	}

	/**
	 * Extract Cloudflare's `errors[]` messages into one readable string.
	 *
	 * @param mixed $decoded Decoded response body.
	 * @return string Joined error messages, or an empty string if none.
	 */
	private static function extract_cloudflare_errors( $decoded ): string {
		if ( ! is_array( $decoded ) || empty( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return '';
		}

		$messages = array();
		foreach ( $decoded['errors'] as $error ) {
			if ( ! is_array( $error ) || empty( $error['message'] ) ) {
				continue;
			}

			$code       = isset( $error['code'] ) ? ' (code ' . $error['code'] . ')' : '';
			$messages[] = $error['message'] . $code;
		}

		return implode( '; ', $messages );
	}

	/**
	 * Validate and normalize a list of hostnames.
	 *
	 * @param string[] $hostnames Raw hostnames.
	 * @return array{hostnames?:string[], error?:string}
	 */
	private static function normalize_hostnames( array $hostnames ): array {
		$normalized = array();

		foreach ( $hostnames as $hostname ) {
			$hostname = trim( (string) $hostname );
			if ( '' === $hostname ) {
				continue;
			}

			if ( ! self::is_valid_hostname( $hostname ) ) {
				return array(
					'error' => 'Invalid hostname "' . $hostname . '". Hostnames must not include a scheme, path, or wildcard.',
				);
			}

			$normalized[] = strtolower( $hostname );
		}

		return array( 'hostnames' => array_values( array_unique( $normalized ) ) );
	}

	/**
	 * Whether a string is a bare hostname: no scheme, no path, no wildcard.
	 *
	 * @param string $hostname Candidate hostname.
	 * @return bool
	 */
	private static function is_valid_hostname( string $hostname ): bool {
		if ( '' === $hostname ) {
			return false;
		}

		if ( str_contains( $hostname, '://' ) || str_contains( $hostname, '/' ) || str_contains( $hostname, '*' ) || str_contains( $hostname, ' ' ) ) {
			return false;
		}

		return false !== filter_var( $hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
	}
}
