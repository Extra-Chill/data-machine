<?php
/**
 * Publish Tumblr Chat Tool
 *
 * Chat tool for publishing Tumblr posts.
 * Wraps the datamachine/tumblr-publish ability for use by the Data Machine chat agent.
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.17.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishTumblr extends AbstractSocialTool {

	protected string $tool_name = 'publish_tumblr';

	protected string $platform = 'tumblr';

	protected string $platform_label = 'Tumblr';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a Tumblr post (Neue Post Format text post) with a title, body, tags, and source attribution. Requires Tumblr OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'title'           => array(
						'type'        => 'string',
						'description' => 'Optional post title (rendered as a heading).',
					),
					'body'            => array(
						'type'        => 'string',
						'description' => 'The main text body of the Tumblr post.',
					),
					'tags'            => array(
						'type'        => 'string',
						'description' => 'Optional comma-separated tags.',
					),
					'state'           => array(
						'type'        => 'string',
						'enum'        => array( 'published', 'queue', 'draft' ),
						'description' => 'Post state. Defaults to published.',
					),
					'source_url'      => array(
						'type'        => 'string',
						'description' => 'Optional source attribution URL (link back to origin).',
					),
					'blog_identifier' => array(
						'type'        => 'string',
						'description' => 'Target Tumblr blog: name or hostname. Required unless a default is configured.',
					),
				),
				'required'   => array( 'body' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$tool_name = 'publish_tumblr';

		if ( empty( $parameters['body'] ) ) {
			return $this->buildErrorResponse( 'body is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$input = array(
			'body' => sanitize_textarea_field( $parameters['body'] ),
		);

		if ( ! empty( $parameters['title'] ) ) {
			$input['title'] = sanitize_text_field( $parameters['title'] );
		}
		if ( ! empty( $parameters['tags'] ) ) {
			$input['tags'] = sanitize_text_field( $parameters['tags'] );
		}
		if ( ! empty( $parameters['state'] ) ) {
			$input['state'] = sanitize_text_field( $parameters['state'] );
		}
		if ( ! empty( $parameters['source_url'] ) ) {
			$input['source_url'] = sanitize_url( $parameters['source_url'] );
		}
		if ( ! empty( $parameters['blog_identifier'] ) ) {
			$input['blog_identifier'] = sanitize_text_field( $parameters['blog_identifier'] );
		}

		$result = \DataMachineSocials\Abilities\Tumblr\TumblrPublishAbility::execute_publish( $input );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'  => 'Post published to Tumblr!',
				'post_id'  => $result['post_id'] ?? '',
				'post_url' => $result['post_url'] ?? '',
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Tumblr publish failed' ), $tool_name );
	}
}
