<?php
/**
 * Image Generation Abilities
 *
 * Primitive ability for AI image generation via Replicate API.
 * All image generation — tools, CLI, REST, chat — flows through this ability.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.23.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Engine\AI\System\SystemAgent;

defined( 'ABSPATH' ) || exit;

class ImageGenerationAbilities {

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
	 * Valid aspect ratios.
	 *
	 * @var array
	 */
	const VALID_ASPECT_RATIOS = array( '1:1', '3:4', '4:3', '9:16', '16:9' );

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return;
		}

		if ( self::$registered ) {
			return;
		}

		$this->registerAbilities();
		self::$registered = true;
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/generate-image',
				array(
					'label'               => 'Generate Image',
					'description'         => 'Generate an image using AI models via Replicate API',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'prompt' ),
						'properties' => array(
							'prompt'       => array(
								'type'        => 'string',
								'description' => 'Detailed image generation prompt.',
							),
							'model'        => array(
								'type'        => 'string',
								'description' => 'Replicate model identifier (default: google/imagen-4-fast).',
							),
							'aspect_ratio' => array(
								'type'        => 'string',
								'description' => 'Image aspect ratio: 1:1, 3:4, 4:3, 9:16, 16:9 (default: 3:4).',
							),
							'pipeline_job_id' => array(
								'type'        => 'integer',
								'description' => 'Pipeline job ID for featured image assignment after publish.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'       => array( 'type' => 'boolean' ),
							'pending'       => array( 'type' => 'boolean' ),
							'job_id'        => array( 'type' => 'integer' ),
							'prediction_id' => array( 'type' => 'string' ),
							'message'       => array( 'type' => 'string' ),
							'error'         => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'generateImage' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Generate an image via Replicate API.
	 *
	 * Starts a Replicate prediction and hands off to the System Agent
	 * for async polling and completion.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function generateImage( array $input ): array {
		$prompt = sanitize_text_field( $input['prompt'] ?? '' );

		if ( empty( $prompt ) ) {
			return array(
				'success' => false,
				'error'   => 'Image generation requires a prompt.',
			);
		}

		$config = self::get_config();

		if ( empty( $config['api_key'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Image generation not configured. Add a Replicate API key in Settings.',
			);
		}

		$model        = ! empty( $input['model'] ) ? sanitize_text_field( $input['model'] ) : ( $config['default_model'] ?? self::DEFAULT_MODEL );
		$aspect_ratio = ! empty( $input['aspect_ratio'] ) ? sanitize_text_field( $input['aspect_ratio'] ) : ( $config['default_aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO );

		if ( ! in_array( $aspect_ratio, self::VALID_ASPECT_RATIOS, true ) ) {
			$aspect_ratio = self::DEFAULT_ASPECT_RATIO;
		}

		$input_params = self::buildInputParams( $prompt, $aspect_ratio, $model );

		// Start Replicate prediction.
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
						'model' => $model,
						'input' => $input_params,
					)
				),
				'context' => 'Image Generation Ability',
			)
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'error'   => 'Failed to start image generation: ' . ( $result['error'] ?? 'Unknown error' ),
			);
		}

		$prediction = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $prediction['id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid response from Replicate API.',
			);
		}

		// Hand off to System Agent for async polling.
		$context = [];
		if ( ! empty( $input['pipeline_job_id'] ) ) {
			$context['pipeline_job_id'] = (int) $input['pipeline_job_id'];
		}

		$systemAgent = SystemAgent::getInstance();
		$jobId       = $systemAgent->scheduleTask(
			'image_generation',
			array(
				'prediction_id' => $prediction['id'],
				'model'         => $model,
				'prompt'        => $prompt,
				'aspect_ratio'  => $aspect_ratio,
			),
			$context
		);

		if ( ! $jobId ) {
			return array(
				'success' => false,
				'error'   => 'Failed to schedule image generation task.',
			);
		}

		return array(
			'success'       => true,
			'pending'       => true,
			'job_id'        => $jobId,
			'prediction_id' => $prediction['id'],
			'message'       => "Image generation scheduled (Job #{$jobId}). Model: {$model}, aspect ratio: {$aspect_ratio}.",
		);
	}

	/**
	 * Build model-specific input parameters for Replicate.
	 *
	 * @param string $prompt       Image generation prompt.
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $model        Model identifier.
	 * @return array Input parameters.
	 */
	private static function buildInputParams( string $prompt, string $aspect_ratio, string $model ): array {
		if ( false !== strpos( $model, 'imagen' ) ) {
			return array(
				'prompt'              => $prompt,
				'aspect_ratio'        => $aspect_ratio,
				'output_format'       => 'jpg',
				'safety_filter_level' => 'block_only_high',
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
}
