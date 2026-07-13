<?php
/**
 * Sendy Abilities
 *
 * Generic, config-driven abilities for the Sendy email-marketing service —
 * a sibling to the Slack, Discord, and Google integrations in this plugin.
 *
 * Every ability is keyed entirely by the configuration the caller passes in
 * (api_key, sendy_url, and optional read-only db connection details). These
 * abilities have NO knowledge of any particular consumer's list IDs, brand,
 * or content — that policy stays with the consumer. The mechanics live in
 * {@see \DataMachineBusiness\Sendy\SendyClient}.
 *
 * Abilities:
 *  - datamachine/sendy-subscribe       Subscribe an email to a list (API).
 *  - datamachine/sendy-push-campaign   Create/update a campaign (API).
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
					'type'       => 'object',
					'required'   => array( 'config', 'subject' ),
					'properties' => array(
						'config'      => $this->config_schema(),
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
						'extra'       => array(
							'type'        => 'object',
							'description' => __( 'Optional extra Sendy create-campaign parameters.', 'data-machine-business' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( $this, 'execute_push_campaign' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array( 'show_in_rest' => false ),
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
		$client = $this->client_from_input( $input );
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $client->push_campaign(
			array(
				'campaign_id' => isset( $input['campaign_id'] ) ? $input['campaign_id'] : null,
				'from_name'   => isset( $input['from_name'] ) ? (string) $input['from_name'] : '',
				'from_email'  => isset( $input['from_email'] ) ? (string) $input['from_email'] : '',
				'reply_to'    => isset( $input['reply_to'] ) ? (string) $input['reply_to'] : '',
				'subject'     => isset( $input['subject'] ) ? (string) $input['subject'] : '',
				'html_text'   => isset( $input['html_text'] ) ? (string) $input['html_text'] : '',
				'plain_text'  => isset( $input['plain_text'] ) ? (string) $input['plain_text'] : '',
				'brand_id'    => isset( $input['brand_id'] ) ? (string) $input['brand_id'] : '',
				'extra'       => isset( $input['extra'] ) && is_array( $input['extra'] ) ? $input['extra'] : array(),
			)
		);
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
