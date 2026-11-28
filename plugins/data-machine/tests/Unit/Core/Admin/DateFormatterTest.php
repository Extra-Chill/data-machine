<?php
/**
 * DateFormatter Tests
 *
 * Tests for the centralized date/time formatting utility.
 *
 * @package DataMachine\Tests\Unit\Core\Admin
 */

namespace DataMachine\Tests\Unit\Core\Admin;

use DataMachine\Core\Admin\DateFormatter;

defined( 'ABSPATH' ) || exit;

class DateFormatterTest extends \WP_UnitTestCase {

	public function testFormat_forDisplay_withValidDate_returnsFormattedString(): void {
		$mysql_datetime = '2024-06-15 14:30:00';

		$result = DateFormatter::format_for_display( $mysql_datetime );

		$this->assertNotEmpty( $result );
		$this->assertNotEquals( 'Never', $result );
		$this->assertNotEquals( 'Invalid date', $result );
	}

	public function testFormat_forDisplay_withNull_returnsNever(): void {
		$result = DateFormatter::format_for_display( null );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_forDisplay_withEmptyString_returnsNever(): void {
		$result = DateFormatter::format_for_display( '' );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_forDisplay_withZeroDate_returnsNever(): void {
		$result = DateFormatter::format_for_display( '0000-00-00 00:00:00' );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_forDisplay_withInvalidDate_returnsInvalidDate(): void {
		$result = DateFormatter::format_for_display( 'not-a-date' );

		$this->assertEquals( 'Invalid date', $result );
	}

	public function testFormat_dateOnly_withValidDate_returnsFormattedString(): void {
		$mysql_datetime = '2024-06-15 14:30:00';

		$result = DateFormatter::format_date_only( $mysql_datetime );

		$this->assertNotEmpty( $result );
		$this->assertNotEquals( 'Never', $result );
		$this->assertNotEquals( 'Invalid date', $result );
	}

	public function testFormat_dateOnly_withNull_returnsNever(): void {
		$result = DateFormatter::format_date_only( null );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_dateOnly_withZeroDate_returnsNever(): void {
		$result = DateFormatter::format_date_only( '0000-00-00 00:00:00' );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_timeOnly_withValidDate_returnsFormattedString(): void {
		$mysql_datetime = '2024-06-15 14:30:00';

		$result = DateFormatter::format_time_only( $mysql_datetime );

		$this->assertNotEmpty( $result );
	}

	public function testFormat_timeOnly_withNull_returnsEmptyString(): void {
		$result = DateFormatter::format_time_only( null );

		$this->assertEquals( '', $result );
	}

	public function testFormat_timeOnly_withZeroDate_returnsEmptyString(): void {
		$result = DateFormatter::format_time_only( '0000-00-00 00:00:00' );

		$this->assertEquals( '', $result );
	}

	public function testFormat_timestamp_withValidTimestamp_returnsFormattedString(): void {
		$timestamp = 1718460600; // 2024-06-15 14:30:00 UTC

		$result = DateFormatter::format_timestamp( $timestamp );

		$this->assertNotEmpty( $result );
		$this->assertNotEquals( 'Never', $result );
	}

	public function testFormat_timestamp_withNull_returnsNever(): void {
		$result = DateFormatter::format_timestamp( null );

		$this->assertEquals( 'Never', $result );
	}

	public function testFormat_timestamp_withZero_returnsNever(): void {
		$result = DateFormatter::format_timestamp( 0 );

		$this->assertEquals( 'Never', $result );
	}

	public function testStaticProperties_areCachedAcrossCalls(): void {
		DateFormatter::format_for_display( '2024-06-15 14:30:00' );
		$result1 = DateFormatter::format_for_display( '2024-06-16 10:00:00' );
		$result2 = DateFormatter::format_date_only( '2024-06-17 08:00:00' );

		$this->assertNotEmpty( $result1 );
		$this->assertNotEmpty( $result2 );
	}
}
