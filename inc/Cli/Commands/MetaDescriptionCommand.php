<?php
/**
 * WP-CLI Meta Description Command
 *
 * Provides CLI access to meta description diagnostics and generation.
 * Wraps MetaDescriptionAbilities API primitives.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.31.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\SEO\MetaDescriptionAbilities;

defined( 'ABSPATH' ) || exit;

class MetaDescriptionCommand extends BaseCommand {

	/**
	 * Diagnose meta description coverage for posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Post type to diagnose.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Diagnose meta description coverage
	 *     wp datamachine meta-description diagnose
	 *
	 *     # Diagnose pages
	 *     wp datamachine meta-description diagnose --post_type=page
	 *
	 *     # JSON output
	 *     wp datamachine meta-description diagnose --format=json
	 *
	 * @subcommand diagnose
	 */
	public function diagnose( array $args, array $assoc_args ): void {
		$post_type = $assoc_args['post_type'] ?? 'post';
		$format    = $assoc_args['format'] ?? 'table';

		$result = MetaDescriptionAbilities::diagnoseMetaDescriptions(
			array( 'post_type' => $post_type )
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to diagnose meta descriptions.' );
			return;
		}

		$total   = (int) ( $result['total_posts'] ?? 0 );
		$missing = (int) ( $result['missing_count'] ?? 0 );
		$has     = (int) ( $result['has_count'] ?? 0 );

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'post_type'     => $result['post_type'] ?? $post_type,
						'meta_key'      => $result['meta_key'] ?? '',
						'total_posts'   => $total,
						'has_count'     => $has,
						'missing_count' => $missing,
						'coverage'      => $result['coverage'] ?? '0%',
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		$items = array(
			array(
				'post_type'     => $result['post_type'] ?? $post_type,
				'total_posts'   => $total,
				'has_count'     => $has,
				'missing_count' => $missing,
				'coverage'      => $result['coverage'] ?? '0%',
			),
		);

		$this->format_items(
			$items,
			array( 'post_type', 'total_posts', 'has_count', 'missing_count', 'coverage' ),
			$assoc_args
		);
	}

	/**
	 * Queue meta description generation for posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Single post ID to generate a description for.
	 *
	 * [--post_type=<type>]
	 * : Post type to batch process (used when --post_id is not set). When
	 * omitted, batch discovery spans every post type registered via the
	 * `datamachine_post_types_for_meta_description` filter (defaults to
	 * `post` and `page`).
	 *
	 * [--limit=<number>]
	 * : Maximum posts to queue in batch mode.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--force]
	 * : Force regeneration even if meta description exists.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate for a specific post
	 *     wp datamachine meta-description generate --post_id=123
	 *
	 *     # Batch generate for posts missing descriptions
	 *     wp datamachine meta-description generate --limit=100
	 *
	 *     # Batch generate for pages
	 *     wp datamachine meta-description generate --post_type=page --limit=50
	 *
	 *     # Force regeneration for a specific post
	 *     wp datamachine meta-description generate --post_id=123 --force
	 *
	 * @subcommand generate
	 */
	public function generate( array $args, array $assoc_args ): void {
		$post_id   = isset( $assoc_args['post_id'] ) ? \absint( $assoc_args['post_id'] ) : 0;
		$post_type = isset( $assoc_args['post_type'] ) ? (string) $assoc_args['post_type'] : '';
		$limit     = isset( $assoc_args['limit'] ) ? \absint( $assoc_args['limit'] ) : 50;
		$force     = isset( $assoc_args['force'] );
		$format    = $assoc_args['format'] ?? 'table';

		$ability_input = array(
			'post_id' => $post_id,
			'limit'   => $limit,
			'force'   => $force,
		);

		if ( '' !== $post_type ) {
			$ability_input['post_type'] = $post_type;
		}

		$result = MetaDescriptionAbilities::generateMetaDescriptions( $ability_input );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to queue meta description generation.' );
			return;
		}

		$queued_count = (int) ( $result['queued_count'] ?? 0 );
		$post_ids     = $result['post_ids'] ?? array();
		$post_ids     = is_array( $post_ids ) ? array_values( array_map( 'intval', $post_ids ) ) : array();

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'queued_count' => $queued_count,
						'post_ids'     => $post_ids,
						'batch_id'     => $result['batch_id'] ?? null,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		if ( 'table' === $format && ! empty( $result['message'] ) ) {
			WP_CLI::success( $result['message'] );
		}

		$items = array(
			array(
				'queued_count' => $queued_count,
				'post_ids'     => empty( $post_ids ) ? '' : implode( ', ', $post_ids ),
			),
		);

		$this->format_items( $items, array( 'queued_count', 'post_ids' ), $assoc_args );
	}
}
