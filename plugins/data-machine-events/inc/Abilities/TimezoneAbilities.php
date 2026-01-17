<?php
/**
 * Timezone Abilities
 *
 * Finds events with missing venue timezone and fixes them with geocoding support.
 * Provides abilities for CLI/REST/MCP and AI tools for chat.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

if (!defined('ABSPATH')) {
	exit;
}

class TimezoneAbilities {
	use ToolRegistrationTrait;

	const DEFAULT_LIMIT = 50;
	const DEFAULT_DAYS_AHEAD = 90;

	public function __construct() {
		$this->registerAbility();
		$this->registerTool('chat', 'find_broken_timezone_events', [$this, 'getFindToolDefinition']);
		$this->registerTool('chat', 'fix_event_timezone', [$this, 'getFixToolDefinition']);
	}

	private function registerAbility(): void {
		add_action('wp_abilities_api_init', function() {
			wp_register_ability('datamachine-events/find-broken-timezone-events', [
				'label' => __('Find Events with Missing Timezone', 'datamachine-events'),
				'description' => __('Find events where venue has no timezone or coordinates', 'datamachine-events'),
				'category' => 'datamachine',
				'input_schema' => [
					'type' => 'object',
					'required' => [],
					'properties' => [
						'scope' => [
							'type' => 'string',
							'enum' => ['upcoming', 'all', 'past'],
							'description' => 'Which events to check (default: upcoming)'
						],
						'days_ahead' => [
							'type' => 'integer',
							'description' => 'Days to look ahead for upcoming scope (default: 90)'
						],
						'limit' => [
							'type' => 'integer',
							'description' => 'Max events to return (default: 50)'
						]
					]
				],
				'output_schema' => [
					'type' => 'object',
					'properties' => [
						'total_broken' => ['type' => 'integer'],
						'broken_events' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'id' => ['type' => 'integer'],
									'title' => ['type' => 'string'],
									'startDate' => ['type' => 'string'],
									'startTime' => ['type' => 'string'],
									'venue' => ['type' => 'string'],
									'venue_id' => ['type' => 'integer'],
									'venue_timezone' => ['type' => 'string'],
									'venue_coordinates' => ['type' => 'string'],
									'reason' => ['type' => 'string', 'enum' => ['no_timezone', 'no_coordinates']]
								]
							]
						],
						'no_venue_count' => ['type' => 'integer'],
						'no_venue_events' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'id' => ['type' => 'integer'],
									'title' => ['type' => 'string'],
									'startDate' => ['type' => 'string'],
									'startTime' => ['type' => 'string']
								]
							]
						],
						'message' => ['type' => 'string']
					]
				],
				'execute_callback' => [$this, 'executeAbility'],
				'permission_callback' => function() {
					return current_user_can('manage_options');
				},
				'meta' => ['show_in_rest' => true]
			]);

			wp_register_ability('datamachine-events/fix-event-timezone', [
				'label' => __('Fix Event Timezone', 'datamachine-events'),
				'description' => __('Update venue timezone with geocoding support. Supports batch updates with inline errors.', 'datamachine-events'),
				'category' => 'datamachine',
				'input_schema' => [
					'type' => 'object',
					'required' => [],
					'properties' => [
						'event' => ['type' => 'integer', 'description' => 'Single event post ID'],
						'events' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'event' => ['type' => 'integer'],
									'timezone' => ['type' => 'string'],
									'auto_derive' => ['type' => 'boolean']
								]
							]
						],
						'timezone' => ['type' => 'string', 'description' => 'IANA timezone identifier'],
						'auto_derive' => ['type' => 'boolean', 'description' => 'Derive from coordinates via GeoNames API']
					]
				],
				'output_schema' => [
					'type' => 'object',
					'properties' => [
						'results' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'event' => ['type' => 'integer'],
									'title' => ['type' => 'string'],
									'status' => ['type' => 'string', 'enum' => ['updated', 'no_change', 'failed']],
									'timezone' => ['type' => 'string'],
									'timezone_source' => ['type' => 'string', 'enum' => ['provided', 'auto_derived', 'geocoded']],
									'error' => ['type' => 'string']
								]
							]
						],
						'summary' => [
							'type' => 'object',
							'properties' => [
								'updated' => ['type' => 'integer'],
								'failed' => ['type' => 'integer'],
								'total' => ['type' => 'integer']
							]
						]
					]
				],
				'execute_callback' => [$this, 'executeFixAbility'],
				'permission_callback' => function() {
					return current_user_can('manage_options');
				},
				'meta' => ['show_in_rest' => true]
			]);
		});
	}

	public function getFindToolDefinition(): array {
		return [
			'class' => self::class,
			'method' => 'handle_tool_call_find',
			'description' => 'Find events where venue has no timezone or coordinates. Also separately notes events with no venue assigned. Returns actual timezone/coordinates values when present.',
			'parameters' => [
				'scope' => [
					'type' => 'string',
					'required' => false,
					'description' => 'Which events to check: "upcoming" (default), "all", or "past"',
					'enum' => ['upcoming', 'all', 'past']
				],
				'days_ahead' => [
					'type' => 'integer',
					'required' => false,
					'description' => 'Days to look ahead for upcoming scope (default: 90)'
				],
				'limit' => [
					'type' => 'integer',
					'required' => false,
					'description' => 'Max events to return (default: 50)'
				]
			]
		];
	}

	public function getFixToolDefinition(): array {
		return [
			'class' => self::class,
			'method' => 'handle_tool_call_fix',
			'description' => 'Fix event timezone by updating venue metadata. Supports batch updates. Event times remain as-is (8pm stays 8pm) regardless of timezone. Calls geocoding if venue lacks coordinates. Returns inline errors and continues processing.',
			'parameters' => [
				'event' => [
					'type' => 'integer',
					'required' => false,
					'description' => 'Single event post ID to fix'
				],
				'events' => [
					'type' => 'array',
					'required' => false,
					'description' => 'Array of event updates. Each item must have "event" (post ID) and optionally "timezone" and "auto_derive".'
				],
				'timezone' => [
					'type' => 'string',
					'required' => false,
					'description' => 'IANA timezone identifier (e.g., "America/Chicago"). If omitted and auto_derive is false, no change is made.'
				],
				'auto_derive' => [
					'type' => 'boolean',
					'required' => false,
					'description' => 'If true, derive timezone from venue coordinates via GeoNames API (requires GeoNames username configured). Default: false.'
				]
			]
		];
	}

	public function handle_tool_call_find(array $parameters, array $tool_def = []): array {
		$scope = $parameters['scope'] ?? 'upcoming';
		$days_ahead = (int) ($parameters['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD);
		$limit = (int) ($parameters['limit'] ?? self::DEFAULT_LIMIT);

		$result = $this->findBrokenTimezoneEvents($scope, $days_ahead, $limit);

		return [
			'success' => true,
			'data' => $result,
			'tool_name' => 'find_broken_timezone_events'
		];
	}

	public function handle_tool_call_fix(array $parameters, array $tool_def = []): array {
		$updates = $this->normalizeFixInput($parameters);

		if (empty($updates)) {
			return [
				'success' => false,
				'error' => 'Either "event" (single) or "events" (batch) parameter is required',
				'tool_name' => 'fix_event_timezone'
			];
		}

		$results = [];
		$updated_count = 0;
		$failed_count = 0;

		foreach ($updates as $update) {
			$result = $this->fixSingleEventTimezone($update);
			$results[] = $result;

			if ($result['status'] === 'updated') {
				$updated_count++;
			} else {
				$failed_count++;
			}
		}

		return [
			'success' => $updated_count > 0,
			'data' => [
				'results' => $results,
				'summary' => [
					'updated' => $updated_count,
					'failed' => $failed_count,
					'total' => count($updates)
				]
			],
			'tool_name' => 'fix_event_timezone'
		];
	}

	public function executeAbility(array $input): array {
		$scope = $input['scope'] ?? 'upcoming';
		$days_ahead = (int) ($input['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD);
		$limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);

		return $this->findBrokenTimezoneEvents($scope, $days_ahead, $limit);
	}

	public function executeFixAbility(array $input): array {
		$updates = $this->normalizeFixInput($input);

		if (empty($updates)) {
			return [
				'success' => false,
				'error' => 'Missing required event data'
			];
		}

		$results = [];
		foreach ($updates as $update) {
			$results[] = $this->fixSingleEventTimezone($update);
		}

		return [
			'success' => true,
			'results' => $results
		];
	}

	private function queryEvents(string $scope, int $days_ahead): array {
		$args = [
			'post_type' => Event_Post_Type::POST_TYPE,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'orderby' => 'meta_value',
			'meta_key' => Event_Post_Type::EVENT_DATE_META_KEY,
			'order' => 'ASC'
		];

		$now = current_time('Y-m-d H:i:s');

		if ($scope === 'upcoming') {
			$end_date = gmdate('Y-m-d H:i:s', strtotime("+{$days_ahead} days"));
			$args['meta_query'] = [
				[
					'key' => Event_Post_Type::EVENT_DATE_META_KEY,
					'value' => [$now, $end_date],
					'compare' => 'BETWEEN',
					'type' => 'DATETIME'
				]
			];
		} elseif ($scope === 'past') {
			$args['meta_query'] = [
				[
					'key' => Event_Post_Type::EVENT_DATE_META_KEY,
					'value' => $now,
					'compare' => '<',
					'type' => 'DATETIME'
				]
			];
			$args['order'] = 'DESC';
		}

		$query = new \WP_Query($args);
		return $query->posts;
	}

	private function findBrokenTimezoneEvents(string $scope, int $days_ahead, int $limit): array {
		$events = $this->queryEvents($scope, $days_ahead);

		if (empty($events)) {
			return [
				'total_broken' => 0,
				'broken_events' => [],
				'no_venue_count' => 0,
				'no_venue_events' => [],
				'message' => 'No events found matching scope.'
			];
		}

		$broken_events = [];
		$no_venue_events = [];

		foreach ($events as $event) {
			$block_attrs = $this->extractBlockAttributes($event->ID);

			$venue_terms = wp_get_post_terms($event->ID, 'venue', ['fields' => 'ids']);

			if (is_wp_error($venue_terms) || empty($venue_terms)) {
				$no_venue_events[] = [
					'id' => $event->ID,
					'title' => $event->post_title,
					'startDate' => $block_attrs['startDate'] ?? '',
					'startTime' => $block_attrs['startTime'] ?? ''
				];
				continue;
			}

			$venue_id = $venue_terms[0];
			$venue_term = get_term($venue_id);
			$venue_timezone = get_term_meta($venue_id, '_venue_timezone', true);
			$venue_coordinates = get_term_meta($venue_id, '_venue_coordinates', true);

			if (empty($venue_timezone)) {
				$broken_events[] = [
					'id' => $event->ID,
					'title' => $event->post_title,
					'startDate' => $block_attrs['startDate'] ?? '',
					'startTime' => $block_attrs['startTime'] ?? '',
					'venue' => $venue_term->name,
					'venue_id' => $venue_id,
					'venue_timezone' => $venue_timezone ?: '',
					'venue_coordinates' => $venue_coordinates ?: '',
					'reason' => 'no_timezone'
				];
			} elseif (empty($venue_coordinates)) {
				$broken_events[] = [
					'id' => $event->ID,
					'title' => $event->post_title,
					'startDate' => $block_attrs['startDate'] ?? '',
					'startTime' => $block_attrs['startTime'] ?? '',
					'venue' => $venue_term->name,
					'venue_id' => $venue_id,
					'venue_timezone' => $venue_timezone ?: '',
					'venue_coordinates' => $venue_coordinates ?: '',
					'reason' => 'no_coordinates'
				];
			}
		}

		$message_parts = [];
		if (count($no_venue_events) > 0) {
			$message_parts[] = count($no_venue_events) . ' events without venue';
		}
		if (count($broken_events) > 0) {
			$message_parts[] = count($broken_events) . ' events with missing timezone/coordinates';
		}

		$message = empty($message_parts)
			? 'All events have venue and proper timezone.'
			: 'Found: ' . implode(', ', $message_parts);

		return [
			'total_broken' => count($broken_events),
			'broken_events' => array_slice($broken_events, 0, $limit),
			'no_venue_count' => count($no_venue_events),
			'no_venue_events' => array_slice($no_venue_events, 0, $limit),
			'message' => $message
		];
	}

	private function fixSingleEventTimezone(array $update): array {
		$event_id = (int) ($update['event'] ?? 0);
		$timezone = $update['timezone'] ?? '';
		$auto_derive = (bool) ($update['auto_derive'] ?? false);

		if ($event_id <= 0) {
			return [
				'event' => $event_id,
				'status' => 'failed',
				'error' => 'Invalid event ID'
			];
		}

		$post = get_post($event_id);
		if (!$post || $post->post_type !== Event_Post_Type::POST_TYPE) {
			return [
				'event' => $event_id,
				'status' => 'failed',
				'error' => 'Event not found or invalid post type'
			];
		}

		$venue_terms = wp_get_post_terms($event_id, 'venue', ['fields' => 'ids']);

		if (is_wp_error($venue_terms) || empty($venue_terms)) {
			return [
				'event' => $event_id,
				'title' => $post->post_title,
				'status' => 'failed',
				'error' => 'Event has no venue assigned - cannot fix timezone without venue'
			];
		}

		$venue_id = $venue_terms[0];
		$existing_timezone = get_term_meta($venue_id, '_venue_timezone', true);

		if (empty($timezone) && !$auto_derive) {
			return [
				'event' => $event_id,
				'title' => $post->post_title,
				'status' => 'no_change',
				'error' => 'No timezone provided and auto_derive is false'
			];
		}

		if (!empty($timezone) && $timezone === $existing_timezone) {
			return [
				'event' => $event_id,
				'title' => $post->post_title,
				'status' => 'no_change',
				'timezone' => $timezone,
				'timezone_source' => 'provided'
			];
		}

		$timezone_source = 'provided';

		if ($auto_derive) {
			$venue_coordinates = get_term_meta($venue_id, '_venue_coordinates', true);

			if (empty($venue_coordinates)) {
				do_action('datamachine_log', 'warning', 'Attempting to derive timezone but venue has no coordinates - calling geocoding', [
					'event_id' => $event_id,
					'venue_id' => $venue_id
				]);

				$geocoded = Venue_Taxonomy::maybe_geocode_venue($venue_id);

				if (!$geocoded) {
					return [
						'event' => $event_id,
						'title' => $post->post_title,
						'status' => 'failed',
						'error' => 'Could not geocode venue (no address data or API failure)'
					];
				}

				$venue_coordinates = get_term_meta($venue_id, '_venue_coordinates', true);
			}

			$derived = Venue_Taxonomy::maybe_derive_timezone($venue_id, $venue_coordinates);

			if (!$derived) {
				return [
					'event' => $event_id,
					'title' => $post->post_title,
					'status' => 'failed',
					'error' => 'Could not derive timezone from coordinates (GeoNames not configured or API error)'
				];
			}

			$timezone = get_term_meta($venue_id, '_venue_timezone', true);
			$timezone_source = 'auto_derived';

			do_action('datamachine_log', 'info', 'Timezone derived from venue coordinates', [
				'event_id' => $event_id,
				'venue_id' => $venue_id,
				'timezone' => $timezone
			]);
		} elseif (!empty($timezone)) {
			update_term_meta($venue_id, '_venue_timezone', sanitize_text_field($timezone));
			$timezone_source = 'provided';

			do_action('datamachine_log', 'info', 'Timezone updated manually', [
				'event_id' => $event_id,
				'venue_id' => $venue_id,
				'timezone' => $timezone
			]);
		}

		return [
			'event' => $event_id,
			'title' => $post->post_title,
			'venue_id' => $venue_id,
			'status' => 'updated',
			'timezone' => $timezone,
			'timezone_source' => $timezone_source
		];
	}

	private function extractBlockAttributes(int $post_id): array {
		$post = get_post($post_id);
		if (!$post) {
			return [];
		}

		$blocks = parse_blocks($post->post_content);

		foreach ($blocks as $block) {
			if ($block['blockName'] === 'datamachine-events/event-details') {
				return $block['attrs'] ?? [];
			}
		}

		return [];
	}

	private function normalizeFixInput(array $parameters): array {
		if (!empty($parameters['events']) && is_array($parameters['events'])) {
			return $parameters['events'];
		}

		if (!empty($parameters['event'])) {
			return [
				[
					'event' => (int) $parameters['event'],
					'timezone' => $parameters['timezone'] ?? '',
					'auto_derive' => $parameters['auto_derive'] ?? false
				]
			];
		}

		return [];
	}
}
