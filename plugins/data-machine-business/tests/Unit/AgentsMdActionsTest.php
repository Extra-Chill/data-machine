<?php
/**
 * Tests for the AGENTS.md flat-`__invoke` action enumeration.
 *
 * Verifies that each flat `__invoke` command declares a static actions()
 * accessor and that the GSC command exposes the documented action set, so the
 * composed AGENTS.md section enumerates actions instead of bare command lines.
 *
 * @package DataMachineBusiness\Tests
 */

class AgentsMdActionsTest extends \PHPUnit\Framework\TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
	}

	/**
	 * Every command class mapped by CommandRegistry must declare a static
	 * actions() accessor so the AGENTS.md renderer can enumerate its verbs.
	 *
	 * @dataProvider provide_command_classes
	 */
	public function test_command_class_declares_static_actions( string $relative_path ): void {
		$source = $this->read_file( $relative_path );

		$this->assertStringContainsString(
			'public static function actions(): array',
			$source,
			"{$relative_path} must declare a public static actions() accessor for AGENTS.md action enumeration."
		);
	}

	public function provide_command_classes(): array {
		return array(
			'GSC'        => array( 'inc/Cli/GoogleSearchConsoleCommand.php' ),
			'GA'         => array( 'inc/Cli/GoogleAnalyticsCommand.php' ),
			'PageSpeed'  => array( 'inc/Cli/PageSpeedCommand.php' ),
			'Media'      => array( 'inc/Cli/MediaHygieneCommand.php' ),
			'Bing'       => array( 'inc/Cli/Commands/BingWebmasterCommand.php' ),
		);
	}

	public function test_gsc_command_actions_cover_documented_set(): void {
		$source = $this->read_file( 'inc/Cli/GoogleSearchConsoleCommand.php' );

		$expected = array(
			'query_stats',
			'page_stats',
			'query_page_stats',
			'date_stats',
			'inspect_url',
			'list_sitemaps',
			'get_sitemap',
			'submit_sitemap',
		);

		foreach ( $expected as $action ) {
			$this->assertStringContainsString(
				"'{$action}'",
				$source,
				"GSC actions() must include the '{$action}' action."
			);
		}
	}

	public function test_renderer_calls_flat_invoke_actions_helper(): void {
		$source = $this->read_file( 'inc/Runtime/AgentsMdSections.php' );

		// The renderer must read the static actions() accessor for flat commands.
		$this->assertStringContainsString( 'flat_invoke_actions', $source );
		$this->assertStringContainsString(
			"call_user_func( array( \$command_class, 'actions' ) )",
			$source
		);
	}

	private function read_file( string $relative_path ): string {
		$contents = file_get_contents( $this->root . '/' . $relative_path );
		$this->assertIsString( $contents );
		return $contents;
	}
}
