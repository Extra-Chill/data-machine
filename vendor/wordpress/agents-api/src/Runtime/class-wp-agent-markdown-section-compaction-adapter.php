<?php
/**
 * Markdown section compaction adapter.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects markdown documents to ordered section compaction items and back.
 */
class WP_Agent_Markdown_Section_Compaction_Adapter {

	public const ITEM_SCHEMA  = 'agents-api.compaction-item';
	public const ITEM_VERSION = 1;

	public const TYPE_PREAMBLE        = 'markdown_preamble';
	public const TYPE_SECTION         = 'markdown_section';
	public const TYPE_SECTION_SUMMARY = 'markdown_section_summary';
	public const TYPE_SECTION_POINTER = 'markdown_section_pointer';

	public const STATUS_SKIPPED  = 'skipped';
	public const STATUS_ARCHIVED = 'archived';

	/**
	 * Parse markdown into ordered compaction items keyed by heading path.
	 *
	 * @param string $markdown Markdown document.
	 * @return array<int, array<string, mixed>> Ordered compaction items.
	 */
	public static function parse( string $markdown ): array {
		$items            = array();
		$lines            = self::split_lines( $markdown );
		$path_stack       = array();
		$heading_counters = array();
		$current          = self::preamble_item();

		foreach ( $lines as $line ) {
			$heading = self::parse_heading( $line );
			if ( null === $heading ) {
				$current['content'] = self::item_content( $current ) . $line;
				continue;
			}

			$items[] = self::finalize_item( $current, count( $items ) );

			$level            = $heading['level'];
			$path_stack_count = count( $path_stack );
			while ( $path_stack_count >= $level ) {
				array_pop( $path_stack );
				--$path_stack_count;
			}

			$path_stack[]                     = $heading['text'];
			$heading_path                     = $path_stack;
			$heading_key                      = self::heading_key( $heading_path );
			$heading_counters[ $heading_key ] = ( $heading_counters[ $heading_key ] ?? 0 ) + 1;

			if ( $heading_counters[ $heading_key ] > 1 ) {
				$heading_key .= '-' . $heading_counters[ $heading_key ];
			}

			$current = self::section_item( $heading, $heading_path, $heading_key );
		}

		$items[] = self::finalize_item( $current, count( $items ) );

		return $items;
	}

	/**
	 * Reconstruct markdown from retained, summary, or pointer items.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered compaction items.
	 * @return string Markdown document.
	 */
	public static function reconstruct( array $items ): string {
		$markdown = '';

		foreach ( $items as $item ) {
			$type     = self::normalize_string( $item['type'] ?? '' );
			$content  = self::item_content( $item );
			$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();

			if ( self::TYPE_PREAMBLE === $type ) {
				$markdown .= $content;
				continue;
			}

			if ( ! in_array( $type, array( self::TYPE_SECTION, self::TYPE_SECTION_SUMMARY, self::TYPE_SECTION_POINTER ), true ) ) {
				throw new \InvalidArgumentException( 'invalid_markdown_section_item: unsupported item type' );
			}

			$heading_line = self::string_value( $metadata['heading_line'] ?? '' );
			if ( '' === $heading_line ) {
				throw new \InvalidArgumentException( 'invalid_markdown_section_item: section item missing heading line' );
			}

			$markdown .= $heading_line . $content;
		}

		return $markdown;
	}

	/**
	 * Build a summary item that keeps the source section heading intact.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $summary      Summary markdown.
	 * @return array<string, mixed>
	 */
	public static function summary_item( array $section_item, string $summary ): array {
		$item = self::replacement_item( $section_item, self::TYPE_SECTION_SUMMARY, $summary );

		$item['metadata']                     = self::item_metadata( $item );
		$item['metadata']['source_item_type'] = $section_item['type'] ?? '';
		return $item;
	}

	/**
	 * Build a pointer item that keeps destinations product-owned and opaque.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $destination  Consumer-owned destination string.
	 * @return array<string, mixed>
	 */
	public static function pointer_item( array $section_item, string $destination ): array {
		$destination = trim( $destination );
		if ( '' === $destination ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_pointer: destination must be non-empty' );
		}

		$item = self::replacement_item( $section_item, self::TYPE_SECTION_POINTER, '[Archived section: ' . $destination . ']' . "\n" );

		$item['metadata']                        = self::item_metadata( $item );
		$item['metadata']['pointer_destination'] = $destination;
		return $item;
	}

