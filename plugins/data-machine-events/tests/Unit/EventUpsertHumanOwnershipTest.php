<?php
/**
 * Imports must not overwrite human-created or human-edited events (#3910).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventOwnershipAbilities;
use DataMachineEvents\Abilities\EventUpdateAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventOwnership;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

require_once __DIR__ . '/EventUpsertTestCase.php';

/** Fill-only upserts against human-owned events. */
class EventUpsertHumanOwnershipTest extends EventUpsertTestCase {

	private string $date;

	/** @var \Closure|null */
	private $log_listener = null;

	public function setUp(): void {
		parent::setUp();
		$this->date = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
	}

	public function tearDown(): void {
		remove_all_filters( 'datamachine_events_is_human_owned_event' );
		if ( $this->log_listener ) {
			remove_action( 'datamachine_log', $this->log_listener, 10 );
		}
		parent::tearDown();
	}

	/**
	 * Create an event the way a person would: insert, then save it through
	 * the block editor's REST endpoint.
	 *
	 * @param string $title Event title.
	 * @param string $venue Venue name.
	 * @return int Post ID.
	 */
	private function createHumanEvent( string $title, string $venue ): int {
		$attrs   = array(
			'startDate' => $this->date,
			'startTime' => '20:00',
			'venue'     => $venue,
		);
		$content = '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attrs ) . ' -->' . "\n"
			. '<div class="wp-block-data-machine-events-event-details">' . "\n"
			. '<!-- wp:paragraph -->' . "\n" . '<p>Promoter copy with the guest list.</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n"
			. '</div>' . "\n" . '<!-- /wp:data-machine-events/event-details -->';
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		$venue_term = wp_insert_term( $venue, 'venue' );
		$this->assertNotWPError( $venue_term );
		wp_set_object_terms( $post_id, array( (int) $venue_term['term_id'] ), 'venue' );
		$promoter = wp_insert_term( 'Hand Booked Presents', 'promoter' );
		$this->assertNotWPError( $promoter );
		wp_set_object_terms( $post_id, array( (int) $promoter['term_id'] ), 'promoter' );
		$flyer = self::factory()->attachment->create_object( 'promoter-flyer.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );
		set_post_thumbnail( $post_id, $flyer );

		$this->assertFalse( EventOwnership::isHumanOwned( $post_id ), 'Programmatic inserts are not human edits.' );
		$this->restUpdate( $post_id, array( 'title' => $title ) );
		$this->assertTrue( EventOwnership::isHumanOwned( $post_id ), 'An editor save must mark the event human-owned.' );

		return $post_id;
	}

	/** Save an event through the REST posts controller as the current user. */
	private function restUpdate( int $post_id, array $body ): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/data_machine_events/' . $post_id );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/** A scraped import of the same show. */
	private function importParameters( string $title, string $venue, array $overrides = array() ): array {
		return array_merge(
			array(
				'title'           => $title,
				'venue'           => $venue,
				'startDate'       => $this->date,
				'startTime'       => '20:00',
				'endTime'         => '23:00',
				'price'           => '$15',
				'ticketUrl'       => 'https://tickets.example.test/show',
				'organizer'       => 'Venue Calendar Presents',
				'description'     => 'Scraped venue calendar copy.',
				'image_file_path' => '/missing/scraped-flyer.jpg',
				'source_type'     => 'universal_web_scraper',
				'item_identifier' => 'scrape-' . md5( $title . $venue ),
			),
			$overrides
		);
	}

	private function importConfig(): array {
		return array(
			'include_images'              => true,
			'taxonomy_promoter_selection' => 'ai_decides',
		);
	}

	private function detailsAttrs( int $post_id ): array {
		foreach ( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) as $block ) {
			if ( Event_Post_Type::EVENT_DETAILS_BLOCK_NAME === $block['blockName'] ) {
				return $block['attrs'];
			}
		}
		return array();
	}

	private function termNames( int $post_id, string $taxonomy ): array {
		return wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
	}

	/** Acceptance 1: content, title, thumbnail, venue/promoter untouched; empty fields filled. */
	public function test_import_matching_human_created_event_only_fills_empty_fields(): void {
		$title     = 'Booked Night ' . uniqid();
		$venue     = 'Corner Taproom ' . uniqid();
		$post_id   = $this->createHumanEvent( $title, $venue );
		$thumbnail = get_post_thumbnail_id( $post_id );
		$venue_ids = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );

