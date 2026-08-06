<?php
/**
 * Sendy Abilities
 *
 * Generic, config-driven abilities for the Sendy email-marketing service —
 * a sibling to the Slack, Discord, and Google integrations in this plugin.
 *
 * Campaign abilities resolve connection configuration inside this plugin so
 * credentials never cross the public ability boundary. Consumers continue to
 * own campaign content and sender identity. The mechanics live in
 * {@see \DataMachineBusiness\Sendy\SendyClient}.
 *
 * Abilities:
 *  - datamachine/sendy-subscribe       Subscribe an email to a list (API).
 *  - datamachine/sendy-push-campaign   Create/update a campaign (API).
 *  - datamachine/sendy-list-campaigns  List campaigns (read-only DB).
 *  - datamachine/sendy-get-campaign    Get a campaign (read-only DB).
 *  - datamachine/sendy-delete-campaign Delete a draft campaign (DB).
 *  - datamachine/sendy-metrics         Email-funnel metrics (read-only DB/API).
 *
 * @package DataMachineBusiness
 * @subpackage Abilities\Sendy
 * @since 0.11.0
 */

namespace DataMachineBusiness\Abilities\Sendy;

use DataMachine\Abilities\PermissionHelper;
use DataMachineBusiness\Sendy\SendyClient;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the generic, config-driven Sendy abilities.
 */
class SendyAbilities {

	/**
	 * Canonical Sendy connection configuration option.
	 *
	 * @var string
	 */
	public const CONFIG_OPTION = 'datamachine_sendy_config';

