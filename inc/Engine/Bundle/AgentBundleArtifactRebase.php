<?php
/**
 * Policy-driven 3-way rebase for locally modified agent bundle artifacts.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Merges local burn-in edits with upstream bundle source-shape fixes.
 *
 * Inputs:
 * - base   = installed artifact payload (last clean upgrade or install).
 * - local  = current live artifact payload on the site (may include burn-in edits).
 * - remote = target bundle artifact payload from the new package.
 * - policy = named merge policy describing which fields are runtime-local vs bundle-owned.
 *
 * Default behavior (policy "conservative") returns the local payload unchanged
 * with every diverging field flagged as ambiguous. Apply paths must keep
 * approval-gating ambiguous artifacts unless an explicit non-conservative
 * policy was supplied.
 *
 * Policies are pluggable via the `datamachine_bundle_rebase_policies` filter.
 * Each policy is a callable accepting an associative array shape:
 *   array(
 *     'artifact_key'  => string,
 *     'artifact_type' => string,
 *     'artifact_id'   => string,
 *     'base'          => mixed,
 *     'local'         => mixed,
 *     'remote'        => mixed,
 *   )
 * and returning an array shape:
 *   array(
 *     'merged'    => mixed,        // Merged payload.
 *     'decisions' => array<string, // Field path decisions (dot-notation).
 *                        array{
 *                          source: 'local'|'remote'|'base'|'merge'|'ambiguous',
 *                          reason: string,
 *                        }>,
 *     'ambiguous' => array<int,string>, // Field paths still requiring approval.
 *   )
 */
final class AgentBundleArtifactRebase {

	public const POLICY_CONSERVATIVE = 'conservative';
	public const POLICY_BURN_IN_SAFE = 'burn-in-safe';

	/**
	 * Rebase one artifact using the named policy.
	 *
	 * @param array<string,mixed> $artifact Artifact envelope: artifact_type, artifact_id,
	 *                                      source_path, base, local, remote.
	 *                                      base/local/remote are payload arrays/strings.
	 * @param string              $policy_name Policy identifier.
	 * @return array<string,mixed> Result with merged payload, hash, decisions, ambiguous list,
	 *                              and bookkeeping fields.
	 */
	public static function rebase( array $artifact, string $policy_name = self::POLICY_CONSERVATIVE ): array {
		$artifact_type = (string) ( $artifact['artifact_type'] ?? '' );
		$artifact_id   = (string) ( $artifact['artifact_id'] ?? '' );
		$artifact_key  = AgentBundleArtifactExtensions::artifact_key( $artifact_type, $artifact_id );
		$base          = $artifact['base']   ?? null;
		$local         = $artifact['local']  ?? null;
		$remote        = $artifact['remote'] ?? null;

		$policies = self::policies();
		if ( ! isset( $policies[ $policy_name ] ) ) {
			$policy_name = self::POLICY_CONSERVATIVE;
		}

		$callable = $policies[ $policy_name ];
		$result   = call_user_func(
			$callable,
			array(
				'artifact_key'  => $artifact_key,
				'artifact_type' => $artifact_type,
				'artifact_id'   => $artifact_id,
				'base'          => $base,
				'local'         => $local,
				'remote'        => $remote,
			)
		);

		if ( ! is_array( $result ) ) {
			$result = array();
		}

		$merged    = array_key_exists( 'merged', $result ) ? $result['merged'] : $local;
		$decisions = isset( $result['decisions'] ) && is_array( $result['decisions'] ) ? $result['decisions'] : array();
		$ambiguous = isset( $result['ambiguous'] ) && is_array( $result['ambiguous'] )
			? array_values( array_unique( array_map( 'strval', $result['ambiguous'] ) ) )
			: array();

		$merged_hash = null === $merged ? null : AgentBundleArtifactHasher::hash( $merged );
		$base_hash   = null === $base ? null : AgentBundleArtifactHasher::hash( $base );
		$local_hash  = null === $local ? null : AgentBundleArtifactHasher::hash( $local );
		$remote_hash = null === $remote ? null : AgentBundleArtifactHasher::hash( $remote );

		return array(
			'artifact_key'   => $artifact_key,
			'artifact_type'  => $artifact_type,
			'artifact_id'    => $artifact_id,
			'source_path'    => (string) ( $artifact['source_path'] ?? '' ),
			'policy'         => $policy_name,
			'merged'         => $merged,
			'merged_hash'    => $merged_hash,
			'base_hash'      => $base_hash,
			'local_hash'     => $local_hash,
			'remote_hash'    => $remote_hash,
			'decisions'      => $decisions,
			'ambiguous'      => $ambiguous,
			'requires_approval' => ! empty( $ambiguous ),
		);
	}

