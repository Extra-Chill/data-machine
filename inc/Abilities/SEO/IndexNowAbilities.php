<?php
/**
 * IndexNow Abilities
 *
 * Instant search engine notification when content changes.
 * Supports Bing, Yandex, DuckDuckGo, and any IndexNow-compatible engine.
 *
 * Provides:
 * - Auto-ping on post publish/update via transition_post_status
 * - Manual URL submission via abilities and CLI
 * - Batch submission (up to 10,000 URLs per request)
 * - API key management with auto-generation
 * - Key file verification endpoint
 *
 * Settings (in datamachine_settings):
 * - indexnow_enabled  (bool)   Enable/disable auto-ping on publish
 * - indexnow_api_key  (string) API key (auto-generated UUID if empty)
 *
 * @package DataMachine\Abilities\SEO
 * @since 0.36.0
 */

namespace DataMachine\Abilities\SEO;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class IndexNowAbilities {

	/**
	 * IndexNow API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Maximum URLs per batch submission.
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 10000;

	/**
	 * Whether the abilities have been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			$this->register_hooks();
			self::$registered = true;
			return;
		}

		$this->registerAbilities();
		$this->register_hooks();
		self::$registered = true;
	}

	/**
	 * Register WordPress hooks for auto-ping and key file serving.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 10, 4 );
		add_action( 'parse_request', array( __CLASS__, 'serve_key_file' ) );
	}

	// =========================================================================
	// WordPress Hooks
	// =========================================================================

	/**
	 * Auto-submit URL to IndexNow when a post is published or updated.
	 *
	 * Uses wp_after_insert_post (since WP 5.6) which fires after the post,
	 * its terms, and its meta are fully saved. This is the recommended hook
	 * for post-publish side effects per WordPress core docs.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object after changes.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post object before changes, or null if new.
	 * @return void
	 */
	public static function on_post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before = null ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! PluginSettings::get( 'indexnow_enabled', false ) ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( empty( $url ) ) {
			return;
		}

		$old_status = $post_before ? $post_before->post_status : 'new';

		$result = self::submit_urls( array( $url ) );

		$log_level = $result['success'] ? 'debug' : 'warning';
		$log_msg   = $result['success']
			? 'IndexNow: Submitted URL on publish'
			: 'IndexNow: Failed to submit URL on publish';

