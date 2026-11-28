<?php
/**
 * Junk / test-payload filter for event import sources.
 *
 * Generic matcher that drops known test/placeholder payloads before they enter
 * the import pipeline. The matching logic is intentionally vendor-agnostic;
 * the actual junk patterns come from the filterable
 * `data_machine_events_junk_payload_patterns` filter, which each source handler
 * seeds with its own defaults. Layer purity: patterns are config, matching is
 * generic — no vendor string lives in this class.
 *
 * @package DataMachineEvents\Steps\EventImport
 * @since 0.14.1
 */

namespace DataMachineEvents\Steps\EventImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters test/placeholder event payloads out of an import stream.
 *
 * Each source handler (Ticketmaster, Dice, …) hooks
 * `data_machine_events_junk_payload_patterns` and returns pattern buckets only
 * when `$source_type` matches its own slug. The matcher then evaluates
 * normalized evidence against whichever buckets were registered.
 */
class JunkPayloadFilter {

	/**
	 * Whether a standardized event payload should be dropped as a known
	 * test/placeholder record.
	 *
	 * @param array  $evidence {
	 *     Normalized evidence about a single fetched item.
	 *
	 *     @type string $source_id          Upstream record ID.
	 *     @type string $title              Event title.
	 *     @type string $artist             Primary attraction / artist name (may be empty).
	 *     @type bool   $is_explicit_test   Optional. Upstream "test" flag, when the source exposes one.
	 * }
	 * @param string $source_type Source handler slug (e.g. 'ticketmaster').
	 * @return bool True when the payload is junk and should be dropped.
	 */
	public function is_junk( array $evidence, string $source_type ): bool {
		$patterns = $this->get_patterns( $source_type );

		if ( ! empty( $patterns['honor_test_flag'] ) ) {
			$explicit_test = $evidence['is_explicit_test'] ?? null;
			if ( is_bool( $explicit_test ) && $explicit_test ) {
				return true;
			}
		}

		$source_id = isset( $evidence['source_id'] ) ? trim( (string) $evidence['source_id'] ) : '';
		if ( '' !== $source_id && $this->contains_any( $source_id, (array) ( $patterns['id'] ?? array() ) ) ) {
			return true;
		}

		$title = isset( $evidence['title'] ) ? trim( (string) $evidence['title'] ) : '';
		if ( '' === $title ) {
			return false;
		}

		if ( $this->contains_any( $title, (array) ( $patterns['title'] ?? array() ) ) ) {
			return true;
		}

		$artist = isset( $evidence['artist'] ) ? trim( (string) $evidence['artist'] ) : '';
		if ( '' === $artist && $this->starts_with_any( $title, (array) ( $patterns['title_prefix_no_artist'] ?? array() ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Filterable junk patterns for a source type.
	 *
	 * Shape (all keys optional, merged over the defaults below):
	 *
	 *     array(
	 *         'honor_test_flag'        => bool,       // honor an upstream explicit test flag.
	 *         'id'                     => string[],   // substring match on source_id.
	 *         'title'                  => string[],   // substring match on title.
	 *         'title_prefix_no_artist' => string[],   // title prefix match when artist is empty.
	 *     )
	 *
	 * Handlers seed their own defaults by hooking
	 * `data_machine_events_junk_payload_patterns` and returning only when
	 * `$source_type` matches their slug.
	 *
	 * @param string $source_type
	 * @return array<string,mixed>
	 */
	public function get_patterns( string $source_type ): array {
		/**
		 * Filter the junk/test-payload patterns for an event import source.
		 *
		 * @param array  $patterns    Pattern buckets (see get_patterns() shape).
		 * @param string $source_type Source handler slug.
		 */
		return (array) apply_filters(
			'data_machine_events_junk_payload_patterns',
			array(
				'honor_test_flag'        => true,
				'id'                     => array(),
				'title'                  => array(),
				'title_prefix_no_artist' => array(),
			),
			$source_type
		);
	}

	/**
	 * Case-insensitive substring match against any needle.
	 *
	 * @param string   $haystack
	 * @param string[] $needles
	 */
	private function contains_any( string $haystack, array $needles ): bool {
		if ( '' === $haystack ) {
			return false;
		}

		foreach ( $needles as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' === $needle ) {
				continue;
			}

			if ( false !== mb_stripos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Case-insensitive prefix match against any prefix.
	 *
	 * @param string   $haystack
	 * @param string[] $prefixes
	 */
	private function starts_with_any( string $haystack, array $prefixes ): bool {
		if ( '' === $haystack ) {
			return false;
		}

		foreach ( $prefixes as $prefix ) {
			$prefix = trim( (string) $prefix );
			if ( '' === $prefix ) {
				continue;
			}

			if ( 0 === mb_stripos( $haystack, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
