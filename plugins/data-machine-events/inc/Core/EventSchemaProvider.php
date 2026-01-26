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

use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

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
			'required'        => false,
			'description'     => 'Event start time (HH:MM 24-hour format)',
			'schema_property' => null,
		),
		'endTime'         => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Event end time (HH:MM 24-hour format)',
			'schema_property' => null,
		),
		'occurrenceDates' => array(
			'type'            => 'array',
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

	private const TYPE_FIELDS = array(
		'eventType' => array(
			'type'            => 'string',
			'required'        => false,
			'description'     => 'Type of event for rich result categorization (MusicEvent, Festival, ComedyEvent, etc.)',
			'schema_property' => '@type',
			'enum'            => array( 'Event', 'MusicEvent', 'Festival', 'ComedyEvent', 'DanceEvent', 'TheaterEvent', 'SportsEvent', 'ExhibitionEvent' ),
			'default'         => 'Event',
		),
	);

	public static function getAllFields(): array {
		return array_merge(
			self::CORE_FIELDS,
			self::OFFER_FIELDS,
			self::PERFORMER_FIELDS,
			self::ORGANIZER_FIELDS,
			self::STATUS_FIELDS,
			self::TYPE_FIELDS
		);
	}

	/**
	 * Get all possible tool parameters (trait implementation).
	 *
	 * @return array Complete parameter definitions
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
	 * @return array Filtered core parameter definitions
	 */
	public static function getCoreToolParameters( array $engine_data = array() ): array {
		$params = self::fieldsToToolParameters( self::CORE_FIELDS );
		return static::filterByEngineData( $params, $engine_data );
	}

	/**
	 * Get schema enrichment tool parameters, filtered by engine data.
	 *
	 * @param array $engine_data Engine data snapshot
	 * @return array Filtered schema parameter definitions
	 */
	public static function getSchemaToolParameters( array $engine_data = array() ): array {
		$schema_fields = array_merge(
			self::OFFER_FIELDS,
			self::PERFORMER_FIELDS,
			self::ORGANIZER_FIELDS,
			self::STATUS_FIELDS,
			self::TYPE_FIELDS
		);
		$params        = self::fieldsToToolParameters( $schema_fields );
		return static::filterByEngineData( $params, $engine_data );
	}

	/**
	 * Get all tool parameters, filtered by engine data.
	 *
	 * @param array $engine_data Engine data snapshot
	 * @return array Filtered parameter definitions
	 */
	public static function getAllToolParameters( array $engine_data = array() ): array {
		$params = self::fieldsToToolParameters( self::getAllFields() );
		return static::filterByEngineData( $params, $engine_data );
	}

	public static function getFieldKeys( string $category = 'all' ): array {
		return match ( $category ) {
			'core' => array_keys( self::CORE_FIELDS ),
			'offer' => array_keys( self::OFFER_FIELDS ),
			'performer' => array_keys( self::PERFORMER_FIELDS ),
			'organizer' => array_keys( self::ORGANIZER_FIELDS ),
			'status' => array_keys( self::STATUS_FIELDS ),
			'type' => array_keys( self::TYPE_FIELDS ),
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

	private static function fieldsToToolParameters( array $fields ): array {
		$params = array();

		foreach ( $fields as $key => $field ) {
			$param = array(
				'type'        => $field['type'],
				'required'    => $field['required'] ?? false,
				'description' => $field['description'],
			);

			if ( ! empty( $field['enum'] ) ) {
				$param['enum'] = $field['enum'];
			}

			$params[ $key ] = $param;
		}

		return $params;
	}

	public static function generateSchemaOrg( array $event_data, array $venue_data, array $organizer_data = array(), int $post_id = 0 ): array {
		$event_type = $event_data['eventType'] ?? 'Event';

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
			$end_time          = ! empty( $resolved_end_time ) ? 'T' . $resolved_end_time : '';
			$schema['endDate'] = $resolved_end_date . $end_time;
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

		if ( ! empty( $event_data['ticketUrl'] ) || ! empty( $event_data['price'] ) ) {
			$schema['offers'] = self::buildOffersSchema( $event_data );
		}

		$status                = $event_data['eventStatus'] ?? 'EventScheduled';
		$schema['eventStatus'] = 'https://schema.org/' . $status;

		if ( 'EventRescheduled' === $status && ! empty( $event_data['previousStartDate'] ) ) {
			$schema['previousStartDate'] = $event_data['previousStartDate'];
		}

		return $schema;
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

		$start_datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
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

		$end_datetime = get_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, true );
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
