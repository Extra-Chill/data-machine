<?php
/**
 * Image Template Abilities
 *
 * Ability for rendering images from registered GD templates.
 * Complements ImageGenerationAbilities (AI/Replicate) with
 * deterministic, text-heavy, brand-consistent graphic output.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.32.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ImageTemplateAbilities {

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
				'datamachine/render-image-template',
				array(
					'label'               => 'Render Image Template',
					'description'         => 'Generate branded graphics from registered GD templates',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'template_id', 'data' ),
						'properties' => array(
							'template_id' => array(
								'type'        => 'string',
								'description' => 'Template identifier (e.g. quote_card, event_roundup)',
							),
							'data'        => array(
								'type'        => 'object',
								'description' => 'Structured data matching the template fields',
							),
							'preset'      => array(
								'type'        => 'string',
								'description' => 'Platform preset override (e.g. instagram_feed_portrait)',
							),
							'format'      => array(
								'type'        => 'string',
								'description' => 'Output format: png or jpeg',
								'enum'        => array( 'png', 'jpeg' ),
								'default'     => 'png',
							),
							'context'     => array(
								'type'        => 'object',
								'description' => 'Storage context with pipeline_id and flow_id for repository storage',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'     => array( 'type' => 'boolean' ),
							'file_paths'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'template_id' => array( 'type' => 'string' ),
							'message'     => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'renderTemplate' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => false ),
				)
			);

			wp_register_ability(
				'datamachine/list-image-templates',
				array(
					'label'               => 'List Image Templates',
					'description'         => 'List all registered image generation templates',
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'   => array( 'type' => 'boolean' ),
							'templates' => array( 'type' => 'object' ),
						),
					),
					'execute_callback'    => array( self::class, 'listTemplates' ),
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
	 * Render an image from a registered template.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with success flag and file paths.
	 */
	public static function renderTemplate( array $input ): array {
		$template_id = $input['template_id'] ?? '';
		$data        = $input['data'] ?? array();
		$preset      = $input['preset'] ?? '';
		$format      = $input['format'] ?? 'png';
		$context     = $input['context'] ?? array();

		if ( empty( $template_id ) ) {
			return array(
				'success' => false,
				'message' => 'template_id is required',
			);
		}

		$template = TemplateRegistry::get( $template_id );
		if ( ! $template ) {
			$available = implode( ', ', array_keys( TemplateRegistry::all() ) );
			return array(
				'success' => false,
				'message' => sprintf(
					'Template "%s" not found. Available templates: %s',
					$template_id,
					$available ? $available : 'none registered'
				),
			);
		}

		// Validate required fields for single-item mode.
		// Skip validation when data contains a batch array (e.g. 'quotes')
		// — the template handles per-item validation internally.
		$has_batch_data = false;
		foreach ( $data as $value ) {
			if ( is_array( $value ) && array_is_list( $value ) ) {
				$has_batch_data = true;
				break;
			}
		}

		if ( ! $has_batch_data ) {
			$missing = array();
			foreach ( $template->get_fields() as $field_key => $field_def ) {
				if ( ! empty( $field_def['required'] ) && empty( $data[ $field_key ] ) ) {
					$missing[] = $field_key;
				}
			}

			if ( ! empty( $missing ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						'Missing required fields for template "%s": %s',
						$template_id,
						implode( ', ', $missing )
					),
				);
			}
		}

		$renderer = new GDRenderer();
		$options  = array(
			'format' => $format,
		);

		if ( ! empty( $preset ) ) {
			$options['preset'] = $preset;
		}

		if ( ! empty( $context ) ) {
			$options['context'] = $context;
		}

		$file_paths = $template->render( $data, $renderer, $options );

		if ( empty( $file_paths ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Template "%s" rendered but produced no output', $template_id ),
			);
		}

		return array(
			'success'     => true,
			'file_paths'  => $file_paths,
			'template_id' => $template_id,
			'message'     => sprintf( 'Generated %d image(s) using template "%s"', count( $file_paths ), $template_id ),
		);
	}

	/**
	 * List all registered templates with their metadata.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result with template definitions.
	 */
	public static function listTemplates( array $input ): array {
		return array(
			'success'   => true,
			'templates' => TemplateRegistry::get_template_definitions(),
			'presets'   => PlatformPresets::all(),
		);
	}
}
