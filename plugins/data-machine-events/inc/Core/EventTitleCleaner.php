<?php
/**
 * Event title schedule/price noise cleaner.
 *
 * Scraped listings often pack schedule and price details into the title:
 * "Sean Smith 6-9pm", "Say Uncle / Kook. Door @ 7:30. Show @ 8:30. $10 21+",
 * "David Peterson $20". Those details belong in the structured startTime,
 * endTime and price fields. Left in the title, they show up on every surface
 * that renders it (calendar rows, event pages, SEO titles, social roundups).
 * See issue #932.
 *
 * Rules are deliberately conservative and anchored so legitimate names
 * survive: "$uicideboy$", "Blink-182", "21 Savage", "Area 51",
 * "Matchbox Twenty", "Born Free". The cleaner only removes:
 *
 *  - Time ranges where the end time carries am/pm ("6-9pm", "7pm - 10pm",
 *    "6:30-9:30pm"), optionally followed by "FREE".
 *  - Door/show schedule fragments ("Door @ 7:30", "Doors at 7pm",
 *    "Show @ 8").
 *  - A trailing price clause that starts with "$<digits>" and contains only
 *    price vocabulary ("$10", "$5 over 21 / $10 18-20", "$20 adv / $25 dos").
 *    Only the first amount is returned as the price.
 *
 * Age restrictions ("21+", "(18+ Event)") stay in the title because no
 * structured age field exists to move them into, and fans need that
 * information. When one sits inside a removed price clause it is re-appended
 * ("Say Uncle / Kook 21+").
 *
 * The removed details are returned so callers can fill empty structured
 * fields instead of discarding them. The cleaner never returns an empty
 * title. When cleaning would empty it, the original is kept.
 *
 * @package DataMachineEvents\Core
 * @since   0.68.0
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class EventTitleCleaner {

	/** Clock time with optional minutes and optional meridiem. */
	private const TIME = '\d{1,2}(?::\d{2})?\s*(?:[ap]\.?\s?m\.?)?';

	/** Clock time with a required meridiem (anchors time ranges). */
	private const TIME_WITH_MERIDIEM = '(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s?m\.?';

	/**
	 * Words allowed after a "$<digits>" price token for the clause to count
	 * as price-only.
	 */
	private const PRICE_WORDS = array( 'adv', 'advance', 'dos', 'door', 'doors', 'over', 'under', 'cover', 'and', 'or', 'ages', 'age', 'event', 'tickets', 'ticket', 'tix', 'presale', 'ga', 'vip', 'plus', 'fees', 'fee', 'only', 'per', 'person', 'suggested', 'donation' );

	/** Multi-word price phrases removed before the vocabulary check. */
	private const PRICE_PHRASES = '/\b(?:day of show|at the door|general admission)\b/i';

	/** Age restriction marker kept in the title ("21+", "18+"). */
	private const AGE_MARKER = '/\b(18|19|21)\s*\+/';

	/**
	 * Clean a title.
	 *
	 * @param string $title Raw (already entity-decoded, sanitized) title.
	 * @return array{title: string, end_time: string, price: string, changed: bool}
	 *               end_time is HH:MM (24-hour) or ''. price is the removed
	 *               price clause or ''.
	 */
	public static function clean( string $title ): array {
		$original = trim( $title );
		$working  = $original;
		$end_time = '';
		$price    = '';
		$removed  = false;

		// 1. Time range anchored on a meridiem end time: "6-9pm", "7pm - 10pm".
		$range = '/(?:^|[\s,|(\[–—-])\s*(?:\d{1,2}(?::\d{2})?\s*(?:[ap]\.?\s?m\.?)?)\s*(?:-|–|—|to)\s*' . self::TIME_WITH_MERIDIEM . '\s*[)\]]?(?:\s+free\b)?(?=\s*(?:$|[\s,.|;:!)\]–—-]))/iu';
		if ( preg_match( $range, $working, $m ) ) {
			$end_time = self::to_24h( (int) $m[1], (int) $m[2], strtolower( $m[3] ) );
			$working  = (string) preg_replace( $range, ' ', $working, 1 );
			$removed  = true;
		}

		// 2. Door/show schedule fragments: "Door @ 7:30.", "Doors at 7pm", "Show @ 8".
		$working = (string) preg_replace(
			'/(?:^|(?<=[\s.,;|]))(?:doors?(?:\s*(?:and|&|\/)\s*show)?|show)\s*(?:@|at)\s*' . self::TIME . '\.?/iu',
			' ',
			$working,
			-1,
			$schedule_count
		);
		$removed = $removed || $schedule_count > 0;

		// 3. Trailing price clause starting with "$<digits>" (not in parentheses).
		if ( preg_match( '/(?:^|\s)(\$\d+(?:\.\d{2})?\b.*)$/u', $working, $pm, PREG_OFFSET_CAPTURE ) ) {
			$clause = $pm[1][0];
			if ( self::is_price_only_clause( $clause ) ) {
				// Store only the first amount. Downstream JSON-LD reads the
				// price by stripping non-digits, so "$10 21+" would become
				// 1021 and "$5 / $10" would become 510.
				preg_match( '/\$\d+(?:\.\d{2})?/', $clause, $amount );
				$price   = (string) ( $amount[0] ?? '' );
				$working = substr( $working, 0, $pm[1][1] );
				$removed = true;

				// Age restrictions have no structured field; keep them visible.
				if ( preg_match( self::AGE_MARKER, $clause, $age ) ) {
					$working = self::tidy( $working ) . ' ' . $age[1] . '+';
				}
			}
		}

		if ( ! $removed ) {
			return array(
				'title'    => $original,
				'end_time' => '',
				'price'    => '',
				'changed'  => false,
			);
		}

		$working = self::tidy( $working );
		$working = (string) preg_replace( '/[.,;]\s+((?:18|19|21)\+)$/u', ' $1', $working );

		// All-or-nothing: a schedule blob ("Rasa Nova 7pm, Touch of Space
		// 8-9pm", "Screening @ 8. Live Performances @ 9") or a clause that
		// leaves a dangling word ("Starting at", "10 for") needs a human or
		// the AI step. A partial cleanup is worse than the original.
		if ( self::has_leftover_schedule( $working ) || self::has_dangling_tail( $working ) ) {
			$working = '';
		}

		if ( '' === $working || mb_strlen( $working ) < 2 ) {
			return array(
				'title'    => $original,
				'end_time' => '',
				'price'    => '',
				'changed'  => false,
			);
		}

		return array(
			'title'    => $working,
			'end_time' => $end_time,
			'price'    => $price,
			'changed'  => $working !== $original,
		);
	}

	/**
	 * Whether schedule details remain after cleaning.
	 *
	 * @param string $text Cleaned title.
	 * @return bool
	 */
	private static function has_leftover_schedule( string $text ): bool {
		return 1 === preg_match( '/\b\d{1,2}(?::\d{2})?\s*[ap]\.?\s?m\b|@\s*\d{1,2}\b/iu', $text );
	}

	/**
	 * Whether the cleaned title ends on a connector that needed the removed text.
	 *
	 * @param string $text Cleaned title.
	 * @return bool
	 */
	private static function has_dangling_tail( string $text ): bool {
		return 1 === preg_match( '/\b(?:at|for|from|starting|only|just|is|are|and|with|w\/)$/iu', $text );
	}

	/**
	 * Whether a "$..." clause contains only price vocabulary.
	 *
	 * @param string $clause Clause starting with "$<digits>".
	 * @return bool
	 */
	private static function is_price_only_clause( string $clause ): bool {
		$clause = (string) preg_replace( self::PRICE_PHRASES, ' ', $clause );
		$words  = preg_split( '/[^a-z]+/i', strtolower( $clause ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $words as $word ) {
			if ( ! in_array( $word, self::PRICE_WORDS, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tidy only what removals leave behind.
	 *
	 * Called only after something was removed. Collapses whitespace runs and
	 * trims separators left dangling at the ends. Interior punctuation is
	 * never rewritten ("A.D.,", "Jr.", "...") because it belongs to the name.
	 *
	 * @param string $text Text to tidy.
	 * @return string
	 */
	private static function tidy( string $text ): string {
		$text = (string) preg_replace( '/\s{2,}/u', ' ', $text );
		$text = (string) preg_replace( '/\s+([.,;])(?=\s|$)/u', '$1', $text );
		$text = (string) preg_replace( '/[\s,;:|\/–—.-]+$/u', '', $text );
		$text = (string) preg_replace( '/^[\s,;:|\/–—.-]+/u', '', $text );

		return trim( $text );
	}

	/**
	 * Convert a 12-hour clock time to HH:MM.
	 *
	 * @param int    $hour     1-12.
	 * @param int    $minute   0-59.
	 * @param string $meridiem 'a' or 'p'.
	 * @return string HH:MM, or '' when out of range.
	 */
	private static function to_24h( int $hour, int $minute, string $meridiem ): string {
		if ( $hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 ) {
			return '';
		}
		if ( 'p' === $meridiem && $hour < 12 ) {
			$hour += 12;
		} elseif ( 'a' === $meridiem && 12 === $hour ) {
			$hour = 0;
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}
}
