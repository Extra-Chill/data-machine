<?php
/**
 * Tools CLI adapter tests; no WordPress installation required.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Tests\Unit\Cli\Commands;

use DataMachine\Cli\Commands\ToolsCommand;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	require_once dirname( __DIR__, 3 ) . '/Doubles/AccessActionsCliCommand.php';
}
if ( ! class_exists( 'WP_CLI' ) ) {
	require_once dirname( __DIR__, 3 ) . '/Doubles/ToolsCli.php';
}
require_once dirname( __DIR__, 4 ) . '/inc/Cli/BaseCommand.php';
require_once dirname( __DIR__, 4 ) . '/inc/Cli/Commands/ToolsCommand.php';

class ToolsCommandTest extends TestCase {

	private function command(): ToolsCommand {
		return new class() extends ToolsCommand {
			public array $calls = array();
			public array $rows = array();
			public int $reads = 0;
			public string $stdin = "  stdin-secret  \r\n";

			protected function readStdin(): string {
				++$this->reads;
				return $this->stdin;
			}

			protected function executeAbility( string $name, array $input ): ?array {
				$this->calls[] = array( $name, $input );
				if ( 'datamachine/get-settings' === $name ) {
					return array( 'global_tools' => array(
						'exa_search' => array( 'label' => 'Exa', 'requires_configuration' => true, 'is_configured' => false ),
						'local_search' => array( 'label' => 'Local', 'requires_configuration' => false, 'is_configured' => true ),
					) );
				}
				if ( 'datamachine/save-tool-config' === $name ) {
					return null;
				}
				return array(
					'fields' => array(
						'api_key' => array( 'label' => 'API Key', 'required' => true, 'type' => 'password' ),
						'endpoint' => array( 'label' => 'Endpoint' ),
						'token' => array(),
						'pin' => array( 'type' => 'secret' ),
					),
					'config' => array( 'api_key' => 'secret-12345678', 'endpoint' => 'https://example.com', 'pin' => '1234' ),
				);
			}

			protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
				$this->rows = $items;
			}
		};
	}

	public function test_flags_map_to_config_data_and_control_flags_are_excluded(): void {
		$command = $this->command();
		$command->config( array( 'exa_search' ), array( 'api-key' => 'new-key', 'endpoint' => '', 'format' => 'json', 'show-secrets' => true ) );
		$this->assertSame( array( 'datamachine/save-tool-config', array(
			'tool_id' => 'exa_search',
			'config_data' => array( 'api_key' => 'new-key', 'endpoint' => '' ),
		) ), $command->calls[1] );
	}

	public function test_stdin_trims_only_line_endings_and_is_read_once(): void {
		$command = $this->command();
		$command->config( array( 'exa_search' ), array( 'api_key' => '-', 'token' => '-' ) );
		$this->assertSame( array( 'api_key' => '  stdin-secret  ', 'token' => '  stdin-secret  ' ), $command->calls[1][1]['config_data'] );
		$this->assertSame( 1, $command->reads );
	}

	public function test_masking_and_field_metadata(): void {
		$command = $this->command();
		$command->config( array( 'exa_search' ), array() );
		$this->assertSame( '***********5678', $command->rows[0]['value'] );
		$this->assertSame( 'API Key', $command->rows[0]['label'] );
		$this->assertTrue( $command->rows[0]['required'] );
		$this->assertSame( 'set', $command->rows[0]['status'] );
		$this->assertSame( 'https://example.com', $command->rows[1]['value'] );
		$this->assertSame( 'unset', $command->rows[2]['status'] );
		$this->assertSame( '****', $command->rows[3]['value'] );
	}

	public function test_show_secrets_displays_original_values_without_saving(): void {
		$command = $this->command();
		$command->config( array( 'exa_search' ), array( 'show-secrets' => true ) );
		$this->assertSame( 'secret-12345678', $command->rows[0]['value'] );
		$this->assertCount( 1, $command->calls );
	}

	public function test_unknown_field_rejected_before_stdin_or_save(): void {
		$command = $this->command();
		try {
			$command->config( array( 'exa_search' ), array( 'api_key' => '-', 'typo' => 'oops' ) );
			$this->fail( 'Expected unknown-field error.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Unknown configuration field: typo', $error->getMessage() );
		}
		$this->assertCount( 1, $command->calls );
		$this->assertSame( 0, $command->reads );
	}

	public function test_json_masks_values_too(): void {
		\WP_CLI::$messages = array();
		$command = $this->command();
		$command->config( array( 'exa_search' ), array( 'format' => 'json' ) );
		$rows = json_decode( \WP_CLI::$messages[0], true );
		$this->assertSame( '***********5678', $rows[0]['value'] );
		$this->assertStringNotContainsString( 'secret-12345678', \WP_CLI::$messages[0] );
	}

	public function test_list_reuses_settings_tool_projection_and_filters(): void {
		$command = $this->command();
		$command->list_tools( array(), array() );
		$this->assertCount( 2, $command->rows );
		$command->list_tools( array(), array( 'configurable' => true ) );
		$this->assertSame( array( array( 'id' => 'exa_search', 'label' => 'Exa', 'requires_config' => true, 'configured' => false ) ), $command->rows );
	}
}
