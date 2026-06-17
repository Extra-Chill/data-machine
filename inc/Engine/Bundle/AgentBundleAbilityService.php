<?php
/**
 * Ability-backed agent bundle lifecycle service.
 *
 * @package DataMachine\Engine\Bundle
 */

namespace DataMachine\Engine\Bundle;

use DataMachine\Core\Agents\AgentBundler;
use DataMachine\Core\Database\Agents\Agents;
use DataMachine\Core\Database\Flows\Flows;
use DataMachine\Core\Database\Pipelines\Pipelines;
use DataMachine\Engine\AI\Actions\ResolvePendingActionAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical implementation for bundle lifecycle abilities.
 */
final class AgentBundleAbilityService {

	private Agents $agents;
	private Pipelines $pipelines;
	private Flows $flows;
	private AgentBundler $bundler;
	private AgentBundleLifecycleProjection $projection;

	public function __construct( ?Agents $agents = null, ?Pipelines $pipelines = null, ?Flows $flows = null, ?AgentBundler $bundler = null, ?AgentBundleLifecycleProjection $projection = null ) {
		$this->agents     = $agents ?? new Agents();
		$this->pipelines  = $pipelines ?? new Pipelines();
		$this->flows      = $flows ?? new Flows();
		$this->bundler    = $bundler ?? new AgentBundler();
		$this->projection = $projection ?? new AgentBundleLifecycleProjection( $this->pipelines, $this->flows );
	}

	/** @return array<string,mixed> */
	public function list_installed(): array {
		$items = array();
		foreach ( $this->agents->get_all() as $agent ) {
			$bundle = $agent['agent_config']['datamachine_bundle'] ?? array();
			if ( empty( $bundle['bundle_slug'] ) ) {
				continue;
			}

			$items[] = array(
				'agent_id'         => (int) $agent['agent_id'],
				'agent_slug'       => (string) $agent['agent_slug'],
				'template_slug'    => (string) ( $bundle['template_slug'] ?? $bundle['bundle_slug'] ),
				'template_version' => (string) ( $bundle['template_version'] ?? $bundle['bundle_version'] ?? '' ),
				'bundle_slug'      => (string) $bundle['bundle_slug'],
				'bundle_version'   => (string) ( $bundle['bundle_version'] ?? '' ),
				'source_ref'       => (string) ( $bundle['source_ref'] ?? '' ),
				'source_revision'  => (string) ( $bundle['source_revision'] ?? '' ),
				'artifacts'        => count( AgentBundleArtifactState::installed_for_agent( $agent ) ),
			);
		}

		return array(
			'success' => true,
			'bundles' => $items,
		);
	}

