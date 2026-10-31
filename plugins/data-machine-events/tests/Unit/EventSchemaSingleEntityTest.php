<?php
/**
 * Event JSON-LD ownership and date formatting (issue #880).
 *
 * Event pages emitted two schema.org Event entities: this block's standalone
 * JSON-LD and a site-level consolidated graph. The block keeps emitting by
 * default (the plugin must work standalone) but a site-level owner can turn
 * it off with `data_machine_events_output_event_schema`.
 *
 * The block's startDate/endDate were also naive local times
 * (`2026-10-21T18:30`). Times now carry their UTC offset.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSchemaProvider;
use WP_UnitTestCase;

class EventSchemaSingleEntityTest extends WP_UnitTestCase {

	private const FILTER = 'data_machine_events_output_event_schema';

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
	}

	public function tearDown(): void {
		remove_all_filters( self::FILTER );
		wp_reset_postdata();
		parent::tearDown();
	}

	public function test_block_emits_event_json_ld_by_default(): void {
		$output = $this->render_event_details( array( 'startDate' => '2026-10-21', 'startTime' => '18:30' ) );

		$this->assertStringContainsString( 'application/ld+json', $output );
	}

	public function test_site_level_owner_can_suppress_block_json_ld(): void {
		add_filter( self::FILTER, '__return_false' );

		$output = $this->render_event_details( array( 'startDate' => '2026-10-21', 'startTime' => '18:30' ) );

		$this->assertStringNotContainsString( 'application/ld+json', $output );
		$this->assertStringContainsString( 'event-info-grid', $output, 'Suppressing schema must not suppress the visible block.' );
	}

	public function test_filter_receives_the_event_post_id(): void {
		$seen = null;
		add_filter(
			self::FILTER,
			static function ( $output, $post_id ) use ( &$seen ) {
				$seen = $post_id;
				return $output;
			},
			10,
			2
		);

		$this->render_event_details( array( 'startDate' => '2026-10-21' ) );

		$this->assertSame( get_the_ID(), $seen );
	}

	public function test_time_is_emitted_with_utc_offset(): void {
		$tz = new \DateTimeZone( 'America/New_York' );

		$this->assertSame( '2026-10-21T18:30:00-04:00', EventSchemaProvider::formatSchemaDateTime( '2026-10-21', '18:30', $tz ) );
		$this->assertSame( '2026-12-21T18:30:00-05:00', EventSchemaProvider::formatSchemaDateTime( '2026-12-21', '18:30:00', $tz ), 'Offset must follow DST for the date.' );
	}

	public function test_date_only_stays_date_only(): void {
		$this->assertSame( '2026-10-21', EventSchemaProvider::formatSchemaDateTime( '2026-10-21', '', new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_generated_schema_uses_venue_timezone(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );

		$schema = EventSchemaProvider::generateSchemaOrg(
			array(
				'startDate' => '2026-10-21',
				'startTime' => '18:30',
				'endDate'   => '2026-10-21',
				'endTime'   => '21:00',
			),
			array( 'timezone' => 'America/Chicago' ),
			array(),
			$post_id
		);

		$this->assertSame( '2026-10-21T18:30:00-05:00', $schema['startDate'] );
		$this->assertSame( '2026-10-21T21:00:00-05:00', $schema['endDate'] );
	}

	public function test_invalid_venue_timezone_falls_back_to_site_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$post_id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );

		$schema = EventSchemaProvider::generateSchemaOrg(
			array( 'startDate' => '2026-10-21', 'startTime' => '18:30' ),
			array( 'timezone' => 'Not/AZone' ),
			array(),
			$post_id
		);

		$this->assertSame( '2026-10-21T18:30:00-04:00', $schema['startDate'] );
	}

	private function render_event_details( array $attributes ): string {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture mirrors the block renderer's current-post context.
		setup_postdata( $post );

		$content = '';
		$block   = null;

		ob_start();
		require DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventDetails/render.php';
		return ob_get_clean();
	}
}
