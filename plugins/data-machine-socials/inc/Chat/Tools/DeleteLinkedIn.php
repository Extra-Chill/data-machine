<?php
/**
 * Delete LinkedIn Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeleteLinkedIn extends AbstractSocialTool {

	protected string $tool_name = 'delete_linkedin';

	protected string $platform = 'linkedin';

	protected string $platform_label = 'LinkedIn';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete a post from LinkedIn. Requires LinkedIn OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'string',
						'description' => 'Post URN to delete (e.g., urn:li:share:12345).',
					),
				),
				'required'   => array( 'post_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_linkedin';

		if ( empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/linkedin-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'LinkedIn delete ability not registered', $tool_name );
		}

		$result = $ability->execute( array(
			'post_id' => sanitize_text_field( $parameters['post_id'] ),
		) );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to delete LinkedIn post' ), $tool_name );
		}

		return array(
			'result'    => 'LinkedIn post deleted successfully!',
			'post_id'   => $result['post_id'] ?? '',
			'tool_name' => $tool_name,
		);
	}
}
