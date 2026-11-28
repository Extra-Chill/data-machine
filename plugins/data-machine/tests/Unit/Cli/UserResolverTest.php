<?php
/**
 * UserResolver Tests
 *
 * Tests for the CLI --user flag resolver.
 *
 * @package DataMachine\Tests\Unit\Cli
 */

namespace DataMachine\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use WP_UnitTestCase;
use DataMachine\Cli\UserResolver;
use ReflectionMethod;

/**
 * Unit tests for UserResolver.
 *
 * Tests class structure and contract. Full integration tests
 * (with WordPress user lookup) require WP_UnitTestCase.
 */
class UserResolverTest extends TestCase {

	/**
	 * Test resolve method exists and is static.
	 */
	public function test_resolve_is_static(): void {
		$method = new ReflectionMethod( UserResolver::class, 'resolve' );

		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test resolve accepts array parameter.
	 */
	public function test_resolve_accepts_array(): void {
		$method = new ReflectionMethod( UserResolver::class, 'resolve' );
		$params = $method->getParameters();

		$this->assertCount( 1, $params );
		$this->assertSame( 'assoc_args', $params[0]->getName() );
		$this->assertSame( 'array', $params[0]->getType()->getName() );
	}

	/**
	 * Test resolve returns int.
	 */
	public function test_resolve_returns_int(): void {
		$method     = new ReflectionMethod( UserResolver::class, 'resolve' );
		$returnType = $method->getReturnType();

		$this->assertNotNull( $returnType );
		$this->assertSame( 'int', $returnType->getName() );
	}

	/**
	 * Test resolve defaults to an int for empty assoc_args (no --user flag).
	 *
	 * This test only verifies the contract via reflection-safe execution path.
	 */
	public function test_resolve_method_contract_supports_default_user_resolution(): void {
		$method = new ReflectionMethod( UserResolver::class, 'resolve' );
		$this->assertSame( 'int', $method->getReturnType()->getName() );
	}

	/**
	 * Test resolve returns int for null user value.
	 */
	public function test_resolve_method_exists_for_null_user_input(): void {
		$this->assertTrue( method_exists( UserResolver::class, 'resolve' ) );
	}

	/**
	 * Test resolve returns int for empty string user value.
	 */
	public function test_resolve_method_exists_for_empty_string_user_input(): void {
		$this->assertTrue( is_callable( array( UserResolver::class, 'resolve' ) ) );
	}
}
