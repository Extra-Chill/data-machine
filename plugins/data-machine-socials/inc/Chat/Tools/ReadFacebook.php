<?php
/**
 * Read Facebook Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class ReadFacebook extends AbstractSocialTool {

	protected string $tool_name = 'read_facebook';

	protected string $platform = 'facebook';

	protected string $platform_label = 'Facebook';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Read Facebook Page posts. List recent posts, get details for a specific post, or get comments. Requires Facebook OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array(
						'type'        => 'string',
						'description' => 'Action: "list" (recent posts), "get" (single post), "comments" (post comments). Defaults to "list".',
						'enum'        => array( 'list', 'get', 'comments' ),
					),
					'post_id' => array(
						'type'        => 'string',
						'description' => 'Facebook post ID. Required for "get" and "comments" actions.',
					),
					'limit'   => array(
						'type'        => 'integer',
						'description' => 'Number of items to return (max 100). Defaults to 25.',
					),
					'after'   => array(
						'type'        => 'string',
						'description' => 'Pagination cursor for next page.',
					),
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'read_facebook';
		$action    = $parameters['action'] ?? 'list';

		if ( in_array( $action, array( 'get', 'comments' ), true ) && empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( "post_id is required for the {$action} action", $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/facebook-read' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/facebook-read ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$input            = array( 'action' => sanitize_text_field( $action ) );

		if ( ! empty( $parameters['post_id'] ) ) {
			$input['post_id'] = sanitize_text_field( $parameters['post_id'] );
		}
		if ( ! empty( $parameters['limit'] ) ) {
			$input['limit'] = absint( $parameters['limit'] );
		}
		if ( ! empty( $parameters['after'] ) ) {
			$input['after'] = sanitize_text_field( $parameters['after'] );
		}

		$result = $ability_instance->execute( $input );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to read from Facebook' ), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result['data'] ?? array(),
			'tool_name' => $tool_name,
		);
	}
}
