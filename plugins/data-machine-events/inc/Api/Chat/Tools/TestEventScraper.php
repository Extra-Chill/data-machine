<?php
/**
 * Test Event Scraper Tool
 *
 * Chat tool wrapper for EventScraperTest ability. Tests universal web scraper
 * compatibility with a target URL.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Abilities\EventScraperTest;

class TestEventScraper {
	use ToolRegistrationTrait;

	public function __construct() {
		$this->registerTool( 'chat', 'test_event_scraper', array( $this, 'getToolDefinition' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Test universal web scraper compatibility with a target URL. Returns structured JSON with event data, extraction method, and coverage warnings.',
			'parameters'  => array(
				'target_url' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Target URL to test scraper against',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$target_url = $parameters['target_url'] ?? '';

		if ( empty( $target_url ) ) {
			return array(
				'success'   => false,
				'error'     => 'Missing required target_url parameter.',
				'tool_name' => 'test_event_scraper',
			);
		}

		$ability = new EventScraperTest();
		$result  = $ability->test( $target_url );

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'test_event_scraper',
		);
	}
}
