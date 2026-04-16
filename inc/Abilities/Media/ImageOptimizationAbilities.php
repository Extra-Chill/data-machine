<?php
/**
 * Image Optimization Abilities
 *
 * Diagnose and fix oversized/unoptimized images in the WordPress media library.
 * Uses WordPress's native image editor (Imagick or GD) for compression and
 * WebP conversion — no external API or plugin dependencies.
 *
 * @package DataMachine\Abilities\Media
 * @since 0.42.0
 */

namespace DataMachine\Abilities\Media;

use DataMachine\Abilities\PermissionHelper;
use DataMachine\Engine\Tasks\TaskScheduler;

defined( 'ABSPATH' ) || exit;

class ImageOptimizationAbilities {

	/**
	 * Default file size threshold in bytes (200KB).
	 */
	const DEFAULT_SIZE_THRESHOLD = 204800;

	/**
	 * Default JPEG/WebP quality for compression.
	 */
	const DEFAULT_QUALITY = 82;

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
				'datamachine/diagnose-images',
				array(
					'label'               => 'Diagnose Images',
					'description'         => 'Scan the media library for oversized images, missing WebP variants, and missing thumbnail sizes.',
					'category'            => 'datamachine-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'size_threshold' => array(
								'type'        => 'integer',
								'description' => 'File size threshold in bytes. Images larger than this are flagged. Default: 204800 (200KB).',
								'default'     => self::DEFAULT_SIZE_THRESHOLD,
							),
							'limit'          => array(
								'type'        => 'integer',
								'description' => 'Maximum images to scan. Default: 500.',
								'default'     => 500,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'            => array( 'type' => 'boolean' ),
							'total_images'       => array( 'type' => 'integer' ),
							'total_size'         => array( 'type' => 'integer' ),
							'oversized_count'    => array( 'type' => 'integer' ),
							'missing_webp_count' => array( 'type' => 'integer' ),
							'potential_savings'  => array( 'type' => 'integer' ),
							'oversized_images'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( self::class, 'diagnoseImages' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine/optimize-images',
				array(
					'label'               => 'Optimize Images',
					'description'         => 'Compress oversized images and generate WebP variants. Uses WordPress image editor (Imagick/GD). Batch-aware.',
					'category'            => 'datamachine-media',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'attachment_id'  => array(
								'type'        => 'integer',
								'description' => 'Specific attachment ID to optimize.',
							),
							'size_threshold' => array(
								'type'        => 'integer',
								'description' => 'Only optimize images larger than this (bytes). Default: 204800 (200KB).',
								'default'     => self::DEFAULT_SIZE_THRESHOLD,
							),
							'quality'        => array(
								'type'        => 'integer',
								'description' => 'JPEG/WebP compression quality (1-100). Default: 82.',
								'default'     => self::DEFAULT_QUALITY,
							),
							'webp'           => array(
								'type'        => 'boolean',
								'description' => 'Generate WebP variant. Default: true.',
								'default'     => true,
							),
							'limit'          => array(
								'type'        => 'integer',
								'description' => 'Maximum images to optimize in one batch. Default: 50.',
								'default'     => 50,
							),
							'dry_run'        => array(
								'type'        => 'boolean',
								'description' => 'Preview changes without applying them. Default: false.',
								'default'     => false,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'      => array( 'type' => 'boolean' ),
							'queued_count' => array( 'type' => 'integer' ),
							'batch_id'     => array( 'type' => array( 'integer', 'null' ) ),
							'message'      => array( 'type' => 'string' ),
							'error'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( self::class, 'optimizeImages' ),
					'permission_callback' => fn() => PermissionHelper::can_manage(),
					'meta'                => array( 'show_in_rest' => true ),
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
	 * Diagnose image optimization issues across the media library.
	 *
	 * Scans for oversized images, missing WebP variants, and reports
	 * potential savings from compression.
	 *
	 * @param array $input Ability input.
	 * @return array Diagnostic results.
	 */
	public static function diagnoseImages( array $input = array() ): array {
		$size_threshold = absint( $input['size_threshold'] ?? self::DEFAULT_SIZE_THRESHOLD );
		$limit          = absint( $input['limit'] ?? 500 );

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$total_size         = 0;
		$oversized_count    = 0;
		$missing_webp_count = 0;
		$potential_savings  = 0;
		$oversized_images   = array();

		foreach ( $attachments as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				continue;
			}

			$file_size   = filesize( $file_path );
			$total_size += $file_size;
			$metadata    = wp_get_attachment_metadata( $attachment_id );
			$mime_type   = get_post_mime_type( $attachment_id );

			// Check for WebP variant.
			$has_webp  = false;
			$webp_path = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $file_path );
			if ( file_exists( $webp_path ) ) {
				$has_webp = true;
			}

			// Also check WP's sources array (WP 6.1+ generates WebP subsizes).
			if ( ! $has_webp && ! empty( $metadata['sources'] ) ) {
				foreach ( $metadata['sources'] as $source ) {
					if ( 'image/webp' === ( $source['mime'] ?? '' ) ) {
						$has_webp = true;
						break;
					}
				}
			}

			if ( ! $has_webp && in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
				++$missing_webp_count;
			}

			// Check oversized.
			if ( $file_size > $size_threshold ) {
				++$oversized_count;

				// Estimate savings (target ~60% reduction for heavily oversized).
				$estimated_savings  = (int) ( $file_size * 0.4 );
				$potential_savings += $estimated_savings;

				$width  = $metadata['width'] ?? 0;
				$height = $metadata['height'] ?? 0;

				$oversized_images[] = array(
					'attachment_id' => $attachment_id,
					'title'         => get_the_title( $attachment_id ),
					'file_size'     => $file_size,
					'file_size_hr'  => size_format( $file_size ),
					'dimensions'    => $width . 'x' . $height,
					'mime_type'     => $mime_type,
					'has_webp'      => $has_webp,
				);
			}
		}

		// Sort oversized by file size descending.
		usort( $oversized_images, fn( $a, $b ) => $b['file_size'] - $a['file_size'] );

		return array(
			'success'              => true,
			'total_images'         => count( $attachments ),
			'total_size'           => $total_size,
			'total_size_hr'        => size_format( $total_size ),
			'oversized_count'      => $oversized_count,
			'missing_webp_count'   => $missing_webp_count,
			'potential_savings'    => $potential_savings,
			'potential_savings_hr' => size_format( $potential_savings ),
			'size_threshold'       => $size_threshold,
			'size_threshold_hr'    => size_format( $size_threshold ),
			'oversized_images'     => $oversized_images,
		);
	}

	/**
	 * Queue image optimization for oversized images.
	 *
	 * Finds eligible images and schedules them for batch optimization
	 * via the TaskScheduler. Each image is optimized individually as
	 * a system task (compression + optional WebP generation).
	 *
	 * @param array $input Ability input.
	 * @return array Scheduling result.
	 */
	public static function optimizeImages( array $input = array() ): array {
		$attachment_id  = absint( $input['attachment_id'] ?? 0 );
		$size_threshold = absint( $input['size_threshold'] ?? self::DEFAULT_SIZE_THRESHOLD );
		$quality        = absint( $input['quality'] ?? self::DEFAULT_QUALITY );
		$webp           = $input['webp'] ?? true;
		$limit          = absint( $input['limit'] ?? 50 );
		$dry_run        = ! empty( $input['dry_run'] );

		$quality = max( 1, min( 100, $quality ) );

		// Resolve eligible attachment IDs.
		$attachment_ids = array();

		if ( $attachment_id > 0 ) {
			$attachment_ids[] = $attachment_id;
		} else {
			$all = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			foreach ( $all as $id ) {
				$file_path = get_attached_file( $id );
				if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
					continue;
				}

				$file_size = filesize( $file_path );
				if ( $file_size > $size_threshold ) {
					$attachment_ids[] = $id;
				}
			}
		}

		if ( empty( $attachment_ids ) ) {
			return array(
				'success'      => true,
				'queued_count' => 0,
				'message'      => 'No oversized images found to optimize.',
			);
		}

		if ( $dry_run ) {
			$preview = array();
			foreach ( $attachment_ids as $id ) {
				$file_path = get_attached_file( $id );
				$file_size = $file_path ? filesize( $file_path ) : 0;
				$preview[] = array(
					'attachment_id' => $id,
					'title'         => get_the_title( $id ),
					'file_size'     => size_format( $file_size ),
					'mime_type'     => get_post_mime_type( $id ),
				);
			}

			return array(
				'success'        => true,
				'queued_count'   => 0,
				'dry_run'        => true,
				'would_optimize' => $preview,
				'message'        => sprintf( '%d image(s) would be optimized (dry run).', count( $preview ) ),
			);
		}

		// Build per-item params for batch scheduling.
		$item_params = array();
		foreach ( $attachment_ids as $id ) {
			$item_params[] = array(
				'attachment_id' => $id,
				'quality'       => $quality,
				'webp'          => $webp,
				'source'        => 'ability',
			);
		}

		$user_id  = get_current_user_id();
		$agent_id = function_exists( 'datamachine_resolve_or_create_agent_id' ) && $user_id > 0 ? datamachine_resolve_or_create_agent_id( $user_id ) : 0;

		$batch = TaskScheduler::scheduleBatch(
			'image_optimization',
			$item_params,
			array(
				'user_id'  => $user_id,
				'agent_id' => $agent_id,
			)
		);

		if ( false === $batch ) {
			return array(
				'success'      => false,
				'queued_count' => 0,
				'message'      => 'Failed to schedule optimization batch.',
				'error'        => 'Task batch scheduling failed.',
			);
		}

		return array(
			'success'      => true,
			'queued_count' => count( $attachment_ids ),
			'batch_id'     => $batch['batch_id'] ?? null,
			'message'      => sprintf(
				'Image optimization scheduled for %d image(s).',
				count( $attachment_ids )
			),
		);
	}
}
