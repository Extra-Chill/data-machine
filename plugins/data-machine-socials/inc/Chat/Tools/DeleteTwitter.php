<?php
/**
 * Delete Twitter Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class DeleteTwitter extends AbstractSocialTool {

	protected string $tool_name = 'delete_twitter';

	protected string $platform = 'twitter';

	protected string $platform_label = 'Twitter';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Delete tweets. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'tweet_id' => array(
						'type'        => 'string',
						'description' => 'Tweet ID to delete.',
					),
				),
				'required'   => array( 'tweet_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'delete_twitter';

		if ( empty( $parameters['tweet_id'] ) ) {
			return $this->buildErrorResponse( 'tweet_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth( false );
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$ability = wp_get_ability( 'datamachine/twitter-delete' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/twitter-delete ability not registered', $tool_name );
		}
		$result  = $ability->execute( array( 'tweet_id' => $parameters['tweet_id'] ) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			return array(
				'result'   => 'Tweet deleted!',
				'tweet_id' => $parameters['tweet_id'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Delete failed' ), $tool_name );
	}
}
