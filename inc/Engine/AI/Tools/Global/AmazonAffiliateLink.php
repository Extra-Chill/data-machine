<?php
/**
 * Amazon Affiliate Link tool via Amazon Creators API.
 *
 * Searches Amazon products and returns affiliate links for AI content generation.
 * Uses OAuth 2.0 client_credentials flow via regional AWS Cognito endpoints.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 * @since 0.24.0
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\Tools\BaseTool;

class AmazonAffiliateLink extends BaseTool {

	/**
	 * Config option name.
	 *
	 * @var string
	 */
	private const CONFIG_OPTION = 'datamachine_amazon_config';

	/**
	 * Token transient name.
	 *
	 * @var string
	 */
	private const TOKEN_TRANSIENT = 'datamachine_amazon_access_token';

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://creatorsapi.amazon';

	/**
	 * Marketplace to region mapping.
	 *
	 * @var array<string, string>
	 */
	private const REGION_MAP = array(
		'www.amazon.com'    => 'NA',
		'www.amazon.ca'     => 'NA',
		'www.amazon.com.mx' => 'NA',
		'www.amazon.com.br' => 'NA',
		'www.amazon.co.uk'  => 'EU',
		'www.amazon.de'     => 'EU',
		'www.amazon.fr'     => 'EU',
		'www.amazon.it'     => 'EU',
		'www.amazon.es'     => 'EU',
		'www.amazon.in'     => 'EU',
		'www.amazon.co.jp'  => 'FE',
		'www.amazon.com.au' => 'FE',
	);

	/**
	 * Region configuration: version and Cognito token endpoint.
	 *
	 * @var array<string, array{version: string, token_endpoint: string}>
	 */
	private const REGION_CONFIG = array(
		'NA' => array(
			'version'        => '2.1',
			'token_endpoint' => 'https://creatorsapi.auth.us-east-1.amazoncognito.com/oauth2/token',
		),
		'EU' => array(
			'version'        => '2.2',
			'token_endpoint' => 'https://creatorsapi.auth.eu-south-2.amazoncognito.com/oauth2/token',
		),
		'FE' => array(
			'version'        => '2.3',
			'token_endpoint' => 'https://creatorsapi.auth.us-west-2.amazoncognito.com/oauth2/token',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registerConfigurationHandlers( 'amazon_affiliate_link' );
		$this->registerGlobalTool( 'amazon_affiliate_link', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute Amazon product search and return affiliate link.
	 *
	 * @param array $parameters Contains 'query' for product search.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Search result with affiliate link or error.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		if ( empty( $parameters['query'] ) ) {
			return $this->buildErrorResponse(
				'Amazon Affiliate Link tool requires a product search query.',
				'amazon_affiliate_link'
			);
		}

		$config = self::get_config();

		if ( empty( $config['client_id'] ) || empty( $config['client_secret'] ) || empty( $config['partner_tag'] ) ) {
			return $this->buildErrorResponse(
				'Amazon Affiliate Link tool not configured. Please add Creators API credentials and partner tag.',
				'amazon_affiliate_link'
			);
		}

		$marketplace = $config['marketplace'] ?? 'www.amazon.com';
		$region      = self::REGION_MAP[ $marketplace ] ?? 'NA';

		$token = $this->getAccessToken( $config, $region );

		if ( ! $token ) {
			return $this->buildErrorResponse(
				'Failed to obtain Amazon Creators API access token. Check credentials.',
				'amazon_affiliate_link'
			);
		}

		$region_config = self::REGION_CONFIG[ $region ];
		$query         = sanitize_text_field( $parameters['query'] );

		$result = HttpClient::post(
			self::API_BASE . '/catalog/v1/searchItems',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token . ', Version ' . $region_config['version'],
					'Content-Type'  => 'application/json',
					'x-marketplace' => $marketplace,
				),
				'body'    => wp_json_encode(
					array(
						'keywords'    => $query,
						'searchIndex' => 'All',
						'itemCount'   => 1,
						'marketplace' => $marketplace,
						'partnerTag'  => $config['partner_tag'],
						'resources'   => array(
							'images.primary.small',
							'itemInfo.title',
						),
					)
				),
				'context' => 'Amazon Creators API SearchItems',
			)
		);

		if ( ! $result['success'] ) {
			return $this->buildErrorResponse(
				'Amazon product search failed: ' . ( $result['error'] ?? 'Unknown error' ),
				'amazon_affiliate_link'
			);
		}

		$data = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->buildErrorResponse(
				'Failed to parse Amazon API response.',
				'amazon_affiliate_link'
			);
		}

		$items = $data['itemsResult']['items'] ?? array();

		if ( empty( $items ) ) {
			return array(
				'success'   => true,
				'data'      => array(
					'message' => "No Amazon products found for \"{$query}\". Try a more specific product name.",
				),
				'tool_name' => 'amazon_affiliate_link',
			);
		}

		$item          = $items[0];
		$product_title = $item['itemInfo']['title']['displayValue'] ?? 'Unknown Product';
		$affiliate_url = $item['detailPageURL'] ?? '';
		$thumbnail_url = $item['images']['primary']['small']['url'] ?? '';
		$asin          = $item['asin'] ?? '';

		return array(
			'success'   => true,
			'data'      => array(
				'product_title' => $product_title,
				'affiliate_url' => $affiliate_url,
				'thumbnail_url' => $thumbnail_url,
				'asin'          => $asin,
			),
			'tool_name' => 'amazon_affiliate_link',
		);
	}

	/**
	 * Get OAuth 2.0 access token, using cached transient when available.
	 *
	 * @param array  $config Amazon configuration.
	 * @param string $region Region identifier (NA, EU, FE).
	 * @return string|null Access token or null on failure.
	 */
	private function getAccessToken( array $config, string $region ): ?string {
		$cached = get_transient( self::TOKEN_TRANSIENT );

		if ( $cached ) {
			return $cached;
		}

		$region_config = self::REGION_CONFIG[ $region ] ?? self::REGION_CONFIG['NA'];

		$result = HttpClient::post(
			$region_config['token_endpoint'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'client_credentials',
						'client_id'     => $config['client_id'],
						'client_secret' => $config['client_secret'],
						'scope'         => 'creatorsapi/default',
					)
				),
				'context' => 'Amazon Creators API Token',
			)
		);

		if ( ! $result['success'] ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to fetch Amazon Creators API token',
				array(
					'error'  => $result['error'] ?? 'Unknown',
					'region' => $region,
				)
			);
			return null;
		}

		$token_data = json_decode( $result['data'], true );

		if ( empty( $token_data['access_token'] ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Amazon Creators API token response missing access_token',
				array( 'response' => $result['data'] )
			);
			return null;
		}

		$expires_in = (int) ( $token_data['expires_in'] ?? 3600 );
		set_transient( self::TOKEN_TRANSIENT, $token_data['access_token'], $expires_in - 100 );

		return $token_data['access_token'];
	}

	/**
	 * Get tool definition for AI agent registration.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Search Amazon products and return an affiliate link with the product title, URL, and thumbnail. Use when content mentions a specific product the reader might want to buy. Only use for genuinely relevant product references — not every noun.',
			'requires_config' => true,
			'parameters'      => array(
				'query' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Product search query (e.g., "Lodge cast iron Dutch oven", "KitchenAid stand mixer"). Be specific for best results.',
				),
			),
		);
	}

	/**
	 * Check if Amazon Affiliate Link tool is configured.
	 *
	 * @return bool True if all required credentials are present.
	 */
	public static function is_configured(): bool {
		$config = self::get_config();

		return ! empty( $config['client_id'] )
			&& ! empty( $config['client_secret'] )
			&& ! empty( $config['partner_tag'] );
	}

	/**
	 * Get stored Amazon configuration.
	 *
	 * @return array Configuration array.
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
	}

	/**
	 * Check configuration status via filter.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool True if configured.
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'amazon_affiliate_link' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	/**
	 * Get configuration via filter.
	 *
	 * @param mixed  $config  Current config.
	 * @param string $tool_id Tool identifier.
	 * @return array Configuration array.
	 */
	public function get_configuration( $config, $tool_id ) {
		if ( 'amazon_affiliate_link' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	/**
	 * Save Amazon configuration.
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param array  $config_data Configuration data from form.
	 */
	public function save_configuration( $tool_id, $config_data ) {
		if ( 'amazon_affiliate_link' !== $tool_id ) {
			return;
		}

		$client_id     = sanitize_text_field( $config_data['client_id'] ?? '' );
		$client_secret = sanitize_text_field( $config_data['client_secret'] ?? '' );
		$partner_tag   = sanitize_text_field( $config_data['partner_tag'] ?? '' );
		$marketplace   = sanitize_text_field( $config_data['marketplace'] ?? 'www.amazon.com' );

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $partner_tag ) ) {
			wp_send_json_error( array( 'message' => __( 'Credential ID, Credential Secret, and Partner Tag are required.', 'data-machine' ) ) );
			return;
		}

		if ( ! isset( self::REGION_MAP[ $marketplace ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid marketplace selected.', 'data-machine' ) ) );
			return;
		}

		$saved = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'partner_tag'   => $partner_tag,
			'marketplace'   => $marketplace,
		);

		// Clear cached token when credentials change.
		delete_transient( self::TOKEN_TRANSIENT );

		if ( update_site_option( self::CONFIG_OPTION, $saved ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Amazon Affiliate configuration saved successfully.', 'data-machine' ),
					'configured' => true,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration.', 'data-machine' ) ) );
		}
	}

	/**
	 * Get configuration field definitions for settings UI.
	 *
	 * @param array  $fields  Current fields.
	 * @param string $tool_id Tool identifier.
	 * @return array Field definitions.
	 */
	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'amazon_affiliate_link' !== $tool_id ) {
			return $fields;
		}

		return array(
			'client_id'     => array(
				'type'        => 'text',
				'label'       => __( 'Credential ID', 'data-machine' ),
				'placeholder' => __( 'Enter your Amazon Creators API Credential ID', 'data-machine' ),
				'required'    => true,
				'description' => __( 'From Amazon Associates → Creators API credentials.', 'data-machine' ),
			),
			'client_secret' => array(
				'type'        => 'password',
				'label'       => __( 'Credential Secret', 'data-machine' ),
				'placeholder' => __( 'Enter your Amazon Creators API Credential Secret', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Keep this secret. Never expose in client-side code.', 'data-machine' ),
			),
			'partner_tag'   => array(
				'type'        => 'text',
				'label'       => __( 'Partner Tag', 'data-machine' ),
				'placeholder' => __( 'e.g., mysite-20', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Your Amazon Associates tracking tag.', 'data-machine' ),
			),
			'marketplace'   => array(
				'type'        => 'select',
				'label'       => __( 'Marketplace', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Amazon marketplace for product searches. Region and API version are auto-detected.', 'data-machine' ),
				'options'     => array(
					'www.amazon.com'    => 'United States (amazon.com)',
					'www.amazon.ca'     => 'Canada (amazon.ca)',
					'www.amazon.com.mx' => 'Mexico (amazon.com.mx)',
					'www.amazon.com.br' => 'Brazil (amazon.com.br)',
					'www.amazon.co.uk'  => 'United Kingdom (amazon.co.uk)',
					'www.amazon.de'     => 'Germany (amazon.de)',
					'www.amazon.fr'     => 'France (amazon.fr)',
					'www.amazon.it'     => 'Italy (amazon.it)',
					'www.amazon.es'     => 'Spain (amazon.es)',
					'www.amazon.in'     => 'India (amazon.in)',
					'www.amazon.co.jp'  => 'Japan (amazon.co.jp)',
					'www.amazon.com.au' => 'Australia (amazon.com.au)',
				),
			),
		);
	}
}

// Self-register the tool.
new AmazonAffiliateLink();
