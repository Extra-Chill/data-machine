<?php
/**
 * WordPress local content fetch handler with timeframe and keyword filtering.
 *
 * Delegates to GetWordPressPostAbility (single) or QueryWordPressPostsAbility (query mode).
 *
 * @package Data_Machine
 * @subpackage Core\Steps\Fetch\Handlers\WordPress
 */

namespace DataMachine\Core\Steps\Fetch\Handlers\WordPress;

use DataMachine\Abilities\Fetch\GetWordPressPostAbility;
use DataMachine\Abilities\Fetch\QueryWordPressPostsAbility;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class WordPress extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'wordpress_local' );

		// Self-register with filters
		self::registerHandler(
			'wordpress_posts',
			'fetch',
			self::class,
			'Local WordPress Posts',
			'Fetch posts and pages from this WordPress installation',
			false,
			null,
			WordPressSettings::class,
			null
		);
	}

	/**
	 * Fetch WordPress posts with timeframe and keyword filtering.
	 *
	 * Delegates to GetWordPressPostAbility (URL/single mode) or QueryWordPressPostsAbility (query mode).
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$source_url = sanitize_url( $config['source_url'] ?? '' );

		// URL-specific access - use GetWordPressPostAbility
		if ( ! empty( $source_url ) ) {
			return $this->fetch_single_post( $source_url, $context );
		}

		// Query mode - use QueryWordPressPostsAbility
		return $this->fetch_posts_via_query( $config, $context );
	}

	/**
	 * Fetch single post by URL using GetWordPressPostAbility.
	 */
	private function fetch_single_post( string $source_url, ExecutionContext $context ): array {
		$ability_input = array(
			'source_url'        => $source_url,
			'include_file_info' => true,
		);

		$ability = new GetWordPressPostAbility();
		$result  = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'WordPress fetch single post failed: ' . $result->get_error_message() );
			return array();
		}

		// Log ability logs
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$context->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] ) {
			return array();
		}

		$data    = $result['data'];
		$post_id = $data['post_id'];

		// Build response for pipeline
		$image_file_path = $data['file_info']['file_path'] ?? '';

		$raw_data = array(
			'title'    => $data['title'],
			'content'  => $data['content'],
			'metadata' => array(
				'source_type'            => 'wordpress_local',
				'item_identifier'        => (string) $post_id,
				'original_id'            => $post_id,
				'original_title'         => $data['title'],
				'original_date_gmt'      => $data['publish_date'],
				'post_type'              => $data['post_type'],
				'post_status'            => $data['post_status'],
				'site_name'              => $data['site_name'],
				'_engine_data'           => array(
					'source_url'      => $data['permalink'] ?? '',
					'image_file_path' => $image_file_path,
				),
			),
		);

		// Add excerpt if present
		if ( ! empty( $data['excerpt'] ) ) {
			$raw_data['content'] .= "\n\nExcerpt: " . $data['excerpt'];
		}

		// Add file_info if featured image is available
		if ( ! empty( $data['file_info'] ) ) {
			$raw_data['file_info'] = $data['file_info'];
		}

		return $raw_data;
	}

	/**
	 * Fetch posts via query using QueryWordPressPostsAbility.
	 *
	 * Returns all eligible posts as { items: [...] }.
	 */
	private function fetch_posts_via_query( array $config, ExecutionContext $context ): array {
		// Build taxonomy query.
		$tax_query = array();
		foreach ( $config as $field_key => $field_value ) {
			if ( strpos( $field_key, 'taxonomy_' ) === 0 && strpos( $field_key, '_filter' ) !== false ) {
				$term_id = intval( $field_value );
				if ( $term_id > 0 ) {
					$taxonomy_slug = str_replace( array( 'taxonomy_', '_filter' ), '', $field_key );
					$tax_query[]   = array(
						'taxonomy' => $taxonomy_slug,
						'field'    => 'term_id',
						'terms'    => array( $term_id ),
					);
				}
			}
		}

		$ability_input = array(
			'post_type'         => sanitize_text_field( $config['post_type'] ?? 'post' ),
			'post_status'       => sanitize_text_field( $config['post_status'] ?? 'publish' ),
			'timeframe_limit'   => $config['timeframe_limit'] ?? 'all_time',
			'search'            => trim( $config['search'] ?? '' ),
			'randomize'         => ! empty( $config['randomize_selection'] ),
			'posts_per_page'    => 10,
			'tax_query'         => $tax_query,
			'include_file_info' => true,
		);

		$ability = new QueryWordPressPostsAbility();
		$result  = $ability->execute( $ability_input );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'WordPress fetch query failed: ' . $result->get_error_message() );
			return array();
		}

		// Log ability logs.
		if ( ! empty( $result['logs'] ) && is_array( $result['logs'] ) ) {
			foreach ( $result['logs'] as $log_entry ) {
				$context->log(
					$log_entry['level'] ?? 'debug',
					$log_entry['message'] ?? '',
					$log_entry['data'] ?? array()
				);
			}
		}

		if ( ! $result['success'] || empty( $result['data'] ) ) {
			return array();
		}

		$data  = $result['data'];
		$items = $data['items'] ?? array( $data );

		$processed_items = array();

		foreach ( $items as &$item ) {
			$post_id = $item['metadata']['original_id'] ?? '';

			// Set item_identifier for centralized dedup in FetchHandler::dedup().
			if ( $post_id ) {
				$item['metadata']['item_identifier'] = (string) $post_id;
			}

			// Append excerpt to content if present.
			$excerpt = $item['metadata']['excerpt'] ?? '';
			if ( ! empty( $excerpt ) ) {
				$item['content'] .= "\n\nExcerpt: " . $excerpt;
			}

			$processed_items[] = $item;
		}
		unset( $item );

		if ( empty( $processed_items ) ) {
			return array();
		}

		return array( 'items' => $processed_items );
	}

	public static function get_label(): string {
		return 'Local WordPress Posts';
	}
}
