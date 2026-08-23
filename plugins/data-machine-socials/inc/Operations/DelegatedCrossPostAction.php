<?php
/**
 * Bounded delegated cross-post operation.
 *
 * @package DataMachineSocials\Operations
 */

namespace DataMachineSocials\Operations;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachineSocials\PublishComposerContract;

defined( 'ABSPATH' ) || exit;

final class DelegatedCrossPostAction {

	public const ACTION_ID = 'datamachine-socials/cross-post';

	private const VERSION = '3';

	private const INPUT_KEYS = array(
		'post_site_id',
		'post_id',
		'attribution_post',
		'source_url',
		'caption',
		'content_hash',
		'channels',
		'media_kind',
		'asset_refs',
	);

	private const RESULT_SOURCE = 'datamachine_socials_cross_post';

	/** Register the owner action through Data Machine's public filter. */
	public static function register(): void {
		add_filter( 'datamachine_delegated_operation_actions', array( self::class, 'register_action' ) );
		new \DataMachineSocials\Abilities\SocialPublishAbility();
	}

	/**
	 * @param array $actions Registered delegated actions.
	 * @return array
	 */
	public static function register_action( array $actions ): array {
		$contract = self::contract( self::VERSION );

		$contract['versions'] = array(
			'1' => self::contract( '1' ),
			'2' => self::contract( '2' ),
		);

		$actions[ self::ACTION_ID ] = $contract;

		return $actions;
	}

	private static function contract( string $version ): array {
		return array(
			'version'         => $version,
			'normalize_input' => array( self::class, 'normalize_input' ),
			'authorize'       => array( self::class, 'authorize' ),
			'prepare'         => array( self::class, 'prepare' ),
			'project'         => array( self::class, 'project' ),
			'retry'           => array( self::class, 'retry' ),
		);
	}

