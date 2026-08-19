<?php
/**
 * Daily memory ability boundary tests.
 *
 * @package DataMachine\Tests\Unit\Abilities
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\DailyMemoryAbilities;
use DataMachine\Core\FilesRepository\DailyMemoryStorage;
use WP_UnitTestCase;

class DailyMemoryAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'datamachine_daily_memory_storage', array( $this, 'failureStorage' ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_daily_memory_storage', array( $this, 'failureStorage' ) );
		parent::tear_down();
	}

	public function failureStorage(): DailyMemoryStorage {
		return new class() implements DailyMemoryStorage {
			public function read( string $year, string $month, string $day ): array {
				return array( 'success' => true );
			}

			public function write( string $year, string $month, string $day, string $content ): array {
				return array( 'success' => true, 'message' => '' );
			}

			public function append( string $year, string $month, string $day, string $content ): array {
				return array( 'success' => true, 'message' => '' );
			}

			public function delete( string $year, string $month, string $day ): array {
				return array( 'success' => true, 'message' => '' );
			}

			public function list_all(): array {
				return array( 'success' => false, 'message' => 'List backend unavailable.', 'backend' => 'test' );
			}

			public function search( string $query, ?string $from = null, ?string $to = null, int $context_lines = 2 ): array {
				return array( 'success' => false, 'message' => 'Search backend unavailable.', 'query' => $query );
			}
		};
	}

	public function test_list_backend_failure_returns_wp_error(): void {
		$result = DailyMemoryAbilities::listDaily( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'daily_memory_list_failed', $result->get_error_code() );
		$this->assertSame( 'test', $result->get_error_data()['backend'] );
	}

	public function test_search_backend_failure_returns_wp_error(): void {
		$result = DailyMemoryAbilities::searchDaily( array( 'query' => 'needle' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'daily_memory_search_failed', $result->get_error_code() );
		$this->assertSame( 'needle', $result->get_error_data()['query'] );
	}
}
