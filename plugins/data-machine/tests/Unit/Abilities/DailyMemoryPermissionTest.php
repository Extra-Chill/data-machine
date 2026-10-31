<?php
/**
 * Coverage for #3698: daily-memory read/append/list/search are gated on the
 * grantable `daily_memory` action (`datamachine_daily_memory`). Scope still
 * comes from the execution principal (#3690); overwrite and delete stay
 * operator-only; a non-operator with no principal is denied.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @see https://github.com/Extra-Chill/data-machine/issues/3698
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\DailyMemoryAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\Bootstrap\ActivationServiceProvider;
use DataMachine\Core\FilesRepository\DailyMemoryStorage;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class DailyMemoryPermissionTest extends WP_UnitTestCase {

	private const GRANTED = array(
		'datamachine/daily-memory-read',
		'datamachine/daily-memory-write',
		'datamachine/daily-memory-list',
		'datamachine/search-daily-memory',
	);

	/**
	 * Day contents keyed by "user:agent:date".
	 *
	 * @var array<string, string>
	 */
	public static array $days = array();

	private int $member_id;

	public function set_up(): void {
		parent::set_up();
		self::$days = array();
		PluginSettings::update( array( 'daily_memory_enabled' => true ) );
		new DailyMemoryAbilities();
		add_filter( 'datamachine_daily_memory_storage', array( $this, 'memoryStorage' ), 10, 3 );

		$this->member_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $this->member_id )->add_cap( 'datamachine_daily_memory' );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_daily_memory_storage', array( $this, 'memoryStorage' ), 10 );
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function memoryStorage( $storage, int $user_id, int $agent_id ): DailyMemoryStorage {
		unset( $storage );
		return new class( "$user_id:$agent_id" ) implements DailyMemoryStorage {
			public function __construct( private string $scope ) {}

			public function read( string $year, string $month, string $day ): array {
				$key = "{$this->scope}:$year-$month-$day";
				return array(
					'success' => true,
					'date'    => "$year-$month-$day",
					'content' => DailyMemoryPermissionTest::$days[ $key ] ?? '',
				);
			}

			public function write( string $year, string $month, string $day, string $content ): array {
				DailyMemoryPermissionTest::$days[ "{$this->scope}:$year-$month-$day" ] = $content;
				return array( 'success' => true, 'message' => '' );
			}

			public function append( string $year, string $month, string $day, string $content ): array {
				$key                                     = "{$this->scope}:$year-$month-$day";
				DailyMemoryPermissionTest::$days[ $key ] = ( DailyMemoryPermissionTest::$days[ $key ] ?? '' ) . $content;
				return array( 'success' => true, 'message' => '' );
			}

			public function delete( string $year, string $month, string $day ): array {
				unset( DailyMemoryPermissionTest::$days[ "{$this->scope}:$year-$month-$day" ] );
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

	/** The member's agent session: token ceiling includes the daily-memory cap. */
	private function actAsMemberAgent(): void {
		PermissionHelper::set_agent_context( 42, $this->member_id, array( 'datamachine_daily_memory' ), 7 );
	}

	public function test_member_agent_passes_read_append_list_search_but_not_delete(): void {
		$this->actAsMemberAgent();

		$this->assertTrue( PermissionHelper::can( 'daily_memory' ) );
		$this->assertFalse( PermissionHelper::can_manage() );
		foreach ( self::GRANTED as $name ) {
			$this->assertTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
		$this->assertNotTrue( wp_get_ability( 'datamachine/daily-memory-delete' )->check_permissions( array() ) );
	}

	public function test_member_agent_appends_and_reads_back_its_own_memory(): void {
		$this->actAsMemberAgent();

		$this->assertNotWPError(
			wp_get_ability( 'datamachine/daily-memory-write' )->execute(
				array(
					'date'    => '2026-10-30',
					'content' => 'Neal Francis giveaway closed out.',
				)
			)
		);
		$read = wp_get_ability( 'datamachine/daily-memory-read' )->execute( array( 'date' => '2026-10-30' ) );

		$this->assertNotWPError( $read );
		$this->assertSame( 'Neal Francis giveaway closed out.', $read['content'] );
		$this->assertArrayHasKey( $this->member_id . ':42:2026-10-30', self::$days );
	}

	public function test_member_agent_cannot_reach_other_scopes_overwrite_or_delete(): void {
		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->actAsMemberAgent();

		$cross = DailyMemoryAbilities::writeDaily(
			array(
				'content' => 'x',
				'user_id' => $other_id,
			)
		);
		$this->assertSame( 'daily_memory_scope_denied', $cross->get_error_code() );

		$overwrite = DailyMemoryAbilities::writeDaily(
			array(
				'content' => 'x',
				'mode'    => 'write',
			)
		);
		$this->assertSame( 'daily_memory_operator_only', $overwrite->get_error_code() );
		$this->assertSame( 'daily_memory_operator_only', DailyMemoryAbilities::deleteDaily( array() )->get_error_code() );
		$this->assertSame( array(), self::$days );
	}

	public function test_member_without_a_principal_is_denied(): void {
		wp_set_current_user( $this->member_id );

		$this->assertTrue( PermissionHelper::can( 'daily_memory' ) );
		$result = DailyMemoryAbilities::readDaily(
			array(
				'user_id'  => 1,
				'agent_id' => 1,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'daily_memory_principal_required', $result->get_error_code() );
	}

	public function test_token_ceiling_without_the_cap_denies(): void {
		PermissionHelper::set_agent_context( 42, $this->member_id, array( 'datamachine_chat' ), 7 );

		$this->assertFalse( PermissionHelper::can( 'daily_memory' ) );
	}

	public function test_users_without_the_cap_are_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( self::GRANTED as $name ) {
			$this->assertNotTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
	}

	public function test_operators_keep_everything(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array_merge( self::GRANTED, array( 'datamachine/daily-memory-delete' ) ) as $name ) {
			$this->assertTrue( wp_get_ability( $name )->check_permissions( array() ), $name );
		}
		$this->assertNotWPError(
			DailyMemoryAbilities::writeDaily(
				array(
					'content'  => 'x',
					'mode'     => 'write',
					'agent_id' => 99,
				)
			)
		);
	}

	public function test_activation_grants_the_cap_to_administrators_only(): void {
		ActivationServiceProvider::register_capabilities();

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'datamachine_daily_memory' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'datamachine_daily_memory' ) );
	}
}
