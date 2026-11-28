<?php
/**
 * Sendy send transport contract with WordPress HTTP interception.
 *
 * @package DataMachineBusiness
 */

namespace DataMachine\Tests\Unit\Abilities\Sendy;

use DataMachineBusiness\Sendy\SendyClient;
use WP_UnitTestCase;

/** Send and schedule transport regression coverage. */
class SendySendCampaignTest extends WP_UnitTestCase {

	/** Verify create-only requests and text response normalization. */
	public function test_send_and_schedule_request_bodies(): void {
		$client = new SendyClient(
			array(
				'api_key'   => 'secret',
				'sendy_url' => 'https://sendy.example.test',
			)
		);
		$input  = $this->campaign();
		$reply  = 'Campaign created and now sending';
		$calls  = array();
		$mock   = static function ( $preempt, $args, $url ) use ( &$calls, &$reply ) {
			$calls[] = array( $url, $args['body'] );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $reply,
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = $client->send_campaign( $input );
			$this->assertTrue( $result['success'] );
			$this->assertFalse( $result['scheduled'] );
			$this->assertNull( $result['campaign_id'] );
			$this->assertSame( 'https://sendy.example.test/api/campaigns/create.php', $calls[0][0] );
			$this->assertSame( 1, $calls[0][1]['send_campaign'] );
			$this->assertSame( 'ListA,ListB', $calls[0][1]['list_ids'] );
			$this->assertSame( 'Newsletter title', $calls[0][1]['title'] );
			$this->assertArrayNotHasKey( 'schedule_date_time', $calls[0][1] );

			$input['exclude_list_ids']    = array( 'ExcludeA', 'ExcludeB' );
			$input['segment_ids']         = array( 'SegmentA' );
			$input['exclude_segment_ids'] = array( 'SegmentB' );
			$input['schedule_date_time']  = '2099-10-19 09:00';
			$input['schedule_timezone']   = 'America/New_York';
			$reply                        = 'Campaign scheduled';
			$result                       = $client->send_campaign( $input );
			$this->assertTrue( $result['success'] );
			$this->assertTrue( $result['scheduled'] );
			$this->assertSame( 'ExcludeA,ExcludeB', $calls[1][1]['exclude_list_ids'] );
			$this->assertSame( 'SegmentA', $calls[1][1]['segment_ids'] );
			$this->assertSame( 'SegmentB', $calls[1][1]['exclude_segments_ids'] );
			$this->assertSame( '2099-10-19 09:00', $calls[1][1]['schedule_date_time'] );
			$this->assertSame( 'America/New_York', $calls[1][1]['schedule_timezone'] );
			foreach ( array( 'Invalid API key', 'List ID is invalid', 'Campaign created', '' ) as $reply ) {
				$this->assertFalse( $client->send_campaign( $input )['success'] );
			}
			$reply = '123';
			$this->assertSame( '123', $client->send_campaign( $input )['campaign_id'] );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
	}

	/** Reject invalid requests before any remote side effects. */
	public function test_invalid_targets_and_schedules_never_call_http(): void {
		$client = new SendyClient(
			array(
				'api_key'   => 'secret',
				'sendy_url' => 'https://sendy.example.test',
			)
		);
		$calls  = 0;
		$mock   = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'unexpected_http', 'No HTTP expected.' );
		};
		add_filter( 'pre_http_request', $mock );
		try {
			$invalid = array(
				array( 'list_ids' => array() ),
				array( 'list_ids' => array( '' ) ),
				array( 'list_ids' => array( 'A,B' ) ),
				array( 'list_ids' => 'A' ),
				array( 'list_ids' => array( 1 ) ),
				array(
					'schedule_date_time' => 'tomorrow',
					'schedule_timezone'  => 'UTC',
				),
				array(
					'schedule_date_time' => '2099-02-30 09:00',
					'schedule_timezone'  => 'UTC',
				),
				array(
					'schedule_date_time' => '2099-10-19 09:01',
					'schedule_timezone'  => 'UTC',
				),
				array(
					'schedule_date_time' => '2000-10-19 09:00',
					'schedule_timezone'  => 'UTC',
				),
				array(
					'schedule_date_time' => '2099-10-19 09:00',
					'schedule_timezone'  => 'Not/AZone',
				),
				array( 'schedule_timezone' => 'UTC' ),
			);
			foreach ( $invalid as $override ) {
				$result = $client->send_campaign( array_merge( $this->campaign(), $override ) );
				$this->assertFalse( $result['success'] );
				$this->assertNotEmpty( $result['message'] );
			}
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
	}

	/**
	 * Build the shipped consumer's request shape, including title.
	 *
	 * @return array
	 */
	private function campaign(): array {
		return array(
			'from_name'  => 'Extra Chill',
			'from_email' => 'newsletter@example.com',
			'reply_to'   => 'reply@example.com',
			'subject'    => 'Subject',
			'html_text'  => '<p>Newsletter</p>',
			'plain_text' => 'Newsletter',
			'brand_id'   => '1',
			'title'      => 'Newsletter title',
			'list_ids'   => array( 'ListA', 'ListB' ),
		);
	}
}
