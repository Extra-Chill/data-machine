<?php
/**
 * Read Tumblr Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.17.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadTumblr extends AbstractSocialTool {

	protected string $tool_name = 'read_tumblr';

	protected string $platform = 'tumblr';

	protected string $platform_label = 'Tumblr';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Tumblr posts and discover tagged content. Actions: posts (list a blog\'s posts), post (single post), info (blog info), tagged (discover public posts by tag across Tumblr). Requires Tumblr OAuth.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'          => array(
						'type'        => 'string',
						'description' => 'Action: "posts", "post", "info", or "tagged". Defaults to "posts".',
						'enum'        => array( 'posts', 'post', 'info', 'tagged' ),
					),
					'blog_identifier' => array(
						'type'        => 'string',
						'description' => 'Tumblr blog identifier. Required for posts, post, and info.',
					),
					'post_id'         => array(
						'type'        => 'string',
						'description' => 'Tumblr post ID. Required for "post".',
					),
					'tag'             => array(
						'type'        => 'string',
						'description' => 'Tag to discover. Required for "tagged".',
					),
					'limit'           => array(
						'type'        => 'integer',
						'description' => 'Number of items (tagged max 20, posts max 100). Defaults to 20.',
					),
					'before'          => array(
						'type'        => 'integer',
						'description' => 'Unix timestamp pagination cursor.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		$tool_name = 'read_tumblr';
		$action    = $parameters['action'] ?? 'posts';

		if ( 'post' === $action && ( empty( $parameters['blog_identifier'] ) || empty( $parameters['post_id'] ) ) ) {
			return $this->buildErrorResponse( 'blog_identifier and post_id are required for the post action', $tool_name );
		}
		if ( 'tagged' === $action && empty( $parameters['tag'] ) ) {
			return $this->buildErrorResponse( 'tag is required for the tagged action', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/tumblr-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/tumblr-read ability not registered', $tool_name );
		}

		$input = array( 'action' => sanitize_text_field( $action ) );
		if ( ! empty( $parameters['blog_identifier'] ) ) {
			$input['blog_identifier'] = sanitize_text_field( $parameters['blog_identifier'] );
		}
		if ( ! empty( $parameters['post_id'] ) ) {
			$input['post_id'] = sanitize_text_field( $parameters['post_id'] );
		}
		if ( ! empty( $parameters['tag'] ) ) {
			$input['tag'] = sanitize_text_field( $parameters['tag'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['before'] ) ) {
			$input['before'] = absint( $parameters['before'] );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from Tumblr' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
