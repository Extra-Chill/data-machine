<?php
/**
 * WordPress Update handler for post modification.
 *
 * Delegates to UpdateWordPressAbility for business logic.
 *
 * @package DataMachine\Core\Steps\Update\Handlers\WordPress
 */

namespace DataMachine\Core\Steps\Update\Handlers\WordPress;

use DataMachine\Core\Steps\Update\Handlers\UpdateHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPress extends UpdateHandler {
	use HandlerRegistrationTrait;

	public function __construct() {
		self::registerHandler(
			'wordpress_update',
			'update',
			self::class,
			'WordPress Update',
			'Update existing WordPress posts and pages',
			false,
			null,
			null,
			array( self::class, 'registerTools' )
		);
	}

	public static function registerTools( $tools, $handler_slug, $handler_config ) {
		if ( 'wordpress_update' === $handler_slug ) {
			$tools['wordpress_update'] = array(
				'class'       => self::class,
				'method'      => 'handle_tool_call',
				'handler'     => 'wordpress_update',
				'description' => 'Update an existing WordPress post. Supports surgical text find/replace, block-level edits by index, full content replacement, title updates, and taxonomy assignment. Requires source_url from previous fetch step.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'content'       => array(
							'type'        => 'string',
							'description' => 'Full content replacement (replaces entire post content)',
						),
						'title'         => array(
							'type'        => 'string',
							'description' => 'New post title',
						),
						'updates'       => array(
							'type'        => 'array',
							'description' => 'Surgical find/replace operations on post content text (HTML-attribute-safe)',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'find'    => array(
										'type'        => 'string',
										'description' => 'Text to find in post content',
									),
									'replace' => array(
										'type'        => 'string',
										'description' => 'Replacement text',
									),
								),
								'required'   => array( 'find', 'replace' ),
							),
						),
						'block_updates' => array(
							'type'        => 'array',
							'description' => 'Block-level find/replace targeting specific Gutenberg blocks by zero-based index',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'block_index' => array(
										'type'        => 'integer',
										'description' => 'Zero-based index of the block to edit',
									),
									'find'        => array(
										'type'        => 'string',
										'description' => 'Text to find within the block',
									),
									'replace'     => array(
										'type'        => 'string',
										'description' => 'Replacement text',
									),
								),
								'required'   => array( 'block_index', 'find', 'replace' ),
							),
						),
						'taxonomies'    => array(
							'type'        => 'object',
							'description' => 'Taxonomy terms to assign (e.g. {"category": "News", "post_tag": ["update", "fix"]})',
						),
					),
				),
			);
		}
		return $tools;
	}

	protected function executeUpdate( array $parameters, array $handler_config ): array {
		$job_id     = $parameters['job_id'] ?? null;
		$source_url = $parameters['source_url'] ?? '';

		if ( empty( $source_url ) ) {
			return array(
				'success'   => false,
				'error'     => 'source_url parameter is required for WordPress Update handler',
				'tool_name' => 'wordpress_update',
			);
		}

		$input = array(
			'source_url'    => $source_url,
			'title'         => $parameters['title'] ?? '',
			'content'       => $parameters['content'] ?? '',
			'updates'       => $parameters['updates'] ?? array(),
			'block_updates' => $parameters['block_updates'] ?? array(),
			'taxonomies'    => $parameters['taxonomies'] ?? array(),
			'job_id'        => $job_id,
		);

		$ability = wp_get_ability( 'datamachine/update-wordpress' );

		if ( ! $ability ) {
			return array(
				'success'   => false,
				'error'     => 'datamachine/update-wordpress ability not registered',
				'tool_name' => 'wordpress_update',
			);
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'WordPress Update: Ability execution failed',
				array(
					'job_id' => $job_id,
					'error'  => $result->get_error_message(),
				)
			);

			return array(
				'success'   => false,
				'error'     => $result->get_error_message(),
				'tool_name' => 'wordpress_update',
			);
		}

		if ( ! empty( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$level          = $log_entry['level'] ?? 'debug';
				$message        = $log_entry['message'] ?? 'WordPress Update log entry';
				$data           = $log_entry['data'] ?? array();
				$data['job_id'] = $job_id;
				do_action( 'datamachine_log', $level, $message, $data );
			}
		}

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'error'     => $result['error'] ?? 'WordPress post update failed',
				'tool_name' => 'wordpress_update',
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'updated_id'       => $result['post_id'],
				'post_url'         => $result['post_url'] ?? '',
				'taxonomy_results' => $result['taxonomy_results'] ?? array(),
				'changes_applied'  => $result['changes_applied'] ?? array(),
			),
			'tool_name' => 'wordpress_update',
		);
	}

	public static function get_label(): string {
		return __( 'WordPress Update', 'data-machine' );
	}
}
