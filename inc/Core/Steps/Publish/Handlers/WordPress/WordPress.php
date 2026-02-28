<?php
/**
 * WordPress publish handler with modular post creation components.
 *
 * @package DataMachine\Core\Steps\Publish\Handlers\WordPress
 */

namespace DataMachine\Core\Steps\Publish\Handlers\WordPress;

use DataMachine\Abilities\Publish\PublishWordPressAbility;
use DataMachine\Core\EngineData;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Selection\SelectionMode;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;
use DataMachine\Core\WordPress\WordPressPublishHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPress extends PublishHandler {
	use HandlerRegistrationTrait;

	protected $taxonomy_handler;

	public function __construct() {
		parent::__construct( 'WordPress' );

		$this->taxonomy_handler = new TaxonomyHandler();

		// Self-register with filters
		self::registerHandler(
			'wordpress_publish',
			'publish',
			self::class,
			'WordPress',
			'Create WordPress posts and pages',
			false,
			null,
			WordPressSettings::class,
			function ( $tools, $handler_slug, $handler_config ) {
				if ( 'wordpress_publish' === $handler_slug ) {
					// Base parameters (always present)
					$base_parameters = array(
						'title'   => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'The title of the WordPress post or page',
						),
						'content' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'The main content of the post in HTML format. Do not include source URL attribution or images - these are handled automatically by the system.',
						),
					);

					// Dynamic taxonomy parameters based on "AI Decides" selections
					$taxonomy_parameters = TaxonomyHandler::getTaxonomyToolParameters( $handler_config );

					// Merge base + dynamic parameters
					$all_parameters = array_merge( $base_parameters, $taxonomy_parameters );

					$tools['wordpress_publish'] = array(
						'class'          => self::class,
						'method'         => 'handle_tool_call',
						'handler'        => 'wordpress_publish',
						'description'    => 'Create WordPress posts and pages with automatic taxonomy assignment, featured image processing, and source URL attribution.',
						'parameters'     => $all_parameters,
						'handler_config' => $handler_config,
					);
				}
				return $tools;
			}
		);
	}

	/**
	 * Execute WordPress post publishing.
	 *
	 * Creates a WordPress post with modular processing for taxonomies, featured images,
	 * and source URL attribution. Uses configuration hierarchy where system defaults
	 * override handler-specific settings.
	 *
	 * Delegates to PublishWordPressAbility for core logic.
	 *
	 * @param array $parameters Tool call parameters including title, content, job_id
	 * @param array $handler_config Handler configuration
	 * @return array Success status with post data or error information
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine = new EngineData( $parameters['engine_data'] ?? array(), $parameters['job_id'] ?? null );
		}

		// Build taxonomies array from handler config
		$taxonomies     = array();
		$taxonomy_names = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomy_names as $taxonomy ) {
			if ( ! TaxonomyHandler::shouldSkipTaxonomy( $taxonomy ) ) {
				$field_key = "taxonomy_{$taxonomy}_selection";
				$selection = $handler_config[ $field_key ] ?? SelectionMode::SKIP;

				if ( SelectionMode::isAiDecides( $selection ) && ! empty( $parameters[ $taxonomy ] ) ) {
					$taxonomies[ $taxonomy ] = $parameters[ $taxonomy ];
				}
			}
		}

		// Delegate to ability
		$ability_input = array(
			'title'                  => $parameters['title'] ?? '',
			'content'                => $parameters['content'] ?? '',
			'post_type'              => $handler_config['post_type'] ?? '',
			'post_status'            => WordPressSettingsResolver::getPostStatus( $handler_config ),
			'post_author'            => WordPressSettingsResolver::getPostAuthor( $handler_config ),
			'taxonomies'             => $taxonomies,
			'featured_image_path'    => $engine->getImagePath(),
			'source_url'             => $engine->getSourceUrl(),
			'add_source_attribution' => true,
			'job_id'                 => $parameters['job_id'] ?? null,
		);

		$ability = new PublishWordPressAbility();
		$result  = $ability->execute( $ability_input );

		// Log ability logs
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$this->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] ) {
			return $this->errorResponse(
				$result['error'] ?? 'WordPress publish failed',
				array( 'ability_result' => $result )
			);
		}

		$post_id = $result['post_id'] ?? null;

		return $this->successResponse(
			array(
				'post_id'               => $post_id,
				'post_title'            => $result['post_title'] ?? '',
				'post_url'              => $result['post_url'] ?? '',
				'taxonomy_results'      => $result['taxonomy_results'] ?? array(),
				'featured_image_result' => $result['featured_image_result'] ?? null,
			)
		);
	}
	/**
	 * Get the display label for the WordPress handler.
	 *
	 * @return string Handler label
	 */
	public static function get_label(): string {
		return 'WordPress';
	}
}
