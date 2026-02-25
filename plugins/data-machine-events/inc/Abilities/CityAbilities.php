<?php
/**
 * City Abilities
 *
 * Provides the ability to add a city to the events calendar by geocoding
 * the location, creating a pipeline, and setting up Ticketmaster and Dice.fm
 * flows automatically. CLI commands, REST endpoints, and chat tools delegate
 * to this ability.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Api\Controllers\Geocoding;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CityAbilities {

	/**
	 * Default Ticketmaster search radius in miles.
	 */
	private const DEFAULT_RADIUS = '50';

	/**
	 * Default scheduling interval for city flows.
	 */
	private const DEFAULT_INTERVAL = 'every_6_hours';

	/**
	 * Default AI model for event processing.
	 */
	private const DEFAULT_AI_MODEL = 'gpt-5-mini';

	/**
	 * Default AI provider.
	 */
	private const DEFAULT_AI_PROVIDER = 'openai';

	/**
	 * Default post author for published events.
	 */
	private const DEFAULT_POST_AUTHOR = 32;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine-events/add-city',
				array(
					'label'               => __( 'Add City', 'datamachine-events' ),
					'description'         => __( 'Add a city to the events calendar. Geocodes the location, creates a pipeline, and sets up Ticketmaster and Dice.fm flows.', 'datamachine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'city' ),
						'properties' => array(
							'city'         => array(
								'type'        => 'string',
								'description' => 'City name with state, e.g. "Nashville, TN" or "Portland, OR"',
							),
							'radius'       => array(
								'type'        => 'string',
								'description' => 'Ticketmaster search radius in miles. Defaults to 50.',
							),
							'interval'     => array(
								'type'        => 'string',
								'description' => 'Scheduling interval for flows. Defaults to every_6_hours.',
							),
							'skip_dice'    => array(
								'type'        => 'boolean',
								'description' => 'Skip creating a Dice.fm flow. Defaults to false.',
							),
							'dry_run'      => array(
								'type'        => 'boolean',
								'description' => 'Preview what would be created without making changes.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'message'     => array( 'type' => 'string' ),
							'city'        => array( 'type' => 'string' ),
							'coordinates' => array( 'type' => 'string' ),
							'pipeline_id' => array( 'type' => 'integer' ),
							'flows'       => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'executeAddCity' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the add-city ability.
	 *
	 * @param array $input City configuration.
	 * @return array Result with pipeline_id, flow details, or error.
	 */
	public function executeAddCity( array $input ): array {
		$city_name = trim( $input['city'] ?? '' );

		if ( empty( $city_name ) ) {
			return array( 'error' => 'City name is required. Example: "Nashville, TN"' );
		}

		$radius   = $input['radius'] ?? self::DEFAULT_RADIUS;
		$interval = $input['interval'] ?? self::DEFAULT_INTERVAL;
		$skip_dice = ! empty( $input['skip_dice'] );
		$dry_run   = ! empty( $input['dry_run'] );

		// Step 1: Geocode the city.
		$geocode_result = $this->geocodeCity( $city_name );
		if ( isset( $geocode_result['error'] ) ) {
			return $geocode_result;
		}

		$coordinates  = $geocode_result['lat'] . ',' . $geocode_result['lon'];
		$display_name = $geocode_result['display_name'];

		// Derive a short city label (e.g. "Nashville" from "Nashville, TN").
		$city_label = $this->deriveCityLabel( $city_name );

		// Derive the location taxonomy term (matches existing pattern).
		$location_term = $city_label;

		if ( $dry_run ) {
			$preview = array(
				'message'     => 'Dry run — no changes made.',
				'city'        => $city_name,
				'city_label'  => $city_label,
				'coordinates' => $coordinates,
				'display_name' => $display_name,
				'pipeline'    => $city_label . ' Events',
				'flows'       => array(
					array(
						'name'    => $city_label . ' Ticketmaster',
						'handler' => 'ticketmaster',
						'config'  => array(
							'classification_type' => 'music',
							'location'            => $coordinates,
							'radius'              => $radius,
						),
					),
				),
				'interval'    => $interval,
			);

			if ( ! $skip_dice ) {
				$preview['flows'][] = array(
					'name'    => $city_label . ' Dice.fm',
					'handler' => 'dice_fm',
					'config'  => array(
						'city' => $city_label,
					),
				);
			}

			return $preview;
		}

		// Step 2: Create the pipeline.
		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $pipeline_ability ) {
			return array( 'error' => 'Data Machine create-pipeline ability not available. Is Data Machine active?' );
		}

		$pipeline_name = $city_label . ' Events';
		$pipeline_result = $pipeline_ability->execute(
			array(
				'pipeline_name' => $pipeline_name,
				'steps'         => array(
					array(
						'step_type' => 'event_import',
						'label'     => 'Event Import',
					),
					array(
						'step_type' => 'ai',
						'label'     => 'AI Agent',
					),
					array(
						'step_type' => 'update',
						'label'     => 'Update',
					),
				),
			)
		);

		if ( isset( $pipeline_result['error'] ) ) {
			return array( 'error' => 'Failed to create pipeline: ' . $pipeline_result['error'] );
		}

		$pipeline_id = $pipeline_result['pipeline_id'] ?? null;
		if ( ! $pipeline_id ) {
			return array( 'error' => 'Pipeline was created but no pipeline_id returned.' );
		}

		// Configure the AI step on the pipeline with city-specific system prompt.
		$this->configurePipelineAiStep( $pipeline_id, $city_label );

		// Step 3: Create Ticketmaster flow.
		$flows_created = array();
		$tm_result = $this->createTicketmasterFlow( $pipeline_id, $city_label, $city_name, $coordinates, $radius, $interval, $location_term );
		if ( isset( $tm_result['error'] ) ) {
			return array(
				'error'       => 'Pipeline created (ID: ' . $pipeline_id . ') but Ticketmaster flow failed: ' . $tm_result['error'],
				'pipeline_id' => $pipeline_id,
			);
		}
		$flows_created[] = $tm_result;

		// Step 4: Create Dice.fm flow (unless skipped).
		if ( ! $skip_dice ) {
			$dice_result = $this->createDiceFmFlow( $pipeline_id, $city_label, $city_name, $interval, $location_term );
			if ( isset( $dice_result['error'] ) ) {
				// Non-fatal — Ticketmaster flow is already created.
				$flows_created[] = array(
					'name'    => $city_label . ' Dice.fm',
					'status'  => 'failed',
					'error'   => $dice_result['error'],
				);
			} else {
				$flows_created[] = $dice_result;
			}
		}

		return array(
			'message'     => 'City added successfully: ' . $city_name,
			'city'        => $city_name,
			'city_label'  => $city_label,
			'coordinates' => $coordinates,
			'pipeline_id' => $pipeline_id,
			'flows'       => $flows_created,
		);
	}

	/**
	 * Geocode a city name to coordinates via Nominatim.
	 */
	private function geocodeCity( string $city_name ): array {
		$geocoding = new Geocoding();
		$request   = new \WP_REST_Request( 'GET' );
		$request->set_param( 'query', $city_name );

		$response = $geocoding->search( $request );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'Geocoding failed: ' . $response->get_error_message() );
		}

		$data = $response->get_data();

		if ( empty( $data['results'] ) ) {
			return array( 'error' => 'No geocoding results for: ' . $city_name );
		}

		$result = $data['results'][0];

		return array(
			'lat'          => $result['lat'],
			'lon'          => $result['lon'],
			'display_name' => $result['display_name'] ?? $city_name,
		);
	}

	/**
	 * Derive a short city label from a "City, ST" input string.
	 */
	private function deriveCityLabel( string $city_name ): string {
		$parts = explode( ',', $city_name );
		return trim( $parts[0] );
	}

	/**
	 * Configure the AI step on a pipeline with a city-specific system prompt.
	 */
	private function configurePipelineAiStep( int $pipeline_id, string $city_label ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$pipeline = $wpdb->get_row(
			$wpdb->prepare( "SELECT pipeline_config FROM {$table} WHERE pipeline_id = %d", $pipeline_id ),
			ARRAY_A
		);

		if ( ! $pipeline ) {
			return;
		}

		$config = json_decode( $pipeline['pipeline_config'], true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as $step_id => &$step ) {
			if ( ( $step['step_type'] ?? '' ) === 'ai' ) {
				$step['provider']       = self::DEFAULT_AI_PROVIDER;
				$step['model']          = self::DEFAULT_AI_MODEL;
				$step['providers']      = array(
					self::DEFAULT_AI_PROVIDER => array( 'model' => self::DEFAULT_AI_MODEL ),
				);
				$step['system_prompt']  = sprintf(
					'You run the Extra Chill events feed for %s. Process the event and prepare it for update to the calendar, assigning the appropriate details to the event based on the available information.

The festival taxonomy should only be used if the event in question is a festival; otherwise ignore it.',
					$city_label
				);
				$step['enabled_tools']  = array();
			}
		}
		unset( $step );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array( 'pipeline_config' => wp_json_encode( $config ) ),
			array( 'pipeline_id' => $pipeline_id )
		);
	}

	/**
	 * Create a Ticketmaster flow for a city.
	 */
	private function createTicketmasterFlow( int $pipeline_id, string $city_label, string $city_full, string $coordinates, string $radius, string $interval, string $location_term ): array {
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $flow_ability ) {
			return array( 'error' => 'Data Machine create-flow ability not available.' );
		}

		$result = $flow_ability->execute(
			array(
				'pipeline_id'      => $pipeline_id,
				'flow_name'        => 'Ticketmaster',
				'scheduling_config' => array(
					'interval' => $interval,
				),
				'step_configs'     => array(
					'event_import' => array(
						'handler_slug'   => 'ticketmaster',
						'handler_config' => array(
							'classification_type' => 'music',
							'location'            => $coordinates,
							'radius'              => $radius,
							'genre'               => '',
							'venue_id'            => '',
							'search'              => '',
							'exclude_keywords'    => '',
						),
					),
					'update' => array(
						'handler_slug'   => 'upsert_event',
						'handler_config' => array(
							'post_status'                   => 'publish',
							'include_images'                => false,
							'post_author'                   => self::DEFAULT_POST_AUTHOR,
							'taxonomy_category_selection'   => 'skip',
							'taxonomy_post_tag_selection'   => 'skip',
							'taxonomy_location_selection'   => $location_term,
							'taxonomy_festival_selection'   => 'ai_decides',
							'taxonomy_artist_selection'     => 'ai_decides',
							'taxonomy_promoter_selection'   => 'skip',
						),
						'user_message' => "IMPORTANT SYSTEM-WIDE RULE: Do not assign WordPress Categories or Tags for events. Always set:\n- taxonomy_category_selection = \"skip\"\n- taxonomy_post_tag_selection = \"skip\"\nIf any source includes categories/tags, ignore them.",
					),
					'ai' => array(
						'user_message' => sprintf(
							'Process this Ticketmaster music event for the %s events calendar',
							$city_full
						),
					),
				),
			)
		);

		if ( isset( $result['error'] ) ) {
			return array( 'error' => $result['error'] );
		}

		$flow_id = $result['flow_id'] ?? null;

		// Patch flow steps directly — step_configs via create-flow may not apply
		// the update step due to ability registration timing in CLI context.
		if ( $flow_id ) {
			$this->patchFlowSteps(
				$flow_id,
				'ticketmaster',
				array(
					'classification_type' => 'music',
					'location'            => $coordinates,
					'radius'              => $radius,
					'genre'               => '',
					'venue_id'            => '',
					'search'              => '',
					'exclude_keywords'    => '',
				),
				$location_term,
				sprintf( 'Process this Ticketmaster music event for the %s events calendar', $city_full )
			);
		}

		return array(
			'name'    => 'Ticketmaster',
			'flow_id' => $flow_id,
			'handler' => 'ticketmaster',
			'status'  => 'created',
		);
	}

	/**
	 * Create a Dice.fm flow for a city.
	 */
	private function createDiceFmFlow( int $pipeline_id, string $city_label, string $city_full, string $interval, string $location_term ): array {
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $flow_ability ) {
			return array( 'error' => 'Data Machine create-flow ability not available.' );
		}

		$result = $flow_ability->execute(
			array(
				'pipeline_id'      => $pipeline_id,
				'flow_name'        => 'Dice.fm',
				'scheduling_config' => array(
					'interval' => $interval,
				),
				'step_configs'     => array(
					'event_import' => array(
						'handler_slug'   => 'dice_fm',
						'handler_config' => array(
							'city'             => $city_label,
							'search'           => '',
							'exclude_keywords' => '',
						),
					),
				),
			)
		);

		if ( isset( $result['error'] ) ) {
			return array( 'error' => $result['error'] );
		}

		$flow_id = $result['flow_id'] ?? null;

		// Patch flow steps directly.
		if ( $flow_id ) {
			$this->patchFlowSteps(
				$flow_id,
				'dice_fm',
				array(
					'city'             => $city_label,
					'search'           => '',
					'exclude_keywords' => '',
				),
				$location_term,
				sprintf( 'Process this Dice.fm event for the %s events calendar.', $city_full )
			);
		}

		return array(
			'name'    => 'Dice.fm',
			'flow_id' => $flow_id,
			'handler' => 'dice_fm',
			'status'  => 'created',
		);
	}

	/**
	 * Directly patch flow step configs in the database.
	 *
	 * This bypasses the update-flow-step ability which may not be registered
	 * early enough in CLI context. Writes handler_slugs, handler_configs,
	 * user_message, and enabled flags directly to the flow_config JSON.
	 *
	 * @param int    $flow_id        Flow ID to patch.
	 * @param string $import_handler Handler slug for the event_import step.
	 * @param array  $import_config  Handler config for the event_import step.
	 * @param string $location_term  Location taxonomy term for the update step.
	 * @param string $ai_message     User message for the AI step.
	 */
	private function patchFlowSteps( int $flow_id, string $import_handler, array $import_config, string $location_term, string $ai_message ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$flow = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_config FROM {$table} WHERE flow_id = %d", $flow_id ),
			ARRAY_A
		);

		if ( ! $flow ) {
			return;
		}

		$config = json_decode( $flow['flow_config'], true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as $step_id => &$step ) {
			$step_type = $step['step_type'] ?? '';

			if ( 'event_import' === $step_type ) {
				$step['handler_slugs']  = array( $import_handler );
				$step['handler_configs'] = array( $import_handler => $import_config );
				$step['enabled']        = true;
			}

			if ( 'update' === $step_type ) {
				$step['handler_slugs']  = array( 'upsert_event' );
				$step['handler_configs'] = array(
					'upsert_event' => array(
						'post_status'                   => 'publish',
						'include_images'                => false,
						'post_author'                   => self::DEFAULT_POST_AUTHOR,
						'taxonomy_category_selection'   => 'skip',
						'taxonomy_post_tag_selection'   => 'skip',
						'taxonomy_location_selection'   => $location_term,
						'taxonomy_festival_selection'   => 'ai_decides',
						'taxonomy_artist_selection'     => 'ai_decides',
						'taxonomy_promoter_selection'   => 'skip',
					),
				);
				$step['user_message'] = "IMPORTANT SYSTEM-WIDE RULE: Do not assign WordPress Categories or Tags for events. Always set:\n- taxonomy_category_selection = \"skip\"\n- taxonomy_post_tag_selection = \"skip\"\nIf any source includes categories/tags, ignore them.";
				$step['enabled']      = true;
			}

			if ( 'ai' === $step_type ) {
				$step['user_message'] = $ai_message;
			}
		}
		unset( $step );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array( 'flow_config' => wp_json_encode( $config ) ),
			array( 'flow_id' => $flow_id )
		);
	}
}
