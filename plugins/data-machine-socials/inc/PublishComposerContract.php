<?php
/**
 * Canonical publish composer discovery and validation.
 *
 * @package DataMachineSocials
 */

namespace DataMachineSocials;

defined( 'ABSPATH' ) || exit;

final class PublishComposerContract {

	public const CROSS_POST_ROUTE = 'datamachine/v1/socials/post';

	/** Return the normalized composer contract for one platform. */
	public static function for_platform( string $platform ): ?array {
		$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();

		foreach ( $handler_abilities->getAllHandlers() as $slug => $handler ) {
			if ( 'publish' !== ( $handler['type'] ?? 'publish' ) ) {
				continue;
			}

			$auth_key = (string) ( $handler['auth_provider_key'] ?? $slug );
			if ( $platform === $auth_key ) {
				return self::for_handler( $handler );
			}
		}

		return null;
	}

	/** Normalize handler-owned metadata into the public composer shape. */
	public static function for_handler( array $handler ): ?array {
		$meta        = is_array( $handler['meta'] ?? null ) ? $handler['meta'] : array();
		$declaration = is_array( $meta['composer'] ?? null ) ? $meta['composer'] : array();
		$media_kinds = is_array( $declaration['mediaKinds'] ?? null )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $declaration['mediaKinds'] ) ) ) )
			: array();

		if ( empty( $declaration ) || empty( $media_kinds ) ) {
			return null;
		}

		$cross_post = true === ( $declaration['crossPostCompatible'] ?? false );
		$target     = $cross_post
			? array(
				'transport' => 'rest',
				'name'      => self::CROSS_POST_ROUTE,
			)
			: array(
				'transport' => 'ability',
				'name'      => sanitize_text_field( (string) ( $declaration['ability'] ?? '' ) ),
			);

		if ( '' === $target['name'] ) {
			return null;
		}

		$input_schema = $cross_post ? self::cross_post_input_schema() : array();
		if ( ! $cross_post ) {
			$ability = wp_get_ability( $target['name'] );
			if ( $ability ) {
				$input_schema = $ability->get_input_schema();
			}
		}

		return array(
			'crossPostCompatible' => $cross_post,
			'mediaKinds'          => $media_kinds,
			'target'              => $target,
			'inputSchema'         => $input_schema,
			'mediaRequirements'   => $cross_post ? self::cross_post_media_requirements( $media_kinds ) : array(),
		);
	}

	/** Validate a generic cross-post selection before any work is scheduled. */
	public static function validate_cross_post( $platforms, string $media_kind ) {
		if ( ! is_array( $platforms ) || ! array_is_list( $platforms ) || empty( $platforms ) ) {
			return new \WP_Error( 'social_cross_post_invalid_channels', 'Platforms must be a non-empty list.' );
		}

		foreach ( $platforms as $platform ) {
			if ( ! is_string( $platform ) ) {
				return new \WP_Error( 'social_cross_post_unsupported_channel', 'A requested platform is not supported by generic cross-posting.' );
			}

			$contract = self::for_platform( $platform );
			if ( ! $contract || ! $contract['crossPostCompatible'] ) {
				return new \WP_Error( 'social_cross_post_unsupported_channel', 'A requested platform requires a specialized composer.' );
			}
			if ( ! in_array( $media_kind, $contract['mediaKinds'], true ) ) {
				return new \WP_Error( 'social_cross_post_unsupported_channel_media', 'A selected platform does not support the requested media kind.' );
			}
		}

		return true;
	}

	/** Return all handler-declared generic cross-post channels. */
	public static function cross_post_channels(): array {
		$handler_abilities = new \DataMachine\Abilities\HandlerAbilities();
		$channels          = array();

		foreach ( $handler_abilities->getAllHandlers() as $slug => $handler ) {
			$contract = self::for_handler( $handler );
			if ( $contract && $contract['crossPostCompatible'] ) {
				$channels[] = (string) ( $handler['auth_provider_key'] ?? $slug );
			}
		}

		sort( $channels );
		return array_values( array_unique( $channels ) );
	}

	private static function cross_post_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'platforms', 'caption', 'media_kind' ),
			'properties' => array(
				'platforms'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'caption'      => array( 'type' => 'string' ),
				'media_kind'   => array(
					'type' => 'string',
					'enum' => array( 'image', 'carousel', 'reel', 'story' ),
				),
				'images'       => array( 'type' => 'array' ),
				'video_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'aspect_ratio' => array( 'type' => 'string' ),
			),
		);
	}

	private static function cross_post_media_requirements( array $media_kinds ): array {
		$requirements = array(
			'image'    => array( 'required' => array( 'caption', 'images' ) ),
			'carousel' => array( 'required' => array( 'caption', 'images' ) ),
			'reel'     => array( 'required' => array( 'caption', 'video_url' ) ),
			'story'    => array(
				'required'      => array( 'caption' ),
				'requiredAnyOf' => array( 'images', 'video_url' ),
			),
		);

		return array_intersect_key( $requirements, array_flip( $media_kinds ) );
	}
}
