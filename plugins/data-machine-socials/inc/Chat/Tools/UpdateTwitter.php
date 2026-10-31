<?php
/**
 * Update Twitter Chat Tool
 *
 * @package    DataMachineSocials
 * @subpackage Chat\Tools
 * @since      0.3.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class UpdateTwitter extends AbstractSocialTool {

	protected string $tool_name = 'update_twitter';

	protected string $platform = 'twitter';

	protected string $platform_label = 'Twitter';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Update Twitter/X content. Delete tweets, retweet, unretweet, like, or unlike tweets. Requires Twitter OAuth to be configured.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'action'   => array(
						'type'        => 'string',
						'description' => 'Action: "delete" (remove tweet), "retweet", "unretweet", "like", "unlike"',
						'enum'        => array( 'delete', 'retweet', 'unretweet', 'like', 'unlike' ),
					),
					'tweet_id' => array(
						'type'        => 'string',
						'description' => 'Tweet ID to operate on.',
					),
				),
				'required'   => array( 'action', 'tweet_id' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_name = 'update_twitter';
		$action    = $parameters['action'] ?? '';

		if ( empty( $parameters['tweet_id'] ) ) {
			return $this->buildErrorResponse( 'tweet_id is required', $tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		// Get the ability.
		$ability = wp_get_ability( 'datamachine/twitter-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/twitter-update ability not registered', $tool_name );
		}

		$ability = wp_get_ability( 'datamachine/twitter-update' );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'datamachine/twitter-update ability not registered', $tool_name );
		}
		$ability_instance = $ability;
		$result           = $ability_instance->execute( array(
			'action'   => sanitize_text_field( $action ),
			'tweet_id' => sanitize_text_field( $parameters['tweet_id'] ),
		) );

		if ( ! is_wp_error( $result ) && $result['success'] ) {
			$message = '';
			switch ( $action ) {
				case 'delete':
					$message = 'Tweet deleted successfully!';
					break;
				case 'retweet':
					$message = 'Tweet retweeted successfully!';
					break;
				case 'unretweet':
					$message = 'Tweet unretweeted successfully!';
					break;
				case 'like':
					$message = 'Tweet liked successfully!';
					break;
				case 'unlike':
					$message = 'Tweet unliked successfully!';
					break;
			}

			return array(
				'result'   => $message,
				'tweet_id' => $result['data']['tweet_id'] ?? $parameters['tweet_id'],
				'data'     => $result['data'],
			);
		}

		return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'Update failed' ), $tool_name );
	}
}
