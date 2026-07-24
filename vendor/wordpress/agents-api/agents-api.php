<?php
/**
 * Plugin Name: Agents API
 * Description: WordPress-shaped agent runtime substrate.
 * Version: 0.6.0
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Author: Automattic
 * License: GPL-2.0-or-later
 * Text Domain: agents-api
 *
 * Agents API bootstrap.
 *
 * WordPress-shaped agent substrate.
 *
 * @package AgentsAPI
 */

// Under static analysis the bootstrap is not executed: PHPStan loads this file
// through the Composer files-autoloader while analysing, and the runtime
// requires below would fatal without WordPress. PHPStan reads src/ directly, so
// returning early here is safe and avoids killing its analysis workers.
if ( defined( '__PHPSTAN_RUNNING__' ) ) {
	return;
}

// Composer evaluates this file through its `files` autoloader. Loading a
// project's autoloader before WordPress is bootstrapped must remain harmless.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( defined( 'AGENTS_API_LOADED' ) ) {
	// A newer bundled or Composer copy can add symbols after an older copy has
	// registered the runtime. Load each class or interface only when its symbol is
	// absent, without repeating registration side effects.
	$agents_api_load_symbol_file = static function ( string $file ): void {
		$namespace = '';
		$tokens    = token_get_all( (string) file_get_contents( $file ) );
		$count     = count( $tokens );

		for ( $index = 0; $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_NAMESPACE === $token[0] ) {
				$namespace = '';
				for ( ++$index; $index < $count; ++$index ) {
					$namespace_token = $tokens[ $index ];
					if ( ';' === $namespace_token || '{' === $namespace_token ) {
						break;
					}
					if ( is_array( $namespace_token ) ) {
						$namespace .= $namespace_token[1];
					}
				}
				continue;
			}

			if ( ! in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
				continue;
			}

			for ( ++$index; $index < $count; ++$index ) {
				$symbol_token = $tokens[ $index ];
				if ( is_array( $symbol_token ) && T_STRING === $symbol_token[0] ) {
					$namespace = trim( $namespace );
					$symbol    = '' === $namespace ? $symbol_token[1] : $namespace . '\\' . $symbol_token[1];
					if ( ! class_exists( $symbol, false ) && ! interface_exists( $symbol, false ) && ! trait_exists( $symbol, false ) ) {
						require_once $file;
					}
					return;
				}
			}
		}
	};

	$agents_api_symbol_files = array_merge(
		glob( __DIR__ . '/src/*/{class,interface}-*.php', GLOB_BRACE ) ?: array(),
		array( __DIR__ . '/src/Channels/register-default-agents-chat-handler.php' )
	);
	foreach ( $agents_api_symbol_files as $agents_api_symbol_file ) {
		$agents_api_load_symbol_file( $agents_api_symbol_file );
	}

	return;
}

define( 'AGENTS_API_LOADED', true );
define( 'AGENTS_API_PATH', __DIR__ . '/' );
define( 'AGENTS_API_PLUGIN_FILE', __FILE__ );

