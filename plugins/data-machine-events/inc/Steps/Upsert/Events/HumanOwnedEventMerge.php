<?php
/**
 * Human-Owned Event Merge
 *
 * Fill-only content merge for imports that match a human-owned event
 * (see EventOwnership). The existing post content is kept byte-for-byte
 * unless the import can fill an event-details attribute the person left
 * empty, or the event has no description at all. Nothing that already has a
 * value is ever replaced.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachineEvents\Core\Event_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Merges imported event details into human-owned content, filling empty fields only.
 */
class HumanOwnedEventMerge {

	/** Display toggles the content builder always emits; never "data". */
	private const DISPLAY_ATTRIBUTES = array( 'showPrice', 'showTicketLink' );

	/**
	 * Attributes the content builder defaults when the import had no value.
	 * They only fill when the import actually supplied the value, so a
	 * builder default never masquerades as source data.
	 *
	 * Map of block attribute => event data key.
	 */
	private const DEFAULTED_ATTRIBUTES = array(
		'performerType'     => 'performerType',
		'organizerType'     => 'organizerType',
		'eventStatus'       => 'eventStatus',
		'priceCurrency'     => 'priceCurrency',
		'offerAvailability' => 'offerAvailability',
		'eventType'         => 'eventType',
	);

	/**
	 * Fill empty event-details fields of existing content from generated content.
	 *
	 * @param string $existing_content  Current post content.
	 * @param string $generated_content Content the import would have written.
	 * @param array  $event_data        Import event data behind $generated_content.
	 * @return array{content: string, changed: bool, filled: string[]}
	 */
	public static function merge( string $existing_content, string $generated_content, array $event_data ): array {
		$unchanged = array(
			'content' => $existing_content,
			'changed' => false,
			'filled'  => array(),
		);

		$generated = self::findDetailsBlock( parse_blocks( $generated_content ) );
		if ( null === $generated ) {
			return $unchanged;
		}

		$existing_blocks = parse_blocks( $existing_content );
		$index           = self::findDetailsBlockIndex( $existing_blocks );
		if ( null === $index ) {
			// No event-details block to fill; a person's free-form content is
			// never restructured by an import.
			return $unchanged;
		}

		$block  = $existing_blocks[ $index ];
		$attrs  = $block['attrs'];
		$filled = array();

		foreach ( (array) ( $generated['attrs'] ?? array() ) as $key => $value ) {
			if ( in_array( $key, self::DISPLAY_ATTRIBUTES, true ) || self::isEmpty( $value ) ) {
				continue;
			}
			if ( isset( self::DEFAULTED_ATTRIBUTES[ $key ] ) && self::isEmpty( $event_data[ self::DEFAULTED_ATTRIBUTES[ $key ] ] ?? null ) ) {
				continue;
			}
			if ( ! self::isEmpty( $attrs[ $key ] ?? null ) ) {
				continue;
			}
			$attrs[ $key ] = $value;
			$filled[]      = (string) $key;
		}

		if ( ! empty( $generated['innerBlocks'] ) && self::hasNoDescription( $block ) && self::hasOnlyDetailsBlock( $existing_blocks, $index ) ) {
			$block['innerBlocks']  = $generated['innerBlocks'];
			$block['innerHTML']    = $generated['innerHTML'];
			$block['innerContent'] = $generated['innerContent'];
			$filled[]              = 'description';
		}

		if ( empty( $filled ) ) {
			return $unchanged;
		}

		$block['attrs']            = $attrs;
		$existing_blocks[ $index ] = $block;

		return array(
			'content' => serialize_blocks( $existing_blocks ),
			'changed' => true,
			'filled'  => $filled,
		);
	}

	/**
	 * First top-level event-details block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|null
	 */
	private static function findDetailsBlock( array $blocks ): ?array {
		$index = self::findDetailsBlockIndex( $blocks );
		return null === $index ? null : $blocks[ $index ];
	}

	/**
	 * Index of the first top-level event-details block.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return int|null
	 */
	private static function findDetailsBlockIndex( array $blocks ): ?int {
		foreach ( $blocks as $index => $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return (int) $index;
			}
		}
		return null;
	}

	/**
	 * Whether the event-details block carries no description.
	 *
	 * @param array $block Parsed event-details block.
	 * @return bool
	 */
	private static function hasNoDescription( array $block ): bool {
		return empty( $block['innerBlocks'] ) && '' === trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
	}

	/**
	 * Whether the event-details block is the post's only non-whitespace content.
	 *
	 * A description written outside the block still counts as a description.
	 *
	 * @param array $blocks Parsed top-level blocks.
	 * @param int   $index  Index of the event-details block.
	 * @return bool
	 */
	private static function hasOnlyDetailsBlock( array $blocks, int $index ): bool {
		foreach ( $blocks as $other_index => $other ) {
			if ( $other_index === $index ) {
				continue;
			}
			if ( null !== ( $other['blockName'] ?? null ) || '' !== trim( (string) ( $other['innerHTML'] ?? '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an attribute value is empty.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function isEmpty( $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return false;
	}
}
