# Tool Execution Architecture

Tool execution is split into a generic execution core and a Data Machine product wrapper.

| Component | File | Responsibility |
|---|---|---|
| `ToolPolicyResolver` | `inc/Engine/AI/Tools/ToolPolicyResolver.php` | Builds the visible tool set for a request. |
| `ToolExecutionCore` | `inc/Engine/AI/Tools/Execution/ToolExecutionCore.php` | Validates required parameters, builds complete parameters, executes ability-only or class/method tools. |
| `ToolExecutor` | `inc/Engine/AI/Tools/ToolExecutor.php` | Applies action policy, stages preview actions, runs direct execution, records post-origin tracking. |
| `ToolParameters` | `inc/Engine/AI/Tools/ToolParameters.php` | Merges AI arguments with payload, data packet, handler config, and tool metadata. |
| `ActionPolicyResolver` | `inc/Engine/AI/Actions/ActionPolicyResolver.php` | Decides whether one invocation is `direct`, `preview`, or `forbidden`. |
| `PendingActionHelper` | `inc/Engine/AI/Actions/PendingActionHelper.php` | Stores approval-required invocations and returns the Agents API approval envelope. |

## Runtime Flow

```text
AI request setup
    |
    v
ToolPolicyResolver::resolve()
    |
    v
model tool call
    |
    v
ToolExecutor::executeTool()
    |
    +--> ToolExecutionCore::prepareToolCall()
    |       - tool exists in available tools
    |       - required parameters are present
    |       - ToolParameters::buildParameters()
    |
    +--> ActionPolicyResolver::resolveForTool()
    |       - direct / preview / forbidden
    |
    +--> forbidden: return error
    |
    +--> preview: PendingActionHelper::stage()
    |
    +--> direct: ToolExecutionCore::executePreparedTool()
            - ability-only tool: WP_Ability permissions + execute()
            - class/method tool: instantiate class and call method
```

## Resolving Tools Before Execution

All runtime tool sets should come from `ToolPolicyResolver::resolve()`.

Pipeline example:

```php
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

$resolver = new ToolPolicyResolver();

$tools = $resolver->resolve( array(
    'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
    'agent_id'             => $agent_id,
    'previous_step_config' => $previous_step_config,
    'next_step_config'     => $next_step_config,
    'pipeline_step_id'     => $flow_step_id,
    'engine_data'          => $engine_data,
    'allow_only'           => $policy_args['allow_only'] ?? array(),
    'deny'                 => $policy_args['deny'] ?? array(),
) );
```

Chat example:

```php
$tools = $resolver->resolve( array(
    'mode'           => ToolPolicyResolver::MODE_CHAT,
    'agent_id'       => $agent_id,
    'client_context' => array(
        'session_id' => $session_id,
    ),
) );
```

`ToolExecutor` expects the already-resolved `$available_tools` array. It does not rediscover tools.

## Executing a Tool

```php
use DataMachine\Engine\AI\Actions\ActionPolicyResolver;
use DataMachine\Engine\AI\Tools\ToolExecutor;

$result = ToolExecutor::executeTool(
    $tool_name,
    $tool_parameters,
    $available_tools,
    array(
        'job_id'           => $job_id,
        'data'             => $data_packets,
        'flow_step_id'     => $flow_step_id,
        'flow_step_config' => $flow_step_config,
    ),
    ActionPolicyResolver::MODE_PIPELINE,
    $agent_id
);
```

Signature:

```php
public static function executeTool(
    string $tool_name,
    array $tool_parameters,
    array $available_tools,
    array $payload,
    string $mode = ActionPolicyResolver::MODE_CHAT,
    int $agent_id = 0,
    array $client_context = array()
): array
```

The `$mode`, `$agent_id`, and `$client_context` values only affect action policy. Tool visibility has already been resolved before execution.

## Generic Execution Core

`ToolExecutionCore::prepareToolCall()` returns either a normalized error or a prepared invocation:

```php
array(
    'ready'      => true,
    'tool_def'   => $tool_def,
    'parameters' => $complete_parameters,
)
```

Preparation checks that the tool exists in `$available_tools`, validates required parameters declared in `parameters`, and calls `ToolParameters::buildParameters()`.

`ToolExecutionCore::executePreparedTool()` supports two executable shapes:

| Shape | Required keys | Execution path |
|---|---|---|
| Ability-only | `ability`, no `class`, no `method` | Resolve ability from `WP_Abilities_Registry`, check permissions, call `execute()`. |
| Class/method | `class`, `method` | Instantiate class and call the method with complete parameters and tool definition. |

Ability-only tools are useful when the WordPress Ability is already the source of truth for permission and behavior. Class/method tools remain valid for Data Machine handler tools and product-specific adapters.

## Parameter Building

`ToolParameters::buildParameters()` merges model-provided arguments with Data Machine payload context.

Common payload values include:

| Payload key | Purpose |
|---|---|
| `job_id` | Job currently running. |
| `data` | Data packets from previous steps. |
| `flow_step_id` | Current flow-step identifier. |
| `flow_step_config` | Current flow-step snapshot. |
| `handler_config` | Handler-specific runtime configuration. |
| `session_id` | Chat session identifier when called from chat. |

The AI's tool parameters win over defaults extracted from the payload. Handler tools also receive `handler_config`, engine data such as `source_url` and `image_url`, and the resolved `tool_definition`.

## Action Policy

Visibility and execution are separate. A tool can be visible to the model and still be prevented from executing directly.

`ActionPolicyResolver::resolveForTool()` returns the Agents API vocabulary:

| Policy | Effect |
|---|---|
| `direct` | Execute immediately. |
| `preview` | Stage the invocation and return an approval-required envelope. |
| `forbidden` | Return a refusal error without running the tool. |

Resolution inputs include `tool_name`, `tool_def`, `mode`, `agent_id`, `client_context`, and optional `deny`.

Resolution order is:

1. Explicit deny list.
2. Per-agent `agent_config.action_policy.tools[tool_name]`.
3. Per-agent `agent_config.action_policy.categories[category]`.
4. Tool-declared default, including per-mode keys such as `action_policy_chat`.
5. Mode preset from `DataMachineModeActionPolicyProvider`.
6. Global default from Agents API.
7. `datamachine_tool_action_policy` filter.

Chat can stage publish-family tools for preview because a user is present. Pipeline and system modes default to direct for automation unless a tool or policy says otherwise.

## Pending Actions

When action policy resolves to `preview`, `ToolExecutor` stages the invocation if the tool declares `action_kind`. Staging uses `PendingActionHelper::stage()`.

```php
$staged = PendingActionHelper::stage( array(
    'kind'         => 'socials_publish_instagram',
    'summary'      => 'Publish Instagram post: Spring menu is live',
    'apply_input'  => $complete_parameters,
    'preview_data' => array(
        'caption' => $complete_parameters['caption'] ?? '',
    ),
    'agent_id'     => $agent_id,
    'context'      => array(
        'mode'      => 'chat',
        'tool_name' => 'publish_instagram',
        'session_id' => $session_id,
    ),
) );
```

The result is an Agents API `approval_required` message with:

| Field | Purpose |
|---|---|
| `payload.pending_action.action_id` | Identifier to resolve later. |
| `payload.pending_action.summary` | Human-readable preview summary. |
| `payload.pending_action.preview` | Renderable preview data. |
| `payload.resolve_with` | Chat tool name to call for resolution, currently `resolve_pending_action`, backed by `agents/resolve-pending-action`. |
| `payload.resolve_params` | Required resolution arguments. |
| top-level `staged` and `action_id` | Data Machine compatibility fields for internal callers. |

Tools should not hand-roll this envelope. Use `PendingActionHelper` so the store shape, approval message, and compatibility fields stay aligned.

## Post-Origin Tracking

After direct execution succeeds, `ToolExecutor` calls `PostTracking::extractPostId()` and records origin metadata for results that created or modified a WordPress post. This applies to handler tools and ability-only tools when their result exposes an extractable post ID.

## Error Shapes

Common normalized failures:

```php
array(
    'success'   => false,
    'error'     => "Tool 'my_tool' not found",
    'tool_name' => 'my_tool',
)
```

```php
array(
    'success'       => false,
    'error'         => 'Tool "publish_instagram" is not permitted in the current context (action_policy=forbidden).',
    'tool_name'     => 'publish_instagram',
    'action_policy' => 'forbidden',
)
```

Ability-only failures include the linked `ability` slug when ability registration, permission, or execution fails.

## Related Docs

- [Tool Manager](tool-manager.md)
- [Policy Resolvers](../architecture/policy-resolvers.md)
- [AI Conversation Loop](ai-conversation-loop.md)
- [Tool Result Finder](tool-result-finder.md)