	/**
	 * Group items by the nearest heading at the requested boundary level.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered compaction items.
	 * @param int                             $level Heading level to group by.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function group_by_heading_boundary( array $items, int $level = 1 ): array {
		$groups = array();
		$level  = max( 1, min( 6, $level ) );

		foreach ( $items as $item ) {
			$metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
			$path     = self::normalize_string_list( $metadata['heading_path'] ?? array() );
			$key      = empty( $path ) ? '__preamble' : self::heading_key( array_slice( $path, 0, $level ) );

			$groups[ $key ][] = $item;
		}

		return $groups;
	}

	/**
	 * Deterministically split ordered markdown section items for overflow archival.
	 *
	 * @param array<int, array<string, mixed>> $items  Ordered markdown section items.
	 * @param array<string, mixed>            $policy Overflow policy.
	 * @return array{status: string, retained_items: array<int, array<string, mixed>>, archive_items: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>}
	 */
	public static function split_for_overflow( array $items, array $policy ): array {
		$policy         = self::normalize_overflow_policy( $policy );
		$items          = WP_Agent_Compaction_Item::normalize_many( $items );
		$original_bytes = strlen( self::reconstruct( $items ) );

		if ( $policy['target_bytes'] <= 0 || $original_bytes <= $policy['target_bytes'] ) {
			return self::overflow_result( self::STATUS_SKIPPED, $items, $items, array(), $policy, array( 'reason' => 'overflow_input_below_target' ) );
		}

		$sections = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return self::TYPE_SECTION === ( $item['type'] ?? '' );
				}
			)
		);

		if ( count( $sections ) < 2 ) {
			return self::overflow_result( self::STATUS_SKIPPED, $items, $items, array(), $policy, array( 'reason' => 'overflow_input_unsplittable' ) );
		}

		$retained      = array();
		$archive_items = array();
		$pointer       = self::archive_pointer_item(
			self::normalize_string( $policy['pointer_heading'] ?? '' ),
			self::normalize_string( $policy['pointer_destination'] ?? '' ),
			self::normalize_non_negative_int( $policy['pointer_level'] ?? 2 ),
			is_string( $policy['pointer_content'] ?? null ) ? $policy['pointer_content'] : ''
		);
		$section_seen  = 0;

		foreach ( $items as $item ) {
			$is_section = self::TYPE_SECTION === ( $item['type'] ?? '' );
			if ( ! $is_section ) {
				$retained[] = $item;
				continue;
			}

			++$section_seen;
			$candidate = array_merge( $retained, array( $item, $pointer ) );
			if ( 1 === $section_seen || strlen( self::reconstruct( $candidate ) ) <= $policy['target_bytes'] ) {
				$retained[] = $item;
				continue;
			}

			$archive_items[] = $item;
		}

		if ( empty( $archive_items ) ) {
			return self::overflow_result( self::STATUS_SKIPPED, $items, $items, array(), $policy, array( 'reason' => 'overflow_no_archive_boundary' ) );
		}

		$retained[] = $pointer;

		return self::overflow_result(
			self::STATUS_ARCHIVED,
			$items,
			$retained,
			$archive_items,
			$policy,
			array(
				'strategy' => 'deterministic_markdown_section_overflow_archive',
				'boundary' => array(
					'retained_count' => count( $retained ),
					'archive_count'  => count( $archive_items ),
				),
			)
		);
	}

	/**
	 * Build an archive pointer section item.
	 *
	 * @param string $heading     Pointer heading text.
	 * @param string $destination Consumer-owned destination string.
	 * @param int    $level       Markdown heading level.
	 * @param string $content     Optional pointer content.
	 * @return array<string, mixed>
	 */
	public static function archive_pointer_item( string $heading, string $destination, int $level = 2, string $content = '' ): array {
		$heading     = trim( $heading );
		$destination = trim( $destination );
		$level       = max( 1, min( 6, $level ) );

		if ( '' === $heading ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_pointer: heading must be non-empty' );
		}

		if ( '' === $destination ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_pointer: destination must be non-empty' );
		}

		if ( '' === $content ) {
			$content = '[Archived section: ' . $destination . ']' . "\n";
		}

		$heading_line = str_repeat( '#', $level ) . ' ' . $heading . "\n";
		$heading_path = array( $heading );
		$heading_key  = self::heading_key( $heading_path );

		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => 'section:' . $heading_key . ':pointer',
			'type'     => self::TYPE_SECTION_POINTER,
			'content'  => $content,
			'metadata' => array(
				'heading_path'         => $heading_path,
				'heading_key'          => $heading_key,
				'heading_level'        => $level,
				'heading_text'         => $heading,
				'heading_line'         => $heading_line,
				'boundary_heading_key' => $heading_key,
				'pointer_destination'  => $destination,
			),
		);
	}

	/**
	 * Normalize markdown overflow policy fields.
	 *
	 * @param array<string, mixed> $policy Raw policy.
	 * @return array<string, mixed>
	 */
	private static function normalize_overflow_policy( array $policy ): array {
		$policy = WP_Agent_Compaction_Conservation::normalize_policy( $policy );

		$archive_pointer               = is_array( $policy['archive_pointer'] ?? null ) ? $policy['archive_pointer'] : array();
		$policy['target_bytes']        = self::normalize_non_negative_int( $policy['target_bytes'] ?? $policy['overflow_retained_bytes'] ?? 0 );
		$policy['pointer_destination'] = self::normalize_string( $policy['pointer_destination'] ?? $archive_pointer['destination'] ?? '' );
		$policy['pointer_heading']     = self::normalize_string( $policy['pointer_heading'] ?? 'Archived Sections' );
		$policy['pointer_level']       = max( 1, min( 6, self::normalize_non_negative_int( $policy['pointer_level'] ?? 2 ) ) );
		$policy['pointer_content']     = is_string( $policy['pointer_content'] ?? null ) ? $policy['pointer_content'] : '';

		return array(
			'target_bytes'        => $policy['target_bytes'],
			'pointer_destination' => $policy['pointer_destination'],
			'pointer_heading'     => $policy['pointer_heading'],
			'pointer_level'       => $policy['pointer_level'],
			'pointer_content'     => $policy['pointer_content'],
		) + $policy;
	}

	/**
	 * Build a normalized overflow result.
	 *
	 * @param string                    $status        Status.
	 * @param array<int, array<string, mixed>> $source_items   Source items.
	 * @param array<int, array<string, mixed>> $retained_items Retained items.
	 * @param array<int, array<string, mixed>> $archive_items  Archive items.
	 * @param array<string, mixed>      $policy        Policy.
	 * @param array<string, mixed>      $extra         Extra metadata.
	 * @return array{status: string, retained_items: array<int, array<string, mixed>>, archive_items: array<int, array<string, mixed>>, metadata: array<string, mixed>, events: array<int, array<string, mixed>>}
	 */
	private static function overflow_result( string $status, array $source_items, array $retained_items, array $archive_items, array $policy, array $extra ): array {
		$archive_id = '';
		if ( ! empty( $archive_items ) ) {
			$archive_id = 'agents-api-markdown-overflow-' . substr( hash( 'sha256', self::encoded_json( $archive_items ) ), 0, 16 );
		}

		$metadata = WP_Agent_Compaction_Conservation::metadata(
			$policy,
			$source_items,
			array(),
			$retained_items,
			$archive_items,
			array_merge(
				$extra,
				array(
					'status'               => $status,
					'archive_id'           => $archive_id,
					'archive_pointer'      => array( 'destination' => $policy['pointer_destination'] ),
					'total_markdown_bytes' => strlen( self::reconstruct( $source_items ) ),
				)
			)
		);

		$events = self::STATUS_ARCHIVED === $status ? array( self::event( 'compaction_overflow_archived', $metadata ) ) : array();

		return array(
			'status'         => $status,
			'retained_items' => $retained_items,
			'archive_items'  => $archive_items,
			'metadata'       => $metadata,
			'events'         => $events,
		);
	}

	/**
	 * Normalize a string policy field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_string( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Return a string value without trimming significant markdown whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function string_value( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize a non-negative integer policy value.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function normalize_non_negative_int( $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			return max( 0, intval( $value ) );
		}

		return 0;
	}

	/**
	 * Normalize a list of string values.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int, string>
	 */
	private static function normalize_string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$strings = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$strings[] = $value;
			}
		}

		return $strings;
	}

	/**
	 * Return item content when it is valid markdown text.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return string
	 */
	private static function item_content( array $item ): string {
		return is_string( $item['content'] ?? null ) ? $item['content'] : '';
	}

	/**
	 * Return item metadata when it is an array.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>
	 */
	private static function item_metadata( array $item ): array {
		if ( ! is_array( $item['metadata'] ?? null ) ) {
			return array();
		}

		$metadata = array();
		foreach ( $item['metadata'] as $key => $value ) {
			if ( is_string( $key ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Encode data consistently for deterministic archive IDs.
	 *
	 * @param mixed $data Data to encode.
	 * @return string Encoded JSON.
	 */
	private static function encoded_json( $data ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-PHP smoke tests run without WordPress loaded.
			$encoded = json_encode( $data );
		}

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Build a lifecycle event payload.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, mixed>
	 */
	private static function event( string $type, array $data ): array {
		return array(
			'type'     => $type,
			'metadata' => $data,
		);
	}

	/**
	 * Split markdown into lines while preserving newline characters.
	 *
	 * @param string $markdown Markdown document.
	 * @return array<int, string>
	 */
	private static function split_lines( string $markdown ): array {
		if ( '' === $markdown ) {
			return array();
		}

		$lines = preg_split( '/(?<=\n)|(?<=\r)(?!\n)/', $markdown );
		if ( false === $lines ) {
			return array( $markdown );
		}

		if ( array( '' ) === array_slice( $lines, -1 ) ) {
			array_pop( $lines );
		}

		return $lines;
	}

	/**
	 * Parse an ATX heading line.
	 *
	 * @param string $line Markdown line including optional newline.
	 * @return array{level: int, text: string, line: string}|null Parsed heading.
	 */
	private static function parse_heading( string $line ): ?array {
		$line_without_newline = preg_replace( '/\r\n|\n|\r$/', '', $line );
		if ( ! is_string( $line_without_newline ) ) {
			return null;
		}

		if ( ! preg_match( '/^(#{1,6})(?:[ \t]+(.*))?[ \t]*$/', $line_without_newline, $matches ) ) {
			return null;
		}

		$text = isset( $matches[2] ) ? (string) $matches[2] : '';
		$text = (string) preg_replace( '/[ \t]+#+[ \t]*$/', '', $text );
		$text = trim( $text );

		return array(
			'level' => strlen( $matches[1] ),
			'text'  => $text,
			'line'  => $line,
		);
	}

	/**
	 * Build the initial preamble item.
	 *
	 * @return array<string, mixed>
	 */
	private static function preamble_item(): array {
		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => '__preamble',
			'type'     => self::TYPE_PREAMBLE,
			'content'  => '',
			'metadata' => array(
				'heading_path'         => array(),
				'heading_key'          => '__preamble',
				'heading_level'        => 0,
				'heading_text'         => '',
				'heading_line'         => '',
				'boundary_heading_key' => '__preamble',
			),
		);
	}

	/**
	 * Build a section item shell.
	 *
	 * @param array<string, mixed> $heading      Parsed heading.
	 * @param array<int, string>   $heading_path Heading path.
	 * @param string               $heading_key  Stable heading key.
	 * @return array<string, mixed>
	 */
	private static function section_item( array $heading, array $heading_path, string $heading_key ): array {
		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => 'section:' . $heading_key,
			'type'     => self::TYPE_SECTION,
			'content'  => '',
			'metadata' => array(
				'heading_path'         => $heading_path,
				'heading_key'          => $heading_key,
				'heading_level'        => $heading['level'],
				'heading_text'         => $heading['text'],
				'heading_line'         => $heading['line'],
				'boundary_heading_key' => self::heading_key( array_slice( $heading_path, 0, 1 ) ),
			),
		);
	}

	/**
	 * Add ordering metadata.
	 *
	 * @param array<string, mixed> $item  Item.
	 * @param int                  $order Original order.
	 * @return array<string, mixed>
	 */
	private static function finalize_item( array $item, int $order ): array {
		$item['metadata']          = self::item_metadata( $item );
		$item['metadata']['order'] = $order;
		return $item;
	}

	/**
	 * Build a replacement item for summaries or pointers.
	 *
	 * @param array<string, mixed> $section_item Source section item.
	 * @param string               $type         Replacement type.
	 * @param string               $content      Replacement content.
	 * @return array<string, mixed>
	 */
	private static function replacement_item( array $section_item, string $type, string $content ): array {
		if ( self::TYPE_PREAMBLE === ( $section_item['type'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_item: preamble cannot be replaced as a section' );
		}

		$metadata = is_array( $section_item['metadata'] ?? null ) ? $section_item['metadata'] : array();
		if ( '' === self::normalize_string( $metadata['heading_line'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'invalid_markdown_section_item: section item missing heading line' );
		}

		return array(
			'schema'   => self::ITEM_SCHEMA,
			'version'  => self::ITEM_VERSION,
			'id'       => self::normalize_string( $section_item['id'] ?? 'section' ) . ':' . str_replace( 'markdown_section_', '', $type ),
			'type'     => $type,
			'content'  => $content,
			'metadata' => array_merge(
				$metadata,
				array(
					'source_item_id' => $section_item['id'] ?? '',
				)
			),
		);
	}

	/**
	 * Convert a heading path into a stable key.
	 *
	 * @param array<int, string> $heading_path Heading path.
	 * @return string
	 */
	private static function heading_key( array $heading_path ): string {
		$segments = array();
		foreach ( $heading_path as $segment ) {
			$segment    = strtolower( trim( $segment ) );
			$segment    = (string) preg_replace( '/[^a-z0-9]+/', '-', $segment );
			$segment    = trim( $segment, '-' );
			$segments[] = '' === $segment ? 'untitled' : $segment;
		}

		return implode( '/', $segments );
	}
}
