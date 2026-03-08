<?php
/**
 * Log Abilities Tests
 *
 * Tests for WordPress 6.9 Abilities API integration for logging operations.
 *
 * @package DataMachine\Tests\Unit\Engine\Abilities
 */

namespace DataMachine\Tests\Unit\Engine\Abilities;

defined('ABSPATH') || exit;

class LogAbilitiesTest extends \WP_UnitTestCase {

	private $original_user_id;

	public function setUp(): void {
		parent::setUp();
		$this->original_user_id = get_current_user_id();
		wp_set_current_user(1);
	}

	public function tearDown(): void {
		wp_set_current_user($this->original_user_id);
		parent::tearDown();
	}

	public function testWrite_toLog_ability_registered(): void {
		$ability = wp_get_ability('datamachine/write-to-log');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/write-to-log', $ability->get_name());
	}

	public function testClear_logs_ability_registered(): void {
		$ability = wp_get_ability('datamachine/clear-logs');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/clear-logs', $ability->get_name());
	}

	public function testWrite_toLog_withValidData_returnsSuccess(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'debug',
			'message' => 'Test log entry',
			'context' => [
				'test' => true
			]
		]);

		$this->assertTrue($result['success']);
		$this->assertEquals('Log entry written', $result['message']);
	}

	public function testWrite_toLog_withoutPermissions_returnsPermissionDenied(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		wp_set_current_user(0);
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'debug',
			'message' => 'Test log entry'
		]);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function testWrite_toLog_withInvalidLevel_returnsError(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'invalid_level',
			'message' => 'Test'
		]);

		// WP 6.9 validates input against schema — invalid enum value returns WP_Error
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function testWrite_toLog_withContext_storesContext(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'info',
			'message' => 'Test log entry',
			'context' => [
				'flow_id' => 123
			]
		]);

		$this->assertTrue($result['success']);
	}

	public function testWrite_toLog_withoutContext_succeeds(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'info',
			'message' => 'Test log entry'
		]);

		$this->assertTrue($result['success']);
	}

	public function testClearLogs_withValidAgentType_returnsSuccess(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('deleted', $result);
	}

	public function testClearLogs_withAll_clearsAllAgentTypes(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertTrue($result['success']);
		$this->assertEquals('Logs cleared successfully', $result['message']);
	}

	public function testClearLogs_withoutPermissions_returnsPermissionDenied(): void {
		if (!class_exists('WP_Ability')) {
			$this->markTestSkipped('WP_Ability class not available');
			return;
		}

		wp_set_current_user(0);
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
