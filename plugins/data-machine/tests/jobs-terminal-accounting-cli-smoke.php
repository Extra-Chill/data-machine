<?php
/** Verify terminal accounting CLI isolates reconciliation failures per job. */

declare(strict_types=1);

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public const TERMINAL_ACCOUNTING_COMPLETE = 4;

		public function get_incomplete_terminal_accounting( int $limit ): array {
			return array(
				array(
					'job_id'                   => 11,
					'status'                   => 'completed',
					'terminal_accounting_state' => 1,
				),
				array(
					'job_id'                   => 12,
					'status'                   => 'completed',
					'terminal_accounting_state' => 2,
				),
			);
		}

		public function count_incomplete_terminal_accounting(): int {
			return 2;
		}

		public function reconcile_terminal_accounting( int $job_id ): array {
			if ( 11 === $job_id ) {
				throw new \RuntimeException( 'simulated reconciliation failure' );
			}

			return array(
				'success'     => true,
				'state'       => self::TERMINAL_ACCOUNTING_COMPLETE,
				'complete'    => true,
				'in_progress' => false,
				'errors'      => array(),
			);
		}
	}
}

namespace DataMachine\Cli {
	abstract class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $default_orderby ): void {}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static array $printed = array();

		public static function print_value( mixed $value, array $args = array() ): void {
			self::$printed[] = $value;
		}

		public static function success( string $message ): void {}

		public static function log( string $message ): void {}
	}

	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/JobsCommand.php';

	$command = new \DataMachine\Cli\Commands\JobsCommand();
	$command->reconcile_terminal_accounting( array(), array( 'format' => 'json' ) );
	$output = WP_CLI::$printed[0] ?? null;

	$assertions = array(
		'isolation preserves both rows' => 2 === count( $output['jobs'] ?? array() ),
		'first row is a typed failure'   => 'failed' === ( $output['jobs'][0]['action'] ?? null )
			&& 'reconciliation_exception' === ( $output['jobs'][0]['errors'][0]['code'] ?? null ),
		'second row still reconciles'    => 'reconciled' === ( $output['jobs'][1]['action'] ?? null ),
		'summary counts mixed outcome'   => 1 === ( $output['summary']['failed'] ?? null )
			&& 1 === ( $output['summary']['reconciled'] ?? null ),
	);

	$failures = 0;
	foreach ( $assertions as $label => $passed ) {
		fwrite( STDOUT, sprintf( "%s: %s\n", $passed ? 'PASS' : 'FAIL', $label ) );
		$failures += $passed ? 0 : 1;
	}

	exit( $failures > 0 ? 1 : 0 );
}
