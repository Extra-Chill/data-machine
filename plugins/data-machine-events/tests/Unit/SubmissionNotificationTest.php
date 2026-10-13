<?php
/**
 * Submission publish-notification tests (#918).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\SubmissionNotification;
use WP_Error;
use WP_UnitTestCase;

class SubmissionNotificationTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		parent::tear_down();
	}

	/**
	 * Short-circuit delivery and record the message.
	 *
	 * @param mixed                $short_circuit Existing short-circuit value.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, array $atts ): bool {
		unset( $short_circuit );
		$this->sent[] = $atts;
		return true;
	}

	public function test_wp_error_result_is_reported_as_failure(): void {
		$message = SubmissionNotification::failure_message( new WP_Error( 'ability_invalid_permissions', 'Denied.' ) );

		$this->assertSame( 'ability_invalid_permissions: Denied.', $message );
	}

	public function test_array_results_are_classified(): void {
		$this->assertNull( SubmissionNotification::failure_message( array( 'success' => true ) ) );
		$this->assertSame( 'smtp down', SubmissionNotification::failure_message( array( 'success' => false, 'error' => 'smtp down' ) ) );
		$this->assertSame( 'unknown error', SubmissionNotification::failure_message( array( 'success' => false ) ) );
	}

	public function test_unexpected_result_type_is_reported(): void {
		$this->assertSame( 'unexpected result type NULL', SubmissionNotification::failure_message( null ) );
	}

	/**
	 * A publisher without Data Machine capabilities must not silently drop
	 * the submitter's email: the send runs as the system.
	 */
	public function test_publish_by_user_without_datamachine_caps_still_sends(): void {
		if ( ! function_exists( 'wp_get_ability' ) || null === wp_get_ability( 'datamachine/send-email' ) ) {
			$this->markTestSkipped( 'datamachine/send-email ability is not registered in this environment.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'pending',
				'post_title'  => 'Submitted Show',
			)
		);
		update_post_meta( $post_id, '_datamachine_submitter_email', 'submitter@example.org' );
		update_post_meta( $post_id, '_datamachine_submitter_name', 'Sam' );

		SubmissionNotification::on_status_transition( 'publish', 'pending', get_post( $post_id ) );

		$this->assertCount( 1, $this->sent, 'The publish email was not handed to wp_mail().' );
		$to = (array) $this->sent[0]['to'];
		$this->assertContains( 'submitter@example.org', $to );
		$this->assertStringContainsString( 'Submitted Show', (string) $this->sent[0]['subject'] );
	}
}
