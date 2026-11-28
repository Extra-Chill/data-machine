<?php
/**
 * Database-backed regression tests for daily-memory context scope (#3487).
 *
 * Proves that DailyMemoryTask context collection — and the owning-layer
 * storage reads it delegates to — is bound to the authorized agent/user
 * principal before any record reaches the model:
 *
 * - Intended inclusion: same-agent, same-user, same-day jobs.
 * - Cross-agent exclusion, including agents that share an owner.
 * - Cross-user exclusion, including users that share an agent.
 * - Calendar-day date bounds on both window edges.
 * - The explicitly authorized agent-wide aggregate path (exactly its
 *   documented records; ordinary compaction does not inherit that breadth).
 *
 * Uses persisted rows and the real query method (Jobs::get_jobs_for_day) so
 * the database predicates themselves are under test.
 *
 * @package DataMachine\Tests\Unit\Engine\AI\System\Tasks
 */

namespace DataMachine\Tests\Unit\Engine\AI\System\Tasks;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\System\Tasks\DailyMemoryTask;
use WP_UnitTestCase;

class DailyMemoryTaskContextScopeTest extends WP_UnitTestCase {

	private const DAY     = '2026-03-10';
	private const EARLIER = '2026-03-09 23:59:59';
	private const LATER   = '2026-03-11 00:00:00';

	private int $owner_user_id;
	private int $other_user_id;
	private int $agent_alpha_id;
	private int $agent_beta_id;

	private Jobs $jobs_db;

	/** Job ids by fixture key. */
	private array $job_ids = array();

	public function set_up(): void {
		parent::set_up();
		datamachine_test_prepare_site();

		$this->jobs_db = new Jobs();

		$this->owner_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$agents_repo = new Agents();

		// Alpha is owned by the owner user. Beta shares that owner so the
		// cross-agent exclusion case is exercised at its hardest: two agents,
		// same user.
		$this->agent_alpha_id = $agents_repo->create_if_missing( 'daily-scope-alpha', 'Daily Scope Alpha', $this->owner_user_id );
		$this->agent_beta_id  = $agents_repo->create_if_missing( 'daily-scope-beta', 'Daily Scope Beta', $this->owner_user_id );

		$this->seed_jobs();
	}

	// -----------------------------------------------------------------
	// Job storage predicates
	// -----------------------------------------------------------------

	public function test_jobs_day_read_includes_principal_and_excludes_cross_agent_and_cross_user(): void {
		$rows = $this->jobs_db->get_jobs_for_day(
			self::DAY,
			array(
				'user_id'  => $this->owner_user_id,
				'agent_id' => $this->agent_alpha_id,
			)
		);

		$this->assertSame( array( $this->job_ids['alpha_owner_day'] ), array_map( 'intval', array_column( $rows, 'job_id' ) ) );
		$this->assertSame( 'Alpha owner day job', $rows[0]['label'] );
		$this->assertSame( (string) $this->agent_alpha_id, (string) $rows[0]['agent_id'] );
	}

	public function test_jobs_day_read_excludes_adjacent_days(): void {
		$rows = $this->jobs_db->get_jobs_for_day(
			self::DAY,
			array(
				'user_id'  => $this->owner_user_id,
				'agent_id' => $this->agent_alpha_id,
			)
		);

		$labels = array_column( $rows, 'label' );
		$this->assertNotContains( 'Alpha owner earlier job', $labels );
		$this->assertNotContains( 'Beta other-user later job', $labels );

		// The earlier/later rows are visible under their own days, proving
		// the exclusion is the day window and not the scope.
		$this->assertSame(
			array( 'Alpha owner earlier job' ),
			array_column( $this->jobs_db->get_jobs_for_day( '2026-03-09', array() ), 'label' )
		);
		$this->assertSame(
			array( 'Beta other-user later job' ),
			array_column( $this->jobs_db->get_jobs_for_day( '2026-03-11', array() ), 'label' )
		);
	}

	public function test_jobs_agent_wide_aggregate_includes_exactly_that_agent(): void {
		$rows = $this->jobs_db->get_jobs_for_day( self::DAY, array( 'agent_id' => $this->agent_alpha_id ) );

		$this->assertSame(
			array( 'Alpha owner day job', 'Alpha other-user day job' ),
			array_column( $rows, 'label' )
		);
	}

	public function test_jobs_site_wide_day_aggregate_remains_deliberately_available(): void {
		$rows = $this->jobs_db->get_jobs_for_day( self::DAY, array() );

		// Every same-day principal is present, adjacent days are not: the
		// aggregate is reachable only by deliberately passing no scope keys.
		$this->assertSame(
			array( 'Alpha owner day job', 'Beta owner day job', 'Alpha other-user day job' ),
			array_column( $rows, 'label' )
		);
	}

