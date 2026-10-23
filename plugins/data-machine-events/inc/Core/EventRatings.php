<?php
/**
 * Event Ratings
 *
 * Generic show-rating primitive: people rate past events 1–5 and optionally
 * write a review. Built on WordPress comments so moderation, spam handling,
 * notifications, editing and the comments REST surface all come from core.
 *
 * Storage:
 * - A review is a top-level comment of type `event_review` on an event post.
 * - The rating lives in comment meta `_dme_rating` (integer 1–5).
 * - Aggregates are cached on the event post as `_dme_rating_avg` and
 *   `_dme_rating_count`, counting approved reviews only.
 *
 * Eligibility: a logged-in user may rate a published, past event. Consumers
 * narrow this through the `data_machine_events_can_rate_event` filter (for
 * example, to require proof of attendance). This plugin never knows which
 * consumer applies which rule.
 *
 * One review per user per event. Additional top-level comments from a user
 * who already reviewed are ordinary comments without a rating; replies are
 * always ordinary comments.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRatings {

	public const COMMENT_TYPE = 'event_review';
	public const META_RATING  = '_dme_rating';
	public const META_AVG     = '_dme_rating_avg';
	public const META_COUNT   = '_dme_rating_count';
	public const MIN_RATING   = 1;
	public const MAX_RATING   = 5;
	public const FORM_FIELD   = 'dme_rating';
	public const FORM_SCRIPT  = 'data-machine-events-rating-form';
	public const UNLOCK_EVENT = 'dme:rating-eligibility';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'add_comment_support' ), 20 );
		add_filter( 'comments_open', array( self::class, 'filter_comments_open' ), 10, 2 );
		add_filter( 'preprocess_comment', array( self::class, 'preprocess_comment' ) );
		add_filter( 'allow_empty_comment', array( self::class, 'allow_rating_only_review' ), 10, 2 );

		add_action( 'comment_form_logged_in_after', array( self::class, 'render_rating_field' ) );
		add_filter( 'comment_form_field_comment', array( self::class, 'filter_review_field' ), 9 );
		add_filter( 'comment_form_field_comment', array( self::class, 'filter_form_field' ) );
		add_filter( 'comment_form_submit_button', array( self::class, 'filter_submit_button' ), 9 );
		add_filter( 'comment_form_defaults', array( self::class, 'filter_form_defaults' ), 20 );
		add_filter( 'comment_form_submit_field', array( self::class, 'filter_form_field' ) );
		add_filter( 'comment_form_logged_in', array( self::class, 'filter_logged_in_notice' ) );
		add_filter( 'comment_text', array( self::class, 'filter_comment_text' ), 5, 2 );

		add_action( 'data_machine_events_action_buttons', array( self::class, 'render_summary_action' ), 5, 3 );

		add_action( 'data_machine_events_rating_updated', array( self::class, 'invalidate_listing_caches' ), 10, 0 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_form_script' ) );

		add_action( 'wp_insert_comment', array( self::class, 'on_comment_change' ), 10, 2 );
		add_action( 'transition_comment_status', array( self::class, 'on_comment_transition' ), 10, 3 );
		add_action( 'edit_comment', array( self::class, 'on_comment_change' ), 10, 1 );
		add_action( 'deleted_comment', array( self::class, 'on_comment_change' ), 10, 2 );
	}

	public static function add_comment_support(): void {
		add_post_type_support( Event_Post_Type::POST_TYPE, 'comments' );
	}

	/* ------------------------------------------------------------------
	 * Eligibility
	 * ---------------------------------------------------------------- */

	/**
	 * Whether the event accepts ratings at all (published and in the past).
	 */
	public static function is_rateable_event( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}

		return 'past' === data_machine_events_get_timing( $post_id );
	}

	/**
	 * Whether a specific user may rate an event.
	 */
	public static function can_rate( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 || ! self::is_rateable_event( $post_id ) ) {
			return false;
		}

		/**
		 * Filter whether a user may rate and review a past event.
		 *
		 * @param bool $can_rate Default true for any logged-in user on a past, published event.
		 * @param int  $user_id  User ID.
		 * @param int  $post_id  Event post ID.
		 */
		return (bool) apply_filters( 'data_machine_events_can_rate_event', true, $user_id, $post_id );
	}

	/**
	 * Comments on events open only once the show is over. Per-user
	 * eligibility is enforced on submission and in the form.
	 */
	public static function filter_comments_open( $open, $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return $open;
		}

		return self::is_rateable_event( (int) $post_id );
	}

	/* ------------------------------------------------------------------
	 * Reads
	 * ---------------------------------------------------------------- */

	/**
	 * The user's existing review comment on an event, if any.
	 */
	public static function get_user_review( int $user_id, int $post_id ): ?\WP_Comment {
		if ( $user_id <= 0 ) {
			return null;
		}

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'user_id' => $user_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'all',
				'parent'  => 0,
				'number'  => 1,
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		);

		$first = is_array( $comments ) ? reset( $comments ) : false;
		return $first instanceof \WP_Comment ? $first : null;
	}

	public static function get_comment_rating( int $comment_id ): int {
		return self::normalize_rating( get_comment_meta( $comment_id, self::META_RATING, true ) );
	}

	/**
	 * Rating aggregate for an event.
	 *
	 * @return array{average: float|null, count: int}
	 */
	public static function get_summary( int $post_id ): array {
		$count = (int) get_post_meta( $post_id, self::META_COUNT, true );
		if ( $count <= 0 ) {
			return array(
				'average' => null,
				'count'   => 0,
			);
		}

		return array(
			'average' => round( (float) get_post_meta( $post_id, self::META_AVG, true ), 2 ),
			'count'   => $count,
		);
	}

	/**
	 * Approved reviews for an event, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_reviews( int $post_id, int $limit = 50, int $offset = 0 ): array {
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => self::COMMENT_TYPE,
				'status'  => 'approve',
				'parent'  => 0,
				'number'  => max( 1, min( 200, $limit ) ),
				'offset'  => max( 0, $offset ),
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		$reviews = array();
		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$reviews[] = self::format_review( $comment );
			}
		}
		return $reviews;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function format_review( \WP_Comment $comment ): array {
		return array(
			'comment_id'  => (int) $comment->comment_ID,
			'event_id'    => (int) $comment->comment_post_ID,
			'user_id'     => (int) $comment->user_id,
			'author_name' => (string) $comment->comment_author,
			'rating'      => self::get_comment_rating( (int) $comment->comment_ID ),
			'review'      => (string) $comment->comment_content,
			'approved'    => '1' === (string) $comment->comment_approved,
			'date_gmt'    => mysql_to_rfc3339( $comment->comment_date_gmt ),
			'link'        => get_comment_link( $comment ),
		);
	}

	/* ------------------------------------------------------------------
	 * Writes
	 * ---------------------------------------------------------------- */

	/**
	 * Create or update the user's rating/review for an event.
	 *
	 * New reviews go through wp_new_comment(), so core moderation, flood
	 * and duplicate checks apply. Existing reviews are updated in place.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function rate( int $user_id, int $post_id, $rating, ?string $review = null ) {
		$rating = self::normalize_rating( $rating );
		if ( 0 === $rating ) {
			return new \WP_Error( 'invalid_rating', sprintf( 'Rating must be a whole number from %d to %d.', self::MIN_RATING, self::MAX_RATING ), array( 'status' => 400 ) );
		}

		if ( ! self::is_rateable_event( $post_id ) ) {
			return new \WP_Error( 'event_not_rateable', 'Only published events that have already happened can be rated.', array( 'status' => 400 ) );
		}

		if ( ! self::can_rate( $user_id, $post_id ) ) {
			return new \WP_Error( 'rating_not_allowed', self::gate_message( $user_id, $post_id ), array( 'status' => 403 ) );
		}

		$existing = self::get_user_review( $user_id, $post_id );

		if ( $existing ) {
			$comment_id = (int) $existing->comment_ID;
			if ( null !== $review ) {
				$updated = wp_update_comment(
					array(
						'comment_ID'      => $comment_id,
						'comment_content' => $review,
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
			update_comment_meta( $comment_id, self::META_RATING, $rating );
			self::recalculate( $post_id );
		} else {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return new \WP_Error( 'invalid_user', 'Unknown user.', array( 'status' => 400 ) );
			}

			$comment_id = wp_new_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_content'      => (string) $review,
					'comment_type'         => self::COMMENT_TYPE,
					'comment_parent'       => 0,
					'user_id'              => $user_id,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_meta'         => array( self::META_RATING => $rating ),
				),
				true
			);

			if ( is_wp_error( $comment_id ) ) {
				return $comment_id;
			}
			$comment_id = (int) $comment_id;
		}

		$comment = get_comment( $comment_id );

		return array(
			'review'  => $comment ? self::format_review( $comment ) : array( 'comment_id' => $comment_id ),
			'created' => ! $existing,
			'summary' => self::get_summary( $post_id ),
		);
	}

	/**
	 * Recompute and cache the approved-review aggregate for an event.
	 */
	public static function recalculate( int $post_id ): void {
		global $wpdb;

		// Fetch raw values and average in PHP: portable across MySQL and the
		// SQLite driver, and a show has at most a few hundred reviews.
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT cm.meta_value
				FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = %s
				WHERE c.comment_post_ID = %d AND c.comment_type = %s AND c.comment_approved = '1' AND c.comment_parent = 0",
				self::META_RATING,
				$post_id,
				self::COMMENT_TYPE
			)
		);

		$ratings = array_filter( array_map( array( self::class, 'normalize_rating' ), (array) $values ) );
		$count   = count( $ratings );
		$average = $count > 0 ? array_sum( $ratings ) / $count : 0.0;

		if ( $count > 0 ) {
			update_post_meta( $post_id, self::META_COUNT, $count );
			update_post_meta( $post_id, self::META_AVG, round( $average, 4 ) );
		} else {
			delete_post_meta( $post_id, self::META_COUNT );
			delete_post_meta( $post_id, self::META_AVG );
		}

		/**
		 * Fires after an event's rating aggregate changes.
		 *
		 * @param int   $post_id Event post ID.
		 * @param array $summary { average: float|null, count: int }.
		 */
		do_action( 'data_machine_events_rating_updated', $post_id, self::get_summary( $post_id ) );
	}

	/* ------------------------------------------------------------------
	 * Comment form submission (wp-comments-post.php)
	 * ---------------------------------------------------------------- */

	/**
	 * Turn a top-level comment-form submission on an event into a review.
	 *
	 * @param array $commentdata Comment data.
	 * @return array
	 */
	public static function preprocess_comment( $commentdata ) {
		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return $commentdata;
		}

		// Reviews created through rate() already carry their type and meta.
		if ( self::COMMENT_TYPE === ( $commentdata['comment_type'] ?? '' ) ) {
			return $commentdata;
		}

		$user_id = (int) ( $commentdata['user_id'] ?? get_current_user_id() );
		if ( ! self::can_rate( $user_id, $post_id ) ) {
			self::reject( self::gate_message( $user_id, $post_id ) );
		}

		$is_reply = ! empty( $commentdata['comment_parent'] );
		if ( $is_reply || self::get_user_review( $user_id, $post_id ) ) {
			return $commentdata;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core comment submission; authenticated and gated above.
		$rating = self::normalize_rating( isset( $_POST[ self::FORM_FIELD ] ) ? wp_unslash( $_POST[ self::FORM_FIELD ] ) : '' );
		if ( 0 === $rating ) {
			self::reject( __( 'Pick a rating from 1 to 5 stars for this show.', 'data-machine-events' ) );
		}

		$commentdata['comment_type']                      = self::COMMENT_TYPE;
		$commentdata['comment_meta']                      = (array) ( $commentdata['comment_meta'] ?? array() );
		$commentdata['comment_meta'][ self::META_RATING ] = $rating;

		return $commentdata;
	}

	/**
	 * A star rating with no written review is a valid review.
	 *
	 * @param bool  $allow       Whether empty comments are allowed.
	 * @param array $commentdata Comment data.
	 */
	public static function allow_rating_only_review( $allow, $commentdata ) {
		$post = get_post( (int) ( $commentdata['comment_post_ID'] ?? 0 ) );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type || ! empty( $commentdata['comment_parent'] ) ) {
			return $allow;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core comment submission.
		$rating = self::normalize_rating( isset( $_POST[ self::FORM_FIELD ] ) ? wp_unslash( $_POST[ self::FORM_FIELD ] ) : '' );
		return $rating > 0 ? true : $allow;
	}

	private static function reject( string $message ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Comment Submission Failure', 'data-machine-events' ),
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Comment form rendering
	 * ---------------------------------------------------------------- */

	private static function current_event_id(): int {
		$post_id = (int) get_the_ID();
		$post    = $post_id ? get_post( $post_id ) : null;
		return ( $post && Event_Post_Type::POST_TYPE === $post->post_type ) ? $post_id : 0;
	}

	/**
	 * Message shown to a logged-in user who may not rate this event.
	 */
	public static function gate_message( int $user_id, int $post_id ): string {
		/**
		 * Filter the message shown when a user may not rate an event.
		 *
		 * @param string $message Default message.
		 * @param int    $user_id User ID.
		 * @param int    $post_id Event post ID.
		 */
		return (string) apply_filters(
			'data_machine_events_rating_gate_message',
			__( 'You can’t rate this show.', 'data-machine-events' ),
			$user_id,
			$post_id
		);
	}

	/**
	 * Whether the current form is being shown to a logged-in user who may not
	 * rate yet. The form still renders, locked, so a front-end change in
	 * eligibility (see UNLOCK_EVENT) can reveal it without a reload. The
	 * server re-checks can_rate() on every submission.
	 */
	private static function form_is_locked( int $post_id ): bool {
		$user_id = get_current_user_id();
		return $post_id > 0 && $user_id > 0 && self::is_rateable_event( $post_id ) && ! self::can_rate( $user_id, $post_id );
	}

	/**
	 * Wrap the textarea and submit button in a hidden, unlockable container
	 * for users who may not rate yet.
	 */
	/**
	 * Whether the form will carry the star picker: a rateable event the
	 * current user has not reviewed yet. Locked forms count too, since they
	 * can unlock in place.
	 */
	private static function form_offers_rating( int $post_id ): bool {
		$user_id = get_current_user_id();
		if ( $post_id <= 0 || $user_id <= 0 || ! self::is_rateable_event( $post_id ) ) {
			return false;
		}
		return null === self::get_user_review( $user_id, $post_id );
	}

	/**
	 * With the star picker present the text is optional (rating-only
	 * reviews are allowed), so drop the browser `required` flag and label
	 * the box as an optional review.
	 */
	public static function filter_review_field( $field ) {
		if ( ! self::form_offers_rating( self::current_event_id() ) ) {
			return $field;
		}

		$field = preg_replace( '/\s(?:required|aria-required="true")(?:="required")?/', '', (string) $field );
		return (string) preg_replace(
			'#<label for="comment">.*?</label>#s',
			'<label for="comment">' . esc_html__( 'Review (optional)', 'data-machine-events' ) . '</label>',
			(string) $field,
			1
		);
	}

	/**
	 * Retitle the form on a first review. Applies when the theme supplies
	 * the heading as a default rather than a comment_form() argument.
	 *
	 * @param array $defaults Comment form defaults.
	 * @return array
	 */
	public static function filter_form_defaults( $defaults ) {
		$post_id = self::current_event_id();
		if ( self::form_offers_rating( $post_id ) ) {
			$defaults['title_reply'] = __( 'Rate this show', 'data-machine-events' );
		} elseif ( self::has_rated( get_current_user_id(), $post_id ) ) {
			// The rating is posted; the form is now for follow-up comments.
			$defaults['title_reply'] = __( 'Add a comment', 'data-machine-events' );
		}
		return $defaults;
	}

	/**
	 * Whether the user has already reviewed a rateable event.
	 */
	private static function has_rated( int $user_id, int $post_id ): bool {
		return $post_id > 0 && $user_id > 0 && self::is_rateable_event( $post_id ) && null !== self::get_user_review( $user_id, $post_id );
	}

	/**
	 * Label the submit button for what it does on a first review.
	 */
	public static function filter_submit_button( $button ) {
		if ( ! self::form_offers_rating( self::current_event_id() ) ) {
			return $button;
		}
		return (string) preg_replace(
			'/value="[^"]*"/',
			'value="' . esc_attr__( 'Post Rating', 'data-machine-events' ) . '"',
			(string) $button,
			1
		);
	}

	public static function filter_form_field( $field ) {
		$post_id = self::current_event_id();
		if ( ! self::form_is_locked( $post_id ) ) {
			return $field;
		}
		return self::locked_wrap( (string) $field );
	}

	/**
	 * Add the gate message for users who may not rate. It hides again when
	 * the form unlocks.
	 */
	public static function filter_logged_in_notice( $html ) {
		$post_id = self::current_event_id();
		if ( ! self::form_is_locked( $post_id ) ) {
			return $html;
		}

		self::enqueue_form_script();
		return sprintf(
			'<p class="dme-rating-gate" data-dme-rating-gate="%d">%s</p>',
			(int) $post_id,
			esc_html( self::gate_message( get_current_user_id(), $post_id ) )
		);
	}

	private static function locked_wrap( string $html ): string {
		return sprintf(
			'<div class="dme-rating-locked" data-dme-rating-locked="%d" hidden>%s</div>',
			(int) self::current_event_id(),
			$html
		);
	}

	public static function register_form_script(): void {
		wp_register_script(
			self::FORM_SCRIPT,
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/rating-form.js',
			array(),
			(string) filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/rating-form.js' ),
			true
		);
	}

	private static function enqueue_form_script(): void {
		if ( ! wp_script_is( self::FORM_SCRIPT, 'registered' ) ) {
			self::register_form_script();
		}
		wp_enqueue_script( self::FORM_SCRIPT );
	}

	/**
	 * Star picker for the user's first review on a show.
	 */
	public static function render_rating_field(): void {
		$post_id = self::current_event_id();
		$user_id = get_current_user_id();
		if ( ! $post_id || ! self::is_rateable_event( $post_id ) ) {
			return;
		}

		$locked   = ! self::can_rate( $user_id, $post_id );
		$existing = $locked ? null : self::get_user_review( $user_id, $post_id );
		if ( $existing ) {
			$rating = self::get_comment_rating( (int) $existing->comment_ID );
			printf(
				'<p class="dme-rating-yours">%s <span class="dme-stars" aria-hidden="true">%s</span></p>',
				esc_html__( 'Your rating:', 'data-machine-events' ),
				esc_html( self::stars( $rating ) )
			);
			return;
		}

		$field = '<fieldset class="dme-rating-field"><legend>' . esc_html__( 'Your rating', 'data-machine-events' ) . '</legend><div class="dme-rating-options">';
		for ( $i = self::MAX_RATING; $i >= self::MIN_RATING; $i-- ) {
			$field .= sprintf(
				'<input type="radio" id="dme-rating-%1$d" name="%2$s" value="%1$d"><label for="dme-rating-%1$d" title="%3$s">★</label>',
				(int) $i,
				esc_attr( self::FORM_FIELD ),
				/* translators: %d: star rating. */
				esc_attr( sprintf( _n( '%d star', '%d stars', $i, 'data-machine-events' ), $i ) )
			);
		}
		$field .= '</div></fieldset>';

		echo $locked ? self::locked_wrap( $field ) : $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
	}

	/**
	 * Prefix review text with its star rating.
	 */
	public static function filter_comment_text( $text, $comment = null ) {
		if ( ! $comment instanceof \WP_Comment || self::COMMENT_TYPE !== $comment->comment_type ) {
			return $text;
		}

		$rating = self::get_comment_rating( (int) $comment->comment_ID );
		if ( 0 === $rating ) {
			return $text;
		}

		$label = sprintf(
			/* translators: 1: rating, 2: max rating. */
			__( 'Rated %1$d out of %2$d', 'data-machine-events' ),
			$rating,
			self::MAX_RATING
		);

		return sprintf(
			'<p class="dme-review-rating"><span class="dme-stars" role="img" aria-label="%s">%s</span></p>',
			esc_attr( $label ),
			esc_html( self::stars( $rating ) )
		) . $text;
	}

	/**
	 * Rating summary in the single-event action row for past shows.
	 */
	public static function render_summary_action( $post_id, $ticket_url = '', $timing = '' ): void {
		if ( 'past' !== $timing ) {
			return;
		}
		echo self::render_summary( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_summary().
	}

	public static function render_summary( int $post_id ): string {
		$summary = self::get_summary( $post_id );
		if ( $summary['count'] <= 0 ) {
			return '';
		}

		$average = number_format_i18n( $summary['average'], 1 );
		$count   = sprintf(
			/* translators: %s: number of ratings. */
			_n( '%s rating', '%s ratings', $summary['count'], 'data-machine-events' ),
			number_format_i18n( $summary['count'] )
		);

		return sprintf(
			'<a class="dme-rating-summary" href="#comments"><span class="dme-stars" aria-hidden="true">★</span> <strong>%s</strong> · %s</a>',
			esc_html( $average ),
			esc_html( $count )
		);
	}

	/**
	 * Compact rating badge for event listings (calendar rows on artist,
	 * venue and city archives). Empty when the event has no ratings.
	 */
	public static function render_listing_badge( int $post_id ): string {
		$summary = self::get_summary( $post_id );
		if ( $summary['count'] <= 0 ) {
			return '';
		}

		$average = number_format_i18n( $summary['average'], 1 );
		$label   = sprintf(
			/* translators: 1: average rating, 2: number of ratings. */
			_n( 'Rated %1$s out of 5 by %2$s person', 'Rated %1$s out of 5 by %2$s people', $summary['count'], 'data-machine-events' ),
			$average,
			number_format_i18n( $summary['count'] )
		);

		return sprintf(
			'<div class="dme-rating-badge" role="img" aria-label="%1$s"><span class="dme-stars" aria-hidden="true">★</span> <strong aria-hidden="true">%2$s</strong> <span class="dme-rating-badge-count" aria-hidden="true">(%3$s)</span></div>',
			esc_attr( $label ),
			esc_html( $average ),
			esc_html( number_format_i18n( $summary['count'] ) )
		);
	}

	/**
	 * A user's own ratings for a set of events, keyed by event ID. Includes
	 * pending reviews so the user sees what they submitted.
	 *
	 * @param int   $user_id   User ID.
	 * @param int[] $event_ids Event post IDs.
	 * @return array<int,int> event_id => rating.
	 */
	public static function get_user_ratings( int $user_id, array $event_ids ): array {
		$event_ids = array_values( array_filter( array_unique( array_map( 'intval', $event_ids ) ) ) );
		if ( $user_id <= 0 || empty( $event_ids ) ) {
			return array();
		}

		$comments = get_comments(
			array(
				'post__in' => $event_ids,
				'user_id'  => $user_id,
				'type'     => self::COMMENT_TYPE,
				'status'   => 'all',
				'parent'   => 0,
				'orderby'  => 'comment_ID',
				'order'    => 'ASC',
			)
		);

		$ratings = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment || 'trash' === $comment->comment_approved || 'spam' === $comment->comment_approved ) {
				continue;
			}
			$event_id = (int) $comment->comment_post_ID;
			if ( isset( $ratings[ $event_id ] ) ) {
				continue;
			}
			$rating = self::get_comment_rating( (int) $comment->comment_ID );
			if ( $rating > 0 ) {
				$ratings[ $event_id ] = $rating;
			}
		}
		return $ratings;
	}

	/**
	 * Calendar listings embed rating badges, so a rating change must rotate
	 * their cache generation.
	 */
	public static function invalidate_listing_caches(): void {
		if ( class_exists( \DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator::class ) ) {
			\DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator::invalidate_all();
		}
	}

	/* ------------------------------------------------------------------
	 * Aggregate maintenance
	 * ---------------------------------------------------------------- */

	public static function on_comment_change( $comment_id, $comment = null ): void {
		$comment = $comment instanceof \WP_Comment ? $comment : get_comment( (int) $comment_id );
		if ( $comment && self::COMMENT_TYPE === $comment->comment_type ) {
			self::recalculate( (int) $comment->comment_post_ID );
		}
	}

	public static function on_comment_transition( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof \WP_Comment && self::COMMENT_TYPE === $comment->comment_type ) {
			self::recalculate( (int) $comment->comment_post_ID );
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * @param mixed $rating Raw rating.
	 * @return int Rating 1–5, or 0 when invalid.
	 */
	public static function normalize_rating( $rating ): int {
		if ( is_int( $rating ) ) {
			$value = $rating;
		} elseif ( is_string( $rating ) && preg_match( '/^\s*\d+\s*$/', $rating ) ) {
			$value = (int) $rating;
		} elseif ( is_float( $rating ) && floor( $rating ) === $rating ) {
			$value = (int) $rating;
		} else {
			return 0;
		}

		return ( $value >= self::MIN_RATING && $value <= self::MAX_RATING ) ? $value : 0;
	}

	public static function stars( int $rating ): string {
		$rating = max( 0, min( self::MAX_RATING, $rating ) );
		return str_repeat( '★', $rating ) . str_repeat( '☆', self::MAX_RATING - $rating );
	}
}
