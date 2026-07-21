<?php
/**
 * Canonical calendar occurrence contract serializer.
 *
 * @package DataMachineEvents\Api\Serializers
 */

namespace DataMachineEvents\Api\Serializers;

defined( 'ABSPATH' ) || exit;

/**
 * Projects the calendar data producer onto its portable occurrence contract.
 */
final class CalendarOccurrence {

	/**
	 * Remove presentation-only fields while preserving canonical producer data.
	 *
	 * @param array $serialized Full serialized event and occurrence.
	 * @return array{event: array<string,mixed>, occurrence: array<string,mixed>}
	 */
	public static function serialize( array $serialized ): array {
		$event      = $serialized['event'];
		$taxonomies = array();

		foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
			$taxonomies[ $taxonomy ] = array_map(
				static function ( array $term ): array {
					return array(
						'term_id' => $term['term_id'],
						'name'    => $term['name'],
						'slug'    => $term['slug'],
					);
				},
				$event['taxonomies'][ $taxonomy ] ?? array()
			);
		}

		return array(
			'event'      => array(
				'id'         => $event['id'],
				'title'      => $event['title'],
				'date'       => $event['date'],
				'venue'      => $event['venue'],
				'organizer'  => $event['organizer'],
				'ticket'     => $event['ticket'],
				'performer'  => $event['performer'],
				'status'     => $event['status'],
				'taxonomies' => $taxonomies,
			),
			'occurrence' => $serialized['occurrence'],
		);
	}
}
