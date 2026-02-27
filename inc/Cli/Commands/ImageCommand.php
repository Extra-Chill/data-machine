<?php
/**
 * WP-CLI Image Command
 *
 * Provides CLI access to AI image generation (Replicate) and
 * GD template rendering. Wraps ImageGenerationAbilities and
 * ImageTemplateAbilities.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.33.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Media\ImageGenerationAbilities;
use DataMachine\Abilities\Media\ImageTemplateAbilities;

defined( 'ABSPATH' ) || exit;

class ImageCommand extends BaseCommand {

	/**
	 * Generate an AI image via Replicate.
	 *
	 * Starts an async prediction on Replicate and schedules a System Agent
	 * task to poll for completion. Optionally refines the prompt via the
	 * configured AI provider before sending to the image model.
	 *
	 * ## OPTIONS
	 *
	 * <prompt>
	 * : Image generation prompt text.
	 *
	 * [--post_id=<id>]
	 * : Post ID to attach the generated image to.
	 *
	 * [--model=<model>]
	 * : Replicate model identifier (default: google/imagen-4-fast).
	 *
	 * [--aspect_ratio=<ratio>]
	 * : Image aspect ratio: 1:1, 3:4, 4:3, 9:16, 16:9 (default: 3:4).
	 *
	 * [--mode=<mode>]
	 * : Post-sideload behavior: featured (default) or insert.
	 *
	 * [--position=<position>]
	 * : Where to insert image in content (insert mode only): after_intro, before_heading, end, or index:N.
	 *
	 * [--post_context=<context>]
	 * : Post content/excerpt for context-aware prompt refinement.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a featured image for a post
	 *     $ wp datamachine image generate "Sunset over Austin skyline" --post_id=123
	 *
	 *     # Generate with a specific model and aspect ratio
	 *     $ wp datamachine image generate "Abstract watercolor" --model=black-forest-labs/flux-1.1-pro --aspect_ratio=16:9
	 *
	 *     # Insert image into post content after the intro
	 *     $ wp datamachine image generate "Jazz band on stage" --post_id=456 --mode=insert --position=after_intro
	 *
	 *     # Generate with post context for smarter prompt refinement
	 *     $ wp datamachine image generate "Article header" --post_id=789 --post_context="A deep dive into coral reef conservation..."
	 *
	 * @subcommand generate
	 */
	public function generate( array $args, array $assoc_args ): void {
		$prompt = $args[0] ?? '';

		if ( empty( $prompt ) ) {
			WP_CLI::error( 'Required: <prompt> (image generation prompt text).' );
			return;
		}

		if ( ! ImageGenerationAbilities::is_configured() ) {
			WP_CLI::error( 'Image generation not configured. Add a Replicate API key in Settings → Image Generation.' );
			return;
		}

		$input = array( 'prompt' => $prompt );

		if ( ! empty( $assoc_args['post_id'] ) ) {
			$input['post_id'] = \absint( $assoc_args['post_id'] );
		}
		if ( ! empty( $assoc_args['model'] ) ) {
			$input['model'] = $assoc_args['model'];
		}
		if ( ! empty( $assoc_args['aspect_ratio'] ) ) {
			$input['aspect_ratio'] = $assoc_args['aspect_ratio'];
		}
		if ( ! empty( $assoc_args['mode'] ) ) {
			$input['mode'] = $assoc_args['mode'];
		}
		if ( ! empty( $assoc_args['position'] ) ) {
			$input['position'] = $assoc_args['position'];
		}
		if ( ! empty( $assoc_args['post_context'] ) ) {
			$input['post_context'] = $assoc_args['post_context'];
		}

		WP_CLI::log( 'Starting image generation...' );

		$result = ImageGenerationAbilities::generateImage( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to start image generation.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Image generation scheduled.' );

		$items = array(
			array(
				'job_id'        => $result['job_id'] ?? '',
				'prediction_id' => $result['prediction_id'] ?? '',
				'status'        => $result['pending'] ? 'pending' : 'complete',
			),
		);

		$this->format_items( $items, array( 'job_id', 'prediction_id', 'status' ), $assoc_args );
	}

	/**
	 * Render an image from a registered GD template.
	 *
	 * Generates deterministic, brand-consistent graphics from structured data
	 * using server-side GD rendering. No external API calls required.
	 *
	 * ## OPTIONS
	 *
	 * --template_id=<template>
	 * : Template identifier (e.g. quote_card, event_roundup). Use 'templates' subcommand to list available templates.
	 *
	 * --data=<json>
	 * : JSON string of structured data matching the template fields.
	 *
	 * [--preset=<preset>]
	 * : Platform preset override (e.g. instagram_feed_portrait).
	 *
	 * [--format=<format>]
	 * : Output image format: png or jpeg (default: png). Note: this controls the image format, not the CLI output format.
	 *
	 * [--pipeline_id=<id>]
	 * : Pipeline ID for file repository storage context.
	 *
	 * [--flow_id=<id>]
	 * : Flow ID for file repository storage context.
	 *
	 * ## EXAMPLES
	 *
	 *     # Render a quote card
	 *     $ wp datamachine image render --template_id=quote_card --data='{"quote":"Be the change","author":"Gandhi"}'
	 *
	 *     # Render with a platform preset
	 *     $ wp datamachine image render --template_id=event_roundup --data='{"events":[...]}' --preset=instagram_feed_portrait
	 *
	 *     # Render as JPEG
	 *     $ wp datamachine image render --template_id=quote_card --data='{"quote":"Hello world","author":"Dev"}' --format=jpeg
	 *
	 * @subcommand render
	 */
	public function render( array $args, array $assoc_args ): void {
		$template_id = $assoc_args['template_id'] ?? '';
		$data_json   = $assoc_args['data'] ?? '';

		if ( empty( $template_id ) ) {
			WP_CLI::error( 'Required: --template_id=<template>. Use "wp datamachine image templates" to list available templates.' );
			return;
		}

		if ( empty( $data_json ) ) {
			WP_CLI::error( 'Required: --data=<json> (JSON string of template data).' );
			return;
		}

		$data = json_decode( $data_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_CLI::error( 'Invalid JSON in --data: ' . json_last_error_msg() );
			return;
		}

		$input = array(
			'template_id' => $template_id,
			'data'        => $data,
		);

		if ( ! empty( $assoc_args['preset'] ) ) {
			$input['preset'] = $assoc_args['preset'];
		}
		if ( ! empty( $assoc_args['format'] ) ) {
			$input['format'] = $assoc_args['format'];
		}

		$context = array();
		if ( ! empty( $assoc_args['pipeline_id'] ) ) {
			$context['pipeline_id'] = \absint( $assoc_args['pipeline_id'] );
		}
		if ( ! empty( $assoc_args['flow_id'] ) ) {
			$context['flow_id'] = \absint( $assoc_args['flow_id'] );
		}
		if ( ! empty( $context ) ) {
			$input['context'] = $context;
		}

		WP_CLI::log( "Rendering template \"{$template_id}\"..." );

		$result = ImageTemplateAbilities::renderTemplate( $input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['message'] ?? 'Template rendering failed.' );
			return;
		}

		WP_CLI::success( $result['message'] ?? 'Template rendered.' );

		$file_paths = $result['file_paths'] ?? array();
		$items      = array();
		foreach ( $file_paths as $i => $path ) {
			$items[] = array(
				'index' => $i + 1,
				'path'  => $path,
			);
		}

		if ( ! empty( $items ) ) {
			$this->format_items( $items, array( 'index', 'path' ), $assoc_args );
		}
	}

	/**
	 * List available image templates.
	 *
	 * Shows all registered GD image templates with their fields and presets.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all templates
	 *     $ wp datamachine image templates
	 *
	 *     # JSON output
	 *     $ wp datamachine image templates --format=json
	 *
	 * @subcommand templates
	 */
	public function templates( array $args, array $assoc_args ): void {
		$result = ImageTemplateAbilities::listTemplates( array() );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( \wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$templates = $result['templates'] ?? array();

		if ( empty( $templates ) ) {
			WP_CLI::log( 'No image templates registered. Templates are registered by downstream plugins via the datamachine/image_generation/templates filter.' );
			return;
		}

		$items = array();
		foreach ( $templates as $id => $def ) {
			$fields   = $def['fields'] ?? array();
			$required = array();
			$optional = array();

			foreach ( $fields as $field_key => $field_def ) {
				if ( ! empty( $field_def['required'] ) ) {
					$required[] = $field_key;
				} else {
					$optional[] = $field_key;
				}
			}

			$items[] = array(
				'template_id' => $id,
				'label'       => $def['label'] ?? $id,
				'required'    => empty( $required ) ? '-' : implode( ', ', $required ),
				'optional'    => empty( $optional ) ? '-' : implode( ', ', $optional ),
			);
		}

		$this->format_items( $items, array( 'template_id', 'label', 'required', 'optional' ), $assoc_args );

		// Show presets.
		$presets = $result['presets'] ?? array();
		if ( ! empty( $presets ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%GAvailable presets:%n' ) );

			$preset_items = array();
			foreach ( $presets as $preset_id => $preset_def ) {
				$preset_items[] = array(
					'preset'      => $preset_id,
					'width'       => $preset_def['width'] ?? '',
					'height'      => $preset_def['height'] ?? '',
					'description' => $preset_def['description'] ?? '',
				);
			}

			$this->format_items( $preset_items, array( 'preset', 'width', 'height', 'description' ), $assoc_args );
		}
	}

	/**
	 * Check image generation configuration status.
	 *
	 * Shows whether the Replicate API key is configured and prompt
	 * refinement is enabled.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp datamachine image status
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$configured    = ImageGenerationAbilities::is_configured();
		$refinement    = ImageGenerationAbilities::is_refinement_enabled();
		$config        = ImageGenerationAbilities::get_config();
		$default_model = $config['default_model'] ?? ImageGenerationAbilities::DEFAULT_MODEL;
		$default_ratio = $config['default_aspect_ratio'] ?? ImageGenerationAbilities::DEFAULT_ASPECT_RATIO;

		$items = array(
			array(
				'setting' => 'Replicate API Key',
				'value'   => $configured ? 'Configured' : 'Not configured',
			),
			array(
				'setting' => 'Prompt Refinement',
				'value'   => $refinement ? 'Enabled' : 'Disabled',
			),
			array(
				'setting' => 'Default Model',
				'value'   => $default_model,
			),
			array(
				'setting' => 'Default Aspect Ratio',
				'value'   => $default_ratio,
			),
		);

		$this->format_items( $items, array( 'setting', 'value' ), $assoc_args );
	}
}
