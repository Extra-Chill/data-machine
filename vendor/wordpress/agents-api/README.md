# Agents API

**The shared foundation for building AI agents in WordPress.**

If you're building a plugin that needs an AI agent — one that can hold a conversation, call tools, run workflows, remember things between sessions, or talk to users through Slack, Telegram, or email — you shouldn't have to reinvent the plumbing every time. Agents API gives you that plumbing.

It's a small, focused WordPress package maintained by Automattic. Think of it as the layer that sits *underneath* your product: it owns the boring-but-important parts (agent identity, runtime contracts, tool mediation, sessions, transcripts, memory, workflow scaffolding), so your plugin can focus on what makes it special.

**What you get out of the box:** a way to register agents, a fallback provider-agnostic `agents/chat` runtime, a messaging channel base class that plugs into any transport, value objects for the agent lifecycle, contracts for tools and memory and consent, and lightweight workflow plumbing (spec, validator, runner, default `ability`, `agent`, `foreach`, and `parallel` step handlers, three abilities, and optional Action Scheduler-backed async branch execution).

**What you don't get — and shouldn't expect:** durable workflow history, a workflow editor UI, admin screens, or any provider-specific AI client. Those belong to your product. Agents API is the substrate, not the application.

New here? Start with the [Introduction](docs/introduction.md) — it breaks down the core concepts and vocabulary in plain language — then browse the [developer documentation](docs/README.md).

## Layer Boundary

```text
wp-ai-client -> provider/model prompt execution and provider capabilities
Agents API   -> identity, runtime contracts, orchestration contracts, tool mediation contracts, memory/transcripts/sessions
Consumers    -> product UX, concrete tools, workflows, prompt policy, storage/materialization policy
```

Agents API sits between tool/action discovery and product-specific automation. It owns the reusable agent runtime contracts; product plugins own the user-facing product experience.

## What Agents API Owns

- Agent registration and lookup.
- Messaging channel base class (`WP_Agent_Channel`) that maps an external transport (Telegram, Slack, WhatsApp, Email, …) onto the Abilities-API chat surface, with shared session continuity and lifecycle hooks.
- Runtime message, request, result, and completion value objects.
- Agent execution principal/context value objects.
- Agent access grant, token, token authenticator, authorization policy, and capability ceiling contracts.
- Multi-turn orchestration contracts.
- Opt-in mediated tool result truncation for oversized transcript payloads.
- Opt-in between-turn interrupt sources for cancel, redirect, or additional instruction messages.
- Canonical chat run-control contracts for run IDs, run status, best-effort cancellation, and queued messages.
- Canonical ability discovery and dispatch meta-abilities for large tool surfaces.
- Agent package and package-artifact contracts.
- Shared `wp_guideline` / `wp_guideline_type` storage substrate polyfill when Core/Gutenberg do not provide it.
- Agent memory store contracts and value objects.
- Generic memory/context source registry, context section registry, injection policy vocabulary, and composable context value object.
- Conversation compaction policy and transcript transformation contracts.
- Generic multi-turn conversation loop sequencing around caller-owned adapters.
- Iteration budget primitives for bounded execution across configurable dimensions.
- Tool-call mediation contracts and runtime tool declaration value objects.
- Generic tool visibility policy and action policy resolver contracts.
- Conversation transcript store contracts.
- Consent policy contracts for memory, transcripts, sharing, and escalation.
- Tool source registration, parameter normalization, tool-call mediation, and execution result contracts.
- Session and persistence contracts where they are provider-neutral.
- Retrieved context authority vocabulary, context item shape, and conflict resolution contracts.
- Workflow spec value object, structural validator, in-memory registry, runner with default `ability`, `agent`, `foreach`, and `parallel` step handlers, `Store` and `Run_Recorder` interfaces, optional Action Scheduler bridge and branch executor, and three canonical abilities (`agents/run-workflow`, `agents/validate-workflow`, `agents/describe-workflow`).
- Runtime package workflow execution request/result contracts and the canonical dispatcher ability (`agents/run-runtime-package`) for consumer-owned package materialization and execution adapters.

## What Agents API Does Not Own

- Provider-specific request code. `wp-ai-client` owns provider/model prompt execution.
- Durable workflow / run history, scheduling adapters beyond the optional Action Scheduler bridge and branch executor, workflow editor UI, and product-specific step types (`branch`, nested `workflow`). The substrate provides default in-process step execution plus an optional Action Scheduler path for async `parallel` branches; consumers ship the persistence and product UX.
- Product UI such as admin pages, settings screens, dashboards, or onboarding.
- Product CLI commands beyond generic substrate needs.
- Public REST controllers in v1 unless they are separately designed.
- Product runner adapters that assemble prompts, choose concrete tools, materialize storage, or decide product policy.
- Concrete runtime package materialization, package source checkout, delegated-runtime provisioning, provider mapping, run polling, or evidence artifact upload. The package run contract only defines the request/result envelope and dispatcher seam.
- Concrete tool execution adapters, prompt assembly policy, or product storage/materialization policy.
- Product-specific consent UX, support routing, escalation targets, or transcript-sharing policy.
- Concrete memory retrieval, file projection, convention-path writing, or filesystem layout adapters.

Products can require Agents API because they build on the substrate. Agents API must not depend on any product plugin, import product classes, mirror a product source tree, or encode product vocabulary as generic runtime API.

## Requirements

Agents API requires **WordPress 7.0 or higher**. The substrate itself is provider-agnostic and loads on earlier versions, but every realistic consumer needs an AI provider. The only WordPress-native provider story is `wp-ai-client`, which ships in WordPress 7.0 core. Sites running 6.8–6.9 can install Agents API without errors but won't have a working AI provider unless they manually install the deprecated `wp-ai-client` plugin.

## Quality Gates

Agents API runs repository checks directly through Composer scripts:

- `composer phpstan` runs PHPStan at max level with WordPress stubs.
- `composer smoke` runs the current PHP smoke-test suite.

These checks keep the package self-contained for Core-candidate review while proving the runtime wiring and static contracts still behave as expected.

## Core-Candidate API Naming

