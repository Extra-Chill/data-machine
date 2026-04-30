<?php
/**
 * Image Template Abilities
 *
 * Ability for rendering images from registered GD templates.
 * Complements ImageGenerationAbilities (AI image models) with
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
								'description' => 'Output destination: "files" returns file paths (default, requires context for repository or returns temp paths), "cached_file" stores the rendered file under uploads/<bucket>/<key>.<ext> and returns a stable public URL — ideal for OG images and other long-lived public artifacts that should not pollute the media library.',
								'enum'        => array( 'files', 'cached_file' ),
								'default'     => 'files',
							),
							'cache'       => array(
								'type'        => 'object',
								'description' => 'Cache options when output=cached_file. Required when output=cached_file.',
								'properties'  => array(
									'bucket' => array(
										'type'        => 'string',
										'description' => 'Subdirectory under wp-content/uploads/ (slug-style; sanitized).',
									),
									'key'    => array(
										'type'        => 'string',
										'description' => 'Stable filename stem within the bucket (sanitized). Re-renders overwrite atomically.',
									),
								),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'file_paths'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'cached_paths' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'cached_urls'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'template_id'  => array( 'type' => 'string' ),
							'message'      => array( 'type' => 'string' ),
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
		$cache_opts  = $input['cache'] ?? array();

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
		if ( 'cached_file' !== $output ) {
			return array(
				'success'     => true,
				'file_paths'  => $file_paths,
				'template_id' => $template_id,
				'message'     => sprintf( 'Generated %d image(s) using template "%s"', count( $file_paths ), $template_id ),
			);
		}

		// Cached file output — copy each rendered file under uploads/<bucket>/<key>.<ext>.
		$cached_result = self::copyFilesToCachedLocation( $file_paths, $template_id, $cache_opts );

		return array_merge(
			array(
				'success'     => ! empty( $cached_result['cached_urls'] ),
				'template_id' => $template_id,
			),
			$cached_result
		);
	}

	/**
	 * Copy rendered template files to a stable cached location under uploads/.
	 *
	 * Files land at `wp-content/uploads/<bucket>/<key>[-<n>].<ext>`. Existing
	 * files at the destination are overwritten, so re-rendering the same key
	 * naturally invalidates and replaces. The bucket is intentionally outside
	 * the YYYY/MM media library tree so cached artifacts do not pollute the
	 * media browser, do not get resized, and do not interact with Imagify or
	 * other media-pipeline plugins.
	 *
	 * Source temp files are removed after the copy to avoid leaking PHP tmp.
	 *
	 * @param string[] $file_paths   Rendered file paths from the template.
	 * @param string   $template_id  Template identifier (for log/message context).
	 * @param array    $cache_opts   { bucket, key } — both required.
	 * @return array {
	 *     @type string[] $file_paths   Original file paths (for parity with files mode).
	 *     @type string[] $cached_paths Absolute filesystem paths of cached copies.
	 *     @type string[] $cached_urls  Public URLs of cached copies.
	 *     @type string   $message      Human-readable summary.
	 * }
	 */
	private static function copyFilesToCachedLocation( array $file_paths, string $template_id, array $cache_opts ): array {
		$bucket = isset( $cache_opts['bucket'] ) ? sanitize_file_name( (string) $cache_opts['bucket'] ) : '';
		$key    = isset( $cache_opts['key'] ) ? sanitize_file_name( (string) $cache_opts['key'] ) : '';

		if ( '' === $bucket || '' === $key ) {
			return array(
				'file_paths'   => $file_paths,
				'cached_paths' => array(),
				'cached_urls'  => array(),
				'message'      => 'cached_file output requires cache.bucket and cache.key',
			);
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return array(
				'file_paths'   => $file_paths,
				'cached_paths' => array(),
				'cached_urls'  => array(),
				'message'      => sprintf( 'Upload directory error: %s', $upload_dir['error'] ),
			);
		}

		$bucket_dir = trailingslashit( $upload_dir['basedir'] ) . $bucket;
		$bucket_url = trailingslashit( $upload_dir['baseurl'] ) . $bucket;

		if ( ! wp_mkdir_p( $bucket_dir ) ) {
			return array(
				'file_paths'   => $file_paths,
				'cached_paths' => array(),
				'cached_urls'  => array(),
				'message'      => sprintf( 'Failed to create cache directory: %s', $bucket_dir ),
			);
		}

		$cached_paths = array();
		$cached_urls  = array();
		$failures     = array();

		foreach ( $file_paths as $index => $source_path ) {
			if ( ! file_exists( $source_path ) ) {
				$failures[] = basename( $source_path );
				continue;
			}

			$ext       = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
			$ext       = '' !== $ext ? $ext : 'png';
			$suffix    = count( $file_paths ) > 1 ? '-' . ( $index + 1 ) : '';
			$filename  = $key . $suffix . '.' . $ext;
			$dest_path = trailingslashit( $bucket_dir ) . $filename;
			$dest_url  = trailingslashit( $bucket_url ) . $filename;

			if ( ! copy( $source_path, $dest_path ) ) {
				$failures[] = basename( $source_path );
				continue;
			}

			wp_delete_file( $source_path );

			$cached_paths[] = $dest_path;
			$cached_urls[]  = $dest_url;
		}

		$message = sprintf(
			'Cached %d image(s) under %s/%s for template "%s"',
			count( $cached_paths ),
			$bucket,
			$key,
			$template_id
		);

		if ( ! empty( $failures ) ) {
			$message .= sprintf( ' — %d failed (%s)', count( $failures ), implode( ', ', $failures ) );
		}

		return array(
			'file_paths'   => $file_paths,
			'cached_paths' => $cached_paths,
			'cached_urls'  => $cached_urls,
			'message'      => $message,
		);
	}

	/**
	 * List all registered templates with their metadata.
	 *
	 * @param array $input Input parameters (unused).
	 * @return array Result with template definitions.
	 */
	public static function listTemplates( array $input ): array {
		unset( $input );
		return array(
			'success'   => true,
			'templates' => TemplateRegistry::get_template_definitions(),
			'presets'   => PlatformPresets::all(),
		);
	}
}