	/**
	 * Normalize and validate owner-controlled operation input before enqueue.
	 *
	 * @param array $input   Raw operation input.
	 * @param array $context Data Machine operation context.
	 * @return array|\WP_Error
	 */
	public static function normalize_input( array $input, array $context = array() ) {
		$unknown_keys = array_diff( array_keys( $input ), self::INPUT_KEYS );
		if ( ! empty( $unknown_keys ) ) {
			return self::error( 'social_cross_post_invalid_input', 'Unknown input fields are not allowed.' );
		}

		$phase = is_string( $context['phase'] ?? null ) ? $context['phase'] : 'submit';
		if ( 'submit' === $phase && array_key_exists( 'post_site_id', $input ) ) {
			return self::error( 'social_cross_post_invalid_input', 'The canonical post site is owner-controlled.' );
		}

		$post_site_id = array_key_exists( 'post_site_id', $input )
			? self::strict_positive_int( $input['post_site_id'] )
			: get_current_blog_id();
		if ( ! self::is_valid_site( $post_site_id ) ) {
			return self::error( 'social_cross_post_invalid_post', 'The canonical post site is invalid.' );
		}

		return self::with_site(
			$post_site_id,
			static function () use ( $input, $post_site_id ) {
				$post_id = self::strict_positive_int( $input['post_id'] ?? null );
				if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
					return self::error( 'social_cross_post_invalid_post', 'A published canonical post is required.' );
				}

				$attribution_post = self::normalize_attribution_post( $input['attribution_post'] ?? null, array_key_exists( 'attribution_post', $input ) );
				if ( is_wp_error( $attribution_post ) ) {
					return $attribution_post;
				}

				$canonical_url = get_permalink( $post_id );
				$source_url    = $input['source_url'] ?? null;
				if ( ! is_string( $source_url ) || ! self::is_public_url( $source_url ) || ! is_string( $canonical_url ) || ! hash_equals( $canonical_url, $source_url ) ) {
					return self::error( 'social_cross_post_invalid_source_url', 'The source URL must match the canonical post URL.' );
				}

				$channels = self::normalize_channels( $input['channels'] ?? null );
				if ( is_wp_error( $channels ) ) {
					return $channels;
				}

				$caption = $input['caption'] ?? null;
				if ( ! is_string( $caption ) || sanitize_textarea_field( $caption ) !== $caption || '' === trim( $caption ) || mb_strlen( $caption ) > self::caption_limit( $channels, $source_url ) ) {
					return self::error( 'social_cross_post_invalid_caption', 'The approved caption must be canonical text within every selected channel limit.' );
				}

				$content_hash = $input['content_hash'] ?? null;
				if ( ! is_string( $content_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $content_hash ) || ! hash_equals( hash( 'sha256', $caption ), $content_hash ) ) {
					return self::error( 'social_cross_post_content_hash_mismatch', 'The approved content hash does not match the caption.' );
				}

				$media_kind = $input['media_kind'] ?? null;
				if ( ! is_string( $media_kind ) ) {
					return self::error( 'social_cross_post_unsupported_media_kind', 'The requested media kind is not supported.' );
				}

				if ( $channels ) {
					$contract_validation = PublishComposerContract::validate_cross_post( $channels, $media_kind );
					if ( is_wp_error( $contract_validation ) ) {
						return $contract_validation;
					}
				}

				$assets = self::normalize_asset_refs( $input['asset_refs'] ?? null, $media_kind );
				if ( is_wp_error( $assets ) ) {
					return $assets;
				}
				if ( 'carousel' === $media_kind && in_array( 'twitter', $channels, true ) && count( $assets['images'] ) > 4 ) {
					return self::error( 'social_cross_post_unsupported_channel_media', 'Twitter carousels support at most four image assets.' );
				}

				$normalized = array(
					'post_site_id'  => $post_site_id,
					'post_id'       => $post_id,
					'source_url'    => $source_url,
					'caption'       => $caption,
					'content_hash'  => $content_hash,
					'channels'      => $channels,
					'media_kind'    => $media_kind,
					'asset_refs'    => $assets['refs'],
					'images'        => $assets['images'],
					'video_url'     => $assets['video_url'],
					'cover_url'     => $assets['cover_url'],
					'share_to_feed' => true,
				);
				if ( null !== $attribution_post ) {
					$normalized['attribution_post'] = $attribution_post;
				}

				return $normalized;
			}
		);
	}

	/**
	 * Require an explicit bounded authorization decision for every phase.
	 *
	 * @param array $context Data Machine operation context.
	 * @return true|\WP_Error
	 */
	public static function authorize( array $context ) {
		/**
		 * Filter delegated cross-post authorization.
		 *
		 * This action is denied by default. A domain owner may authorize its exact
		 * resource using the normalized input and actor supplied in the context.
		 *
		 * @param bool  $authorized Whether the operation is authorized.
		 * @param array $context    Delegated operation context.
		 */
		$authorized = apply_filters( 'datamachine_socials_delegated_cross_post_authorized', false, $context );

		return true === $authorized
			? true
			: self::error( 'social_cross_post_forbidden', 'The actor is not authorized for this cross-post operation.' );
	}

	/**
	 * Compose the private workflow and stable Data Machine execution owner.
	 *
	 * @param array $input   Normalized owner input.
	 * @param array $context Data Machine operation context.
	 * @return array|\WP_Error
	 */
	public static function prepare( array $input, array $context ) {
		$owner = function_exists( 'datamachine_resolve_system_agent_context' )
			? datamachine_resolve_system_agent_context()
			: array();

		/**
		 * Filter the trusted, stable execution owner registered for this action.
		 *
		 * @param array $owner   Data Machine user and agent identity.
		 * @param array $input   Normalized owner input.
		 * @param array $context Delegated operation context.
		 */
		/** @var mixed $filtered_owner Owner filters must remain runtime-validated. */
		$filtered_owner = apply_filters( 'datamachine_socials_delegated_cross_post_execution_owner', $owner, $input, $context );
		$owner          = $filtered_owner;

		if ( ! is_array( $owner ) || empty( $owner['user_id'] ) || empty( $owner['agent_id'] ) ) {
			return self::error( 'social_cross_post_owner_unavailable', 'The stable Socials execution owner is unavailable.' );
		}

		$params = array(
			'post_site_id'            => $input['post_site_id'],
			'post_id'                 => $input['post_id'],
			'platforms'               => $input['channels'],
			'caption'                 => $input['caption'],
			'images'                  => $input['images'],
			'media_kind'              => $input['media_kind'],
			'video_url'               => $input['video_url'],
			'cover_url'               => $input['cover_url'],
			'share_to_feed'           => true,
			'source_url'              => $input['source_url'],
			'delegated_operation_ref' => (string) ( $context['operation_ref'] ?? '' ),
			'delegated_input'         => self::canonical_input( $input ),
		);
		if ( isset( $input['attribution_post'] ) ) {
			$params['attribution_post'] = $input['attribution_post'];
		}

		return array(
			'owner_user_id' => (int) $owner['user_id'],
			'agent_id'      => (int) $owner['agent_id'],
			'label'         => 'Delegated social cross-post',
			'workflow'      => array(
				'steps' => array(
					array(
						'step_type'          => 'system_task',
						'flow_step_settings' => array(
							'task_type' => 'social_cross_post',
							'params'    => $params,
						),
					),
				),
			),
		);
	}

	/**
	 * Project canonical packet references onto the bounded public result.
	 *
	 * @param array $run_result Canonical datamachine.run_result.v1 envelope.
	 * @param array $context    Data Machine operation context.
	 * @return array
	 */
	public static function project( array $run_result, array $context = array() ): array {
		if ( empty( $run_result ) ) {
			return array();
		}
		if ( in_array( $run_result['status'] ?? '', array( 'submitted', 'executing', 'retrying' ), true ) ) {
			return array();
		}
		if ( str_starts_with( strtolower( (string) ( $run_result['status'] ?? '' ) ), 'cancelled' ) ) {
			return array(
				'effect_count'   => 0,
				'classification' => 'cancelled',
				'share_refs'     => array(),
				'error_codes'    => array(),
			);
		}

		$share_refs    = array();
		$error_codes   = array();
		$input         = is_array( $context['input'] ?? null ) ? $context['input'] : array();
		$tracking_post = self::tracking_post( $input );
		$post_id       = $tracking_post['post_id'];
		$operation_ref = is_string( $context['operation_ref'] ?? null ) ? $context['operation_ref'] : '';
		foreach ( self::packet_refs( $run_result ) as $ref ) {
			if ( self::RESULT_SOURCE !== ( $ref['source_type'] ?? '' ) ) {
				continue;
			}

			$channel = self::bounded_channel( $ref['source_id'] ?? '' );
			if ( '' === $channel ) {
				continue;
			}

			if ( 'social_share_ref' === ( $ref['type'] ?? '' ) ) {
				$post_site_id = $tracking_post['site_id'];
				$receipt = $post_id && '' !== $operation_ref
					? self::with_site( $post_site_id, static fn() => \DataMachineSocials\Tracking\SocialShareTracker::get_operation_share( $post_id, $channel, $operation_ref ) )
					: null;

				$packet_id = sanitize_text_field( (string) ( $ref['source_item_id'] ?? '' ) );
				if ( ! is_array( $receipt ) || ! hash_equals( (string) ( $receipt['platform_post_id'] ?? '' ), $packet_id ) ) {
					$error_codes[] = array(
						'channel' => $channel,
						'code'    => 'delivery_receipt_failed',
					);
					continue;
				}
				$share_refs[] = array(
					'channel'          => $channel,
					'platform_post_id' => $packet_id,
				);
			} elseif ( 'social_share_error' === ( $ref['type'] ?? '' ) ) {
				$code = (string) ( $ref['source_item_id'] ?? '' );
				if ( in_array( $code, array( 'channel_unavailable', 'delivery_receipt_failed', 'delivery_unknown', 'effect_authorization_failed', 'resource_changed', 'undelivered' ), true ) ) {
					$error_codes[] = array(
						'channel' => $channel,
						'code'    => $code,
					);
				}
			}
		}

		$effect_count   = count( $share_refs );
		$classification = 'no_op';
		if ( $effect_count > 0 && ! empty( $error_codes ) ) {
			$classification = 'partial';
		} elseif ( $effect_count > 0 ) {
			$classification = 'success';
		} elseif ( ! empty( $error_codes ) || self::run_failed( $run_result ) ) {
			$classification = 'failure';
		}

		return array(
			'effect_count'   => $effect_count,
			'classification' => $classification,
			'share_refs'     => $share_refs,
			'error_codes'    => $error_codes,
		);
	}

	/**
	 * Prove that replay can only attempt channels without a recorded effect.
	 *
	 * @param array $run_result Failed canonical datamachine.run_result.v1 envelope.
	 * @param array $context    Frozen delegated operation context.
	 * @return true|\WP_Error
	 */
	public static function retry( array $run_result, array $context ) {
		$input         = is_array( $context['input'] ?? null ) ? $context['input'] : array();
		$tracking_post = self::tracking_post( $input );
		$post_id       = $tracking_post['post_id'];
		$operation_ref = is_string( $context['operation_ref'] ?? null ) ? $context['operation_ref'] : '';
		$channels      = is_array( $input['channels'] ?? null ) ? $input['channels'] : array();
		if ( ! $post_id || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) || array() === $channels ) {
			return self::error( 'social_cross_post_retry_unsafe', 'The prior delivery effects cannot be reconciled safely.' );
		}

		$live_input = self::validate_effect( $input, self::bounded_actor( $context['actor'] ?? array() ), $operation_ref, 'retry' );
		if ( is_wp_error( $live_input ) ) {
			return $live_input;
		}

		$failures = array();
		$shares   = array();
		foreach ( self::packet_refs( $run_result ) as $ref ) {
			if ( self::RESULT_SOURCE !== ( $ref['source_type'] ?? '' ) ) {
				continue;
			}

			$channel = self::bounded_channel( $ref['source_id'] ?? '' );
			if ( '' === $channel ) {
				continue;
			}

			if ( 'social_share_ref' === ( $ref['type'] ?? '' ) ) {
				$shares[ $channel ] = (string) ( $ref['source_item_id'] ?? '' );
			} elseif ( 'social_share_error' === ( $ref['type'] ?? '' ) ) {
				$failures[ $channel ] = (string) ( $ref['source_item_id'] ?? '' );
			}
		}

		foreach ( $channels as $channel ) {
			$channel = self::bounded_channel( $channel );
			if ( '' === $channel ) {
				return self::error( 'social_cross_post_retry_unsafe', 'The prior delivery effects cannot be reconciled safely.' );
			}

			$post_site_id = self::tracking_post( $live_input )['site_id'];
			$receipt = self::with_site( $post_site_id, static fn() => \DataMachineSocials\Tracking\SocialShareTracker::get_operation_share( $post_id, $channel, $operation_ref ) );
			if ( is_array( $receipt ) ) {
				$recorded_id = (string) ( $receipt['platform_post_id'] ?? '' );
				if ( isset( $shares[ $channel ] ) && ! hash_equals( $recorded_id, $shares[ $channel ] ) ) {
					return self::error( 'social_cross_post_retry_unsafe', 'The prior delivery effects cannot be reconciled safely.' );
				}
				continue;
			}

			if ( isset( $shares[ $channel ] ) || ! in_array( $failures[ $channel ] ?? '', array( 'channel_unavailable', 'undelivered' ), true ) ) {
				return self::error( 'social_cross_post_retry_unsafe', 'The prior delivery effects cannot be reconciled safely.' );
			}
		}

		return true;
	}

	/** Map private provider failures to bounded public codes. */
	public static function classify_error( $error ): string {
		if ( is_array( $error ) ) {
			if ( 'undelivered' === ( $error['delivery_state'] ?? '' ) ) {
				return 'undelivered';
			}
			$error = $error['error_code'] ?? ( $error['error'] ?? '' );
		}
		if ( in_array( $error, array( 'delivery_receipt_failed', 'effect_authorization_failed', 'resource_changed' ), true ) ) {
			return (string) $error;
		}
		if ( is_string( $error ) && str_contains( $error, 'not registered' ) ) {
			return 'channel_unavailable';
		}

		return 'delivery_unknown';
	}

	/** Revalidate frozen input and owner authority immediately before effects. */
	public static function validate_effect( array $input, array $actor, string $operation_ref, string $phase = 'effect' ) {
		$normalized = self::normalize_input( self::canonical_input( $input ), array( 'phase' => $phase ) );
		if ( is_wp_error( $normalized ) ) {
			return self::error( 'resource_changed', 'The approved cross-post resource is no longer valid.' );
		}

		$authorized = self::authorize(
			array(
				'phase'         => $phase,
				'action'        => self::ACTION_ID,
				'operation_ref' => $operation_ref,
				'actor'         => self::bounded_actor( $actor ),
				'input'         => $normalized,
			)
		);
		if ( is_wp_error( $authorized ) ) {
			return self::error( 'effect_authorization_failed', 'The initiating actor is no longer authorized for this effect.' );
		}

		return $normalized;
	}

	/** Resolve the frozen initiator from the verified delegated parent job. */
	public static function resolve_effect_actor( int $parent_job_id, string $operation_ref ) {
		if ( $parent_job_id <= 0 || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
			return self::error( 'effect_authorization_failed', 'The initiating actor could not be verified.' );
		}

		$job       = ( new Jobs() )->get_job( $parent_job_id );
		$envelope  = is_array( $job['operation_envelope'] ?? null ) ? $job['operation_envelope'] : array();
		$operation = is_array( $envelope['delegated_operation'] ?? null ) ? $envelope['delegated_operation'] : array();
		if (
			'delegated' !== ( $job['source'] ?? '' )
			|| self::ACTION_ID !== ( $operation['action'] ?? '' )
			|| ! hash_equals( (string) ( $operation['operation_ref'] ?? '' ), $operation_ref )
		) {
			return self::error( 'effect_authorization_failed', 'The initiating actor could not be verified.' );
		}

		$actor = self::bounded_actor( $operation['initiator'] ?? array() );
		if ( 0 === $actor['user_id'] && 0 === $actor['agent_id'] ) {
			return self::error( 'effect_authorization_failed', 'The initiating actor could not be verified.' );
		}

		return $actor;
	}

	private static function canonical_input( array $input ): array {
		return array_intersect_key( $input, array_flip( self::INPUT_KEYS ) );
	}

	private static function bounded_actor( $actor ): array {
		$actor = is_array( $actor ) ? $actor : array();
		return array(
			'user_id'  => max( 0, (int) ( $actor['user_id'] ?? 0 ) ),
			'agent_id' => max( 0, (int) ( $actor['agent_id'] ?? 0 ) ),
		);
	}

	/** @return array{site_id:int,post_id:int}|null|\WP_Error */
	private static function normalize_attribution_post( $reference, bool $provided ) {
		if ( ! $provided ) {
			return null;
		}
		if ( ! is_array( $reference ) || array_diff( array_keys( $reference ), array( 'site_id', 'post_id' ) ) || 2 !== count( $reference ) ) {
			return self::error( 'social_cross_post_invalid_attribution_post', 'The attribution post reference is invalid.' );
		}

		$site_id = self::strict_positive_int( $reference['site_id'] ?? null );
		$post_id = self::strict_positive_int( $reference['post_id'] ?? null );
		if ( ! self::is_valid_site( $site_id ) ) {
			return self::error( 'social_cross_post_invalid_attribution_post', 'The attribution post reference is invalid.' );
		}

		$published = self::with_site( $site_id, static fn(): bool => 'publish' === get_post_status( $post_id ) );
		return $post_id && $published
			? compact( 'site_id', 'post_id' )
			: self::error( 'social_cross_post_invalid_attribution_post', 'The attribution post must be published.' );
	}

	/** @return array{site_id:int,post_id:int} */
	private static function tracking_post( array $input ): array {
		$reference = is_array( $input['attribution_post'] ?? null ) ? $input['attribution_post'] : array();
		$site_id   = self::strict_positive_int( $reference['site_id'] ?? null );
		$post_id   = self::strict_positive_int( $reference['post_id'] ?? null );
		if ( $site_id && $post_id ) {
			return compact( 'site_id', 'post_id' );
		}

		$site_id = self::strict_positive_int( $input['post_site_id'] ?? null ) ?: get_current_blog_id();
		$post_id = self::strict_positive_int( $input['post_id'] ?? null );
		return compact( 'site_id', 'post_id' );
	}

	/** @return array<int,array<string,mixed>> */
	private static function packet_refs( array $run_result ): array {
		if ( is_array( $run_result['packet_refs'] ?? null ) ) {
			return array_values( array_filter( $run_result['packet_refs'], 'is_array' ) );
		}

		$refs = array();
		foreach ( array( 'steps', 'step_results' ) as $steps_key ) {
			foreach ( is_array( $run_result[ $steps_key ] ?? null ) ? $run_result[ $steps_key ] : array() as $step ) {
				if ( is_array( $step['packet_refs'] ?? null ) ) {
					$refs = array_merge( $refs, $step['packet_refs'] );
				}
			}
		}

		return array_values( array_filter( $refs, 'is_array' ) );
	}

	private static function run_failed( array $run_result ): bool {
		$status = strtolower( (string) ( $run_result['status'] ?? '' ) );
		return 'failed' === $status || str_starts_with( $status, 'failed' );
	}

	/**
	 * @param mixed $channels Raw channels.
	 * @return array|\WP_Error
	 */
	private static function normalize_channels( $channels ) {
		$supported = PublishComposerContract::cross_post_channels();
		if ( ! is_array( $channels ) || ! array_is_list( $channels ) || count( $channels ) > count( $supported ) ) {
			return self::error( 'social_cross_post_invalid_channels', 'Channels must be a bounded list.' );
		}

		$normalized = array();
		foreach ( $channels as $channel ) {
			if ( ! is_string( $channel ) || ! in_array( $channel, $supported, true ) ) {
				return self::error( 'social_cross_post_unsupported_channel', 'A requested channel is not supported.' );
			}
			if ( in_array( $channel, $normalized, true ) ) {
				return self::error( 'social_cross_post_invalid_channels', 'Duplicate channels are not allowed.' );
			}
			$normalized[] = $channel;
		}

		sort( $normalized );
		return $normalized;
	}

	/**
	 * @param string[] $channels Normalized channels.
	 */
	private static function caption_limit( array $channels, string $source_url ): int {
		$limits = array(
			'bluesky'   => 300,
			'facebook'  => 63206,
			'instagram' => max( 1, 2200 - mb_strlen( "\n\n" . $source_url ) ),
			'pinterest' => 500,
			'threads'   => max( 1, 498 - mb_strlen( $source_url ) ),
			'twitter'   => 256,
		);

		if ( empty( $channels ) ) {
			return 2200;
		}

		$channel_limits = array_intersect_key( $limits, array_flip( $channels ) );
		return empty( $channel_limits ) ? 2200 : min( $channel_limits );
	}

	/**
	 * @param mixed  $asset_refs Raw attachment references.
	 * @param string $media_kind Requested media kind.
	 * @return array|\WP_Error
	 */
	private static function normalize_asset_refs( $asset_refs, string $media_kind ) {
		if ( ! is_array( $asset_refs ) || ! array_is_list( $asset_refs ) || count( $asset_refs ) > 11 ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Asset references must be a bounded list.' );
		}

		$refs        = array();
		$images      = array();
		$video_url   = '';
		$cover_url   = '';
		$seen_ids    = array();
		$valid_roles = array( 'image', 'video', 'cover' );

		foreach ( $asset_refs as $ref ) {
			if ( ! is_array( $ref ) || array_diff( array_keys( $ref ), array( 'source_id', 'attachment_id', 'role' ) ) || ! isset( $ref['role'] ) ) {
				return self::error( 'social_cross_post_invalid_asset_ref', 'Each asset reference requires one canonical identity and role.' );
			}

			$has_source_id     = array_key_exists( 'source_id', $ref );
			$has_attachment_id = array_key_exists( 'attachment_id', $ref );
			if ( $has_source_id === $has_attachment_id ) {
				return self::error( 'social_cross_post_invalid_asset_ref', 'Each asset reference requires exactly one canonical identity.' );
			}

			$identity = $has_source_id
				? self::parse_asset_source_id( $ref['source_id'] )
				: array(
					'site_id'       => get_current_blog_id(),
					'attachment_id' => self::strict_positive_int( $ref['attachment_id'] ),
				);
			$role     = $ref['role'];
			if ( is_wp_error( $identity ) || ! is_string( $role ) || ! in_array( $role, $valid_roles, true ) ) {
				return self::error( 'social_cross_post_invalid_asset_ref', 'An asset reference is malformed or duplicated.' );
			}
			$site_id       = $identity['site_id'];
			$attachment_id = $identity['attachment_id'];
			$source_id     = $site_id . ':' . $attachment_id;
			if ( in_array( $source_id, $seen_ids, true ) || ! self::is_valid_site( $site_id ) ) {
				return self::error( 'social_cross_post_invalid_asset_ref', 'An asset reference is malformed or duplicated.' );
			}

			$asset = self::with_site(
				$site_id,
				static fn(): array => array(
					'type' => get_post_type( $attachment_id ),
					'url'  => wp_get_attachment_url( $attachment_id ),
					'mime' => (string) get_post_mime_type( $attachment_id ),
				)
			);
			if ( 'attachment' !== $asset['type'] ) {
				return self::error( 'social_cross_post_invalid_asset_ref', 'An asset reference is malformed or duplicated.' );
			}
			$url  = $asset['url'];
			$mime = $asset['mime'];
			if ( ! is_string( $url ) || ! self::is_public_url( $url ) ) {
				return self::error( 'social_cross_post_asset_not_public', 'Every asset must resolve to a public URL.' );
			}
			if ( ( 'video' === $role && ! str_starts_with( $mime, 'video/' ) ) || ( 'video' !== $role && ! str_starts_with( $mime, 'image/' ) ) ) {
				return self::error( 'social_cross_post_invalid_asset_kind', 'An asset MIME type does not match its role.' );
			}

			if ( 'video' === $role ) {
				if ( '' !== $video_url ) {
					return self::error( 'social_cross_post_invalid_asset_refs', 'Only one video asset is supported.' );
				}
				$video_url = $url;
			} elseif ( 'cover' === $role ) {
				if ( '' !== $cover_url ) {
					return self::error( 'social_cross_post_invalid_asset_refs', 'Only one cover asset is supported.' );
				}
				$cover_url = $url;
			} else {
				$images[] = array( 'url' => $url );
			}

			$refs[]     = array(
				'source_id' => $source_id,
				'role'      => $role,
			);
			$seen_ids[] = $source_id;
		}

		$image_count = count( $images );
		if ( 'image' === $media_kind && 1 !== $image_count ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Image publishing requires exactly one image asset.' );
		}
		if ( 'carousel' === $media_kind && ( $image_count < 2 || $image_count > 10 ) ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Carousel publishing requires between two and ten image assets.' );
		}
		if ( 'reel' === $media_kind && '' === $video_url ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Reel publishing requires one video asset.' );
		}
		if ( 'story' === $media_kind && '' === $video_url && 1 !== $image_count ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Story publishing requires one image or video asset.' );
		}
		if ( ! in_array( $media_kind, array( 'reel', 'story' ), true ) && ( '' !== $video_url || '' !== $cover_url ) ) {
			return self::error( 'social_cross_post_invalid_asset_refs', 'Video and cover assets are not valid for this media kind.' );
		}

		return compact( 'refs', 'images', 'video_url', 'cover_url' );
	}

	private static function bounded_channel( $channel ): string {
		return is_string( $channel ) && in_array( $channel, PublishComposerContract::cross_post_channels(), true ) ? $channel : '';
	}

	private static function strict_positive_int( $value ): int {
		return is_int( $value ) && $value > 0 ? $value : 0;
	}

	/** @return array{site_id:int,attachment_id:int}|\WP_Error */
	private static function parse_asset_source_id( $source_id ) {
		if ( ! is_string( $source_id ) || 1 !== preg_match( '/^([1-9][0-9]*):([1-9][0-9]*)$/', $source_id, $matches ) ) {
			return self::error( 'social_cross_post_invalid_asset_ref', 'The asset identity is invalid.' );
		}

		$site_id       = filter_var( $matches[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$attachment_id = filter_var( $matches[2], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false !== $site_id && false !== $attachment_id
			? array(
				'site_id'       => $site_id,
				'attachment_id' => $attachment_id,
			)
			: self::error( 'social_cross_post_invalid_asset_ref', 'The asset identity is invalid.' );
	}

	private static function is_valid_site( int $site_id ): bool {
		if ( $site_id <= 0 ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return get_current_blog_id() === $site_id;
		}

		$site = get_site( $site_id );
		return $site instanceof \WP_Site
			&& get_current_network_id() === (int) $site->network_id
			&& ! $site->archived
			&& ! $site->deleted
			&& ! $site->spam;
	}

	/** @return mixed */
	private static function with_site( int $site_id, callable $callback ) {
		$switched = get_current_blog_id() !== $site_id;
		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	private static function is_public_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