Agents API intentionally exposes WordPress-shaped public APIs such as `wp_register_agent()`, `wp_get_agent()`, `wp_agents_api_init`, and `wp_agent_*` hooks.

These names are not accidental plugin globals. They are the public API surface being evaluated for possible WordPress Core alignment, following existing Core naming conventions for substrate APIs. Plugin Check may report prefix warnings for these symbols; those warnings are expected for this Core-candidate package and should be reviewed in that context.

## Installation

Agents API supports the same two delivery shapes used by other shared WordPress runtime packages such as Action Scheduler: install it as a normal WordPress plugin, or require it through Composer and load Composer's autoloader from the host project.

Both paths load the same `agents-api.php` bootstrap. The bootstrap is idempotent, so loading it through Composer and WordPress in the same request is safe.

### WordPress Plugin

Install and activate the Agents API plugin through the normal WordPress plugin mechanism. This is the recommended path for site owners and plugins that declare Agents API as a plugin dependency.

### Composer

Require the package from a host plugin, mu-plugin, or Composer-managed WordPress project:

```sh
composer require wordpress/agents-api:^0.1
```

Composer autoloads `agents-api.php` through the package `files` autoload entry. The consuming project must load Composer's generated `vendor/autoload.php` during WordPress bootstrap. After that, the same public API is available as in the plugin activation path.

Pin released tags (`^0.1`, `0.1.x`, or an exact `0.1.0` tag) for reproducible production installs. Track `main` only for active substrate development.

## Consumer Integration

Product plugins should treat Agents API as an optional or required runtime dependency depending on their feature surface.

For hard requirements, declare the plugin dependency using normal WordPress/plugin-distribution mechanisms and fail clearly when Agents API is unavailable.

For optional integrations, feature-detect the public API before registering agent-backed features inside the registration hook:

```php
add_action(
	'wp_agents_api_init',
	static function () {
		if ( function_exists( 'wp_register_agent' ) ) {
			wp_register_agent( 'example-agent', array( /* ... */ ) );
		}
	}
);
```

Register agent definitions from inside a `wp_agents_api_init` callback. Reads such as `wp_get_agent()` and `wp_has_agent()` are safe after WordPress `init` has fired.

Agents can declare source provenance in `meta` so registration diagnostics can identify which plugin or package owns a slug:

```php
wp_register_agent(
	'example-agent',
	array(
		'label' => 'Example Agent',
		'meta'  => array(
			'source_plugin'  => 'example-plugin/example-plugin.php',
			'source_type'    => 'bundled-agent',
			'source_package' => 'example-package',
			'source_version' => '1.2.3',
		),
	)
);
```

## Public Surface

