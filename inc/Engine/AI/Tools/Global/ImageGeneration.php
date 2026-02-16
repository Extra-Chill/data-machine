<?php
/**
 * Image Generation tool using Replicate API.
 *
 * Supports multiple models (Google Imagen 4 Fast, Flux, etc.) with
 * configurable defaults via the settings page. Available to all AI agents
 * during pipeline execution and chat.
 *
 * @package DataMachine\Engine\AI\Tools\Global
 */

namespace DataMachine\Engine\AI\Tools\Global;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\Tools\BaseTool;

class ImageGeneration extends BaseTool {

	/**
	 * Option key for storing image generation configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_image_generation_config';

	/**
	 * Default model identifier on Replicate.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'google/imagen-4-fast';

	/**
	 * Default aspect ratio for generated images.
	 *
	 * @var string
	 */
	const DEFAULT_ASPECT_RATIO = '3:4';

	/**
	 * Maximum seconds to poll for prediction completion.
	 *
	 * @var int
	 */
	const POLL_TIMEOUT = 120;

	/**
	 * Seconds between poll requests.
	 *
	 * @var int
	 */
	const POLL_INTERVAL = 2;

	/**
	 * Valid aspect ratios.
	 *
	 * @var array
	 */
	const VALID_ASPECT_RATIOS = array( '1:1', '3:4', '4:3', '9:16', '16:9' );

	public function __construct() {
		$this->registerConfigurationHandlers( 'image_generation' );
		$this->registerGlobalTool( 'image_generation', array( $this, 'getToolDefinition' ) );
	}

	/**
	 * Execute image generation via Replicate API.
	 *
	 * @param array $parameters Contains 'prompt' and optional 'model', 'aspect_ratio'.
	 * @param array $tool_def   Tool definition (unused).
	 * @return array Result with image URL on success.
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		if ( empty( $parameters['prompt'] ) ) {
			return $this->buildErrorResponse(
				'Image generation requires a prompt parameter.',
				'image_generation'
			);
		}

		$config = self::get_config();

		if ( empty( $config['api_key'] ) ) {
			return $this->buildErrorResponse(
				'Image generation tool not configured. Please add a Replicate API key in Settings.',
				'image_generation'
			);
		}

		$prompt       = sanitize_text_field( $parameters['prompt'] );
		$model        = ! empty( $parameters['model'] ) ? sanitize_text_field( $parameters['model'] ) : ( $config['default_model'] ?? self::DEFAULT_MODEL );
		$aspect_ratio = ! empty( $parameters['aspect_ratio'] ) ? sanitize_text_field( $parameters['aspect_ratio'] ) : ( $config['default_aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO );

		if ( ! in_array( $aspect_ratio, self::VALID_ASPECT_RATIOS, true ) ) {
			$aspect_ratio = self::DEFAULT_ASPECT_RATIO;
		}

		$input_params = $this->build_input_params( $prompt, $aspect_ratio, $model );

		// Start prediction.
		$result = HttpClient::post(
			'https://api.replicate.com/v1/predictions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Token ' . $config['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'version' => $model,
						'input'   => $input_params,
					)
				),
				'context' => 'Image Generation Tool',
			)
		);

		if ( ! $result['success'] ) {
			return $this->buildErrorResponse(
				'Failed to start image generation: ' . ( $result['error'] ?? 'Unknown error' ),
				'image_generation'
			);
		}

		$prediction = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $prediction['id'] ) ) {
			return $this->buildErrorResponse(
				'Invalid response from Replicate API.',
				'image_generation'
			);
		}

		// Poll for completion.
		$image_url = $this->poll_prediction( $prediction['id'], $config['api_key'] );

		if ( null === $image_url ) {
			return $this->buildErrorResponse(
				'Image generation timed out or failed. Check Replicate dashboard for details.',
				'image_generation'
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'message'      => "Image generated successfully using {$model}.",
				'image_url'    => $image_url,
				'prompt'       => $prompt,
				'model'        => $model,
				'aspect_ratio' => $aspect_ratio,
			),
			'tool_name' => 'image_generation',
		);
	}

	/**
	 * Build model-specific input parameters for Replicate.
	 *
	 * @param string $prompt       Image generation prompt.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $model        Model identifier.
	 * @return array Input parameters for the prediction.
	 */
	private function build_input_params( string $prompt, string $aspect_ratio, string $model ): array {
		if ( false !== strpos( $model, 'imagen' ) ) {
			return array(
				'prompt'               => $prompt,
				'aspect_ratio'         => $aspect_ratio,
				'output_format'        => 'jpg',
				'safety_filter_level'  => 'block_only_high',
			);
		}

		// Flux and other models.
		return array(
			'prompt'         => $prompt,
			'num_outputs'    => 1,
			'aspect_ratio'   => $aspect_ratio,
			'output_format'  => 'webp',
			'output_quality' => 90,
		);
	}

	/**
	 * Poll Replicate for prediction completion.
	 *
	 * @param string $prediction_id Prediction ID.
	 * @param string $api_key       Replicate API key.
	 * @return string|null Image URL or null on failure/timeout.
	 */
	private function poll_prediction( string $prediction_id, string $api_key ): ?string {
		$elapsed = 0;

		while ( $elapsed < self::POLL_TIMEOUT ) {
			sleep( self::POLL_INTERVAL );
			$elapsed += self::POLL_INTERVAL;

			$result = HttpClient::get(
				"https://api.replicate.com/v1/predictions/{$prediction_id}",
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Token ' . $api_key,
					),
					'context' => 'Image Generation Poll',
				)
			);

			if ( ! $result['success'] ) {
				continue;
			}

			$status = json_decode( $result['data'], true );

			if ( 'succeeded' === ( $status['status'] ?? '' ) ) {
				$output = $status['output'] ?? null;

				if ( is_string( $output ) ) {
					return $output;
				}

				if ( is_array( $output ) && ! empty( $output ) ) {
					return $output[0];
				}

				return null;
			}

			if ( 'failed' === ( $status['status'] ?? '' ) || 'canceled' === ( $status['status'] ?? '' ) ) {
				return null;
			}
		}

		return null;
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
		$config = self::get_config();
		return ! empty( $config['api_key'] );
	}

	/**
	 * Get stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		return get_site_option( self::CONFIG_OPTION, array() );
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
			'default_model'        => sanitize_text_field( $config_data['default_model'] ?? self::DEFAULT_MODEL ),
			'default_aspect_ratio' => sanitize_text_field( $config_data['default_aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO ),
		);

		if ( update_site_option( self::CONFIG_OPTION, $config ) ) {
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
				'placeholder' => self::DEFAULT_MODEL,
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
