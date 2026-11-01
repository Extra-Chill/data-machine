<?php
namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static array $lines = array();
			public static function line( $line ): void { self::$lines[] = $line; }
			public static function warning( $line ): void { self::$lines[] = $line; }
			public static function error( $line ): void { throw new \InvalidArgumentException( $line ); }
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ) { return json_encode( $value ); }
	}
}
namespace WP_CLI\Utils {
	function format_items( $format, $rows, $fields ): void {
		$GLOBALS['source_health_format'] = array( $format, $rows, $fields );
	}
}
namespace DataMachineEvents\Tests\Contracts {
	use DataMachineEvents\Core\SourceHealth;
	use DataMachineEvents\Cli\Check\CheckSourceHealthCommand;
	use PHPUnit\Framework\TestCase;

	final class SourceHealthCliTest extends TestCase {
		private function command(): CheckSourceHealthCommand {
			return new class extends CheckSourceHealthCommand {
				protected function health(): SourceHealth {
					return new class extends SourceHealth {
						public function report( int $pipeline = 0, array $statuses = array() ): array {
							return self::result( array( array_fill_keys( CheckSourceHealthCommand::FIELDS, '' ) + array() ), $statuses );
						}
					};
				}
			};
		}

		public function test_json_and_tabular_contracts(): void {
			$command = new class extends CheckSourceHealthCommand {
				protected function health(): SourceHealth {
					return new class extends SourceHealth {
						public function report( int $pipeline = 0, array $statuses = array() ): array {
							$row = array_fill_keys( CheckSourceHealthCommand::FIELDS, '' );
							$row['flow_id'] = 12;
							$row['status'] = 'dark';
							return self::result( array( $row ), $statuses );
						}
					};
				}
			};
			\WP_CLI::$lines = array();
			$command( array(), array( 'format' => 'json', 'pipeline' => '1' ) );
			$json = json_decode( \WP_CLI::$lines[0], true );
			$this->assertSame( array( 'summary', 'flows' ), array_keys( $json ) );
			$this->assertSame( 1, $json['summary']['dark'] );
			$this->assertSame( CheckSourceHealthCommand::FIELDS, array_keys( $json['flows'][0] ) );
			foreach ( array( 'table', 'csv' ) as $format ) {
				$command( array(), array( 'format' => $format ) );
				$this->assertSame( $format, $GLOBALS['source_health_format'][0] );
				$this->assertSame( CheckSourceHealthCommand::FIELDS, $GLOBALS['source_health_format'][2] );
			}
		}

		public function test_rejects_unknown_status_before_querying(): void {
			$this->expectException( \InvalidArgumentException::class );
			$this->command()( array(), array( 'status' => 'bogus' ) );
		}
	}
}
