<?php
/**
 * Coverage for #3690: daily memory read/write/list/search is gated on a
 * dedicated `daily_memory` permission action (`datamachine_daily_memory`
 * capability) instead of can_manage(). Delete stays operator-only.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @see https://github.com/Extra-Chill/data-machine/issues/3690
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\DailyMemoryAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Bootstrap\ActivationServiceProvider;
use DataMachine\Core\FilesRepository\DailyMemoryStorage;
use WP_UnitTestCase;

class DailyMemoryPermissionTest extends WP_UnitTestCase {

	private const GRANTED = array(
		'datamachine/daily-memory-read',
		'datamachine/daily-memory-write',
		'datamachine/daily-memory-list',
		'datamachine/search-daily-memory',
	);

	/**
	 * In-memory storage so execution never touches the filesystem.
	 *
	 * @var array<string, string>
	 */
	public static array $days = array();

	public function set_up(): void {
		parent::set_up();
		self::$days = array();
		new DailyMemoryAbilities();
		add_filter( 'datamachine_daily_memory_storage', array( $this, 'memoryStorage' ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_daily_memory_storage', array( $this, 'memoryStorage' ) );
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function memoryStorage(): DailyMemoryStorage {
		return new class() implements DailyMemoryStorage {
			public function read( string $year, string $month, string $day ): array {
				$key = "$year-$month-$day";
				return array(
					'success' => isset( DailyMemoryPermissionTest::$days[ $key ] ),
					'date'    => $key,
					'content' => DailyMemoryPermissionTest::$days[ $key ] ?? '',
					'message' => '',
				);
			}

			public function write( string $year, string $month, string $day, string $content ): array {
				DailyMemoryPermissionTest::$days[ "$year-$month-$day" ] = $content;
				return array( 'success' => true, 'message' => '' );
			}

			public function append( string $year, string $month, string $day, string $content ): array {
				$key                                     = "$year-$month-$day";
				DailyMemoryPermissionTest::$days[ $key ] = ( DailyMemoryPermissionTest::$days[ $key ] ?? '' ) . $content;
				return array( 'success' => true, 'message' => '' );
			}

			public function delete( string $year, string $month, string $day ): array {
				unset( DailyMemoryPermissionTest::$days[ "$year-$month-$day" ] );
				return array( 'success' => true, 'message' => '' );
			}

			public function list_all(): array {
				return array( 'success' => true, 'months' => array() );
			}

			public function search( string $query, ?string $from = null, ?string $to = null, int $context_lines = 2 ): array {
				return array( 'success' => true, 'matches' => array(), 'query' => $query );
			}
		};
	}

	private function user_with( array $caps, string $role = 'editor' ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$user    = get_user_by( 'id', $user_id );
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $user_id );
		return $user_id;
	}

	public function test_dedicated_cap_grants_read_write_list_search_but_not_delete(): void {
		$this->user_with( array( 'datamachine_daily_memory' ) );

		$this->assertTrue( PermissionHelper::can( 'daily_memory' ) );
		$this->assertFalse( PermissionHelper::can_manage() );

		foreach ( self::GRANTED as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$this->assertTrue( $ability->check_permissions( array() ), $name );
		}

		$this->assertNotTrue( wp_get_ability( 'datamachine/daily-memory-delete' )->check_permissions( array() ) );
	}

	public function test_cap_holder_can_append_and_read_back_through_the_abilities(): void {
		\DataMachine\Core\PluginSettings::update( array( 'daily_memory_enabled' => true ) );
		$this->user_with( array( 'datamachine_daily_memory' ) );

		$write = wp_get_ability( 'datamachine/daily-memory-write' )->execute(
			array(
				'date'    => '2026-10-30',
				'content' => 'Neal Francis giveaway closed out.',
				'mode'    => 'append',
			)
		);
		$this->assertNotWPError( $write );

		$read = wp_get_ability( 'datamachine/daily-memory-read' )->execute( array( 'date' => '2026-10-30' ) );
		$this->assertNotWPError( $read );
		$this->assertStringContainsString( 'Neal Francis giveaway', (string) wp_json_encode( $read ) );
	}

	public function test_users_without_the_cap_are_denied(): void {
		$this->user_with( array( 'datamachine_chat' ), 'contributor' );

		$this->assertFalse( PermissionHelper::can( 'daily_memory' ) );
		foreach ( self::GRANTED as $name ) {
			$this->assertNotTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
	}

	public function test_management_holders_keep_access(): void {
		$this->user_with( array( 'datamachine_manage_flows' ), 'subscriber' );

		$this->assertTrue( PermissionHelper::can( 'daily_memory' ) );
		foreach ( self::GRANTED as $name ) {
			$this->assertTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
		$this->assertTrue( wp_get_ability( 'datamachine/daily-memory-delete' )->check_permissions( array() ) );
	}

	public function test_agent_token_ceiling_bounds_daily_memory(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		PermissionHelper::set_agent_context( 123, $owner_id, array( 'datamachine_daily_memory' ), 456 );
		$this->assertTrue( PermissionHelper::can( 'daily_memory' ) );
		$this->assertFalse( PermissionHelper::can( 'manage_flows' ) );

		PermissionHelper::clear_agent_context();
		PermissionHelper::set_agent_context( 123, $owner_id, array( 'datamachine_chat' ), 456 );
		$this->assertFalse( PermissionHelper::can( 'daily_memory' ) );
	}

	public function test_activation_grants_the_cap_to_administrators_only(): void {
		ActivationServiceProvider::register_capabilities();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'datamachine_daily_memory' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'datamachine_daily_memory' ) );
	}
}
