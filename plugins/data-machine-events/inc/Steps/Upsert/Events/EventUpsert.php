<?php
// phpcs:disable PSR12.Files.FileHeader.IncorrectOrder,PSR12.Files.FileHeader.IncorrectGrouping,Universal.Operators.DisallowShortTernary.Found -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Event Upsert Handler
 *
 * Intelligently creates or updates event posts based on event identity.
 * Searches for existing events by (title, venue, startDate) and updates if found,
 * creates if new, or skips if data unchanged.
 *
 * Thin orchestrator: fetch normalized data → validate → dedup (via
 * EventDuplicateStrategy) → build content → assign taxonomy → write.
 * Validation, block-content assembly, and venue/promoter taxonomy assignment
 * are delegated to focused collaborators (see #425):
 *  - EventUpsertValidator       — pre-publish validation gate.
 *  - EventBlockContentBuilder   — event-details block / description assembly.
 *  - EventTaxonomyAssigner      — venue/promoter/location taxonomy assignment.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since   0.2.0
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachine\Core\PluginSettings;
use DataMachineEvents\Steps\EventImport\JunkPayloadFilter;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;
use function DataMachineEvents\Core\datamachine_extract_ticket_identity;
use DataMachine\Core\Similarity\SimilarityEngine;
use DataMachine\Core\Steps\Upsert\Handlers\UpsertHandler;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;
use DataMachine\Core\WordPress\WordPressPublishHelper;
use DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter;

defined( 'ABSPATH' ) || exit;

class EventUpsert extends UpsertHandler {

	protected $taxonomy_handler;

	/**
	 * Pre-publish validation gate (title, junk markers, dates, junk payload).
	 *
	 * @var EventUpsertValidator
	 */
	private EventUpsertValidator $validator;

	/**
	 * Event-details block / description content assembly.
	 *
	 * @var EventBlockContentBuilder
	 */
	private EventBlockContentBuilder $content_builder;

	/**
	 * Venue/promoter/location taxonomy assignment.
	 *
	 * @var EventTaxonomyAssigner
	 */
	private EventTaxonomyAssigner $taxonomy_assigner;

	public function __construct() {
		$this->taxonomy_handler = new TaxonomyHandler();

		$this->validator         = new EventUpsertValidator( new JunkPayloadFilter(), static::class );
		$this->content_builder   = new EventBlockContentBuilder();
		$this->taxonomy_assigner = new EventTaxonomyAssigner();

		// Register custom handler for venue taxonomy
		TaxonomyHandler::addCustomHandler( 'venue', array( $this->taxonomy_assigner, 'assignVenueTaxonomy' ) );
		// Register custom handler for promoter taxonomy
		TaxonomyHandler::addCustomHandler( 'promoter', array( $this->taxonomy_assigner, 'assignPromoterTaxonomy' ) );
	}

	/**
	 * Execute event upsert (create or update)
	 *
	 * @param array $parameters Event data from AI tool call
	 * @param array $handler_config Handler configuration
	 * @return array Tool call result with action: created|updated|no_change
	 */
	protected function executeUpsert( array $parameters, array $handler_config ): array {
		// Get engine data FIRST (before validation)
		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine_snapshot = $job_id ? $this->getEngineData( $job_id ) : array();
			$engine          = new EngineData( $engine_snapshot, $job_id );
		}

		// Extract event identity fields (AI title takes precedence, engine data fallback for other fields)
		$title     = sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' );
		$venue     = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';
		$startDate = $engine->get( 'startDate' ) ?? $parameters['startDate'] ?? '';
		$ticketUrl = $engine->get( 'ticketUrl' ) ?? $parameters['ticketUrl'] ?? '';

		// Run the consolidated pre-publish validation gate before any write.
		//
		// Every fetch handler (web scraper, Ticketmaster, Dice, …) funnels
		// through this single boundary, so a minimum quality bar is enforced
		// uniformly regardless of source. Rejected items are SKIP + LOG:
		// nothing is published, nothing is drafted, and each rejection is
		// logged so upstream parsers and the junk pattern table can be tuned.
		// The gate consolidates the prior scattered guards (empty startDate
		// #415, junk-marker title #349/#367, junk/test payload #416) instead
		// of duplicating them. See issue #417.
		$start_time  = trim( (string) ( $engine->get( 'startTime' ) ?? $parameters['startTime'] ?? '' ) );
		$source_type = (string) ( $engine->get( 'source_type' ) ?? $parameters['source_type'] ?? '' );
		$artist      = (string) ( $engine->get( 'performer' ) ?? $parameters['performer'] ?? $parameters['artist'] ?? '' );

		$rejection = $this->validateForPublish(
			array(
				'title'       => $title,
				'venue'       => $venue,
				'startDate'   => $startDate,
				'startTime'   => $start_time,
				'source_type' => $source_type,
				'artist'      => $artist,
			),
			$parameters,
			$engine
		);

		if ( null !== $rejection ) {
			return $rejection;
		}

		do_action(
			'datamachine_log',
			'debug',
			'Event Upsert: Processing event',
			array(
				'title'     => $title,
				'venue'     => $venue,
				'startDate' => $startDate,
				'ticketUrl' => $ticketUrl,
			)
		);

		// Acquire advisory lock to prevent race conditions between concurrent
		// flows importing the same event. Lock key is derived from the date and
		// normalized title so that two flows processing "Eggy" at Charleston Pour
		// House on 2026-03-20 will serialize instead of both creating a new post.
		$lock_key = $this->acquireUpsertLock( $title, $startDate );

		try {
			return $this->executeUpsertWithinLock( $title, $venue, $startDate, $ticketUrl, $parameters, $handler_config, $engine );
		} finally {
			$this->releaseUpsertLock( $lock_key );
		}
	}

	/**
	 * Pre-publish validation gate. Delegates to EventUpsertValidator.
	 *
	 * Kept as a thin delegator so the upsert path and existing reflection-based
	 * tests resolve against EventUpsert; the gate logic lives in
	 * EventUpsertValidator (extracted in #425).
	 *
	 * @param array      $evidence Pre-extracted event fields.
	 * @param array      $parameters Full tool parameters.
	 * @param EngineData $engine Engine data.
	 * @return array|null Null on pass; rejection array on fail.
	 */
	private function validateForPublish( array $evidence, array $parameters, EngineData $engine ): ?array {
		return $this->validator->validateForPublish( $evidence, $parameters, $engine );
	}

	/**
	 * Junk-marker title check. Delegates to EventUpsertValidator.
	 *
	 * @param string $title Event title.
	 * @return bool True if the title is a junk-marker leak.
	 */
	private function isJunkTitle( string $title ): bool {
		return $this->validator->isJunkTitle( $title );
	}

	/**
	 * Datetime confidence. Delegates to EventUpsertValidator.
	 *
	 * @param array $parameters Event parameters.
	 * @param EngineData $engine Engine data helper.
	 * @return string One of full|date_only|none.
	 */
	private function getDateTimeConfidence( array $parameters, EngineData $engine ): string {
		return $this->validator->getDateTimeConfidence( $parameters, $engine );
	}

	/**
	 * Execute the find-or-create logic within an advisory lock.
	 *
	 * Separated from executeUpsert() so the lock boundary is clear:
	 * the lock is held from before findExistingEventViaAbility() through the
	 * completion of the DM core upsert and post-processing.
	 *
	 * Domain-specific identity resolution (fuzzy title/venue/date matching,
	 * ticket URL canonical matching) is kept here. The actual create/update/no_change
	 * decision is delegated to datamachine/upsert-post, which handles content hash
	 * comparison and provenance stamping.
	 *
	 * @param string     $title         Event title.
	 * @param string     $venue         Venue name.
	 * @param string     $startDate     Start date.
	 * @param string     $ticketUrl     Ticket URL.
	 * @param array      $parameters    Full tool parameters.
	 * @param array      $handler_config Handler configuration.
	 * @param EngineData $engine        Engine data.
	 * @return array Tool call result.
	 */
	private function executeUpsertWithinLock(
		string $title,
		string $venue,
		string $startDate,
		string $ticketUrl,
		array $parameters,
		array $handler_config,
		EngineData $engine
	): array {
		// 1. Find existing event via domain-specific duplicate detection.
		// Pass venue address + city so dedup can resolve the incoming venue
		// via the same address-first cascade the upsert path uses
		// (Venue_Taxonomy::find_or_create_venue). Without this, dupes slip
		// through whenever the incoming venue string differs from the
		// canonical taxonomy term name. See issue #252.
		// findExistingEventViaAbility() returns ?int — null means no existing post.
		// Normalize to int (0 = no match) so downstream type contracts hold.
		$venueAddress     = (string) ( $engine->get( 'venueAddress' ) ?? $parameters['venueAddress'] ?? '' );
		$venueCity        = (string) ( $engine->get( 'venueCity' ) ?? $parameters['venueCity'] ?? '' );
		$existing_post_id = (int) $this->findExistingEventViaAbility( $title, $venue, $startDate, $ticketUrl, $venueAddress, $venueCity );

		// 2. Build event data.
		$event_data = $this->buildEventData( $parameters, $handler_config, $engine, $existing_post_id );

		// 3. Generate block content and compute hash for idempotency.
		$block_content = $this->content_builder->generate_event_block_content( $event_data, $parameters );
		$content_hash  = md5( $block_content );

		// 4. Resolve post author and status.
		$post_author = $this->resolvePostAuthor( $handler_config, $engine );
		$post_status = WordPressSettingsResolver::getPostStatus( $handler_config );

		// 5. Build meta input.
		$meta_input = $this->buildEventMetaInput( $event_data, $parameters, $engine );

		// 6. Delegate to DM core upsert ability.
		$upsert_input = array(
			'post_type'    => Event_Post_Type::POST_TYPE,
			'title'        => $event_data['title'],
			'content'      => $block_content,
			'content_hash' => $content_hash,
			'post_status'  => $post_status,
			'meta_input'   => $meta_input,
		);

		if ( $existing_post_id > 0 ) {
			$upsert_input['post_id'] = $existing_post_id;
		} else {
			$upsert_input['post_author'] = $post_author;
		}

		$ability = wp_get_ability( 'datamachine/upsert-post' );
		if ( ! $ability ) {
			return $this->errorResponse( 'datamachine/upsert-post ability not available' );
		}

		$result = $ability->execute( $upsert_input );

		if ( empty( $result['success'] ) ) {
			return $this->errorResponse(
				$result['error'] ?? 'Event upsert failed',
				array( 'title' => $title )
			);
		}

		$post_id = (int) $result['post_id'];
		$action  = $result['action'];

		// 7. no_change: nothing else to do.
		if ( 'no_change' === $action ) {
			return $this->successResponse(
				array(
					'post_id'  => $post_id,
					'post_url' => get_permalink( $post_id ),
					'action'   => 'no_change',
				)
			);
		}

		// 8. Post-upsert event-specific processing (create and update paths).
		$this->processEventFeaturedImage( $post_id, $handler_config, $engine );
		$this->taxonomy_assigner->processVenue( $post_id, $parameters, $engine, $handler_config );
		$this->taxonomy_assigner->processPromoter( $post_id, $parameters, $engine, $handler_config );

		// Derive location from the event's actual venue city rather than the
		// pipeline's ingest center. processLocation returns true when it took
		// ownership of the location term (PRE_SELECTED mode), in which case the
		// generic taxonomy pass below must skip location so it doesn't
		// re-stamp the pipeline-center term. See data-machine-events#379.
		$location_handled = $this->taxonomy_assigner->processLocation( $post_id, $parameters, $engine, $handler_config );

		// Map performer to artist taxonomy if not explicitly provided.
		if ( empty( $parameters['artist'] ) && ! empty( $event_data['performer'] ) ) {
			$parameters['artist'] = $event_data['performer'];
		}

		$handler_config_for_tax                                = $handler_config;
		$handler_config_for_tax['taxonomy_venue_selection']    = 'skip';
		$handler_config_for_tax['taxonomy_promoter_selection'] = 'skip';
		if ( $location_handled ) {
			$handler_config_for_tax['taxonomy_location_selection'] = 'skip';
		}
		$engine_data_array = $engine instanceof EngineData ? $engine->all() : array();
		$this->taxonomy_handler->processTaxonomies( $post_id, $parameters, $handler_config_for_tax, $engine_data_array );

		do_action( 'datamachine_event_taxonomy_processed', $post_id );

		// 9. Sync identity index (title or ticket URL may have changed).
		EventIdentityWriter::syncIdentityRow( $post_id, $title, datamachine_normalize_ticket_url( $ticketUrl ) ?: null );

		// 10. Sync engine data for pipeline continuation (create path only).
		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( 'created' === $action && $job_id ) {
			datamachine_merge_engine_data(
				$job_id,
				array(
					'event_id'  => $post_id,
					'event_url' => get_permalink( $post_id ),
				)
			);
		}

		// 11. Log and return.
		$log_level = 'created' === $action ? 'info' : 'info';
		$log_msg   = 'created' === $action ? 'Created new event' : 'Updated existing event';
		do_action(
			'datamachine_log',
			$log_level,
			"Event Upsert: {$log_msg}",
			array(
				'post_id' => $post_id,
				'title'   => $title,
			)
		);

		return $this->successResponse(
			array(
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
				'action'   => $action,
			)
		);
	}

	/**
	 * Find existing event using DM core's duplicate detection system.
	 *
	 * Calls the `datamachine/check-duplicate` ability which delegates to
	 * the registered EventDuplicateStrategy, querying the PostIdentityIndex
	 * with indexed lookups instead of postmeta LIKE scans.
	 *
	 * The indexed strategy is date-aware: every query scopes by date_only,
	 * so recurring series (same title/venue, different date) are never
	 * falsely matched. See #423.
	 *
	 * The `address` and `city` context fields let EventDuplicateStrategy
	 * resolve the incoming venue via the same address-first cascade the
	 * upsert path uses (Venue_Taxonomy::find_or_create_venue). See #252.
	 *
	 * @param string $title     Event title.
	 * @param string $venue     Venue name.
	 * @param string $startDate Start date.
	 * @param string $ticketUrl Ticket URL.
	 * @param string $address   Venue street address (for address-aware venue resolution).
	 * @param string $city      Venue city (required alongside address).
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function findExistingEventViaAbility( string $title, string $venue, string $startDate, string $ticketUrl, string $address = '', string $city = '' ): ?int {
		// The indexed EventDuplicateStrategy owns all event duplicate
		// detection. If the index class or ability is unavailable (DM core
		// too old), there is no safe fallback — return null so a new event
		// is created rather than silently skipping dedup. In practice core
		// is network-pinned so this branch is unreachable.
		if ( ! class_exists( 'DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex' ) ) {
			do_action( 'datamachine_log', 'warning', 'Event Upsert: PostIdentityIndex unavailable — skipping dedup, creating new event', array( 'title' => $title ) );
			return null;
		}

		$duplicate_check = wp_get_ability( 'datamachine/check-duplicate' );

		if ( ! $duplicate_check ) {
			do_action( 'datamachine_log', 'warning', 'Event Upsert: datamachine/check-duplicate ability unavailable — skipping dedup, creating new event', array( 'title' => $title ) );
			return null;
		}

		$result = $duplicate_check->execute(
			array(
				'title'     => $title,
				'post_type' => Event_Post_Type::POST_TYPE,
				'scope'     => 'published',
				'context'   => array(
					'venue'     => $venue,
					'startDate' => $startDate,
					'ticketUrl' => $ticketUrl,
					'address'   => $address,
					'city'      => $city,
				),
			)
		);

		if ( ! ( is_array( $result ) && 'duplicate' === ( $result['verdict'] ?? '' ) ) ) {
			return null;
		}

		$post_id = (int) ( $result['match']['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return null;
		}

		// Defensive date guard: EventDuplicateStrategy is date-aware (queries
		// by date_only), so its own matches are always date-correct. However
		// DM core may run additional strategies that match on title alone.
		// If such a strategy returned this match and the existing event is
		// on a genuinely different calendar date, treat it as a distinct
		// recurring-series event rather than a duplicate.
		// See: https://github.com/Extra-Chill/data-machine/issues/1108
		if ( ! empty( $startDate ) ) {
			$existing_data      = $this->extractEventData( $post_id );
			$existing_startDate = $existing_data['startDate'] ?? '';

			if ( ! empty( $existing_startDate ) ) {
				$existing_date_only = self::extractDateForQuery( $existing_startDate );
				$incoming_date_only = self::extractDateForQuery( $startDate );

				if ( $existing_date_only !== $incoming_date_only ) {
					do_action(
						'datamachine_log',
						'info',
						'Event Upsert: Ability matched title but date differs — treating as new event (recurring series)',
						array(
							'title'            => $title,
							'venue'            => $venue,
							'incoming_date'    => $incoming_date_only,
							'existing_date'    => $existing_date_only,
							'existing_post_id' => $post_id,
						)
					);

					return null;
				}
			}
		}

		return $post_id;
	}

	/**
	 * Acquire a MySQL advisory lock for an event identity.
	 *
	 * Uses GET_LOCK() to serialize concurrent upserts for the same event.
	 * The lock key is derived from the date and normalized title, so two
	 * flows importing the same event will queue instead of racing.
	 *
	 * MySQL advisory locks are:
	 * - Per-connection (each PHP-FPM worker has its own connection)
	 * - Automatically released on connection close (no leak risk)
	 * - Non-blocking for unrelated events (different lock keys)
	 *
	 * @since 0.17.3
	 * @param string $title     Event title.
	 * @param string $startDate Event start date.
	 * @return string The lock key (needed for release).
	 */
	private function acquireUpsertLock( string $title, string $startDate ): string {
		global $wpdb;

		$date_only  = self::extractDateForQuery( $startDate );
		$normalized = SimilarityEngine::normalizeTitle( $title );
		$lock_key   = 'dme_' . md5( $date_only . '|' . $normalized );

		// MySQL lock names are limited to 64 characters; md5 = 36 + prefix = 40, safe.
		// Try up to 3 times with increasing timeouts (5s, 10s, 15s).
		// If all attempts fail, proceed without lock — better to risk a dupe
		// than deadlock the pipeline entirely.
		$timeouts = array( 5, 10, 15 );
		$acquired = false;

		foreach ( $timeouts as $attempt => $timeout ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, $timeout ) );

			if ( '1' === (string) $result ) {
				$acquired = true;
				break;
			}
		}

		if ( ! $acquired ) {
			do_action(
				'datamachine_log',
				'warning',
				'Event Upsert: Advisory lock failed after 3 attempts, proceeding without lock',
				array(
					'lock_key'        => $lock_key,
					'title'           => $title,
					'startDate'       => $startDate,
					'normalized'      => $normalized,
					'total_wait_secs' => array_sum( $timeouts ),
				)
			);
		}

		return $lock_key;
	}

	/**
	 * Release a MySQL advisory lock.
	 *
	 * @since 0.17.3
	 * @param string $lock_key The lock key to release.
	 */
	private function releaseUpsertLock( string $lock_key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
	}

	/**
	 * Extract just the date portion (YYYY-MM-DD) from a datetime string.
	 *
	 * Used for date-scoped queries and advisory-lock key derivation.
	 *
	 * @param string $datetime Datetime or date string.
	 * @return string Date portion only (YYYY-MM-DD).
	 */
	private static function extractDateForQuery( string $datetime ): string {
		// Handle both "2026-03-20" and "2026-03-20 21:00:00" and "2026-03-20T21:00:00"
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $datetime, $matches ) ) {
			return $matches[1];
		}
		return $datetime;
	}

	/**
	 * Extract event data from existing post
	 *
	 * @param int $post_id Post ID
	 * @return array Event attributes from event-details block
	 */
	private function extractEventData( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Resolve post author for event creation.
	 *
	 * When an event is submitted by a user (logged-in or anonymous-with-a-
	 * resolved-account — see extrachill-events Phase 1), their user_id is stored
	 * in initial_data['submission']['user_id'] and takes priority over handler
	 * config defaults so submitted events are attributed to the submitter.
	 *
	 * For genuine automation (no submission context), the author resolves to:
	 *   1. An explicitly-configured author (system-wide default_author_id, then
	 *      per-handler post_author) — respected when set.
	 *   2. The network bot account via `ec_get_network_bot_user_id()` — the
	 *      honest default for headless automation, config-driven so the bot id
	 *      is not a magic literal (issue #207 Phase 2). This intentionally
	 *      supersedes WordPressSettingsResolver's first-administrator fallback,
	 *      which historically misattributed automation to uid 1 (the ~3k uid-1
	 *      event rows that the backfill in extrachill-events corrects).
	 *   3. WordPressSettingsResolver (logged-in user / first admin) — last
	 *      resort, only when the bot-account helper is unavailable.
	 *
	 * @param array      $handler_config Handler configuration.
	 * @param EngineData $engine         Engine snapshot helper.
	 * @return int Post author ID.
	 */
	private function resolvePostAuthor( array $handler_config, EngineData $engine ): int {
		// 1. Submission user_id (human submitter) — highest priority.
		$submission = $engine->get( 'submission' );

		if ( is_array( $submission ) && ! empty( $submission['user_id'] ) ) {
			$submitter_id = (int) $submission['user_id'];
			if ( $submitter_id > 0 && get_userdata( $submitter_id ) ) {
				return $submitter_id;
			}
		}

		// 2. Explicit author configuration (system default, then handler
		//    override). Per-flow post_author wins over the bot default so an
		//    operator can still pin a specific author on a flow.
		$explicit = $this->resolve_explicit_author_id( $handler_config );
		if ( $explicit > 0 ) {
			return $explicit;
		}

		// 3. Network bot account — the honest author for headless automation.
		if ( function_exists( 'ec_get_network_bot_user_id' ) ) {
			$bot_id = (int) ec_get_network_bot_user_id();
			if ( $bot_id > 0 ) {
				return $bot_id;
			}
		}

		// 4. Last resort: generic resolver (logged-in user / first admin).
		return WordPressSettingsResolver::getPostAuthor( $handler_config );
	}

	/**
	 * Resolve an explicitly-configured author id, if any.
	 *
	 * Mirrors the config-first portion of WordPressSettingsResolver::getPostAuthor():
	 * system-wide default_author_id, then handler-specific post_author. Returns 0
	 * when neither is set (the headless-automation case the bot-account default
	 * handles). Extracted so resolvePostAuthor() can branch on "no explicit
	 * config → bot" without re-running the resolver's first-admin fallback.
	 *
	 * @param array $handler_config Handler configuration.
	 * @return int Explicitly-configured author id, or 0 if none.
	 */
	private function resolve_explicit_author_id( array $handler_config ): int {
		$wp_settings = PluginSettings::get( 'wordpress_settings', array() );
		$default     = (int) ( $wp_settings['default_author_id'] ?? 0 );
		if ( $default > 0 ) {
			return $default;
		}

		return (int) ( $handler_config['post_author'] ?? 0 );
	}

	/**
	 * Build meta_input array for the DM core upsert ability.
	 *
	 * Currently handles submission metadata only. Event-specific meta
	 * (datetimes, ticket URL) is managed by event-dates-sync hooks.
	 *
	 * @param array      $event_data Event data.
	 * @param array      $parameters Tool parameters.
	 * @param EngineData $engine     Engine data.
	 * @return array Meta key/value pairs.
	 */
	private function buildEventMetaInput( array $event_data, array $parameters, EngineData $engine ): array {
		$meta_input = array();

		$submission = $engine->get( 'submission' );
		if ( is_array( $submission ) ) {
			if ( ! empty( $submission['user_id'] ) ) {
				$meta_input['_datamachine_submitted_by'] = (int) $submission['user_id'];
			}
			if ( ! empty( $submission['contact_name'] ) ) {
				$meta_input['_datamachine_submitter_name'] = sanitize_text_field( $submission['contact_name'] );
			}
			if ( ! empty( $submission['contact_email'] ) ) {
				$meta_input['_datamachine_submitter_email'] = sanitize_email( $submission['contact_email'] );
			}
		}

		return $meta_input;
	}

	/**
	 * Build event data by merging engine data with AI parameters.
	 *
	 * Engine data takes precedence since AI only received parameters
	 * for fields not already in engine data (filtered at definition time).
	 *
	 * @param array $parameters AI-provided parameters
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine data helper
	 * @return array Merged event data
	 */
	private function buildEventData( array $parameters, array $handler_config, EngineData $engine, int $existing_post_id = 0 ): array {
		$event_data = array(
			'title'       => sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' ),
			'description' => $parameters['description'] ?? '',
		);

		// Engine data takes precedence - use schema providers as single source of truth
		$schema_fields     = EventSchemaProvider::getFieldKeys();
		$venue_fields      = VenueParameterProvider::getParameterKeys();
		$all_engine_fields = array_unique( array_merge( $schema_fields, $venue_fields ) );

		foreach ( $all_engine_fields as $field ) {
			$value = $engine->get( $field );
			if ( null !== $value && '' !== $value ) {
				$event_data[ $field ] = $value;
			}
		}

		// AI parameters fill in remaining fields
		foreach ( $schema_fields as $field ) {
			if ( ! isset( $event_data[ $field ] ) && ! empty( $parameters[ $field ] ) ) {
				if ( 'ticketUrl' === $field ) {
					$event_data[ $field ] = trim( $parameters[ $field ] );
				} else {
					$event_data[ $field ] = sanitize_text_field( $parameters[ $field ] );
				}
			}
		}

		// Handler config venue override (highest priority)
		if ( ! empty( $handler_config['venue'] ) ) {
			$event_data['venue'] = $handler_config['venue'];
		}

		// Persist datetime values from meta as system-level fallbacks
		$resolved_post_id = $existing_post_id > 0 ? $existing_post_id : ( $engine->get( 'post_id' ) ?? $parameters['post_id'] ?? 0 );
		if ( ! empty( $resolved_post_id ) ) {
			$this->hydrateStartDateFromMeta( (int) $resolved_post_id, $event_data );
			$this->hydrateEndDateFromMeta( (int) $resolved_post_id, $event_data );
		}

		return $event_data;
	}

	private function hydrateStartDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['startDate'] ) && array_key_exists( 'startTime', $event_data ) ) {
			return;
		}

		$dates          = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$start_datetime = $dates ? $dates->start_datetime : '';
		if ( empty( $start_datetime ) ) {
			return;
		}

		$date_obj = date_create( $start_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['startDate'] ) ) {
			$event_data['startDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( ! array_key_exists( 'startTime', $event_data ) ) {
			$time = $date_obj->format( 'H:i:s' );
			if ( '00:00:00' !== $time ) {
				$event_data['startTime'] = $time;
			}
		}
	}

	private function hydrateEndDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['endDate'] ) && array_key_exists( 'endTime', $event_data ) ) {
			return;
		}

		$dates        = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$end_datetime = $dates ? $dates->end_datetime : '';
		if ( empty( $end_datetime ) ) {
			return;
		}

		$date_obj = date_create( $end_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['endDate'] ) ) {
			$event_data['endDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( ! array_key_exists( 'endTime', $event_data ) ) {
			$time = $date_obj->format( 'H:i:s' );
			if ( '00:00:00' !== $time && ( $event_data['startTime'] ?? '' ) !== $time ) {
				$event_data['endTime'] = $time;
			}
		}
	}

	/**
	 * Process featured image with EngineData context and handler fallbacks.
	 */
	private function processEventFeaturedImage( int $post_id, array $handler_config, EngineData $engine ): void {
		if ( empty( $handler_config['include_images'] ) ) {
			return;
		}

		$image_path = $engine->getImagePath();

		if ( ! empty( $image_path ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $image_path, $handler_config );
		} elseif ( ! empty( $handler_config['eventImage'] ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $handler_config['eventImage'], $handler_config );
		}
	}

	/**
	 * Success response wrapper
	 */
	protected function successResponse( array $data ): array {
		return array(
			'success'   => true,
			'data'      => $data,
			'tool_name' => 'data_machine_events',
		);
	}
}
