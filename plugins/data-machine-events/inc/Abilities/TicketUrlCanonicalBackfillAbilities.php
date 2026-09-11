<?php
/**
 * Ticket URL Canonical Backfill Abilities
 *
 * One-time migration converting wrapper-stored affiliate ticket URLs to
 * canonical vendor URLs in the Event Details block's `ticketUrl` attribute
 * (issue #818). The wrapper is re-assembled at resolve time from config
 * (see `inc/Core/ticket-destination.php`), so post_content no longer needs
 * to carry the affiliate ID, campaign ID, or ad ID.
 *
 * Establishes nothing new operationally: it follows the dry-run-by-default,
 * `--execute`, `--future-only`, `--limit` batch traversal conventions of
 * `TicketUrlResyncAbilities` (the v0.8.39 recovery tool) and rewrites block
 * content the way `EncodingFixAbilities` does (parse_blocks → mutate attrs
 * → serialize_blocks → wp_update_post). It is a deliberate sibling, not a
 * modification of either — see the file-ownership note on PR for #818.
 *
 * Safety properties (the reason this migration is independently revertible):
 *
 * - Dry-run by default; nothing writes without `execute => true`.
 * - Only rows whose unwrap → re-assemble round-trip is byte-identical to
 *   the normalized stored wrapper are converted. Attribution drift is
 *   structurally impossible, not merely unlikely: any wrapper whose inner
 *   URL carries nested percent-encoding (the Impact Radius-wrapped
 *   SeatGeek shape, for example) would not round-trip exactly through the
 *   shared unwrapper, and is reported as `skipped_roundtrip_mismatch`
 *   instead of being rewritten. A partial, provably-safe migration beats a
 *   complete, possibly-lossy one.
 * - Mangled rows (the v0.8.39-era `u=httpswww.ticketmaster.com...`
 *   punctuation-stripped shape, 17 known published events) cannot be
 *   unwrapped and are REPORTED, never written — detection/repair of that
 *   shape is owned by issue #823; this tool only surfaces them so the two
 *   views can be reconciled.
 * - Idempotent and therefore resumable: converted rows are no longer
 *   affiliate-shaped, so a re-run (or a run interrupted by `--limit`
 *   chunking) simply reports them as `skipped_not_wrapper` and moves on.
 *
 * Inline prose anchors (36,627 events carry the affiliate URL as an inline
 * anchor in post_content) are deliberately out of scope: they are handled
 * at render time by the legacy ticket-link gate (`legacy-ticket-link-gate.php`),
 * which keeps working on wrapper-stored anchors regardless of what this
 * migration does to block attributes.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.62.1
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use function DataMachineEvents\Core\data_machine_events_assemble_affiliate_wrapper;
use function DataMachineEvents\Core\data_machine_events_is_affiliate_ticket_url;
use function DataMachineEvents\Core\datamachine_decode_stored_url_artifacts;
use function DataMachineEvents\Core\datamachine_unwrap_affiliate_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketUrlCanonicalBackfillAbilities {

	private const DEFAULT_LIMIT = -1;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$changes_item_schema = self::buildPostKeyedItemSchema(
			array(
				'old' => array( 'type' => 'string' ),
				'new' => array( 'type' => 'string' ),
			)
		);
		$report_item_schema  = self::buildPostKeyedItemSchema(
			array(
				'reason' => array( 'type' => 'string' ),
				'url'    => array( 'type' => 'string' ),
			)
		);

		$register_callback = function () use ( $changes_item_schema, $report_item_schema ) {
			wp_register_ability(
				'data-machine-events/backfill-canonical-ticket-urls',
				array(
					'label'               => __( 'Backfill Canonical Ticket URLs', 'data-machine-events' ),
					'description'         => __( 'Convert wrapper-stored affiliate ticket URLs to canonical vendor URLs in event block content. Dry-run by default; only converts rows whose unwrap/re-assemble round-trip is byte-identical.', 'data-machine-events' ),
					'category'            => AbilityCategories::EVENTS,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'     => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying (default: true)',
								'default'     => true,
							),
							'limit'       => array(
								'type'        => 'integer',
								'description' => 'Maximum events to process (default: -1 for all)',
								'default'     => -1,
							),
							'future_only' => array(
								'type'        => 'boolean',
								'description' => 'Only process events with future start dates (default: false)',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'dry_run'                    => array( 'type' => 'boolean' ),
							'scanned'                    => array( 'type' => 'integer' ),
							'updated'                    => array( 'type' => 'integer' ),
							'failed'                     => array( 'type' => 'integer' ),
							'skipped_no_url'             => array( 'type' => 'integer' ),
							'skipped_not_wrapper'        => array( 'type' => 'integer' ),
							'skipped_roundtrip_mismatch' => array( 'type' => 'integer' ),
							'mangled'                    => array( 'type' => 'integer' ),
							'changes'                    => array(
								'type'  => 'array',
								'items' => $changes_item_schema,
							),
							'report'                     => array(
								'type'  => 'array',
								'items' => $report_item_schema,
							),
							'message'                    => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeBackfill' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Build a JSON Schema `items` shape keyed on `post_id`/`title` plus
	 * caller-supplied trailing fields.
	 *
	 * `changes` and `report` in the output_schema below are both arrays of
	 * objects that identify the same post the same way, differing only in
	 * which extra fields they carry (`old`/`new` vs `reason`/`url`). A
	 * single builder — rather than two literal array blocks — is what
	 * keeps that shared shape from being duplicated in source.
	 *
	 * @param array<string, array<string, string>> $trailing_fields Extra
	 *                                                               JSON
	 *                                                               Schema
	 *                                                               property
	 *                                                               definitions
	 *                                                               beyond
	 *                                                               `post_id`/`title`.
	 * @return array<string, mixed> A JSON Schema object shape.
	 */
	private static function buildPostKeyedItemSchema( array $trailing_fields ): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
				),
				$trailing_fields
			),
		);
	}

	/**
	 * Execute the canonical ticket URL backfill.
	 *
	 * @param array $input Input parameters (`dry_run`, `limit`, `future_only`).
	 * @return array Per-slice counts, converted changes, and non-converted report.
	 */
	public function executeBackfill( array $input ): array {
		$dry_run     = $input['dry_run'] ?? true;
		$limit       = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$future_only = $input['future_only'] ?? false;

		$event_query = new EventDateQueryAbilities();
		$query_input = array(
			// Backfill writes are scoped to published events: the 74,522-row
			// blast radius from issue #818 is the published corpus, and
			// minimizing the write surface keeps non-public states untouched.
			'scope'    => $future_only ? 'upcoming' : 'all',
			'status'   => 'publish',
			'per_page' => $limit,
			'order'    => 'DESC',
		);

		$result = $event_query->executeQueryEvents( $query_input );
		$events = $result['posts'];

		$counters = array(
			'scanned'                    => 0,
			'updated'                    => 0,
			'failed'                     => 0,
			'skipped_no_url'             => 0,
			'skipped_not_wrapper'        => 0,
			'skipped_roundtrip_mismatch' => 0,
			'mangled'                    => 0,
		);
		$changes  = array();
		$report   = array();

		foreach ( $events as $event ) {
			++$counters['scanned'];

			$stored = $this->extractTicketUrl( (int) $event->ID );

			if ( '' === $stored ) {
				++$counters['skipped_no_url'];
				continue;
			}

			// Entity normalization must run before any parsing: ~54% of rows
			// store `&amp;` (and some a literal `\u0026` artifact), and the
			// unwrapper's parse_str would otherwise split on the entity.
			$normalized = datamachine_decode_stored_url_artifacts( $stored );

			if ( ! data_machine_events_is_affiliate_ticket_url( $normalized ) ) {
				// Already canonical (previous backfill pass or a post-#818
				// import), or a direct URL to a vendor we do not wrap.
				// Idempotency lives here: re-runs land in this bucket.
				++$counters['skipped_not_wrapper'];
				continue;
			}

			$canonical = datamachine_unwrap_affiliate_url( $normalized );

			if ( $canonical === $normalized ) {
				// Unwrapper could not extract an inner URL — the mangled
				// `u=httpswww...` shape. Report, never write; #823 owns the
				// detector/repair conversation for this shape.
				++$counters['mangled'];
				$report[] = array(
					'post_id' => (int) $event->ID,
					'title'   => $event->post_title,
					'reason'  => 'mangled_unrecoverable',
					'url'     => $normalized,
				);
				continue;
			}

			// Round-trip guard: only convert when re-assembling the wrapper
			// from the extracted canonical reproduces the stored wrapper
			// byte-for-byte. Nested-encoding inner URLs (SeatGeek-style
			// `dd_referrer=https%253A%252F%252F...`) over-decode through the
			// shared unwrapper and fail this check, so they stay
			// wrapper-stored — proven-working shapes are never rewritten.
			$reassembled = data_machine_events_assemble_affiliate_wrapper( $canonical );

			if ( $reassembled !== $normalized ) {
				++$counters['skipped_roundtrip_mismatch'];
				$report[] = array(
					'post_id' => (int) $event->ID,
					'title'   => $event->post_title,
					'reason'  => 'roundtrip_mismatch',
					'url'     => $normalized,
				);
				continue;
			}

			if ( ! $dry_run ) {
				$wrote = $this->writeCanonicalTicketUrl( (int) $event->ID, $canonical );
				if ( ! $wrote ) {
					++$counters['failed'];
					$report[] = array(
						'post_id' => (int) $event->ID,
						'title'   => $event->post_title,
						'reason'  => 'write_failed',
						'url'     => $normalized,
					);
					continue;
				}
			}

			$changes[] = array(
				'post_id' => (int) $event->ID,
				'title'   => $event->post_title,
				'old'     => $normalized,
				'new'     => $canonical,
			);
			++$counters['updated'];
		}

		return array_merge(
			$counters,
			array(
				'dry_run' => (bool) $dry_run,
				'changes' => $changes,
				'report'  => $report,
				'message' => $this->buildSummaryMessage( (bool) $dry_run, $counters ),
			)
		);
	}

	/**
	 * Extract the raw ticket URL from the Event Details block.
	 *
	 * Same traversal pattern as `TicketUrlResyncAbilities::extractTicketUrl()`
	 * and `ResolveTicketDestinationAbilities::findTicketUrlInBlocks()`.
	 *
	 * @param int $post_id Post ID.
	 * @return string Raw stored ticket URL, or empty string.
	 */
	private function extractTicketUrl( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$blocks = parse_blocks( $post->post_content );
		return $this->findTicketUrlInBlocks( $blocks );
	}

	/**
	 * Recursively search blocks for the ticket URL.
	 *
	 * @param array $blocks Block array.
	 * @return string Ticket URL or empty string.
	 */
	private function findTicketUrlInBlocks( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				return (string) ( $block['attrs']['ticketUrl'] ?? '' );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = $this->findTicketUrlInBlocks( $block['innerBlocks'] );
				if ( '' !== $result ) {
					return $result;
				}
			}
		}
		return '';
	}

	/**
	 * Write the canonical URL into the Event Details block's ticketUrl attr.
	 *
	 * Same parse_blocks → mutate attrs → serialize_blocks → wp_update_post
	 * pattern as `EncodingFixAbilities::applyFixes()`. The canonical URL
	 * contains no ampersands (no query string), so it is unaffected by the
	 * entity normalization wp_insert_post applies for non-`unfiltered_html`
	 * authors. `wp_update_post` fires `save_post`, which re-syncs the
	 * dedup ticket-URL meta and the event-dates table from the new block
	 * content — derived state stays consistent with the migration.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $canonical Canonical vendor ticket URL.
	 * @return bool True when the content write succeeded.
	 */
	private function writeCanonicalTicketUrl( int $post_id, string $canonical ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$blocks      = parse_blocks( $post->post_content );
		$block_index = null;

		foreach ( $blocks as $index => $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
				$block_index = $index;
				break;
			}
		}

		if ( null === $block_index ) {
			return false;
		}

		// Rebuild the block array with all five keys explicit rather than
		// mutating the 'attrs' offset in place: assigning into a single
		// offset of a variable-indexed array-shape union (parse_blocks()'s
		// return type) makes PHPStan lose track of the block's other keys,
		// so it can no longer prove the mutated element still matches the
		// shape serialize_blocks() requires.
		$block                  = $blocks[ $block_index ];
		$blocks[ $block_index ] = array(
			'blockName'    => $block['blockName'],
			'attrs'        => array_merge( (array) $block['attrs'], array( 'ticketUrl' => $canonical ) ),
			'innerBlocks'  => $block['innerBlocks'],
			'innerHTML'    => $block['innerHTML'],
			'innerContent' => $block['innerContent'],
		);

		$new_content = serialize_blocks( $blocks );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Build summary message with per-slice counts.
	 *
	 * @param bool  $dry_run  Whether this is a dry run.
	 * @param array $counters Counter map.
	 * @return string Summary message.
	 */
	private function buildSummaryMessage( bool $dry_run, array $counters ): string {
		$summary = sprintf(
			'Scanned %d published events: %d would be converted, %d already canonical/non-affiliate, %d without a ticket URL, %d mangled (reported), %d round-trip mismatch (reported), %d failed.',
			$counters['scanned'],
			$counters['updated'],
			$counters['skipped_not_wrapper'],
			$counters['skipped_no_url'],
			$counters['mangled'],
			$counters['skipped_roundtrip_mismatch'],
			$counters['failed']
		);

		if ( $dry_run ) {
			return $summary . ' Dry run — run with --execute to apply.';
		}

		return $summary;
	}
}