	/**
	 * Available policies keyed by name.
	 *
	 * @return array<string,callable>
	 */
	public static function policies(): array {
		$builtin = array(
			self::POLICY_CONSERVATIVE => array( self::class, 'policy_conservative' ),
			self::POLICY_BURN_IN_SAFE => array( self::class, 'policy_burn_in_safe' ),
		);

		/**
		 * Register additional bundle artifact rebase policies.
		 *
		 * @param array<string,callable> $policies Policy name => callable map.
		 */
		$policies = function_exists( 'apply_filters' )
			? apply_filters( 'datamachine_bundle_rebase_policies', $builtin )
			: $builtin;

		return is_array( $policies ) ? $policies : $builtin;
	}

	/**
	 * Conservative policy: keep local, flag every diverging field as ambiguous.
	 *
	 * Never silently merges. Operators must explicitly choose a policy that
	 * understands the artifact shape.
	 *
	 * @param array<string,mixed> $input Rebase input.
	 * @return array<string,mixed>
	 */
	public static function policy_conservative( array $input ): array {
		$local     = $input['local'] ?? null;
		$remote    = $input['remote'] ?? null;
		$paths     = self::diverging_paths( $local, $remote );
		$decisions = array();

		foreach ( $paths as $path ) {
			$decisions[ $path ] = array(
				'source' => 'ambiguous',
				'reason' => 'conservative_no_auto_merge',
			);
		}

		return array(
			'merged'    => $local,
			'decisions' => $decisions,
			'ambiguous' => $paths,
		);
	}

