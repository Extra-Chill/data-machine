<?php
/**
 * WP-CLI Blocks Command
 *
 * CLI access to block-level content editing abilities.
 * Wraps Content abilities (get-post-blocks, edit-post-blocks, replace-post-blocks).
 *
 * @package DataMachine\Cli\Commands
 * @since 0.28.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Content\GetPostBlocksAbility;
use DataMachine\Abilities\Content\EditPostBlocksAbility;
use DataMachine\Abilities\Content\ReplacePostBlocksAbility;
use DataMachine\Core\AbilityResult;
use DataMachine\Core\Content\SourceDerivedBlockAttributeRepair;

defined( 'ABSPATH' ) || exit;

class BlocksCommand extends BaseCommand {

	/**
	 * List Gutenberg blocks in a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID to parse.
	 *
	 * [--type=<block_type>]
	 * : Filter to specific block type (e.g. core/paragraph). Repeatable.
	 *
	 * [--search=<text>]
	 * : Filter to blocks containing this text (case-insensitive).
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
	 * ## EXAMPLES
	 *
	 *     wp dm blocks list 123
	 *     wp dm blocks list 123 --type=core/paragraph
	 *     wp dm blocks list 123 --search="internal link"
	 *
	 * @subcommand list
	 */
	public function list_blocks( $args, $assoc_args ) {
		$post_id     = absint( $args[0] );
		$block_types = array();
		$search      = $assoc_args['search'] ?? '';
		$format      = $assoc_args['format'] ?? 'table';

		if ( ! empty( $assoc_args['type'] ) ) {
			$block_types = array( $assoc_args['type'] );
		}

		$result = AbilityResult::normalize( GetPostBlocksAbility::execute( array(
			'post_id'     => $post_id,
			'block_types' => $block_types,
			'search'      => $search,
		) ) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to parse blocks' );
		}

		if ( empty( $result['blocks'] ) ) {
			WP_CLI::log( sprintf( 'No matching blocks found in post #%d (total blocks: %d)', $post_id, $result['total_blocks'] ) );
			return;
		}

		// Truncate innerHTML for table display.
		$display_blocks = array_map(
			function ( $block ) use ( $format ) {
				if ( 'table' === $format ) {
					$block['inner_html'] = mb_substr( wp_strip_all_tags( $block['inner_html'] ), 0, 80 );
					if ( strlen( $block['inner_html'] ) >= 80 ) {
						$block['inner_html'] .= '...';
					}
				}
				return $block;
			},
			$result['blocks']
		);

		WP_CLI::log( sprintf( 'Post #%d — %d matching blocks (of %d total)', $post_id, count( $display_blocks ), $result['total_blocks'] ) );

		WP_CLI\Utils\format_items( $format, $display_blocks, array( 'index', 'block_name', 'inner_html' ) );
	}

	/**
	 * Edit content within specific blocks using find/replace.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID to edit.
	 *
	 * <block_index>
	 * : Zero-based block index to edit.
	 *
	 * --find=<text>
	 * : Text to find within the block.
	 *
	 * --replace=<text>
	 * : Replacement text.
	 *
	 * [--dry-run]
	 * : Preview the change without saving.
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
	 *     wp dm blocks edit 123 5 --find="old text" --replace="new text"
	 *     wp dm blocks edit 123 5 --find="old text" --replace="new text" --dry-run
	 *
	 * @subcommand edit
	 */
	public function edit( $args, $assoc_args ) {
		$post_id     = absint( $args[0] );
		$block_index = absint( $args[1] );
		$find        = $assoc_args['find'] ?? '';
		$replace     = $assoc_args['replace'] ?? '';
		$dry_run     = ! empty( $assoc_args['dry-run'] );

		if ( '' === $find ) {
			WP_CLI::error( '--find is required' );
		}

		if ( $dry_run ) {
			// Preview: use get-post-blocks to show what would change.
			$blocks_result = AbilityResult::normalize( GetPostBlocksAbility::execute( array( 'post_id' => $post_id ) ) );
			if ( empty( $blocks_result['success'] ) ) {
				WP_CLI::error( $blocks_result['error'] ?? 'Failed to parse blocks' );
			}

			$target_block = null;
			foreach ( $blocks_result['blocks'] as $block ) {
				if ( $block['index'] === $block_index ) {
					$target_block = $block;
					break;
				}
			}

			if ( ! $target_block ) {
				WP_CLI::error( sprintf( 'Block index %d not found', $block_index ) );
			}

			if ( false === strpos( $target_block['inner_html'], $find ) ) {
				WP_CLI::warning( 'Find text not found in target block' );
			} else {
				$preview = str_replace( $find, $replace, $target_block['inner_html'] );
				WP_CLI::log( '--- DRY RUN ---' );
				WP_CLI::log( "Block #{$block_index} ({$target_block['block_name']})" );
				WP_CLI::log( 'Before: ' . mb_substr( wp_strip_all_tags( $target_block['inner_html'] ), 0, 200 ) );
				WP_CLI::log( 'After:  ' . mb_substr( wp_strip_all_tags( $preview ), 0, 200 ) );
			}
			return;
		}

		$result = AbilityResult::normalize( EditPostBlocksAbility::execute( array(
			'post_id' => $post_id,
			'edits'   => array(
				array(
					'block_index' => $block_index,
					'find'        => $find,
					'replace'     => $replace,
				),
			),
		) ) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Edit failed' );
		}

		WP_CLI::success( sprintf( 'Edited block #%d in post #%d — %s', $block_index, $post_id, $result['post_url'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::log( wp_json_encode( $result['changes_applied'], JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Replace entire block content by index.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID to edit.
	 *
	 * <block_index>
	 * : Zero-based block index to replace.
	 *
	 * --content=<html>
	 * : New innerHTML for the block.
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
	 *     wp dm blocks replace 123 5 --content="<p>New paragraph with <a href='/link'>a link</a>.</p>"
	 *
	 * @subcommand replace
	 */
	public function replace( $args, $assoc_args ) {
		$post_id     = absint( $args[0] );
		$block_index = absint( $args[1] );
		$content     = $assoc_args['content'] ?? '';

		if ( '' === $content ) {
			WP_CLI::error( '--content is required' );
		}

		$result = AbilityResult::normalize( ReplacePostBlocksAbility::execute( array(
			'post_id'      => $post_id,
			'replacements' => array(
				array(
					'block_index' => $block_index,
					'new_content' => $content,
				),
			),
		) ) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Replace failed' );
		}

		WP_CLI::success( sprintf( 'Replaced block #%d in post #%d — %s', $block_index, $post_id, $result['post_url'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::log( wp_json_encode( $result['blocks_replaced'], JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Inventory or repair the known malformed RichText content attributes.
	 *
	 * This is an operator-only maintenance command. On multisite, always pass the
	 * intended site's `--url`; the command inspects only that site's posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Inspect one post. By default all posts of the selected type are inspected.
	 *
	 * [--post_type=<type>]
	 * : Comma-separated post types to inspect. Bulk apply requires this option.
	 * Reusable `wp_block` records are inspected only when explicitly selected.
	 * ---
	 * default: page
	 * ---
	 *
	 * [--post_status=<status>]
	 * : Post status to inspect.
	 * ---
	 * default: any
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum bulk posts per page. Default 100; maximum 500.
	 *
	 * [--paged=<number>]
	 * : One-based bulk result page, ordered by ascending post ID.
	 *
	 * [--apply]
	 * : Persist repairs. Without this flag the command is an inventory-only dry run.
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
	 * ## EXAMPLES
	 *
	 *     wp --url=https://example.com datamachine blocks repair-source-attributes --post_type=page
	 *     wp --url=https://example.com datamachine blocks repair-source-attributes --post_id=123 --apply
	 *
	 * @subcommand repair-source-attributes
	 */
	public function repair_source_attributes( array $args, array $assoc_args ): void {
		$apply  = isset( $assoc_args['apply'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( '--format must be table, json, or csv.' );
		}

		$post_id = 0;
		if ( array_key_exists( 'post_id', $assoc_args ) ) {
			try {
				$post_id = self::parsePositiveInteger( $assoc_args['post_id'], '--post_id' );
			} catch ( \InvalidArgumentException $exception ) {
				WP_CLI::error( $exception->getMessage() );
			}
		}

		$limit = 1;
		$paged = 1;
		if ( $post_id > 0 ) {
			if ( ! get_post( $post_id ) ) {
				WP_CLI::error( sprintf( 'Post #%d does not exist on the selected site.', $post_id ) );
			}
			$post_ids   = array( $post_id );
			$post_types = array( get_post_type( $post_id ) );
		} else {
			try {
				$limit = self::parsePositiveInteger( $assoc_args['limit'] ?? '100', '--limit', 500 );
				$paged = self::parsePositiveInteger( $assoc_args['paged'] ?? '1', '--paged' );
			} catch ( \InvalidArgumentException $exception ) {
				WP_CLI::error( $exception->getMessage() );
			}

			$post_type_supplied = isset( $assoc_args['post_type'] ) && '' !== trim( (string) $assoc_args['post_type'] );
			try {
				$post_types = self::parsePostTypes( $assoc_args['post_type'] ?? 'page' );
			} catch ( \InvalidArgumentException $exception ) {
				WP_CLI::error( $exception->getMessage() );
			}
			if ( $apply && ( ! $post_type_supplied || in_array( 'any', $post_types, true ) ) ) {
				WP_CLI::error( 'Bulk --apply requires an explicit --post_type that is not "any".' );
			}

			$query    = new \WP_Query( self::bulkQueryArgs( $post_types, $assoc_args['post_status'] ?? 'any', $limit, $paged ) );
			$post_ids = array_map( 'intval', $query->posts );
		}

		$repair   = new SourceDerivedBlockAttributeRepair();
		$findings = array();
		foreach ( $post_ids as $candidate_id ) {
			$post = get_post( $candidate_id );
			if ( ! $post || ! is_string( $post->post_content ?? null ) || false === strpos( $post->post_content, '<!-- wp:' ) ) {
				continue;
			}

			$result = $repair->processPost( (int) $candidate_id, $post->post_content, $apply );
			foreach ( $result['findings'] as $finding ) {
				$status = $finding['status'];
				$reason = $finding['reason'];
				if ( isset( $result['error_code'] ) && ( 'repairable' === $status || 'integrity_verification_failed' === $reason ) ) {
					$status = 'error';
					$reason = $result['error_code'];
				} elseif ( $result['applied'] && 'repairable' === $status ) {
					$status = 'removed';
				} elseif ( 'repairable' === $status ) {
					$status = 'would_remove';
				}

				$findings[] = array_merge(
					$finding,
					array(
						'post_id' => (int) $candidate_id,
						'status'  => $status,
						'reason'  => $reason,
					)
				);
			}
		}

		$summary = array(
			'mode'                => $apply ? 'apply' : 'dry-run',
			'site_url'            => home_url( '/' ),
			'post_types'          => array_values( array_filter( $post_types ) ),
			'limit'               => $limit,
			'paged'               => $paged,
			'posts_inspected'     => count( $post_ids ),
			'posts_with_findings' => count( array_unique( array_column( $findings, 'post_id' ) ) ),
			'findings'            => count( $findings ),
			'repairable'          => count( array_filter( $findings, static fn ( array $finding ): bool => in_array( $finding['status'], array( 'would_remove', 'removed' ), true ) ) ),
			'skipped'             => count( array_filter( $findings, static fn ( array $finding ): bool => 'skipped' === $finding['status'] ) ),
			'errors'              => count( array_filter( $findings, static fn ( array $finding ): bool => 'error' === $finding['status'] ) ),
		);
		$fields  = array( 'post_id', 'block_path', 'block_name', 'attribute', 'status', 'reason', 'value_type', 'value_bytes', 'value_sha256' );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( array(
				'findings' => $findings,
				'summary'  => $summary,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			WP_CLI\Utils\format_items( $format, $findings, $fields );
			if ( 'table' === $format ) {
				WP_CLI::log( 'Summary: ' . (string) wp_json_encode( $summary, JSON_UNESCAPED_SLASHES ) );
			}
		}

		if ( 0 !== self::applyExitCode( $apply, $summary ) ) {
			if ( 'table' === $format ) {
				WP_CLI::warning( 'One or more repairs failed; review error findings. Successful repairs remain committed.' );
			}
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Return a nonzero exit only when an apply run contains errors.
	 *
	 * @param bool  $apply Whether mutation was requested.
	 * @param array $summary Output summary.
	 */
	private static function applyExitCode( bool $apply, array $summary ): int {
		return $apply && 0 < (int) ( $summary['errors'] ?? 0 ) ? 1 : 0;
	}

	/**
	 * Parse one positive canonical integer CLI value.
	 *
	 * @param mixed  $value Raw CLI value.
	 * @param string $option Option name for errors.
	 * @param int    $maximum Optional maximum.
	 */
	private static function parsePositiveInteger( $value, string $option, int $maximum = PHP_INT_MAX ): int {
		$raw = is_string( $value ) || is_int( $value ) ? (string) $value : '';
		if ( ! preg_match( '/^[1-9][0-9]*$/', $raw ) || (string) (int) $raw !== $raw || (int) $raw > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only validation uses trusted option labels and integers.
			throw new \InvalidArgumentException( sprintf( '%s must be a positive canonical integer%s.', $option, PHP_INT_MAX === $maximum ? '' : sprintf( ' no greater than %d', $maximum ) ) );
		}

		return (int) $raw;
	}

	/**
	 * Parse selected post types without implicitly following reusable references.
	 *
	 * @param mixed $value Raw post type list.
	 * @return string[]
	 */
	private static function parsePostTypes( $value ): array {
		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', (string) $value ) ) ) ) );
		if ( empty( $types ) ) {
			throw new \InvalidArgumentException( '--post_type must contain at least one post type.' );
		}

		return $types;
	}

	/**
	 * Build the bounded, cache-free bulk query.
	 *
	 * @param string[] $post_types Selected post types.
	 * @param mixed    $post_status Raw post status.
	 * @param int      $limit Page size.
	 * @param int      $paged Result page.
	 * @return array
	 */
	private static function bulkQueryArgs( array $post_types, $post_status, int $limit, int $paged ): array {
		$normalized_post_status = sanitize_key( (string) $post_status );

		return array(
			'post_type'              => 1 === count( $post_types ) ? $post_types[0] : $post_types,
			'post_status'            => $normalized_post_status ? $normalized_post_status : 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => $limit,
			'paged'                  => $paged,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lazy_load_term_meta'    => false,
		);
	}
}
