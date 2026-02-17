<?php
/**
 * Image Generation Abilities
 *
 * Primitive ability for AI image generation via Replicate API.
 * All image generation — tools, CLI, REST, chat — flows through this ability.
 *
 * Includes optional prompt refinement: uses Data Machine's configured AI provider
 * to craft detailed image generation prompts from simple inputs. Inspired by the
 * modular prompt pattern — a configurable style guide shapes every generated image.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.23.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\HttpClient;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;
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
	const VALID_ASPECT_RATIOS = [ '1:1', '3:4', '4:3', '9:16', '16:9' ];

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
				[
					'label'               => 'Generate Image',
					'description'         => 'Generate an image using AI models via Replicate API',
					'category'            => 'datamachine',
					'input_schema'        => [
						'type'       => 'object',
						'required'   => [ 'prompt' ],
						'properties' => [
							'prompt'          => [
								'type'        => 'string',
								'description' => 'Image generation prompt. If prompt refinement is enabled, this will be enriched by the AI before generation.',
							],
							'model'           => [
								'type'        => 'string',
								'description' => 'Replicate model identifier (default: google/imagen-4-fast).',
							],
							'aspect_ratio'    => [
								'type'        => 'string',
								'description' => 'Image aspect ratio: 1:1, 3:4, 4:3, 9:16, 16:9 (default: 3:4).',
							],
							'pipeline_job_id' => [
								'type'        => 'integer',
								'description' => 'Pipeline job ID for featured image assignment after publish.',
							],
							'post_id'         => [
								'type'        => 'integer',
								'description' => 'Post ID to set the generated image as featured image (for standalone/direct calls).',
							],

							'mode'            => [
								'type'        => 'string',
								'description' => 'Post-sideload behavior: featured (set as featured image, default) or insert (insert image block into post content).',
							],
							'position'        => [
								'type'        => 'string',
								'description' => 'Where to insert image in content (insert mode only): after_intro (default), before_heading, end, or index:N.',
							],
							'post_context'    => [
								'type'        => 'string',
								'description' => 'Optional post content/excerpt for context-aware prompt refinement.',
							],
						],
					],
					'output_schema'       => [
						'type'       => 'object',
						'properties' => [
							'success'       => [ 'type' => 'boolean' ],
							'pending'       => [ 'type' => 'boolean' ],
							'job_id'        => [ 'type' => 'integer' ],
							'prediction_id' => [ 'type' => 'string' ],
							'message'       => [ 'type' => 'string' ],
							'error'         => [ 'type' => 'string' ],
						],
					],
					'execute_callback'    => [ self::class, 'generateImage' ],
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => [ 'show_in_rest' => false ],
				]
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
	 * Optionally refines the prompt using Data Machine's configured AI provider,
	 * then starts a Replicate prediction and hands off to the System Agent
	 * for async polling and completion.
	 *
	 * @param array $input Ability input.
	 * @return array Ability response.
	 */
	public static function generateImage( array $input ): array {
		$prompt = sanitize_text_field( $input['prompt'] ?? '' );

		if ( empty( $prompt ) ) {
			return [
				'success' => false,
				'error'   => 'Image generation requires a prompt.',
			];
		}

		$config = self::get_config();

		if ( empty( $config['api_key'] ) ) {
			return [
				'success' => false,
				'error'   => 'Image generation not configured. Add a Replicate API key in Settings.',
			];
		}

		$original_prompt = $prompt;
		$post_context    = $input['post_context'] ?? '';

		// Apply prompt refinement using DM's own AI engine.
		if ( self::is_refinement_enabled( $config ) ) {
			$refined = self::refine_prompt( $prompt, $post_context, $config );
			if ( ! empty( $refined ) ) {
				$prompt = $refined;
			}
			// If refinement fails, continue with the original prompt — never block.
		}

		$model        = ! empty( $input['model'] ) ? sanitize_text_field( $input['model'] ) : ( $config['default_model'] ?? self::DEFAULT_MODEL );
		$aspect_ratio = ! empty( $input['aspect_ratio'] ) ? sanitize_text_field( $input['aspect_ratio'] ) : ( $config['default_aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO );

		if ( ! in_array( $aspect_ratio, self::VALID_ASPECT_RATIOS, true ) ) {
			$aspect_ratio = self::DEFAULT_ASPECT_RATIO;
		}

		$input_params = self::buildInputParams( $prompt, $aspect_ratio, $model );

		// Start Replicate prediction.
		$result = HttpClient::post(
			"https://api.replicate.com/v1/models/{$model}/predictions",
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Token ' . $config['api_key'],
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( [
					'input' => $input_params,
				] ),
				'context' => 'Image Generation Ability',
			]
		);

		if ( ! $result['success'] ) {
			return [
				'success' => false,
				'error'   => 'Failed to start image generation: ' . ( $result['error'] ?? 'Unknown error' ),
			];
		}

		$prediction = json_decode( $result['data'], true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $prediction['id'] ) ) {
			return [
				'success' => false,
				'error'   => 'Invalid response from Replicate API.',
			];
		}

		// Hand off to System Agent for async polling.
		$context = [];
		if ( ! empty( $input['pipeline_job_id'] ) ) {
			$context['pipeline_job_id'] = (int) $input['pipeline_job_id'];
		}
		if ( ! empty( $input['post_id'] ) ) {
			$context['post_id'] = (int) $input['post_id'];
		}
		if ( ! empty( $input['mode'] ) ) {
			$context['mode'] = sanitize_text_field( $input['mode'] );
		}
		if ( ! empty( $input['position'] ) ) {
			$context['position'] = sanitize_text_field( $input['position'] );
		}

		$systemAgent = SystemAgent::getInstance();
		$jobId       = $systemAgent->scheduleTask(
			'image_generation',
			[
				'prediction_id'   => $prediction['id'],
				'model'           => $model,
				'prompt'          => $prompt,
				'original_prompt' => $original_prompt,
				'aspect_ratio'    => $aspect_ratio,
				'prompt_refined'  => $prompt !== $original_prompt,
			],
			$context
		);

		if ( ! $jobId ) {
			return [
				'success' => false,
				'error'   => 'Failed to schedule image generation task.',
			];
		}

		return [
			'success'       => true,
			'pending'       => true,
			'job_id'        => $jobId,
			'prediction_id' => $prediction['id'],
			'message'       => "Image generation scheduled (Job #{$jobId}). Model: {$model}, aspect ratio: {$aspect_ratio}."
				. ( $prompt !== $original_prompt ? ' Prompt was refined by AI.' : '' ),
		];
	}

	/**
	 * Refine a raw prompt using Data Machine's configured AI provider.
	 *
	 * Uses the same AI infrastructure as pipeline and chat agents — no external
	 * API keys needed beyond what's already configured in DM settings. The style
	 * guide is user-configurable in the image generation tool settings.
	 *
	 * @param string $raw_prompt   Raw user/pipeline prompt (e.g., article title).
	 * @param string $post_context Optional post content for context-aware refinement.
	 * @param array  $config       Image generation config.
	 * @return string|null Refined prompt on success, null on failure.
	 */
	public static function refine_prompt( string $raw_prompt, string $post_context = '', array $config = [] ): ?string {
		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		if ( empty( $provider ) || empty( $model ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Image Generation: Prompt refinement skipped — no AI provider configured',
				[ 'raw_prompt' => $raw_prompt ]
			);
			return null;
		}

		if ( empty( $config ) ) {
			$config = self::get_config();
		}

		$style_guide = $config['prompt_style_guide'] ?? self::get_default_style_guide();

		// Build the refinement request with context.
		$user_message = $raw_prompt;
		if ( ! empty( $post_context ) ) {
			$user_message .= "\n\n---\nArticle context:\n" . mb_substr( $post_context, 0, 1000 );
		}

		$messages = [
			[
				'role'    => 'system',
				'content' => $style_guide,
			],
			[
				'role'    => 'user',
				'content' => $user_message,
			],
		];

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			[], // No tools needed for prompt refinement.
			'system',
			[ 'purpose' => 'image_prompt_refinement' ]
		);

		if ( empty( $response['success'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'Image Generation: Prompt refinement AI request failed',
				[
					'error'      => $response['error'] ?? 'Unknown error',
					'raw_prompt' => $raw_prompt,
					'provider'   => $provider,
				]
			);
			return null;
		}

		$refined = trim( $response['data']['content'] ?? '' );

		if ( empty( $refined ) ) {
			return null;
		}

		do_action(
			'datamachine_log',
			'info',
			'Image Generation: Prompt refined',
			[
				'original' => $raw_prompt,
				'refined'  => mb_substr( $refined, 0, 200 ),
				'provider' => $provider,
			]
		);

		return $refined;
	}

	/**
	 * Check if prompt refinement is enabled.
	 *
	 * Refinement requires: the setting to be enabled (default: true) AND a
	 * default AI provider to be configured in DM settings.
	 *
	 * @param array $config Image generation config.
	 * @return bool
	 */
	public static function is_refinement_enabled( array $config = [] ): bool {
		if ( empty( $config ) ) {
			$config = self::get_config();
		}

		// Default to enabled — opt-out, not opt-in.
		$enabled = $config['prompt_refinement_enabled'] ?? true;

		if ( ! $enabled ) {
			return false;
		}

		// Must have a DM AI provider configured.
		$provider = PluginSettings::get( 'default_provider', '' );
		$model    = PluginSettings::get( 'default_model', '' );

		return ! empty( $provider ) && ! empty( $model );
	}

	/**
	 * Get the default style guide for prompt refinement.
	 *
	 * This is the system prompt sent to the AI when refining image prompts.
	 * Users can customize this in the image generation tool settings.
	 *
	 * @return string Default style guide.
	 */
	public static function get_default_style_guide(): string {
		return implode( "\n", [
			'You are an expert at crafting image generation prompts. Transform the input into a detailed, vivid prompt for AI image generation models (Imagen, Flux, etc.).',
			'',
			'Your refined prompt should specify:',
			'- Visual style: photography, digital art, watercolor, oil painting, illustration, etc.',
			'- Composition: framing, perspective, focal point, depth of field',
			'- Lighting: natural, golden hour, dramatic, soft, backlit, etc.',
			'- Color palette and mood/atmosphere',
			'- Subject details: specific, concrete visual descriptions',
			'- Environment and background context',
			'',
			'Rules:',
			'- NEVER include text, words, letters, numbers, or writing in the image. AI models cannot render text well.',
			'- Keep the prompt under 200 words but make it specific and evocative.',
			'- If article context is provided, ensure the image captures the essence of the content.',
			'- Output ONLY the refined prompt. No explanations, no prefixes, no labels.',
		] );
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
			return [
				'prompt'              => $prompt,
				'aspect_ratio'        => $aspect_ratio,
				'output_format'       => 'jpg',
				'safety_filter_level' => 'block_only_high',
			];
		}

		// Flux and other models.
		return [
			'prompt'         => $prompt,
			'num_outputs'    => 1,
			'aspect_ratio'   => $aspect_ratio,
			'output_format'  => 'webp',
			'output_quality' => 90,
		];
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
		return get_site_option( self::CONFIG_OPTION, [] );
	}
}