	/**
	 * Burn-in-safe policy for flow artifacts.
	 *
	 * Preserves local runtime/safety fields:
	 *   - flow_config.*.prompt_queue
	 *   - flow_config.*.config_patch_queue
	 *   - flow_config.*.queue_mode
	 *   - flow_config.*._queue_consume_revision
	 *   - flow_config.*.handler_config.max_items (and handler_configs.*.max_items)
	 *     when local diverged from base only on the throttle.
	 *   - scheduling_config (whole subtree, including max_items)
	 *
	 * Takes remote source-shape changes when remote diverged from base on
	 * provider-routing fields:
	 *   - flow_config.*.handler
	 *   - flow_config.*.handler_config.* and handler_configs.*.* except local-preserved keys
	 *     (server, provider, tool, params, query, owner, repo, channel, perPage, source-shape)
	 *
	 * Non-flow artifacts are forwarded to conservative policy.
	 *
	 * @param array<string,mixed> $input Rebase input.
	 * @return array<string,mixed>
	 */
	public static function policy_burn_in_safe( array $input ): array {
		$type = (string) $input['artifact_type'];
		if ( 'flow' !== $type ) {
			return self::policy_conservative( $input );
		}

		$base   = is_array( $input['base'] ?? null )   ? $input['base']   : array();
		$local  = is_array( $input['local'] ?? null )  ? $input['local']  : array();
		$remote = is_array( $input['remote'] ?? null ) ? $input['remote'] : array();

		$decisions = array();
		$ambiguous = array();
		$merged    = $remote; // Start from remote, then surgically restore local-owned fields.

		// Always preserve scheduling_config from local. Bundle authors should not
		// fight runtime cron throttles; if they need to force a schedule change,
		// it must be explicit (not in scope for v1).
		if ( array_key_exists( 'scheduling_config', $local ) ) {
			$merged['scheduling_config']      = $local['scheduling_config'];
			$decisions['scheduling_config']   = array(
				'source' => 'local',
				'reason' => 'burn_in_preserve_scheduling',
			);
		}

		// Walk flow_config step by step, merging per-step keys.
		$base_steps   = is_array( $base['flow_config']   ?? null ) ? $base['flow_config']   : array();
		$local_steps  = is_array( $local['flow_config']  ?? null ) ? $local['flow_config']  : array();
		$remote_steps = is_array( $remote['flow_config'] ?? null ) ? $remote['flow_config'] : array();
		$merged_steps = is_array( $merged['flow_config'] ?? null ) ? $merged['flow_config'] : array();

		$step_ids = array_unique(
			array_merge(
				array_keys( $local_steps ),
				array_keys( $remote_steps )
			)
		);

		foreach ( $step_ids as $step_id ) {
			$base_step   = is_array( $base_steps[ $step_id ]   ?? null ) ? $base_steps[ $step_id ]   : array();
			$local_step  = is_array( $local_steps[ $step_id ]  ?? null ) ? $local_steps[ $step_id ]  : array();
			$remote_step = is_array( $remote_steps[ $step_id ] ?? null ) ? $remote_steps[ $step_id ] : array();

			if ( empty( $remote_step ) && ! empty( $local_step ) ) {
				// Remote dropped this step; without explicit policy, keep local
				// and flag as ambiguous so operator confirms.
				$merged_steps[ $step_id ]                            = $local_step;
				$decisions[ "flow_config.{$step_id}" ]               = array(
					'source' => 'ambiguous',
					'reason' => 'remote_dropped_step',
				);
				$ambiguous[] = "flow_config.{$step_id}";
				continue;
			}

			$merged_step = $remote_step;

			// Preserve per-step runtime queue fields from local.
			foreach ( array( 'prompt_queue', 'config_patch_queue', 'queue_mode', '_queue_consume_revision' ) as $rt_field ) {
				if ( array_key_exists( $rt_field, $local_step ) ) {
					$merged_step[ $rt_field ]                                       = $local_step[ $rt_field ];
					$decisions[ "flow_config.{$step_id}.{$rt_field}" ]              = array(
						'source' => 'local',
						'reason' => 'burn_in_preserve_runtime_queue',
					);
				} elseif ( array_key_exists( $rt_field, $remote_step ) ) {
					unset( $merged_step[ $rt_field ] );
				}
			}

			// Merge handler_config keys (single-handler shape).
			$base_hc   = is_array( $base_step['handler_config']   ?? null ) ? $base_step['handler_config']   : array();
			$local_hc  = is_array( $local_step['handler_config']  ?? null ) ? $local_step['handler_config']  : array();
			$remote_hc = is_array( $remote_step['handler_config'] ?? null ) ? $remote_step['handler_config'] : array();

			if ( ! empty( $local_hc ) || ! empty( $remote_hc ) ) {
				$merged_hc                               = self::merge_handler_config(
					$base_hc,
					$local_hc,
					$remote_hc,
					"flow_config.{$step_id}.handler_config",
					$decisions,
					$ambiguous
				);
				$merged_step['handler_config']           = $merged_hc;
			}

			// Merge handler_configs (multi-handler shape: keyed by handler slug).
			$base_hcs   = is_array( $base_step['handler_configs']   ?? null ) ? $base_step['handler_configs']   : array();
			$local_hcs  = is_array( $local_step['handler_configs']  ?? null ) ? $local_step['handler_configs']  : array();
			$remote_hcs = is_array( $remote_step['handler_configs'] ?? null ) ? $remote_step['handler_configs'] : array();

			if ( ! empty( $local_hcs ) || ! empty( $remote_hcs ) ) {
				$merged_hcs = array();
				$slugs      = array_unique( array_merge( array_keys( $local_hcs ), array_keys( $remote_hcs ) ) );
				foreach ( $slugs as $slug ) {
					$lc                  = is_array( $local_hcs[ $slug ]  ?? null ) ? $local_hcs[ $slug ]  : array();
					$rc                  = is_array( $remote_hcs[ $slug ] ?? null ) ? $remote_hcs[ $slug ] : array();
					$bc                  = is_array( $base_hcs[ $slug ]   ?? null ) ? $base_hcs[ $slug ]   : array();
					$merged_hcs[ $slug ] = self::merge_handler_config(
						$bc,
						$lc,
						$rc,
						"flow_config.{$step_id}.handler_configs.{$slug}",
						$decisions,
						$ambiguous
					);
				}
				$merged_step['handler_configs'] = $merged_hcs;
			}

			$merged_steps[ $step_id ] = $merged_step;
		}

		$merged['flow_config'] = $merged_steps;

		// Top-level fields that aren't flow_config or scheduling_config.
		// Anything else uses last-write-wins from remote, but if local diverged
		// from base on the same key while remote also diverged differently, mark
		// it ambiguous so an operator confirms.
		$top_keys = array_unique(
			array_merge(
				array_keys( is_array( $local )  ? $local  : array() ),
				array_keys( is_array( $remote ) ? $remote : array() )
			)
		);
		foreach ( $top_keys as $key ) {
			if ( in_array( $key, array( 'flow_config', 'scheduling_config' ), true ) ) {
				continue;
			}
			$base_val   = $base[ $key ]   ?? null;
			$local_val  = $local[ $key ]  ?? null;
			$remote_val = $remote[ $key ] ?? null;

			if ( $local_val === $remote_val ) {
				continue;
			}

			$local_changed  = $local_val !== $base_val;
			$remote_changed = $remote_val !== $base_val;

			if ( $local_changed && $remote_changed ) {
				$merged[ $key ]      = $local_val;
				$decisions[ $key ]   = array(
					'source' => 'ambiguous',
					'reason' => 'both_diverged_from_base',
				);
				$ambiguous[]         = $key;
				continue;
			}

			if ( $local_changed ) {
				$merged[ $key ]    = $local_val;
				$decisions[ $key ] = array(
					'source' => 'local',
					'reason' => 'remote_unchanged_from_base',
				);
				continue;
			}

			$merged[ $key ]    = $remote_val;
			$decisions[ $key ] = array(
				'source' => 'remote',
				'reason' => 'local_unchanged_from_base',
			);
		}

		return array(
			'merged'    => $merged,
			'decisions' => $decisions,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * 3-way merge a single handler_config map.
	 *
	 * Local-preserved keys (when local diverged from base): max_items.
	 * Remote-preserved keys (source-shape) win when remote diverged from base.
	 * If both diverged from base on the same key, mark ambiguous.
	 *
	 * @param array<string,mixed> $base   Base handler_config.
	 * @param array<string,mixed> $local  Local handler_config.
	 * @param array<string,mixed> $remote Remote handler_config.
	 * @param string              $path_prefix Decision path prefix.
	 * @param array<string,array<string,string>> $decisions In/out decisions map.
	 * @param array<int,string>   $ambiguous In/out ambiguous list.
	 * @return array<string,mixed>
	 */
	private static function merge_handler_config(
		array $base,
		array $local,
		array $remote,
		string $path_prefix,
		array &$decisions,
		array &$ambiguous
	): array {
		$local_preserve_keys = array( 'max_items' );

		$keys   = array_unique( array_merge( array_keys( $base ), array_keys( $local ), array_keys( $remote ) ) );
		$merged = array();

		foreach ( $keys as $key ) {
			$base_val   = $base[ $key ]   ?? null;
			$local_val  = $local[ $key ]  ?? null;
			$remote_val = $remote[ $key ] ?? null;
			$path       = $path_prefix . '.' . (string) $key;

			$local_changed  = array_key_exists( $key, $local )  && $local_val  !== $base_val;
			$remote_changed = array_key_exists( $key, $remote ) && $remote_val !== $base_val;

			// Identical local and remote → take it, no decision noise.
			if ( $local_val === $remote_val ) {
				if ( array_key_exists( $key, $local ) || array_key_exists( $key, $remote ) ) {
					$merged[ $key ] = $local_val;
				}
				continue;
			}

			// Local-preserved throttle key: keep local if it diverged from base.
			if ( in_array( (string) $key, $local_preserve_keys, true ) ) {
				if ( $local_changed && ! $remote_changed ) {
					$merged[ $key ]    = $local_val;
					$decisions[ $path ] = array(
						'source' => 'local',
						'reason' => 'burn_in_preserve_throttle',
					);
					continue;
				}
				if ( $local_changed && $remote_changed ) {
					// Both moved this throttle. Default to local (safer) but flag ambiguous.
					$merged[ $key ]    = $local_val;
					$decisions[ $path ] = array(
						'source' => 'ambiguous',
						'reason' => 'throttle_diverged_in_local_and_remote',
					);
					$ambiguous[]       = $path;
					continue;
				}
				// Local unchanged from base; safe to take remote when remote moved
				// (e.g. bundle deliberately raised throttle and local never overrode).
				if ( array_key_exists( $key, $remote ) ) {
					$merged[ $key ]    = $remote_val;
					$decisions[ $path ] = array(
						'source' => 'remote',
						'reason' => 'local_unchanged_from_base',
					);
				} elseif ( array_key_exists( $key, $local ) ) {
					$merged[ $key ] = $local_val;
				}
				continue;
			}

			// Source-shape keys default to remote when remote diverged.
			if ( $remote_changed && ! $local_changed ) {
				$merged[ $key ]    = $remote_val;
				$decisions[ $path ] = array(
					'source' => 'remote',
					'reason' => 'remote_source_shape_change',
				);
				continue;
			}

			if ( $local_changed && ! $remote_changed ) {
				$merged[ $key ]    = $local_val;
				$decisions[ $path ] = array(
					'source' => 'local',
					'reason' => 'remote_unchanged_from_base',
				);
				continue;
			}

			if ( $local_changed && $remote_changed ) {
				// Both moved a non-throttle key. Default merged value to local
				// (don't silently clobber) and flag for approval.
				$merged[ $key ]    = $local_val;
				$decisions[ $path ] = array(
					'source' => 'ambiguous',
					'reason' => 'both_diverged_from_base',
				);
				$ambiguous[]       = $path;
				continue;
			}

			// Neither changed but values differ → take whichever is set.
			if ( array_key_exists( $key, $remote ) ) {
				$merged[ $key ] = $remote_val;
			} elseif ( array_key_exists( $key, $local ) ) {
				$merged[ $key ] = $local_val;
			}
		}

		return $merged;
	}

	/**
	 * Compute dot-notation paths where local and remote payloads diverge.
	 *
	 * Used by the conservative policy to surface every disagreement without
	 * attempting a merge.
	 *
	 * @param mixed  $local Local payload.
	 * @param mixed  $remote Remote payload.
	 * @param string $prefix Dot-notation prefix.
	 * @return array<int,string>
	 */
	private static function diverging_paths( mixed $local, mixed $remote, string $prefix = '' ): array {
		if ( $local === $remote ) {
			return array();
		}
		if ( ! is_array( $local ) || ! is_array( $remote ) ) {
			return array( '' === $prefix ? '$' : $prefix );
		}

		$paths = array();
		$keys  = array_unique( array_merge( array_keys( $local ), array_keys( $remote ) ) );
		foreach ( $keys as $key ) {
			$child_prefix = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
			$lv           = $local[ $key ]  ?? null;
			$rv           = $remote[ $key ] ?? null;
			if ( $lv === $rv ) {
				continue;
			}
			if ( is_array( $lv ) && is_array( $rv ) ) {
				foreach ( self::diverging_paths( $lv, $rv, $child_prefix ) as $child ) {
					$paths[] = $child;
				}
				continue;
			}
			$paths[] = $child_prefix;
		}

		return $paths;
	}
}
