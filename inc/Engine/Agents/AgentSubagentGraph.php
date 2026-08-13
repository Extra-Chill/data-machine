<?php
/**
 * Portable, persisted Agents API subagent graph contract.
 *
 * @package DataMachine\Engine\Agents
 */

namespace DataMachine\Engine\Agents;

use DataMachine\Engine\Bundle\BundleValidationException;
use DataMachine\Engine\Bundle\PortableSlug;

defined( 'ABSPATH' ) || exit;

final class AgentSubagentGraph {

	/** @return string[] */
	public static function edges_from_config( mixed $edges, string $slug = '' ): array {
		if ( ! is_array( $edges ) || ! array_is_list( $edges ) ) {
			throw new BundleValidationException( 'Subagent edges must be a list.' );
		}
		$normalized = array();
		foreach ( $edges as $edge ) {
			if ( ! is_string( $edge ) || '' === trim( $edge ) ) {
				throw new BundleValidationException( 'Subagent edges must contain non-empty slugs.' );
			}
			$edge_slug = PortableSlug::normalize( $edge, 'subagent' );
			if ( $edge_slug !== trim( $edge ) ) {
				throw new BundleValidationException( sprintf( 'Invalid subagent edge slug: %s.', esc_html( $edge ) ) );
			}
			if ( '' !== $slug && $edge_slug === PortableSlug::normalize( $slug, 'agent' ) ) {
				throw new BundleValidationException( sprintf( 'Subagent %s cannot reference itself.', esc_html( $slug ) ) );
			}
			if ( in_array( $edge_slug, $normalized, true ) ) {
				throw new BundleValidationException( sprintf( 'Duplicate subagent edge: %s.', esc_html( $edge_slug ) ) );
			}
			$normalized[] = $edge_slug;
		}
		sort( $normalized, SORT_STRING );
		return $normalized;
	}

	/**
	 * Normalize and validate a coordinator plus its bundled child definitions.
	 *
	 * @return array<int,array<string,mixed>> Deterministically slug-sorted children.
	 */
	public static function normalize( mixed $children, string $coordinator_slug = '' ): array {
		if ( null === $children || array() === $children ) {
			return array();
		}
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			throw new BundleValidationException( 'manifest.json subagents must be a list.' );
		}

