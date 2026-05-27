<?php
/**
 * Artist URL Submissions Admin
 *
 * Adds a submenu under the Events post-type menu for moderating
 * URL-based artist tour import submissions queued by the
 * `data-machine-events/submit-artist-url` ability.
 *
 * The screen is intentionally simple (v1):
 *   - Status filter tabs: Pending review (default), Approved, Rejected, Failed scrapes.
 *   - One row per submission with submitter, URL, suggested artist, events found.
 *   - Approve form: artist term ID OR new artist name + schedule interval.
 *   - Reject form: optional reason.
 *
 * Form submits POST back to `admin-post.php` with a nonce — same shape
 * Settings_Page uses for its writes. Each form delegates to the
 * corresponding ability via wp_get_ability(), then redirects back to
 * the screen with a status flash.
 *
 * @package DataMachineEvents\Admin
 * @since   0.40.0
 */

namespace DataMachineEvents\Admin;

use DataMachineEvents\Core\ArtistUrlSubmissionsTable;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlSubmissionsAdmin {

	const PAGE_SLUG = 'data-machine-events-artist-url-submissions';

	const NONCE_APPROVE = 'datamachine_events_artist_url_approve';
	const NONCE_REJECT  = 'datamachine_events_artist_url_reject';

	public function __construct() {
		add_filter( 'data_machine_events_post_type_menu_items', array( $this, 'add_menu_item' ) );

		// admin-post.php endpoints for the inline forms.
		add_action( 'admin_post_' . self::NONCE_APPROVE, array( $this, 'handle_approve_post' ) );
		add_action( 'admin_post_' . self::NONCE_REJECT, array( $this, 'handle_reject_post' ) );
	}

	public function add_menu_item( $allowed_items ) {
		$allowed_items['artist_url_submissions'] = array(
			'type'     => 'submenu',
			'callback' => array( $this, 'register_submenu' ),
		);
		return $allowed_items;
	}

	public function register_submenu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Event_Post_Type::POST_TYPE,
			__( 'Artist URL Submissions', 'data-machine-events' ),
			__( 'Artist URL Imports', 'data-machine-events' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'data-machine-events' ) );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW;
		$allowed_statuses = array(
			ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			ArtistUrlSubmissionsTable::STATUS_APPROVED,
			ArtistUrlSubmissionsTable::STATUS_REJECTED,
			ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED,
		);
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW;
		}

		$counts      = ArtistUrlSubmissionsTable::counts_by_status();
		$submissions = ArtistUrlSubmissionsTable::list_by_status( $status, 100, 0 );

		$base_url = admin_url( 'edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG );

		$flash = isset( $_GET['flash'] ) ? sanitize_key( wp_unslash( (string) $_GET['flash'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Artist URL Imports', 'data-machine-events' ) . '</h1>';
		echo '<p>' . esc_html__( 'Moderation queue for URL-based artist tour imports submitted via the event-submission form. Approving a row creates a Data Machine pipeline scoped to the chosen artist; rejecting closes it without creating one.', 'data-machine-events' ) . '</p>';

		if ( 'approved' === $flash ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Submission approved and pipeline created.', 'data-machine-events' ) . '</p></div>';
		} elseif ( 'rejected' === $flash ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Submission rejected.', 'data-machine-events' ) . '</p></div>';
		} elseif ( 'error' === $flash ) {
			$msg = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['msg'] ) ) : __( 'Action failed.', 'data-machine-events' );
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Status tabs.
		echo '<ul class="subsubsub">';
		$tabs = array(
			ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW  => __( 'Pending review', 'data-machine-events' ),
			ArtistUrlSubmissionsTable::STATUS_APPROVED        => __( 'Approved', 'data-machine-events' ),
			ArtistUrlSubmissionsTable::STATUS_REJECTED        => __( 'Rejected', 'data-machine-events' ),
			ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED => __( 'Failed scrapes', 'data-machine-events' ),
		);
		$last_idx = count( $tabs ) - 1;
		$i        = 0;
		foreach ( $tabs as $tab_key => $tab_label ) {
			$url       = add_query_arg( 'status', $tab_key, $base_url );
			$count     = (int) ( $counts[ $tab_key ] ?? 0 );
			$is_active = ( $tab_key === $status );
			echo '<li><a href="' . esc_url( $url ) . '"' . ( $is_active ? ' class="current"' : '' ) . '>';
			echo esc_html( $tab_label ) . ' <span class="count">(' . esc_html( (string) $count ) . ')</span>';
			echo '</a>' . ( $i < $last_idx ? ' |' : '' ) . '</li>';
			++$i;
		}
		echo '</ul>';
		echo '<br class="clear" />';

		// Table.
		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'No submissions in this status.', 'data-machine-events' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitter', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Suggested artist', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Events found', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Detected format', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'data-machine-events' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'data-machine-events' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $submissions as $row ) {
			$this->render_row( $row, $status );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_row( array $row, string $status_context ): void {
		$submitter = (string) ( $row['contact_name'] ?: $row['contact_email'] ?: '—' );
		$user_id   = (int) ( $row['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$submitter = sprintf( '%s (#%d)', $user->display_name ?: $user->user_login, $user_id );
			}
		}

		$suggested = (string) ( $row['suggested_artist_name'] ?? '' );
		if ( ! empty( $row['suggested_artist_term_id'] ) ) {
			$term = get_term( (int) $row['suggested_artist_term_id'], 'artist' );
			if ( $term && ! is_wp_error( $term ) ) {
				$suggested .= sprintf( ' (term #%d: %s)', $term->term_id, $term->name );
			}
		}

		echo '<tr>';
		echo '<td>' . esc_html( (string) $row['id'] ) . '</td>';
		echo '<td><a href="' . esc_url( $row['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $row['url'] ) . '</a></td>';
		echo '<td>' . esc_html( $submitter ) . '</td>';
		echo '<td>' . esc_html( $suggested ?: '—' ) . '</td>';
		echo '<td>' . esc_html( (string) (int) $row['events_found_count'] ) . '</td>';
		echo '<td><code>' . esc_html( (string) ( $row['detected_format'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
		echo '<td>';

		if ( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW === $status_context ) {
			$this->render_approve_form( (int) $row['id'], $row );
			echo '<hr style="margin: 8px 0; opacity: 0.4;" />';
			$this->render_reject_form( (int) $row['id'] );
		} elseif ( ArtistUrlSubmissionsTable::STATUS_APPROVED === $status_context ) {
			if ( ! empty( $row['pipeline_id'] ) ) {
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=data-machine&pipeline_id=' . (int) $row['pipeline_id'] ) ) . '">';
				echo esc_html__( 'View pipeline', 'data-machine-events' );
				echo '</a>';
			} else {
				echo '—';
			}
		} elseif ( ArtistUrlSubmissionsTable::STATUS_REJECTED === $status_context ) {
			$reason = (string) ( $row['rejection_reason'] ?? '' );
			echo esc_html( $reason ?: __( 'Rejected (no reason)', 'data-machine-events' ) );
		} else {
			// scraping_failed — read-only.
			echo '<em>' . esc_html__( 'No actions — investigate handler coverage.', 'data-machine-events' ) . '</em>';
		}

		echo '</td>';
		echo '</tr>';
	}

	private function render_approve_form( int $submission_id, array $row ): void {
		$action_url = admin_url( 'admin-post.php' );
		$suggested_term_id   = (int) ( $row['suggested_artist_term_id'] ?? 0 );
		$suggested_name      = (string) ( $row['suggested_artist_name'] ?? '' );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display: block; margin-bottom: 6px;">
			<?php wp_nonce_field( self::NONCE_APPROVE . '_' . $submission_id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_APPROVE ); ?>" />
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission_id ); ?>" />

			<label style="display: block; margin: 2px 0;">
				<?php esc_html_e( 'Existing artist term ID:', 'data-machine-events' ); ?>
				<input type="number" name="artist_term_id" min="0" value="<?php echo esc_attr( $suggested_term_id ?: '' ); ?>" style="width: 100px;" />
			</label>
			<label style="display: block; margin: 2px 0;">
				<?php esc_html_e( 'OR new artist name:', 'data-machine-events' ); ?>
				<input type="text" name="artist_name" value="<?php echo esc_attr( $suggested_term_id ? '' : $suggested_name ); ?>" style="width: 200px;" />
			</label>
			<label style="display: block; margin: 2px 0;">
				<?php esc_html_e( 'Schedule:', 'data-machine-events' ); ?>
				<select name="schedule_interval">
					<option value="weekly" selected><?php esc_html_e( 'Weekly', 'data-machine-events' ); ?></option>
					<option value="daily"><?php esc_html_e( 'Daily', 'data-machine-events' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Monthly', 'data-machine-events' ); ?></option>
				</select>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve', 'data-machine-events' ); ?></button>
		</form>
		<?php
	}

	private function render_reject_form( int $submission_id ): void {
		$action_url = admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display: block;">
			<?php wp_nonce_field( self::NONCE_REJECT . '_' . $submission_id ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_REJECT ); ?>" />
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission_id ); ?>" />
			<label style="display: block; margin: 2px 0;">
				<?php esc_html_e( 'Rejection reason (optional):', 'data-machine-events' ); ?>
				<input type="text" name="reason" style="width: 220px;" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Reject', 'data-machine-events' ); ?></button>
		</form>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────
	// admin-post.php handlers
	// ────────────────────────────────────────────────────────────────────

	public function handle_approve_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'data-machine-events' ), 403 );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		check_admin_referer( self::NONCE_APPROVE . '_' . $submission_id );

		$ability = wp_get_ability( 'data-machine-events/approve-artist-url-submission' );
		if ( ! $ability ) {
			$this->redirect_with_flash( 'pending_review', 'error', __( 'Ability not registered.', 'data-machine-events' ) );
		}

		$result = $ability->execute(
			array(
				'submission_id'     => $submission_id,
				'artist_term_id'    => isset( $_POST['artist_term_id'] ) ? (int) $_POST['artist_term_id'] : 0,
				'artist_name'       => isset( $_POST['artist_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['artist_name'] ) ) : '',
				'schedule_interval' => isset( $_POST['schedule_interval'] ) ? sanitize_key( wp_unslash( (string) $_POST['schedule_interval'] ) ) : 'weekly',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_flash( 'pending_review', 'error', $result->get_error_message() );
		}

		$this->redirect_with_flash( 'approved', 'approved' );
	}

	public function handle_reject_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'data-machine-events' ), 403 );
		}

		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		check_admin_referer( self::NONCE_REJECT . '_' . $submission_id );

		$ability = wp_get_ability( 'data-machine-events/reject-artist-url-submission' );
		if ( ! $ability ) {
			$this->redirect_with_flash( 'pending_review', 'error', __( 'Ability not registered.', 'data-machine-events' ) );
		}

		$result = $ability->execute(
			array(
				'submission_id' => $submission_id,
				'reason'        => isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_flash( 'pending_review', 'error', $result->get_error_message() );
		}

		$this->redirect_with_flash( 'rejected', 'rejected' );
	}

	private function redirect_with_flash( string $status_filter, string $flash, string $msg = '' ): void {
		$url = admin_url( 'edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG );
		$url = add_query_arg(
			array(
				'status' => $status_filter,
				'flash'  => $flash,
				'msg'    => $msg ? rawurlencode( $msg ) : '',
			),
			$url
		);
		wp_safe_redirect( $url );
		exit;
	}
}
