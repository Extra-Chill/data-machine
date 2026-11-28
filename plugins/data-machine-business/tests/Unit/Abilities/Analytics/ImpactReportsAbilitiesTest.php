<?php
/**
 * Impact affiliate reporting contract tests.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\ImpactReportsAbilities;
use DataMachineBusiness\Abilities\Revenue\RevenueLedger;
use DataMachineBusiness\Abilities\Revenue\RevenueLedgerAbilities;
use WP_UnitTestCase;

class ImpactReportsAbilitiesTest extends WP_UnitTestCase {

	private const SID   = 'IRtestSID123';
	private const TOKEN = 'tok_DO_NOT_ECHO';

	/** @var string[] */
	private array $requests = array();

	/** @var callable */
	private $responder;

	public function set_up(): void {
		parent::set_up();
		RevenueLedger::create_table();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RevenueLedger::table_name() ) );
		update_option( ImpactReportsAbilities::CONFIG_OPTION, array(
			'account_sid' => self::SID,
			'auth_token'  => self::TOKEN,
		) );
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( ImpactReportsAbilities::CONFIG_OPTION );
		parent::tear_down();
	}

	public function intercept( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'auth' => $args['headers']['Authorization'] ?? '',
		);
		$body = ( $this->responder )( $url );
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => is_array( $body ) && isset( $body['__status'] ) ? $body['__status'] : 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private static function action( string $id, string $event, string $payout, string $state = 'APPROVED', string $campaign = 'Ticketmaster' ): array {
		return array(
			'Id'           => $id,
			'CampaignId'   => '4272',
			'CampaignName' => $campaign,
			'State'        => $state,
			'Payout'       => $payout,
			'Amount'       => '20.00',
			'Currency'     => 'USD',
			'EventDate'    => $event,
		);
	}

	public function test_chunk_windows_never_exceed_45_days_and_cover_range(): void {
		$chunks = ImpactReportsAbilities::chunkWindows( '2025-11-25', '2026-11-24' );

		$this->assertSame( '2025-11-25', $chunks[0]['start'] );
		$this->assertSame( '2026-11-24', end( $chunks )['end'] );
		$previous_end = null;
		foreach ( $chunks as $chunk ) {
			$days = ( strtotime( $chunk['end'] . ' UTC' ) - strtotime( $chunk['start'] . ' UTC' ) ) / 86400 + 1;
			$this->assertLessThanOrEqual( 45, $days );
			if ( null !== $previous_end ) {
				$this->assertSame( gmdate( 'Y-m-d', strtotime( $previous_end . ' UTC' ) + 86400 ), $chunk['start'], 'chunks are contiguous' );
			}
			$previous_end = $chunk['end'];
		}
		$this->assertCount( 1, ImpactReportsAbilities::chunkWindows( '2026-10-01', '2026-10-01' ) );
	}

	public function test_aggregate_buckets_by_utc_day_and_excludes_reversed_from_amount(): void {
		$result = ImpactReportsAbilities::aggregateActions(
			array(
				self::action( 'a1', '2026-10-01T05:09:31-07:00', '0.10' ),
				self::action( 'a2', '2026-10-01T20:00:00-07:00', '1.25', 'PENDING' ), // 2026-10-02 03:00 UTC.
				self::action( 'a3', '2026-10-01T09:00:00-07:00', '2.00', 'REVERSED' ),
				self::action( 'a1', '2026-10-01T05:09:31-07:00', '0.10' ), // Duplicate id.
				self::action( 'a4', '2026-09-30T12:00:00Z', '9.99' ), // Outside window.
			),
			'2026-10-01',
			'2026-10-31'
		);

		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( '2026-10-01', $result['rows'][0]['period_start'] );
		$this->assertSame( 'affiliate_commission', $result['rows'][0]['kind'] );
		$this->assertSame( 'Ticketmaster', $result['rows'][0]['object_ref'] );
		$this->assertSame( 0.10, (float) $result['rows'][0]['amount'] );
		$this->assertSame( 2, $result['rows'][0]['metrics']['actions'] );
		$this->assertSame( 1, $result['rows'][0]['metrics']['states']['reversed']['actions'] );
		$this->assertSame( '2026-10-02', $result['rows'][1]['period_start'] );
		$this->assertSame( 1.25, (float) $result['rows'][1]['amount'] );
		$this->assertSame( 1.35, (float) $result['totals'][0]['amount'] );
		$this->assertSame( 1, $result['outside_window'] );
		$this->assertSame( 4, $result['aggregated'] );
	}

	public function test_fetch_chunks_requests_and_follows_next_page_uri(): void {
		$this->responder = static function ( string $url ): array {
			if ( str_contains( $url, 'Page=2' ) ) {
				return array(
					'@nextpageuri' => '',
					'Actions'      => array( self::action( 'p2', '2026-10-05T12:00:00Z', '3.00' ) ),
				);
			}
			if ( str_contains( $url, 'ActionDateStart=2026-10-01' ) ) {
				return array(
					'@nextpageuri' => '/Mediapartners/' . self::SID . '/Actions?Page=2&ActionDateStart=2026-10-01T00%3A00%3A00Z',
					'Actions'      => array( self::action( 'p1', '2026-10-01T12:00:00Z', '1.00' ) ),
				);
			}
			return array(
				'@nextpageuri' => '',
				'Actions'      => array( self::action( 'c2', '2026-11-20T12:00:00Z', '0.50' ) ),
			);
		};

		$result = ImpactReportsAbilities::fetch( array(
			'start_date' => '2026-10-01',
			'end_date'   => '2026-11-30',
		) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertCount( 3, $this->requests, 'two chunks, first chunk paginated' );
		$this->assertStringContainsString( 'ActionDateEnd=2026-11-15T00%3A00%3A00Z', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'ActionDateStart=2026-11-15', $this->requests[2]['url'] );
		$this->assertSame( 'Basic ' . base64_encode( self::SID . ':' . self::TOKEN ), $this->requests[0]['auth'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( 4.5, (float) $result['totals'][0]['amount'] );
		$this->assertCount( 2, $result['provenance']['chunks'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $result ) );
	}

	public function test_store_is_idempotent_and_queryable(): void {
		$payout          = '1.00';
		$this->responder = static function () use ( &$payout ): array {
			return array(
				'@nextpageuri' => '',
				'Actions'      => array(
					self::action( 's1', '2026-10-03T12:00:00Z', $payout ),
					self::action( 's2', '2026-10-04T12:00:00Z', '2.00' ),
				),
			);
		};
		$input = array(
			'start_date' => '2026-10-01',
			'end_date'   => '2026-10-31',
			'store'      => true,
		);

		$dry = ImpactReportsAbilities::fetch( array_merge( $input, array( 'dry_run' => true ) ) );
		$this->assertIsArray( $dry );
		$this->assertTrue( $dry['ledger']['dry_run'] );
		$this->assertSame( 0, $this->ledgerTotalRows() );

		$first = ImpactReportsAbilities::fetch( $input );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertSame( 2, $first['ledger']['inserted'] );

		$payout = '1.50';
		$second = ImpactReportsAbilities::fetch( $input );
		$this->assertIsArray( $second );
		$this->assertSame( 2, $second['ledger']['replaced'] );
		$this->assertSame( 2, $this->ledgerTotalRows() );

		$query = RevenueLedgerAbilities::query( array(
			'source'     => 'impact',
			'start_date' => '2026-10-01',
			'end_date'   => '2026-10-31',
		) );
		$this->assertIsArray( $query );
		$this->assertSame( 3.5, (float) $query['totals'][0]['amount'] );
		$this->assertSame( 'affiliate_commission', $query['totals'][0]['kind'] );
	}

	public function test_errors_are_clear_and_never_echo_the_token(): void {
		$this->responder = static fn() => array(
			'__status' => 400,
			'Status'   => 'ERROR',
			'Message'  => 'bad request ' . self::TOKEN,
		);
		$error = ImpactReportsAbilities::fetch( array(
			'start_date' => '2026-10-01',
			'end_date'   => '2026-10-02',
		) );
		$this->assertWPError( $error );
		$this->assertSame( 'impact_api_error', $error->get_error_code() );
		$this->assertStringNotContainsString( self::TOKEN, $error->get_error_message() );

		$this->assertSame( 'impact_invalid_window', ImpactReportsAbilities::fetch( array(
			'start_date' => '2026-10-05',
			'end_date'   => '2026-10-01',
		) )->get_error_code() );
		$this->assertSame( 'impact_window_too_long', ImpactReportsAbilities::fetch( array(
			'start_date' => '2025-01-01',
			'end_date'   => '2026-11-01',
		) )->get_error_code() );

		delete_option( ImpactReportsAbilities::CONFIG_OPTION );
		$this->assertSame( 'impact_not_configured', ImpactReportsAbilities::fetch( array(
			'start_date' => '2026-10-01',
			'end_date'   => '2026-10-02',
		) )->get_error_code() );
	}

	private function ledgerTotalRows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s', RevenueLedger::table_name(), 'impact' ) );
	}
}
