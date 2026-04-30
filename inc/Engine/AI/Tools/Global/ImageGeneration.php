<?php
/**
 * Image Generation tool — AI agent wrapper for the generate-image ability.
 *
 * Provides the AI-callable tool interface and settings page configuration.
 * Delegates actual image generation to the datamachine/generate-image ability.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Engine\AI\Tools\BaseTool;

class ImageGeneration extends BaseTool {

	/**
	 * This tool uses async execution via the System Agent.
	 *
	 * @var bool
	 */
	protected bool $async = true;

	public function __construct() {
		$this->registerConfigurationHandlers( 'image_generation' );
		$this->registerTool( 'image_generation', array( $this, 'getToolDefinition' ), array( 'chat', 'pipeline' ), array( 'ability' => 'datamachine/generate-image' ) );
	}

	/**
	 * Execute image generation by delegating to the ability.
	 *
	 * @param array $parameters Contains 'prompt' and optional 'provider', 'model', 'aspect_ratio'.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result with pending status on success.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$ability = wp_get_ability( 'datamachine/generate-image' );

		if ( ! $ability ) {
			return $this->buildErrorResponse(
				'Image generation ability not registered. Ensure WordPress 6.9+ and ImageGenerationAbilities is loaded.',
				'image_generation'
			);
		}

		$input = array(
			'prompt'       => $parameters['prompt'] ?? '',
			'provider'     => $parameters['provider'] ?? '',
			'model'        => $parameters['model'] ?? '',
			'aspect_ratio' => $parameters['aspect_ratio'] ?? '',
		);

		// Pass pipeline job context for featured image assignment.
		if ( ! empty( $parameters['job_id'] ) ) {
			$input['pipeline_job_id'] = (int) $parameters['job_id'];
		}

		$result = $ability->execute( $input );

		// Handle WP_Error from ability execution.
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse(
				$result->get_error_message(),
				'image_generation'
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return $this->buildErrorResponse(
				$result['error'],
				'image_generation'
			);
		}

		// Return the ability result with tool_name added.
		$result['tool_name'] = 'image_generation';
		return $result;
	}

	/**
	 * Get tool definition for AI agents.
	 *
	 * @return array Tool definition array.
	 */
	public function getToolDefinition(): array {
		return array(
			'class'           => __CLASS__,
			'method'          => 'handle_tool_call',
			'description'     => 'Generate images using wp-ai-client image models. Returns a pending image-generation job that will sideload the generated image and optionally set it as featured media. Use descriptive, detailed prompts for best results. Default aspect ratio is 3:4 (portrait, ideal for Pinterest and blog featured images).',
			'requires_config' => true,
			'parameters'      => array(
				'prompt'       => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Detailed image generation prompt describing the desired image. Be specific about style, composition, lighting, and subject.',
				),
				'model'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'wp-ai-client model identifier. Defaults to the image generation tool configuration.',
				),
				'provider'     => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'wp-ai-client provider identifier. Defaults to the image generation tool configuration.',
				),
				'aspect_ratio' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Image aspect ratio. Options: 1:1, 3:4, 4:3, 9:16, 16:9. Default: 3:4 (portrait).',
				),
			),
		);
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
		$provider = sanitize_text_field( $config_data['default_provider'] ?? ImageGenerationAbilities::DEFAULT_PROVIDER );
		$model    = sanitize_text_field( $config_data['default_model'] ?? ImageGenerationAbilities::DEFAULT_MODEL );

		if ( empty( $provider ) || empty( $model ) ) {
			return array( 'error' => __( 'wp-ai-client provider and model are required', 'data-machine' ) );
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
				'placeholder' => ImageGenerationAbilities::DEFAULT_PROVIDER,
				'required'    => true,
				'description' => __( 'wp-ai-client provider identifier. API keys are configured through the provider settings, not this tool.', 'data-machine' ),
			),
			'default_model'             => array(
				'type'        => 'text',
				'label'       => __( 'Default Model', 'data-machine' ),
				'placeholder' => ImageGenerationAbilities::DEFAULT_MODEL,
				'required'    => false,
				'description' => __( 'wp-ai-client image model identifier. AI agents can override per-call.', 'data-machine' ),
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
