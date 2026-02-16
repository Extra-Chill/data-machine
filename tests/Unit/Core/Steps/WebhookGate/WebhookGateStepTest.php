<?php
/**
 * Tests for WebhookGateStep.
 *
 * @package DataMachine\Tests\Unit\Core\Steps\WebhookGate
 */

namespace DataMachine\Tests\Unit\Core\Steps\WebhookGate;

use PHPUnit\Framework\TestCase;
use DataMachine\Core\Steps\WebhookGate\WebhookGateStep;
use DataMachine\Core\Steps\WebhookGate\WebhookGateSettings;
use DataMachine\Core\JobStatus;
use ReflectionMethod;

class WebhookGateStepTest extends TestCase {

	/**
	 * Test that WebhookGateStep class exists and extends Step.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( WebhookGateStep::class ) );
	}

	/**
	 * Test that WebhookGateStep extends the base Step class.
	 */
	public function test_extends_step(): void {
		$reflection = new \ReflectionClass( WebhookGateStep::class );
		$this->assertTrue( $reflection->isSubclassOf( \DataMachine\Core\Steps\Step::class ) );
	}

	/**
	 * Test that WebhookGateStep uses StepTypeRegistrationTrait.
	 */
	public function test_uses_registration_trait(): void {
		$traits = class_uses( WebhookGateStep::class );
		$this->assertArrayHasKey(
			\DataMachine\Core\Steps\StepTypeRegistrationTrait::class,
			$traits
		);
	}

	/**
	 * Test that validateStepConfiguration exists and returns bool.
	 */
	public function test_has_validate_step_configuration(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'validateStepConfiguration' );
		$this->assertTrue( $method->isProtected() );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'bool', $return_type->getName() );
	}

	/**
	 * Test that executeStep exists and returns array.
	 */
	public function test_has_execute_step(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'executeStep' );
		$this->assertTrue( $method->isProtected() );
		$return_type = $method->getReturnType();
		$this->assertNotNull( $return_type );
		$this->assertSame( 'array', $return_type->getName() );
	}

	/**
	 * Test that handleInboundWebhook is a public static method.
	 */
	public function test_has_handle_inbound_webhook(): void {
		$method = new ReflectionMethod( WebhookGateStep::class, 'handleInboundWebhook' );
		$this->assertTrue( $method->isPublic() );
		$this->assertTrue( $method->isStatic() );
	}

	/**
	 * Test WebhookGateSettings fields.
	 */
	public function test_settings_fields(): void {
		$fields = WebhookGateSettings::get_fields();

		$this->assertIsArray( $fields );
		$this->assertArrayHasKey( 'timeout_hours', $fields );
		$this->assertArrayHasKey( 'description', $fields );
		$this->assertSame( 'number', $fields['timeout_hours']['type'] );
		$this->assertSame( 0, $fields['timeout_hours']['default'] );
		$this->assertSame( 'text', $fields['description']['type'] );
	}

	/**
	 * Test WebhookGateSettings extends SettingsHandler.
	 */
	public function test_settings_extends_settings_handler(): void {
		$reflection = new \ReflectionClass( WebhookGateSettings::class );
		$this->assertTrue( $reflection->isSubclassOf( \DataMachine\Core\Steps\Settings\SettingsHandler::class ) );
	}
}
