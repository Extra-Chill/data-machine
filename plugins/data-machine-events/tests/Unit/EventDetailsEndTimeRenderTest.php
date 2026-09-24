<?php
/**
 * EventDetails block end-time rendering tests (issue #860).
 *
 * `render.php` computed `$end_datetime` and fed it into
 * `EventSchemaProvider::generateSchemaOrg()` for the JSON-LD `endDate`, but
 * had no branch anywhere that ever echoed it into the visible page — an
 * event's end time was invisible to every human visitor even though the
 * data was captured and even used for SEO structured data.
 *
 * Renders the real block template (not a simulation) by setting the same
 * `$attributes` / `$content` / `$block` local variables WordPress's block
 * renderer would provide and `require`-ing `render.php` directly, which is
 * safe here because the template never reads `$block` (only documents it)
 * and reads everything else from `$attributes`, `$content`, and the
 * current post via `get_the_ID()`.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class EventDetailsEndTimeRenderTest extends WP_UnitTestCase {

	private $original_date_format;
	private $original_time_format;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		// Pin both options so assertions don't depend on the test
		// environment's ambient settings — this is also the exact
		// configuration verified read-only against production
		// (events.extrachill.com) for issue #860.
		$this->original_date_format = get_option( 'date_format' );
		$this->original_time_format = get_option( 'time_format' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
	}

	public function tearDown(): void {
		update_option( 'date_format', $this->original_date_format );
		update_option( 'time_format', $this->original_time_format );
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * The exact real-world case from issue #860: the WordPress meetup event
	 * (events.extrachill.com post 486727), stored 2026-10-21 18:30 to 21:00.
	 * Before this fix the page showed only "6:30 pm"; it must now also show
	 * "9:00 pm", collapsed into a single range per the shared
	 * DisplayVars::format_time_range() convention.
	 */
	public function test_same_day_end_renders_as_collapsed_time_range(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-10-21',
				'startTime' => '18:30',
				'endDate'   => '2026-10-21',
				'endTime'   => '21:00',
			)
		);

		$this->assertStringContainsString( '6:30 - 9:00 pm', $output, 'Same-day start/end must collapse into one range.' );
		$this->assertStringNotContainsString( 'through', $output, 'A same-day end is not a multi-day span.' );
	}

	/**
	 * The overwhelmingly common case for scraped events: no end stored at
	 * all. Must render byte-for-byte like the pre-#860 behavior — no dash,
	 * no invented end, no "through" line.
	 */
	public function test_no_end_stored_is_unchanged(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-10-21',
				'startTime' => '18:30',
			)
		);

		$this->assertStringContainsString( '6:30 pm', $output );
		$this->assertStringNotContainsString( ' - ', $output, 'No end stored must never render a range dash.' );
		$this->assertStringNotContainsString( 'through', $output );
	}

	/**
	 * An end time identical to the start time is not a real duration and
	 * must render exactly like "no end stored" — not "6:30 - 6:30 pm".
	 */
	public function test_end_equal_to_start_is_treated_as_no_end(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-10-21',
				'startTime' => '18:30',
				'endDate'   => '2026-10-21',
				'endTime'   => '18:30',
			)
		);

		$this->assertStringContainsString( '6:30 pm', $output );
		$this->assertStringNotContainsString( ' - ', $output );
		$this->assertStringNotContainsString( 'through', $output );
	}

	/**
	 * A genuinely multi-day occurrence (a continuous festival/run spanning
	 * real calendar days, not a same-night bar show) must surface its end
	 * date — previously invisible entirely, which is the core of #860 for
	 * this shape.
	 */
	public function test_multi_day_span_surfaces_end_date_and_time(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '19:30',
				'endDate'   => '2026-07-13',
				'endTime'   => '23:00',
			)
		);

		$this->assertStringContainsString( '7:30 pm', $output, 'Multi-day keeps the start time.' );
		$this->assertStringContainsString( 'through', $output );
		$this->assertStringContainsString( 'July 13, 2026', $output );
		$this->assertStringContainsString( '11:00 pm', $output, 'The end time is part of the through-line.' );
	}

	/**
	 * An all-day multi-day event (dates only, no times at all) must still
	 * surface the end date, with no time appended to either side.
	 */
	public function test_all_day_multi_day_span_surfaces_end_date_without_time(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-07-10',
				'endDate'   => '2026-07-13',
			)
		);

		$this->assertStringContainsString( 'through', $output );
		$this->assertStringContainsString( 'July 13, 2026', $output );
		$this->assertStringNotContainsString( ' - ', $output );
	}

	/**
	 * A same-night cross-midnight end (9 PM start -> 2 AM end the next
	 * calendar day, before the 05:00 default cutoff — #833's shared rule)
	 * is a single continuation of the same evening, not a multi-day span:
	 * it renders as one collapsed range, with no "through" line.
	 */
	public function test_same_night_cross_midnight_end_renders_as_range_not_multi_day(): void {
		$output = $this->render_event_details(
			array(
				'startDate' => '2026-07-10',
				'startTime' => '21:00',
				'endDate'   => '2026-07-11',
				'endTime'   => '02:00',
			)
		);

		$this->assertStringContainsString( '9:00 pm - 2:00 am', $output );
		$this->assertStringNotContainsString( 'through', $output );
	}

	/**
	 * Renders the real Event Details block template for a fresh event
	 * post, returning the produced HTML.
	 *
	 * @param array $attributes Block attributes (startDate/startTime/endDate/endTime, etc.).
	 * @return string Rendered HTML.
	 */
	private function render_event_details( array $attributes ): string {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture mirrors the block renderer's current-post context.
		setup_postdata( $post );

		// Local variables named exactly as WordPress's block renderer
		// provides them; render.php reads these directly (see its file
		// docblock `@var` tags) rather than receiving them as function
		// arguments, since it's a server-side-render template, not a
		// callable.
		$content = '';
		$block   = null;

		ob_start();
		require DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/Blocks/EventDetails/render.php';
		return ob_get_clean();
	}
}
