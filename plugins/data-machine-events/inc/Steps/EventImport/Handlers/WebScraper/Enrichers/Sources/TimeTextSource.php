<?php
/**
 * Time text enrichment source.
 *
 * Extracts a show start time from the visible text of a fetched detail
 * page: "På scen kl. 20:00", "Startar 12 sep 20:00", "Doors 7pm /
 * Show 8pm", "8:00PM", "19.30".
 *
 * Doors are not starts. Each time occurrence on the page inherits the
 * class of the nearest label within a small look-behind window: a doors
 * label (Doors, Insläpp, Entrén öppnar) marks it a door time, a show-start
 * label (Startar, Starts, Show, På scen, Scen) marks it a start. A bare
 * time prefix (kl., klockan) counts as a start only when no door label is
 * nearer; a time with no label at all is treated as a start candidate.
 * If every time on the page is door-classified, no start time is returned
 * rather than guessing.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BaseExtractor;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Enrichers\Sources\SourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TimeTextSource extends BaseExtractor implements SourceInterface {

	/**
	 * How far back (characters) a label may sit from the time it governs.
	 *
	 * Keeps nav/footer text ("Startsida") from classifying unrelated body
	 * times.
	 */
	private const LABEL_LOOKBEHIND_CHARS = 60;

	/**
	 * Door labels — never a show start on their own.
	 */
	private const DOOR_PATTERN = '/(doors?\b|insläpp\b|entrén?\b)/iu';

	/**
	 * Strong show-start labels.
	 */
	private const START_PATTERN = '/\b(start\w*|show(?:time)?\b|på\s+scen\b|\bscen\b)/iu';

	/**
	 * Weak time prefixes — a start signal only when no door label is nearer.
	 */
	private const WEAK_PATTERN = '/(klockan\b|\bkl\b\.?)/iu';

	/**
	 * Times with a separator: "20:00", "19.30", "6:00PM".
	 */
	private const TIME_SEPARATOR_PATTERN = '/(?<!\d)(\d{1,2})\s*[:.]\s*(\d{2})(?!\d)(?:\s*([ap])\.?\s?m\.?)?/iu';

	/**
	 * Hour + meridiem without separator: "7pm", "8 PM".
	 */
	private const TIME_MERIDIEM_PATTERN = '/(?<!\d)(\d{1,2})\s*([ap])\.?\s?m\.?(?![a-z])/iu';

	/**
	 * Whether the page carries any time-like token at all.
	 *
	 * Cheap pre-check; the enrichment stage calls extract() directly, so
	 * this exists to satisfy the BaseExtractor contract with a meaningful
	 * answer rather than a stub.
	 *
	 * @param string $html Raw page HTML.
	 * @return bool
	 */
	public function canExtract( string $html ): bool {
		return (bool) preg_match( self::TIME_SEPARATOR_PATTERN, $html )
			|| (bool) preg_match( self::TIME_MERIDIEM_PATTERN, $html );
	}

	/** {@inheritdoc} */
	public function provides(): array {
		return array( 'startTime' );
	}

	/** {@inheritdoc} */
	public function getMethod(): string {
		return 'time_text';
	}

	/**
	 * Extract a show start time from a fetched detail page.
	 *
	 * @param string $html       Raw page HTML.
	 * @param string $source_url URL the HTML was fetched from (unused; part of the source contract).
	 * @return array{startTime?: string} Found fields; empty array when no start time is present.
	 */
	public function extract( string $html, string $source_url = '' ): array {
		$text = $this->visibleText( $html );
		if ( '' === $text ) {
			return array();
		}

		$time = $this->selectShowStart( $text );
		if ( '' === $time ) {
			return array();
		}

		return array( 'startTime' => $time );
	}

	/**
	 * Reduce page HTML to decoded, whitespace-collapsed visible text.
	 *
	 * Entities are decoded twice: some venues double-encode copy
	 * ("P&amp;aring; scen" for "På scen").
	 *
	 * @param string $html Raw page HTML.
	 * @return string
	 */
	private function visibleText( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $html );
		$html = preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html );

		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Pick the show start time from visible text.
	 *
	 * Preference order: the first start-labelled time, then the first
	 * unlabelled time, then nothing (door-only pages stay empty).
	 *
	 * @param string $text Visible page text.
	 * @return string Time in H:i, or empty string.
	 */
	private function selectShowStart( string $text ): string {
		$bare_fallback = '';

		foreach ( $this->findTimes( $text ) as $candidate ) {
			switch ( $this->classify( $text, $candidate['offset'] ) ) {
				case 'start':
					return $this->normalize( $candidate );
				case 'bare':
					if ( '' === $bare_fallback ) {
						$bare_fallback = $this->normalize( $candidate );
					}
					break;
			}
		}

		return $bare_fallback;
	}

	/**
	 * Find all time-like tokens in the text with their offsets.
	 *
	 * @param string $text Visible page text.
	 * @return array<int, array{offset: int, hour: int, minute: int, meridiem: string}>
	 */
	private function findTimes( string $text ): array {
		$candidates = array();
		$occupied   = array();

		foreach ( $this->matchTimePatterns( $text ) as $candidate ) {
			$offset = $candidate['offset'];

			foreach ( $occupied as $span ) {
				if ( $offset < $span[1] && $span[0] < $offset + $candidate['length'] ) {
					continue 2;
				}
			}

			if ( $candidate['hour'] > 23 || $candidate['minute'] > 59 ) {
				continue;
			}

			$occupied[]   = array( $offset, $offset + $candidate['length'] );
			$candidates[] = array(
				'offset'   => $candidate['offset'],
				'hour'     => $candidate['hour'],
				'minute'   => $candidate['minute'],
				'meridiem' => $candidate['meridiem'],
			);
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $a['offset'] <=> $b['offset'];
			}
		);

		return $candidates;
	}

	/**
	 * Run both time patterns and yield raw candidates.
	 *
	 * Group maps per pattern:
	 *  - separator pattern: 1 = hour, 2 = minutes, 3 = meridiem letter (optional)
	 *  - meridiem pattern:  1 = hour, 2 = meridiem letter
	 *
	 * @param string $text Visible page text.
	 * @return iterable<array{offset: int, length: int, hour: int, minute: int, meridiem: string}>
	 */
	private function matchTimePatterns( string $text ): iterable {
		$match_count = preg_match_all( self::TIME_SEPARATOR_PATTERN, $text, $separator_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false !== $match_count && $match_count > 0 ) {
			foreach ( $separator_matches as $match ) {
				yield array(
					'offset'   => (int) $match[0][1],
					'length'   => strlen( $match[0][0] ),
					'hour'     => (int) $match[1][0],
					'minute'   => (int) $match[2][0],
					'meridiem' => isset( $match[3] ) ? strtolower( $match[3][0] ) : '',
				);
			}
		}

		$match_count = preg_match_all( self::TIME_MERIDIEM_PATTERN, $text, $meridiem_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false !== $match_count && $match_count > 0 ) {
			foreach ( $meridiem_matches as $match ) {
				yield array(
					'offset'   => (int) $match[0][1],
					'length'   => strlen( $match[0][0] ),
					'hour'     => (int) $match[1][0],
					'minute'   => 0,
					'meridiem' => strtolower( $match[2][0] ),
				);
			}
		}
	}

	/**
	 * Classify a time occurrence as 'start', 'door', or 'bare'.
	 *
	 * @param string $text   Visible page text.
	 * @param int    $offset Byte offset of the time token.
	 * @return string
	 */
	private function classify( string $text, int $offset ): string {
		$window_start = max( 0, $offset - self::LABEL_LOOKBEHIND_CHARS );
		$before       = substr( $text, $window_start, $offset - $window_start );

		$last_class  = '';
		$last_offset = -1;
		$patterns    = array(
			'door'  => self::DOOR_PATTERN,
			'start' => self::START_PATTERN,
		);

		foreach ( $patterns as $class => $pattern ) {
			$label_count = preg_match_all( $pattern, $before, $label_matches, PREG_OFFSET_CAPTURE );
			if ( false === $label_count || 0 === $label_count ) {
				continue;
			}

			foreach ( $label_matches[1] as $label_match ) {
				if ( (int) $label_match[1] > $last_offset ) {
					$last_class  = $class;
					$last_offset = (int) $label_match[1];
				}
			}
		}

		if ( '' !== $last_class ) {
			return $last_class;
		}

		if ( preg_match( self::WEAK_PATTERN, $before ) ) {
			return 'start';
		}

		return 'bare';
	}

	/**
	 * Normalize a candidate to H:i via BaseExtractor::parseTimeString().
	 *
	 * @param array{offset: int, hour: int, minute: int, meridiem: string} $candidate Time candidate from findTimes().
	 * @return string Time in H:i, or empty string on failure.
	 */
	private function normalize( array $candidate ): string {
		$hour     = $candidate['hour'];
		$meridiem = $candidate['meridiem'];

		if ( 'p' === $meridiem && $hour < 12 ) {
			$hour += 12;
		} elseif ( 'a' === $meridiem && 12 === $hour ) {
			$hour = 0;
		}

		return $this->parseTimeString( sprintf( '%d:%02d', $hour, $candidate['minute'] ) );
	}
}