- `wp_agents_api_init`
- `agents_api_loop_event`
- `wp_agent_dispatch_ability()` / `wp_agent_run_runtime_package()`
- `wp_register_agent()` / `wp_get_agent()` / `wp_get_agents()` / `wp_has_agent()` / `wp_unregister_agent()`
- `WP_Agent`
- `WP_Agent_Runtime_Overrides`
- `WP_Agents_Registry`
- `WP_Agent_Event_Trigger`
- `WP_Agent_Event_Trigger_Registry`
- `WP_Agent_Package*` value objects and artifact registry helpers
- `WP_Agent_Access_Grant`
- `WP_Agent_Access_Store`
- `WP_Agent_Token`
- `WP_Agent_Token_Store`
- `WP_Agent_Token_Authenticator`
- `WP_Agent_Authorization_Policy`
- `WP_Agent_WordPress_Authorization_Policy`
- `WP_Agent_Capability_Ceiling`
- `WP_Agent_Memory_Registry`
- `WP_Agent_Memory_Layer`
- `WP_Agent_Context_Section_Registry`
- `WP_Agent_Context_Injection_Policy`
- `WP_Agent_Composable_Context`
- `wp_guideline_types()` and `WP_Guidelines_Substrate`
- `AgentsAPI\AI\WP_Agent_Message`
- `AgentsAPI\AI\WP_Agent_Execution_Principal`
- `AgentsAPI\AI\WP_Agent_Conversation_Request`
- `AgentsAPI\AI\WP_Agent_Conversation_Runner`
- `AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision`
- `AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy`
- `AgentsAPI\AI\WP_Agent_Transcript_Persister`
- `AgentsAPI\AI\WP_Agent_Null_Transcript_Persister`
- `AgentsAPI\AI\WP_Agent_Conversation_Compaction`
- `AgentsAPI\AI\WP_Agent_Iteration_Budget`
- `AgentsAPI\AI\WP_Agent_Spin_Signature`
- `AgentsAPI\AI\WP_Agent_Spin_Detector`
- `AgentsAPI\AI\WP_Agent_Consecutive_Spin_Detector`
- `AgentsAPI\AI\WP_Agent_Identical_Failure_Signature`
- `AgentsAPI\AI\WP_Agent_Identical_Failure_Tracker`
- `AgentsAPI\AI\WP_Agent_Consecutive_Identical_Failure_Tracker`
- `AgentsAPI\AI\WP_Agent_Tool_Result_Truncator`
- `AgentsAPI\AI\WP_Agent_Byte_Limit_Tool_Result_Truncator`
- `AgentsAPI\AI\WP_Agent_Conversation_Result`
- `AgentsAPI\AI\WP_Agent_Chat_Run_Control`
- `AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Request`
- `AgentsAPI\AI\WP_Agent_Runtime_Package_Run_Result`
- `AgentsAPI\AI\WP_Agent_Conversation_Loop`
- `WP_Agent_Consent_Policy`
- `WP_Agent_Default_Consent_Policy`
- `AgentsAPI\AI\Consent\WP_Agent_Consent_Operation`
- `AgentsAPI\AI\Consent\WP_Agent_Consent_Decision`
- `AgentsAPI\AI\Channels\WP_Agent_External_Message`
- `AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Option_Channel_Session_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Channel_Session_Map`
- `AgentsAPI\AI\Channels\WP_Agent_Webhook_Signature`
- `AgentsAPI\AI\Channels\WP_Agent_Message_Idempotency_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Transient_Message_Idempotency_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Message_Idempotency`
- `AgentsAPI\AI\Channels\WP_Agent_Bridge_Client`
- `AgentsAPI\AI\Channels\WP_Agent_Bridge_Queue_Item`
- `AgentsAPI\AI\Channels\WP_Agent_Bridge_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Option_Bridge_Store`
- `AgentsAPI\AI\Channels\WP_Agent_Bridge`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Declaration`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Call`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Parameters`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Executor`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core`
- `AgentsAPI\AI\Tools\WP_Agent_Tool_Result`
- `agents/ability-search` / `agents/ability-call`
- `agents/run-runtime-package`
- `agents/chat` / `agents/get-chat-run` / `agents/cancel-chat-run` / `agents/queue-chat-message`
- `AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Status`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Store`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer`
- `AgentsAPI\AI\Approvals\WP_Agent_Approval_Memory_Store`
- `AgentsAPI\AI\Approvals\WP_Agent_Null_Approval_Memory_Store`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Resolver`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Handler`
- `AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier`
- `AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind`
- `AgentsAPI\AI\Context\WP_Agent_Context_Item`
- `AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Resolution`
- `AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Resolver`
- `AgentsAPI\AI\Context\WP_Agent_Default_Context_Conflict_Resolver`
- `wp_register_workflow()` / `wp_get_workflow()`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec_Validator`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Bindings`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Store`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry`
- `AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge`
- `AgentsAPI\AI\Workflows\register_workflow_handler()`
- `WP_Agent_Tool_Policy`
- `WP_Agent_Tool_Policy_Filter`
- `WP_Agent_Tool_Access_Policy`
- `WP_Agent_Tool_Tier_Resolver`
- `WP_Agent_Default_Tool_Tier_Resolver`
- `WP_Agent_Tool_Usage_Tracker`
- `WP_Agent_Null_Tool_Usage_Tracker`
- `WP_Agent_Action_Policy_Resolver`
- `WP_Agent_Action_Policy_Provider`
- `AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope`
- `AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store`
- `AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock`
- `AgentsAPI\Core\Database\Chat\WP_Agent_Null_Conversation_Lock`
- `AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Store` and memory value objects, including provenance/trust metadata contracts

## Conversation Loop Events

`WP_Agent_Conversation_Loop` exposes lifecycle events through two observer surfaces:

- The caller-owned `on_event` option: `fn( string $event, array $payload ): void`.
- The WordPress `agents_api_loop_event` action: `do_action( 'agents_api_loop_event', $event, $payload )`.

The callable sink is for the component that directly invokes the loop. The WordPress action is for independent observers such as logging, tracing, metrics, or transcript diagnostics.

Event payloads are read-only snapshots. Observers must not rely on mutating payloads to affect loop behavior. Exceptions thrown by either observer surface are swallowed by the loop, so logging or tracing failures cannot break provider execution, tool mediation, budget enforcement, transcript persistence, or the returned result.

## Memory Provenance Metadata

Memory stores can carry first-class metadata alongside content so callers can distinguish direct user assertions from agent inferences, workspace extraction, curated facts, system-generated facts, or imports.

`WP_Agent_Memory_Metadata` standardizes these fields:

- `source_type`: `user_asserted`, `agent_inferred`, `workspace_extracted`, `system_generated`, `curated`, or `imported`.
- `source_ref`: caller-owned source reference, such as a URL, file path, record ID, or content hash.
- `created_by_user_id` and `created_by_agent_id`: identities responsible for the memory write.
- `workspace`: optional `WP_Agent_Workspace_Scope` identity for revalidation.
- `confidence`: trust score from `0.0` to `1.0`.
- `validator`: validator identifier that can re-check the memory against current substrate state.
- `authority_tier`: `low`, `medium`, `high`, or `canonical`.
- `created_at` and `updated_at`: Unix timestamps for metadata/content lifecycle.

Default trust is intentionally conservative. `agent_inferred` defaults to `0.5` confidence and `low` authority, while `user_asserted`, `curated`, and `system_generated` memories rank higher by default.

Stores declare metadata support through `WP_Agent_Memory_Store_Capabilities`:

```php
$capabilities = $store->capabilities();

$unsupported = $capabilities->unsupported_metadata_fields(
	array( 'source_type', 'workspace', 'confidence' ),
	'read'
);
```

Writes, reads, and list entries include `unsupported_metadata_fields` so a caller can tell the difference between missing metadata and a store that cannot persist, return, filter, or rank the requested fields.

Retrieval filters and ranking hints use `WP_Agent_Memory_Query`:

```php
$entries = $store->list_layer(
	new AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope(
		'user',
		$workspace->workspace_type,
		$workspace->workspace_id,
		123,
		456,
		''
	),
	new AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Query(
		source_types: array( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata::SOURCE_USER_ASSERTED ),
		min_confidence: 0.8,
		authority_tiers: array( AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata::AUTHORITY_HIGH ),
		order_by: 'confidence'
	)
);
```

Validators are pluggable and workspace-aware without being tied to a specific product or host:

```php
final class RepoMemoryValidator implements AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validator {
	public function id(): string {
		return 'repo_state';
	}

