<?php
/**
 * AgentResolver Tests
 *
 * Tests for the CLI --agent flag resolver.
 *
 * @package DataMachine\Tests\Unit\Cli
 */

namespace DataMachine\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use WP_UnitTestCase;
use DataMachine\Cli\AgentResolver;
use ReflectionMethod;

/**
 * Unit tests for AgentResolver.
 *
 * Tests class structure and contract. Full integration tests
 * (with WordPress agent lookup) require WP_UnitTestCase.
 */
class AgentResolverTest extends TestCase {

	/**
	 * Test resolve method exists and is static.
	 */
	public function test_resolve_is_static(): void {
		$method = new ReflectionMethod( AgentResolver::class, 'resolve' );

		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test resolve accepts array parameter.
	 */
	public function test_resolve_accepts_array(): void {
		$method = new ReflectionMethod( AgentResolver::class, 'resolve' );
		$params = $method->getParameters();

		$this->assertCount( 1, $params );
		$this->assertSame( 'assoc_args', $params[0]->getName() );
		$this->assertSame( 'array', $params[0]->getType()->getName() );
	}

	/**
	 * Test resolve returns nullable int.
	 */
	public function test_resolve_returns_nullable_int(): void {
		$method     = new ReflectionMethod( AgentResolver::class, 'resolve' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertTrue( $returnType->allowsNull() );
		$this->assertSame( 'int', $returnType->getName() );
	}

	/**
	 * Test resolveContext method exists and is static.
	 */
	public function test_resolveContext_is_static(): void {
		$method = new ReflectionMethod( AgentResolver::class, 'resolveContext' );

		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test resolveContext returns array.
	 */
	public function test_resolveContext_returns_array(): void {
		$method     = new ReflectionMethod( AgentResolver::class, 'resolveContext' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'array', $returnType->getName() );
	}

	/**
	 * Test buildScopingInput method exists and is static.
	 */
	public function test_buildScopingInput_is_static(): void {
		$method = new ReflectionMethod( AgentResolver::class, 'buildScopingInput' );

		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test buildScopingInput returns array.
	 */
	public function test_buildScopingInput_returns_array(): void {
		$method     = new ReflectionMethod( AgentResolver::class, 'buildScopingInput' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'array', $returnType->getName() );
	}

	/**
	 * Test buildScopingInput accepts array parameter.
	 */
	public function test_buildScopingInput_accepts_array(): void {
		$method = new ReflectionMethod( AgentResolver::class, 'buildScopingInput' );
		$params = $method->getParameters();

		$this->assertCount( 1, $params );
		$this->assertSame( 'assoc_args', $params[0]->getName() );
	}

	/**
	 * Test all three public methods exist.
	 */
	public function test_all_public_methods_exist(): void {
		$this->assertTrue( method_exists( AgentResolver::class, 'resolve' ) );
		$this->assertTrue( method_exists( AgentResolver::class, 'resolveContext' ) );
		$this->assertTrue( method_exists( AgentResolver::class, 'buildScopingInput' ) );
	}
}
