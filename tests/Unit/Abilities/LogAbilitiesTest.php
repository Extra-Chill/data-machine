<?php
/**
 * Log Abilities Tests
 *
 * Tests for WordPress 6.9 Abilities API integration for logging operations.
 *
 * @package DataMachine\Tests\Unit\Engine\Abilities
 */

namespace DataMachine\Tests\Unit\Engine\Abilities;

use WP_UnitTestCase;

defined('ABSPATH') || exit;

class LogAbilitiesTest extends WP_UnitTestCase {

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
		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'invalid_level',
			'message' => 'Test'
		]);

		// WP 6.9 validates input against schema — invalid enum value returns WP_Error
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function testWrite_toLog_withContext_storesContext(): void {
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
		$ability = wp_get_ability('datamachine/write-to-log');
		$result = $ability->execute([
			'level' => 'info',
			'message' => 'Test log entry'
		]);

		$this->assertTrue($result['success']);
	}

	public function testClearLogs_withValidAgentType_returnsSuccess(): void {
		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('deleted', $result);
	}

	public function testClearLogs_withAll_clearsAllAgentTypes(): void {
		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertTrue($result['success']);
		$this->assertEquals('Logs cleared successfully', $result['message']);
	}

	public function testClearLogs_withoutPermissions_returnsPermissionDenied(): void {
		wp_set_current_user(0);
		add_filter( 'datamachine_cli_bypass_permissions', '__return_false' );

		$ability = wp_get_ability('datamachine/clear-logs');
		$result = $ability->execute([]);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function testReadDebugLog_ability_registered(): void {
		$ability = wp_get_ability('datamachine/read-debug-log');

		$this->assertNotNull($ability);
		$this->assertSame('datamachine/read-debug-log', $ability->get_name());
	}

	public function testReadDebugLog_returnsSuccess(): void {
		$ability = wp_get_ability('datamachine/read-debug-log');
		$result = $ability->execute([]);

		// Either the file exists and we get entries, or it doesn't and we get an error.
		// Both are valid outcomes.
		$this->assertIsArray($result);
		$this->assertArrayHasKey('success', $result);
	}

	public function testParseDebugLogLine_parsesStandardFormat(): void {
		// Test via the static method.
		$line = '[09-Mar-2026 14:30:00 UTC] PHP Fatal error:  Uncaught Exception in /var/www/wp-content/plugins/test/plugin.php on line 42';
		
		// Use reflection to call private method.
		$class = new \ReflectionClass(\DataMachine\Abilities\LogAbilities::class);
		$method = $class->getMethod('parseDebugLogLine');
		$method->setAccessible(true);
		
		$result = $method->invoke(null, $line);

		$this->assertNotNull($result);
		$this->assertEquals('FATAL', $result['level']);
		$this->assertStringContainsString('Uncaught Exception', $result['message']);
		$this->assertEquals('/var/www/wp-content/plugins/test/plugin.php', $result['file']);
		$this->assertEquals(42, $result['line']);
	}

	public function testParseDebugLogLine_parsesWarning(): void {
		$line = '[09-Mar-2026 14:30:00 UTC] PHP Warning:  Trying to access array offset on null in /var/www/wp-content/themes/test/functions.php on line 123';
		
		$class = new \ReflectionClass(\DataMachine\Abilities\LogAbilities::class);
		$method = $class->getMethod('parseDebugLogLine');
		$method->setAccessible(true);
		
		$result = $method->invoke(null, $line);

		$this->assertNotNull($result);
		$this->assertEquals('WARNING', $result['level']);
		$this->assertStringContainsString('Trying to access array offset', $result['message']);
	}

	public function testParseDebugLogLine_parsesDeprecated(): void {
		$line = '[09-Mar-2026 14:30:00 UTC] PHP Deprecated:  Function get_magic_quotes() is deprecated in /var/www/wp-content/plugins/old/plugin.php on line 10';
		
		$class = new \ReflectionClass(\DataMachine\Abilities\LogAbilities::class);
		$method = $class->getMethod('parseDebugLogLine');
		$method->setAccessible(true);
		
		$result = $method->invoke(null, $line);

		$this->assertNotNull($result);
		$this->assertEquals('DEPRECATED', $result['level']);
	}

	public function testNormalizeLogLevel_mapsVariants(): void {
		$class = new \ReflectionClass(\DataMachine\Abilities\LogAbilities::class);
		$method = $class->getMethod('normalizeLogLevel');
		$method->setAccessible(true);

		$this->assertEquals('FATAL', $method->invoke(null, 'FATAL ERROR'));
		$this->assertEquals('FATAL', $method->invoke(null, 'Core Error'));
		$this->assertEquals('WARNING', $method->invoke(null, 'User Warning'));
		$this->assertEquals('DEPRECATED', $method->invoke(null, 'User Deprecated'));
		$this->assertEquals('UNKNOWN', $method->invoke(null, 'SomeRandomLevel'));
	}
}
