<?php
/**
 * Web page content retrieval with HTML processing and 50K character limit.
 */
namespace DataMachine\Engine\AI\Tools\Global;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\Tools\BaseTool;

class WebFetch extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'web_fetch', array( $this, 'getToolDefinition' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {

		$url = $parameters['url'] ?? '';
		if ( empty( $url ) ) {
			return array(
				'success'   => false,
				'error'     => 'URL parameter is required',
				'tool_name' => 'web_fetch',
			);
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return array(
				'success'   => false,
				'error'     => 'Invalid URL format. Must be a valid HTTP or HTTPS URL',
				'tool_name' => 'web_fetch',
			);
		}

		$result = HttpClient::get(
			$url,
			array(
				'timeout'      => 30,
				'browser_mode' => true,
				'context'      => 'Web Fetch Tool',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => 'Failed to fetch URL: ' . ( $result['error'] ?? 'Unknown error' ),
				'tool_name' => 'web_fetch',
			);
		}

		$html_content = $result['data'];
		if ( empty( $html_content ) ) {
			return array(
				'success'   => false,
				'error'     => 'No content received from URL',
				'tool_name' => 'web_fetch',
			);
		}

		$extracted_content = $this->extract_readable_content( $html_content );

		$max_length        = 50000;
		$content_truncated = false;
		if ( strlen( $extracted_content['content'] ) > $max_length ) {
			$extracted_content['content'] = substr( $extracted_content['content'], 0, $max_length ) . '... [Content truncated]';
			$content_truncated            = true;
		}

		$content_length = strlen( $extracted_content['content'] );
		$message        = $content_length > 0
			? "FETCH COMPLETE: Retrieved content from \"{$url}\". Content Length: {$content_length} characters"
			: "FETCH COMPLETE: No readable content found at \"{$url}\".";

		return array(
			'success'   => true,
			'data'      => array(
				'message'           => $message,
				'url'               => $url,
				'title'             => $extracted_content['title'],
				'content'           => $extracted_content['content'],
				'content_length'    => $content_length,
				'content_truncated' => $content_truncated,
				'fetch_timestamp'   => gmdate( 'Y-m-d H:i:s' ),
			),
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
				'url' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Full HTTP/HTTPS URL to fetch content from. Must be a valid web address.',
				),
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

	private function extract_readable_content( string $html_content ): array {

		$title = '';
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html_content, $title_matches ) ) {
			$title = html_entity_decode( wp_strip_all_tags( $title_matches[1] ), ENT_QUOTES, 'UTF-8' );
			$title = trim( $title );
		}

		$content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html_content );
		$content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );

		$unwanted_elements = array( 'nav', 'header', 'footer', 'aside', 'menu' );
		foreach ( $unwanted_elements as $element ) {
			$content = preg_replace( "/<{$element}[^>]*>.*?<\/{$element}>/is", '', $content );
		}

		$block_elements = array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br' );
		foreach ( $block_elements as $element ) {
			$content = preg_replace( "/<\/{$element}>/i", "</{$element}>\n", $content );
		}

		$content = wp_strip_all_tags( $content );

		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		$content = preg_replace( '/\n\s*\n/', "\n\n", $content );
		$content = preg_replace( '/[ \t]+/', ' ', $content );
		$content = trim( $content );

		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return array(
			'title'   => $title,
			'content' => $content,
		);
	}
}
