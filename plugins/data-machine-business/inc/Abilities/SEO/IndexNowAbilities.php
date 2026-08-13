<?php
/**
 * IndexNow abilities and automatic URL submission.
 *
 * @package DataMachineBusiness\Abilities\SEO
 */

namespace DataMachineBusiness\Abilities\SEO;

use DataMachine\Abilities\AbilityRegistration;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Core\PluginSettings;

defined( 'ABSPATH' ) || exit;

class IndexNowAbilities {

	public const API_ENDPOINT = 'https://api.indexnow.org/indexnow';
	public const MAX_BATCH_SIZE = 10000;

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		$this->register_abilities();
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 10, 4 );
		add_action( 'parse_request', array( __CLASS__, 'serve_key_file' ) );
		self::$registered = true;
	}

	public static function on_post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before = null ): void {
		if ( 'publish' !== $post->post_status || ! PluginSettings::get( 'indexnow_enabled', false ) ) {
			return;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->public || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		/**
		 * Filters whether to skip the automatic per-post IndexNow ping.
		 *
		 * @param bool     $skip    Whether to skip the auto-submit. Default false.
		 * @param int      $post_id Post ID being published or updated.
		 * @param \WP_Post $post    Post object.
		 */
		if ( apply_filters( 'datamachine_indexnow_skip_auto_submit', false, $post_id, $post ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( empty( $url ) ) {
			return;
		}

		$result = self::submit_urls( array( $url ) );
		do_action(
			'datamachine_log',
			$result['success'] ? 'debug' : 'warning',
			$result['success'] ? 'IndexNow: Submitted URL on publish' : 'IndexNow: Failed to submit URL on publish',
			array(
				'url'         => $url,
				'post_id'     => $post_id,
				'post_type'   => $post->post_type,
				'old_status'  => $post_before ? $post_before->post_status : 'new',
				'response'    => $result['message'] ?? $result['error'] ?? '',
				'status_code' => $result['status_code'] ?? '',
			)
		);
	}

	public static function serve_key_file( \WP $wp ): void {
		$key = self::get_api_key();
		if ( empty( $key ) || trim( $wp->request, '/' ) !== $key . '.txt' ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=86400' );
		status_header( 200 );
		echo esc_html( $key );
		exit;
	}

	public static function submit_urls( array $urls ): array {
		if ( empty( $urls ) ) {
			return array( 'success' => false, 'error' => 'No URLs provided' );
		}

		$key = self::get_or_generate_key();
		if ( empty( $key ) ) {
			return array( 'success' => false, 'error' => 'Could not generate IndexNow API key' );
		}

		$urls = array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, self::MAX_BATCH_SIZE );
		$host = wp_parse_url( $urls[0], PHP_URL_HOST ) ?: wp_parse_url( home_url(), PHP_URL_HOST );
		$result = HttpClient::post(
			self::API_ENDPOINT,
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => $urls,
					)
				),
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
		if ( in_array( $status_code, array( 200, 202 ), true ) ) {
			return array(
				'success'     => true,
				'message'     => 200 === $status_code ? 'URL submitted and accepted' : 'URL received, will be processed',
				'status_code' => $status_code,
				'url_count'   => count( $urls ),
			);
		}

		$messages = array(
			400 => 'Invalid request — check URL format',
			403 => 'API key not valid — verify key file is accessible',
			422 => 'URL does not belong to the host',
			429 => 'Too many requests — rate limited',
		);

		return array(
			'success'     => false,
			'error'       => $messages[ $status_code ] ?? 'Unexpected response code: ' . $status_code,
			'status_code' => $status_code,
		);
	}

	public static function get_api_key(): string {
		return PluginSettings::get( 'indexnow_api_key', '' );
	}

	public static function get_or_generate_key(): string {
		return self::get_api_key() ?: self::generate_key();
	}

	public static function generate_key(): string {
		$key                              = str_replace( '-', '', wp_generate_uuid4() );
		$settings                         = get_option( 'datamachine_settings', array() );
		$settings['indexnow_api_key']     = $key;
		update_option( 'datamachine_settings', $settings );
		PluginSettings::clearCache();
		do_action( 'datamachine_log', 'info', 'IndexNow: Generated new API key', array( 'key_preview' => substr( $key, 0, 8 ) . '...' ) );
		return $key;
	}

	public static function verify_key_file(): array {
		$key = self::get_api_key();
		if ( empty( $key ) ) {
			return array( 'success' => false, 'error' => 'No API key configured. Generate one first.' );
		}

		$url    = home_url( '/' . $key . '.txt' );
		$result = HttpClient::get( $url, array( 'timeout' => 10, 'context' => 'IndexNow Key Verification' ) );
		if ( ! $result['success'] ) {
			return array( 'success' => false, 'url' => $url, 'error' => 'Key file not accessible: ' . ( $result['error'] ?? 'unknown error' ) );
		}

		if ( trim( $result['data'] ?? '' ) !== $key ) {
			return array( 'success' => false, 'url' => $url, 'error' => 'Key file content does not match API key' );
		}

		return array( 'success' => true, 'url' => $url, 'message' => 'Key file verified successfully' );
	}

	public static function get_status(): array {
		$key = self::get_api_key();
		return array(
			'enabled'      => (bool) PluginSettings::get( 'indexnow_enabled', false ),
			'has_key'      => ! empty( $key ),
			'key_preview'  => $key ? substr( $key, 0, 8 ) . '...' : '',
			'key_file_url' => $key ? home_url( '/' . $key . '.txt' ) : '',
			'endpoint'     => self::API_ENDPOINT,
		);
	}

	private function register_abilities(): void {
		AbilityRegistration::on_abilities_api_init(
			function (): void {
				$this->register_submit_ability();
				$this->register_status_ability();
				$this->register_generate_key_ability();
				$this->register_verify_key_ability();
			}
		);
	}

	private function register_submit_ability(): void {
		wp_register_ability(
			'datamachine/indexnow-submit',
			array(
				'label'               => __( 'IndexNow Submit', 'data-machine-business' ),
				'description'         => __( 'Submit one or more URLs to IndexNow for instant search engine indexing.', 'data-machine-business' ),
				'category'            => 'datamachine-seo',
				'input_schema'        => array( 'type' => 'object', 'required' => array( 'urls' ), 'properties' => array( 'urls' => array( 'type' => 'array', 'description' => __( 'Array of full URLs to submit', 'data-machine-business' ), 'items' => array( 'type' => 'string' ) ) ) ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'message' => array( 'type' => 'string' ), 'url_count' => array( 'type' => 'integer' ), 'status_code' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
				'execute_callback'    => array( $this, 'execute_submit' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function register_status_ability(): void {
		wp_register_ability(
			'datamachine/indexnow-status',
			array(
				'label'               => __( 'IndexNow Status', 'data-machine-business' ),
				'description'         => __( 'Get IndexNow integration status including enabled state and API key.', 'data-machine-business' ),
				'category'            => 'datamachine-seo',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'enabled' => array( 'type' => 'boolean' ), 'has_key' => array( 'type' => 'boolean' ), 'key_preview' => array( 'type' => 'string' ), 'key_file_url' => array( 'type' => 'string' ), 'endpoint' => array( 'type' => 'string' ) ) ),
				'execute_callback'    => array( $this, 'execute_status' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function register_generate_key_ability(): void {
		wp_register_ability(
			'datamachine/indexnow-generate-key',
			array(
				'label'               => __( 'IndexNow Generate Key', 'data-machine-business' ),
				'description'         => __( 'Generate a new IndexNow API key and save it to settings.', 'data-machine-business' ),
				'category'            => 'datamachine-seo',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'key_preview' => array( 'type' => 'string' ), 'key_file_url' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ) ) ),
				'execute_callback'    => array( $this, 'execute_generate_key' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function register_verify_key_ability(): void {
		wp_register_ability(
			'datamachine/indexnow-verify-key',
			array(
				'label'               => __( 'IndexNow Verify Key', 'data-machine-business' ),
				'description'         => __( 'Verify that the IndexNow key file is accessible and correct.', 'data-machine-business' ),
				'category'            => 'datamachine-seo',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'url' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ), 'error' => array( 'type' => 'string' ) ) ),
				'execute_callback'    => array( $this, 'execute_verify_key' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function execute_submit( array $input ): array {
		$urls = $input['urls'] ?? array();
		if ( empty( $urls ) || ! is_array( $urls ) ) {
			return array( 'success' => false, 'error' => 'urls parameter is required and must be a non-empty array' );
		}

		$urls = array_filter( array_map( 'esc_url_raw', $urls ) );
		return empty( $urls ) ? array( 'success' => false, 'error' => 'No valid URLs after sanitization' ) : self::submit_urls( $urls );
	}

	public function execute_status( array $input ): array {
		$status            = self::get_status();
		$status['success'] = true;
		return $status;
	}

	public function execute_generate_key( array $input ): array {
		$key = self::generate_key();
		return array( 'success' => true, 'key_preview' => substr( $key, 0, 8 ) . '...', 'key_file_url' => home_url( '/' . $key . '.txt' ), 'message' => 'New IndexNow API key generated' );
	}

	public function execute_verify_key( array $input ): array {
		return self::verify_key_file();
	}
}
