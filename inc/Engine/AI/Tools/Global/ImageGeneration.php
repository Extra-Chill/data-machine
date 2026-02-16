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
		$this->registerGlobalTool( 'image_generation', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute image generation by delegating to the ability.
	 *
	 * @param array $parameters Contains 'prompt' and optional 'model', 'aspect_ratio'.
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
			'description'     => 'Generate images using AI models (Google Imagen 4, Flux, etc.) via Replicate. Returns a URL to the generated image. Use descriptive, detailed prompts for best results. Default aspect ratio is 3:4 (portrait, ideal for Pinterest and blog featured images).',
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
					'description' => 'Replicate model identifier (default: google/imagen-4-fast). Other options: black-forest-labs/flux-schnell, etc.',
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
	 * Save configuration from settings page.
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param array  $config_data Configuration data.
	 */
	public function save_configuration( $tool_id, $config_data ) {
		if ( 'image_generation' !== $tool_id ) {
			return;
		}

		$api_key = sanitize_text_field( $config_data['api_key'] ?? '' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Replicate API key is required', 'data-machine' ) ) );
			return;
		}

		$config = array(
			'api_key'              => $api_key,
			'default_model'        => sanitize_text_field( $config_data['default_model'] ?? ImageGenerationAbilities::DEFAULT_MODEL ),
			'default_aspect_ratio' => sanitize_text_field( $config_data['default_aspect_ratio'] ?? ImageGenerationAbilities::DEFAULT_ASPECT_RATIO ),
		);

		if ( update_site_option( ImageGenerationAbilities::CONFIG_OPTION, $config ) ) {
			wp_send_json_success(
				array(
					'message'    => __( 'Image generation configuration saved successfully', 'data-machine' ),
					'configured' => true,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to save configuration', 'data-machine' ) ) );
		}
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
			'api_key'              => array(
				'type'        => 'password',
				'label'       => __( 'Replicate API Key', 'data-machine' ),
				'placeholder' => __( 'Enter your Replicate API key', 'data-machine' ),
				'required'    => true,
				'description' => __( 'Get your API key from replicate.com/account/api-tokens', 'data-machine' ),
			),
			'default_model'        => array(
				'type'        => 'text',
				'label'       => __( 'Default Model', 'data-machine' ),
				'placeholder' => ImageGenerationAbilities::DEFAULT_MODEL,
				'required'    => false,
				'description' => __( 'Replicate model identifier. Default: google/imagen-4-fast. AI agents can override per-call.', 'data-machine' ),
			),
			'default_aspect_ratio' => array(
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
		);
	}
}

// Self-register the tool.
new ImageGeneration();
