<?php
/**
 * ContentActionHandlers — register content abilities on the unified
 * PendingActionStore lane.
 *
 * The three content abilities (edit_post_blocks, replace_post_blocks,
 * insert_content) stage their previews via PendingActionHelper::stage().
 * This file registers their `apply` + `can_resolve` callbacks on the
 * `datamachine_pending_action_handlers` filter so that
 * ResolvePendingActionAbility can replay the staged invocation when the
 * user accepts.
 *
 * Each handler re-invokes the original ability's execute() with `preview`
 * stripped, so the same code path that ran the preview also applies it.
 *
 * @package DataMachine\Abilities\Content
 * @since   0.79.0
 */

namespace DataMachine\Abilities\Content;

defined( 'ABSPATH' ) || exit;

add_filter(
	'datamachine_pending_action_handlers',
	static function ( $handlers ) {
		if ( ! is_array( $handlers ) ) {
			$handlers = array();
		}

		$handlers['edit_post_blocks'] = array(
			'apply'       => static function ( array $apply_input ) {
				unset( $apply_input['preview'] );
				return EditPostBlocksAbility::execute( $apply_input );
			},
			'can_resolve' => static function ( array $payload, string $decision, int $user_id ) {
				return ContentActionHandlers::can_resolve_post_edit( $payload, $user_id );
			},
		);

		$handlers['replace_post_blocks'] = array(
			'apply'       => static function ( array $apply_input ) {
				unset( $apply_input['preview'] );
				return ReplacePostBlocksAbility::execute( $apply_input );
			},
			'can_resolve' => static function ( array $payload, string $decision, int $user_id ) {
				return ContentActionHandlers::can_resolve_post_edit( $payload, $user_id );
			},
		);

		$handlers['insert_content'] = array(
			'apply'       => static function ( array $apply_input ) {
				$apply_input['preview'] = false;
				return InsertContentAbility::execute( $apply_input );
			},
			'can_resolve' => static function ( array $payload, string $decision, int $user_id ) {
				return ContentActionHandlers::can_resolve_post_edit( $payload, $user_id );
			},
		);

		return $handlers;
	}
);

/**
 * Helper container for the shared can_resolve check.
 *
 * The three content abilities all mutate post content, so the gate is the
 * same: the resolving user must have `edit_post` on the target post.
 */
class ContentActionHandlers {

	/**
	 * Gate resolution of a content-edit pending action.
	 *
	 * @param array $payload Stored PendingAction payload.
	 * @param int   $user_id Resolving user ID.
	 * @return bool|\WP_Error True if allowed, WP_Error with explanation otherwise.
	 */
	public static function can_resolve_post_edit( array $payload, int $user_id ) {
		$apply_input = isset( $payload['apply_input'] ) && is_array( $payload['apply_input'] )
			? $payload['apply_input']
			: array();

		$post_id = absint( $apply_input['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'datamachine_invalid_pending_action',
				__( 'Stored pending action is missing a target post.', 'data-machine' )
			);
		}

		if ( $user_id > 0 ) {
			if ( user_can( $user_id, 'edit_post', $post_id ) ) {
				return true;
			}
		} elseif ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return new \WP_Error(
			'datamachine_forbidden',
			__( 'You do not have permission to edit this post.', 'data-machine' )
		);
	}
}
