<?php
/**
 * Web page content retrieval with HTML processing and 50K character limit.
 */
namespace DataMachine\Engine\AI\Tools\Global;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Abilities\WebFetchAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class WebFetch extends BaseTool {

	public function __construct() {
		$this->registerTool( 'web_fetch', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'access_level' => 'author' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$url    = (string) ( $parameters['url'] ?? '' );
		$result = WebFetchAbilities::fetch( $url );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'web_fetch',
			);
		}

		$result['message'] = $result['content_length'] > 0
			? "FETCH COMPLETE: Retrieved content from \"{$url}\". Content Length: {$result['content_length']} characters"
			: "FETCH COMPLETE: No readable content found at \"{$url}\".";

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'web_fetch',
		);
	}

	/**
	 * Get Web Fetch tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Fetch and extract readable content from any HTTP/HTTPS web page URL (50K character limit). Use when you have a specific URL to retrieve full article content. Automatically converts HTML to readable text. Best for single-page content analysis after discovery via Google Search.',
			'requires_config' => false,
			'parameters'      => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array(
					'type'        => 'string',
					'description' => 'Full HTTP/HTTPS URL to fetch content from. Must be a valid web address.',
				),
				),
				'required'   => array( 'url' ),
			),
		);
	}

	public static function is_configured(): bool {
		return true;
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'web_fetch' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