	public function validate(
		AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope $scope,
		string $content,
		AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Metadata $metadata,
		array $workspace_context = array()
	): AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validation_Result {
		unset( $scope, $content, $metadata );

		return ! empty( $workspace_context['current'] )
			? AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validation_Result::valid()
			: AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Validation_Result::stale( 'Repository state changed.' );
	}
}
```

Agents API defines the contracts only. Concrete stores decide how metadata is physically materialized, how ranking is executed, and which workspace facts are supplied to validators.

For the proposed optional reference implementations, see [Default Stores Companion Proposal](docs/default-stores-companion.md). The proposal keeps concrete persistence policy outside this canonical substrate while documenting the package boundary, test expectations, and extraction order for issue #78.

## External Clients

Agents API owns the generic substrate for external conversation clients: direct
channel plugins, remote bridge clients, normalized external message context, and
session continuity. Product plugins and channel plugins still own platform APIs,
settings screens, and product policy.

See [External Clients, Channels, And Bridges](docs/external-clients.md) for the
architecture boundary and follow-up slices.

## Tool Visibility And Action Policy

Agents API owns generic policy contracts for deciding which tools are visible to an agent and how a called tool is allowed to execute. Consumers still own concrete tool sources, concrete execution adapters, approval storage, UI, workflows, and any product-specific mandatory tools.

Tool visibility is resolved by `WP_Agent_Tool_Policy` over an already-gathered tool map. The resolver applies generic layers only:

- Tool-declared runtime modes through `mode` or `modes`.
- Caller-owned access checks through `tool_access_checker`.
- Registered agent or runtime `tool_policy` config with `allow` / `deny`, `tools`, and `categories`.
- Host-provided `WP_Agent_Tool_Access_Policy` policy fragments.
- Runtime `categories`, `allow_only`, and explicit `deny` lists.
- Caller-provided runtime tool opt-in via `runtime_tools`, `runtime_categories`, allow-mode `tools` / `categories`, `allow_only`, or mandatory policy.

Caller-provided runtime tools are declarations with neutral metadata such as `runtime_tool: true`, `executor: client`, or `scope: run`. They are excluded by default so transport-provided tools are not exposed to the model ambiently. A caller, agent config, or policy provider must opt them in explicitly by name or category. Mandatory tools are not hardcoded by Agents API; a consumer that needs mandatory runtime plumbing can return `mandatory_tools` or `mandatory_categories` from a policy provider, and explicit deny still wins.

This visibility policy is separate from parameter sourcing. Required tool parameters are filled from model/caller parameters first, then from `client_context_bindings` only when the tool declaration explicitly maps a context key. Ambient runtime context keys, including sensitive names such as `api_key`, `token`, and `authorization`, do not satisfy required parameters just because their names match.

```php
$visible_tools = ( new WP_Agent_Tool_Policy() )->resolve(
	$all_tools,
	array(
		'mode'         => 'chat',
		'agent_config' => array(
			'tool_policy' => array(
				'mode'       => 'allow',
				'categories' => array( 'read' ),
				'runtime_tools' => array( 'client/choose_post' ),
			),
		),
		'tool_policy_providers' => array( $consumer_policy_provider ),
	)
);
```

Consumers that gather tools from multiple product-owned sources can use `WP_Agent_Tool_Source_Registry` before the policy pass. Sources are named callbacks that receive `(array $context, WP_Agent_Tool_Source_Registry $registry)` and return declarations keyed by tool name. Lower source priorities run earlier, `agents_api_tool_source_order` can reorder sources for runtime context such as modes, and earlier sources win duplicate tool names.

```php
$registry = new WP_Agent_Tool_Source_Registry();
$registry->registerSource( 'runtime', $runtime_source, 10 );
$registry->registerSource( 'static', $static_source, 20 );

$all_tools = $registry->gather(
	array(
		'agent_id' => 'writer',
		'modes'    => array( 'chat' ),
	)
);
```

The registry exposes three composition hooks: `agents_api_tool_sources` for injecting or replacing named sources, `agents_api_tool_source_order` for source precedence, and `agents_api_tool_source_tools` for context-aware adjustment of one source's gathered declarations.

Action policy is resolved by `WP_Agent_Action_Policy_Resolver` and always returns one of the canonical values from `AgentsAPI\AI\Tools\WP_Agent_Action_Policy`: `direct`, `preview`, or `forbidden`.

Resolution order is:

1. Explicit runtime `deny` list resolves a tool to `forbidden`.
2. Registered agent or runtime `action_policy.tools[tool_name]`.
3. Registered agent or runtime `action_policy.categories[category]`.
4. Host-provided `WP_Agent_Action_Policy_Provider` providers.
5. Host-provided `WP_Agent_Approval_Memory_Store` remembered decisions.
6. Tool-declared `action_policy` default.
7. Tool-declared mode-specific `action_policy_<mode>` default.
8. Global default `direct`.
9. Final `agents_api_tool_action_policy` filter, if present.

```php
$policy = ( new WP_Agent_Action_Policy_Resolver() )->resolve_for_tool(
	array(
		'tool_name'    => 'example/publish',
		'tool_def'     => $visible_tools['example/publish'],
		'mode'         => 'chat',
		'agent_config' => array(
			'action_policy' => array(
				'categories' => array(
					'publishing' => 'preview',
				),
			),
		),
		'action_policy_providers' => array( $consumer_action_policy_provider ),
	)
);
```

Remembered approvals are host-owned. Pass an `approval_memory_store` context value, constructor argument, or filter-provided store through `agents_api_approval_memory_store` to let a user's "always allow" decision participate in the canonical resolver without maintaining a parallel cache.

## Workspace Scope

`AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope` is the generic workspace identity shared by memory, transcript, persistence, and audit adapters. It is deliberately broader than a WordPress site ID:

```php
$workspace = AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope::from_parts(
	'code_workspace',
	'Automattic/intelligence@contexta8c-read-coverage'
);

