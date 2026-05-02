<?php
/**
 * Image Generation Abilities
 *
 * Primitive ability for AI image generation via wp-ai-client.
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
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\RequestBuilder;
use DataMachine\Engine\Tasks\TaskScheduler;

defined( 'ABSPATH' ) || exit;

class ImageGenerationAbilities {

	/**
	 * Option key for storing image generation configuration.
	 *
	 * @var string
	 */
	const CONFIG_OPTION = 'datamachine_image_generation_config';

	/**
	 * Default provider identifier for wp-ai-client image generation.
	 *
	 * @var string
	 */
	const DEFAULT_PROVIDER = 'openai';

	/**
	 * Default model identifier for wp-ai-client image generation.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gpt-image-1';

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
					'description'         => 'Generate an image using AI models via wp-ai-client',
					'category'            => 'datamachine-media',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'prompt' ),
						'properties' => array(
							'prompt'          => array(
								'type'        => 'string',
								'description' => 'Image generation prompt. If prompt refinement is enabled, this will be enriched by the AI before generation.',
							),
							'model'           => array(
								'type'        => 'string',
								'description' => 'wp-ai-client model identifier. Defaults to the image generation tool configuration.',
							),
							'provider'        => array(
								'type'        => 'string',
								'description' => 'wp-ai-client provider identifier. Defaults to the image generation tool configuration.',
							),
							'aspect_ratio'    => array(
								'type'        => 'string',
								'description' => 'Image aspect ratio: 1:1, 3:4, 4:3, 9:16, 16:9 (default: 3:4).',
							),
							'pipeline_job_id' => array(
								'type'        => 'integer',
								'description' => 'Pipeline job ID for featured image assignment after publish.',
							),
							'post_id'         => array(
								'type'        => 'integer',
								'description' => 'Post ID to set the generated image as featured image (for direct calls).',
							),

							'mode'            => array(
								'type'        => 'string',
								'description' => 'Post-sideload behavior: featured (set as featured image, default) or insert (insert image block into post content).',
							),
							'position'        => array(
								'type'        => 'string',
								'description' => 'Where to insert image in content (insert mode only): after_intro (default), before_heading, end, or index:N.',
							),
							'post_context'    => array(
								'type'        => 'string',
								'description' => 'Optional post content/excerpt for context-aware prompt refinement.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'pending'   => array( 'type' => 'boolean' ),
							'job_id'    => array( 'type' => 'integer' ),
							'image_url' => array( 'type' => 'string' ),
							'message'   => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'generateImage' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Generate an image via wp-ai-client.
	 *
	 * Optionally refines the prompt using Data Machine's configured AI provider,
	 * then asks wp-ai-client to generate the image and hands off to the System
	 * Agent for media sideloading and post mutation.
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
		if ( empty( $config ) ) {
			return array(
				'success' => false,
				'error'   => 'Image generation not configured. Select a wp-ai-client provider and model in Settings.',
			);
		}

		$provider = ! empty( $input['provider'] ) ? sanitize_text_field( $input['provider'] ) : ( $config['default_provider'] ?? self::DEFAULT_PROVIDER );
		$model    = ! empty( $input['model'] ) ? sanitize_text_field( $input['model'] ) : ( $config['default_model'] ?? self::DEFAULT_MODEL );

		if ( empty( $provider ) || empty( $model ) ) {
			return array(
				'success' => false,
				'error'   => 'Image generation not configured. Select a wp-ai-client provider and model in Settings.',
			);
		}

		$unavailable_reason = RequestBuilder::wpAiClientUnavailableReason( $provider );
		if ( null !== $unavailable_reason ) {
			return array(
				'success' => false,
				'error'   => $unavailable_reason,
			);
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

		$aspect_ratio = ! empty( $input['aspect_ratio'] ) ? sanitize_text_field( $input['aspect_ratio'] ) : ( $config['default_aspect_ratio'] ?? self::DEFAULT_ASPECT_RATIO );

		if ( ! in_array( $aspect_ratio, self::VALID_ASPECT_RATIOS, true ) ) {
			$aspect_ratio = self::DEFAULT_ASPECT_RATIO;
		}

		try {
			\DataMachine\Engine\AI\WpAiClientCache::install();

			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			/** @var callable $has_provider wp-ai-client exposes this through __call() in some versions. */
			$has_provider = array( $registry, 'hasProvider' );
			if ( ! call_user_func( $has_provider, $provider ) ) {
				throw new \InvalidArgumentException( sprintf( 'Provider %s is not registered in wp-ai-client', esc_html( $provider ) ) );
			}

			/** @var callable $provider_id_resolver wp-ai-client exposes this through __call() in some versions. */
			$provider_id_resolver = array( $registry, 'getProviderId' );
			$provider_id          = call_user_func( $provider_id_resolver, $provider );
			$api_key              = \DataMachine\Engine\AI\WpAiClientProviderAdmin::resolveApiKey( $provider );
			if ( '' !== $api_key ) {
				$registry->setProviderRequestAuthentication(
					$provider_id,
					new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
				);
			}

			/** @var callable $model_resolver wp-ai-client exposes this through __call() in some versions. */
			$model_resolver = array( $registry, 'getProviderModel' );
			$image_builder  = \wp_ai_client_prompt( $prompt )
				->using_provider( $provider_id )
				->using_model( call_user_func( $model_resolver, $provider_id, $model, null ) );

			/** @var callable $file_type_setter wp-ai-client prompt builders expose this through __call() in some versions. */
			$file_type_setter = array( $image_builder, 'as_output_file_type' );
			$image_builder    = call_user_func( $file_type_setter, \WordPress\AiClient\Files\Enums\FileTypeEnum::remote() );
			$image_builder    = $image_builder->as_output_media_aspect_ratio( $aspect_ratio );

			$supported = $image_builder->is_supported_for_image_generation();
			if ( is_wp_error( $supported ) ) {
				$image_file = $supported;
			} elseif ( ! $supported ) {
				$image_file = new \WP_Error( 'wp_ai_client_image_unsupported', sprintf( 'wp-ai-client model "%s" does not support image generation for provider "%s"', $model, $provider_id ) );
			} else {
				$image_file = $image_builder->generate_image();
			}
		} catch ( \Throwable $e ) {
			$image_file = new \WP_Error( 'wp_ai_client_image_exception', 'wp-ai-client image generation threw: ' . $e->getMessage() );
		}

		if ( $image_file instanceof \WP_Error || ! is_object( $image_file ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to generate image: ' . ( $image_file instanceof \WP_Error ? $image_file->get_error_message() : 'wp-ai-client returned no image file.' ),
			);
		}

		$image_url      = is_callable( array( $image_file, 'getUrl' ) ) ? (string) call_user_func( array( $image_file, 'getUrl' ) ) : '';
		$image_data_uri = is_callable( array( $image_file, 'getDataUri' ) ) ? (string) call_user_func( array( $image_file, 'getDataUri' ) ) : '';

		if ( '' === $image_url && '' === $image_data_uri ) {
			return array(
				'success' => false,
				'error'   => 'wp-ai-client image generation returned no usable image.',
			);
		}

		// Hand off to System Agent for async media handling and post mutation.
		$context = array();
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

		$jobId = TaskScheduler::schedule(
			'image_generation',
			array(
				'image_url'       => $image_url,
				'image_data_uri'  => $image_data_uri,
				'provider'        => $provider,
				'model'           => $model,
				'prompt'          => $prompt,
				'original_prompt' => $original_prompt,
				'aspect_ratio'    => $aspect_ratio,
				'prompt_refined'  => $prompt !== $original_prompt,
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
			'success'   => true,
			'pending'   => true,
			'job_id'    => $jobId,
			'image_url' => $image_url,
			'message'   => "Image generation scheduled (Job #{$jobId}). Model: {$model}, aspect ratio: {$aspect_ratio}."
				. ( $prompt !== $original_prompt ? ' Prompt was refined by AI.' : '' ),
		);
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
	public static function refine_prompt( string $raw_prompt, string $post_context = '', array $config = array() ): ?string {
		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentMode( $agent_id, 'system' );
		$provider        = $system_defaults['provider'];
		$model           = $system_defaults['model'];

		if ( empty( $provider ) || empty( $model ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Image Generation: Prompt refinement skipped — no AI provider configured',
				array( 'raw_prompt' => $raw_prompt )
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

		$messages = array(
			\DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'system', $style_guide ),
			\DataMachine\Engine\AI\ConversationManager::buildConversationMessage( 'user', $user_message ),
		);

		$response = RequestBuilder::build(
			$messages,
			$provider,
			$model,
			array(), // No tools needed for prompt refinement.
			'system',
			array( 'purpose' => 'image_prompt_refinement' )
		);

		if ( $response instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'warning',
				'Image Generation: Prompt refinement AI request failed',
				array(
					'error'      => $response->get_error_message(),
					'raw_prompt' => $raw_prompt,
					'provider'   => $provider,
				)
			);
			return null;
		}

		$refined = trim( RequestBuilder::resultText( $response ) );

		if ( empty( $refined ) ) {
			return null;
		}

		do_action(
			'datamachine_log',
			'info',
			'Image Generation: Prompt refined',
			array(
				'original' => $raw_prompt,
				'refined'  => mb_substr( $refined, 0, 200 ),
				'provider' => $provider,
			)
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
	public static function is_refinement_enabled( array $config = array() ): bool {
		if ( empty( $config ) ) {
			$config = self::get_config();
		}

		// Default to enabled — opt-out, not opt-in.
		$enabled = $config['prompt_refinement_enabled'] ?? true;

		if ( ! $enabled ) {
			return false;
		}

		// Must have a DM AI provider configured.
		$user_id         = get_current_user_id();
		$agent_id        = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;
		$system_defaults = PluginSettings::resolveModelForAgentMode( $agent_id, 'system' );

		return ! empty( $system_defaults['provider'] ) && ! empty( $system_defaults['model'] );
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
		return implode( "\n", array(
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
		) );
	}

	/**
	 * Check if image generation is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$config = self::get_config();
		if ( empty( $config ) ) {
			return false;
		}

		$provider = $config['default_provider'] ?? self::DEFAULT_PROVIDER;
		$model    = $config['default_model'] ?? self::DEFAULT_MODEL;

		return ! empty( $provider ) && ! empty( $model ) && null === RequestBuilder::wpAiClientUnavailableReason( (string) $provider );
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
