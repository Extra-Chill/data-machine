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

	public function __construct() {		if ( self::$registered ) {
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
					'category'            => 'datamachine-media',
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
							'output'      => array(
								'type'        => 'string',
								'description' => 'Output destination: "files" returns file paths (default, requires context for repository or returns temp paths), "attachment" creates WordPress attachments and returns IDs/URLs.',
								'enum'        => array( 'files', 'attachment' ),
								'default'     => 'files',
							),
							'attachment'  => array(
								'type'        => 'object',
								'description' => 'Attachment options when output=attachment. Supports parent_post_id (attach to a post), title, alt_text.',
								'properties'  => array(
									'parent_post_id' => array( 'type' => 'integer' ),
									'title'          => array( 'type' => 'string' ),
									'alt_text'       => array( 'type' => 'string' ),
								),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'        => array( 'type' => 'boolean' ),
							'file_paths'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'attachment_ids' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer' ),
							),
							'attachment_urls' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'template_id'    => array( 'type' => 'string' ),
							'message'        => array( 'type' => 'string' ),
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
					'category'            => 'datamachine-media',
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
		$output      = $input['output'] ?? 'files';
		$attachment  = $input['attachment'] ?? array();

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

		// Default output mode — return file paths as-is.
		if ( 'attachment' !== $output ) {
			return array(
				'success'     => true,
				'file_paths'  => $file_paths,
				'template_id' => $template_id,
				'message'     => sprintf( 'Generated %d image(s) using template "%s"', count( $file_paths ), $template_id ),
			);
		}

		// Attachment output — convert each rendered file into a WP attachment.
		$attachment_result = self::convertFilesToAttachments( $file_paths, $template_id, $attachment );

		return array_merge(
			array(
				'success'     => ! empty( $attachment_result['attachment_ids'] ),
				'template_id' => $template_id,
			),
			$attachment_result
		);
	}

	/**
	 * Convert rendered template files into WordPress attachments.
	 *
	 * Each file is sideloaded into the media library via wp_insert_attachment().
	 * Original temp files are removed after sideload completes.
	 *
	 * @param string[] $file_paths       Rendered file paths from the template.
	 * @param string   $template_id      Template identifier (used in fallback titles).
	 * @param array    $attachment_opts  Attachment options (parent_post_id, title, alt_text).
	 * @return array {
	 *     @type string[] $file_paths      Original file paths (for parity with files mode).
	 *     @type int[]    $attachment_ids  Created attachment post IDs.
	 *     @type string[] $attachment_urls Public URLs for the created attachments.
	 *     @type string   $message         Human-readable summary.
	 * }
	 */
	private static function convertFilesToAttachments( array $file_paths, string $template_id, array $attachment_opts ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$parent_post_id = isset( $attachment_opts['parent_post_id'] ) ? (int) $attachment_opts['parent_post_id'] : 0;
		$title          = isset( $attachment_opts['title'] ) ? (string) $attachment_opts['title'] : '';
		$alt_text       = isset( $attachment_opts['alt_text'] ) ? (string) $attachment_opts['alt_text'] : '';

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return array(
				'file_paths'      => $file_paths,
				'attachment_ids'  => array(),
				'attachment_urls' => array(),
				'message'         => sprintf( 'Upload directory error: %s', $upload_dir['error'] ),
			);
		}

		$attachment_ids  = array();
		$attachment_urls = array();
		$failures        = array();

		foreach ( $file_paths as $index => $source_path ) {
			if ( ! file_exists( $source_path ) ) {
				$failures[] = basename( $source_path );
				continue;
			}

			$basename     = basename( $source_path );
			$safe_name    = wp_unique_filename( $upload_dir['path'], $basename );
			$dest_path    = trailingslashit( $upload_dir['path'] ) . $safe_name;

			if ( ! @copy( $source_path, $dest_path ) ) {
				$failures[] = $basename;
				continue;
			}

			// Best-effort cleanup of the source temp file.
			wp_delete_file( $source_path );

			$filetype = wp_check_filetype( $dest_path );

			$attachment_title = $title;
			if ( '' === $attachment_title ) {
				$attachment_title = sprintf( '%s-%d', $template_id, $index + 1 );
			}

			$attachment_data = array(
				'guid'           => trailingslashit( $upload_dir['url'] ) . $safe_name,
				'post_mime_type' => $filetype['type'] ?: 'image/png',
				'post_title'     => sanitize_text_field( $attachment_title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment_data, $dest_path, $parent_post_id, true );

			if ( is_wp_error( $attach_id ) || ! $attach_id ) {
				wp_delete_file( $dest_path );
				$failures[] = $basename;
				continue;
			}

			$metadata = wp_generate_attachment_metadata( $attach_id, $dest_path );
			wp_update_attachment_metadata( $attach_id, $metadata );

			if ( '' !== $alt_text ) {
				update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			}

			$attachment_ids[]  = (int) $attach_id;
			$attachment_urls[] = wp_get_attachment_url( (int) $attach_id );
		}

		$message = sprintf(
			'Created %d attachment(s) from template "%s"',
			count( $attachment_ids ),
			$template_id
		);

		if ( ! empty( $failures ) ) {
			$message .= sprintf( ' — %d failed (%s)', count( $failures ), implode( ', ', $failures ) );
		}

		return array(
			'file_paths'      => $file_paths,
			'attachment_ids'  => $attachment_ids,
			'attachment_urls' => $attachment_urls,
			'message'         => $message,
		);
	}

	/**
	 * List all registered templates with their metadata.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result with template definitions.
	 */
	public static function listTemplates( array $input ): array {
		$input;
		return array(
			'success'   => true,
			'templates' => TemplateRegistry::get_template_definitions(),
			'presets'   => PlatformPresets::all(),
		);
	}
}
