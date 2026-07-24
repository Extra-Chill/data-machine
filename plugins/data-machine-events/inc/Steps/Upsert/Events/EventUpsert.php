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

use DataMachine\Core\AbilityResult;
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

	public const SOURCE_IDENTITY_META_KEY = '_datamachine_event_source_identity';
	public const SOURCE_NAME_META_KEY     = '_datamachine_event_source';
	public const SOURCE_ID_META_KEY       = '_datamachine_event_source_id';

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
	 * Run the canonical event upsert without workflow/job context.
	 *
	 * This is the supported entry point for the public event upsert ability.
	 * Workflow handlers continue to use handle_tool_call() and executeUpsert().
	 *
	 * @param array $event          Canonical event fields.
	 * @param array $handler_config Post and taxonomy settings.
	 * @return array Canonical handler result.
	 */
	public function upsertCanonicalEvent( array $event, array $handler_config = array() ): array {
		$event['engine'] = new EngineData( $event, 0 );
		$event['job_id'] = 0;

		return $this->executeUpsert( $event, $handler_config );
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

		$identity_start = self::composeIdentityStart( $startDate, $start_time );
		$lock_keys      = $this->acquireUpsertLocks(
			$title,
			$venue,
			$identity_start,
			(string) ( $parameters['source_identity'] ?? '' ),
			array(
				'address' => (string) ( $engine->get( 'venueAddress' ) ?? $parameters['venueAddress'] ?? '' ),
				'city'    => (string) ( $engine->get( 'venueCity' ) ?? $parameters['venueCity'] ?? '' ),
				'state'   => (string) ( $engine->get( 'venueState' ) ?? $parameters['venueState'] ?? '' ),
				'country' => (string) ( $engine->get( 'venueCountry' ) ?? $parameters['venueCountry'] ?? '' ),
			)
		);
		if ( null === $lock_keys ) {
			return array(
				'success'    => false,
				'error'      => 'Event upsert lock unavailable; retry the event.',
				'error_code' => 'event_upsert_lock_unavailable',
				'retryable'  => true,
				'tool_name'  => static::class,
			);
		}

		try {
			return $this->executeUpsertWithinLock( $title, $venue, $identity_start, $ticketUrl, $parameters, $handler_config, $engine );
		} finally {
			$this->releaseUpsertLocks( $lock_keys );
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
		// Pass complete venue geography so dedup can resolve the incoming venue
		// via the same address-first cascade the upsert path uses
		// (Venue_Taxonomy::find_or_create_venue). Without this, dupes slip
		// through whenever the incoming venue string differs from the
		// canonical taxonomy term name. See issue #252.
		// findExistingEventViaAbility() returns ?int — null means no existing post.
		// Normalize to int (0 = no match) so downstream type contracts hold.
		$venueAddress     = (string) ( $engine->get( 'venueAddress' ) ?? $parameters['venueAddress'] ?? '' );
		$venueCity        = (string) ( $engine->get( 'venueCity' ) ?? $parameters['venueCity'] ?? '' );
		$venueState       = (string) ( $engine->get( 'venueState' ) ?? $parameters['venueState'] ?? '' );
		$venueCountry     = (string) ( $engine->get( 'venueCountry' ) ?? $parameters['venueCountry'] ?? '' );
		$source_identity  = (string) ( $parameters['source_identity'] ?? '' );
		$existing_post_id = $this->findExistingEventBySourceIdentity( $source_identity );
		if ( $existing_post_id <= 0 ) {
			$existing_post_id = (int) $this->findExistingEventViaAbility(
				$title,
				$venue,
				$startDate,
				$ticketUrl,
				$venueAddress,
				$venueCity,
				$venueState,
				$venueCountry
			);
		}

		// 2. Build event data.
		$event_data             = $this->buildEventData( $parameters, $handler_config, $engine, $existing_post_id );
		$venue_resolution       = $this->taxonomy_assigner->resolveVenue( $parameters, $engine, $handler_config );
		$authoritative_venue_id = (int) $venue_resolution['term_id'];
		if ( 'skip' === $venue_resolution['action'] && $existing_post_id > 0 ) {
			$existing_venues = wp_get_object_terms( $existing_post_id, 'venue', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $existing_venues ) ) {
				return $this->lifecycleErrorResponse(
					new \WP_Error(
						'event_retained_venue_read_failed',
						'The existing event venue could not be read safely.',
						array(
							'status'    => 503,
							'retryable' => true,
						)
					),
					$title
				);
			}
			$existing_venues = array_values( array_unique( array_filter( array_map( 'absint', $existing_venues ) ) ) );
			if ( count( $existing_venues ) > 1 ) {
				return $this->lifecycleErrorResponse(
					new \WP_Error(
						'event_retained_venue_ambiguous',
						'The existing event has multiple venue assignments and cannot be updated safely.',
						array(
							'status'    => 409,
							'venue_ids' => $existing_venues,
						)
					),
					$title
				);
			}
			if ( 1 === count( $existing_venues ) ) {
				$authoritative_venue_id = (int) reset( $existing_venues );
			}
		}

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

		if ( '' !== $source_identity ) {
			$upsert_input['identity_meta'] = array(
				'key'   => self::SOURCE_IDENTITY_META_KEY,
				'value' => $source_identity,
			);
		}

		if ( $existing_post_id > 0 ) {
			$upsert_input['post_id'] = $existing_post_id;
		} else {
			$upsert_input['post_author'] = $post_author;
		}

		$ability = wp_get_ability( 'datamachine/upsert-post' );
		if ( ! $ability ) {
			return $this->errorResponse( 'datamachine/upsert-post ability not available' );
		}

		$context          = array(
			'invocation_id'    => wp_generate_uuid4(),
			'venue_term_id'    => $authoritative_venue_id,
			'event'            => $event_data,
			'post_status'      => $post_status,
			'existing_post_id' => $existing_post_id,
			'source'           => (string) ( $parameters['source'] ?? '' ),
			'source_id'        => (string) ( $parameters['source_id'] ?? '' ),
			'source_identity'  => $source_identity,
		);
		$post_id          = 0;
		$lifecycle_result = null;
		$warnings         = array();

		try {
			/**
			 * Filters whether an event may cross the canonical persistence boundary.
			 *
			 * @param bool|\WP_Error $preflight True to continue, or an error to abort.
			 * @param array          $context   Normalized event persistence context.
			 */
			$preflight = apply_filters( 'datamachine_events_before_event_upsert_persistence', true, $context );
			if ( false === $preflight ) {
				$preflight = new \WP_Error(
					'event_upsert_persistence_denied',
					'Event persistence was denied.',
					array( 'status' => 403 )
				);
			}
			if ( is_wp_error( $preflight ) ) {
				$lifecycle_result = $preflight;
				return $this->lifecycleErrorResponse( $preflight, $title );
			}

			$result = AbilityResult::normalize( $ability->execute( $upsert_input ) );

			if ( empty( $result['success'] ) ) {
				$error_data = $result['error_data'] ?? $result['wp_error_data'] ?? array();
				$error_data = is_array( $error_data ) ? $error_data : array();
				foreach ( array( 'status', 'retryable', 'transient', 'rule', 'cause' ) as $field ) {
					if ( array_key_exists( $field, $result ) ) {
						$error_data[ $field ] = $result[ $field ];
					}
				}
				$error_data['status'] = (int) ( $error_data['status'] ?? ( ! empty( $error_data['retryable'] ) || ! empty( $error_data['transient'] ) ? 503 : 400 ) );
				$lifecycle_result     = new \WP_Error(
					(string) ( $result['error_code'] ?? $result['wp_error_code'] ?? 'event_upsert_persistence_failed' ),
					(string) ( $result['error'] ?? 'Event upsert failed' ),
					$error_data
				);
				return $this->lifecycleErrorResponse( $lifecycle_result, $title );
			}

			$post_id = (int) $result['post_id'];
			$action  = $result['action'];
			if ( '' !== $source_identity ) {
				update_post_meta( $post_id, self::SOURCE_IDENTITY_META_KEY, $source_identity );
				update_post_meta( $post_id, self::SOURCE_NAME_META_KEY, sanitize_text_field( (string) ( $parameters['source'] ?? '' ) ) );
				update_post_meta( $post_id, self::SOURCE_ID_META_KEY, sanitize_text_field( (string) ( $parameters['source_id'] ?? '' ) ) );
			}

			// 7. Content idempotency does not imply taxonomy or identity integrity.
			// Skip image work for unchanged content, but always reconcile indexed state.
			if ( 'no_change' !== $action ) {
				$this->processEventFeaturedImage( $post_id, $handler_config, $engine );
			}
			$venue_assignment = $this->taxonomy_assigner->processVenue( $post_id, $parameters, $engine, $handler_config, $venue_resolution );
			if ( empty( $venue_assignment['success'] ) ) {
				$warnings[] = (string) ( $venue_assignment['error'] ?? 'Venue assignment failed.' );
				do_action(
					'datamachine_log',
					'warning',
					'Event upsert completed without venue assignment',
					array(
						'post_id' => $post_id,
						'warning' => end( $warnings ),
					)
				);
			}
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

			// 8. Sync identity index after taxonomy reconciliation.
			EventIdentityWriter::syncIdentityRow( $post_id, $title, datamachine_normalize_ticket_url( $ticketUrl ) ?: null );

			if ( 'no_change' === $action ) {
				$lifecycle_result             = $this->successResponse(
					array(
						'post_id'  => $post_id,
						'post_url' => get_permalink( $post_id ),
						'action'   => 'no_change',
					)
				);
				$lifecycle_result['warnings'] = $warnings;
				return $lifecycle_result;
			}

			// 9. Sync engine data for pipeline continuation (create path only).
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

			// 10. Log and return.
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

			$lifecycle_result             = $this->successResponse(
				array(
					'post_id'  => $post_id,
					'post_url' => get_permalink( $post_id ),
					'action'   => $action,
				)
			);
			$lifecycle_result['warnings'] = $warnings;
			return $lifecycle_result;
		} catch ( \Throwable $throwable ) {
			$lifecycle_result = new \WP_Error( 'event_upsert_persistence_exception', 'Event persistence failed unexpectedly.', array( 'exception' => get_class( $throwable ) ) );
			throw $throwable;
		} finally {
			/**
			 * Fires after the canonical event persistence lifecycle completes.
			 *
			 * @param array                $context Event persistence context.
			 * @param int                  $post_id Persisted post ID, or zero before persistence.
			 * @param array|\WP_Error|null $result  Success response, failure, or null on an unexpected abort.
			 */
			do_action( 'datamachine_events_after_event_upsert_persistence', $context, $post_id, $lifecycle_result );
		}
	}

	/** Preserve a lifecycle WP_Error while retaining the handler array contract. */
	private function lifecycleErrorResponse( \WP_Error $error, string $title ): array {
		$response               = $this->errorResponse( $error->get_error_message(), array( 'title' => $title ) );
		$data                   = $error->get_error_data();
		$data                   = is_array( $data ) ? $data : array();
		$response['error_code'] = $error->get_error_code();
		$response['error_data'] = $data;
		$response['status']     = (int) ( $data['status'] ?? 400 );
		$response['retryable']  = ! empty( $data['retryable'] ) || ! empty( $data['transient'] ) || $response['status'] >= 500;
		$response['transient']  = ! empty( $data['transient'] ) || $response['retryable'];
		$response['rule']       = $data['rule'] ?? null;
		return $response;
	}

	/**
	 * Resolve a caller-supplied source identity before domain deduplication.
	 *
	 * @param string $source_identity Stable hashed source identity.
	 * @return int Matching event ID, or 0 when no event is associated.
	 */
	private function findExistingEventBySourceIdentity( string $source_identity ): int {
		if ( '' === $source_identity ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::SOURCE_IDENTITY_META_KEY,
						'value' => $source_identity,
					),
				),
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
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
	 * The geographic context fields let EventDuplicateStrategy
	 * resolve the incoming venue via the same address-first cascade the
	 * upsert path uses (Venue_Taxonomy::find_or_create_venue). See #252.
	 *
	 * @param string $title     Event title.
	 * @param string $venue     Venue name.
	 * @param string $startDate Start date.
	 * @param string $ticketUrl Ticket URL.
	 * @param string $address   Venue street address (for address-aware venue resolution).
	 * @param string $city      Venue city (required alongside address).
	 * @param string $state     Venue state or region.
	 * @param string $country   Venue country.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function findExistingEventViaAbility(
		string $title,
		string $venue,
		string $startDate,
		string $ticketUrl,
		string $address = '',
		string $city = '',
		string $state = '',
		string $country = ''
	): ?int {
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
					'state'     => $state,
					'country'   => $country,
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
	 * Acquire deterministic MySQL advisory locks for an event identity.
	 *
	 * A concrete source lock serializes updates even when source data changes its
	 * title, date, or venue. Canonical venue/time locks serialize different feeds
	 * whose fuzzy titles can resolve to the same event. Keys are globally sorted
	 * before acquisition so overlapping key sets cannot deadlock each other.
	 *
	 * MySQL advisory locks are:
	 * - Per-connection (each PHP-FPM worker has its own connection)
	 * - Automatically released on connection close (no leak risk)
	 * - Non-blocking for unrelated events (different lock keys)
	 *
	 * @since 0.17.3
	 * @param string $title           Event title.
	 * @param string $venue           Event venue.
	 * @param string $startDate       Event start date or datetime.
	 * @param string $source_identity Optional stable source identity.
	 * @param array  $venue_context   Venue address geography.
	 * @return array|null The acquired lock keys, or null on timeout/error.
	 */
	private function acquireUpsertLocks( string $title, string $venue, string $startDate, string $source_identity = '', array $venue_context = array() ): ?array {
		global $wpdb;

		$lock_keys = $this->buildUpsertLockKeys( $title, $venue, $startDate, $source_identity, $venue_context );
		$acquired  = array();
		$deadline  = microtime( true ) + 10;
		$complete  = false;

		try {
			foreach ( $lock_keys as $lock_key ) {
				$timeout = max( 0, (int) ceil( $deadline - microtime( true ) ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, $timeout ) );

				if ( '1' === (string) $result ) {
					$acquired[] = $lock_key;
					continue;
				}

				do_action(
					'datamachine_log',
					'warning',
					'Event Upsert: Advisory lock unavailable; returning retryable failure',
					array(
						'lock_key'  => $lock_key,
						'lock_keys' => $lock_keys,
						'title'     => $title,
						'startDate' => $startDate,
						'result'    => null === $result ? 'error' : 'timeout',
					)
				);

				return null;
			}

			$complete = true;

			return $acquired;
		} catch ( \Throwable $throwable ) {
			do_action(
				'datamachine_log',
				'warning',
				'Event Upsert: Advisory lock acquisition failed; returning retryable failure',
				array(
					'lock_keys' => $lock_keys,
					'acquired'  => $acquired,
					'title'     => $title,
					'startDate' => $startDate,
					'exception' => $throwable->getMessage(),
				)
			);

			return null;
		} finally {
			if ( ! $complete ) {
				$this->releaseUpsertLocks( $acquired );
			}
		}
	}

	/**
	 * Build globally ordered source and canonical collision-domain lock keys.
	 *
	 * Known times acquire their own two-hour bucket and its predecessor. This is a
	 * conservative adjacent-bucket collision domain, not an exact two-hour range:
	 * starts beyond 120 minutes can briefly serialize when their bucket sets overlap.
	 * Fuzzy equivalence has no exact narrow key, so that bounded false contention is
	 * the correctness tradeoff. Different venue/geography scopes and distant bucket
	 * sets remain parallel. An unknown time acquires 12 venue/day buckets so it
	 * overlaps any time-aware equivalent. Unknown venues include a stable title
	 * prefix fingerprint so venue-less events remain bounded.
	 *
	 * @param string $title           Event title.
	 * @param string $venue           Event venue.
	 * @param string $startDate       Event start date or datetime.
	 * @param string $source_identity Optional stable source identity.
	 * @param array  $venue_context   Venue address geography.
	 * @return array Ordered advisory lock keys.
	 */
	private function buildUpsertLockKeys( string $title, string $venue, string $startDate, string $source_identity = '', array $venue_context = array() ): array {
		$blog_id   = get_current_blog_id();
		$date_only = self::extractDateForQuery( $startDate );
		$identity  = \DataMachineEvents\Core\Venue_Taxonomy::resolve_venue_identity( $venue, $venue_context );

		if ( ! empty( $identity['term_id'] ) ) {
			$venue_scopes = array( 'term:' . (int) $identity['term_id'] );
		} elseif ( '' !== trim( $venue ) ) {
			$venue_scopes = array( 'name:' . SimilarityEngine::normalizeBasic( $venue ) );
		} else {
			$normalized_title = SimilarityEngine::normalizeTitle( $title );
			$title_tokens     = array_slice( array_filter( explode( ' ', $normalized_title ) ), 0, 3 );
			$venue_scopes     = array( 'unknown:' . implode( ' ', $title_tokens ) );
		}

		$normalized_address = \DataMachineEvents\Core\Venue_Taxonomy::normalize_address_for_matching(
			(string) ( $venue_context['address'] ?? '' )
		);
		if ( '' !== $normalized_address ) {
			// Address is intentionally independent of optional city/state/country.
			// Partial source geography must still converge on the same physical key.
			$venue_scopes[] = 'geo:address:' . $normalized_address;
		}
		$venue_scopes = array_values( array_unique( $venue_scopes ) );

		$domains = array();
		if ( '' !== $source_identity ) {
			$domains[] = 'source|' . $blog_id . '|' . $source_identity;
		}

		if ( preg_match( '/\b(\d{1,2}):(\d{2})/', $startDate, $matches ) ) {
			$minutes = ( (int) $matches[1] * 60 ) + (int) $matches[2];
			$bucket  = (int) floor( $minutes / 120 );
			foreach ( $venue_scopes as $venue_scope ) {
				foreach ( array( $bucket - 1, $bucket ) as $time_bucket ) {
					$domains[] = 'canonical|' . $blog_id . '|' . $date_only . '|' . $venue_scope . '|time:' . $time_bucket;
				}
			}
		} else {
			foreach ( $venue_scopes as $venue_scope ) {
				foreach ( range( 0, 11 ) as $time_bucket ) {
					$domains[] = 'canonical|' . $blog_id . '|' . $date_only . '|' . $venue_scope . '|time:' . $time_bucket;
				}
			}
		}

		$lock_keys = array_map(
			static fn( string $domain ): string => 'dme:' . md5( $domain ),
			array_unique( $domains )
		);
		sort( $lock_keys, SORT_STRING );

		return $lock_keys;
	}

	/**
	 * Release acquired MySQL advisory locks.
	 *
	 * @since 0.17.3
	 * @param array $lock_keys Lock keys to release.
	 */
	private function releaseUpsertLocks( array $lock_keys ): void {
		global $wpdb;

		foreach ( array_reverse( $lock_keys ) as $lock_key ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
			} catch ( \Throwable $throwable ) {
				do_action(
					'datamachine_log',
					'warning',
					'Event Upsert: Advisory lock release failed',
					array(
						'lock_key'  => $lock_key,
						'exception' => $throwable->getMessage(),
					)
				);
			}
		}
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
	 * Compose the time-aware identity used by duplicate detection.
	 *
	 * @param string $start_date Event start date or datetime.
	 * @param string $start_time Event start time when stored separately.
	 * @return string Date or datetime suitable for duplicate comparison.
	 */
	private static function composeIdentityStart( string $start_date, string $start_time ): string {
		if ( '' === $start_time || preg_match( '/\d{2}:\d{2}/', $start_date ) ) {
			return $start_date;
		}

		return trim( $start_date ) . ' ' . trim( $start_time );
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
	 * When an event is submitted by a user, their user_id is stored in
	 * initial_data['submission']['user_id'] and takes priority over handler
	 * config defaults so submitted events are attributed to the submitter.
	 *
	 * For genuine automation (no submission context), the author resolves to:
	 *   1. An explicitly-configured author (system-wide default_author_id, then
	 *      per-handler post_author) — respected when set.
	 *   2. A consumer-provided fallback author via
	 *      `data_machine_events_fallback_author_id`.
	 *   3. WordPressSettingsResolver (logged-in user / first admin) — last
	 *      resort when no fallback policy provides an author.
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

		/**
		 * Filter the fallback post author for automated event imports.
		 *
		 * Return a positive user ID to apply a consumer-specific authorship
		 * policy. The default of 0 leaves WordPress to resolve its usual
		 * logged-in-user or first-administrator fallback.
		 *
		 * @param int        $author_id     Fallback author ID. Default 0.
		 * @param array      $handler_config Handler configuration.
		 * @param EngineData $engine        Engine snapshot helper.
		 */
		$fallback_author_id = (int) apply_filters( 'data_machine_events_fallback_author_id', 0, $handler_config, $engine );
		if ( $fallback_author_id > 0 ) {
			return $fallback_author_id;
		}

		// 4. Last resort: generic resolver (logged-in user / first admin).
		return WordPressSettingsResolver::getPostAuthor( $handler_config );
	}

	/**
	 * Resolve an explicitly-configured author id, if any.
	 *
	 * Mirrors the config-first portion of WordPressSettingsResolver::getPostAuthor():
	 * system-wide default_author_id, then handler-specific post_author. Returns 0
	 * when neither is set. Extracted so resolvePostAuthor() can apply a fallback
	 * policy without re-running the resolver's first-admin fallback.
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
		$meta_input      = array();
		$source_identity = (string) ( $parameters['source_identity'] ?? '' );

		if ( '' !== $source_identity ) {
			$meta_input[ self::SOURCE_IDENTITY_META_KEY ] = $source_identity;
			$meta_input[ self::SOURCE_NAME_META_KEY ]     = sanitize_text_field( (string) ( $parameters['source'] ?? '' ) );
			$meta_input[ self::SOURCE_ID_META_KEY ]       = sanitize_text_field( (string) ( $parameters['source_id'] ?? '' ) );
		}

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
