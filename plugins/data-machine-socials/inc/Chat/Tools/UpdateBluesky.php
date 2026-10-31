<?php
/**
 * Update Bluesky Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class UpdateBluesky extends AbstractSocialTool {

	protected string $tool_name = 'update_bluesky';

	protected string $platform = 'bluesky';

	protected string $platform_label = 'Bluesky';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Bluesky posts. Delete posts or like posts. Requires Bluesky app password to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action: "delete", "like", "unlike"',
						'enum'        => array( 'delete', 'like', 'unlike' ),
					),
					'post_uri' => array(
						'type'        => 'string',
						'description' => 'Bluesky post URI (at://...).',
					),
				),
				'required'   => array( 'action', 'post_uri' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_bluesky';

		if ( empty( $parameters['post_uri'] ) ) {
			return $this->buildErrorResponse( 'post_uri is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/bluesky-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/bluesky-update ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$result           = $ability_instance->execute( array(
			'action'   => sanitize_text_field( $parameters['action'] ),
			'post_uri' => sanitize_text_field( $parameters['post_uri'] ),
		) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			$action = $parameters['action'];
			$msg    = 'delete' === $action ? 'Post deleted!' : 'Post liked!';
			return array(
				'result'   => $msg,
				'post_uri' => $result['data']['post_uri'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Update failed' ), $tool_name );
	}
}
