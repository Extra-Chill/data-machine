<?php
/**
 * Project persisted Data Machine agents into the Agents API registry.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.110.3
 */

namespace DataMachine\Engine\Agents;

use DataMachine\Core\Agents\AgentConfigFactory;
use DataMachine\Core\Database\Agents\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime projection for durable `datamachine_agents` rows.
 */
class PersistedAgentProjector {

	/**
	 * Register persisted agents as WP_Agent definitions.
	 *
	 * Data Machine's database remains the source of truth. This method only
	 * mirrors current rows into the request-local Agents API registry so APIs
	 * that list or resolve registered agents can see bundle-installed agents.
	 *
	 * @param Agents|null $agents_repository Optional repository for tests.
	 * @return string[] Registered slugs.
	 */
	public static function register_persisted_agents( ?Agents $agents_repository = null ): array {
		if ( ! class_exists( Agents::class ) ) {
			return array();
		}

		$agents_repository = $agents_repository ?? new Agents();
		$registered        = array();
		$existing_agents   = wp_get_agents();

		foreach ( $agents_repository->get_all() as $row ) {
			$slug = sanitize_title( (string) ( $row['agent_slug'] ?? '' ) );
			if ( '' === $slug || isset( $existing_agents[ $slug ] ) || wp_has_agent( $slug ) ) {
				continue;
			}

			$agent = wp_register_agent( $slug, self::definition_from_row( $row ) );
			if ( $agent instanceof \WP_Agent ) {
				$registered_slug                     = $agent->get_slug();
				$registered[]                        = $registered_slug;
				$existing_agents[ $registered_slug ] = $agent;
			}
		}

		return $registered;
	}

	/**
	 * Build WP_Agent registration args from a persisted agent row.
	 *
	 * @param array<string,mixed> $row Data Machine agent row.
	 * @return array<string,mixed>
	 */
	public static function definition_from_row( array $row ): array {
		$config   = AgentConfigFactory::normalize( is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array() );
		$owner_id = (int) ( $row['owner_id'] ?? 0 );

		return array(
			'label'          => self::label_from_row( $row ),
			'description'    => self::description_from_row( $row, $config ),
			'owner_resolver' => static fn(): int => $owner_id,
			'default_config' => $config,
			'meta'           => self::meta_from_row( $row, $config ),
		);
	}

	/**
	 * Resolve display label from durable row fields.
	 *
	 * @param array<string,mixed> $row Data Machine agent row.
	 */
	private static function label_from_row( array $row ): string {
		$label = trim( (string) ( $row['agent_name'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}

		return sanitize_title( (string) ( $row['agent_slug'] ?? 'agent' ) );
	}

	/**
	 * Resolve description from known durable config shapes.
	 *
	 * @param array<string,mixed> $row    Data Machine agent row.
	 * @param array<string,mixed> $config Agent config.
	 */
	private static function description_from_row( array $row, array $config ): string {
		$sources = array( $config );
		if ( function_exists( 'apply_filters' ) ) {
			$sources = apply_filters( 'datamachine_persisted_agent_description_sources', $sources, $row, $config );
		}

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			foreach ( array( 'description', 'agent_description', 'summary' ) as $key ) {
				$description = trim( (string) ( $source[ $key ] ?? '' ) );
				if ( '' !== $description ) {
					return $description;
				}
			}
		}

		return '';
	}

	/**
	 * Build diagnostics/provenance metadata for the runtime definition.
	 *
	 * @param array<string,mixed> $row    Data Machine agent row.
	 * @param array<string,mixed> $config Agent config.
	 * @return array<string,mixed>
	 */
	private static function meta_from_row( array $row, array $config ): array {
		$meta = array(
			'source_plugin'        => 'data-machine',
			'source_type'          => 'persisted-agent',
			'datamachine_agent_id' => (int) ( $row['agent_id'] ?? 0 ),
			'datamachine_owner_id' => (int) ( $row['owner_id'] ?? 0 ),
		);

		foreach ( self::metadata_projectors( $row, $config ) as $projector ) {
			if ( is_callable( $projector ) ) {
				$projected = call_user_func( $projector, $row, $config );
				if ( is_array( $projected ) ) {
					$meta = array_merge( $meta, $projected );
				}
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$meta = apply_filters( 'datamachine_persisted_agent_projection_meta', $meta, $row, $config );
		}

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Return metadata projectors for persisted agent rows.
	 *
	 * Extensions can register domain-specific projectors without Data Machine core
	 * knowing their config shape.
	 *
	 * @param array<string,mixed> $row    Data Machine agent row.
	 * @param array<string,mixed> $config Agent config.
	 * @return array<int,callable>
	 */
	private static function metadata_projectors( array $row, array $config ): array {
		$projectors = array(
			static fn( array $projector_row, array $projector_config ): array => self::bundle_metadata_from_config( $projector_row, $projector_config ),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$projectors = apply_filters( 'datamachine_persisted_agent_metadata_projectors', $projectors, $row, $config );
		}

		return is_array( $projectors ) ? $projectors : array();
	}

	/**
	 * Project generic Data Machine bundle metadata.
	 *
	 * @param array<string,mixed> $row    Data Machine agent row.
	 * @param array<string,mixed> $config Agent config.
	 * @return array<string,mixed>
	 */
	private static function bundle_metadata_from_config( array $row, array $config ): array {
		unset( $row );

		$bundle = is_array( $config['datamachine_bundle'] ?? null ) ? $config['datamachine_bundle'] : array();
		$meta   = array();

		foreach ( array( 'bundle_slug', 'bundle_version', 'source_ref', 'source_revision' ) as $key ) {
			$value = trim( (string) ( $bundle[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$meta[ 'datamachine_' . $key ] = $value;
			}
		}

		if ( ! empty( $bundle['bundle_slug'] ) ) {
			$meta['source_package'] = (string) $bundle['bundle_slug'];
		}

		return $meta;
	}
}
