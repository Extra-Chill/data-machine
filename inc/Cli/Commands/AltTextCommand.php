<?php
/**
 * WP-CLI Alt Text Command
 *
 * Provides CLI access to alt text diagnostics and generation.
 * Wraps AltTextAbilities API primitives.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\Media\AltTextAbilities;

defined( 'ABSPATH' ) || exit;

class AltTextCommand extends BaseCommand {

	/**
	 * Diagnose alt text coverage for image attachments.
	 *
	 * ## OPTIONS
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
	 *     # Diagnose alt text coverage
	 *     wp datamachine alt-text diagnose
	 *
	 *     # JSON output
	 *     wp datamachine alt-text diagnose --format=json
	 *
	 *     # CSV output
	 *     wp datamachine alt-text diagnose --format=csv
	 *
	 * @subcommand diagnose
	 */
	public function diagnose( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$result = AltTextAbilities::diagnoseAltText();

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to diagnose alt text.' );
			return;
		}

		$total_images      = (int) ( $result['total_images'] ?? 0 );
		$missing_alt_count = (int) ( $result['missing_alt_count'] ?? 0 );
		$by_mime_type      = $result['by_mime_type'] ?? array();

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'total_images'      => $total_images,
						'missing_alt_count' => $missing_alt_count,
						'by_mime_type'      => $by_mime_type,
					),
					JSON_PRETTY_PRINT
				)
			);
			return;
		}

		$items   = array();
		$items[] = array(
			'mime_type'         => 'all',
			'total_images'      => $total_images,
			'missing_alt_count' => $missing_alt_count,
		);

		foreach ( $by_mime_type as $row ) {
			$items[] = array(
				'mime_type'         => $row['mime_type'] ?? 'unknown',
				'total_images'      => (int) ( $row['total'] ?? 0 ),
				'missing_alt_count' => (int) ( $row['missing'] ?? 0 ),
			);
		}

		$this->format_items( $items, array( 'mime_type', 'total_images', 'missing_alt_count' ), $assoc_args );
	}

	/**
	 * Queue alt text generation for attachments or a post.
	 *
	 * ## OPTIONS
	 *
	 * [--attachment_id=<id>]
	 * : Attachment ID to queue.
	 *
	 * [--post_id=<id>]
	 * : Post ID to queue attached images (and featured image).
	 *
	 * [--force]
	 * : Force regeneration even if alt text exists.
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
	 *     # Queue generation for a specific attachment
	 *     wp datamachine alt-text generate --attachment_id=123
	 *
	 *     # Queue generation for a post's images
	 *     wp datamachine alt-text generate --post_id=55
	 *
	 *     # Force regeneration
	 *     wp datamachine alt-text generate --attachment_id=123 --force
	 *
	 *     # JSON output
	 *     wp datamachine alt-text generate --post_id=55 --format=json
	 *
	 * @subcommand generate
	 */
	public function generate( array $args, array $assoc_args ): void {
		$attachment_id = isset( $assoc_args['attachment_id'] ) ? \absint( $assoc_args['attachment_id'] ) : 0;
		$post_id       = isset( $assoc_args['post_id'] ) ? \absint( $assoc_args['post_id'] ) : 0;
		$force         = isset( $assoc_args['force'] );
		$format        = $assoc_args['format'] ?? 'table';

		if ( 0 === $attachment_id && 0 === $post_id ) {
			WP_CLI::error( 'Required: --attachment_id=<id> or --post_id=<id>' );
			return;
		}

		$result = AltTextAbilities::generateAltText(
			array(
				'attachment_id' => $attachment_id,
				'post_id'       => $post_id,
				'force'         => $force,
			)
		);

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to queue alt text generation.' );
			return;
		}

		$queued_count   = (int) ( $result['queued_count'] ?? 0 );
		$attachment_ids = $result['attachment_ids'] ?? array();
		$attachment_ids = is_array( $attachment_ids ) ? array_values( array_map( 'intval', $attachment_ids ) ) : array();

		if ( 'json' === $format ) {
			WP_CLI::line(
				\wp_json_encode(
					array(
						'queued_count'   => $queued_count,
						'attachment_ids' => $attachment_ids,
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
				'queued_count'   => $queued_count,
				'attachment_ids' => empty( $attachment_ids ) ? '' : implode( ', ', $attachment_ids ),
			),
		);

		$this->format_items( $items, array( 'queued_count', 'attachment_ids' ), $assoc_args );
	}
}
