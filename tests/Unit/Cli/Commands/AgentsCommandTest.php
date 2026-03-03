<?php
/**
 * AgentsCommand Tests
 *
 * Tests for the wp datamachine agents list command.
 *
 * @package DataMachine\Tests\Unit\Cli\Commands
 */

namespace DataMachine\Tests\Unit\Cli\Commands;

use PHPUnit\Framework\TestCase;
use DataMachine\Cli\Commands\AgentsCommand;
use ReflectionMethod;

/**
 * Unit tests for AgentsCommand.
 */
class AgentsCommandTest extends TestCase {

	/**
	 * Test the command class exists and extends BaseCommand.
	 */
	public function test_extends_base_command(): void {
		$this->assertTrue(
			is_subclass_of( AgentsCommand::class, \DataMachine\Cli\BaseCommand::class ),
			'AgentsCommand should extend BaseCommand'
		);
	}

	/**
	 * Test list_agents method exists.
	 */
	public function test_has_list_agents_method(): void {
		$this->assertTrue(
			method_exists( AgentsCommand::class, 'list_agents' ),
			'AgentsCommand should have list_agents method'
		);
	}

	/**
	 * Test list_agents method signature.
	 */
	public function test_list_agents_signature(): void {
		$method = new ReflectionMethod( AgentsCommand::class, 'list_agents' );

		$this->assertTrue( $method->isPublic() );

		$params = $method->getParameters();
		$this->assertCount( 2, $params );
		$this->assertSame( 'args', $params[0]->getName() );
		$this->assertSame( 'assoc_args', $params[1]->getName() );
	}

	/**
	 * Test list_agents return type is void.
	 */
	public function test_list_agents_returns_void(): void {
		$method     = new ReflectionMethod( AgentsCommand::class, 'list_agents' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'void', $returnType->getName() );
	}
}