	/**
	 * Guards against double registration.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Hook ability registration onto the Abilities API init.
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) || self::$registered ) {
			return;
		}

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( array( $this, 'register_abilities' ) );

		self::$registered = true;
	}

	/**
	 * The reusable config input-schema fragment shared by every Sendy ability.
	 *
	 * @return array
	 */
	private function config_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Sendy connection configuration. Supplied by the caller — this plugin stores no Sendy credentials of its own.', 'data-machine-business' ),
			'properties'  => array(
				'api_key'   => array(
					'type'        => 'string',
					'description' => __( 'Sendy API key.', 'data-machine-business' ),
				),
				'sendy_url' => array(
					'type'        => 'string',
					'description' => __( 'Sendy installation URL.', 'data-machine-business' ),
				),
				'db'        => array(
					'type'        => 'object',
					'description' => __( 'Optional read-only Sendy database connection (host, user, pass, name, port). Required only for the DB-backed metric reads.', 'data-machine-business' ),
					'properties'  => array(
						'host' => array( 'type' => 'string' ),
						'user' => array( 'type' => 'string' ),
						'pass' => array( 'type' => 'string' ),
						'name' => array( 'type' => 'string' ),
						'port' => array( 'type' => 'string' ),
					),
				),
			),
			'required'    => array( 'api_key', 'sendy_url' ),
		);
	}

	/**
	 * Stable campaign summary schema.
	 *
	 * @return array
	 */
	private function campaign_summary_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array(
				'id',
				'title',
				'status',
				'sent',
				'sent_date',
				'scheduled_date',
				'to_send',
				'recipients',
				'opens',
				'clicks',
				'open_rate',
				'click_rate',
				'opens_tracking',
				'links_tracking',
				'stopped',
			),
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'title'          => array( 'type' => 'string' ),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'sent', 'sending', 'scheduled', 'draft' ),
				),
				'sent'           => array( 'type' => array( 'integer', 'null' ) ),
				'sent_date'      => array( 'type' => array( 'string', 'null' ) ),
				'scheduled_date' => array( 'type' => array( 'string', 'null' ) ),
				'to_send'        => array( 'type' => 'integer' ),
				'recipients'     => array( 'type' => 'integer' ),
				'opens'          => array( 'type' => 'integer' ),
				'clicks'         => array( 'type' => 'integer' ),
				'open_rate'      => array( 'type' => 'number' ),
				'click_rate'     => array( 'type' => 'number' ),
				'opens_tracking' => array( 'type' => 'boolean' ),
				'links_tracking' => array( 'type' => 'boolean' ),
				'stopped'        => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Register all Sendy abilities.
	 */
	public function register_abilities(): void {

		// ── Subscribe ──
		wp_register_ability(
			'datamachine/sendy-subscribe',
			array(
				'label'               => __( 'Sendy: Subscribe', 'data-machine-business' ),
				'description'         => __( 'Subscribe an email address to a Sendy list via the Sendy API.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'config', 'list_id', 'email' ),
					'properties' => array(
						'config'  => $this->config_schema(),
						'list_id' => array(
							'type'        => 'string',
							'description' => __( 'Sendy list ID (encrypted hash).', 'data-machine-business' ),
						),
						'email'   => array(
							'type'        => 'string',
							'description' => __( 'Email address to subscribe.', 'data-machine-business' ),
						),
						'name'    => array(
							'type'        => 'string',
							'description' => __( 'Optional subscriber name.', 'data-machine-business' ),
						),
						'fields'  => array(
							'type'        => 'object',
							'description' => __( 'Optional extra Sendy fields (e.g. custom fields).', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_subscribe' ),
				'permission_callback' => array( $this, 'check_subscribe_permission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);

		// ── Push Campaign ──
		wp_register_ability(
			'datamachine/sendy-push-campaign',
			array(
				'label'               => __( 'Sendy: Push Campaign', 'data-machine-business' ),
				'description'         => __( 'Create or update a Sendy email campaign via the Sendy API. The caller owns the content and sender identity.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'from_name', 'from_email', 'reply_to', 'subject', 'html_text', 'brand_id' ),
					'properties'           => array(
						'campaign_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Existing campaign ID to update. Omit to create a new campaign.', 'data-machine-business' ),
						),
						'from_name'   => array( 'type' => 'string' ),
						'from_email'  => array( 'type' => 'string' ),
						'reply_to'    => array( 'type' => 'string' ),
						'subject'     => array( 'type' => 'string' ),
						'html_text'   => array( 'type' => 'string' ),
						'plain_text'  => array( 'type' => 'string' ),
						'brand_id'    => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'success', 'campaign_id', 'message', 'created' ),
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'campaign_id' => array( 'type' => array( 'string', 'null' ) ),
						'message'     => array( 'type' => 'string' ),
						'created'     => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_push_campaign' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);

		// ── Campaign Management ──
		wp_register_ability(
			'datamachine/sendy-list-campaigns',
			array(
				'label'               => __( 'Sendy: List Campaigns', 'data-machine-business' ),
				'description'         => __( 'List Sendy campaigns from the configured database.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'offset'   => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'sent', 'draft', 'scheduled' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'total', 'per_page', 'offset', 'campaigns' ),
					'properties' => array(
						'total'     => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
						'offset'    => array( 'type' => 'integer' ),
						'campaigns' => array(
							'type'  => 'array',
							'items' => $this->campaign_summary_schema(),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute_list_campaigns' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		$campaign_detail_schema                             = $this->campaign_summary_schema();
		$campaign_detail_schema['properties']['from_name']  = array( 'type' => 'string' );
		$campaign_detail_schema['properties']['from_email'] = array( 'type' => 'string' );
		$campaign_detail_schema['properties']['reply_to']   = array( 'type' => 'string' );
		$campaign_detail_schema['properties']['errors']     = array( 'type' => 'string' );
		$campaign_detail_schema['required'][]               = 'from_name';
		$campaign_detail_schema['required'][]               = 'from_email';
		$campaign_detail_schema['required'][]               = 'reply_to';
		$campaign_detail_schema['required'][]               = 'errors';

		wp_register_ability(
			'datamachine/sendy-get-campaign',
			array(
				'label'               => __( 'Sendy: Get Campaign', 'data-machine-business' ),
				'description'         => __( 'Get one Sendy campaign from the configured database.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'campaign_id' ),
					'properties'           => array(
						'campaign_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => $campaign_detail_schema,
				'execute_callback'    => array( $this, 'execute_get_campaign' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'datamachine/sendy-delete-campaign',
			array(
				'label'               => __( 'Sendy: Delete Campaign', 'data-machine-business' ),
				'description'         => __( 'Delete a Sendy draft campaign. Sent and sending campaigns are protected.', 'data-machine-business' ),
				'category'            => 'datamachine-publishing',
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'campaign_id' ),
					'properties'           => array(
						'campaign_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'success', 'message' ),
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_delete_campaign' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);

		// ── Metrics (email funnel) ──
		wp_register_ability(
			'datamachine/sendy-metrics',
			array(
				'label'               => __( 'Sendy: Email-Funnel Metrics', 'data-machine-business' ),
				'description'         => __( 'Read-only email-funnel metrics from Sendy: per-list active subscribers and unsubscribe rate, per-campaign open/click rates, and aggregate engagement. Reads the Sendy database when configured.', 'data-machine-business' ),
				'category'            => 'datamachine-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'config' ),
					'properties' => array(
						'config'         => $this->config_schema(),
						'metric'         => array(
							'type'        => 'string',
							'enum'        => array( 'funnel', 'subscribers', 'campaigns' ),
							'description' => __( '"funnel" (default) returns the full picture; "subscribers" returns list/subscriber stats only; "campaigns" returns recent-campaign engagement only.', 'data-machine-business' ),
						),
						'campaign_limit' => array(
							'type'        => 'integer',
							'description' => __( 'Number of recent sent campaigns to analyse. Default 25.', 'data-machine-business' ),
						),
						'source'         => array(
							'type'        => 'string',
							'enum'        => array( 'auto', 'db', 'api' ),
							'description' => __( 'Where to read subscriber stats from. "auto" prefers DB and falls back to API.', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_metrics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Management permission gate for privileged Sendy abilities
	 * (push-campaign, metrics).
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Public permission gate for the subscribe ability.
	 *
	 * Subscribing an email to a list is an inherently public/anonymous action:
	 * it backs the front-end newsletter signup form, which is submitted by
	 * logged-out visitors. Gating it behind a management capability breaks every
	 * real signup (it only "works" via WP-CLI, which bypasses permissions).
	 *
	 * Abuse is already mitigated upstream — the EC signup form is protected by
	 * Cloudflare Turnstile, and Sendy itself dedupes repeat addresses — so this
	 * primitive is intentionally open.
	 *
	 * @return bool
	 */
	public function check_subscribe_permission(): bool {
		return true;
	}

	/**
	 * Build a client from the ability's config input.
	 *
	 * @param array $input Ability input.
	 * @return SendyClient|\WP_Error
	 */
	private function client_from_input( array $input ) {
		$config = isset( $input['config'] ) && is_array( $input['config'] ) ? $input['config'] : array();

		if ( empty( $config['api_key'] ) || empty( $config['sendy_url'] ) ) {
			return new \WP_Error(
				'sendy_config_incomplete',
				__( 'A Sendy config with api_key and sendy_url is required.', 'data-machine-business' )
			);
		}

		return new SendyClient( $config );
	}

	/**
	 * Resolve the DMB-owned Sendy configuration for campaign operations.
	 *
	 * Deployments may provide secrets through the filter instead of persisting
	 * them in the network option. The filter remains owned by this integration;
	 * consumers never pass credentials through an ability input.
	 *
	 * @return array
	 */
	public static function get_campaign_config(): array {
		$config = get_site_option( self::CONFIG_OPTION, array() );
		$config = is_array( $config ) ? $config : array();

		/**
		 * Filter the canonical Sendy connection configuration.
		 *
		 * @param array $config Keys: api_key, sendy_url, and optional db.
		 */
		return apply_filters( 'datamachine_sendy_config', $config );
	}

	/**
	 * Build a campaign client from canonical configuration.
	 *
	 * @param string $requirement Required connection: api or db.
	 * @return SendyClient|\WP_Error
	 */
	private function campaign_client( string $requirement ) {
		$config = self::get_campaign_config();
		if ( empty( $config ) ) {
			return new \WP_Error(
				'sendy_provider_unavailable',
				__( 'The Sendy provider is not configured.', 'data-machine-business' )
			);
		}

		if ( 'api' === $requirement ) {
			if ( empty( $config['api_key'] ) || empty( $config['sendy_url'] ) ) {
				return new \WP_Error(
					'sendy_config_incomplete',
					__( 'The Sendy API configuration requires api_key and sendy_url.', 'data-machine-business' )
				);
			}

			if ( ! wp_http_validate_url( (string) $config['sendy_url'] ) ) {
				return new \WP_Error(
					'sendy_config_invalid',
					__( 'The configured Sendy URL is invalid.', 'data-machine-business' )
				);
			}
		}

		if ( 'db' === $requirement ) {
			$db = isset( $config['db'] ) && is_array( $config['db'] ) ? $config['db'] : array();
			if ( empty( $db['host'] ) || empty( $db['user'] ) || empty( $db['name'] ) ) {
				return new \WP_Error(
					'sendy_db_not_configured',
					__( 'The Sendy database configuration requires host, user, and name.', 'data-machine-business' )
				);
			}
		}

		return new SendyClient( $config );
	}

	/**
	 * Execute datamachine/sendy-subscribe.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_subscribe( array $input ) {
		$client = $this->client_from_input( $input );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$list_id = isset( $input['list_id'] ) ? (string) $input['list_id'] : '';
		$email   = isset( $input['email'] ) ? (string) $input['email'] : '';
		$name    = isset( $input['name'] ) ? (string) $input['name'] : '';
		$fields  = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

		if ( '' === $email ) {
			return new \WP_Error( 'missing_email', __( 'An email address is required.', 'data-machine-business' ) );
		}

		if ( '' === $list_id ) {
			return new \WP_Error( 'missing_list_id', __( 'A Sendy list ID is required.', 'data-machine-business' ) );
		}

		return $client->subscribe( $list_id, $email, $name, $fields );
	}

	/**
	 * Execute datamachine/sendy-push-campaign.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_push_campaign( array $input ) {
		$client = $this->campaign_client( 'api' );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$required = array( 'from_name', 'from_email', 'reply_to', 'subject', 'html_text', 'brand_id' );
		foreach ( $required as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === trim( (string) $input[ $field ] ) ) {
				/* translators: %s: Sendy campaign input field name. */
				$message = sprintf( __( '%s is required.', 'data-machine-business' ), $field );
				return new \WP_Error(
					'sendy_validation_error',
					$message
				);
			}
		}

		if ( ! is_email( (string) $input['from_email'] ) || ! is_email( (string) $input['reply_to'] ) ) {
			return new \WP_Error(
				'sendy_validation_error',
				__( 'from_email and reply_to must be valid email addresses.', 'data-machine-business' )
			);
		}

		if ( isset( $input['campaign_id'] ) && ( ! ctype_digit( (string) $input['campaign_id'] ) || (int) $input['campaign_id'] < 1 ) ) {
			return new \WP_Error(
				'sendy_validation_error',
				__( 'campaign_id must be a positive numeric identifier.', 'data-machine-business' )
			);
		}

		$result = $client->push_campaign(
			array(
				'campaign_id' => isset( $input['campaign_id'] ) ? $input['campaign_id'] : null,
				'from_name'   => isset( $input['from_name'] ) ? (string) $input['from_name'] : '',
				'from_email'  => isset( $input['from_email'] ) ? (string) $input['from_email'] : '',
				'reply_to'    => isset( $input['reply_to'] ) ? (string) $input['reply_to'] : '',
				'subject'     => isset( $input['subject'] ) ? (string) $input['subject'] : '',
				'html_text'   => isset( $input['html_text'] ) ? (string) $input['html_text'] : '',
				'plain_text'  => isset( $input['plain_text'] ) ? (string) $input['plain_text'] : '',
				'brand_id'    => isset( $input['brand_id'] ) ? (string) $input['brand_id'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Raw provider responses are internal diagnostics and may contain secrets.
		unset( $result['raw'] );
		return $result;
	}

	/**
	 * Execute datamachine/sendy-list-campaigns.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_list_campaigns( array $input ) {
		$client = $this->campaign_client( 'db' );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$offset   = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$status   = isset( $input['status'] ) ? (string) $input['status'] : '';
		if ( $per_page < 1 || $per_page > 100 || $offset < 0 || ( '' !== $status && ! in_array( $status, array( 'sent', 'draft', 'scheduled' ), true ) ) ) {
			return new \WP_Error( 'sendy_validation_error', __( 'Invalid campaign list parameters.', 'data-machine-business' ) );
		}

		return $client->list_campaigns(
			array(
				'per_page' => $per_page,
				'offset'   => $offset,
				'status'   => $status,
			)
		);
	}

	/**
	 * Execute datamachine/sendy-get-campaign.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_get_campaign( array $input ) {
		$campaign_id = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;
		if ( $campaign_id < 1 ) {
			return new \WP_Error( 'sendy_validation_error', __( 'campaign_id must be a positive integer.', 'data-machine-business' ) );
		}

		$client = $this->campaign_client( 'db' );
		return is_wp_error( $client ) ? $client : $client->get_campaign( $campaign_id );
	}

	/**
	 * Execute datamachine/sendy-delete-campaign.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_delete_campaign( array $input ) {
		$campaign_id = isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0;
		if ( $campaign_id < 1 ) {
			return new \WP_Error( 'sendy_validation_error', __( 'campaign_id must be a positive integer.', 'data-machine-business' ) );
		}

		$client = $this->campaign_client( 'db' );
		return is_wp_error( $client ) ? $client : $client->delete_campaign( $campaign_id );
	}

	/**
	 * Execute datamachine/sendy-metrics.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute_metrics( array $input ) {
		$client = $this->client_from_input( $input );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$metric = isset( $input['metric'] ) ? (string) $input['metric'] : 'funnel';
		$source = isset( $input['source'] ) ? (string) $input['source'] : 'auto';

		if ( 'subscribers' === $metric ) {
			return $client->subscriber_stats( $source );
		}

		$args = array();
		if ( isset( $input['campaign_limit'] ) ) {
			$args['campaign_limit'] = absint( $input['campaign_limit'] );
		}

		if ( 'campaigns' === $metric ) {
			$result = $client->list_campaigns(
				array(
					'per_page' => isset( $args['campaign_limit'] ) ? $args['campaign_limit'] : 25,
					'status'   => 'sent',
				)
			);
			return $result;
		}

		// Default: full funnel.
		return $client->email_funnel_metrics( $args );
	}
}
