<?php
/**
 * CliCommandIntrospector unit tests — issue #2613.
 *
 * Asserts the introspector reflects over command classes (no live WP-CLI
 * runner required) and parses `@subcommand` names + PHPDoc short descriptions
 * exactly as WP-CLI's DocParser/CommandFactory would.
 *
 * @package DataMachine\Tests\Unit\Engine\AI
 */

namespace DataMachine\Tests\Unit\Engine\AI;

use DataMachine\Engine\AI\CliCommandIntrospector;
use DataMachine\Tests\Unit\Engine\AI\Fixtures\FixtureCommand;
use DataMachine\Tests\Unit\Engine\AI\Fixtures\SecondFixtureCommand;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DataMachine\Engine\AI\CliCommandIntrospector
 */
class CliCommandIntrospectorTest extends TestCase {

	/**
	 * Reflecting a command class returns each @subcommand with its short desc.
	 */
	public function test_describe_class_parses_subcommands_and_descriptions(): void {
		$subcommands = CliCommandIntrospector::describe_class( FixtureCommand::class );

		$by_name = array();
		foreach ( $subcommands as $sub ) {
			$by_name[ $sub['name'] ] = $sub['description'];
		}

		$this->assertSame( 'Discover venues in a city.', $by_name['discover'] ?? null );
		$this->assertSame( 'Qualify a venue URL for scraping.', $by_name['qualify'] ?? null );
	}

	/**
	 * A documented method without @subcommand falls back to the method name.
	 */
	public function test_describe_class_falls_back_to_method_name(): void {
		$subcommands = CliCommandIntrospector::describe_class( FixtureCommand::class );

		$names = array_column( $subcommands, 'name' );

		$this->assertContains( 'audit', $names );
	}

	/**
	 * Only the first non-tag line of a docblock is the short description.
	 */
	public function test_short_description_is_first_line_only(): void {
		$subcommands = CliCommandIntrospector::describe_class( FixtureCommand::class );

		$by_name = array_column( $subcommands, 'description', 'name' );

		$this->assertSame( 'List everything without an explicit subcommand tag.', $by_name['audit'] ?? null );
	}

	/**
	 * Static methods, magic methods, and undocumented methods are excluded.
	 */
	public function test_describe_class_excludes_non_subcommands(): void {
		$names = array_column( CliCommandIntrospector::describe_class( FixtureCommand::class ), 'name' );

		$this->assertNotContains( 'helper', $names, 'static method must be excluded' );
		$this->assertNotContains( 'should-not-appear', $names, 'static method @subcommand must be excluded' );
		$this->assertNotContains( '__construct', $names, 'magic method must be excluded' );
		$this->assertNotContains( 'undocumented', $names, 'method without docblock must be excluded by default' );
	}

	/**
	 * Undocumented public methods are included when explicitly requested.
	 */
	public function test_describe_class_can_include_undocumented(): void {
		$names = array_column(
			CliCommandIntrospector::describe_class( FixtureCommand::class, array( 'include_undocumented' => true ) ),
			'name'
		);

		$this->assertContains( 'undocumented', $names );
	}

	/**
	 * Subcommands are returned sorted by name.
	 */
	public function test_describe_class_sorts_by_name(): void {
		$names = array_column( CliCommandIntrospector::describe_class( FixtureCommand::class ), 'name' );

		$sorted = $names;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $names );
	}

	/**
	 * A non-existent class returns an empty array, never an error.
	 */
	public function test_describe_class_unknown_class_returns_empty(): void {
		$this->assertSame( array(), CliCommandIntrospector::describe_class( '\\This\\Class\\Does\\Not\\Exist' ) );
	}

	/**
	 * describe() merges subcommands across multiple classes for a namespace.
	 */
	public function test_describe_merges_multiple_classes(): void {
		$result = CliCommandIntrospector::describe(
			'fixtures',
			array( FixtureCommand::class, SecondFixtureCommand::class )
		);

		$this->assertSame( 'fixtures', $result['namespace'] );

		$names = array_column( $result['subcommands'], 'name' );

		$this->assertContains( 'discover', $names );
		$this->assertContains( 'geocode', $names );
		$this->assertContains( 'add', $names );
	}

	/**
	 * describe() accepts a single class (not wrapped in an array).
	 */
	public function test_describe_accepts_single_class(): void {
		$result = CliCommandIntrospector::describe( 'fixtures', FixtureCommand::class );

		$names = array_column( $result['subcommands'], 'name' );

		$this->assertContains( 'discover', $names );
	}

	/**
	 * describe_namespace_map() preserves per-command grouping.
	 */
	public function test_describe_namespace_map_groups_by_command(): void {
		$result = CliCommandIntrospector::describe_namespace_map(
			'fixtures',
			array(
				'venues'  => FixtureCommand::class,
				'geocode' => SecondFixtureCommand::class,
			)
		);

		$this->assertSame( 'fixtures', $result['namespace'] );
		$this->assertCount( 2, $result['commands'] );

		$commands = array_column( $result['commands'], 'subcommands', 'command' );

		$venues_subs = array_column( $commands['venues'] ?? array(), 'name' );
		$this->assertContains( 'discover', $venues_subs );
		$this->assertContains( 'qualify', $venues_subs );

		$geocode_subs = array_column( $commands['geocode'] ?? array(), 'name' );
		$this->assertContains( 'geocode', $geocode_subs );
		$this->assertContains( 'add', $geocode_subs );
	}
}
