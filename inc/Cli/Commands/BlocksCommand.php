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

		$result = GetPostBlocksAbility::execute( array(
			'post_id'     => $post_id,
			'block_types' => $block_types,
			'search'      => $search,
		) );

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
					$block['inner_html'] = mb_substr( strip_tags( $block['inner_html'] ), 0, 80 );
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
			$blocks_result = GetPostBlocksAbility::execute( array( 'post_id' => $post_id ) );
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
				WP_CLI::log( "--- DRY RUN ---" );
				WP_CLI::log( "Block #{$block_index} ({$target_block['block_name']})" );
				WP_CLI::log( "Before: " . mb_substr( strip_tags( $target_block['inner_html'] ), 0, 200 ) );
				WP_CLI::log( "After:  " . mb_substr( strip_tags( $preview ), 0, 200 ) );
			}
			return;
		}

		$result = EditPostBlocksAbility::execute( array(
			'post_id' => $post_id,
			'edits'   => array(
				array(
					'block_index' => $block_index,
					'find'        => $find,
					'replace'     => $replace,
				),
			),
		) );

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

		$result = ReplacePostBlocksAbility::execute( array(
			'post_id'      => $post_id,
			'replacements' => array(
				array(
					'block_index' => $block_index,
					'new_content' => $content,
				),
			),
		) );

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Replace failed' );
		}

		WP_CLI::success( sprintf( 'Replaced block #%d in post #%d — %s', $block_index, $post_id, $result['post_url'] ) );

		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::log( wp_json_encode( $result['blocks_replaced'], JSON_PRETTY_PRINT ) );
		}
	}
}
