<?php
/**
 * Tests for PromptBuilder handling of directives that cannot run.
 *
 * @package DataMachine\Tests\Unit\AI
 */

namespace DataMachine\Tests\Unit\AI;

use DataMachine\Engine\AI\Directives\DirectiveInterface;
use DataMachine\Engine\AI\PromptBuilder;
use WP_UnitTestCase;

/**
 * Valid directive fixture.
 */
class PromptBuilderValidTestDirective implements DirectiveInterface {
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		return array(
			array(
				'type'    => 'system_text',
				'content' => 'Valid directive output.',
			),
		);
	}
}

/**
 * Directive fixture that forgets to implement DirectiveInterface.
 */
class PromptBuilderNoInterfaceTestDirective {
	public static function get_outputs( string $provider_name, array $tools, ?string $step_id = null, array $payload = array() ): array {
		return array(
			array(
				'type'    => 'system_text',
				'content' => 'This must never reach the prompt.',
			),
		);
	}
}

/**
 * Second non-interface fixture, used to verify once-per-class reporting.
 */
class PromptBuilderNoInterfaceOnceTestDirective extends PromptBuilderNoInterfaceTestDirective {
}

class PromptBuilderSkippedDirectivesTest extends WP_UnitTestCase {

	private const DOING_IT_WRONG = PromptBuilder::class . '::buildDetailed';

	/** @var array<int,array> */
	private array $logs = array();

	public function set_up(): void {
		parent::set_up();
		$this->logs = array();
		add_action( 'datamachine_log', array( $this, 'capture_log' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_action( 'datamachine_log', array( $this, 'capture_log' ), 10 );
		parent::tear_down();
	}

	public function capture_log( $level, $message, $context = array() ): void {
		$this->logs[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	private function errorLogsFor( string $class_name ): array {
		return array_values(
			array_filter(
				$this->logs,
				static fn( array $log ): bool => 'error' === $log['level'] && ( $log['context']['class'] ?? '' ) === $class_name
			)
		);
	}

	private function buildWith( array $directives ): array {
		$builder = new PromptBuilder();
		$builder->setMessages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);
		foreach ( $directives as $priority => $directive ) {
			$builder->addDirective( $directive, (int) $priority, array( 'all' ) );
		}
		return $builder->buildDetailed( array( 'pipeline' ), 'openai', array() );
	}

	public function test_valid_directive_runs_and_nothing_is_skipped(): void {
		$result = $this->buildWith( array( 10 => PromptBuilderValidTestDirective::class ) );

		$this->assertSame( array( 'PromptBuilderValidTestDirective' ), $result['applied_directives'] );
		$this->assertCount( 1, $result['directive_breakdown'] );
		$this->assertSame( PromptBuilderValidTestDirective::class, $result['directive_breakdown'][0]['class'] );
		$this->assertSame( array(), $result['skipped_directives'] );
		$this->assertSame( array(), $this->logs );

		$contents = wp_json_encode( $result['messages'] );
		$this->assertStringContainsString( 'Valid directive output.', $contents );
		$this->assertStringContainsString( 'Hello', $contents );
	}

	public function test_valid_directive_instance_runs(): void {
		$result = $this->buildWith( array( 10 => new PromptBuilderValidTestDirective() ) );

		$this->assertSame( array( 'PromptBuilderValidTestDirective' ), $result['applied_directives'] );
		$this->assertSame( array(), $result['skipped_directives'] );
	}

	public function test_non_interface_directive_is_skipped_loudly(): void {
		$this->setExpectedIncorrectUsage( self::DOING_IT_WRONG );

		$result = $this->buildWith(
			array(
				10 => PromptBuilderNoInterfaceTestDirective::class,
				20 => PromptBuilderValidTestDirective::class,
			)
		);

		// Valid directive still runs.
		$this->assertSame( array( 'PromptBuilderValidTestDirective' ), $result['applied_directives'] );
		$this->assertStringNotContainsString( 'This must never reach the prompt.', wp_json_encode( $result['messages'] ) );

		$this->assertCount( 1, $result['skipped_directives'] );
		$skipped = $result['skipped_directives'][0];
		$this->assertSame( PromptBuilderNoInterfaceTestDirective::class, $skipped['class'] );
		$this->assertSame( 'PromptBuilderNoInterfaceTestDirective', $skipped['name'] );
		$this->assertSame( 10, $skipped['priority'] );
		$this->assertSame( 'missing_interface', $skipped['reason'] );

		$logs = $this->errorLogsFor( PromptBuilderNoInterfaceTestDirective::class );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'missing_interface', $logs[0]['context']['reason'] );
		$this->assertStringContainsString( PromptBuilderNoInterfaceTestDirective::class, $logs[0]['context']['message'] );
	}

	public function test_missing_class_is_skipped_loudly(): void {
		$this->setExpectedIncorrectUsage( self::DOING_IT_WRONG );

		$missing = 'DataMachine\\Tests\\Unit\\AI\\PromptBuilderDefinitelyMissingDirective';
		$result  = $this->buildWith(
			array(
				5  => $missing,
				20 => PromptBuilderValidTestDirective::class,
			)
		);

		$this->assertSame( array( 'PromptBuilderValidTestDirective' ), $result['applied_directives'] );
		$this->assertCount( 1, $result['skipped_directives'] );
		$this->assertSame( $missing, $result['skipped_directives'][0]['class'] );
		$this->assertSame( 'class_not_found', $result['skipped_directives'][0]['reason'] );

		$logs = $this->errorLogsFor( $missing );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'class_not_found', $logs[0]['context']['reason'] );
	}

	public function test_non_interface_directive_is_reported_once_per_request_but_always_recorded(): void {
		$this->setExpectedIncorrectUsage( self::DOING_IT_WRONG );

		$first  = $this->buildWith( array( 10 => PromptBuilderNoInterfaceOnceTestDirective::class ) );
		$second = $this->buildWith( array( 10 => PromptBuilderNoInterfaceOnceTestDirective::class ) );

		$this->assertCount( 1, $first['skipped_directives'] );
		$this->assertCount( 1, $second['skipped_directives'] );
		$this->assertCount( 1, $this->errorLogsFor( PromptBuilderNoInterfaceOnceTestDirective::class ) );
		$this->assertCount( 1, $this->caught_doing_it_wrong );
	}
}
