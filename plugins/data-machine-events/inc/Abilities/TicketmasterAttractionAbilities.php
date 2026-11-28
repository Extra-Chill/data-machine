<?php
/**
 * Ticketmaster Attraction Lookup Ability
 *
 * Resolves an act name to Ticketmaster Discovery attractions so callers can
 * configure the Ticketmaster handler's `attraction_ids` (artist mode) by
 * name instead of hand-copying IDs. Read-only.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers data-machine-events/find-ticketmaster-attractions.
 */
class TicketmasterAttractionAbilities {

	private const API_URL = 'https://app.ticketmaster.com/discovery/v2/attractions.json';

	/**
	 * Registration guard.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/** Hook ability registration once. */
	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register the ability. */
	public function register(): void {
		wp_register_ability(
			'data-machine-events/find-ticketmaster-attractions',
			array(
				'label'               => __( 'Find Ticketmaster Attractions', 'data-machine-events' ),
				'description'         => __( 'Look up Ticketmaster attractions (acts) by name, with upcoming event counts and an exact-name flag. Use the returned id in the Ticketmaster handler attraction_ids setting.', 'data-machine-events' ),
				'category'            => AbilityCategories::EVENTS,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'                => array(
							'type'        => 'string',
							'description' => 'Act name to search for.',
						),
						'classification_name' => array(
							'type'        => 'string',
							'description' => 'Ticketmaster classification filter (default: music).',
						),
						'limit'               => array(
							'type'        => 'integer',
							'description' => 'Max attractions to return (1-20, default 5).',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attractions' => array( 'type' => 'array' ),
						'best_match'  => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				// Data Machine's permission helper honors CLI and run_as_system()
				// so scheduled flow-maintenance jobs can call this lookup.
				'permission_callback' => static function () {
					return \DataMachine\Abilities\PermissionHelper::can( 'manage_flows' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute the lookup.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		$name = trim( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'missing_name', 'A name is required.', array( 'status' => 400 ) );
		}

		$api_key = (string) ( ( new TicketmasterAuth() )->get_account()['api_key'] ?? '' );
		if ( '' === $api_key ) {
			return new \WP_Error( 'api_key_not_configured', 'Ticketmaster API key not configured.', array( 'status' => 500 ) );
		}

		$limit  = max( 1, min( 20, (int) ( $input['limit'] ?? 5 ) ) );
		$params = array(
			'apikey'  => $api_key,
			'keyword' => $name,
			'size'    => $limit,
		);
		$class  = (string) ( $input['classification_name'] ?? 'music' );
		if ( '' !== $class ) {
			$params['classificationName'] = $class;
		}

		$result = \DataMachine\Core\HttpClient::get(
			self::API_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'context' => 'Ticketmaster Attractions',
			)
		);

		if ( empty( $result['success'] ) || 200 !== (int) ( $result['status_code'] ?? 0 ) ) {
			$status = (int) ( $result['status_code'] ?? 0 );
			return new \WP_Error(
				429 === $status ? 'rate_limited' : 'request_failed',
				sprintf( 'Ticketmaster attraction lookup failed (HTTP %d).', $status ),
				array( 'status' => 429 === $status ? 429 : 502 )
			);
		}

		$data = json_decode( (string) ( $result['data'] ?? '' ), true );

		return self::parse_response( is_array( $data ) ? $data : array(), $name );
	}

	/**
	 * Shape a Discovery attractions response.
	 *
	 * The best_match is the exact-name attraction (case, leading "The" and
	 * punctuation ignored) with the most upcoming events, or null. Fuzzy
	 * keyword hits are never promoted to best_match.
	 *
	 * @param array  $data Decoded response.
	 * @param string $name Searched name.
	 * @return array{attractions: array, best_match: array|null}
	 */
	public static function parse_response( array $data, string $name ): array {
		$wanted      = self::normalize_name( $name );
		$attractions = array();
		$best        = null;

		foreach ( (array) ( $data['_embedded']['attractions'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
				continue;
			}
			$row = array(
				'id'       => (string) $raw['id'],
				'name'     => (string) ( $raw['name'] ?? '' ),
				'upcoming' => (int) ( $raw['upcomingEvents']['_total'] ?? 0 ),
				'genre'    => (string) ( $raw['classifications'][0]['genre']['name'] ?? '' ),
				'url'      => (string) ( $raw['url'] ?? '' ),
				'exact'    => self::normalize_name( (string) ( $raw['name'] ?? '' ) ) === $wanted,
			);

			$attractions[] = $row;
			if ( $row['exact'] && ( null === $best || $row['upcoming'] > $best['upcoming'] ) ) {
				$best = $row;
			}
		}

		return array(
			'attractions' => $attractions,
			'best_match'  => $best,
		);
	}

	/**
	 * Name key for exact matching.
	 *
	 * @param string $name Act name.
	 * @return string
	 */
	public static function normalize_name( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = mb_strtolower( trim( $name ) );
		$name = (string) preg_replace( '/^the\s+/u', '', $name );
		$name = str_replace( array( '&', '+' ), ' and ', $name );
		$name = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );

		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}
}