require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-runtime-overrides.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-installed-agent.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-materialization-request.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-materialization-result.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-installed-agent-state-store.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-installed-agent-projector.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agent-registered-agent-materialization-adapter.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-type.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifacts-registry.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-status.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-hasher.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-workspace-preload-artifact.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-installed-artifact.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-update-plan.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-update-planner.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-callbacks.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-capability-report.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-capability-checker.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-diff.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-artifact-state-store.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-request.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-result.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adoption-orchestrator.php';
require_once AGENTS_API_PATH . 'src/Packages/class-wp-agent-package-adopter.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-capability-ceiling.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-grant.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access-store.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-principal-access-store.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-access.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token-store.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-caller-context.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-authorization-policy.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-token-authenticator.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-wordpress-authorization-policy.php';
require_once AGENTS_API_PATH . 'src/Auth/register-agent-access-abilities.php';
require_once AGENTS_API_PATH . 'src/Runtime/functions-input-normalization.php';
require_once AGENTS_API_PATH . 'src/Runtime/functions-sse-response.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-profile.php';
require_once AGENTS_API_PATH . 'src/Runtime/interface-wp-agent-runtime-profile-provider.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-injection-policy.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-memory-layer.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-memory-registry.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-composable-context.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-section-registry.php';
require_once AGENTS_API_PATH . 'src/Registry/class-wp-agents-registry.php';
require_once AGENTS_API_PATH . 'src/Registry/register-agents.php';
require_once AGENTS_API_PATH . 'src/Registry/register-agent-runtime-bundle-importer.php';
require_once AGENTS_API_PATH . 'src/Packages/register-agent-package-artifacts.php';
add_action( 'wp_agent_package_artifacts_init', array( 'WP_Agent_Workspace_Preload_Artifact', 'register' ) );
require_once AGENTS_API_PATH . 'src/Workspace/class-wp-agent-workspace-scope.php';
require_once AGENTS_API_PATH . 'src/Workspace/class-wp-agent-safe-execution-workspace.php';
require_once AGENTS_API_PATH . 'src/Workspace/register-safe-execution-workspace.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-identity-scope.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-materialized-identity.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-identity-store.php';
require_once AGENTS_API_PATH . 'src/Identity/class-wp-agent-identity-stores.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-conversation-store.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-principal-conversation-store.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-principal-conversation-session-reader.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-conversation-sessions.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-conversation-lock.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-null-conversation-lock.php';
require_once AGENTS_API_PATH . 'src/Transcripts/class-wp-agent-cpt-conversation-store.php';
require_once AGENTS_API_PATH . 'src/Transcripts/register-default-conversation-store.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-store.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-status.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-approval-decision.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-approval-memory-store.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-null-approval-memory-store.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-observer.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-handler.php';
require_once AGENTS_API_PATH . 'src/Approvals/class-wp-agent-pending-action-resolver.php';
require_once AGENTS_API_PATH . 'src/Approvals/register-pending-action-abilities.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-operation.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-decision.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-consent-policy.php';
require_once AGENTS_API_PATH . 'src/Consent/class-wp-agent-default-consent-policy.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-citation-metadata.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-message.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-execution-principal.php';
require_once AGENTS_API_PATH . 'src/Auth/class-wp-agent-autonomous-capability-policy.php';
require_once AGENTS_API_PATH . 'src/Transcripts/register-agents-conversation-session-abilities.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-effective-agent-resolver.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-compaction-item.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-compaction-conservation.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-parameters.php';
require_once AGENTS_API_PATH . 'src/Abilities/class-wp-agent-ability-dispatcher.php';
require_once AGENTS_API_PATH . 'src/Abilities/functions-ability-dispatch.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-declaration.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-runtime-tool-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-access-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-provider.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy-filter.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-policy.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-action-policy-resolver.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-usage-tracker.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-null-tool-usage-tracker.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-tier-resolver.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-default-tool-tier-resolver.php';
require_once AGENTS_API_PATH . 'src/Tools/register-agent-ability-meta-abilities.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-request.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-profile-resolver.php';
require_once AGENTS_API_PATH . 'src/Runtime/functions-agent-runtime-profile.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-runner.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-provider-turn-request.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-provider-turn-result.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-provider-turn-adapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-completion-decision.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-completion-policy.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-transcript-persister.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-null-transcript-persister.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-compaction.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-tool-pair-validator.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-tool-call-gate.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-markdown-section-compaction-adapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-iteration-budget.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-spin-signature.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-spin-detector.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-consecutive-spin-detector.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-identical-failure-signature.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-identical-failure-tracker.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-consecutive-identical-failure-tracker.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-tool-result-truncator.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-byte-limit-tool-result-truncator.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-tool-request.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-tool-result.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-tool-request-store.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-tool-continuation.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-tool-lifecycle.php';
require_once AGENTS_API_PATH . 'src/Runtime/register-runtime-tool-lifecycle-abilities.php';
require_once AGENTS_API_PATH . 'src/Runtime/interface-wp-agent-run-control-store.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-option-run-control-store.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-run-control.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-run-result-envelope.php';
require_once AGENTS_API_PATH . 'src/Runtime/interface-wp-agent-run-control-adapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-filter-run-control-adapter.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-run-outcome.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-package-run-request.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-runtime-package-run-result.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-result.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-chat-run-control.php';
require_once AGENTS_API_PATH . 'src/Tasks/class-wp-agent-task-run-control.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-conversation-loop.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-default-provider-turn-adapter.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-call.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-result.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-executor.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-ability-tool-executor.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-executor-registry.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-execution-core.php';
require_once AGENTS_API_PATH . 'src/Tools/class-wp-agent-tool-source-registry.php';
require_once AGENTS_API_PATH . 'src/Runtime/class-wp-agent-tool-mediation-runner.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-authority-tier.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-kind.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-item.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-resolution.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-context-conflict-resolver.php';
require_once AGENTS_API_PATH . 'src/Context/class-wp-agent-default-context-conflict-resolver.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-scope.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-metadata.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-store-capabilities.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-query.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-validation-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-validator.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-list-entry.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-read-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-write-result.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-store.php';
require_once AGENTS_API_PATH . 'src/Memory/class-wp-agent-memory-stores.php';
require_once AGENTS_API_PATH . 'src/Abilities/class-wp-agent-ability-lifecycle-bridge.php';
require_once AGENTS_API_PATH . 'src/Guidelines/guidelines.php';
require_once AGENTS_API_PATH . 'src/Guidelines/class-wp-guidelines-substrate.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-external-message.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel-session-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-option-channel-session-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel-session-map.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-webhook-signature.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-message-idempotency-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-transient-message-idempotency-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-message-idempotency.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-client.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-queue-item.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-option-bridge-store.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-bridge.php';
require_once AGENTS_API_PATH . 'src/Channels/class-wp-agent-channel.php';
require_once AGENTS_API_PATH . 'src/Channels/register-agents-chat-ability.php';
require_once AGENTS_API_PATH . 'src/Channels/register-default-agents-chat-handler.php';
require_once AGENTS_API_PATH . 'src/Channels/register-agents-chat-run-control-abilities.php';
require_once AGENTS_API_PATH . 'src/Runtime/register-runtime-package-run-ability.php';
require_once AGENTS_API_PATH . 'src/Tasks/register-agents-task-abilities.php';
require_once AGENTS_API_PATH . 'src/Channels/register-frontend-chat-rest-route.php';
require_once AGENTS_API_PATH . 'src/Channels/register-agents-chat-jsonrpc-route.php';
require_once AGENTS_API_PATH . 'src/Channels/register-agents-dispatch-message-ability.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-bindings.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-spec-validator.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-spec.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-result.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-store.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-lifecycle.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-recorder.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-context.php';
require_once AGENTS_API_PATH . 'src/Workflows/interface-wp-agent-workflow-branch-executor.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-step-executor.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-runner.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-registry.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-action-scheduler-bridge.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-branch-store.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-action-scheduler-branch-executor.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-scoped-drain.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-run-awaiter.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-workflows.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-workflow-step-executor.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-agents-workflow-abilities.php';
require_once AGENTS_API_PATH . 'src/Workflows/class-wp-agent-workflow-reconcile-lock.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-reconcile-workflow-branch.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-workflow-branch-executor.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-workflow-bridge-sync.php';
require_once AGENTS_API_PATH . 'src/Workflows/register-action-scheduler-listener.php';
require_once AGENTS_API_PATH . 'src/Routines/class-wp-agent-routine.php';
require_once AGENTS_API_PATH . 'src/Routines/class-wp-agent-routine-registry.php';
require_once AGENTS_API_PATH . 'src/Routines/class-wp-agent-routine-action-scheduler-bridge.php';
require_once AGENTS_API_PATH . 'src/Routines/register-routines.php';
require_once AGENTS_API_PATH . 'src/Routines/register-routine-bridge-sync.php';
require_once AGENTS_API_PATH . 'src/Routines/register-action-scheduler-listener.php';
require_once AGENTS_API_PATH . 'src/Triggers/class-wp-agent-event-trigger.php';
require_once AGENTS_API_PATH . 'src/Triggers/class-wp-agent-event-trigger-registry.php';
require_once AGENTS_API_PATH . 'src/Triggers/register-event-triggers.php';
require_once AGENTS_API_PATH . 'src/Triggers/register-event-trigger-handler.php';

add_action(
	'init',
	static function (): void {
		WP_Agents_Registry::init();
	},
	10
);
add_action( 'init', array( 'WP_Guidelines_Substrate', 'register' ), 9 );
add_action( 'init', array( 'AgentsAPI\\AI\\Abilities\\WP_Agent_Ability_Lifecycle_Bridge', 'register' ), 5 );
