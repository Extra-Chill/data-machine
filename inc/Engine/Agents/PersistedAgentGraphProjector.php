<?php
/**
 * Read-only persisted agent graph projection for generic runtime adapters.
 *
 * @package DataMachine\Engine\Agents
 */

namespace DataMachine\Engine\Agents;

use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\FilesRepository\DirectoryManager;
use DataMachine\Engine\Bundle\AgentBundleArtifactState;
use DataMachine\Engine\Bundle\BundleRelativePath;
use DataMachine\Engine\Bundle\BundleValidationException;

defined( 'ABSPATH' ) || exit;

final class PersistedAgentGraphProjector {

	/** @return array<string,mixed> */
	public static function project( string $slug, ?Agents $repository = null ): array {
		$repository = $repository ?? new Agents();
		$root       = $repository->get_by_slug( sanitize_title( $slug ) );
		if ( ! $root ) {
			return array(
				'success' => false,
				'error'   => 'Agent not found.',
			);
		}

		try {
			$nodes   = array();
			$pending = array( $root );
			while ( ! empty( $pending ) ) {
				$row  = array_shift( $pending );
				$slug = (string) $row['agent_slug'];
				if ( isset( $nodes[ $slug ] ) ) {
					continue;
				}
				$config         = is_array( $row['agent_config'] ?? null ) ? $row['agent_config'] : array();
				$edges          = AgentSubagentGraph::edges_from_config( $config['subagents'] ?? array(), $slug );
				$paths          = array();
				$artifact_paths = array(
					'skills'     => array(),
					'references' => array(),
				);
				if ( class_exists( DirectoryManager::class ) ) {
					$directory       = ( new DirectoryManager() )->get_agent_identity_directory( $slug );
					$identity_hashes = self::identity_hashes( $row );
					foreach ( array( 'SOUL.md', 'MEMORY.md' ) as $filename ) {
						if ( isset( $identity_hashes[ $filename ] ) || is_file( $directory . '/' . $filename ) ) {
							$paths[ $filename ] = self::verified_source_path( $directory, $filename, $identity_hashes[ $filename ] ?? null, 'instruction' );
						}
					}
					$stored_subagent = is_array( $config['datamachine_subagent'] ?? null ) ? $config['datamachine_subagent'] : array();
					foreach ( array( 'skills', 'references' ) as $kind ) {
						foreach ( is_array( $stored_subagent[ $kind ] ?? null ) ? $stored_subagent[ $kind ] : array() as $relative => $sha256 ) {
							if ( ! is_string( $relative ) || ! is_string( $sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
								continue;
							}
							$artifact_paths[ $kind ][ (string) $relative ] = self::verified_source_path( $directory . '/' . $kind, $relative, $sha256, $kind );
						}
					}
				}
				$subagent       = is_array( $config['datamachine_subagent'] ?? null ) ? $config['datamachine_subagent'] : array();
				$nodes[ $slug ] = array(
					'slug'         => $slug,
					'label'        => (string) ( $row['agent_name'] ?? $slug ),
					'description'  => (string) ( $config['description'] ?? '' ),
					'subagents'    => $edges,
					'model'        => (string) ( $config['model'] ?? $config['default_model'] ?? '' ),
					'sources'      => array(
						'instructions' => $paths,
						'skills'       => $artifact_paths['skills'],
						'references'   => $artifact_paths['references'],
					),
					'tool_policy'  => is_array( $subagent['tool_policy'] ?? null ) ? $subagent['tool_policy'] : array(),
					'skill_policy' => array_merge( is_array( $subagent['skill_policy'] ?? null ) ? $subagent['skill_policy'] : array(), array( 'paths' => array_keys( $artifact_paths['skills'] ) ) ),
				);
				foreach ( $edges as $edge ) {
					$child = $repository->get_by_slug( $edge );
					if ( ! $child ) {
						return array(
							'success'     => false,
							'coordinator' => (string) $root['agent_slug'],
							'error'       => sprintf( 'Persisted subagent is missing: %s.', $edge ),
						);
					}
					$pending[] = $child;
				}
			}
			ksort( $nodes, SORT_STRING );
			return array(
				'success'     => true,
				'coordinator' => (string) $root['agent_slug'],
				'nodes'       => array_values( $nodes ),
			);
		} catch ( BundleValidationException $e ) {
			return array(
				'success'     => false,
				'coordinator' => (string) $root['agent_slug'],
				'error'       => 'Graph projection failed: ' . $e->getMessage(),
			);
		}
	}

	/** @return array<string,string> */
	private static function identity_hashes( array $row ): array {
		$hashes = array();
		foreach ( AgentBundleArtifactState::installed_for_agent( $row ) as $artifact ) {
			if ( 'memory' !== (string) ( $artifact['artifact_type'] ?? '' ) ) {
				continue;
			}
			$id = (string) ( $artifact['artifact_id'] ?? '' );
			if ( str_starts_with( $id, 'agent/' ) && preg_match( '/^[a-f0-9]{64}$/', (string) ( $artifact['installed_hash'] ?? '' ) ) ) {
				$hashes[ substr( $id, 6 ) ] = (string) $artifact['installed_hash'];
			}
		}
		return $hashes;
	}

	private static function verified_source_path( string $root, string $relative, ?string $sha256, string $label ): string {
		BundleRelativePath::validate( $relative, $label );
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exception data is returned to callers, never rendered here.
			throw new BundleValidationException( sprintf( 'Graph %s root is missing: %s', $label, $root ) );
		}
		$path   = BundleRelativePath::contained_join( $root_real, $relative, $label );
		$cursor = $root_real;
		foreach ( explode( '/', $relative ) as $segment ) {
			$cursor .= '/' . $segment;
			if ( is_link( $cursor ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exception data is returned to callers, never rendered here.
				throw new BundleValidationException( sprintf( 'Graph %s source is a symlink: %s', $label, $relative ) );
			}
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! str_starts_with( $real, $root_real . DIRECTORY_SEPARATOR ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exception data is returned to callers, never rendered here.
			throw new BundleValidationException( sprintf( 'Graph %s source is missing or escapes its root: %s', $label, $relative ) );
		}
		$contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is a verified local file path, not a remote URL.
		if ( ! is_string( $contents ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exception data is returned to callers, never rendered here.
			throw new BundleValidationException( sprintf( 'Graph %s source is unreadable: %s', $label, $relative ) );
		}
		if ( null !== $sha256 && ! hash_equals( $sha256, hash( 'sha256', $contents ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation exception data is returned to callers, never rendered here.
			throw new BundleValidationException( sprintf( 'Graph %s source hash does not match: %s', $label, $relative ) );
		}
		return $real;
	}
}