$workspace->to_array();
// array(
// 	'workspace_type' => 'code_workspace',
// 	'workspace_id'   => 'Automattic/intelligence@contexta8c-read-coverage',
// )
```

Consumers may map WordPress sites, networks, headless runtimes, Studio sites, code workspaces, pull requests, or ephemeral execution environments into that pair. Agents API keeps those mappings in consumer adapters; the generic contracts only depend on `workspace_type` + `workspace_id`.

Memory scope uses `(layer, workspace_type, workspace_id, user_id, agent_id, filename)` as its identity model:

```php
$scope = new AgentsAPI\Core\FilesRepository\WP_Agent_Memory_Scope(
	'user',
	$workspace->workspace_type,
	$workspace->workspace_id,
	123,
	456,
	'MEMORY.md'
);
```

Transcript sessions are also workspace-stamped. `WP_Agent_Conversation_Store::create_session()` and `::get_recent_pending_session()` both receive an `WP_Agent_Workspace_Scope`, and `WP_Agent_Conversation_Request` can carry a workspace so runtime persisters can stamp the session they materialize.

Transcript sessions use registered agent slugs for runtime agent identity. `WP_Agent_Conversation_Store::create_session()` accepts `string $agent_slug = ''`, matching `wp_register_agent()` / `wp_get_agent()`. Concrete stores that materialize agents as posts can keep post IDs internally, but generic session arrays expose `agent_slug` rather than requiring a WordPress post ID.

Transcript stores preserve provider continuity metadata as part of the complete session state. `WP_Agent_Conversation_Store::update_session()` accepts an optional opaque `provider_response_id`, and `::get_session()` returns the same key alongside `provider` and `model`. Consumers using provider-side state, such as the OpenAI Responses API `previous_response_id` flow, can pass the provider's response ID through this field without encoding per-consumer metadata keys. A `null` value means no provider-side response ID is associated with the current transcript state.

## Retrieved Context Authority

Retrieved context is not only ordered text. Consumers may retrieve memory, identity, conversation, workspace, platform, or support-mode context that conflicts. Agents API provides generic vocabulary and value objects so products can preserve source authority without encoding product-specific policy into this substrate.

Authority tiers, highest authority first:

```text
platform_authority
support_authority
workspace_shared
user_workspace_private
user_global
agent_identity
agent_memory
conversation
```

`platform_authority` and `support_authority` are generic governance tiers. Consumers decide when those sources are enabled and mode-gated. Agents API does not define a WP.com-specific source, storage path, or activation condition.

`AgentsAPI\AI\Context\WP_Agent_Context_Item` is the transport shape for one retrieved item:

```php
$item = new AgentsAPI\AI\Context\WP_Agent_Context_Item(
	'Use concise replies.',
	array( 'workspace' => 'example', 'user_id' => 12 ),
	AgentsAPI\AI\Context\WP_Agent_Context_Authority_Tier::USER_WORKSPACE_PRIVATE,
	array( 'source' => 'memory', 'uri' => 'memory:user/12/preferences.md' ),
	AgentsAPI\AI\Context\WP_Agent_Context_Conflict_Kind::PREFERENCE,
	'response_style'
);
```

The exported shape is JSON-friendly and includes:

- `content` - retrieved text or serialized context payload.
- `scope` - product-defined scope metadata such as workspace, user, agent, mode, or site.
- `authority_tier` - one of the generic authority tiers above.
- `provenance` - source metadata such as provider, URI, content hash, timestamp, or retrieval score.
- `conflict_kind` - `preference` or `authoritative_fact`.
- `conflict_key` - optional shared key for mutually conflicting items.
- `metadata` - optional caller-owned JSON-friendly metadata.

Conflict semantics are intentionally explicit:

- **Preferences** may resolve by specificity. A user workspace preference can override a broad platform default preference because it is more specific to the current run.
- **Authoritative facts** resolve by authority tier. Lower-scope memory, identity, or conversation context cannot override a higher platform/support/workspace fact.

`WP_Agent_Context_Conflict_Resolver` defines the resolver contract. `WP_Agent_Default_Context_Conflict_Resolver` provides the generic behavior above: authoritative facts use `authority_tier`; preferences use `specificity_then_authority`.

## Guideline Capabilities

When Agents API provides the `wp_guideline` polyfill, guideline access is scoped by explicit capabilities instead of ordinary post/private-post semantics:

- `read_agent_memory`
- `edit_agent_memory`
- `read_private_agent_memory`
- `edit_private_agent_memory`
- `read_workspace_guidelines`
- `edit_workspace_guidelines`
- `promote_agent_memory`

Private user-workspace memory is identified with guideline metadata, not by `post_status=private` alone:

- `_wp_guideline_scope=private_user_workspace_memory`
- `_wp_guideline_user_id=<owner user id>`
- `_wp_guideline_workspace_id=<workspace id>`

Workspace-shared guidance is identified with `_wp_guideline_scope=workspace_shared_guidance` and `_wp_guideline_workspace_id=<workspace id>`.

The substrate maps private memory reads/edits through the explicit owner metadata, so editors and administrators do not gain access merely because they can read private posts. Workspace-shared guidance reads map to the editorial threshold (`edit_posts`), edits map to the publishing threshold (`publish_posts`), and promotion from private memory to shared guidance requires the owner plus the explicit `promote_agent_memory` capability.

Hosts that provide their own guideline substrate can disable the polyfill with the `wp_guidelines_substrate_enabled` filter or register `wp_guideline` before Agents API does.

## Memory And Context Registry

Agents API separates memory identity, retrieval policy, composable context assembly, and storage projection:

```text
Memory/context source registry -> what can exist and when it is eligible
Context section registry       -> ordered pieces that can compose a context
Composable context             -> runtime assembly result
Consumer adapters              -> file paths, database rows, guidelines, external stores
```

`WP_Agent_Memory_Registry` registers memory/context sources by layer and mode without assuming they are files. File conventions are metadata for adapters, not the identity model:

```php
WP_Agent_Memory_Registry::register(
	'workspace/instructions',
	array(
		'layer'            => WP_Agent_Memory_Layer::WORKSPACE,
		'priority'         => 20,
		'protected'        => true,
		'editable'         => false,
		'modes'            => array( 'chat', 'pipeline' ),
		'retrieval_policy' => WP_Agent_Context_Injection_Policy::ALWAYS,
		'composable'       => true,
		'context_slug'     => 'workspace-instructions',
		'convention_path'  => 'AGENTS.md',
	)
);
```

Supported retrieval policies are `always`, `on_intent`, `on_tool_need`, `manual`, and `never`. Agents API only defines vocabulary and filtering; dynamic retrieval heuristics remain consumer/runtime policy.

`WP_Agent_Context_Section_Registry` registers composable sections independently from any projection target:

```php
WP_Agent_Context_Section_Registry::register(
	'workspace-instructions',
	'agent-memory-policy',
	20,
	static function ( array $context, array $section ): string {
		return "## Memory Policy\nUse only context sources allowed for " . ( $context['mode'] ?? 'runtime' ) . '.';
	},
	array(
		'modes'            => array( 'chat' ),
		'retrieval_policy' => WP_Agent_Context_Injection_Policy::ALWAYS,
	)
);

