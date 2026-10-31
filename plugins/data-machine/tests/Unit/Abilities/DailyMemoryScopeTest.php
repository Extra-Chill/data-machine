<?php
/**
 * Coverage for #3690: daily-memory abilities derive scope from the
 * execution principal (like AgentMemoryAbilities::resolveMemory()), deny
 * cross-scope access without can_manage(), and keep destructive operations
 * (mode=write, delete) operator-only.
 *
 * @package DataMachine\Tests\Unit\Abilities
 * @see https://github.com/Extra-Chill/data-machine/issues/3690
 */

namespace DataMachine\Tests\Unit\Abilities;

use DataMachine\Abilities\DailyMemoryAbilities;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\FilesRepository\DailyMemoryStorage;
use DataMachine\Core\PluginSettings;
use WP_UnitTestCase;

class DailyMemoryScopeTest extends WP_UnitTestCase {

	/**
	 * Storage calls observed, as "user:agent:operation".
	 *
	 * @var string[]
	 */
	public static array $calls = array();

	private int $owner_id;
	private int $other_user_id;

	public function set_up(): void {
		parent::set_up();
		self::$calls = array();
		PluginSettings::update( array( 'daily_memory_enabled' => true ) );
		add_filter( 'datamachine_daily_memory_storage', array( $this, 'recordingStorage' ), 10, 3 );

		$this->owner_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down(): void {
		remove_filter( 'datamachine_daily_memory_storage', array( $this, 'recordingStorage' ), 10 );
		PermissionHelper::clear_agent_context();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function recordingStorage( $storage, int $user_id, int $agent_id ): DailyMemoryStorage {
		unset( $storage );
		return new class( $user_id, $agent_id ) implements DailyMemoryStorage {
			public function __construct( private int $user_id, private int $agent_id ) {}

			private function record( string $op ): array {
				DailyMemoryScopeTest::$calls[] = "{$this->user_id}:{$this->agent_id}:{$op}";
				return array(
					'success' => true,
					'message' => '',
					'content' => '',
				);
			}

			public function read( string $year, string $month, string $day ): array {
				return $this->record( 'read' );
			}

			public function write( string $year, string $month, string $day, string $content ): array {
				return $this->record( 'write' );
			}

			public function append( string $year, string $month, string $day, string $content ): array {
				return $this->record( 'append' );
			}

			public function delete( string $year, string $month, string $day ): array {
				return $this->record( 'delete' );
			}

			public function list_all(): array {
				return $this->record( 'list' ) + array( 'months' => array() );
			}

			public function search( string $query, ?string $from = null, ?string $to = null, int $context_lines = 2 ): array {
				return $this->record( 'search' ) + array( 'matches' => array() );
			}
		};
	}

	/**
	 * Act as agent 42 owned by $this->owner_id, with a token ceiling that
	 * excludes Data Machine management.
	 */
	private function actAsScopedAgent(): void {
		PermissionHelper::set_agent_context( 42, $this->owner_id, array( 'datamachine_chat' ), 7 );
	}

	/**
	 * Act as agent 42 owned by an administrator with an unrestricted token.
	 */
	private function actAsOperatorAgent(): void {
		$admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->owner_id = $admin_id;
		PermissionHelper::set_agent_context( 42, $admin_id, null, 7 );
	}

	public function test_principal_scope_replaces_omitted_input(): void {
		$this->actAsScopedAgent();

		$this->assertSame(
			array(
				'user_id'  => $this->owner_id,
				'agent_id' => 42,
			),
			DailyMemoryAbilities::resolveScope( array() )
		);

		$this->assertNotWPError( DailyMemoryAbilities::writeDaily( array( 'content' => 'note' ) ) );
		$this->assertNotWPError( DailyMemoryAbilities::readDaily( array() ) );
		$this->assertNotWPError( DailyMemoryAbilities::listDaily( array() ) );
		$this->assertNotWPError( DailyMemoryAbilities::searchDaily( array( 'query' => 'x' ) ) );

		$expected = $this->owner_id . ':42:';
		foreach ( self::$calls as $call ) {
			$this->assertStringStartsWith( $expected, $call );
		}
		$this->assertCount( 4, self::$calls );
	}

	public function test_matching_explicit_scope_is_allowed(): void {
		$this->actAsScopedAgent();

		$this->assertNotWPError(
			DailyMemoryAbilities::writeDaily(
				array(
					'content'  => 'note',
					'user_id'  => $this->owner_id,
					'agent_id' => 42,
				)
			)
		);
		$this->assertSame( array( $this->owner_id . ':42:append' ), self::$calls );
	}

	public function test_principal_cannot_reach_another_users_or_agents_memory(): void {
		$this->actAsScopedAgent();

		$cross_scopes = array(
			array( 'user_id' => $this->other_user_id ),
			array( 'agent_id' => 99 ),
			array(
				'user_id'  => $this->other_user_id,
				'agent_id' => 99,
			),
		);

		foreach ( $cross_scopes as $scope ) {
			foreach ( array(
				DailyMemoryAbilities::readDaily( $scope ),
				DailyMemoryAbilities::writeDaily( $scope + array( 'content' => 'x' ) ),
				DailyMemoryAbilities::listDaily( $scope ),
				DailyMemoryAbilities::searchDaily( $scope + array( 'query' => 'x' ) ),
			) as $result ) {
				$this->assertWPError( $result );
				$this->assertSame( 'daily_memory_scope_denied', $result->get_error_code() );
			}
		}

		$this->assertSame( array(), self::$calls, 'No storage may be touched for a denied scope.' );
	}

	public function test_overwrite_and_delete_are_operator_only(): void {
		$this->actAsScopedAgent();

		$write = DailyMemoryAbilities::writeDaily(
			array(
				'content' => 'replace everything',
				'mode'    => 'write',
			)
		);
		$this->assertWPError( $write );
		$this->assertSame( 'daily_memory_operator_only', $write->get_error_code() );

		$delete = DailyMemoryAbilities::deleteDaily( array( 'date' => '2026-10-30' ) );
		$this->assertWPError( $delete );
		$this->assertSame( 'daily_memory_operator_only', $delete->get_error_code() );

		$this->assertSame( array(), self::$calls );
	}

	public function test_operator_principal_can_target_any_scope_and_run_destructive_operations(): void {
		$this->actAsOperatorAgent();

		$this->assertNotWPError(
			DailyMemoryAbilities::writeDaily(
				array(
					'content'  => 'x',
					'mode'     => 'write',
					'user_id'  => $this->other_user_id,
					'agent_id' => 99,
				)
			)
		);
		$this->assertNotWPError(
			DailyMemoryAbilities::deleteDaily(
				array(
					'date'     => '2026-10-30',
					'agent_id' => 99,
				)
			)
		);

		$this->assertSame(
			array(
				$this->other_user_id . ':99:write',
				$this->owner_id . ':99:delete',
			),
			self::$calls
		);
	}

	public function test_without_a_principal_operators_keep_the_requested_scope(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertSame(
			array(
				'user_id'  => $this->other_user_id,
				'agent_id' => 99,
			),
			DailyMemoryAbilities::resolveScope(
				array(
					'user_id'  => $this->other_user_id,
					'agent_id' => 99,
				)
			)
		);
		$this->assertNotWPError( DailyMemoryAbilities::deleteDaily( array( 'agent_id' => 99 ) ) );
		$this->assertSame( array( '0:99:delete' ), self::$calls );
	}

	public function test_non_operator_without_a_principal_cannot_overwrite_or_delete(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$this->assertWPError(
			DailyMemoryAbilities::writeDaily(
				array(
					'content' => 'x',
					'mode'    => 'write',
				)
			)
		);
		$this->assertWPError( DailyMemoryAbilities::deleteDaily( array() ) );
	}
}