		do_action(
			'datamachine_log',
			$log_level,
			$log_msg,
			array(
				'url'         => $url,
				'post_id'     => $post_id,
				'post_type'   => $post->post_type,
				'old_status'  => $old_status,
				'response'    => $result['message'] ?? $result['error'] ?? '',
				'status_code' => $result['status_code'] ?? '',
			)
		);
	}

	/**
	 * Serve the IndexNow key verification file.
	 *
	 * Hooks into parse_request to intercept requests for /{key}.txt
	 * before WordPress processes them as 404s. No rewrite rules needed.
	 *
	 * @param \WP $wp WordPress request object.
	 * @return void
	 */
	public static function serve_key_file( \WP $wp ): void {
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		$request = trim( $wp->request, '/' );
		if ( $request !== $api_key . '.txt' ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=86400' );
		status_header( 200 );
		echo esc_html( $api_key );
		exit;
	}

	// =========================================================================
	// Core Logic
	// =========================================================================

	/**
	 * Submit one or more URLs to IndexNow.
	 *
	 * Uses the batch endpoint when multiple URLs are provided.
	 * Automatically gets or generates the API key.
	 *
	 * @param array $urls Array of full URLs to submit.
	 * @return array Result with success, message, status_code keys.
	 */
	public static function submit_urls( array $urls ): array {
		if ( empty( $urls ) ) {
			return array(
				'success' => false,
				'error'   => 'No URLs provided',
			);
		}

		$api_key = self::get_or_generate_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Could not generate IndexNow API key',
			);
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( count( $urls ) > self::MAX_BATCH_SIZE ) {
			$urls = array_slice( $urls, 0, self::MAX_BATCH_SIZE );
		}

		$parsed = wp_parse_url( $urls[0] );
		$host   = $parsed['host'] ?? wp_parse_url( home_url(), PHP_URL_HOST );

		$body = array(
			'host'        => $host,
			'key'         => $api_key,
			'keyLocation' => home_url( '/' . $api_key . '.txt' ),
			'urlList'     => $urls,
		);

		$result = HttpClient::post(
			self::API_ENDPOINT,
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
				'context' => 'IndexNow Submission',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success'     => false,
				'error'       => $result['error'] ?? 'HTTP request failed',
				'status_code' => $result['status_code'] ?? 0,
			);
		}

		$status_code = $result['status_code'] ?? 0;

		// IndexNow returns:
		// 200 = URL submitted successfully
		// 202 = URL received, will be processed later
		// 400 = Invalid request
		// 403 = Key not valid
		// 422 = URL doesn't belong to host
		// 429 = Too many requests
		$success_codes = array( 200, 202 );

		if ( in_array( $status_code, $success_codes, true ) ) {
			return array(
				'success'     => true,
				'message'     => 200 === $status_code ? 'URL submitted and accepted' : 'URL received, will be processed',
				'status_code' => $status_code,
				'url_count'   => count( $urls ),
			);
		}

		$error_messages = array(
			400 => 'Invalid request — check URL format',
			403 => 'API key not valid — verify key file is accessible',
			422 => 'URL does not belong to the host',
			429 => 'Too many requests — rate limited',
		);

		return array(
			'success'     => false,
			'error'       => $error_messages[ $status_code ] ?? 'Unexpected response code: ' . $status_code,
			'status_code' => $status_code,
		);
	}

	/**
	 * Get the IndexNow API key from settings.
	 *
	 * @return string API key or empty string.
	 */
	public static function get_api_key(): string {
		return PluginSettings::get( 'indexnow_api_key', '' );
	}

	/**
	 * Get existing API key or generate a new one.
	 *
	 * @return string API key.
	 */
	public static function get_or_generate_key(): string {
		$key = self::get_api_key();

		if ( ! empty( $key ) ) {
			return $key;
		}

		return self::generate_key();
	}

	/**
	 * Generate a new IndexNow API key and save it.
	 *
	 * Key format: 32-character hex string (like a UUID without hyphens).
	 * IndexNow requires keys to be at least 8 characters, alphanumeric + dashes.
	 *
	 * @return string Generated key.
	 */
	public static function generate_key(): string {
		$key = wp_generate_uuid4();
		$key = str_replace( '-', '', $key );

		$settings                     = get_option( 'datamachine_settings', array() );
		$settings['indexnow_api_key'] = $key;
		update_option( 'datamachine_settings', $settings );

		PluginSettings::clearCache();

		do_action(
			'datamachine_log',
			'info',
			'IndexNow: Generated new API key',
			array( 'key_preview' => substr( $key, 0, 8 ) . '...' )
		);

		return $key;
	}

	/**
	 * Verify that the key file is accessible at the expected URL.
	 *
	 * @return array Result with success, url, and message keys.
	 */
	public static function verify_key_file(): array {
		$api_key = self::get_api_key();

		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'No API key configured. Generate one first.',
			);
		}

		$key_url = home_url( '/' . $api_key . '.txt' );
		$result  = HttpClient::get(
			$key_url,
			array(
				'timeout' => 10,
				'context' => 'IndexNow Key Verification',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'url'     => $key_url,
				'error'   => 'Key file not accessible: ' . ( $result['error'] ?? 'unknown error' ),
			);
		}

		$body = trim( $result['data'] ?? '' );

		if ( $body !== $api_key ) {
			return array(
				'success' => false,
				'url'     => $key_url,
				'error'   => 'Key file content does not match API key',
			);
		}

		return array(
			'success' => true,
			'url'     => $key_url,
			'message' => 'Key file verified successfully',
		);
	}

	/**
	 * Get IndexNow status including enabled state, key, and verification.
	 *
	 * @return array Status information.
	 */
	public static function get_status(): array {
		$enabled = PluginSettings::get( 'indexnow_enabled', false );
		$api_key = self::get_api_key();

		return array(
			'enabled'      => (bool) $enabled,
			'has_key'      => ! empty( $api_key ),
			'key_preview'  => ! empty( $api_key ) ? substr( $api_key, 0, 8 ) . '...' : '',
			'key_file_url' => ! empty( $api_key ) ? home_url( '/' . $api_key . '.txt' ) : '',
			'endpoint'     => self::API_ENDPOINT,
		);
	}

	// =========================================================================
	// Abilities Registration
	// =========================================================================

	/**
	 * Register all IndexNow abilities.
	 *
	 * @return void
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerSubmitAbility();
			$this->registerStatusAbility();
			$this->registerGenerateKeyAbility();
			$this->registerVerifyKeyAbility();
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Register the submit URL(s) ability.
	 *
	 * @return void
	 */
	private function registerSubmitAbility(): void {
		wp_register_ability(
			'datamachine/indexnow-submit',
			array(
				'label'               => __( 'IndexNow Submit', 'data-machine' ),
				'description'         => __( 'Submit one or more URLs to IndexNow for instant search engine indexing.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'urls' ),
					'properties' => array(
						'urls' => array(
							'type'        => 'array',
							'description' => __( 'Array of full URLs to submit', 'data-machine' ),
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'message'     => array( 'type' => 'string' ),
						'url_count'   => array( 'type' => 'integer' ),
						'status_code' => array( 'type' => 'integer' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeSubmit' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register the status ability.
	 *
	 * @return void
	 */
	private function registerStatusAbility(): void {
		wp_register_ability(
			'datamachine/indexnow-status',
			array(
				'label'               => __( 'IndexNow Status', 'data-machine' ),
				'description'         => __( 'Get IndexNow integration status including enabled state and API key.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'enabled'      => array( 'type' => 'boolean' ),
						'has_key'      => array( 'type' => 'boolean' ),
						'key_preview'  => array( 'type' => 'string' ),
						'key_file_url' => array( 'type' => 'string' ),
						'endpoint'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeStatus' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register the generate key ability.
	 *
	 * @return void
	 */
	private function registerGenerateKeyAbility(): void {
		wp_register_ability(
			'datamachine/indexnow-generate-key',
			array(
				'label'               => __( 'IndexNow Generate Key', 'data-machine' ),
				'description'         => __( 'Generate a new IndexNow API key and save it to settings.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'      => array( 'type' => 'boolean' ),
						'key_preview'  => array( 'type' => 'string' ),
						'key_file_url' => array( 'type' => 'string' ),
						'message'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGenerateKey' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Register the verify key ability.
	 *
	 * @return void
	 */
	private function registerVerifyKeyAbility(): void {
		wp_register_ability(
			'datamachine/indexnow-verify-key',
			array(
				'label'               => __( 'IndexNow Verify Key', 'data-machine' ),
				'description'         => __( 'Verify that the IndexNow key file is accessible and correct.', 'data-machine' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'url'     => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeVerifyKey' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	// =========================================================================
	// Ability Executors
	// =========================================================================

	/**
	 * Execute submit ability.
	 *
	 * @param array $input Input with 'urls' array.
	 * @return array Result.
	 */
	public function executeSubmit( array $input ): array {
		$urls = $input['urls'] ?? array();

		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return array(
				'success' => false,
				'error'   => 'urls parameter is required and must be a non-empty array',
			);
		}

		$urls = array_map( 'esc_url_raw', $urls );
		$urls = array_filter( $urls );

		if ( empty( $urls ) ) {
			return array(
				'success' => false,
				'error'   => 'No valid URLs after sanitization',
			);
		}

		return self::submit_urls( $urls );
	}

	/**
	 * Execute status ability.
	 *
	 * @param array $input Input (unused).
	 * @return array Status information.
	 */
	public function executeStatus( array $input ): array {
		$input;
		$status            = self::get_status();
		$status['success'] = true;
		return $status;
	}

	/**
	 * Execute generate key ability.
	 *
	 * @param array $input Input (unused).
	 * @return array Result with key preview.
	 */
	public function executeGenerateKey( array $input ): array {
		$input;
		$key = self::generate_key();

		return array(
			'success'      => true,
			'key_preview'  => substr( $key, 0, 8 ) . '...',
			'key_file_url' => home_url( '/' . $key . '.txt' ),
			'message'      => 'New IndexNow API key generated',
		);
	}

	/**
	 * Execute verify key ability.
	 *
	 * @param array $input Input (unused).
	 * @return array Verification result.
	 */
	public function executeVerifyKey( array $input ): array {
		$input;
		return self::verify_key_file();
	}
}
