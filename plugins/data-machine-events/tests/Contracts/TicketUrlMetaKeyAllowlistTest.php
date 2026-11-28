<?php
/**
 * Allowlist guard for readers of the lossy dedup-comparison ticket URL meta.
 *
 * `EVENT_TICKET_URL_META_KEY` (`_datamachine_ticket_url`, defined in
 * `inc/Core/event-dates-sync.php`) is written by
 * `datamachine_normalize_ticket_url()`, which intentionally strips every
 * query parameter except the `u`/`e` identity params. It is a
 * DUPLICATE-DETECTION COMPARISON KEY, not a redirect-safe URL — a redirect
 * or display consumer that reads it expecting the complete, as-authored
 * ticket URL silently drops `utm_medium=affiliate` and every other tracking
 * parameter (issue #816, PR #820).
 *
 * Issue #821 tracked hardening this footgun so a future redirect-purpose
 * reader gets caught before merge instead of in review, as #816/#820 was.
 * This test is that guard: every file that references the meta key (by
 * constant or by its literal string value) must be an explicitly reviewed,
 * allow-listed consumer. Adding a NEW reader anywhere else fails this test
 * — that is the point. When it fails, either the new reader is genuinely
 * dedup/comparison-only (add it to the allow list below with a one-line
 * justification) or it is a redirect/display consumer that must NOT use
 * this meta key (parse the Event Details block's `ticketUrl` attribute
 * directly instead — see `ResolveTicketDestinationAbilities::resolveTicketUrl()`).
 *
 * @package DataMachineEvents\Tests\Contracts
 * @since   0.65.1
 * @see     https://github.com/Extra-Chill/data-machine-events/issues/821
 */

namespace DataMachineEvents\Tests\Contracts;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

class TicketUrlMetaKeyAllowlistTest extends TestCase {

	/**
	 * Every file permitted to reference `EVENT_TICKET_URL_META_KEY` (by
	 * constant name or by its literal `_datamachine_ticket_url` string
	 * value), relative to the plugin root, with the reason it's safe.
	 *
	 * All are dedup/comparison-only EXCEPT
	 * `ResolveTicketDestinationAbilities`, which reads the meta as a
	 * deliberate, heavily documented fallback for redirect purposes only
	 * when no Event Details block is parseable — see the docblock on
	 * `ResolveTicketDestinationAbilities::resolveTicketUrl()` and issue
	 * #821 for why that fallback's lossiness is accepted there.
	 */
	private const ALLOWED_FILES = array(
		// Definition + write site.
		'inc/Core/event-dates-sync.php',
		// Duplicate-detection reads (comparison-only).
		'inc/Cli/Check/CleanDuplicatesCommand.php',
		'inc/Core/DuplicateDetection/EventMergeHelper.php',
		'inc/Core/DuplicateDetection/EventDuplicateStrategy.php',
		'inc/Abilities/MergedBillDecideAbilities.php',
		'inc/Abilities/MergedBillDetectAbilities.php',
		'inc/Abilities/TicketUrlResyncAbilities.php',
		// Deliberate, documented, accepted redirect-purpose fallback — see
		// class docblock above.
		'inc/Abilities/ResolveTicketDestinationAbilities.php',
	);

	public function test_meta_key_readers_are_allow_listed(): void {
		$root = dirname( __DIR__, 2 );
		$inc  = $root . '/inc';

		$files = new RegexIterator(
			new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $inc ) ),
			'/\.php$/'
		);

		$patterns  = array( 'EVENT_TICKET_URL_META_KEY', '_datamachine_ticket_url' );
		$unallowed = array();

		foreach ( $files as $file ) {
			$relative = 'inc/' . ltrim( str_replace( $inc, '', $file->getPathname() ), '/' );
			if ( in_array( $relative, self::ALLOWED_FILES, true ) ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() );
			foreach ( $patterns as $pattern ) {
				if ( false !== strpos( $contents, $pattern ) ) {
					$unallowed[] = $relative;
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$unallowed,
			"New reader(s) of EVENT_TICKET_URL_META_KEY found outside the allow list: " . implode( ', ', $unallowed )
			. "\nThis meta key is a dedup COMPARISON key (see issue #821) that strips affiliate/tracking query params."
			. ' If this new reader only ever compares/matches the value, add it to TicketUrlMetaKeyAllowlistTest::ALLOWED_FILES'
			. ' with a one-line reason. If it sends a real visitor to the value or displays it verbatim, it must NOT read'
			. " this meta key — parse the Event Details block's ticketUrl attribute directly instead."
		);
	}

	/**
	 * The allow list itself must stay accurate: every listed file must
	 * still exist and still reference the meta key. A stale entry (file
	 * deleted, or the reference removed) would let the allow list quietly
	 * grow without ever being re-examined.
	 */
	public function test_allow_list_entries_are_still_accurate(): void {
		$root  = dirname( __DIR__, 2 );
		$stale = array();

		foreach ( self::ALLOWED_FILES as $relative ) {
			$path = $root . '/' . $relative;
			if ( ! is_file( $path ) ) {
				$stale[] = "{$relative} (file no longer exists)";
				continue;
			}

			$contents = file_get_contents( $path );
			$found    = false;
			foreach ( array( 'EVENT_TICKET_URL_META_KEY', '_datamachine_ticket_url' ) as $pattern ) {
				if ( false !== strpos( $contents, $pattern ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$stale[] = "{$relative} (no longer references the meta key)";
			}
		}

		$this->assertSame( array(), $stale, 'Stale allow-list entries: ' . implode( ', ', $stale ) );
	}
}
