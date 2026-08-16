# Plugin Check v0.174.0 Hook-Name Dossier

## Scope

Source evidence: `.homeboy/evidence/plugin-check-v01740/data-machine.json`.

The declared evidence contains 6 `DynamicHooknameFound` warnings and 1
`NonPrefixedHooknameFound` warning. This change addresses only those seven
findings; the evidence's other 246 findings remain outside this boundary.

## Hook Inventory

| Hook or selector | Owner | Change kind | Compatibility decision |
| --- | --- | --- | --- |
| `wp_agent_workflow_should_fanout` | Agents API | WPCS justification | Retained as the canonical external contract. |
| `agents_chat_runtime_principal_permission` | Agents API/WP Codebox | WPCS justification | Retained because WP Codebox consumes this shared runtime-principal authorization contract. |
| `datamachine_job_terminal_committed`, `datamachine_job_complete` | Data Machine | WPCS justification | A fixed two-item literal set preserves ordered terminal notifications. |
| `datamachine_delegated_operation_actions` | Data Machine | WPCS justification | A canonical constant names the registry extension point. |
| `datamachine_job_artifact_*` | Data Machine | WPCS justification | The private helper has three explicit prefixed call sites. |
| `datamachine_pending_action_resolution_token_html` | Data Machine | WPCS justification | A canonical constant names the response extension point. |
| `datamachine_agent_config_artifact_projection_policies` | Data Machine | WPCS justification | The private helper has one explicit prefixed call site. |

## Verification

Before (declared Plugin Check evidence): 6 `DynamicHooknameFound`; 1
`NonPrefixedHooknameFound`.

After (targeted WPCS verification): 0 findings from
`WordPress.NamingConventions.PrefixAllGlobals` across all seven affected source
files. The repository does not provide a WordPress Plugin Check runner, so a
full Plugin Check rerun is outside the available verification capability.
WPCS justifications are intentionally narrow and document each bounded
selector.

Run:

```sh
php tests/parallel-map-fanout-adapter-smoke.php
php tests/agents-chat-access-permission-smoke.php
php tests/signed-pending-action-resolution-smoke.php
php tests/job-status-accounting-smoke.php
vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.NamingConventions.PrefixAllGlobals inc/Abilities/Engine/ParallelMapFanoutAdapter.php inc/Abilities/Chat/AgentsChatHandler.php inc/Core/Database/Jobs/Jobs.php inc/Core/DelegatedOperations/DelegatedOperationRegistry.php inc/Core/JobArtifactSurfaces.php inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php inc/Engine/Bundle/AgentConfigArtifactProjector.php
```

Runtime-change evidence boundary: all hook names and dispatch behavior are
preserved. Narrow annotations document the shared external hook and bounded
dynamic selectors; the listed runtime smoke tests verify the related fan-out,
chat, and terminal-accounting contracts.

## AI Disclosure

GPT-5.6 Sol via OpenCode/Homeboy was used for implementation and verification.
Chris Huber remains responsible for every line.
