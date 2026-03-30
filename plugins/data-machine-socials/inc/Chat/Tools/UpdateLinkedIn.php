<?php
/**
 * Update LinkedIn Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.5.0
 */

namespace DataMachineSocials\Chat\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachine\Abilities\AuthAbilities;

defined( 'ABSPATH' ) || exit;

class UpdateLinkedIn extends BaseTool {

	public function __construct() {
		$this->registerTool( 'update_linkedin', array( $this, 'getToolDefinition' ), array( 'chat' ) );
	}

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update the commentary (text) of an existing LinkedIn post. Requires LinkedIn OAuth to be configured.',
			'parameters'  => array(
				'post_id'    => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Post URN to update (e.g., urn:li:share:12345).',
				),
				'commentary' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'New commentary text for the post.',
				),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_linkedin';

		if ( empty( $parameters['post_id'] ) ) {
			return $this->buildErrorResponse( 'post_id is required', $tool_name );
		}

		if ( empty( $parameters['commentary'] ) ) {
			return $this->buildErrorResponse( 'commentary is required', $tool_name );
		}

		$auth_abilities = new AuthAbilities();
		$provider       = $auth_abilities->getProvider( 'linkedin' );

		if ( ! $provider ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn auth provider not available',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_registered',
				),
				array(
					'action'    => 'configure_linkedin_auth',
					'message'   => 'Configure LinkedIn OAuth in Data Machine Settings > Auth.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		if ( ! $provider->is_authenticated() ) {
			return $this->buildDiagnosticErrorResponse(
				'LinkedIn is not authenticated',
				'prerequisite_missing',
				$tool_name,
				array(
					'provider' => 'linkedin',
					'status'   => 'not_authenticated',
				),
				array(
					'action'    => 'authenticate_linkedin',
					'message'   => 'Connect LinkedIn via Data Machine Settings > Auth > LinkedIn.',
					'tool_hint' => 'authenticate_handler',
				)
			);
		}

		$ability = wp_get_ability( 'datamachine/linkedin-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'LinkedIn update ability not registered', $tool_name );
		}

		$result = $ability->execute( array(
			'post_id'    => sanitize_text_field( $parameters['post_id'] ),
			'commentary' => sanitize_textarea_field( $parameters['commentary'] ),
		) );

		if ( is_wp_error( $result ) || ! $this->isAbilitySuccess( $result ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : $this->getAbilityError( $result, 'Failed to update LinkedIn post' ), $tool_name );
		}

		return array(
			'result'    => 'LinkedIn post updated successfully!',
			'post_id'   => $result['post_id'] ?? '',
			'tool_name' => $tool_name,
		);
	}
}
