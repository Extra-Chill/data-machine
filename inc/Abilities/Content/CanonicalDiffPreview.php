<?php
/**
 * CanonicalDiffPreview — shared preview-payload builder for content abilities.
 *
 * Produces the canonical diff shape that gets nested inside the
 * PendingAction envelope as `preview_data`. The three content abilities
 * (edit_post_blocks, replace_post_blocks, insert_content) all funnel
 * through `build()` so that consumers (Gutenberg diff block, CLI review
 * UIs, notification payloads) can read a single stable shape.
 *
 * Storage is handled by PendingActionHelper::stage(); this class is a
 * pure formatter.
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
	 * Callers pass the same action_id that will be used to stage the payload
	 * via PendingActionHelper::stage(). The returned array embeds `actionId`
	 * so Gutenberg / CLI consumers can round-trip user decisions without
	 * separately tracking the envelope id.
	 *
	 * @param array $args Canonical diff data. Supported keys: action_id,
	 *                    diff_type, original_content, replacement_content,
	 *                    summary, items, position, insertion_point, editor.
	 * @return array
	 */
	public static function build( array $args ): array {
		$action_id           = (string) ( $args['action_id'] ?? '' );
		$diff_type           = (string) ( $args['diff_type'] ?? 'edit' );
		$original_content    = (string) ( $args['original_content'] ?? '' );
		$replacement_content = (string) ( $args['replacement_content'] ?? '' );
		$summary             = (string) ( $args['summary'] ?? '' );
		$items               = isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( $args['items'] ) : array();
		$position            = isset( $args['position'] ) ? (string) $args['position'] : '';
		$insertion_point     = isset( $args['insertion_point'] ) ? (string) $args['insertion_point'] : '';
		$editor              = isset( $args['editor'] ) && is_array( $args['editor'] ) ? $args['editor'] : array();

		$diff = array(
			'actionId'           => $action_id,
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
}
