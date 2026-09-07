<?php
/**
 * Centralized event schema provider for AI tools and Schema.org JSON-LD.
 *
 * Single source of truth for all event field definitions, mapping between
 * AI tool parameters and Schema.org properties for Google Event rich results.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventSchemaProvider {
	use DynamicToolParametersTrait;

	public const EVENT_TYPES = array(
		'Event',
		'MusicEvent',
		'Festival',
		'ComedyEvent',
		'DanceEvent',
		'TheaterEvent',
		'SportsEvent',
		'ExhibitionEvent',
	);

	public const EVENT_STATUSES = array(
		'EventScheduled',
		'EventPostponed',
		'EventCancelled',
		'EventRescheduled',
	);

	public const PERFORMER_TYPES = array(
		'Person',
		'PerformingGroup',
		'MusicGroup',
	);

	public const ORGANIZER_TYPES = array(
		'Person',
		'Organization',
	);

	public const OFFER_AVAILABILITY = array(
		'InStock',
		'SoldOut',
		'PreOrder',
	);

	/**
	 * Default vocabulary backing the `event_type` taxonomy.
	 *
	 * Schema.org event types, each self-mapped to its own `@type`. This is the
	 * standards-correct out-of-the-box vocabulary; consumers replace or extend
	 * it through the `data_machine_events_event_type_vocabulary` filter. No
	 * non-Schema.org term belongs here.
	 *
	 * @return array<int,array{name:string,slug:string,schema_type:string,default:bool}>
	 */
	public static function default_event_type_vocabulary(): array {
		$vocabulary = array();

		foreach ( self::EVENT_TYPES as $type ) {
			$vocabulary[] = array(
				'name'        => $type,
				'slug'        => sanitize_title( $type ),
				'schema_type' => $type,
				'default'     => 'Event' === $type,
			);
		}

		return $vocabulary;
	}

	private const CORE_FIELDS = array(
		'title'           => array(
			'type'            => 'string',
			'required'        => true,
			'description'     => 'Event title. The original scraped title is in data packet metadata (original_title). Use it directly if clear and complete, or write a better title if the original is unclear, truncated, ALL CAPS, contains emojis, dates, or unnecessary formatting.',
			'schema_property' => 'name',
		),
		'startDate'       => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Event start date (YYYY-MM-DD format)',
			'schema_property' => 'startDate',
		),
		'endDate'         => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Event end date (YYYY-MM-DD format)',
			'schema_property' => 'endDate',
		),
		'startTime'       => array(
			'type'            => 'string',
			'required'        => true,
			'description'     => 'Event start time (HH:MM 24-hour format). REQUIRED. If not provided in structured data, extract from the event title or description (e.g., "6-9pm" → "18:00", "doors at 7" → "19:00", "starts 8pm" → "20:00"). If no start time can be determined from any source, reject this item via the rejection disposition tool instead — do not publish events without a start time.',
			'schema_property' => null,
		),
		'endTime'         => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Event end time (HH:MM 24-hour format). If not provided in structured data but a time range is mentioned (e.g., "6-9pm"), extract the end time ("21:00"). If only a start time is found, leave this empty.',
			'schema_property' => null,
		),
		'occurrenceDates' => array(
			'type'            => 'array',
			'items'           => array( 'type' => 'string' ),
			'required'        => false,
			'description'     => 'For recurring events: array of specific dates (YYYY-MM-DD) when the event occurs within the start/end range. If provided, event displays only on these dates instead of every day in the range.',
			'schema_property' => null,
		),
		'description'     => array(
			'type'            => 'string',
			'required'        => true,
			'description'     => 'Generate an engaging, informative HTML description. Use <p> tags for paragraphs, <strong> for emphasis. Focus on what makes this event unique and what attendees can expect.',
			'schema_property' => 'description',
		),
	);

	private const OFFER_FIELDS = array(
		'price'             => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Ticket price if mentioned in the content. Acceptable formats: "$25", "$25 - $75" (range), "$20 adv / $25 door", or "Free". Extract the actual price from the description if visible. Leave empty if no price information is available - do not use "TBD", "Varies", or other placeholders.',
			'schema_property' => 'offers.price',
		),
		'priceCurrency'     => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'ISO 4217 currency code (USD, EUR, GBP, etc.)',
			'schema_property' => 'offers.priceCurrency',
			'default'         => 'USD',
		),
		'ticketUrl'         => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'URL to purchase tickets',
			'schema_property' => 'offers.url',
		),
		'offerAvailability' => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Ticket availability status',
			'schema_property' => 'offers.availability',
			'enum'            => array( 'InStock', 'SoldOut', 'PreOrder' ),
			'default'         => 'InStock',
		),
		'validFrom'         => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Date and time when tickets go on sale (ISO-8601 format)',
			'schema_property' => 'offers.validFrom',
		),
	);

	private const PERFORMER_FIELDS = array(
		'performer'     => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Name of the performing artist, band, comedian, or group',
			'schema_property' => 'performer.name',
		),
		'performerType' => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Type of performer: Person for solo artists, PerformingGroup for bands',
			'schema_property' => 'performer.@type',
			'enum'            => array( 'Person', 'PerformingGroup', 'MusicGroup' ),
			'default'         => 'PerformingGroup',
		),
	);

	private const ORGANIZER_FIELDS = array(
		'organizer'     => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Name of the event organizer or promoter',
			'schema_property' => 'organizer.name',
		),
		'organizerType' => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Type of organizer: Person or Organization',
			'schema_property' => 'organizer.@type',
			'enum'            => array( 'Person', 'Organization' ),
			'default'         => 'Organization',
		),
		'organizerUrl'  => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Website URL of the event organizer',
			'schema_property' => 'organizer.url',
		),
	);

	private const STATUS_FIELDS = array(
		'eventStatus'       => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Event status for scheduling changes',
			'schema_property' => 'eventStatus',
			'enum'            => array( 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled' ),
			'default'         => 'EventScheduled',
		),
		'previousStartDate' => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Original start date if event was rescheduled (YYYY-MM-DD format)',
			'schema_property' => 'previousStartDate',
		),
	);

	/**
	 * Event-type field declaration, derived from the active vocabulary.
	 *
	 * The enum is resolved at call time from the `event_type` taxonomy
	 * vocabulary (data-machine-events#761) so the AI tool schema and the
	 * taxonomy can never disagree about what values are legal.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function typeFields(): array {
		$names   = Event_Type_Taxonomy::get_vocabulary_names();
		$default = Event_Type_Taxonomy::get_default_entry();

		return array(
			'eventType' => array(
				'type'            => 'string',
				'required'        => false,
				'description'     => sprintf(
					'The format of this event. Choose exactly one value from the allowed list: %s. This is a closed vocabulary — never invent a value.',
					implode( ', ', $names )
				),
				'schema_property' => '@type',
				'enum'            => array_values( $names ),
				'default'         => (string) ( $default['name'] ?? 'Event' ),
			),
		);
	}

	public static function getAllFields(): array {
		return array_merge(
			self::CORE_FIELDS,
			self::OFFER_FIELDS,
			self::PERFORMER_FIELDS,
			self::ORGANIZER_FIELDS,
			self::STATUS_FIELDS,
			self::typeFields()
		);
	}

	/**
	 * Get all possible tool parameters (trait implementation).
	 *
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	protected static function getAllParameters(): array {
		return self::fieldsToToolParameters( self::getAllFields() );
	}

	/**
	 * Get parameter keys that should check engine data (trait implementation).
	 * Excludes 'description' which AI should always generate.
	 *
	 * @return array List of parameter keys that are engine-aware
	 */
	protected static function getEngineAwareKeys(): array {
		$all_keys = array_keys( self::getAllFields() );
		return array_filter( $all_keys, fn( $key ) => 'description' !== $key && 'title' !== $key );
	}

	/**
	 * Get core event tool parameters, filtered by engine data.
	 *
	 * @param array $engine_data Engine data snapshot
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	public static function getCoreToolParameters( array $engine_data = array() ): array {
		$fragment = self::fieldsToToolParameters( self::CORE_FIELDS );
		return static::filterByEngineData( $fragment, $engine_data );
	}

	/**
	 * Get schema enrichment tool parameters, filtered by engine data.
	 *
	 * @param array $engine_data Engine data snapshot
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	public static function getSchemaToolParameters( array $engine_data = array() ): array {
		$schema_fields = array_merge(
			self::OFFER_FIELDS,
			self::PERFORMER_FIELDS,
			self::ORGANIZER_FIELDS,
			self::STATUS_FIELDS,
			self::typeFields()
		);
		$fragment      = self::fieldsToToolParameters( $schema_fields );
		return static::filterByEngineData( $fragment, $engine_data );
	}

	/**
	 * Get all tool parameters, filtered by engine data.
	 *
	 * @param array $engine_data Engine data snapshot
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	public static function getAllToolParameters( array $engine_data = array() ): array {
		$fragment = self::fieldsToToolParameters( self::getAllFields() );
		return static::filterByEngineData( $fragment, $engine_data );
	}

	public static function getFieldKeys( string $category = 'all' ): array {
		return match ( $category ) {
			'core' => array_keys( self::CORE_FIELDS ),
			'offer' => array_keys( self::OFFER_FIELDS ),
			'performer' => array_keys( self::PERFORMER_FIELDS ),
			'organizer' => array_keys( self::ORGANIZER_FIELDS ),
			'status' => array_keys( self::STATUS_FIELDS ),
			'type' => array_keys( self::typeFields() ),
			default => array_keys( self::getAllFields() )
		};
	}

	public static function getDefaults(): array {
		$defaults = array();
		foreach ( self::getAllFields() as $key => $field ) {
			$defaults[ $key ] = $field['default'] ?? '';
		}
		return $defaults;
	}

	public static function getSchemaPropertyMap(): array {
		$map = array();
		foreach ( self::getAllFields() as $key => $field ) {
			if ( ! empty( $field['schema_property'] ) ) {
				$map[ $key ] = $field['schema_property'];
			}
		}
		return $map;
	}

	public static function extractFromParameters( array $parameters ): array {
		$event_data = array();
		$field_keys = self::getFieldKeys();

		foreach ( $field_keys as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$event_data[ $key ] = $parameters[ $key ];
			}
		}

		return $event_data;
	}

	/**
	 * Check a validFrom value without changing the caller-provided timestamp.
	 *
	 * WordPress's RFC3339 parser accepts timezone-less ISO-8601 timestamps, so
	 * this follows the core date-time contract while rejecting impossible dates.
	 */
	public static function isValidValidFrom( string $value ): bool {
		if ( false === rest_parse_date( $value ) ) {
			return false;
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[Tt ](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)?$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	/**
	 * Convert a field definition map into a canonical JSON Schema fragment.
	 *
	 * Returns `{ properties: {...}, required: [...] }`. The `required` key is
	 * omitted when no field declares `required => true` so we do not emit
	 * an empty `required: []` (which OpenAI's strict tool-schema validator
	 * rejects in some configurations).
	 *
	 * @param array $fields Field declarations keyed by parameter name.
	 * @return array Canonical fragment with `properties` and optional `required`.
	 */
	private static function fieldsToToolParameters( array $fields ): array {
		$properties = array();
		$required   = array();

		foreach ( $fields as $key => $field ) {
			$property = array(
				'type'        => $field['type'],
				'description' => $field['description'],
			);

			if ( ! empty( $field['enum'] ) ) {
				$property['enum'] = $field['enum'];
			}

			// JSON Schema requires `items` on every array type, and OpenAI's
			// strict tool schema validation rejects arrays without it.
			// Propagate `items` from the field declaration; default to a string
			// array when the declaration is incomplete to avoid emitting an
			// invalid schema that would fail at runtime.
			if ( 'array' === ( $field['type'] ?? '' ) ) {
				$property['items'] = $field['items'] ?? array( 'type' => 'string' );
			}

			$properties[ $key ] = $property;

			if ( ! empty( $field['required'] ) ) {
				$required[] = $key;
			}
		}

		$fragment = array( 'properties' => $properties );
		if ( ! empty( $required ) ) {
			$fragment['required'] = $required;
		}

		return $fragment;
	}

	public static function generateSchemaOrg( array $event_data, array $venue_data, array $organizer_data = array(), int $post_id = 0 ): array {
		$event_type = self::resolveSchemaEventType( $event_data, $post_id );

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $event_type,
			'name'     => get_the_title( $post_id ),
		);

		[$resolved_start_date, $resolved_start_time] = self::resolveStartDate( $event_data, $post_id );
		if ( ! empty( $resolved_start_date ) ) {
			$start_time          = ! empty( $resolved_start_time ) ? 'T' . $resolved_start_time : '';
			$schema['startDate'] = $resolved_start_date . $start_time;
		}

		[$resolved_end_date, $resolved_end_time] = self::resolveEndDate( $event_data, $post_id );
		if ( ! empty( $resolved_end_date ) ) {
			$end_time     = ! empty( $resolved_end_time ) ? 'T' . $resolved_end_time : '';
			$end_date_iso = $resolved_end_date . $end_time;

			// Only include endDate if it differs from startDate.
			// Identical values mean no real end time was provided — omitting
			// is better than telling Google the event is zero minutes long.
			if ( ! isset( $schema['startDate'] ) || $end_date_iso !== $schema['startDate'] ) {
				$schema['endDate'] = $end_date_iso;
			}
		}

		if ( ! empty( $event_data['description'] ) ) {
			$schema['description'] = wp_strip_all_tags( $event_data['description'] );
		}

		$location = self::buildLocationSchema( $venue_data, $event_data );
		if ( ! empty( $location ) ) {
			$schema['location'] = $location;
		}

		if ( ! empty( $event_data['performer'] ) ) {
			$schema['performer'] = array(
				'@type' => $event_data['performerType'] ?? 'PerformingGroup',
				'name'  => $event_data['performer'],
			);
		}

		$organizer_schema = self::buildOrganizerSchema( $organizer_data, $event_data );
		if ( ! empty( $organizer_schema ) ) {
			$schema['organizer'] = $organizer_schema;
		}

		$images = self::buildImageArray( $post_id );
		if ( ! empty( $images ) ) {
			$schema['image'] = $images;
		}

		if ( ! empty( $event_data['ticketUrl'] ) || ! empty( $event_data['price'] ) || ! empty( $event_data['validFrom'] ) ) {
			$schema['offers'] = self::buildOffersSchema( $event_data );
		}

		$status                = $event_data['eventStatus'] ?? 'EventScheduled';
		$schema['eventStatus'] = 'https://schema.org/' . $status;

		if ( 'EventRescheduled' === $status && ! empty( $event_data['previousStartDate'] ) ) {
			$schema['previousStartDate'] = $event_data['previousStartDate'];
		}

		return $schema;
	}

	/**
	 * Resolve the JSON-LD `@type` for an event.
	 *
	 * The `event_type` taxonomy term's `_schema_type` meta is authoritative
	 * (data-machine-events#761). The block attribute is derived output kept as
	 * a fallback for events that predate the taxonomy, and is itself resolved
	 * through the vocabulary so an editorial term never leaks into JSON-LD.
	 *
	 * @param array $event_data Event data, possibly carrying the legacy attribute.
	 * @param int   $post_id    Event post ID.
	 * @return string Schema.org `@type`.
	 */
	private static function resolveSchemaEventType( array $event_data, int $post_id ): string {
		$term_schema_type = Event_Type_Taxonomy::get_schema_type_for_post( $post_id );
		if ( '' !== $term_schema_type ) {
			return $term_schema_type;
		}

		$attribute = $event_data['eventType'] ?? '';
		if ( is_string( $attribute ) && '' !== $attribute ) {
			$resolved = Event_Type_Taxonomy::resolve_schema_type( $attribute );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}

		return 'Event';
	}

	private static function resolveStartDate( array $event_data, int $post_id ): array {
		$start_date = $event_data['startDate'] ?? '';
		$start_time = $event_data['startTime'] ?? '';

		if ( ! empty( $start_date ) ) {
			return array( $start_date, $start_time );
		}

		if ( ! $post_id ) {
			return array( '', '' );
		}

		$dates          = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$start_datetime = $dates ? $dates->start_datetime : '';
		if ( empty( $start_datetime ) ) {
			return array( '', '' );
		}

		$date_obj = date_create( $start_datetime );
		if ( ! $date_obj ) {
			return array( '', '' );
		}

		return array(
			$date_obj->format( 'Y-m-d' ),
			$start_time ? $start_time : $date_obj->format( 'H:i:s' ),
		);
	}

	private static function resolveEndDate( array $event_data, int $post_id ): array {
		$end_date = $event_data['endDate'] ?? '';
		$end_time = $event_data['endTime'] ?? '';

		if ( ! empty( $end_date ) ) {
			return array( $end_date, $end_time );
		}

		if ( ! $post_id ) {
			return array( '', '' );
		}

		$dates        = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$end_datetime = $dates ? $dates->end_datetime : '';
		if ( empty( $end_datetime ) ) {
			return array( '', '' );
		}

		$date_obj = date_create( $end_datetime );
		if ( ! $date_obj ) {
			return array( '', '' );
		}

		return array(
			$date_obj->format( 'Y-m-d' ),
			$end_time ? $end_time : $date_obj->format( 'H:i:s' ),
		);
	}

	private static function buildLocationSchema( array $venue_data, array $event_data ): array {
		$venue_name = $venue_data['name'] ?? $event_data['venue'] ?? '';

		if ( empty( $venue_name ) ) {
			return array();
		}

		$location = array(
			'@type' => 'Place',
			'name'  => $venue_name,
		);

		$address        = array( '@type' => 'PostalAddress' );
		$address_fields = array(
			'streetAddress'   => $venue_data['address'] ?? $event_data['venueAddress'] ?? '',
			'addressLocality' => $venue_data['city'] ?? $event_data['venueCity'] ?? '',
			'addressRegion'   => $venue_data['state'] ?? $event_data['venueState'] ?? '',
			'postalCode'      => $venue_data['zip'] ?? $event_data['venueZip'] ?? '',
			'addressCountry'  => $venue_data['country'] ?? $event_data['venueCountry'] ?? 'US',
		);

		$has_address = false;
		foreach ( $address_fields as $key => $value ) {
			if ( ! empty( $value ) ) {
				$address[ $key ] = $value;
				$has_address     = true;
			}
		}

		if ( $has_address ) {
			$location['address'] = $address;
		}

		$phone = $venue_data['phone'] ?? $event_data['venuePhone'] ?? '';
		if ( ! empty( $phone ) ) {
			$location['telephone'] = $phone;
		}

		$website = $venue_data['website'] ?? $event_data['venueWebsite'] ?? '';
		if ( ! empty( $website ) ) {
			$location['url'] = $website;
		}

		$coordinates = $venue_data['coordinates'] ?? $event_data['venueCoordinates'] ?? '';
		if ( ! empty( $coordinates ) ) {
			$coords = explode( ',', $coordinates );
			if ( count( $coords ) === 2 ) {
				$location['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => trim( $coords[0] ),
					'longitude' => trim( $coords[1] ),
				);
			}
		}

		return $location;
	}

	/**
	 * Build organizer schema from taxonomy data or event data fallback.
	 *
	 * @param array $organizer_data Organizer taxonomy data (name, url, type)
	 * @param array $event_data Event data with organizer fields as fallback
	 * @return array Organizer schema or empty array
	 */
	private static function buildOrganizerSchema( array $organizer_data, array $event_data ): array {
		$organizer_name = $organizer_data['name'] ?? $event_data['organizer'] ?? '';

		if ( empty( $organizer_name ) ) {
			return array();
		}

		$organizer = array(
			'@type' => $organizer_data['type'] ?? $event_data['organizerType'] ?? 'Organization',
			'name'  => $organizer_name,
		);

		$organizer_url = $organizer_data['url'] ?? $event_data['organizerUrl'] ?? '';
		if ( ! empty( $organizer_url ) ) {
			$organizer['url'] = $organizer_url;
		}

		return $organizer;
	}

	private static function buildOffersSchema( array $event_data ): array {
		$offers = array( '@type' => 'Offer' );

		if ( ! empty( $event_data['ticketUrl'] ) ) {
			$offers['url'] = $event_data['ticketUrl'];
		}

		$availability           = $event_data['offerAvailability'] ?? 'InStock';
		$offers['availability'] = 'https://schema.org/' . $availability;

		if ( ! empty( $event_data['price'] ) ) {
			$numeric_price = preg_replace( '/[^0-9.]/', '', $event_data['price'] );
			if ( $numeric_price ) {
				$offers['price']         = floatval( $numeric_price );
				$offers['priceCurrency'] = $event_data['priceCurrency'] ?? 'USD';
			}
		}

		if ( ! empty( $event_data['validFrom'] ) ) {
			$offers['validFrom'] = $event_data['validFrom'];
		}

		return $offers;
	}

	private static function buildImageArray( int $post_id ): array {
		$images            = array();
		$featured_image_id = get_post_thumbnail_id( $post_id );

		if ( ! $featured_image_id ) {
			return $images;
		}

		$sizes = array( 'full', 'large', 'medium_large' );
		foreach ( $sizes as $size ) {
			$url = wp_get_attachment_image_url( $featured_image_id, $size );
			if ( $url && ! in_array( $url, $images, true ) ) {
				$images[] = $url;
			}
		}

		return $images;
	}
}
