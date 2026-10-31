<?php
/**
 * Google Search Console extraction tests.
 *
 * @package DataMachineBusiness\Tests
 */

class GoogleSearchConsoleBusinessTest extends \PHPUnit\Framework\TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__ );
	}

	public function test_business_ships_google_search_console_surfaces(): void {
		$this->assertFileExists( $this->root . '/inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php' );
		$this->assertFileExists( $this->root . '/inc/Tools/GoogleSearchConsole.php' );
		$this->assertFileExists( $this->root . '/inc/Api/GoogleSearchConsoleAnalytics.php' );
		$this->assertFileExists( $this->root . '/inc/Cli/GoogleSearchConsoleCommand.php' );
	}

	public function test_business_preserves_legacy_google_search_console_configuration(): void {
		$this->assertStringContainsString(
			"CONFIG_OPTION = 'datamachine_gsc_config'",
			$this->read_file( 'inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php' )
		);

		// The per-ability token transient was removed when JWT-bearer auth moved
		// to the shared BaseServiceAccountProvider. Legacy credential storage is
		// still honoured: the provider falls back to this option when no
		// provider-scoped config exists.
		$this->assertStringContainsString(
			'new GoogleServiceAccountAuth( self::CONFIG_OPTION )',
			$this->read_file( 'inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php' )
		);
	}

	public function test_business_registers_google_search_console_tool_api_and_cli(): void {
		$this->assertStringContainsString(
			"registerTool( 'google_search_console'",
			$this->read_file( 'inc/Tools/GoogleSearchConsole.php' )
		);

		$this->assertStringContainsString(
			"'/analytics/gsc'",
			$this->read_file( 'inc/Api/GoogleSearchConsoleAnalytics.php' )
		);

		$this->assertStringContainsString(
			'datamachine analytics gsc',
			$this->read_file( 'inc/Cli/CommandRegistry.php' )
		);
	}

	private function read_file( string $relative_path ): string {
		$contents = file_get_contents( $this->root . '/' . $relative_path );
		$this->assertIsString( $contents );
		return $contents;
	}
}