	/** @return array<string,mixed> */
	public function status( string $slug ): array {
		$agent = $this->resolve_installed_agent( $slug );
		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => 'Installed bundle not found.',
			);
		}

		return array_merge( array( 'success' => true ), $this->installed_status( $agent ) );
	}

	/** @return array<string,mixed> */
	public function plan( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle            = $loaded['bundle'];
		$slug              = (string) ( $input['slug'] ?? '' );
		$reconcile_runtime = ! empty( $input['reconcile_runtime'] );
		$plan              = $this->add_runtime_drift_to_plan(
			$this->plan_for_bundle( $bundle, $slug )->to_array(),
			$bundle,
			$slug,
			$reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing'
		);

		return array(
			'success' => true,
			'plan'    => $plan,
			'bundle'  => $this->bundle_summary( $bundle, $slug ),
		);
	}

	/** @return array<string,mixed> */
	public function inspect( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle        = $loaded['bundle'];
		$package       = AgentPackageProjection::from_array_bundle( $bundle );
		$compatibility = self::capability_report( $package )->to_array();

		return array(
			'success'       => true,
			'bundle'        => $this->bundle_summary( $bundle, (string) ( $input['slug'] ?? '' ) ),
			'package'       => $package->to_array(),
			'compatibility' => $compatibility,
		);
	}

	/** @return array<string,mixed> */
	public function validate( array $input ): array {
		$inspect = $this->inspect( $input );
		if ( empty( $inspect['success'] ) ) {
			return array_merge(
				$inspect,
				array(
					'valid'  => false,
					'status' => 'invalid',
				)
			);
		}

		$compatibility = is_array( $inspect['compatibility'] ?? null ) ? $inspect['compatibility'] : array();
		$compatible    = ! empty( $compatibility['compatible'] );

		return array(
			'success'       => true,
			'valid'         => $compatible,
			'status'        => $compatible ? 'valid' : 'unsupported',
			'bundle'        => $inspect['bundle'],
			'compatibility' => $compatibility,
		);
	}

	/** @return array<string,mixed> */
	public function rebase( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle      = $loaded['bundle'];
		$slug        = (string) ( $input['slug'] ?? '' );
		$policy_name = (string) ( $input['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );
		$only        = isset( $input['artifact'] ) ? (string) $input['artifact'] : '';
		$plan        = $this->plan_for_bundle( $bundle, $slug );
		$rebased     = $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name );

		if ( '' !== $only ) {
			$rebased = array_values(
				array_filter(
					$rebased,
					static fn( array $entry ) => ( $entry['artifact_key'] ?? '' ) === $only
				)
			);
		}

		return array(
			'success' => true,
			'policy'  => $policy_name,
			'count'   => count( $rebased ),
			'rebased' => $rebased,
		);
	}

	/** @return array<string,mixed> */
	public function upgrade( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle            = $loaded['bundle'];
		$slug              = (string) ( $input['slug'] ?? '' );
		$dry_run           = ! empty( $input['dry_run'] );
		$reconcile_runtime = ! empty( $input['reconcile_runtime'] );
		$rebase_local      = ! empty( $input['rebase_local'] );
		$policy_name       = (string) ( $input['policy'] ?? AgentBundleArtifactRebase::POLICY_CONSERVATIVE );
		$plan              = $this->plan_for_bundle( $bundle, $slug );

		if ( $dry_run ) {
			$plan_array = $this->add_runtime_drift_to_plan(
				$plan->to_array(),
				$bundle,
				$slug,
				$reconcile_runtime ? 'replace_bundle_seed' : 'preserve_existing'
			);
			if ( $rebase_local ) {
				$plan_array['rebase'] = $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name );
			}

			return array(
				'success' => true,
				'plan'    => $plan_array,
				'bundle'  => $this->bundle_summary( $bundle, $slug ),
			);
		}

		$result = $this->bundler->import(
			$bundle,
			'' !== $slug ? $slug : null,
			isset( $input['owner_id'] ) ? (int) $input['owner_id'] : 0,
			false,
			array(
				'reconcile_runtime' => $reconcile_runtime,
				'is_upgrade'        => true,
			)
		);
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$response = array(
			'success' => true,
			'import'  => $result,
			'plan'    => $plan->to_array(),
		);

		if ( $plan->has_pending_approval() ) {
			$agent                      = $this->resolve_bundle_agent( $bundle, $slug );
			$rebased_artifacts          = $rebase_local
				? $this->rebase_locally_modified( $plan, $bundle, $slug, $policy_name )
				: array();
			$pending                    = AgentBundleUpgradePendingAction::stage(
				$plan,
				array(
					'bundle'             => $this->bundle_summary( $bundle, $slug ),
					'agent'              => $agent ? $agent : array(),
					'target_artifacts'   => $this->projection->target_artifacts( $bundle, $agent ? $agent : null ),
					'approved_artifacts' => $this->approved_rebased_artifact_keys( $rebased_artifacts ),
					'rebased_artifacts'  => $rebased_artifacts,
					'summary'            => 'Review locally modified bundle artifacts before applying.',
					'agent_id'           => $agent ? (int) $agent['agent_id'] : 0,
					'user_id'            => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				)
			);
			$response['pending_action'] = $pending;
			if ( ! empty( $rebased_artifacts ) ) {
				$response['rebase'] = $rebased_artifacts;
			}
		}

		return $response;
	}

	/**
	 * Bind an already-live agent to a bundle without re-importing its data.
	 *
	 * Live-origin agents (built in the UI/CLI, then exported into a bundle)
	 * carry no package provenance: pipeline/flow rows have portable_slug NULL,
	 * the bundle_artifacts ledger is empty, and agent_config has no
	 * datamachine_bundle header. A subsequent `upgrade` therefore matches zero
	 * artifacts and would INSERT a full duplicate set.
	 *
	 * adopt() is the one-time, idempotent bind that:
	 * - matches each bundle pipeline/flow to the live row by normalized
	 *   portable_slug-or-name (refusing to guess on ambiguous name collisions),
	 * - backfills portable_slug on each matched live row,
	 * - writes the datamachine_bundle header onto agent_config,
	 * - populates the ledger with installed_hash = current_hash = hash of the
	 *   CURRENT live state, so the next upgrade diffs cleanly.
	 *
	 * @param array<string,mixed> $input { source, slug, dry_run, token, token_env }.
	 * @return array<string,mixed>
	 */
	public function adopt( array $input ): array {
		$loaded = $this->load_bundle_from_input( $input );
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$bundle  = $loaded['bundle'];
		$slug    = (string) ( $input['slug'] ?? '' );
		$dry_run = ! empty( $input['dry_run'] );

		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					'No live agent found for slug "%s". adopt binds an existing live agent to a bundle; use import to create a new one.',
					'' !== $slug ? sanitize_title( $slug ) : sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) )
				),
			);
		}

		$agent_id  = (int) $agent['agent_id'];
		$summary   = $this->bundle_summary( $bundle, $slug );
		$matched   = array();
		$unmatched = array();
		$ambiguous = array();

		// ---- Pipelines: match bundle entries to live rows by normalized slug.
		$pipeline_rows  = $this->pipelines->get_all_pipelines( null, $agent_id );
		$pipeline_index = AgentBundleSlugMatcher::index_existing( $pipeline_rows, 'pipeline_name', 'pipeline' );

		// Map original bundle pipeline id -> live pipeline id so flows can be
		// scoped to the right live pipeline. The in-memory bundle adopt loads
		// (AgentBundleArrayAdapter::to_array_bundle) synthesizes `original_id` on
		// every pipeline and `original_pipeline_id` on every flow, so this keys
		// the scope correctly for live-origin bundles.
		$pipeline_id_map = array();

		foreach ( $bundle['pipelines'] ?? array() as $bundle_pipeline ) {
			if ( ! is_array( $bundle_pipeline ) ) {
				continue;
			}
			$slug_key = AgentBundleSlugMatcher::bundle_slug( $bundle_pipeline, 'pipeline_name', 'pipeline' );

			if ( isset( $pipeline_index['ambiguous'][ $slug_key ] ) ) {
				$ambiguous[] = array(
					'artifact_type' => 'pipeline',
					'artifact_id'   => $slug_key,
					'reason'        => sprintf( '%d live pipelines resolve to slug "%s".', count( $pipeline_index['ambiguous'][ $slug_key ] ), $slug_key ),
				);
				continue;
			}

			$live = $pipeline_index['matched'][ $slug_key ] ?? null;
			if ( null === $live ) {
				$unmatched[] = array(
					'artifact_type' => 'pipeline',
					'artifact_id'   => $slug_key,
				);
				continue;
			}

			$live_id = (int) ( $live['pipeline_id'] ?? 0 );

			$pipeline_id_map[ (int) ( $bundle_pipeline['original_id'] ?? 0 ) ] = $live_id;

			$matched[] = array(
				'artifact_type'  => 'pipeline',
				'artifact_id'    => $slug_key,
				'record_id'      => $live_id,
				'needs_backfill' => '' === trim( (string) ( $live['portable_slug'] ?? '' ) ),
				'payload'        => AgentBundleArtifactPayloads::pipeline_payload( $live, $slug_key ),
			);
		}

		// ---- Flows: scope each to its matched live pipeline, match by slug.
		$flow_rows         = $this->flows->get_all_flows( null, $agent_id );
		$flows_by_pipeline = array();
		foreach ( $flow_rows as $flow_row ) {
			if ( ! is_array( $flow_row ) ) {
				continue;
			}
			$flows_by_pipeline[ (int) ( $flow_row['pipeline_id'] ?? 0 ) ][] = $flow_row;
		}

		foreach ( $bundle['flows'] ?? array() as $bundle_flow ) {
			if ( ! is_array( $bundle_flow ) ) {
				continue;
			}
			$slug_key        = AgentBundleSlugMatcher::bundle_slug( $bundle_flow, 'flow_name', 'flow' );
			$old_pipeline_id = (int) ( $bundle_flow['original_pipeline_id'] ?? 0 );
			$live_pipeline   = (int) ( $pipeline_id_map[ $old_pipeline_id ] ?? 0 );

			if ( $live_pipeline <= 0 ) {
				$unmatched[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $slug_key,
					'reason'        => 'parent pipeline unmatched',
				);
				continue;
			}

			$scoped_rows = $flows_by_pipeline[ $live_pipeline ] ?? array();
			$flow_index  = AgentBundleSlugMatcher::index_existing( $scoped_rows, 'flow_name', 'flow' );

			if ( isset( $flow_index['ambiguous'][ $slug_key ] ) ) {
				$ambiguous[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $slug_key,
					'reason'        => sprintf( '%d live flows on pipeline %d resolve to slug "%s".', count( $flow_index['ambiguous'][ $slug_key ] ), $live_pipeline, $slug_key ),
				);
				continue;
			}

			$live = $flow_index['matched'][ $slug_key ] ?? null;

			// Pipeline-scoped handler fallback (primary): the unique-slug pass
			// above misses for live-origin source flows because the bundle's slug
			// was deduped at export ("dice-fm-101") and its `name` was renamed to
			// "<Source> — <City>" (event-bundles#5), while the live row still
			// carries the bare source label ("Dice.fm"). Neither editable label
			// can meet the live row. The flow's import HANDLER, however, is
			// rename-proof: within this already-matched parent pipeline a given
			// source ("dice_fm", "ticketmaster") appears once — per-venue scrapers
			// share `universal_web_scraper` but already resolved on the unique
			// slug pass before reaching here. So re-key BOTH sides on the
			// normalized handler identity (bundle `steps[]` vs live `flow_config`).
			// A unique match is unambiguous; a genuine in-pipeline handler
			// collision stays ambiguous and is never guessed.
			if ( null === $live ) {
				$handler_key = AgentBundleSlugMatcher::bundle_handler_key( $bundle_flow, 'flow' );
				if ( '' !== $handler_key ) {
					$handler_index = AgentBundleSlugMatcher::index_existing_by_handler( $scoped_rows, 'flow' );

					if ( isset( $handler_index['ambiguous'][ $handler_key ] ) ) {
						$ambiguous[] = array(
							'artifact_type' => 'flow',
							'artifact_id'   => $slug_key,
							'reason'        => sprintf( '%d live flows on pipeline %d resolve to handler "%s".', count( $handler_index['ambiguous'][ $handler_key ] ), $live_pipeline, $handler_key ),
						);
						continue;
					}

					$live = $handler_index['matched'][ $handler_key ] ?? null;
				}
			}

			// Pipeline-scoped name fallback (last resort): when a flow carries no
			// usable handler signal, fall back to the normalized source label
			// within this single pipeline. Preserved for flows whose only stable
			// cross-side identity is the (unrenamed) display name; the unique
			// bundle slug is still what gets backfilled. A genuine in-pipeline
			// name collision stays ambiguous.
			if ( null === $live ) {
				$name_key   = AgentBundleSlugMatcher::bundle_name_key( $bundle_flow, 'flow_name', 'flow' );
				$name_index = AgentBundleSlugMatcher::index_existing_by_name( $scoped_rows, 'flow_name', 'flow' );

				if ( isset( $name_index['ambiguous'][ $name_key ] ) ) {
					$ambiguous[] = array(
						'artifact_type' => 'flow',
						'artifact_id'   => $slug_key,
						'reason'        => sprintf( '%d live flows on pipeline %d resolve to source label "%s".', count( $name_index['ambiguous'][ $name_key ] ), $live_pipeline, $name_key ),
					);
					continue;
				}

				$live = $name_index['matched'][ $name_key ] ?? null;
			}

			if ( null === $live ) {
				$unmatched[] = array(
					'artifact_type' => 'flow',
					'artifact_id'   => $slug_key,
				);
				continue;
			}

			$matched[] = array(
				'artifact_type'  => 'flow',
				'artifact_id'    => $slug_key,
				'record_id'      => (int) ( $live['flow_id'] ?? 0 ),
				'needs_backfill' => '' === trim( (string) ( $live['portable_slug'] ?? '' ) ),
				'payload'        => AgentBundleArtifactPayloads::flow_payload( $live, $slug_key ),
			);
		}

		$result = array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'agent_id'   => $agent_id,
			'agent_slug' => (string) $agent['agent_slug'],
			'bundle'     => $summary,
			'counts'     => array(
				'matched'   => count( $matched ),
				'unmatched' => count( $unmatched ),
				'ambiguous' => count( $ambiguous ),
			),
			'matched'    => $matched,
			'unmatched'  => $unmatched,
			'ambiguous'  => $ambiguous,
		);

		if ( ! empty( $ambiguous ) ) {
			$result['success'] = false;
			$result['error']   = sprintf(
				'Refusing to adopt: %d artifact slug(s) are ambiguous (duplicate names collide). Rename or disambiguate the live rows, then retry.',
				count( $ambiguous )
			);
			return $result;
		}

		if ( $dry_run ) {
			return $result;
		}

		// ---- Write provenance: backfill slugs, ledger, bundle header. -------
		$ledger_rows = array();
		foreach ( $matched as $entry ) {
			$type      = (string) $entry['artifact_type'];
			$slug_key  = (string) $entry['artifact_id'];
			$record_id = (int) $entry['record_id'];
			$hash      = AgentBundleArtifactHasher::hash( $entry['payload'] );

			if ( ! empty( $entry['needs_backfill'] ) && $record_id > 0 ) {
				if ( 'pipeline' === $type ) {
					$this->pipelines->update_pipeline( $record_id, array( 'portable_slug' => $slug_key ) );
				} else {
					$this->flows->update_flow( $record_id, array( 'portable_slug' => $slug_key ) );
				}
			}

			$ledger_rows[] = array(
				'bundle_slug'       => (string) $summary['bundle_slug'],
				'bundle_version'    => (string) $summary['bundle_version'],
				'artifact_type'     => $type,
				'artifact_id'       => $slug_key,
				'source_path'       => ( 'pipeline' === $type ? 'pipelines/' : 'flows/' ) . $slug_key . '.json',
				'installed_hash'    => $hash,
				'current_hash'      => $hash,
				'installed_payload' => $entry['payload'],
				'installed_at'      => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			);
		}

		$persisted = AgentBundleArtifactState::persist_for_agent_result( $agent_id, $ledger_rows );
		if ( is_wp_error( $persisted ) ) {
			return array(
				'success' => false,
				'error'   => $persisted->get_error_message(),
			);
		}

		$config = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
		if ( ! isset( $config['datamachine_bundle'] ) || ! is_array( $config['datamachine_bundle'] ) ) {
			$config['datamachine_bundle'] = array();
		}
		$header = is_array( $bundle['agent']['agent_config']['datamachine_bundle'] ?? null ) ? $bundle['agent']['agent_config']['datamachine_bundle'] : array();

		$config['datamachine_bundle']['bundle_slug']      = (string) $summary['bundle_slug'];
		$config['datamachine_bundle']['bundle_version']   = (string) $summary['bundle_version'];
		$config['datamachine_bundle']['template_slug']    = (string) ( $header['template_slug'] ?? $summary['bundle_slug'] );
		$config['datamachine_bundle']['template_version'] = (string) ( $header['template_version'] ?? $summary['bundle_version'] );
		$config['datamachine_bundle']['source_ref']       = (string) ( $bundle['source_ref'] ?? $header['source_ref'] ?? '' );
		$config['datamachine_bundle']['source_revision']  = (string) ( $bundle['source_revision'] ?? $header['source_revision'] ?? '' );
		$config['datamachine_bundle']['adopted']          = true;

		if ( ! $this->agents->update_agent( $agent_id, array( 'agent_config' => $config ) ) ) {
			return array(
				'success' => false,
				'error'   => 'Ledger written, but updating the agent_config bundle header failed.',
			);
		}

		return $result;
	}

	/** @return array<string,mixed> */
	public function apply_pending_action( string $action_id ): array {
		$action_id = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $action_id ) : trim( $action_id );
		if ( '' === $action_id ) {
			return array(
				'success' => false,
				'error'   => 'Pending action ID is required.',
			);
		}

		return ResolvePendingActionAbility::execute(
			array(
				'action_id' => $action_id,
				'decision'  => 'accepted',
			)
		);
	}

	/** @return array<string,mixed> */
	private function load_bundle_from_input( array $input ): array {
		$source = trim( (string) ( $input['source'] ?? '' ) );
		if ( '' === $source ) {
			return array(
				'success' => false,
				'error'   => 'Bundle source is required.',
			);
		}

		$context  = BundleSourceAuth::build_resolve_context(
			isset( $input['token'] ) ? (string) $input['token'] : null,
			isset( $input['token_env'] ) ? (string) $input['token_env'] : null
		);
		$resolved = BundleSource::resolve( $source, $context );
		if ( is_wp_error( $resolved ) ) {
			return array(
				'success' => false,
				'error'   => $resolved->get_error_message(),
			);
		}

		$revision = BundleSource::is_remote( $source ) ? BundleSource::last_resolved_revision() : null;
		$bundle   = null;
		try {
			if ( is_dir( $resolved ) ) {
				$bundle = $this->bundler->from_directory( $resolved );
			} elseif ( preg_match( '/\.zip$/i', $resolved ) ) {
				$bundle = $this->bundler->from_zip( $resolved );
			} elseif ( preg_match( '/\.json$/i', $resolved ) ) {
				$bundle = $this->bundler->from_json( (string) file_get_contents( $resolved ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} catch ( BundleValidationException $e ) {
			BundleSource::cleanup( $resolved, $source );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		BundleSource::cleanup( $resolved, $source );
		if ( ! is_array( $bundle ) ) {
			return array(
				'success' => false,
				'error'   => 'Failed to parse bundle. Use .zip, .json, or a bundle directory.',
			);
		}

		if ( BundleSource::is_remote( $source ) && empty( $bundle['source_ref'] ) ) {
			$bundle['source_ref'] = $source;
		}
		if ( null !== $revision && empty( $bundle['source_revision'] ) ) {
			$bundle['source_revision'] = $revision;
		}

		return array(
			'success' => true,
			'bundle'  => $bundle,
		);
	}

	private function plan_for_bundle( array $bundle, string $slug = '' ): AgentBundleUpgradePlan {
		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return AgentBundleUpgradePlanner::plan( array(), array(), $this->projection->target_artifacts( $bundle ), $this->bundle_summary( $bundle, $slug ) );
		}

		$installed = AgentBundleArtifactState::installed_for_agent( $agent );

		return AgentBundleUpgradePlanner::plan(
			$installed,
			$this->projection->current_artifacts( $agent, $installed ),
			$this->projection->target_artifacts( $bundle, $agent ),
			$this->bundle_summary( $bundle, $slug )
		);
	}

	public static function capability_report( \WP_Agent_Package $package ): \WP_Agent_Package_Capability_Report {
		if ( 0 === did_action( 'wp_agent_package_artifacts_init' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Canonical wp-agent package artifact registry hook.
			do_action( 'wp_agent_package_artifacts_init' );
		}

		return \WP_Agent_Package_Capability_Checker::check( $package, self::host_capabilities() );
	}

	/** @return array<int,string> */
	public static function host_capabilities(): array {
		$capabilities = array(
			'datamachine',
			'datamachine/agent',
			'datamachine/agent-bundle',
			'datamachine/bundle-schema-v1',
			'datamachine/pipeline',
			'datamachine/flow',
			'datamachine/prompt',
			'datamachine/rubric',
			'datamachine/tool-policy',
			'datamachine/auth-ref',
			'datamachine/queue-seed',
		);

		/**
		 * Extend host capability strings used for read-only bundle compatibility checks.
		 *
		 * @param array<int,string> $capabilities Data Machine host capabilities.
		 */
		$capabilities = function_exists( 'apply_filters' ) ? apply_filters( 'datamachine_agent_bundle_host_capabilities', $capabilities ) : $capabilities;
		if ( ! is_array( $capabilities ) ) {
			$capabilities = array();
		}

		$normalized = array();
		foreach ( $capabilities as $capability ) {
			$capability = trim( strtolower( (string) $capability ) );
			if ( '' !== $capability ) {
				$normalized[] = $capability;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	private function add_runtime_drift_to_plan( array $plan, array $bundle, string $slug = '', string $decision = 'preserve_existing' ): array {
		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return $plan;
		}

		$drifts = $this->runtime_drifts_for_bundle( $bundle, $agent, $decision );
		if ( empty( $drifts ) ) {
			return $plan;
		}

		$plan['runtime_drift'] = $drifts;
		foreach ( $drifts as $drift ) {
			$plan['warnings'][] = $drift;
		}
		$plan['counts']['warnings'] = count( $plan['warnings'] ?? array() );

		return $plan;
	}

	/** @return array<int,array<string,mixed>> */
	private function runtime_drifts_for_bundle( array $bundle, array $agent, string $decision ): array {
		$agent_id        = (int) ( $agent['agent_id'] ?? 0 );
		$pipeline_id_map = array();
		$drifts          = array();

		// Key existing pipelines under the SAME normalized slug the bundle side
		// uses, falling back to the normalized pipeline_name when portable_slug
		// is empty. Without this fallback, live-origin agents (portable_slug
		// NULL on every row) never match and every artifact is misclassified.
		$existing_pipelines_by_slug = AgentBundleSlugMatcher::index_existing(
			$this->pipelines->get_all_pipelines( null, $agent_id ),
			'pipeline_name',
			'pipeline'
		)['matched'];

		// Index existing flows per pipeline once, with the same name fallback,
		// so flow rows with NULL portable_slug still resolve.
		$existing_flows_by_pipeline = array();
		foreach ( $this->flows->get_all_flows( null, $agent_id ) as $existing_flow_row ) {
			if ( ! is_array( $existing_flow_row ) ) {
				continue;
			}
			$existing_flows_by_pipeline[ (int) ( $existing_flow_row['pipeline_id'] ?? 0 ) ][] = $existing_flow_row;
		}

		foreach ( $bundle['pipelines'] ?? array() as $pipeline ) {
			if ( ! is_array( $pipeline ) ) {
				continue;
			}
			$slug = AgentBundleSlugMatcher::bundle_slug( $pipeline, 'pipeline_name', 'pipeline' );
			if ( isset( $existing_pipelines_by_slug[ $slug ] ) ) {
				$pipeline_id_map[ (int) ( $pipeline['original_id'] ?? 0 ) ] = (int) ( $existing_pipelines_by_slug[ $slug ]['pipeline_id'] ?? 0 );
			}
		}

		foreach ( $bundle['flows'] ?? array() as $flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$old_pipeline_id = (int) ( $flow['original_pipeline_id'] ?? 0 );
			$new_pipeline_id = (int) ( $pipeline_id_map[ $old_pipeline_id ] ?? 0 );
			if ( $new_pipeline_id <= 0 ) {
				continue;
			}
			$flow_slug     = AgentBundleSlugMatcher::bundle_slug( $flow, 'flow_name', 'flow' );
			$flow_index    = AgentBundleSlugMatcher::index_existing( $existing_flows_by_pipeline[ $new_pipeline_id ] ?? array(), 'flow_name', 'flow' );
			$existing_flow = $flow_index['matched'][ $flow_slug ] ?? null;
			if ( null === $existing_flow ) {
				continue;
			}
			$target_flow = array_merge(
				$flow,
				array(
					'flow_config' => BundleStepIdRemapper::remap_flow_step_ids( is_array( $flow['flow_config'] ?? null ) ? $flow['flow_config'] : array(), $old_pipeline_id, $new_pipeline_id, (int) $existing_flow['flow_id'] ),
				)
			);
			$preview     = AgentBundleRuntimeDrift::preview( $flow_slug, $existing_flow, $target_flow, $decision );
			if ( null !== $preview ) {
				$drifts[] = $preview;
			}
		}

		return $drifts;
	}

	private function rebase_locally_modified( AgentBundleUpgradePlan $plan, array $bundle, string $slug, string $policy_name ): array {
		$needs_approval = $plan->bucket( 'needs_approval' );
		if ( empty( $needs_approval ) ) {
			return array();
		}

		$agent = $this->resolve_bundle_agent( $bundle, $slug );
		if ( ! $agent ) {
			return array();
		}

		$installed       = AgentBundleArtifactState::installed_for_agent( $agent );
		$installed_index = array();
		foreach ( $installed as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                     = AgentBundleArtifactExtensions::artifact_key( (string) ( $row['artifact_type'] ?? '' ), (string) ( $row['artifact_id'] ?? '' ) );
			$installed_index[ $key ] = $row;
		}

		$current_index = array();
		foreach ( $this->projection->current_artifacts( $agent, $installed ) as $artifact ) {
			$key                   = AgentBundleArtifactExtensions::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
			$current_index[ $key ] = $artifact;
		}

		$target_index = array();
		foreach ( $this->projection->target_artifacts( $bundle, $agent ) as $artifact ) {
			$key                  = AgentBundleArtifactExtensions::artifact_key( (string) $artifact['artifact_type'], (string) $artifact['artifact_id'] );
			$target_index[ $key ] = $artifact;
		}

		$results = array();
		foreach ( $needs_approval as $entry ) {
			$key    = (string) ( $entry['artifact_key'] ?? '' );
			$target = $target_index[ $key ] ?? null;
			$local  = $current_index[ $key ] ?? null;
			if ( ! is_array( $target ) || ! is_array( $local ) ) {
				continue;
			}

			$base_payload  = null;
			$installed_row = $installed_index[ $key ] ?? null;
			if ( is_array( $installed_row ) ) {
				if ( array_key_exists( 'installed_payload', $installed_row ) ) {
					$base_payload = $installed_row['installed_payload'];
				} elseif ( array_key_exists( 'payload', $installed_row ) ) {
					$base_payload = $installed_row['payload'];
				}
			}

			$results[] = AgentBundleArtifactRebase::rebase(
				array(
					'artifact_type' => (string) $target['artifact_type'],
					'artifact_id'   => (string) $target['artifact_id'],
					'source_path'   => (string) ( $target['source_path'] ?? '' ),
					'base'          => $base_payload,
					'local'         => $local['payload'] ?? null,
					'remote'        => $target['payload'] ?? null,
				),
				$policy_name
			);
		}

		return $results;
	}

	private function approved_rebased_artifact_keys( array $rebased_artifacts ): array {
		$approved = array();
		foreach ( $rebased_artifacts as $artifact ) {
			if ( ! is_array( $artifact ) || ! empty( $artifact['requires_approval'] ) ) {
				continue;
			}
			$key = (string) ( $artifact['artifact_key'] ?? '' );
			if ( '' === $key ) {
				$key = AgentBundleArtifactExtensions::artifact_key( (string) ( $artifact['artifact_type'] ?? '' ), (string) ( $artifact['artifact_id'] ?? '' ) );
			}
			if ( '' !== $key ) {
				$approved[] = $key;
			}
		}

		return array_values( array_unique( $approved ) );
	}

	private function resolve_bundle_agent( array $bundle, string $slug = '' ): ?array {
		$target = '' !== $slug ? sanitize_title( $slug ) : sanitize_title( (string) ( $bundle['agent']['agent_slug'] ?? '' ) );
		return '' === $target ? null : $this->agents->get_by_slug( $target );
	}

	private function resolve_installed_agent( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$agent = $this->agents->get_by_slug( $slug );
		if ( $agent && ! empty( $agent['agent_config']['datamachine_bundle']['bundle_slug'] ) ) {
			return $agent;
		}

		foreach ( $this->agents->get_all() as $candidate ) {
			$bundle = $candidate['agent_config']['datamachine_bundle'] ?? array();
			if ( sanitize_title( (string) ( $bundle['bundle_slug'] ?? '' ) ) === $slug ) {
				return $candidate;
			}
		}

		return null;
	}

	private function installed_status( array $agent ): array {
		$bundle    = $agent['agent_config']['datamachine_bundle'] ?? array();
		$artifacts = AgentBundleArtifactState::installed_for_agent( $agent );

		return array(
			'agent_id'         => (int) $agent['agent_id'],
			'agent_slug'       => (string) $agent['agent_slug'],
			'template_slug'    => (string) ( $bundle['template_slug'] ?? $bundle['bundle_slug'] ?? '' ),
			'template_version' => (string) ( $bundle['template_version'] ?? $bundle['bundle_version'] ?? '' ),
			'bundle_slug'      => (string) ( $bundle['bundle_slug'] ?? '' ),
			'bundle_version'   => (string) ( $bundle['bundle_version'] ?? '' ),
			'source_ref'       => (string) ( $bundle['source_ref'] ?? '' ),
			'source_revision'  => (string) ( $bundle['source_revision'] ?? '' ),
			'artifact_count'   => count( $artifacts ),
			'artifacts'        => $artifacts,
		);
	}

	private function bundle_summary( array $bundle, string $slug = '' ): array {
		$package = AgentBundler::package_from_bundle( $bundle );

		return array(
			'bundle_slug'    => $package->get_slug(),
			'bundle_version' => $package->get_version(),
			'target_slug'    => '' !== $slug ? sanitize_title( $slug ) : $package->get_agent()->get_slug(),
			'pipelines'      => count( $bundle['pipelines'] ?? array() ),
			'flows'          => count( $bundle['flows'] ?? array() ),
			'artifacts'      => count( $package->get_artifacts() ),
		);
	}
}
