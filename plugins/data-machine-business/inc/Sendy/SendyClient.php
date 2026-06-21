<?php
/**
 * Generic Sendy Client
 *
 * Config-driven client for the Sendy email-marketing service. Owns the raw
 * mechanics — HTTP API calls (subscribe, campaign create/update, status) and
 * read-only MySQL queries against the Sendy database (lists, subscribers,
 * campaign engagement) — with NO knowledge of any particular consumer.
 *
 * Every method is keyed entirely by the configuration passed into the
 * constructor: a Sendy API key, the installation URL, and (optionally) the
 * read-only database connection details. Consumers (such as a newsletter
 * plugin) supply their own list IDs, brand IDs, and content; this client never
 * hardcodes or assumes any of them.
 *
 * @package DataMachineBusiness
 * @subpackage Sendy
 * @since 0.11.0
 */

namespace DataMachineBusiness\Sendy;

defined( 'ABSPATH' ) || exit;

/**
 * Config-driven client for the generic Sendy mechanics (API + read-only DB).
 */
class SendyClient {

	/**
	 * Sendy API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Sendy installation URL (no trailing slash required).
	 *
	 * @var string
	 */
	private string $sendy_url;

	/**
	 * Read-only Sendy database connection details.
	 *
	 * Keys: host, user, pass, name, port. Empty when DB access is not
	 * configured — in which case the DB-backed read methods return a
	 * WP_Error and callers may fall back to the HTTP API where available.
	 *
	 * @var array
	 */
	private array $db_config;

	/**
	 * Lazily-instantiated wpdb instance bound to the Sendy database.
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $db = null;

	/**
	 * Build a client from a generic config array.
	 *
	 * Expected keys: api_key (string), sendy_url (string), and optionally
	 * db (array of host, user, pass, name, port).
	 *
	 * @param array $config Sendy connection configuration.
	 */
	public function __construct( array $config ) {
		$this->api_key   = isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
		$this->sendy_url = isset( $config['sendy_url'] ) ? untrailingslashit( (string) $config['sendy_url'] ) : '';

		$db              = isset( $config['db'] ) && is_array( $config['db'] ) ? $config['db'] : array();
		$this->db_config = wp_parse_args(
			$db,
			array(
				'host' => '',
				'user' => '',
				'pass' => '',
				'name' => '',
				'port' => '',
			)
		);
	}

	/**
	 * Whether the API credentials are present.
	 *
	 * @return bool
	 */
	public function has_api_config(): bool {
		return '' !== $this->api_key && '' !== $this->sendy_url;
	}

	/**
	 * Whether read-only DB credentials are present.
	 *
	 * @return bool
	 */
	public function has_db_config(): bool {
		return '' !== $this->db_config['host']
			&& '' !== $this->db_config['user']
			&& '' !== $this->db_config['name'];
	}

	// ─── API mechanics ──────────────────────────────────────────────────────

	/**
	 * Subscribe an email address to a Sendy list.
	 *
	 * @param string $list_id Sendy list ID (encrypted hash).
	 * @param string $email   Email address to subscribe.
	 * @param string $name    Optional subscriber name.
	 * @param array  $extra   Optional extra fields passed straight to Sendy
	 *                        (e.g. custom fields). Reserved keys are ignored.
	 * @return array {
	 *     @type bool   $success Whether the subscription succeeded.
	 *     @type string $status  Normalised status: subscribed, already_subscribed,
	 *                           invalid, failed, error.
	 *     @type string $message Human-readable message.
	 *     @type string $raw     Raw response body (for diagnostics).
	 * }
	 */
	public function subscribe( string $list_id, string $email, string $name = '', array $extra = array() ): array {
		if ( ! $this->has_api_config() ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'message' => 'Sendy API key or URL not configured.',
				'raw'     => '',
			);
		}