$composed = WP_Agent_Context_Section_Registry::compose(
	'workspace-instructions',
	array( 'mode' => 'chat' )
);
```

The generic layer vocabulary uses `workspace` rather than `site`. Products that need site or network files should map them through adapters or registration metadata such as `convention_path` or `external_projection_target`.

## Execution Principals

`AgentsAPI\AI\WP_Agent_Execution_Principal` represents the actor and agent context for one runtime request. It records the acting WordPress user ID, effective agent ID/slug, auth source, request context, optional token ID, workspace ID, client ID, capability ceiling, optional caller context, and JSON-friendly request metadata.

Host plugins can resolve the current principal from REST, CLI, cron, bearer-token, or session state through the `agents_api_execution_principal` filter:

```php
add_filter(
	'agents_api_execution_principal',
	static function ( $principal, array $context ) {
		if ( 'rest' !== ( $context['request_context'] ?? '' ) ) {
			return $principal;
		}

		return AgentsAPI\AI\WP_Agent_Execution_Principal::user_session(
			get_current_user_id(),
			(string) ( $context['agent_id'] ?? '' ),
			'rest'
		);
	},
	10,
	2
);
```

## Agent Authorization

Agents API provides generic authorization substrate shapes without owning product tables, workflows, or UI.

```text
request bearer token
  -> WP_Agent_Token_Authenticator
  -> WP_Agent_Token_Store resolves hash only
  -> WP_Agent_Execution_Principal records actor, agent, token, workspace, client
  -> WP_Agent_Capability_Ceiling intersects token/client restrictions
  -> WP_Agent_WordPress_Authorization_Policy calls user_can() for the owner/user ceiling
```

`WP_Agent_Access_Grant` models a role-based grant between a WordPress user and an agent, optionally scoped by a host workspace. Roles are generic and ordered: `viewer`, `operator`, `admin`. Concrete storage belongs to hosts via `WP_Agent_Access_Store`.

`WP_Agent_Token` models token metadata for bearer-token authentication. It stores token hash, prefix, label, expiry, last-used timestamp, optional client/workspace identifiers, and optional capability restrictions. It never exposes raw token material in metadata exports.

`WP_Agent_Token_Authenticator` accepts a raw bearer token at the request edge, hashes it, asks a host token store to resolve the hash, rejects expired tokens, touches successful tokens, and returns an `WP_Agent_Execution_Principal` populated with token/client/workspace context.

## Caller Context

`WP_Agent_Caller_Context` carries cross-site agent-to-agent caller claims alongside an execution principal. It is a value object and parser only; hosts remain responsible for deciding whether the caller host and token are trusted.

Canonical inbound header names:

- `X-Agents-Api-Caller-Agent` — agent ID/slug on the caller host.
- `X-Agents-Api-Caller-User` — user ID on the caller host, or `0` when no caller-host user applies.
- `X-Agents-Api-Caller-Host` — `self` or an absolute URL for the remote caller host.
- `X-Agents-Api-Chain-Depth` — non-negative chain depth, where `0` is top-of-chain.
- `X-Agents-Api-Chain-Root` — stable identifier for the originating request.

Requests with no caller headers parse as a top-of-chain context: no caller agent, caller user `0`, caller host `self`, chain depth `0`, and a generated chain root request ID. Requests with malformed caller headers are rejected fail-closed by `WP_Agent_Token_Authenticator` before the token is touched.

```php
$principal = $authenticator->authenticate_bearer_token(
	$raw_token,
	AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
	array(),
	$request // WP_REST_Request or array of request headers.
);

if ( $principal && $principal->caller_context?->is_cross_site() ) {
	// Host-owned trust policy: verify caller_host through shared keys, mTLS,
	// allow-lists, or another product-specific mechanism before honoring claims.
}
```

The default parser depth ceiling is `WP_Agent_Caller_Context::DEFAULT_MAX_CHAIN_DEPTH` (`16`). Hosts can pass a stricter maximum to `authenticate_bearer_token()` or parse with `WP_Agent_Caller_Context::from_headers( $headers, $max_depth )` before applying their own loop policy.

`WP_Agent_WordPress_Authorization_Policy` is the default WordPress-shaped policy. It denies a capability unless both are true:

- The token/client ceiling allows the requested capability, when a ceiling allow-list exists.
- The acting/owner WordPress user has the requested capability via `user_can()`.

Hosts can replace this policy by implementing `WP_Agent_Authorization_Policy`, or pass host-owned access/token stores while keeping the generic value objects.

## Consent Policy Boundary

Agents API owns a generic consent contract for runtime operations that carry different user expectations:

- `store_memory` — store consolidated agent memory.
- `use_memory` — use existing agent memory during a run.
- `store_transcript` — store a raw conversation transcript.
- `share_transcript` — share a raw transcript outside its owning context.
- `escalate_to_human` — escalate a run or transcript to a human/support adapter.

Memory consent and transcript consent are intentionally separate. Allowing an agent to store or use consolidated memory does not imply consent to persist or share raw transcripts. Escalation is also separate so support-mode adapters can ask their own product questions without adding support-product logic to Agents API.

Policies implement `WP_Agent_Consent_Policy` and return `WP_Agent_Consent_Decision` values with `allowed`, `operation`, `reason`, and `audit_metadata` fields. Consumers should store those decision arrays alongside any memory write, transcript persistence/share event, or escalation event they apply.

```php
$policy = new WP_Agent_Default_Consent_Policy();

$decision = $policy->can_store_transcript(
	array(
		'mode'    => 'chat',
		'user_id' => get_current_user_id(),
		'agent_id' => 'example-agent',
		'consent' => array(
			'store_transcript' => true,
		),
	)
);

