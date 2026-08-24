<?php
/**
 * Image generation tool settings adapter.
 *
 * Provides settings-page configuration for the image generation ability tool.
 *
 * @package DataMachine\Engine\AI\Configuration
 */

namespace DataMachine\Engine\AI\Configuration;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class ImageGenerationSettings extends BaseTool {

	public function __construct() {
		$this->registerConfigurationHandlers( 'image_generation' );
	}

	/**
	 * Check if image generation is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return ImageGenerationAbilities::is_configured();
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return ImageGenerationAbilities::get_config();
	}

	/**
	 * Check if this tool is configured.
	 *
	 * @param bool   $configured Current status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		if ( 'image_generation' !== $tool_id ) {
			return $configured;
		}

		return self::is_configured();
	}

	/**
	 * Get current configuration.
	 *
	 * @param array  $config  Current config.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_configuration( $config, $tool_id ) {
		if ( 'image_generation' !== $tool_id ) {
			return $config;
		}

		return self::get_config();
	}

	/**
	 * Get configuration option name.
	 *
	 * @return string
	 */
	protected function get_config_option_name(): string {
		return ImageGenerationAbilities::CONFIG_OPTION;
	}

	/**
	 * Validate and normalize settings-page configuration.
	 *
	 * @param array $config_data Configuration data.
	 * @return array
	 */
	protected function validate_and_build_config( array $config_data ): array {
		$provider = sanitize_text_field( $config_data['default_provider'] ?? '' );
		$model    = sanitize_text_field( $config_data['default_model'] ?? '' );

		if ( ( '' === $provider ) !== ( '' === $model ) ) {
			return array( 'error' => __( 'wp-ai-client provider and model must be configured together', 'data-machine' ) );
		}

		return array(
			'config'  => array(
				'default_provider'          => $provider,
				'default_model'             => $model,
				'default_aspect_ratio'      => sanitize_text_field( $config_data['default_aspect_ratio'] ?? ImageGenerationAbilities::DEFAULT_ASPECT_RATIO ),
				'prompt_refinement_enabled' => ! empty( $config_data['prompt_refinement_enabled'] ),
				'prompt_style_guide'        => sanitize_textarea_field( $config_data['prompt_style_guide'] ?? '' ),
			),
			'message' => __( 'Image generation configuration saved successfully', 'data-machine' ),
		);
	}

	/**
	 * Get configuration field definitions for the settings page.
	 *
	 * @param array  $fields  Current fields.
	 * @param string $tool_id Tool identifier.
	 * @return array
	 */
	public function get_config_fields( $fields = array(), $tool_id = '' ) {
		if ( ! empty( $tool_id ) && 'image_generation' !== $tool_id ) {
			return $fields;
		}

		return array(
			'default_provider'          => array(
				'type'        => 'text',
				'label'       => __( 'Default Provider', 'data-machine' ),
				'placeholder' => __( 'Provider id', 'data-machine' ),
				'required'    => false,
				'description' => __( 'wp-ai-client provider identifier used when an image-generation request does not provide one.', 'data-machine' ),
			),
			'default_model'             => array(
				'type'        => 'text',
				'label'       => __( 'Default Model', 'data-machine' ),
				'placeholder' => __( 'Model id', 'data-machine' ),
				'required'    => false,
				'description' => __( 'wp-ai-client image model identifier used when an image-generation request does not provide one.', 'data-machine' ),
			),
			'default_aspect_ratio'      => array(
				'type'        => 'select',
				'label'       => __( 'Default Aspect Ratio', 'data-machine' ),
				'required'    => false,
				'options'     => array(
					'1:1'  => '1:1 (Square)',
					'3:4'  => '3:4 (Portrait)',
					'4:3'  => '4:3 (Landscape)',
					'9:16' => '9:16 (Tall)',
					'16:9' => '16:9 (Wide)',
				),
				'description' => __( 'Default aspect ratio for generated images. 3:4 (portrait) is ideal for Pinterest and blog featured images.', 'data-machine' ),
			),
			'prompt_refinement_enabled' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable Prompt Refinement', 'data-machine' ),
				'required'    => false,
				'description' => __( 'Use Data Machine\'s AI to craft detailed image prompts from simple inputs. Requires a default AI provider in DM settings. Enabled by default.', 'data-machine' ),
			),
			'prompt_style_guide'        => array(
				'type'        => 'textarea',
				'label'       => __( 'Image Style Guide', 'data-machine' ),
				'placeholder' => ImageGenerationAbilities::get_default_style_guide(),
				'required'    => false,
				'description' => __( 'System prompt that guides how image prompts are refined. Customize this to match your brand\'s visual style. Leave empty to use the default.', 'data-machine' ),
			),
		);
	}
}
