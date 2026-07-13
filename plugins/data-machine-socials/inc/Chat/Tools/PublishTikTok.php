<?php
/**
 * Publish TikTok Chat Tool.
 *
 * @package DataMachineSocials
 * @subpackage Chat\Tools
 * @since 0.17.0
 */

namespace DataMachineSocials\Chat\Tools;

defined( 'ABSPATH' ) || exit;

class PublishTikTok extends AbstractSocialTool {

	protected string $tool_name = 'publish_tiktok';
	protected string $platform = 'tiktok';
	protected string $platform_label = 'TikTok';

	public function getToolDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'description' => 'Publish a video to TikTok from a public HTTPS video URL. Requires TikTok OAuth and a verified video-hosting domain. Public visibility requires TikTok Content Posting Audit; unaudited clients can only publish privately.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'caption'       => array(
						'type'        => 'string',
						'description' => 'Video caption, maximum 2200 characters.',
					),
					'video_url'     => array(
						'type'        => 'string',
						'description' => 'Public HTTPS video URL for TikTok to pull.',
					),
					'privacy_level' => array(
						'type'        => 'string',
						'enum'        => array( 'PUBLIC_TO_EVERYONE', 'SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR' ),
						'description' => 'Requested visibility. Use SELF_ONLY until the Content Posting Audit is approved.',
					),
				),
				'required'   => array( 'caption', 'video_url' ),
			),
		);
	}

	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$tool_def;
		if ( empty( $parameters['caption'] ) || empty( $parameters['video_url'] ) ) {
			return $this->buildErrorResponse( 'caption and video_url are required', $this->tool_name );
		}

		$auth_error = $this->guardAuth();
		if ( null !== $auth_error ) {
			return $auth_error;
		}

		$privacy_level = $parameters['privacy_level'] ?? 'PUBLIC_TO_EVERYONE';
		if ( ! in_array( $privacy_level, array( 'PUBLIC_TO_EVERYONE', 'SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR' ), true ) ) {
			return $this->buildErrorResponse( 'privacy_level is invalid', $this->tool_name );
		}

		$result = \DataMachineSocials\Abilities\TikTok\TikTokPublishAbility::execute_publish(
			array(
				'content'       => sanitize_textarea_field( $parameters['caption'] ),
				'video_url'     => sanitize_url( $parameters['video_url'] ),
				'privacy_level' => $privacy_level,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			return $this->buildErrorResponse( is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? 'TikTok publish failed.' ), $this->tool_name );
		}

		return array(
			'result'         => 'TikTok post submitted.',
			'publish_id'     => $result['publish_id'] ?? '',
			'status'         => $result['status'] ?? '',
			'public_post_id' => $result['public_post_id'] ?? '',
		);
	}
}
