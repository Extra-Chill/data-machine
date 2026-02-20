<?php
/**
 * WordPress Post Reader - AI tool for retrieving WordPress post content by URL.
 *
 * @package DataMachine
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Tools\BaseTool;

class WordPressPostReader extends BaseTool {

	public function __construct() {
		$this->registerGlobalTool( 'wordpress_post_reader', array( $this, 'getToolDefinition' ) );
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {

		if ( empty( $parameters['source_url'] ) ) {
			return array(
				'success'   => false,
				'error'     => 'WordPress Post Reader tool call missing required source_url parameter',
				'tool_name' => 'wordpress_post_reader',
			);
		}

		$source_url   = sanitize_url( $parameters['source_url'] );
		$include_meta = ! empty( $parameters['include_meta'] );

		$post_id = url_to_postid( $source_url );
		if ( ! $post_id ) {
			return array(
				'success'   => false,
				'error'     => sprintf( 'Could not extract valid WordPress post ID from URL: %s', $source_url ),
				'tool_name' => 'wordpress_post_reader',
			);
		}

		$post = get_post( $post_id );

		if ( ! $post || $post->post_status === 'trash' ) {
			return array(
				'success'   => false,
				'error'     => sprintf( 'Post at URL %s (ID: %d) not found or is trashed', $source_url, $post_id ),
				'tool_name' => 'wordpress_post_reader',
			);
		}

		$title        = $post->post_title;
		$content      = $post->post_content;
		$permalink    = get_permalink( $post_id );
		$post_type    = get_post_type( $post_id );
		$post_status  = $post->post_status;
		$publish_date = get_the_date( 'Y-m-d H:i:s', $post_id );
		$author_name  = get_the_author_meta( 'display_name', (int) $post->post_author );

		$content_length     = strlen( $content );
		$content_word_count = str_word_count( wp_strip_all_tags( $content ) );

		$featured_image_url = null;
		$featured_image_id  = get_post_thumbnail_id( $post_id );
		if ( $featured_image_id ) {
			$featured_image_url = wp_get_attachment_image_url( $featured_image_id, 'full' );
		}

		$response_data = array(
			'post_id'            => $post_id,
			'title'              => $title,
			'content'            => $content,
			'content_length'     => $content_length,
			'content_word_count' => $content_word_count,
			'permalink'          => $permalink,
			'post_type'          => $post_type,
			'post_status'        => $post_status,
			'publish_date'       => $publish_date,
			'author'             => $author_name,
			'featured_image'     => $featured_image_url,
		);

		if ( $include_meta ) {
			$meta_fields = get_post_meta( $post_id );
			$clean_meta  = array();
			foreach ( $meta_fields as $key => $values ) {
				if ( strpos( $key, '_' ) === 0 ) {
					continue;
				}
				$clean_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}
			$response_data['meta_fields'] = $clean_meta;
		} else {
			$response_data['meta_fields'] = array();
		}

		$message = $content_length > 0
			? "READ COMPLETE: Retrieved WordPress post from \"{$permalink}\". Content Length: {$content_length} characters ({$content_word_count} words)"
			: "READ COMPLETE: WordPress post found at \"{$permalink}\" but has no content.";

		$response_data['message'] = $message;

		return array(
			'success'   => true,
			'data'      => $response_data,
			'tool_name' => 'wordpress_post_reader',
		);
	}

	/**
	 * Get WordPress Post Reader tool definition.
	 * Called lazily when tool is first accessed to ensure translations are loaded.
	 *
	 * @return array Tool definition array
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'name'            => 'WordPress Post Reader',
			'description'     => 'Read full content and metadata from a specific WordPress post by permalink URL. Use after Local Search when you need complete post content instead of excerpts. Accepts standard WordPress permalinks (e.g., /post-slug/) or shortlinks (?p=123). Does NOT accept REST API URLs (/wp-json/...). Essential for content analysis before WordPress Update operations.',
			'requires_config' => false,
			'parameters'      => array(
				'source_url'   => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'WordPress permalink URL (e.g., https://site.com/post-slug/ or https://site.com/?p=123). Do not use REST API URLs.',
				),
				'include_meta' => array(
					'type'        => 'boolean',
					'required'    => false,
					'description' => 'Include custom fields in response (default: false)',
				),
			),
		);
	}

	public static function is_configured(): bool {
		return true;
	}

	public function check_configuration( $configured, $tool_id ) {
		if ( 'wordpress_post_reader' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}
}