if ( $decision->is_allowed() ) {
	$transcript_id = $transcript_persister->persist( $messages, $request, $result );
	$audit_store->record( $decision->to_array() + array( 'transcript_id' => $transcript_id ) );
}
```

`WP_Agent_Default_Consent_Policy` is conservative: non-interactive modes are denied by default, and interactive modes still require explicit per-operation consent. Products can supply adapter-specific policies for their own UX, authorization ceilings, support routing, retention rules, and audit stores.

## Conversation Compaction

Agents can declare support for runtime conversation compaction without tying Agents API to a provider or model executor:

```php
wp_register_agent(
	'example-agent',
	array(
		'supports_conversation_compaction' => true,
		'conversation_compaction_policy'   => array(
			'enabled'         => true,
			'max_messages'    => 40,
			'recent_messages' => 12,
		),
	)
);
```

`AgentsAPI\AI\WP_Agent_Conversation_Compaction::compact()` transforms a transcript before model dispatch. The caller supplies a summarizer callable, keeping low-level model execution outside Agents API. The result includes:

- `messages`: the transformed transcript, with a synthetic summary message followed by retained recent messages.
- `metadata.compaction`: status, compacted boundary, retained count, and summary metadata for persisted transcripts.
- `events`: `compaction_started`, `compaction_completed`, or `compaction_failed` lifecycle events that streaming clients can relay.

Boundary selection preserves tool-call/tool-result integrity by default. If summarization fails, the original normalized transcript is returned unchanged and a failure event is emitted rather than silently dropping history.

## Conversation Loop Boundary

`AgentsAPI\AI\WP_Agent_Conversation_Loop` is a generic loop facade. It owns the reusable mechanics that every multi-turn agent run needs:

- Normalizing inbound messages to `WP_Agent_Message`.
- Optionally applying caller-supplied compaction before each turn.
- Calling a runner adapter once per turn.
- Validating each runner response with `WP_Agent_Conversation_Result`.
- Tool-call mediation through `WP_Agent_Tool_Execution_Core` + `WP_Agent_Tool_Executor` when enabled.
- Typed completion policy via `WP_Agent_Conversation_Completion_Policy`.
- Transcript persistence via `WP_Agent_Transcript_Persister`.
- Lifecycle event emission via an `on_event` callable.
- Asking a caller-supplied `should_continue` continuation policy whether another turn is needed.

It does not assemble prompts, select a provider/model, implement concrete tools, choose durable storage, expose admin UI, or define product workflow semantics. Consumers provide adapters for those concerns and pass them into the loop.

### Minimal caller-managed usage

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		return $runner->run_turn( $messages, $context );
	},
	array(
		'max_turns'       => 4,
		'should_continue' => static function ( array $turn_result, array $context ): bool {
			return $policy->should_continue( $turn_result, $context );
		},
		'compaction_policy' => $agent->conversation_compaction_policy,
		'summarizer'         => $summarizer,
	)
);
```

### Full usage with tool mediation, completion policy, persistence, and events

