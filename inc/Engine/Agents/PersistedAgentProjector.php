<?php
/**
 * Project persisted Data Machine agents into the Agents API registry.
 *
 * @package DataMachine\Engine\Agents
 * @since   0.110.3
 */

namespace DataMachine\Engine\Agents;

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

		foreach ( $agents_repository->get_all() as $row ) {
			$slug = sanitize_title( (string) ( $row['agent_slug'] ?? '' ) );
			if ( '' === $slug || wp_has_agent( $slug ) ) {
				continue;
			}

			$agent = wp_register_agent( $slug, self::definition_from_row( $row ) );
			if ( $agent instanceof \WP_Agent ) {
				$registered[] = $agent->get_slug();
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
		$config   = is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array();
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
		foreach ( array( $config, $config['intelligence_wiki_brain'] ?? null ) as $source ) {
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
		$bundle = is_array( $config['datamachine_bundle'] ?? null ) ? $config['datamachine_bundle'] : array();
		$brain  = is_array( $config['intelligence_wiki_brain'] ?? null ) ? $config['intelligence_wiki_brain'] : array();

		$meta = array(
			'source_plugin'        => 'data-machine',
			'source_type'          => 'persisted-agent',
			'datamachine_agent_id' => (int) ( $row['agent_id'] ?? 0 ),
			'datamachine_owner_id' => (int) ( $row['owner_id'] ?? 0 ),
		);

		foreach ( array( 'bundle_slug', 'bundle_version', 'source_ref', 'source_revision' ) as $key ) {
			$value = trim( (string) ( $bundle[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$meta[ 'datamachine_' . $key ] = $value;
			}
		}

		if ( ! empty( $bundle['bundle_slug'] ) ) {
			$meta['source_package'] = (string) $bundle['bundle_slug'];
		}

		foreach ( array( 'domain', 'wiki_slug', 'source_slug', 'brain_slug', 'bundle_slug' ) as $key ) {
			$value = trim( (string) ( $brain[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$meta[ 'intelligence_wiki_brain_' . $key ] = $value;
			}
		}

		return $meta;
	}
}
