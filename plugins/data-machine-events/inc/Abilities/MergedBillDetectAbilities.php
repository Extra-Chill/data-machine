<?php
/**
 * Merged-Bill Detector Ability
 *
 * Scans upcoming events for the merged-bill duplicate pattern: the same show
 * scraped from multiple artist landing pages, producing one post per
 * headliner. Different titles → different title_hash → identity index never
 * relates them. The signal lives in the post body lineups, not the title.
 *
 * Hard preconditions before scoring:
 *   - Same venue_term_id
 *   - Same start_datetime to the minute
 *   - Different title_hash
 *   - Both posts publish status
 *   - start_datetime >= NOW (upcoming only)
 *
 * Scoring signals (per issue #256):
 *   - Mutual body-lineup mention      +5
 *   - Identical end_datetime          +2
 *   - Matching price                  +1
 *   - Matching source URL host        +1
 *
 * Pairs at or above the threshold (default 5 = mutual lineup mention minimum)
 * are persisted to the datamachine_pending_actions queue with kind
 * `merged_bill_resolve`. The agent decision step (merged_bill_decide) later
 * drains the queue and emits a verdict.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.34.0
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Engine\AI\Actions\PendingActionStore;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;

defined( 'ABSPATH' ) || exit;

class MergedBillDetectAbilities {

	public const PENDING_ACTION_KIND = 'merged_bill_resolve';
	public const DEFAULT_THRESHOLD   = 5;
	public const DEFAULT_DAYS_AHEAD  = 90;
	public const DEFAULT_LIMIT       = 50;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/merged-bill-detect',
				array(
					'label'               => __( 'Detect merged-bill duplicate events', 'data-machine-events' ),
					'description'         => __( 'Scan upcoming events for same-venue + same-time + different-title pairs that look like the same bill scraped twice. Queues high-confidence pairs to the pending-actions queue for agent resolution.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'days_ahead' => array(
								'type'        => 'integer',
								'description' => 'How many days ahead to scan. Default 90.',
							),
							'threshold'  => array(
								'type'        => 'integer',
								'description' => 'Minimum score to queue a pair. Default 5 (mutual lineup mention).',
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Max pairs to queue this run. Default 50.',
							),
							'dry_run'    => array(
								'type'        => 'boolean',
								'description' => 'If true, return scored pairs without writing to pending_actions.',
							),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the detector.
	 *
	 * @param array $input {
	 *     @type int  $days_ahead
	 *     @type int  $threshold
	 *     @type int  $limit
	 *     @type bool $dry_run
	 * }
	 * @return array {
	 *     @type int   $scanned_pairs       Count of pairs evaluated.
	 *     @type int   $queued              Pairs persisted to pending_actions.
	 *     @type int   $skipped_decided     Pairs skipped because already decided.
	 *     @type array $pairs               Per-pair details (always included; in dry_run nothing is persisted).
	 *     @type int   $threshold
	 *     @type bool  $dry_run
	 * }
	 */
	public function execute( array $input ): array {
		$days_ahead = (int) ( $input['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD );
		$threshold  = (int) ( $input['threshold'] ?? self::DEFAULT_THRESHOLD );
		$limit      = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$dry_run    = (bool) ( $input['dry_run'] ?? false );

		if ( $days_ahead <= 0 ) {
			$days_ahead = self::DEFAULT_DAYS_AHEAD;
		}
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$groups = $this->findCandidateGroups( $days_ahead );

		$pairs           = array();
		$queued          = 0;
		$skipped_decided = 0;

		foreach ( $groups as $group ) {
			$rows  = $group['rows'];
			$count = count( $rows );

			// Pairwise within each (venue, start_datetime) group.
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$a = $rows[ $i ];
					$b = $rows[ $j ];

					// Must have different title_hash; if either is empty fall
					// back to title-string comparison (same titles aren't a
					// merged-bill — those are caught by clean-duplicates).
					if ( $a['title_hash'] !== '' && $b['title_hash'] !== '' ) {
						if ( $a['title_hash'] === $b['title_hash'] ) {
							continue;
						}
					} else {
						if ( mb_strtolower( trim( $a['title'] ) ) === mb_strtolower( trim( $b['title'] ) ) ) {
							continue;
						}
					}

					$pair_key = $this->buildPairKey( (int) $a['post_id'], (int) $b['post_id'] );

					if ( $this->isAlreadyDecided( $pair_key ) ) {
						++$skipped_decided;
						continue;
					}

					$score_info = $this->scorePair( $a, $b );
					$pair       = array(
						'pair_key'       => $pair_key,
						'post_a_id'      => (int) $a['post_id'],
						'post_a_title'   => $a['title'],
						'post_b_id'      => (int) $b['post_id'],
						'post_b_title'   => $b['title'],
						'venue_term_id'  => (int) $a['venue_term_id'],
						'start_datetime' => $a['start_datetime'],
						'score'          => $score_info['score'],
						'signals'        => $score_info['signals'],
					);

					$pairs[] = $pair;

					if ( $score_info['score'] >= $threshold && ! $dry_run && $queued < $limit ) {
						if ( $this->queuePair( $pair ) ) {
							++$queued;
						}
					}
				}
			}
		}

		// Sort by score desc for readability.
		usort(
			$pairs,
			static function ( $left, $right ) {
				return ( $right['score'] ?? 0 ) <=> ( $left['score'] ?? 0 );
			}
		);

		return array(
			'scanned_pairs'   => count( $pairs ),
			'queued'          => $queued,
			'skipped_decided' => $skipped_decided,
			'threshold'       => $threshold,
			'dry_run'         => $dry_run,
			'pairs'           => $pairs,
		);
	}

	/**
	 * Find candidate groups: events that share (venue_term_id, start_datetime)
	 * and are upcoming + published.
	 *
	 * Returns one group per (venue, start_datetime) tuple where 2+ posts
	 * exist. Each group has the minimal fields needed for pairwise scoring;
	 * the heavier per-post content (body, price, source) is loaded lazily
	 * inside scorePair() only for the pair being evaluated.
	 *
	 * @return array<int, array{key: string, rows: array<int, array<string, mixed>>}>
	 */
	private function findCandidateGroups( int $days_ahead ): array {
		global $wpdb;

		$dates_table    = EventDatesTable::table_name();
		$identity_table = $wpdb->prefix . 'datamachine_post_identity';
		$tt             = $wpdb->term_relationships;
		$tx             = $wpdb->term_taxonomy;

		$now_mysql = current_time( 'mysql' );
		$horizon   = gmdate( 'Y-m-d H:i:s', strtotime( $now_mysql . ' +' . $days_ahead . ' days' ) );

		// Pull all candidate rows: upcoming published events with a venue
		// term resolved. Use the identity index for title_hash + source_url
		// when present (it always is for scraper-imported events). Left-join
		// so we still capture posts that lack an identity row, and fall back
		// to live title-hashing inside the loop in that case.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare(
			"SELECT
				ed.post_id            AS post_id,
				ed.start_datetime     AS start_datetime,
				ed.end_datetime       AS end_datetime,
				p.post_title          AS title,
				tt.term_id            AS venue_term_id,
				COALESCE( pi.title_hash, '' ) AS title_hash,
				COALESCE( pi.source_url, '' ) AS source_url
			FROM {$dates_table} ed
			INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			LEFT JOIN {$identity_table} pi ON pi.post_id = ed.post_id
			WHERE ed.post_status = %s
				AND p.post_type = %s
				AND ed.start_datetime >= %s
				AND ed.start_datetime <= %s
			ORDER BY tt.term_id ASC, ed.start_datetime ASC, ed.post_id ASC",
			'venue',
			'publish',
			Event_Post_Type::POST_TYPE,
			$now_mysql,
			$horizon
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$buckets = array();
		foreach ( $rows as $row ) {
			$key               = $row['venue_term_id'] . '|' . $row['start_datetime'];
			$buckets[ $key ][] = $row;
		}

		$groups = array();
		foreach ( $buckets as $key => $bucket ) {
			if ( count( $bucket ) < 2 ) {
				continue;
			}
			$groups[] = array(
				'key'  => $key,
				'rows' => array_values( $bucket ),
			);
		}

		return $groups;
	}

	/**
	 * Score a pair according to the issue #256 signal table.
	 *
	 * @return array{score: int, signals: array<string, bool>}
	 */
	private function scorePair( array $a, array $b ): array {
		$signals = array(
			'mutual_lineup_mention' => false,
			'identical_end'         => false,
			'matching_price'        => false,
			'matching_source_host'  => false,
		);

		$detail_a = $this->loadEventDetail( (int) $a['post_id'] );
		$detail_b = $this->loadEventDetail( (int) $b['post_id'] );

		// Mutual lineup mention (+5).
		$mutual = $this->hasMutualLineupMention(
			$a['title'],
			$detail_a['performer'] ?? '',
			$detail_a['body_text'] ?? '',
			$b['title'],
			$detail_b['performer'] ?? '',
			$detail_b['body_text'] ?? ''
		);
		if ( $mutual ) {
			$signals['mutual_lineup_mention'] = true;
		}

		// Identical end_datetime (+2).
		$end_a = (string) ( $a['end_datetime'] ?? '' );
		$end_b = (string) ( $b['end_datetime'] ?? '' );
		if ( '' !== $end_a && '' !== $end_b && $end_a === $end_b ) {
			$signals['identical_end'] = true;
		}

		// Matching price (+1).
		if ( $this->pricesMatch( $detail_a['price'] ?? '', $detail_b['price'] ?? '' ) ) {
			$signals['matching_price'] = true;
		}

		// Matching source URL host (+1).
		if ( $this->sourceHostsMatch( $a['source_url'] ?? '', $b['source_url'] ?? '' ) ) {
			$signals['matching_source_host'] = true;
		}

		$score = 0;
		if ( $signals['mutual_lineup_mention'] ) {
			$score += 5;
		}
		if ( $signals['identical_end'] ) {
			$score += 2;
		}
		if ( $signals['matching_price'] ) {
			$score += 1;
		}
		if ( $signals['matching_source_host'] ) {
			$score += 1;
		}

		return array(
			'score'   => $score,
			'signals' => $signals,
		);
	}

	/**
	 * Detect mutual lineup mention.
	 *
	 * For each direction: does the other post's title or performer field
	 * appear as a whole-word substring inside this post's body text?
	 *
	 * Returns true only when BOTH directions hold — that is the "mutual"
	 * part. One-sided mentions are noisy (record-release shows often
	 * reference the headliner of another bill without sharing it).
	 */
	public function hasMutualLineupMention(
		string $title_a,
		string $performer_a,
		string $body_a,
		string $title_b,
		string $performer_b,
		string $body_b
	): bool {
		$a_in_b = $this->bodyMentionsArtist( $body_b, $title_a ) || $this->bodyMentionsArtist( $body_b, $performer_a );
		$b_in_a = $this->bodyMentionsArtist( $body_a, $title_b ) || $this->bodyMentionsArtist( $body_a, $performer_b );

		return $a_in_b && $b_in_a;
	}

	/**
	 * Check whether $body mentions $artist as a meaningful substring.
	 *
	 * Normalizes whitespace and case, and ignores artist strings that are
	 * too short to be discriminative (e.g. "Boy", "Jay"). Whole-word match
	 * with a 3-character minimum.
	 */
	private function bodyMentionsArtist( string $body, string $artist ): bool {
		$artist = trim( $artist );
		if ( '' === $artist || strlen( $artist ) < 3 ) {
			return false;
		}

		$body_norm   = $this->normalizeForMatch( $body );
		$artist_norm = $this->normalizeForMatch( $artist );

		if ( '' === $body_norm || '' === $artist_norm ) {
			return false;
		}

		$pattern = '/\b' . preg_quote( $artist_norm, '/' ) . '\b/u';
		return 1 === preg_match( $pattern, $body_norm );
	}

	private function normalizeForMatch( string $value ): string {
		$value = wp_strip_all_tags( $value );
		// Collapse whitespace.
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		return mb_strtolower( trim( $value ) );
	}

	private function pricesMatch( string $a, string $b ): bool {
		$a_n = $this->normalizePrice( $a );
		$b_n = $this->normalizePrice( $b );
		if ( '' === $a_n || '' === $b_n ) {
			return false;
		}
		return $a_n === $b_n;
	}

	private function normalizePrice( string $price ): string {
		$price = trim( mb_strtolower( $price ) );
		$price = preg_replace( '/\s+/u', '', $price ) ?? $price;
		// Drop currency symbols and dollar sign decoration; keep digits/dots/dashes.
		$price = preg_replace( '/[^\d\.\-]/u', '', $price ) ?? '';
		return $price;
	}

	private function sourceHostsMatch( string $url_a, string $url_b ): bool {
		$host_a = $this->extractHost( $url_a );
		$host_b = $this->extractHost( $url_b );
		if ( '' === $host_a || '' === $host_b ) {
			return false;
		}
		return $host_a === $host_b;
	}

	private function extractHost( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';
		return mb_strtolower( ltrim( $host, 'www.' ) );
	}

	/**
	 * Load the per-post detail needed for scoring (body, performer, price).
	 *
	 * Kept package-private (public for the chat tool to reuse) so the
	 * decide step can use the same extraction logic without duplicating it.
	 *
	 * @return array{body_text: string, performer: string, price: string}
	 */
	public function loadEventDetail( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'body_text' => '',
				'performer' => '',
				'price'     => '',
			);
		}

		$body_text = '';
		$performer = '';
		$price     = '';

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				$attrs     = $block['attrs'] ?? array();
				$performer = (string) ( $attrs['performer'] ?? '' );
				$price     = (string) ( $attrs['price'] ?? '' );

				if ( ! empty( $block['innerBlocks'] ) ) {
					$parts = array();
					foreach ( $block['innerBlocks'] as $inner ) {
						if ( ! empty( $inner['innerHTML'] ) ) {
							$parts[] = wp_strip_all_tags( $inner['innerHTML'] );
						}
					}
					$body_text = trim( implode( ' ', $parts ) );
				}
				break;
			}
		}

		// Fall back to full post_content text if we didn't find a paragraph body.
		if ( '' === $body_text ) {
			$body_text = wp_strip_all_tags( $post->post_content );
		}

		return array(
			'body_text' => $body_text,
			'performer' => $performer,
			'price'     => $price,
		);
	}

	/**
	 * Build a stable pair key from two post IDs (order-independent).
	 *
	 * Used both as the pending-action context key and as a dedupe key when
	 * checking whether a pair has already been decided.
	 */
	public function buildPairKey( int $id_a, int $id_b ): string {
		$lo = min( $id_a, $id_b );
		$hi = max( $id_a, $id_b );
		return 'mb_pair_' . $lo . '_' . $hi;
	}

	/**
	 * Has this pair already been resolved (or queued) in pending_actions?
	 *
	 * We treat any non-purged row for this pair_key as a reason to skip:
	 *   - pending: there's already a queued action; queueing again would dup.
	 *   - accepted/rejected/expired/deleted: we've already decided once, skip.
	 *
	 * Operators can clear a pair_key out of pending_actions to force a
	 * re-scan if needed.
	 */
	private function isAlreadyDecided( string $pair_key ): bool {
		// Look up by context match — list() supports filtering on context keys.
		$rows = PendingActionStore::list(
			array(
				'kind'    => self::PENDING_ACTION_KIND,
				'context' => array( 'pair_key' => $pair_key ),
				'limit'   => 5,
			)
		);

		return ! empty( $rows );
	}

	/**
	 * Persist a candidate pair to the pending_actions queue.
	 */
	private function queuePair( array $pair ): bool {
		$action_id = PendingActionStore::generate_id();

		$summary = sprintf(
			'Merged-bill candidate: posts %d + %d at venue term %d on %s (score %d).',
			$pair['post_a_id'],
			$pair['post_b_id'],
			$pair['venue_term_id'],
			$pair['start_datetime'],
			$pair['score']
		);

		$payload = array(
			'kind'         => self::PENDING_ACTION_KIND,
			'summary'      => $summary,
			'preview_data' => array(
				'post_a_id'      => $pair['post_a_id'],
				'post_a_title'   => $pair['post_a_title'],
				'post_b_id'      => $pair['post_b_id'],
				'post_b_title'   => $pair['post_b_title'],
				'venue_term_id'  => $pair['venue_term_id'],
				'start_datetime' => $pair['start_datetime'],
				'score'          => $pair['score'],
				'signals'        => $pair['signals'],
			),
			'apply_input'  => array(
				'pair_id'   => $action_id,
				'pair_key'  => $pair['pair_key'],
				'post_a_id' => $pair['post_a_id'],
				'post_b_id' => $pair['post_b_id'],
			),
			'context'      => array(
				'pair_key'   => $pair['pair_key'],
				'source'     => 'merged_bill_detector',
				'venue_term' => $pair['venue_term_id'],
			),
		);

		return PendingActionStore::store( $action_id, $payload );
	}
}