When `tool_executor` and `tool_declarations` are provided, the loop handles the tool-call → validate → execute → message assembly cycle internally. The turn runner becomes the AI request adapter only — it sends messages to the provider and returns a response with optional `tool_calls`:

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	static function ( array $messages, array $context ): array {
		// Turn runner dispatches to the AI provider and returns:
		// - 'messages': current transcript
		// - 'content': assistant text response (optional)
		// - 'tool_calls': array of {name, parameters} (optional)
		$response = $ai_client->prompt( $messages );
		return array(
			'messages'   => $messages,
			'content'    => $response->text(),
			'tool_calls' => $response->tool_calls(),
		);
	},
	array(
		'max_turns'         => 10,
		'context'           => array( 'agent_id' => 'my-agent' ),

		// Tool execution mediation (#45)
		'tool_executor'     => $my_tool_executor,      // WP_Agent_Tool_Executor
		'tool_declarations' => $available_tools,        // array keyed by tool name

		// Typed completion policy (#42)
		'completion_policy' => $my_completion_policy,   // WP_Agent_Conversation_Completion_Policy

		// Optional tool result truncation for oversized mediated results.
		'tool_result_truncator' => new AgentsAPI\AI\WP_Agent_Byte_Limit_Tool_Result_Truncator( 8192 ),

		// Optional between-turn interruption. Return a message array or null.
		'interrupt_source' => static function ( AgentsAPI\AI\WP_Agent_Conversation_Request $request ): ?array {
			return $interrupt_queue->next_message_for( $request );
		},

		// Transcript persistence (#43)
		'transcript_persister' => $my_persister,        // WP_Agent_Transcript_Persister

		// Iteration budgets (#47)
		'budgets' => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 20 ),
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls_progress_story', 5 ),
		),

		// Lifecycle events (#44)
		'on_event' => static function ( string $event, array $payload ): void {
			// Events: turn_started, tool_call, tool_result, tool_result_truncated, interrupt_received, budget_exceeded, completed, failed
			$logger->log( $event, $payload );
		},

		// Caller-owned continuation policy; typed completion policy takes precedence.
		'should_continue' => static function ( array $result, array $context ): bool {
			return ! empty( $result['tool_execution_results'] );
		},

		// Compaction (unchanged)
		'compaction_policy' => $agent->conversation_compaction_policy,
		'summarizer'         => $summarizer,

		// Optional: pass the original request for transcript persistence context
		'request' => $conversation_request,            // WP_Agent_Conversation_Request
	)
);
```

All new options are opt-in. Existing callers passing only the original options continue to work identically.

Server-mediated tools use the canonical `WP_Agent_Tool_Declaration::normalizeForServer()` envelope before they reach provider-turn requests or loop mediation. The normalized shape is a stable namespaced `name`, host/source `source`, non-empty `description`, array `parameters`, `executor => 'host'`, `scope => 'run'`, optional sanitized `runtime` metadata, and JSON-friendly extension fields such as `client_context_bindings`. Legacy non-client executor labels canonicalize to `host` because concrete execution remains owned by `WP_Agent_Tool_Executor`. Client runtime tools remain strict through `normalize()`, while request/catalog ingestion keeps older `client/*` loop declarations working by supplying implied `client` executor/source/scope defaults.

When `tool_result_truncator` is provided, the loop asks it to normalize mediated tool results before adding them to `tool_execution_results` and transcript `tool_result` messages. `WP_Agent_Byte_Limit_Tool_Result_Truncator` replaces oversized JSON-encoded results with an excerpt plus byte-count metadata and emits `tool_result_truncated`; the event payload includes `original_result` for observer-owned storage, logging, or artifact capture.

When `interrupt_source` is provided, the loop checks it between turns with the current `WP_Agent_Conversation_Request`. Returning a message appends that message to the transcript and emits `interrupt_received`. Message metadata can set `interrupt_action` to `message`, `redirect`, or `cancel`; `cancel` stops the loop with `status: interrupted`, while `message` and `redirect` continue through the normal continuation policy.

For large ability surfaces, Agents API registers two canonical meta-abilities. `agents/ability-search` searches registered abilities by name, category, substring, `+keyword`, or `select:foo/bar,baz/qux` and returns compact `{ name, summary, required_fields }` entries. `agents/ability-call` invokes a registered ability by name with JSON parameters, letting consumers keep lower-priority tools out of the prompt while still making them reachable through a stable discovery-and-call path.

The loop treats all adapter inputs and outputs as JSON-friendly arrays so products can map them to their own storage, streaming, audit, and transport layers without Agents API owning those layers.

## Pending Action Approval Boundary

Agents API owns generic approval primitives for runtime actions that need explicit user or policy approval before a consumer applies them. The lifecycle is:

- A runtime or tool proposes an action instead of applying it immediately.
- The proposal is emitted or stored as a generic `WP_Agent_Pending_Action` value.
- A UI, user, policy service, or resolver actor accepts or rejects the pending action.
- The consumer adapter resolves the decision, runs handler-level permission checks, applies or discards the proposal through its own product-specific handler, and records terminal audit metadata.

Agents API owns the reusable contract shape only: value objects and interfaces for pending actions, the JSON-friendly proposal and decision shape, status vocabulary, policy vocabulary for approval requirements, and a typed `approval_required` envelope that runtimes can return without knowing where the proposal will be stored or displayed.

Durable pending action records include:

- `action_id`, `kind`, `summary`, `preview`, and `apply_input`.
- `workspace` as an `WP_Agent_Workspace_Scope` array (`workspace_type` + `workspace_id`), plus `agent` and `creator` actor/provenance fields.
- `status` using `WP_Agent_Pending_Action_Status`: `pending`, `accepted`, `rejected`, `expired`, or `deleted`.
- `created_at`, `expires_at`, and terminal `resolved_at` timestamps.
- `resolver`, `resolution_result`, `resolution_error`, and `resolution_metadata` audit fields.
- Generic `metadata` for JSON-serializable caller context that is not part of handler replay input.

`WP_Agent_Pending_Action_Store` defines the durable queue/audit surface: `store`, `get`, `list`, `summary`, `record_resolution`, `expire`, and `delete`. `WP_Agent_Pending_Action_Resolver` defines accept/reject resolution with an explicit resolver identity. `WP_Agent_Pending_Action_Handler` lets product handlers enforce handler-level permission checks before applying or rejecting a stored action.

Consuming products own the concrete materialization: database tables, REST routes, abilities or tool surfaces, chat/admin UI, permission ceilings, queues, jobs, workflows, and product-specific apply/reject handlers. Those concerns belong in adapters because they depend on each product's UX, authorization model, and operational semantics.

Package artifacts can also describe a `diff_callback` so packages can generate reviewable diffs for installer or updater flows. That artifact is related to approval because it helps produce human-reviewable change previews, but it is not the same primitive as runtime pending-action approval. `diff_callback` belongs to package artifact review; `approval_required` belongs to a live runtime/tool proposal that must be accepted or rejected before the consumer applies it.

## Iteration Budgets

`AgentsAPI\AI\WP_Agent_Iteration_Budget` is a generic bounded-iteration primitive. It counts a named dimension (turns, tool calls, chain depth, retries) and exposes a uniform API for checking exceedance. A budget is a stateful value object — call `increment()` at each iteration, then `exceeded()` to decide whether to continue.

```php
$budget = new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'chain_depth', 3 );

$budget->name();      // 'chain_depth'
$budget->ceiling();   // 3
$budget->current();   // 0
$budget->exceeded();  // false
$budget->remaining(); // 3

$budget->increment();
$budget->current();   // 1
$budget->exceeded();  // false

$budget->increment();
$budget->increment();
$budget->exceeded();  // true (current >= ceiling)
$budget->remaining(); // 0
```

### Loop integration

Pass budgets to `WP_Agent_Conversation_Loop::run()` via the `budgets` option. The loop enforces them at the appropriate seams:

- **`turns`** — incremented after each turn. When an explicit `WP_Agent_Iteration_Budget('turns', N)` is provided, it overrides `max_turns` and produces a `budget_exceeded` status when tripped.
- **`tool_calls`** — incremented after each tool call when tool mediation is enabled.
- **`tool_calls_<name>`** — incremented per tool name for ping-pong protection (e.g. `tool_calls_progress_story`).

```php
$result = AgentsAPI\AI\WP_Agent_Conversation_Loop::run(
	$messages,
	$turn_runner,
	array(
		'max_turns' => 10,
		'budgets'   => array(
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls', 20 ),
			new AgentsAPI\AI\WP_Agent_Iteration_Budget( 'tool_calls_progress_story', 5 ),
		),
		'tool_executor'     => $executor,
		'tool_declarations' => $tools,
	)
);

if ( ( $result['status'] ?? null ) === 'budget_exceeded' ) {
	// $result['budget'] contains the name of the exceeded budget.
	$logger->warn( 'Budget exceeded: ' . $result['budget'] );
}
```

When a budget trips, the loop returns early with `status: 'budget_exceeded'` and `budget: '<name>'` in the result. A `budget_exceeded` event is also emitted through the `on_event` sink with `budget`, `current`, and `ceiling` in the payload.

External observers tracking exotic dimensions (token cost, wall-clock, custom chain depth) can use the `on_event` hook to increment their own `WP_Agent_Iteration_Budget` instances and signal the loop through the existing `should_continue` or completion policy escape hatches.

The substrate ships only the per-execution value object. Registries, configuration persistence, and ceiling policies are consumer concerns.

## Tests

```bash
composer test
```
