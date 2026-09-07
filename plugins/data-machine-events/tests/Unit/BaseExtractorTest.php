<?php
/**
 * Base extractor contract tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\BaseExtractor;
use ReflectionClass;
use WP_UnitTestCase;

class BaseExtractorTest extends WP_UnitTestCase {

	public function test_declares_extractor_interface_methods_as_abstract(): void {
		$reflection = new ReflectionClass( BaseExtractor::class );

		$this->assertTrue( $reflection->isAbstract() );

		foreach ( array( 'canExtract', 'extract', 'getMethod' ) as $method_name ) {
			$method = $reflection->getMethod( $method_name );

			$this->assertSame( BaseExtractor::class, $method->getDeclaringClass()->getName() );
			$this->assertTrue( $method->isPublic() );
			$this->assertTrue( $method->isAbstract() );
		}
	}

	/**
	 * inferDateFromMonthDay() keeps the current year for a date only
	 * slightly in the past (grace window) instead of fabricating a show
	 * ~12 months out (#770).
	 */
	public function test_infer_date_from_month_day_keeps_recent_past_within_grace_window(): void {
		$now = new \DateTimeImmutable( '2026-09-07 00:00:00' );

		$this->assertSame( '2026-09-06', $this->inferDate( 'September', '6', $now ), '1 day in the past keeps the current year.' );
		$this->assertSame( '2026-08-24', $this->inferDate( 'August', '24', $now ), 'Exactly 14 days in the past keeps the current year (boundary).' );
	}

	/**
	 * Dates older than the grace window still roll forward to next year.
	 */
	public function test_infer_date_from_month_day_rolls_old_past_dates_forward(): void {
		$now = new \DateTimeImmutable( '2026-09-07 00:00:00' );

		$this->assertSame( '2027-08-23', $this->inferDate( 'August', '23', $now ), '15 days in the past rolls to next year.' );
		$this->assertSame( '2027-03-01', $this->inferDate( 'March', '1', $now ), 'Months-old dates roll to next year.' );
	}

	/**
	 * Future dates keep the current year.
	 */
	public function test_infer_date_from_month_day_keeps_future_dates_in_current_year(): void {
		$now = new \DateTimeImmutable( '2026-09-07 00:00:00' );

		$this->assertSame( '2026-09-10', $this->inferDate( 'September', '10', $now ) );
		$this->assertSame( '2026-12-31', $this->inferDate( 'December', '31', $now ) );
	}

	/**
	 * Invoke the protected inferDateFromMonthDay() with an injected "now"
	 * so expectations are deterministic.
	 */
	private function inferDate( string $month, string $day, \DateTimeImmutable $now ): string {
		$reflection = new ReflectionClass( BaseExtractor::class );
		$method     = $reflection->getMethod( 'inferDateFromMonthDay' );
		$method->setAccessible( true );

		$extractor = new class() extends BaseExtractor {
			public function canExtract( string $html ): bool {
				return false;
			}
			public function extract( string $html, string $source_url ): array {
				return array();
			}
			public function getMethod(): string {
				return 'test';
			}
		};

		return $method->invoke( $extractor, $month, $day, $now );
	}
}
