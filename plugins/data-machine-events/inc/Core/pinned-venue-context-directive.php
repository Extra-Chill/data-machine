<?php
/**
 * Pinned venue context directive.
 *
 * Venue flows (e.g. created by `venues add`) pin one venue to a city
 * pipeline after the operator has checked it belongs to that market. The
 * pipeline system prompt only names the market ("events feed for
 * Manchester"), so for a venue in a neighbouring town the model sometimes
 * rejects its events as the wrong location. This directive tells event-upsert
 * AI steps on a pinned-venue flow that location is already settled (#3956).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies pinned-venue location context to event-upsert AI steps.
 */
final class PinnedVenueContextDirective {

	/**
	 * Register the directive with Data Machine for pipeline AI steps.
	 */
	public static function register(): void {
		add_filter(
			'datamachine_directives',
			static function ( $directives ) {
				$directives[] = array(
					'class'    => self::class,
					'priority' => 56,
					'modes'    => array( 'pipeline' ),
				);
				return $directives;
			}
		);
	}

	/**
	 * Directive outputs for the Data Machine directive system.
	 *
	 * @param string      $provider_name AI provider name.
	 * @param array       $tools         Tools available to this step, keyed by tool name.
	 * @param string|null $step_id       Pipeline step ID.
	 * @param array       $payload       Directive payload.
	 * @return array<int, array{type: string, content: string}>
	 */
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Data Machine directive contract.
		if ( ! EventDateContextDirective::has_event_upsert_tool( $tools ) ) {
			return array();
		}

		$engine_data = is_array( $payload['engine_data'] ?? null ) ? $payload['engine_data'] : array();
		$venue       = self::pinned_venue( is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array() );
		if ( null === $venue ) {
			return array();
		}

		return array(
			array(
				'type'    => 'system_text',
				'content' => self::content( $venue ),
			),
		);
	}

	/**
	 * The venue a flow pins through its upsert step, if any.
	 *
	 * @param array $flow_config Flow config keyed by flow step ID.
	 * @return array{name: string, place: string}|null
	 */
	public static function pinned_venue( array $flow_config ): ?array {
		$term_id = 0;
		$name    = '';
		$place   = array();

		foreach ( $flow_config as $step ) {
			$handler_configs = is_array( $step['handler_configs'] ?? null ) ? $step['handler_configs'] : array();
			foreach ( $handler_configs as $config ) {
				if ( ! is_array( $config ) ) {
					continue;
				}
				$selection = (string) ( $config['taxonomy_venue_selection'] ?? '' );
				if ( ctype_digit( $selection ) && (int) $selection > 0 ) {
					$term_id = (int) $selection;
				}
				if ( '' === $name && '' !== trim( (string) ( $config['venue_name'] ?? '' ) ) ) {
					$name  = trim( (string) $config['venue_name'] );
					$place = array_filter( array( trim( (string) ( $config['venue_city'] ?? '' ) ), trim( (string) ( $config['venue_state'] ?? '' ) ) ) );
				}
			}
		}

		if ( $term_id <= 0 ) {
			return null;
		}

		if ( '' === $name ) {
			$term = get_term( $term_id );
			$name = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES ) : '';
		}
		if ( '' === $name ) {
			return null;
		}

		return array(
			'name'  => $name,
			'place' => implode( ', ', $place ),
		);
	}

	/**
	 * Directive text for a pinned venue.
	 *
	 * @param array{name: string, place: string} $venue Pinned venue.
	 */
	public static function content( array $venue ): string {
		$where = '' !== $venue['place'] ? "{$venue['name']} ({$venue['place']})" : $venue['name'];

		return implode(
			"\n",
			array(
				'## Pinned venue',
				"This flow imports one venue: {$where}. An operator added it to this feed on purpose, so it belongs to this market even if it is in a neighbouring town.",
				'Never reject or defer an event from this source because of its location, city, or distance from the market. Judge everything else (music relevance, past or cancelled events, missing details) as usual.',
			)
		);
	}
}
