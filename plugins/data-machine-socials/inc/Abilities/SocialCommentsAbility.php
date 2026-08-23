<?php
/**
 * Generic account-level social comments ability.
 *
 * @package DataMachineSocials
 */

namespace DataMachineSocials\Abilities;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch recent comments without requiring callers to enumerate media.
 */
class SocialCommentsAbility extends AbstractSocialAbility {

	protected static bool $registered = false;

	private const PROVIDERS = array(
		'instagram' => 'datamachine/instagram-read',
		'facebook'  => 'datamachine/facebook-read',
	);

	public function __construct() {
		$this->registerAbility( $this->registerCallback(), true );
	}

	private function registerCallback(): callable {
		return function () {
			wp_register_ability(
				'datamachine/social-comments',
				array(
					'label'               => __( 'Read Recent Social Comments', 'data-machine-socials' ),
					'description'         => __( 'Read recent account comments through a normalized provider-independent contract.', 'data-machine-socials' ),
					'category'            => 'datamachine-socials',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'action'   => array(
								'type'    => 'string',
								'enum'    => array( 'recent_comments' ),
								'default' => 'recent_comments',
							),
							'provider' => array(
								'type'        => 'string',
								'description' => __( 'Provider slug.', 'data-machine-socials' ),
							),
							'limit'    => array(
								'type'        => 'integer',
								'default'     => 25,
								'description' => __( 'Maximum comments to return.', 'data-machine-socials' ),
							),
							'after'    => array(
								'type'        => 'string',
								'description' => __( 'Provider cursor for the next media page.', 'data-machine-socials' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'type' => 'object' ),
							'error'   => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};
	}

	public function checkPermission(): bool {
		return PermissionHelper::can( 'use_tools' );
	}

	/**
	 * Return the stable account-level result envelope.
	 *
	 * Provider reads are deliberately performed through existing read abilities;
	 * this layer owns only aggregation and normalization.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input ): array {
		if ( 'recent_comments' !== ( $input['action'] ?? 'recent_comments' ) ) {
			return array(
				'success' => false,
				'data'    => array(
					'provider' => sanitize_key( $input['provider'] ?? '' ),
					'comments' => array(),
					'count'    => 0,
					'partial'  => false,
					'status'   => 'invalid_action',
				),
				'error'   => 'Unknown action. Use recent_comments.',
			);
		}

		$provider = sanitize_key( $input['provider'] ?? '' );
		$base     = array(
			'provider'    => $provider,
			'comments'    => array(),
			'count'       => 0,
			'partial'     => false,
			'status'      => 'ok',
			'next_cursor' => null,
		);

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			$base['status'] = 'unsupported';
			$base['error']  = 'Recent comments are not supported for this provider.';
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => 'Recent comments are not supported for this provider.',
			);
		}

		$ability = $this->getProviderAbility( $provider );
		if ( ! $ability ) {
			$base['status'] = 'provider_error';
			$base['error']  = 'The provider read ability is not available.';
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => 'The provider read ability is not available.',
			);
		}

		$limit = min( max( absint( $input['limit'] ?? 25 ), 1 ), 100 );
		$list  = array(
			'action' => 'list',
			'limit'  => 25,
		);
		if ( ! empty( $input['after'] ) ) {
			$list['after'] = sanitize_text_field( $input['after'] );
		}

		$media_result = $ability->execute( $list );
		if ( is_wp_error( $media_result ) || empty( $media_result['success'] ) ) {
			$base['status'] = 'provider_error';
			$base['error']  = $this->errorMessage( $media_result, 'Provider media read failed.' );
			return array(
				'success' => false,
				'data'    => $base,
				'error'   => $base['error'],
			);
		}

		$media = $media_result['data']['media'] ?? $media_result['data']['posts'] ?? $media_result['data']['threads'] ?? array();
		foreach ( $media as $item ) {
			$media_id = $item['id'] ?? '';
			if ( ! $media_id ) {
				continue;
			}

			$comments_result = $ability->execute( array(
				'action'   => 'comments',
				'media_id' => $media_id,
				'post_id'  => $media_id,
				'limit'    => min( 25, $limit ),
			) );
			if ( is_wp_error( $comments_result ) || empty( $comments_result['success'] ) ) {
				$base['partial'] = ! empty( $base['comments'] );
				$base['status']  = $base['partial'] ? 'partial' : 'provider_error';
				$base['error']   = $this->errorMessage( $comments_result, 'Provider comments read failed.' );
				if ( ! $base['partial'] ) {
					return array(
						'success' => false,
						'data'    => $base,
						'error'   => $base['error'],
					);
				}
				break;
			}

			$raw_comments = $comments_result['data']['comments'] ?? array();
			foreach ( $raw_comments as $comment ) {
				$base['comments'][] = $this->normalizeComment( $comment, $provider, $media_id );
			}
		}

		usort( $base['comments'], static function ( array $left, array $right ): int {
			return strcmp( $right['timestamp'], $left['timestamp'] );
		} );
		$base['comments'] = array_slice( $base['comments'], 0, $limit );

		$base['count'] = count( $base['comments'] );
		if ( ! empty( $media_result['data']['has_next'] ) && 'ok' === $base['status'] ) {
			$base['partial'] = true;
			$base['status']  = 'partial';
		}
		$base['next_cursor'] = $media_result['data']['cursors']['after'] ?? null;

		return array(
			'success' => true,
			'data'    => $base,
		);
	}

	private function normalizeComment( array $comment, string $provider, string $media_id ): array {
		$text     = (string) ( $comment['text'] ?? $comment['message'] ?? '' );
		$mentions = array();
		if ( preg_match_all( '/@([a-zA-Z0-9._]{1,30})/', $text, $matches ) ) {
			$mentions = array_values( array_unique( $matches[1] ) );
		}

		return array(
			'id'              => (string) ( $comment['id'] ?? '' ),
			'platform'        => $provider,
			'media_id'        => $media_id,
			'author_username' => (string) ( $comment['username'] ?? $comment['from']['name'] ?? '' ),
			'text'            => $text,
			'timestamp'       => (string) ( $comment['timestamp'] ?? $comment['created_time'] ?? '' ),
			'like_count'      => (int) ( $comment['like_count'] ?? 0 ),
			'reply_count'     => (int) ( $comment['reply_count'] ?? 0 ),
			'mentions'        => $mentions,
			'parent_id'       => $comment['parent_id'] ?? null,
			'raw'             => $comment,
		);
	}

	/** Resolve the existing platform read ability. Overridable for contract tests. */
	protected function getProviderAbility( string $provider ) {
		return wp_get_ability( self::PROVIDERS[ $provider ] );
	}

	private function errorMessage( $result, string $fallback ): string {
		return is_wp_error( $result ) ? $result->get_error_message() : ( $result['error'] ?? $fallback );
	}
}