	// -----------------------------------------------------------------
	// Task-level binding
	// -----------------------------------------------------------------

	public function test_gather_context_binds_principal_and_excludes_other_principals(): void {
		$context = $this->gather_context(
			array(
				'date'     => self::DAY,
				'user_id'  => $this->owner_user_id,
				'agent_id' => $this->agent_alpha_id,
			)
		);

		// Intended inclusion.
		$this->assertStringContainsString( 'Alpha owner day job', $context );

		// Cross-agent exclusion (beta shares the owner).
		$this->assertStringNotContainsString( 'Beta owner day job', $context );

		// Cross-user exclusion (the other user shares alpha).
		$this->assertStringNotContainsString( 'Alpha other-user day job', $context );

		// Date bounds.
		$this->assertStringNotContainsString( 'Alpha owner earlier job', $context );
		$this->assertStringNotContainsString( 'Beta other-user later job', $context );
	}

	public function test_gather_context_collects_nothing_without_principal(): void {
		$context = $this->gather_context( array( 'date' => self::DAY ) );

		$this->assertSame( '', $context );
	}

	public function test_gather_context_explicit_agent_scope_aggregates_only_that_agent(): void {
		$context = $this->gather_context(
			array(
				'date'          => self::DAY,
				'user_id'       => $this->owner_user_id,
				'agent_id'      => $this->agent_alpha_id,
				'context_scope' => 'agent',
			)
		);

		// The authorized agent-wide aggregate spans both of alpha's users...
		$this->assertStringContainsString( 'Alpha owner day job', $context );
		$this->assertStringContainsString( 'Alpha other-user day job', $context );

		// ...and nothing outside that agent.
		$this->assertStringNotContainsString( 'Beta owner day job', $context );
	}

	public function test_gather_context_agentless_user_run_reads_own_records_only(): void {
		$context = $this->gather_context(
			array(
				'date'    => self::DAY,
				'user_id' => $this->owner_user_id,
			)
		);

		// A user-bound, agentless (delegated) run reads the user's own
		// records across agents, and never another user's.
		$this->assertStringContainsString( 'Alpha owner day job', $context );
		$this->assertStringContainsString( 'Beta owner day job', $context );
		$this->assertStringNotContainsString( 'Alpha other-user day job', $context );
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Invoke the private gatherContext() through the real task instance so
	 * the binding logic runs against the persisted rows.
	 */
	private function gather_context( array $params ): string {
		$method = new \ReflectionMethod( DailyMemoryTask::class, 'gatherContext' );
		$method->setAccessible( true );

		return (string) $method->invoke( new DailyMemoryTask(), $params );
	}

	private function seed_jobs(): void {
		$this->job_ids['alpha_owner_day'] = $this->create_job_at(
			'Alpha owner day job',
			$this->owner_user_id,
			$this->agent_alpha_id,
			self::DAY . ' 09:00:00'
		);
		$this->job_ids['beta_owner_day'] = $this->create_job_at(
			'Beta owner day job',
			$this->owner_user_id,
			$this->agent_beta_id,
			self::DAY . ' 10:00:00'
		);
		$this->job_ids['alpha_other_day'] = $this->create_job_at(
			'Alpha other-user day job',
			$this->other_user_id,
			$this->agent_alpha_id,
			self::DAY . ' 11:00:00'
		);
		$this->job_ids['alpha_owner_earlier'] = $this->create_job_at(
			'Alpha owner earlier job',
			$this->owner_user_id,
			$this->agent_alpha_id,
			self::EARLIER
		);
		$this->job_ids['beta_other_later'] = $this->create_job_at(
			'Beta other-user later job',
			$this->other_user_id,
			$this->agent_beta_id,
			self::LATER
		);
	}

	private function create_job_at( string $label, int $user_id, int $agent_id, string $created_at ): int {
		global $wpdb;

		$job_id = $this->jobs_db->create_job(
			array(
				'source'   => 'direct',
				'label'    => $label,
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		$this->assertGreaterThan( 0, $job_id, "Failed to create job {$label}" );

		// Pin the timestamp so the day window is deterministic and both
		// edges (>= midnight, < next midnight) are exercised regardless of
		// when the suite runs.
		$wpdb->update(
			$wpdb->prefix . 'datamachine_jobs',
			array( 'created_at' => $created_at ),
			array( 'job_id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);

		return (int) $job_id;
	}
}
