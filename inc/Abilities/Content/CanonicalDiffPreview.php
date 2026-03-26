<?php
/**
 * CanonicalDiffPreview — shared preview payload builder for content abilities.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.60.0
 */

namespace DataMachine\Abilities\Content;

defined( 'ABSPATH' ) || exit;

class CanonicalDiffPreview {

	/**
	 * Build the canonical diff payload returned by preview-mode content abilities.
	 *
	 * @param array $args Canonical diff data.
	 * @return array
	 */
	public static function build( array $args ): array {
		$diff_id             = (string) ( $args['diff_id'] ?? '' );
		$diff_type           = (string) ( $args['diff_type'] ?? 'edit' );
		$original_content    = (string) ( $args['original_content'] ?? '' );
		$replacement_content = (string) ( $args['replacement_content'] ?? '' );
		$summary             = (string) ( $args['summary'] ?? '' );
		$items               = isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( $args['items'] ) : array();
		$position            = isset( $args['position'] ) ? (string) $args['position'] : '';
		$insertion_point     = isset( $args['insertion_point'] ) ? (string) $args['insertion_point'] : '';
		$editor              = isset( $args['editor'] ) && is_array( $args['editor'] ) ? $args['editor'] : array();

		$diff = array(
			'diffId'             => $diff_id,
			'diffType'           => $diff_type,
			'originalContent'    => $original_content,
			'replacementContent' => $replacement_content,
			'status'             => 'pending',
		);

		if ( '' !== $summary ) {
			$diff['summary'] = $summary;
		}

		if ( ! empty( $items ) ) {
			$diff['items'] = $items;
		}

		if ( '' !== $position ) {
			$diff['position'] = $position;
		}

		if ( '' !== $insertion_point ) {
			$diff['insertionPoint'] = $insertion_point;
		}

		if ( ! empty( $editor ) ) {
			$diff['editor'] = $editor;
		}

		return $diff;
	}

	/**
	 * Store pending diff metadata for later resolution.
	 *
	 * @param string $diff_id Diff identifier.
	 * @param array  $args    Pending diff metadata.
	 * @return void
	 */
	public static function store_pending( string $diff_id, array $args ): void {
		PendingDiffStore::store( $diff_id, array(
			'type'    => (string) ( $args['type'] ?? '' ),
			'post_id' => absint( $args['post_id'] ?? 0 ),
			'input'   => isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array(),
			'diff'    => isset( $args['diff'] ) && is_array( $args['diff'] ) ? $args['diff'] : array(),
		) );
	}

	/**
	 * Wrap a preview response in the canonical shape.
	 *
	 * @param int    $post_id Post ID being edited.
	 * @param string $message Human summary.
	 * @param array  $diff    Canonical diff payload.
	 * @param array  $extra   Additional response fields.
	 * @return array
	 */
	public static function response( int $post_id, string $message, array $diff, array $extra = array() ): array {
		return array_merge(
			array(
				'success' => true,
				'preview' => true,
				'post_id' => $post_id,
				'diff_id' => $diff['diffId'] ?? '',
				'diff'    => $diff,
				'message' => $message,
			),
			$extra
		);
	}
}