		if ( '' === $list_id ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'message' => 'A Sendy list ID is required.',
				'raw'     => '',
			);
		}

		$body = array(
			'email'   => $email,
			'list'    => $list_id,
			'boolean' => 'true',
			'api_key' => $this->api_key,
		);

		if ( '' !== $name ) {
			$body['name'] = sanitize_text_field( $name );
		}

		$reserved = array( 'email', 'list', 'boolean', 'api_key', 'name' );
		foreach ( $extra as $key => $value ) {
			if ( ! in_array( $key, $reserved, true ) ) {
				$body[ $key ] = $value;
			}
		}

		$response = wp_remote_post(
			$this->sendy_url . '/subscribe',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'message' => $response->get_error_message(),
				'raw'     => '',
			);
		}

		$raw = wp_remote_retrieve_body( $response );

		if ( '1' === $raw || false !== strpos( $raw, 'Success' ) ) {
			return array(
				'success' => true,
				'status'  => 'subscribed',
				'message' => 'Subscribed successfully.',
				'raw'     => $raw,
			);
		}

		if ( false !== strpos( $raw, 'Already subscribed' ) ) {
			return array(
				'success' => false,
				'status'  => 'already_subscribed',
				'message' => 'Email already subscribed.',
				'raw'     => $raw,
			);
		}

		if ( false !== strpos( $raw, 'Invalid' ) ) {
			return array(
				'success' => false,
				'status'  => 'invalid',
				'message' => 'Invalid email address.',
				'raw'     => $raw,
			);
		}

		return array(
			'success' => false,
			'status'  => 'failed',
			'message' => 'Subscription failed.',
			'raw'     => $raw,
		);
	}

	/**
	 * Check a subscriber's status in a Sendy list via the API.
	 *
	 * @param string $list_id Sendy list ID (encrypted hash).
	 * @param string $email   Email address.
	 * @return string|\WP_Error Status string from Sendy or WP_Error on transport failure.
	 */
	public function subscriber_status( string $list_id, string $email ) {
		if ( ! $this->has_api_config() ) {
			return new \WP_Error( 'sendy_config_incomplete', 'Sendy API key or URL not configured.' );
		}

		$response = wp_remote_post(
			$this->sendy_url . '/api/subscribers/subscription-status.php',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'api_key' => $this->api_key,
					'email'   => $email,
					'list_id' => $list_id,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return trim( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Create or update a Sendy campaign.
	 *
	 * When $campaign_id is supplied and the campaign exists, it is updated;
	 * otherwise a new campaign is created. The caller owns the content (subject,
	 * html, plain text) and sender identity — this method just transports it.
	 *
	 * Campaign keys: from_name, from_email, reply_to, subject, html_text,
	 * plain_text, brand_id (all strings); campaign_id (string|null — existing
	 * campaign to update, omit to create); and extra (array of additional Sendy
	 * create-campaign params).
	 *
	 * @param array $campaign Campaign content and sender identity.
	 * @return array Result keyed by success (bool), campaign_id (string|null),
	 *               message (string), created (bool), raw (string).
	 */
	public function push_campaign( array $campaign ): array {
		if ( ! $this->has_api_config() ) {
			return array(
				'success'     => false,
				'campaign_id' => null,
				'message'     => 'Sendy API key or URL not configured.',
				'created'     => false,
				'raw'         => '',
			);
		}

		$campaign_id = isset( $campaign['campaign_id'] ) && '' !== (string) $campaign['campaign_id']
			? (string) $campaign['campaign_id']
			: null;

		// Determine whether an existing campaign should be updated.
		$endpoint = '/api/campaigns/create.php';
		if ( $campaign_id ) {
			$check = wp_remote_post(
				$this->sendy_url . '/api/campaigns/status.php',
				array(
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => array(
						'api_key'     => $this->api_key,
						'campaign_id' => $campaign_id,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $check ) ) {
				return array(
					'success'     => false,
					'campaign_id' => $campaign_id,
					'message'     => 'Failed to check campaign status: ' . $check->get_error_message(),
					'created'     => false,
					'raw'         => '',
				);
			}

			if ( 'Campaign exists' === trim( wp_remote_retrieve_body( $check ) ) ) {
				$endpoint = '/api/campaigns/update.php';
			} else {
				$campaign_id = null;
			}
		}

		$body = array(
			'api_key'    => $this->api_key,
			'from_name'  => isset( $campaign['from_name'] ) ? $campaign['from_name'] : '',
			'from_email' => isset( $campaign['from_email'] ) ? $campaign['from_email'] : '',
			'reply_to'   => isset( $campaign['reply_to'] ) ? $campaign['reply_to'] : '',
			'subject'    => isset( $campaign['subject'] ) ? $campaign['subject'] : '',
			'plain_text' => isset( $campaign['plain_text'] ) ? $campaign['plain_text'] : '',
			'html_text'  => isset( $campaign['html_text'] ) ? $campaign['html_text'] : '',
			'brand_id'   => isset( $campaign['brand_id'] ) ? $campaign['brand_id'] : '',
		);

		if ( isset( $campaign['extra'] ) && is_array( $campaign['extra'] ) ) {
			foreach ( $campaign['extra'] as $key => $value ) {
				if ( ! isset( $body[ $key ] ) ) {
					$body[ $key ] = $value;
				}
			}
		}

		if ( $campaign_id ) {
			$body['campaign_id'] = $campaign_id;
		}

		$response = wp_remote_post(
			$this->sendy_url . $endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'     => false,
				'campaign_id' => $campaign_id,
				'message'     => $response->get_error_message(),
				'created'     => false,
				'raw'         => '',
			);
		}

		$raw     = wp_remote_retrieve_body( $response );
		$created = false;

		// A newly-created campaign returns its numeric ID.
		if ( ! $campaign_id && is_numeric( trim( $raw ) ) ) {
			$campaign_id = (string) trim( $raw );
			$created     = true;
		}

		// Sendy returns the campaign ID (numeric) on success, or an error string.
		$success = $created || ( '/api/campaigns/update.php' === $endpoint && false === stripos( $raw, 'error' ) );

		return array(
			'success'     => $success,
			'campaign_id' => $campaign_id,
			'message'     => $success ? 'Campaign pushed to Sendy successfully.' : trim( $raw ),
			'created'     => $created,
			'raw'         => $raw,
		);
	}

	// ─── DB mechanics (read-only) ───────────────────────────────────────────

	/**
	 * Get a wpdb instance bound to the read-only Sendy database.
	 *
	 * @return \wpdb|\WP_Error
	 */
	public function get_db() {
		if ( null !== $this->db ) {
			return $this->db;
		}

		if ( ! $this->has_db_config() ) {
			return new \WP_Error(
				'sendy_db_not_configured',
				'Sendy database credentials are not configured.'
			);
		}

		$host = $this->db_config['host'];
		if ( '' !== $this->db_config['port'] ) {
			$host .= ':' . $this->db_config['port'];
		}

		$this->db = new \wpdb(
			$this->db_config['user'],
			$this->db_config['pass'],
			$this->db_config['name'],
			$host
		);

		return $this->db;
	}

	/**
	 * List Sendy campaigns from the read-only DB.
	 *
	 * @param array $args {per_page, offset, status}.
	 * @return array|\WP_Error {total, per_page, offset, campaigns[]}.
	 */
	public function list_campaigns( array $args = array() ) {
		$per_page = isset( $args['per_page'] ) ? absint( $args['per_page'] ) : 20;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$status   = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';

		$db = $this->get_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		$where = '';
		if ( 'sent' === $status ) {
			$where = 'WHERE sent != "" AND sent IS NOT NULL';
		} elseif ( 'draft' === $status ) {
			$where = 'WHERE (sent = "" OR sent IS NULL) AND (send_date = "" OR send_date IS NULL)';
		} elseif ( 'scheduled' === $status ) {
			$where = 'WHERE send_date != "" AND send_date IS NOT NULL AND send_date != 0';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from a fixed allow-list above.
		$total = (int) $db->get_var( "SELECT COUNT(*) FROM campaigns {$where}" );

		$rows = $db->get_results(
			$db->prepare(
				"SELECT id, title, sent, to_send, recipients, opens, clicks, send_date, lists, opens_tracking, links_tracking, campaign_stopped FROM campaigns {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$campaigns = array();
		foreach ( (array) $rows as $c ) {
			$campaigns[] = $this->shape_campaign_summary( $c );
		}

		return array(
			'total'     => $total,
			'per_page'  => $per_page,
			'offset'    => $offset,
			'campaigns' => $campaigns,
		);
	}

	/**
	 * Get a single Sendy campaign by ID from the read-only DB.
	 *
	 * @param int $campaign_id Campaign primary key.
	 * @return array|\WP_Error
	 */
	public function get_campaign( int $campaign_id ) {
		if ( $campaign_id <= 0 ) {
			return new \WP_Error( 'missing_campaign_id', 'campaign_id is required.' );
		}

		$db = $this->get_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		$campaign = $db->get_row(
			$db->prepare( 'SELECT * FROM campaigns WHERE id = %d', $campaign_id ),
			ARRAY_A
		);

		if ( ! $campaign ) {
			return new \WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		$summary               = $this->shape_campaign_summary( $campaign );
		$summary['from_name']  = isset( $campaign['from_name'] ) ? $campaign['from_name'] : '';
		$summary['from_email'] = isset( $campaign['from_email'] ) ? $campaign['from_email'] : '';
		$summary['reply_to']   = isset( $campaign['reply_to'] ) ? $campaign['reply_to'] : '';
		$summary['errors']     = isset( $campaign['errors'] ) ? $campaign['errors'] : '';

		return $summary;
	}

	/**
	 * Delete a Sendy campaign draft from the DB.
	 *
	 * Sent campaigns are protected and cannot be deleted.
	 *
	 * @param int $campaign_id Campaign primary key.
	 * @return array|\WP_Error {success, message}.
	 */
	public function delete_campaign( int $campaign_id ) {
		if ( $campaign_id <= 0 ) {
			return new \WP_Error( 'missing_campaign_id', 'campaign_id is required.' );
		}

		$db = $this->get_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		$campaign = $db->get_row(
			$db->prepare( 'SELECT id, sent, send_date, to_send, recipients FROM campaigns WHERE id = %d', $campaign_id ),
			ARRAY_A
		);

		if ( ! $campaign ) {
			return new \WP_Error( 'campaign_not_found', 'Campaign not found.' );
		}

		$status = $this->campaign_status( $campaign );
		if ( 'sent' === $status || 'sending' === $status ) {
			return new \WP_Error(
				'cannot_delete_sent',
				'Cannot delete a sent or sending campaign.'
			);
		}

		$db->delete( 'campaigns', array( 'id' => $campaign_id ), array( '%d' ) );

		return array(
			'success' => true,
			'message' => sprintf( 'Campaign %d (%s) deleted.', $campaign_id, $status ),
		);
	}

	/**
	 * Active-subscriber stats via the read-only DB.
	 *
	 * "Active" matches Sendy's definition: confirmed = 1 AND unsubscribed = 0
	 * AND bounced = 0 AND complaint = 0.
	 *
	 * @return array|\WP_Error {total_active, list_count, lists[], source}.
	 */
	public function subscriber_stats_via_db() {
		$db = $this->get_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		$total = $db->get_var(
			'SELECT COUNT(*) FROM subscribers
			 WHERE confirmed = 1 AND unsubscribed = 0 AND bounced = 0 AND complaint = 0'
		);

		if ( null === $total ) {
			return new \WP_Error( 'sendy_db_query_failed', 'Failed to query Sendy subscribers table.' );
		}

		$rows = $db->get_results(
			'SELECT l.id, l.name, COUNT(s.id) AS active
			 FROM lists l
			 LEFT JOIN subscribers s
			   ON s.list = l.id
			  AND s.confirmed = 1
			  AND s.unsubscribed = 0
			  AND s.bounced = 0
			  AND s.complaint = 0
			 GROUP BY l.id, l.name
			 ORDER BY active DESC, l.name ASC',
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error( 'sendy_db_query_failed', 'Failed to query Sendy lists table.' );
		}

		$lists = array();
		foreach ( (array) $rows as $row ) {
			$lists[] = array(
				'id'     => (int) $row['id'],
				'name'   => (string) $row['name'],
				'active' => (int) $row['active'],
			);
		}

		return array(
			'total_active' => (int) $total,
			'list_count'   => count( $lists ),
			'lists'        => $lists,
			'source'       => 'db',
		);
	}

	/**
	 * Active-subscriber stats via the HTTP API (brands → lists → counts).
	 *
	 * Slower fallback for when DB access is unavailable.
	 *
	 * @return array|\WP_Error {total_active, list_count, lists[], source}.
	 */
	public function subscriber_stats_via_api() {
		if ( ! $this->has_api_config() ) {
			return new \WP_Error( 'sendy_config_incomplete', 'Sendy API key or URL not configured.' );
		}

		$brands_response = wp_remote_post(
			$this->sendy_url . '/api/brands/get-brands.php',
			array(
				'timeout' => 15,
				'body'    => array( 'api_key' => $this->api_key ),
			)
		);

		if ( is_wp_error( $brands_response ) ) {
			return $brands_response;
		}

		$brands = json_decode( wp_remote_retrieve_body( $brands_response ), true );
		if ( ! is_array( $brands ) ) {
			return new \WP_Error( 'sendy_api_invalid_brands', 'Unexpected response from Sendy get-brands API.' );
		}

		$lists = array();
		foreach ( $brands as $brand ) {
			if ( empty( $brand['id'] ) ) {
				continue;
			}

			$lists_response = wp_remote_post(
				$this->sendy_url . '/api/lists/get-lists.php',
				array(
					'timeout' => 15,
					'body'    => array(
						'api_key'        => $this->api_key,
						'brand_id'       => $brand['id'],
						'include_hidden' => 'yes',
					),
				)
			);

			if ( is_wp_error( $lists_response ) ) {
				continue;
			}

			$brand_lists = json_decode( wp_remote_retrieve_body( $lists_response ), true );
			if ( ! is_array( $brand_lists ) ) {
				continue;
			}

			foreach ( $brand_lists as $list ) {
				if ( empty( $list['id'] ) ) {
					continue;
				}
				$lists[] = array(
					'id'   => (string) $list['id'],
					'name' => isset( $list['name'] ) ? (string) $list['name'] : '',
				);
			}
		}

		$total = 0;
		$out   = array();
		foreach ( $lists as $list ) {
			$count_response = wp_remote_post(
				$this->sendy_url . '/api/subscribers/active-subscriber-count.php',
				array(
					'timeout' => 15,
					'body'    => array(
						'api_key' => $this->api_key,
						'list_id' => $list['id'],
					),
				)
			);

			$active = 0;
			if ( ! is_wp_error( $count_response ) ) {
				$body = trim( (string) wp_remote_retrieve_body( $count_response ) );
				if ( ctype_digit( $body ) ) {
					$active = (int) $body;
				}
			}

			$total += $active;
			$out[]  = array(
				'id'     => $list['id'],
				'name'   => $list['name'],
				'active' => $active,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['active'] <=> $a['active'];
			}
		);

		return array(
			'total_active' => $total,
			'list_count'   => count( $out ),
			'lists'        => $out,
			'source'       => 'api',
		);
	}

	/**
	 * Active-subscriber stats with automatic DB→API fallback.
	 *
	 * @param string $source 'auto' (default), 'db', or 'api'.
	 * @return array|\WP_Error
	 */
	public function subscriber_stats( string $source = 'auto' ) {
		if ( ! in_array( $source, array( 'auto', 'db', 'api' ), true ) ) {
			$source = 'auto';
		}

		if ( 'api' === $source ) {
			return $this->subscriber_stats_via_api();
		}

		$result = $this->subscriber_stats_via_db();
		if ( is_wp_error( $result ) && 'auto' === $source ) {
			return $this->subscriber_stats_via_api();
		}

		return $result;
	}

	/**
	 * Compose the email-funnel metrics: list growth + per-campaign engagement.
	 *
	 * Generic, config-driven. Reads the read-only Sendy DB for the heavy
	 * aggregates and computes open/click/unsubscribe rates from the raw counts
	 * Sendy stores on each campaign row plus per-list subscriber state.
	 *
	 * Accepts an optional campaign_limit (int — number of recent sent campaigns
	 * to analyse, default 25).
	 *
	 * @param array $args Optional arguments.
	 * @return array|\WP_Error Funnel metrics: lists, total_active, campaigns,
	 *                         aggregate, source, generated_at; or WP_Error.
	 */
	public function email_funnel_metrics( array $args = array() ) {
		$campaign_limit = isset( $args['campaign_limit'] ) ? absint( $args['campaign_limit'] ) : 25;
		if ( $campaign_limit < 1 ) {
			$campaign_limit = 25;
		}

		$db = $this->get_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		// ── List growth + unsubscribe state ──
		$list_rows = $db->get_results(
			'SELECT l.id, l.name,
			        SUM(CASE WHEN s.confirmed = 1 AND s.unsubscribed = 0 AND s.bounced = 0 AND s.complaint = 0 THEN 1 ELSE 0 END) AS active,
			        SUM(CASE WHEN s.unsubscribed = 1 THEN 1 ELSE 0 END) AS unsubscribed,
			        SUM(CASE WHEN s.bounced = 1 THEN 1 ELSE 0 END) AS bounced,
			        COUNT(s.id) AS total
			 FROM lists l
			 LEFT JOIN subscribers s ON s.list = l.id
			 GROUP BY l.id, l.name
			 ORDER BY active DESC, l.name ASC',
			ARRAY_A
		);

		if ( null === $list_rows ) {
			return new \WP_Error( 'sendy_db_query_failed', 'Failed to query Sendy lists/subscribers.' );
		}

		$lists        = array();
		$total_active = 0;
		foreach ( (array) $list_rows as $row ) {
			$active        = (int) $row['active'];
			$total         = (int) $row['total'];
			$unsubscribed  = (int) $row['unsubscribed'];
			$total_active += $active;

			$lists[] = array(
				'id'               => (int) $row['id'],
				'name'             => (string) $row['name'],
				'active'           => $active,
				'unsubscribed'     => $unsubscribed,
				'bounced'          => (int) $row['bounced'],
				'total'            => $total,
				'unsubscribe_rate' => $total > 0 ? round( $unsubscribed / $total, 4 ) : 0.0,
			);
		}

		// ── Per-campaign engagement (recent sent campaigns) ──
		$campaign_rows = $db->get_results(
			$db->prepare(
				'SELECT id, title, sent, recipients, opens, clicks
				 FROM campaigns
				 WHERE sent != "" AND sent IS NOT NULL AND sent != 0
				 ORDER BY sent DESC
				 LIMIT %d',
				$campaign_limit
			),
			ARRAY_A
		);

		$campaigns      = array();
		$sum_open_rate  = 0.0;
		$sum_click_rate = 0.0;
		$analysed       = 0;
		foreach ( (array) $campaign_rows as $c ) {
			$recipients = (int) $c['recipients'];
			$opens      = (int) $c['opens'];
			$clicks     = (int) $c['clicks'];

			$open_rate  = $recipients > 0 ? round( $opens / $recipients, 4 ) : 0.0;
			$click_rate = $recipients > 0 ? round( $clicks / $recipients, 4 ) : 0.0;

			$sum_open_rate  += $open_rate;
			$sum_click_rate += $click_rate;
			$analysed++;

			$campaigns[] = array(
				'id'         => (int) $c['id'],
				'title'      => (string) $c['title'],
				'sent_date'  => $c['sent'] ? gmdate( 'Y-m-d H:i:s', (int) $c['sent'] ) : null,
				'recipients' => $recipients,
				'opens'      => $opens,
				'clicks'     => $clicks,
				'open_rate'  => $open_rate,
				'click_rate' => $click_rate,
			);
		}

		// ── Aggregate engagement + best/worst by open rate ──
		$best  = null;
		$worst = null;
		foreach ( $campaigns as $c ) {
			if ( null === $best || $c['open_rate'] > $best['open_rate'] ) {
				$best = $c;
			}
			if ( null === $worst || $c['open_rate'] < $worst['open_rate'] ) {
				$worst = $c;
			}
		}

		$aggregate = array(
			'campaigns_analysed' => $analysed,
			'avg_open_rate'      => $analysed > 0 ? round( $sum_open_rate / $analysed, 4 ) : 0.0,
			'avg_click_rate'     => $analysed > 0 ? round( $sum_click_rate / $analysed, 4 ) : 0.0,
			'best_campaign'      => $best,
			'worst_campaign'     => $worst,
		);

		return array(
			'total_active' => $total_active,
			'list_count'   => count( $lists ),
			'lists'        => $lists,
			'campaigns'    => $campaigns,
			'aggregate'    => $aggregate,
			'source'       => 'db',
			'generated_at' => gmdate( 'c' ),
		);
	}

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Shape a raw campaign DB row into a normalised summary.
	 *
	 * @param array $c Raw campaign row.
	 * @return array
	 */
	private function shape_campaign_summary( array $c ): array {
		$recipients = isset( $c['recipients'] ) ? (int) $c['recipients'] : 0;
		$opens      = isset( $c['opens'] ) ? (int) $c['opens'] : 0;
		$clicks     = isset( $c['clicks'] ) ? (int) $c['clicks'] : 0;

		return array(
			'id'             => isset( $c['id'] ) ? (int) $c['id'] : 0,
			'title'          => isset( $c['title'] ) ? (string) $c['title'] : '',
			'status'         => $this->campaign_status( $c ),
			'sent'           => ! empty( $c['sent'] ) ? (int) $c['sent'] : null,
			'sent_date'      => ! empty( $c['sent'] ) ? gmdate( 'Y-m-d H:i:s', (int) $c['sent'] ) : null,
			'scheduled_date' => ( ! empty( $c['send_date'] ) && '0' !== (string) $c['send_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $c['send_date'] ) : null,
			'to_send'        => isset( $c['to_send'] ) ? (int) $c['to_send'] : 0,
			'recipients'     => $recipients,
			'opens'          => $opens,
			'clicks'         => $clicks,
			'open_rate'      => $recipients > 0 ? round( $opens / $recipients, 4 ) : 0.0,
			'click_rate'     => $recipients > 0 ? round( $clicks / $recipients, 4 ) : 0.0,
			'opens_tracking' => isset( $c['opens_tracking'] ) ? (bool) $c['opens_tracking'] : false,
			'links_tracking' => isset( $c['links_tracking'] ) ? (bool) $c['links_tracking'] : false,
			'stopped'        => isset( $c['campaign_stopped'] ) ? (bool) $c['campaign_stopped'] : false,
		);
	}

	/**
	 * Determine campaign status from a raw DB row.
	 *
	 * @param array $campaign Raw campaign row.
	 * @return string sent|sending|scheduled|draft.
	 */
	private function campaign_status( array $campaign ): string {
		if ( ! empty( $campaign['sent'] ) && '0' !== (string) $campaign['sent'] ) {
			if ( isset( $campaign['to_send'], $campaign['recipients'] ) && (int) $campaign['to_send'] > (int) $campaign['recipients'] ) {
				return 'sending';
			}
			return 'sent';
		}

		if ( ! empty( $campaign['send_date'] ) && '0' !== (string) $campaign['send_date'] ) {
			return 'scheduled';
		}

		return 'draft';
	}
}
