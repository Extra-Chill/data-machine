<?php
/**
 * Read Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadBluesky extends AbstractSocialTool {

	protected string $tool_name = 'read_bluesky';

	protected string $platform = 'bluesky';

	protected string $platform_label = 'Bluesky';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Bluesky posts. List recent posts from your feed, get a post thread, or view your profile. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action: "list" (author feed), "get" (post thread), "profile" (user profile). Defaults to "list".',
						'enum'        => array( 'list', 'get', 'profile' ),
					),
					'post_uri' => array(
						'type'        => 'string',
						'description' => 'AT Protocol post URI (at://did:plc:.../app.bsky.feed.post/...). Required for "get" action.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Number of posts to return (max 100). Defaults to 25.',
					),
					'cursor'   => array(
						'type'        => 'string',
						'description' => 'Pagination cursor for next page.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_bluesky';
		$action    = $parameters['action'] ?? 'list';

		if ( 'get' === $action && empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required for the get action', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/bluesky-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/bluesky-read ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['post_uri'] ) ) {
			$input['post_uri'] = sanitize_text_field( $parameters['post_uri'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['cursor'] ) ) {
			$input['cursor'] = sanitize_text_field( $parameters['cursor'] );
		}

		$result = $ability_instance->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from Bluesky' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
