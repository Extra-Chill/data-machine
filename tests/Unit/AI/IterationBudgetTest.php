<?php
/**
 * Tests for IterationBudget and IterationBudgetRegistry.
 *
 * @package DataMachine\Tests\Unit\AI
 */

namespace DataMachine\Tests\Unit\AI;

use AgentsAPI\AI\IterationBudget;
use DataMachine\Engine\AI\IterationBudgetRegistry;
use WP_UnitTestCase;

class IterationBudgetTest extends WP_UnitTestCase {

	/**
	 * Unique registry key per test to avoid cross-test contamination.
	 */
	private string $test_budget_name;

	public function set_up(): void {
		parent::set_up();
		$this->test_budget_name = 'test_budget_' . uniqid();
	}

	public function test_budget_starts_below_ceiling(): void {
		$budget = new IterationBudget( 'x', 5 );
		$this->assertSame( 0, $budget->current() );
		$this->assertSame( 5, $budget->ceiling() );
		$this->assertSame( 5, $budget->remaining() );
		$this->assertFalse( $budget->exceeded() );
	}

	public function test_increment_advances_counter(): void {
		$budget = new IterationBudget( 'x', 3 );
		$budget->increment();
		$this->assertSame( 1, $budget->current() );
		$this->assertSame( 2, $budget->remaining() );
		$this->assertFalse( $budget->exceeded() );
	}

	public function test_exceeded_at_ceiling(): void {
		$budget = new IterationBudget( 'x', 2 );
		$budget->increment();
		$budget->increment();
		$this->assertSame( 2, $budget->current() );
		$this->assertSame( 0, $budget->remaining() );
		$this->assertTrue( $budget->exceeded() );
	}

	public function test_exceeded_above_ceiling(): void {
		$budget = new IterationBudget( 'x', 2 );
		$budget->increment();
		$budget->increment();
		$budget->increment();
		$this->assertSame( 3, $budget->current() );
		$this->assertSame( 0, $budget->remaining() );
		$this->assertTrue( $budget->exceeded() );
	}

	public function test_ceiling_minimum_enforced(): void {
		$budget = new IterationBudget( 'x', 0 );
		$this->assertSame( 1, $budget->ceiling(), 'Ceiling floor of 1 enforced' );
	}

	public function test_negative_starting_current_clamped_to_zero(): void {
		$budget = new IterationBudget( 'x', 5, -3 );
		$this->assertSame( 0, $budget->current() );
	}

	public function test_already_exceeded_start_preserved(): void {
		$budget = new IterationBudget( 'x', 3, 7 );
		$this->assertSame( 7, $budget->current() );
		$this->assertTrue( $budget->exceeded() );
	}

	public function test_registry_register_and_get_config(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 7,
			'min'     => 2,
			'max'     => 12,
		) );

		$this->assertTrue( IterationBudgetRegistry::is_registered( $this->test_budget_name ) );

		$config = IterationBudgetRegistry::get_config( $this->test_budget_name );
		$this->assertSame( 7, $config['default'] );
		$this->assertSame( 2, $config['min'] );
		$this->assertSame( 12, $config['max'] );
	}

	public function test_registry_create_uses_default_when_no_override(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 8,
			'min'     => 1,
			'max'     => 20,
		) );

		$budget = IterationBudgetRegistry::create( $this->test_budget_name );
		$this->assertSame( 8, $budget->ceiling() );
		$this->assertSame( 0, $budget->current() );
	}

	public function test_registry_create_override_wins_over_default(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 8,
			'min'     => 1,
			'max'     => 20,
		) );

		$budget = IterationBudgetRegistry::create( $this->test_budget_name, 0, 15 );
		$this->assertSame( 15, $budget->ceiling() );
	}

	public function test_registry_create_clamps_override_to_max(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 5,
			'min'     => 1,
			'max'     => 10,
		) );

		$budget = IterationBudgetRegistry::create( $this->test_budget_name, 0, 999 );
		$this->assertSame( 10, $budget->ceiling() );
	}

	public function test_registry_create_clamps_override_to_min(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 5,
			'min'     => 3,
			'max'     => 10,
		) );

		$budget = IterationBudgetRegistry::create( $this->test_budget_name, 0, 0 );
		$this->assertSame( 3, $budget->ceiling() );
	}

	public function test_registry_create_seeds_current_counter(): void {
		IterationBudgetRegistry::register( $this->test_budget_name, array(
			'default' => 10,
			'min'     => 1,
			'max'     => 20,
		) );

		$budget = IterationBudgetRegistry::create( $this->test_budget_name, 4 );
		$this->assertSame( 4, $budget->current() );
	}

	public function test_registry_create_unregistered_name_returns_permissive_budget(): void {
		$budget = IterationBudgetRegistry::create( 'never_registered_' . uniqid() );
		$this->assertGreaterThan( 0, $budget->ceiling() );
		$this->assertSame( 0, $budget->current() );
		$this->assertFalse( $budget->exceeded() );
	}

	public function test_conversation_turns_budget_registered_at_boot(): void {
		// The bootstrap file registers 'conversation_turns' at load time.
		// This test documents that registration and guards against
		// accidental removal.
		$this->assertTrue(
			IterationBudgetRegistry::is_registered( 'conversation_turns' ),
			'conversation_turns budget should be registered at boot by inc/bootstrap.php'
		);

		$config = IterationBudgetRegistry::get_config( 'conversation_turns' );
		$this->assertSame( 'max_turns', $config['setting'] );
		$this->assertSame( 1, $config['min'] );
		$this->assertSame( 50, $config['max'] );
	}
}