		$nodes = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				throw new BundleValidationException( 'Each subagent definition must be an object.' );
			}
			foreach ( array( 'slug', 'label', 'description', 'agent_config', 'memory', 'tool_policy', 'skills', 'references', 'subagents' ) as $field ) {
				if ( ! array_key_exists( $field, $child ) ) {
					throw new BundleValidationException( sprintf( 'Subagent is missing required field %s.', esc_html( $field ) ) );
				}
			}
			if ( ! is_string( $child['slug'] ) || '' === $child['slug'] ) {
				throw new BundleValidationException( 'Subagent slug must be a non-empty string.' );
			}
			$slug = PortableSlug::normalize( $child['slug'], 'subagent' );
			if ( $slug !== $child['slug'] ) {
				throw new BundleValidationException( sprintf( 'Subagent slug must be canonical: %s.', esc_html( $child['slug'] ) ) );
			}
			if ( '' !== $coordinator_slug && $slug === PortableSlug::normalize( $coordinator_slug, 'agent' ) ) {
				throw new BundleValidationException( 'A coordinator cannot declare itself as a subagent.' );
			}
			if ( isset( $nodes[ $slug ] ) ) {
				throw new BundleValidationException( sprintf( 'Duplicate subagent slug: %s.', esc_html( $slug ) ) );
			}
			if ( ! is_array( $child['agent_config'] ) || ! is_array( $child['memory'] ) || ! is_array( $child['tool_policy'] ) || ! is_array( $child['skills'] ) || ! is_array( $child['references'] ) || ! is_array( $child['subagents'] ) ) {
				throw new BundleValidationException( sprintf( 'Subagent %s has an invalid object field.', esc_html( $slug ) ) );
			}
			if ( array_key_exists( 'skill_policy', $child ) && ( ! is_array( $child['skill_policy'] ) || array_is_list( $child['skill_policy'] ) ) ) {
				throw new BundleValidationException( sprintf( 'Subagent %s has an invalid skill policy.', esc_html( $slug ) ) );
			}
			$memory = array();
			foreach ( $child['memory'] as $path => $contents ) {
				$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
				if ( '' === $path || str_contains( $path, '..' ) || ! is_string( $contents ) ) {
					throw new BundleValidationException( sprintf( 'Subagent %s has an invalid memory file.', esc_html( $slug ) ) );
				}
				$memory[ $path ] = $contents;
			}
			ksort( $memory, SORT_STRING );
			$edges = self::edges_from_config( $child['subagents'], $slug );
			$nodes[ $slug ] = array(
				'slug'         => $slug,
				'label'        => (string) $child['label'],
				'description'  => (string) $child['description'],
				'agent_config' => $child['agent_config'],
				'memory'       => $memory,
				'tool_policy'  => $child['tool_policy'],
				'skill_policy' => is_array( $child['skill_policy'] ?? null ) ? $child['skill_policy'] : array(),
				'skills'       => self::normalize_file_map( $child['skills'], $slug, 'skill' ),
				'references'   => self::normalize_file_map( $child['references'], $slug, 'reference' ),
				'subagents'    => $edges,
			);
		}
		ksort( $nodes, SORT_STRING );
		foreach ( $nodes as $node ) {
			foreach ( $node['subagents'] as $edge ) {
				if ( ! isset( $nodes[ $edge ] ) ) {
					throw new BundleValidationException( sprintf( 'Subagent edge %s -> %s does not resolve within the bundle.', esc_html( $node['slug'] ), esc_html( $edge ) ) );
				}
			}
		}
		self::assert_acyclic( $nodes );
		return array_values( $nodes );
	}

	/** @param string[] $edges @param array<int,array<string,mixed>> $children @return string[] */
	public static function coordinator_edges( mixed $edges, array $children, string $coordinator_slug ): array {
		$normalized = self::edges_from_config( $edges, $coordinator_slug );
		$available  = array_fill_keys( array_column( $children, 'slug' ), true );
		foreach ( $normalized as $edge ) {
			if ( ! isset( $available[ $edge ] ) ) {
				throw new BundleValidationException( sprintf( 'Coordinator edge %s does not resolve within the bundle.', esc_html( $edge ) ) );
			}
		}
		return $normalized;
	}

	private static function assert_acyclic( array $nodes ): void {
		$visiting = array();
		$visited  = array();
		$visit    = static function ( string $slug ) use ( &$visit, &$visiting, &$visited, $nodes ): void {
			if ( isset( $visiting[ $slug ] ) ) {
				throw new BundleValidationException( sprintf( 'Subagent graph contains a cycle at %s.', esc_html( $slug ) ) );
			}
			if ( isset( $visited[ $slug ] ) ) {
				return;
			}
			$visiting[ $slug ] = true;
			foreach ( $nodes[ $slug ]['subagents'] as $child ) {
				$visit( $child );
			}
			unset( $visiting[ $slug ] );
			$visited[ $slug ] = true;
		};
		foreach ( array_keys( $nodes ) as $slug ) {
			$visit( $slug );
		}
	}

	private static function sorted_value( array $value ): array {
		foreach ( $value as $key => $child ) {
			$value[ $key ] = is_array( $child ) ? self::sorted_value( $child ) : $child;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/** @return array<string,string> */
	private static function normalize_file_map( array $files, string $slug, string $kind ): array {
		if ( array_is_list( $files ) ) {
			throw new BundleValidationException( sprintf( 'Subagent %s %s artifacts must be a path-to-bytes object.', esc_html( $slug ), esc_html( $kind ) ) );
		}
		$normalized = array();
		foreach ( $files as $path => $contents ) {
			$path = str_replace( '\\', '/', (string) $path );
			if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, '..' ) || str_contains( $path, '//' ) || in_array( $path, array( '.', '..' ), true ) || ! is_string( $contents ) ) {
				throw new BundleValidationException( sprintf( 'Subagent %s has an invalid %s artifact.', esc_html( $slug ), esc_html( $kind ) ) );
			}
			$normalized[ $path ] = $contents;
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}
}
