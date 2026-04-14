<?php
/**
 * ResolveDiffAbility — accept or reject a pending diff.
 *
 * When a content ability runs in preview mode, the pending edit is stored
 * server-side via PendingDiffStore. This ability retrieves the stored edit
 * and either applies it (accept) or discards it (reject).
 *
 * Provides both an Abilities API registration and a REST endpoint so any
 * consumer (Roadie, editor, CLI) can resolve diffs universally.
 *
 * @package DataMachine\Abilities\Content
 * @since 0.60.0
 */

namespace DataMachine\Abilities\Content;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class ResolveDiffAbility {

	private static bool $registered = false;

	public function __construct() {
		if ( ! class_exists( 'WP_Ability' ) || self::$registered ) {
			return;
		}

		$this->register_ability();
		$this->register_rest_route();
		self::$registered = true;
	}

	/**
	 * Register the WordPress ability.
	 */
	private function register_ability(): void {
		$register = function () {
			wp_register_ability( 'datamachine/resolve-diff', array(
				'label'               => __( 'Resolve Diff', 'data-machine' ),
				'description'         => __( 'Accept or reject a pending content diff.', 'data-machine' ),
				'category'            => 'datamachine/content',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'diff_id', 'decision' ),
					'properties' => array(
						'diff_id'  => array(
							'type'        => 'string',
							'description' => __( 'The pending diff identifier.', 'data-machine' ),
						),
						'decision' => array(
							'type'        => 'string',
							'enum'        => array( 'accepted', 'rejected' ),
							'description' => __( 'Whether to apply or discard the change.', 'data-machine' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'decision' => array( 'type' => 'string' ),
						'diff_id'  => array( 'type' => 'string' ),
						'post_id'  => array( 'type' => 'integer' ),
						'error'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
				'meta'                => array( 'show_in_rest' => true ),
			) );
		};

		if ( doing_action( 'wp_abilities_api_init' ) ) {
			$register();
		} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
			add_action( 'wp_abilities_api_init', $register );
		}
	}

	/**
	 * Register the REST route.
	 */
	private function register_rest_route(): void {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'datamachine/v1', '/diff/resolve', array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_rest' ),
				'permission_callback' => fn() => PermissionHelper::can( 'chat' ),
				'args'                => array(
					'diff_id'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'decision' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'accepted', 'rejected' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) );
		} );
	}

	/**
	 * REST handler — delegates to execute.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
		$result = self::execute( array(
			'diff_id'  => $request->get_param( 'diff_id' ),
			'decision' => $request->get_param( 'decision' ),
		) );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * Execute: accept or reject a pending diff.
	 *
	 * Accept → re-run the original ability without preview to apply the write.
	 * Reject → discard the stored edit, no changes made.
	 *
	 * @param array $input { diff_id, decision }.
	 * @return array
	 */
	public static function execute( array $input ): array {
		$diff_id  = $input['diff_id'] ?? '';
		$decision = $input['decision'] ?? '';

		if ( '' === $diff_id || '' === $decision ) {
			return array(
				'success' => false,
				'error'   => 'diff_id and decision are required.',
			);
		}

		if ( ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'decision must be "accepted" or "rejected".',
			);
		}

		$pending = PendingDiffStore::get( $diff_id );

		if ( null === $pending ) {
			return array(
				'success' => false,
				'error'   => 'Pending diff not found or expired.',
				'diff_id' => $diff_id,
			);
		}

		$post_id = absint( $pending['post_id'] ?? 0 );
		$type    = $pending['type'] ?? '';

		// Verify the user can edit this post.
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'success' => false,
				'error'   => 'You do not have permission to edit this post.',
				'diff_id' => $diff_id,
			);
		}

		// Always clean up the stored diff.
		PendingDiffStore::delete( $diff_id );

		// Reject: nothing to do, just discard.
		if ( 'rejected' === $decision ) {
			do_action( 'datamachine_diff_resolved', $decision, $diff_id, $post_id, $type );

			return array(
				'success'  => true,
				'decision' => 'rejected',
				'diff_id'  => $diff_id,
				'post_id'  => $post_id,
			);
		}

		// Accept: re-execute the original ability without preview.
		$original_input = $pending['input'] ?? array();

		$apply_result = self::apply( $type, $original_input );

		do_action( 'datamachine_diff_resolved', $decision, $diff_id, $post_id, $type );

		if ( ! $apply_result['success'] ) {
			return array(
				'success'  => false,
				'decision' => 'accepted',
				'diff_id'  => $diff_id,
				'post_id'  => $post_id,
				'error'    => $apply_result['error'] ?? 'Failed to apply the pending edit.',
			);
		}

		return array(
			'success'  => true,
			'decision' => 'accepted',
			'diff_id'  => $diff_id,
			'post_id'  => $post_id,
			'post_url' => $apply_result['post_url'] ?? '',
		);
	}

	/**
	 * Re-execute the original ability to apply the write.
	 *
	 * @param string $type  The ability type ('edit_post_blocks' or 'replace_post_blocks').
	 * @param array  $input The original input parameters (without preview).
	 * @return array
	 */
	private static function apply( string $type, array $input ): array {
		// Ensure preview is not set — we want the real write.
		unset( $input['preview'] );

		switch ( $type ) {
			case 'edit_post_blocks':
				return EditPostBlocksAbility::execute( $input );

			case 'replace_post_blocks':
				return ReplacePostBlocksAbility::execute( $input );

			case 'insert_content':
				return self::apply_insert_content( $input );

			default:
				return array(
					'success' => false,
					'error'   => sprintf( 'Unknown pending diff type: %s', $type ),
				);
		}
	}

	/**
	 * Apply an insert-content diff.
	 *
	 * @param array $input Original insert input.
	 * @return array
	 */
	private static function apply_insert_content( array $input ): array {
		$input['preview'] = false;
		return InsertContentAbility::execute( $input );
	}
}
