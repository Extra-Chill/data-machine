<?php
/**
 * Tests for concise Data Machine Business AGENTS.md routing.
 *
 * @package DataMachineBusiness\Tests
 */

class AgentsMdRoutingTest extends \PHPUnit\Framework\TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_renderer_retains_bounded_default_routes(): void {
		$source = $this->read_file( 'inc/Runtime/AgentsMdSections.php' );

		foreach (
			array(
				'datamachine analytics ga <action>',
				'datamachine analytics gsc <action>',
				'datamachine analytics bing <action>',
				'datamachine analytics mediavine <action>',
				'datamachine analytics pagespeed <action>',
				'datamachine media <action>',
			) as $route
		) {
			$this->assertStringContainsString( $route, $source );
		}
	}

	public function test_renderer_delegates_mutable_contract_to_live_help(): void {
		$source = $this->read_file( 'inc/Runtime/AgentsMdSections.php' );

		$this->assertStringContainsString( 'datamachine --help', $source );
		$this->assertStringContainsString( 'datamachine analytics --help', $source );
		$this->assertStringContainsString( 'help <command>', $source );
		$this->assertStringContainsString( 'Live help is authoritative.', $source );
		$this->assertStringNotContainsString( 'flat_invoke_actions', $source );
		$this->assertStringNotContainsString( 'CliCommandIntrospector', $source );
	}

	public function test_registration_and_prefix_resolution_remain_context_safe(): void {
		$source = $this->read_file( 'inc/Runtime/AgentsMdSections.php' );

		$this->assertStringContainsString( "class_exists( '\\\\DataMachine\\\\Engine\\\\AI\\\\SectionRegistry' )", $source );
		$this->assertStringContainsString( "'datamachine_wp_cli_cmd'", $source );
		$this->assertStringContainsString( "'data-machine-business'", $source );
		$this->assertStringNotContainsString( 'CommandRegistry::map()', $source );
	}

	private function read_file( string $relative_path ): string {
		$contents = file_get_contents( $this->root . '/' . $relative_path );
		$this->assertIsString( $contents );
		return $contents;
	}
}
