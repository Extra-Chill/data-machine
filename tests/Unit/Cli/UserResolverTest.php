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
	 * Test resolve returns 0 for empty assoc_args (no --user flag).
	 *
	 * This test works without WordPress because the early-return path
	 * doesn't call any WP functions.
	 */
	public function test_resolve_returns_zero_when_no_user_flag(): void {
		$result = UserResolver::resolve( array() );
		$this->assertSame( 0, $result );
	}

	/**
	 * Test resolve returns 0 for null user value.
	 */
	public function test_resolve_returns_zero_for_null_user(): void {
		$result = UserResolver::resolve( array( 'user' => null ) );
		$this->assertSame( 0, $result );
	}

	/**
	 * Test resolve returns 0 for empty string user value.
	 */
	public function test_resolve_returns_zero_for_empty_string_user(): void {
		$result = UserResolver::resolve( array( 'user' => '' ) );
		$this->assertSame( 0, $result );
	}
}
