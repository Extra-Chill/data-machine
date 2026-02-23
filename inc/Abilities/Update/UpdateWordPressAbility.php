<?php
/**
 * Update WordPress Ability
 *
 * Abilities API primitive for updating WordPress posts.
 * Handles surgical text updates, title changes, full content replacement,
 * and taxonomy assignment. Block-level updates delegate to EditPostBlocksAbility.
 *
 * @package DataMachine\Abilities\Update
 */

namespace DataMachine\Abilities\Update;

use DataMachine\Abilities\Content\BlockSanitizer;
use DataMachine\Abilities\Content\EditPostBlocksAbility;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\WordPress\TaxonomyHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateWordPressAbility {

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
				'datamachine/update-wordpress',
				array(
					'label'               => __( 'Update WordPress Post', 'data-machine' ),
					'description'         => __( 'Update WordPress posts with surgical text edits, block-level edits, or full content replacement', 'data-machine' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'source_url' ),
						'properties' => array(
							'source_url'    => array(
								'type'        => 'string',
								'description' => __( 'URL of the WordPress post to update', 'data-machine' ),
							),
							'title'         => array(
								'type'        => 'string',
								'description' => __( 'New post title (optional)', 'data-machine' ),
							),
							'content'       => array(
								'type'        => 'string',
								'description' => __( 'Full content replacement (optional)', 'data-machine' ),
							),
							'updates'       => array(
								'type'        => 'array',
								'description' => __( 'Surgical find/replace updates (HTML-attribute-safe)', 'data-machine' ),
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'find'    => array( 'type' => 'string' ),
										'replace' => array( 'type' => 'string' ),
									),
								),
							),
							'block_updates' => array(
								'type'        => 'array',
								'description' => __( 'Block-level updates targeting specific Gutenberg blocks by index', 'data-machine' ),
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'block_index' => array( 'type' => 'integer' ),
										'find'        => array( 'type' => 'string' ),
										'replace'     => array( 'type' => 'string' ),
									),
								),
							),
							'taxonomies'    => array(
								'type'        => 'object',
								'default'     => array(),
								'description' => __( 'Taxonomy terms to assign', 'data-machine' ),
							),
							'job_id'        => array(
								'type'        => 'integer',
								'default'     => null,
								'description' => __( 'Job ID for tracking', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'          => array( 'type' => 'boolean' ),
							'post_id'          => array( 'type' => 'integer' ),
							'post_url'         => array( 'type' => 'string' ),
							'changes_applied'  => array( 'type' => 'object' ),
							'taxonomy_results' => array( 'type' => 'object' ),
							'error'            => array( 'type' => 'string' ),
							'logs'             => array( 'type' => 'array' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
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
	 * Permission callback for ability.
	 *
	 * @return bool True if user has permission.
	 */
	public function checkPermission(): bool {
		return PermissionHelper::can_manage();
	}

	/**
	 * Execute WordPress update ability.
	 *
	 * @param array $input Input parameters.
	 * @return array Result with update data or error.
	 */
	public function execute( array $input ): array {
		$logs   = array();
		$config = $this->normalizeConfig( $input );

		$source_url    = $config['source_url'];
		$title         = $config['title'];
		$content       = $config['content'];
		$updates       = $config['updates'];
		$block_updates = $config['block_updates'];
		$taxonomies    = $config['taxonomies'];
		$job_id        = $config['job_id'];

		if ( empty( $source_url ) ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress Update: source_url is required',
			);
			return array(
				'success' => false,
				'error'   => 'source_url parameter is required for WordPress Update',
				'logs'    => $logs,
			);
		}

		$post_id = url_to_postid( $source_url );
		if ( ! $post_id ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress Update: Could not extract post ID from URL',
				'data'    => array( 'source_url' => $source_url ),
			);
			return array(
				'success' => false,
				'error'   => "Could not extract valid WordPress post ID from URL: {$source_url}",
				'logs'    => $logs,
			);
		}

		$existing_post = get_post( $post_id );
		if ( ! $existing_post ) {
			$logs[] = array(
				'level'   => 'error',
				'message' => 'WordPress Update: Post does not exist',
				'data'    => array( 'post_id' => $post_id ),
			);
			return array(
				'success' => false,
				'error'   => "WordPress post with ID {$post_id} does not exist",
				'logs'    => $logs,
			);
		}

		$logs[] = array(
			'level'   => 'debug',
			'message' => 'WordPress Update: Processing update',
			'data'    => array(
				'post_id'              => $post_id,
				'post_title'           => $existing_post->post_title,
				'has_title_update'     => ! empty( $title ),
				'has_content_update'   => ! empty( $content ),
				'has_surgical_updates' => ! empty( $updates ),
				'has_block_updates'    => ! empty( $block_updates ),
			),
		);

		$post_data        = array( 'ID' => $post_id );
		$all_changes      = array();
		$original_content = $existing_post->post_content;

		// Apply surgical updates (HTML-attribute-safe text replacement).
		if ( ! empty( $updates ) ) {
			$result                         = $this->applySurgicalUpdates( $original_content, $updates, $logs );
			$post_data['post_content']      = BlockSanitizer::sanitizeAndSerialize( parse_blocks( $result['content'] ) );
			$all_changes['content_updates'] = $result['changes'];
		}

		// Delegate block-level updates to EditPostBlocksAbility.
		if ( ! empty( $block_updates ) ) {
			$block_result = EditPostBlocksAbility::execute(
				array(
					'post_id' => $post_id,
					'edits'   => $block_updates,
				)
			);

			$all_changes['block_updates'] = $block_result['changes_applied'] ?? array();

			if ( ! $block_result['success'] ) {
				$logs[] = array(
					'level'   => 'warning',
					'message' => 'WordPress Update: Block updates failed',
					'data'    => array( 'error' => $block_result['error'] ?? 'Unknown' ),
				);
			} else {
				$logs[] = array(
					'level'   => 'debug',
					'message' => 'WordPress Update: Block updates applied via EditPostBlocksAbility',
					'data'    => $block_result['changes_applied'] ?? array(),
				);

				// Re-read post content since EditPostBlocksAbility saved directly.
				$refreshed_post = get_post( $post_id );
				if ( $refreshed_post ) {
					$original_content = $refreshed_post->post_content;
				}
			}
		}

		// Apply full content replacement.
		if ( ! empty( $content ) ) {
			$post_data['post_content']               = BlockSanitizer::sanitizeAndSerialize( parse_blocks( wp_unslash( $content ) ) );
			$all_changes['full_content_replacement'] = true;
		}

		// Apply title update.
		if ( ! empty( $title ) ) {
			$post_data['post_title']     = sanitize_text_field( wp_unslash( $title ) );
			$all_changes['title_update'] = true;
		}

		// Determine if we need to call wp_update_post.
		// Block updates already saved via EditPostBlocksAbility, so only save
		// if there are additional changes (title, surgical, full content).
		$has_post_data_updates = count( $post_data ) > 1;

		if ( $has_post_data_updates ) {
			$logs[] = array(
				'level'   => 'debug',
				'message' => 'WordPress Update: Applying changes',
				'data'    => array(
					'updating_title'   => isset( $post_data['post_title'] ),
					'updating_content' => isset( $post_data['post_content'] ),
				),
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'WordPress Update: wp_update_post failed',
					'data'    => array( 'error' => $result->get_error_message() ),
				);
				return array(
					'success' => false,
					'error'   => 'WordPress post update failed: ' . $result->get_error_message(),
					'logs'    => $logs,
				);
			}

			if ( 0 === $result ) {
				$logs[] = array(
					'level'   => 'error',
					'message' => 'WordPress Update: wp_update_post returned 0',
				);
				return array(
					'success' => false,
					'error'   => 'WordPress post update failed: wp_update_post returned 0',
					'logs'    => $logs,
				);
			}
		}

		$has_any_changes = ! empty( $all_changes );

		if ( ! $has_any_changes ) {
			$logs[] = array(
				'level'   => 'info',
				'message' => 'WordPress Update: No updates to apply',
			);
			return array(
				'success'         => true,
				'post_id'         => $post_id,
				'post_url'        => get_permalink( $post_id ),
				'changes_applied' => array(),
				'logs'            => $logs,
			);
		}

		// Process taxonomies.
		$taxonomy_results = array();
		if ( ! empty( $taxonomies ) ) {
			$taxonomy_handler = new TaxonomyHandler();
			$taxonomy_results = $taxonomy_handler->processTaxonomies( $post_id, array( 'taxonomies' => $taxonomies ), array(), array() );
			$logs[]           = array(
				'level'   => 'debug',
				'message' => 'WordPress Update: Taxonomies processed',
				'data'    => $taxonomy_results,
			);
		}

		$logs[] = array(
			'level'   => 'info',
			'message' => 'WordPress Update: Post updated successfully',
			'data'    => array(
				'post_url'        => get_permalink( $post_id ),
				'changes_applied' => array_keys( $all_changes ),
			),
		);

		return array(
			'success'          => true,
			'post_id'          => $post_id,
			'post_url'         => get_permalink( $post_id ),
			'taxonomy_results' => $taxonomy_results,
			'changes_applied'  => $all_changes,
			'logs'             => $logs,
		);
	}

	/**
	 * Normalize input configuration with defaults.
	 */
	private function normalizeConfig( array $input ): array {
		$defaults = array(
			'source_url'    => '',
			'title'         => '',
			'content'       => '',
			'updates'       => array(),
			'block_updates' => array(),
			'taxonomies'    => array(),
			'job_id'        => null,
		);

		return array_merge( $defaults, $input );
	}

	/**
	 * Apply surgical find-and-replace updates with HTML-attribute-safe replacement.
	 *
	 * Splits content by HTML tags before performing text replacement so that
	 * text inside tag attributes (href, class, src, etc.) is never modified.
	 * Adapted from Wordsurf's smart_text_replace pattern.
	 *
	 * @param string $original_content Original content.
	 * @param array  $updates Array of update operations.
	 * @param array  $logs Log array to append to.
	 * @return array Array with 'content' and 'changes' keys.
	 */
	private function applySurgicalUpdates( string $original_content, array $updates, array &$logs ): array {
		$working_content = $original_content;
		$changes_made    = array();

		foreach ( $updates as $update ) {
			if ( ! isset( $update['find'] ) || ! isset( $update['replace'] ) ) {
				$changes_made[] = array(
					'found'         => $update['find'] ?? '',
					'replaced_with' => $update['replace'] ?? '',
					'success'       => false,
					'error'         => 'Missing find or replace parameter',
				);
				continue;
			}

			$find    = $update['find'];
			$replace = $update['replace'];

			if ( strpos( $working_content, $find ) === false ) {
				$changes_made[] = array(
					'found'         => $find,
					'replaced_with' => $replace,
					'success'       => false,
					'error'         => 'Target text not found in content',
				);
				$logs[]         = array(
					'level'   => 'warning',
					'message' => 'WordPress Update: Surgical update target not found',
					'data'    => array(
						'find_text'      => substr( $find, 0, 100 ) . ( strlen( $find ) > 100 ? '...' : '' ),
						'content_length' => strlen( $working_content ),
					),
				);
				continue;
			}

			$working_content = self::smartTextReplace( $working_content, $find, $replace );
			$changes_made[]  = array(
				'found'         => $find,
				'replaced_with' => $replace,
				'success'       => true,
			);
			$logs[]          = array(
				'level'   => 'debug',
				'message' => 'WordPress Update: Surgical update applied',
				'data'    => array(
					'find_length'    => strlen( $find ),
					'replace_length' => strlen( $replace ),
				),
			);
		}

		return array(
			'content' => $working_content,
			'changes' => $changes_made,
		);
	}

	/**
	 * HTML-attribute-safe text replacement.
	 *
	 * Splits content by HTML tags so only visible text nodes are modified.
	 * HTML tag internals (attributes, URLs, class names) are never touched.
	 *
	 * @param string $content  Full content string.
	 * @param string $find     Text to find.
	 * @param string $replace  Replacement text.
	 * @return string Content with replacements applied to text nodes only.
	 */
	public static function smartTextReplace( string $content, string $find, string $replace ): string {
		if ( strpos( $content, '<' ) === false ) {
			return str_replace( $find, $replace, $content );
		}

		$parts  = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$result = '';

		foreach ( $parts as $part ) {
			if ( isset( $part[0] ) && '<' === $part[0] && '>' === substr( $part, -1 ) ) {
				$result .= $part;
			} else {
				$result .= str_replace( $find, $replace, $part );
			}
		}

		return $result;
	}
}