		$image_attempts     = 0;
		$this->log_listener = static function ( $level, $message ) use ( &$image_attempts ): void {
			if ( str_contains( (string) $message, 'Image file not found' ) ) {
				++$image_attempts;
			}
		};
		add_action( 'datamachine_log', $this->log_listener, 10, 2 );

		$result = $this->invoke_upsert( $this->importParameters( $title, $venue ), $this->importConfig() );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( 'updated', $result['data']['action'] );
		$this->assertTrue( $result['data']['fill_only'] );

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'Promoter copy with the guest list.', $content );
		$this->assertStringNotContainsString( 'Scraped venue calendar copy.', $content );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id ) );
		$this->assertSame( $thumbnail, get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 0, $image_attempts, 'A human flyer must not be replaced by the import image.' );
		$this->assertSame( $venue_ids, wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( 'Hand Booked Presents' ), $this->termNames( $post_id, 'promoter' ) );

		$attrs = $this->detailsAttrs( $post_id );
		$this->assertSame( '20:00', $attrs['startTime'] );
		$this->assertSame( '23:00', $attrs['endTime'] );
		$this->assertSame( '$15', $attrs['price'] );
		$this->assertSame( 'https://tickets.example.test/show', $attrs['ticketUrl'] );
		$this->assertSame( '', (string) get_post_meta( $post_id, EventUpsert::SOURCE_NAME_META_KEY, true ), 'The importer must not claim the human event.' );
		$this->assertSame( EventOwnership::OWNER_HUMAN, EventOwnership::explicitOwnership( $post_id ) );
	}

	/** Acceptance 1 (explicit candidate): a different import title and venue are not written. */
	public function test_explicit_candidate_keeps_human_title_and_venue(): void {
		$title     = 'Booked Explicit ' . uniqid();
		$venue     = 'Booked Room ' . uniqid();
		$post_id   = $this->createHumanEvent( $title, $venue );
		$venue_ids = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );

		$parameters             = $this->importParameters( 'Scraped Retitle ' . uniqid(), 'Some Other Venue ' . uniqid() );
		$parameters['event_id'] = $post_id;
		$result                 = $this->invoke_upsert( $parameters, $this->importConfig() );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id ) );
		$this->assertSame( $venue_ids, wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $venue, $this->detailsAttrs( $post_id )['venue'] );
	}

	/** Acceptance 2: import-created, never-edited events keep full-update behavior. */
	public function test_import_created_event_still_updates_fully(): void {
		$source_id = 'tm-' . uniqid();
		$venue     = 'Import Venue ' . uniqid();
		$first     = $this->invoke_upsert(
			array(
				'title'           => 'Import Original ' . uniqid(),
				'venue'           => $venue,
				'startDate'       => $this->date,
				'startTime'       => '19:00',
				'description'     => 'First scrape.',
				'source_type'     => 'ticketmaster',
				'item_identifier' => $source_id,
			)
		);
		$post_id   = $first['data']['post_id'];
		$this->assertSame( '', EventOwnership::explicitOwnership( $post_id ), 'Importer writes must never flag ownership, even with a logged-in user.' );

		$revised = 'Import Revised ' . uniqid();
		$second  = $this->invoke_upsert(
			array(
				'title'           => $revised,
				'venue'           => $venue,
				'startDate'       => $this->date,
				'startTime'       => '19:00',
				'description'     => 'Second scrape.',
				'source_type'     => 'ticketmaster',
				'item_identifier' => $source_id,
			)
		);

		$this->assertSame( $post_id, $second['data']['post_id'] );
		$this->assertSame( 'updated', $second['data']['action'] );
		$this->assertArrayNotHasKey( 'fill_only', $second['data'] );
		$this->assertSame( $revised, get_post_field( 'post_title', $post_id ) );
		$this->assertStringContainsString( 'Second scrape.', (string) get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 'ticketmaster', get_post_meta( $post_id, EventUpsert::SOURCE_NAME_META_KEY, true ) );
		$this->assertFalse( EventOwnership::isHumanOwned( $post_id ) );
	}

	/**
	 * Create an import-owned event.
	 *
	 * @return array{0: int, 1: array} Post ID and the import parameters.
	 */
	private function createImportedEvent(): array {
		$parameters = array(
			'title'           => 'Imported Show ' . uniqid(),
			'venue'           => 'Imported Venue ' . uniqid(),
			'startDate'       => $this->date,
			'startTime'       => '21:00',
			'price'           => '$10',
			'description'     => 'Original scrape copy.',
			'source_type'     => 'dice_fm',
			'item_identifier' => 'dice-' . uniqid(),
		);
		$created    = $this->invoke_upsert( $parameters );
		$this->assertSame( 'created', $created['data']['action'] );

		return array( (int) $created['data']['post_id'], $parameters );
	}

	/** Acceptance 3: the update-event ability run by a user protects an imported event. */
	public function test_import_created_event_is_protected_after_update_event_ability_edit(): void {
		list( $post_id, $parameters ) = $this->createImportedEvent();

		$abilities = new EventUpdateAbilities();
		$updated   = $abilities->executeUpdateEvent(
			array(
				'event' => $post_id,
				'price' => '$25 door',
			)
		);
		$this->assertSame( 1, $updated['summary']['updated'], wp_json_encode( $updated ) );
		$this->assertSame( EventOwnership::OWNER_HUMAN, EventOwnership::explicitOwnership( $post_id ) );
		$this->assertTrue( EventOwnership::isHumanOwned( $post_id ) );

		$parameters['price']       = '$12';
		$parameters['description'] = 'Rewritten scrape copy.';
		$result                    = $this->invoke_upsert( $parameters );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertTrue( $result['data']['fill_only'] );
		$this->assertSame( '$25 door', $this->detailsAttrs( $post_id )['price'] );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'Original scrape copy.', $content );
		$this->assertStringNotContainsString( 'Rewritten scrape copy.', $content );
	}

	/** Acceptance 3: a block-editor (REST) save protects an imported event; logged-out writes do not. */
	public function test_import_created_event_is_protected_after_editor_save(): void {
		list( $post_id, $parameters ) = $this->createImportedEvent();

		$current = get_current_user_id();
		wp_set_current_user( 0 );
		EventOwnership::onRestInsert( get_post( $post_id ), new \WP_REST_Request(), false );
		$this->assertFalse( EventOwnership::isHumanOwned( $post_id ), 'Writes without a user are never human edits.' );
		wp_set_current_user( $current );

		$this->restUpdate( $post_id, array( 'title' => 'Editor Retitled Show' ) );
		$this->assertTrue( EventOwnership::isHumanOwned( $post_id ) );

		$result = $this->invoke_upsert( $parameters );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( 'Editor Retitled Show', get_post_field( 'post_title', $post_id ) );
	}

	/** Acceptance 4: the filter can mark an event human-owned. */
	public function test_filter_marks_event_human_owned(): void {
		list( $post_id, $parameters ) = $this->createImportedEvent();
		$this->assertFalse( EventOwnership::isHumanOwned( $post_id ) );

		add_filter(
			'datamachine_events_is_human_owned_event',
			static fn( bool $owned, int $id ): bool => $owned || $id === $post_id,
			10,
			2
		);
		$this->assertTrue( EventOwnership::isHumanOwned( $post_id ) );
		$this->assertTrue( data_machine_events_is_human_owned_event( $post_id ) );

		$original                  = (string) get_post_field( 'post_title', $post_id );
		$parameters['description'] = 'Filtered scrape copy.';
		$result                    = $this->invoke_upsert( $parameters );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertTrue( $result['data']['fill_only'] );
		$this->assertSame( 'no_change', $result['data']['action'] );
		$this->assertSame( $original, get_post_field( 'post_title', $post_id ) );
		$this->assertStringNotContainsString( 'Filtered scrape copy.', (string) get_post_field( 'post_content', $post_id ) );
	}

	/** Acceptance 5: re-running the same import does not create a duplicate. */
	public function test_reimport_keeps_matching_the_human_event_without_duplicates(): void {
		$title   = 'Booked Repeat ' . uniqid();
		$venue   = 'Repeat Room ' . uniqid();
		$post_id = $this->createHumanEvent( $title, $venue );
		$import  = $this->importParameters( $title, $venue );

		$first  = $this->invoke_upsert( $import, $this->importConfig() );
		$second = $this->invoke_upsert( $import, $this->importConfig() );
		// The source changes its title; the linked identity still resolves.
		$third = $this->invoke_upsert( array_merge( $import, array( 'title' => 'Completely Renamed Listing ' . uniqid() ) ), $this->importConfig() );

		foreach ( array( $first, $second, $third ) as $result ) {
			$this->assertTrue( $result['success'], wp_json_encode( $result ) );
			$this->assertSame( $post_id, $result['data']['post_id'] );
		}
		$this->assertSame( 'no_change', $second['data']['action'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
		$this->assertSame(
			array( hash( 'sha256', $import['source_type'] . "\0" . $import['item_identifier'] ) ),
			get_post_meta( $post_id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false )
		);
		$this->assertSame( '', (string) get_post_meta( $post_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) );
		$this->assertSame( $title, get_post_field( 'post_title', $post_id ) );
	}

	/** Escape hatch: an operator hands a human event back to the importer. */
	public function test_operator_release_restores_full_import_updates(): void {
		$title   = 'Booked Release ' . uniqid();
		$venue   = 'Release Room ' . uniqid();
		$post_id = $this->createHumanEvent( $title, $venue );
		$import  = $this->importParameters( $title, $venue );
		$this->invoke_upsert( $import, $this->importConfig() );

		$ability = new EventOwnershipAbilities();
		$status  = $ability->execute(
			array(
				'event'     => $post_id,
				'ownership' => EventOwnership::OWNER_IMPORT,
			)
		);
		$this->assertFalse( $status['human_owned'] );
		$this->assertSame( EventOwnership::OWNER_IMPORT, $status['explicit_ownership'] );
		$this->assertSame( 1, $status['linked_source_identities'] );

		$result = $this->invoke_upsert( $import, $this->importConfig() );

		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertArrayNotHasKey( 'fill_only', $result['data'] );
		$this->assertStringContainsString( 'Scraped venue calendar copy.', (string) get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 'universal_web_scraper', get_post_meta( $post_id, EventUpsert::SOURCE_NAME_META_KEY, true ) );

		$this->assertWPError( $ability->execute( array( 'event' => self::factory()->post->create() ) ) );
	}

	/** Submitted events are human-owned, but a submitter's resubmission still updates them. */
	public function test_submission_is_human_owned_and_resubmission_updates(): void {
		$submission = array(
			'title'           => 'Submitted Show ' . uniqid(),
			'venue'           => 'Submission Venue ' . uniqid(),
			'startDate'       => $this->date,
			'startTime'       => '18:00',
			'description'     => 'Submitted copy.',
			'source_type'     => 'event_submission',
			'item_identifier' => 'submitter-' . uniqid(),
			'submission'      => array(
				'user_id'       => get_current_user_id(),
				'contact_email' => 'promoter@example.test',
			),
		);
		$created    = $this->invoke_upsert( $submission );
		$post_id    = $created['data']['post_id'];
		$this->assertTrue( EventOwnership::isHumanOwned( $post_id ) );
		$this->assertSame( '', EventOwnership::explicitOwnership( $post_id ) );

		$scrape = array_merge(
			$submission,
			array(
				'description'     => 'Scraped copy.',
				'source_type'     => 'universal_web_scraper',
				'item_identifier' => 'scrape-' . uniqid(),
			)
		);
		unset( $scrape['submission'] );
		$scraped = $this->invoke_upsert( $scrape );
		$this->assertSame( $post_id, $scraped['data']['post_id'] );
		$this->assertTrue( $scraped['data']['fill_only'] );
		$this->assertStringNotContainsString( 'Scraped copy.', (string) get_post_field( 'post_content', $post_id ) );

		$submission['description'] = 'Corrected submitted copy.';
		$resubmitted               = $this->invoke_upsert( $submission );
		$this->assertSame( $post_id, $resubmitted['data']['post_id'] );
		$this->assertArrayNotHasKey( 'fill_only', $resubmitted['data'] );
		$this->assertStringContainsString( 'Corrected submitted copy.', (string) get_post_field( 'post_content', $post_id ) );
	}
}
