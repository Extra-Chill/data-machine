# Policy Resolvers

Data Machine uses small policy resolvers instead of one shared policy base class. Each resolver reads declarative configuration near the thing it governs, normalizes that policy, and answers one runtime question.

| Resolver | Question | Primary input | Return shape |
|---|---|---|---|
| `ToolPolicyResolver` | Which tools can this request see? | Tool sources, request args, `agent_config.tool_policy` | `array<tool_name, tool_def>` |
| `MemoryPolicyResolver` | Which memory files inject? | Memory registry/config, `agent_config.memory_policy` | `array<filename, metadata>` |
| `DirectivePolicyResolver` | Which directives remain in the prompt stack? | Registered directives, `agent_config.directive_policy` | `array{directives: array, suppressed: array}` |
| `ActionPolicyResolver` | How may one tool invocation execute? | Tool definition, mode, `agent_config.action_policy` | `direct`, `preview`, or `forbidden` |
| `PipelineTranscriptPolicy` | Should the pipeline transcript persist? | Flow config, pipeline config, site option | `bool` |

The shared convention is policy normalization plus explicit precedence. The classes do not share a parent because their return values and call sites are intentionally different.

## Tool Policy Resolver

`ToolPolicyResolver` is the single entry point for runtime tool visibility.

```php
$tools = ( new ToolPolicyResolver() )->resolve( array(
    'mode'       => ToolPolicyResolver::MODE_CHAT,
    'agent_id'   => $agent_id,
    'deny'       => array( 'dangerous_tool' ),
    'allow_only' => array( 'safe_tool' ),
    'categories' => array( 'content' ),
) );
```

Current implementation shape:

```text
ToolPolicyResolver::resolve()
    |
    +--> chat gate via DataMachineToolAccessPolicy::passesChatGate()
    |
    +--> ToolSourceRegistry::gather()
    |       - adjacent_handlers in pipeline mode
    |       - static_registry in every mode
    |
    +--> WP_Agent_Tool_Policy::resolve()
    |       - mandatory Data Machine tools are preserved
    |       - generic allow/deny/category/mode filtering
    |       - chat ability/access checks when applicable
    |
    +--> apply_filters( 'datamachine_resolved_tools', ... )
```

Resolution concepts:

| Layer | Meaning |
|---|---|
| Explicit `deny` | Highest-precedence removal list. Pipeline `disabled_tools` becomes this arg. |
| Per-agent `tool_policy` | Read from `agent_config.tool_policy` through `DataMachineAgentToolPolicyProvider`. |
| Categories | Resolved through linked WordPress Abilities, not a separate Data Machine tool category registry. |
| `allow_only` | Narrows optional/static tools. Pipeline `enabled_tools` becomes this arg. |
| Mode preset | Tool definitions declare `modes`; source registry gathers sources by mode. |
| Global/config checks | `DataMachineToolRegistrySource` calls `ToolManager::is_tool_available()` and `is_globally_enabled()`. |
| Final filter | `datamachine_resolved_tools`. |

Adjacent handler tools are different from optional/static tools. They come from the previous and next pipeline steps and represent the workflow's required flow plumbing. They are gathered by `AdjacentHandlerToolSource` before generic policy filtering and are protected by Data Machine's mandatory-tool policy where appropriate. A required adjacent publish/upsert handler with no callable AI tool fails before the model call; request inspection reports the same missing-handler state.

### Pipeline Policy-Gated Tools

Some tools are safe for chat but too powerful for default pipeline exposure. They declare `pipeline_policy` mode instead of `pipeline` mode.

```php
'modes' => array( 'chat', ToolPolicyResolver::MODE_PIPELINE_POLICY )
```

`DataMachineToolRegistrySource` includes such a tool in a pipeline request only when `ToolPolicyResolver::isPipelinePolicyToolAllowed()` sees the tool name in `allow_only`. In practice, that means the flow-step snapshot's `enabled_tools` list opted in.

### Pipeline Disabled Tools

`PipelineToolPolicyArgs::fromConfigs()` converts snapshot config into resolver args:

| Snapshot config | Resolver arg |
|---|---|
| `flow_step_config.enabled_tools` | `allow_only` |
| `pipeline_step_config.disabled_tools` | `deny` |
| `flow_step_config.disabled_tools` | `deny` |

This keeps direct workflows and historical job runs deterministic. Do not re-read persisted pipeline rows while resolving runtime policy.

## Tool Source Registry

`ToolSourceRegistry` is a composition seam, not a policy resolver. It asks named sources for candidate tools before `ToolPolicyResolver` applies policy.

Default source order:

| Mode | Sources |
|---|---|
| `pipeline` | `adjacent_handlers`, then `static_registry` |
| `chat` | `static_registry` |
| `system` | `static_registry` |
| custom mode | `static_registry` unless `agents_api_tool_sources_for_mode` changes it |

Extension hooks:

| Hook | Purpose |
|---|---|
| `agents_api_tool_sources` | Register source callbacks keyed by source slug. |
| `agents_api_tool_sources_for_mode` | Select source slugs for a mode. |

Data Machine's `datamachine_tools` filter remains the product registry adapted by `DataMachineToolRegistrySource`. The old source filters with Data Machine-specific names are not mirrored.

## Directive Policy Resolver

`DirectivePolicyResolver` applies per-agent directive suppression after directives are registered and before prompt assembly continues.

Policy shape:

```json
{
  "directive_policy": {
    "mode": "deny",
    "deny": ["CoreMemoryFilesDirective"],
    "modes": ["pipeline"]
  }
}
```

Supported modes are:

| Policy mode | Effect |
|---|---|
| `default` | No-op. |
| `deny` | Suppress matching directive classes. |
| `allow_only` | Keep only matching directive classes. |

Policy entries match either the fully-qualified class name or the short class name. The optional `modes` list scopes the directive policy to request modes. The resolver returns both the filtered directive list and the suppressed short names for observability.

Final filter: `datamachine_resolved_directives`.

## Action Policy Resolver

`ActionPolicyResolver` answers a different question from tool policy. Tool policy decides whether the model can see a tool. Action policy decides what happens after the model calls it.

Values use the Agents API vocabulary:

| Value | Meaning |
|---|---|
| `direct` | Execute immediately. |
| `preview` | Stage through `PendingActionHelper` and return an approval-required envelope. |
| `forbidden` | Refuse the invocation. |

Resolution order:

1. Explicit `deny` context list.
2. Per-agent `agent_config.action_policy.tools[tool_name]`.
3. Per-agent `agent_config.action_policy.categories[category]`.
4. Tool-declared default, including per-mode keys such as `action_policy_chat`.
5. Mode provider default from `DataMachineModeActionPolicyProvider`.
6. Agents API global default.
7. `datamachine_tool_action_policy` filter.

`ToolExecutor` enforces the result. `preview` requires the tool definition to declare `action_kind`; otherwise Data Machine logs a warning and falls back to direct execution because it cannot safely synthesize replay semantics.

## Pending Action Helper

`PendingActionHelper` is the staging helper used when action policy resolves to `preview`. It writes a pending action via `PendingActionStore`, fires `datamachine_pending_action_staged`, and returns an Agents API `approval_required` message.

The approval envelope includes `pending_action`, `resolve_with`, `resolve_params`, and user-facing instructions. It also carries top-level `staged` and `action_id` compatibility fields for internal callers.

Tools should provide self-contained `apply_input`. The accepted/rejected resolution path replays the stored input through the registered pending action handler, so preview staging must not depend on transient local variables.

## Memory and Transcript Policies

`MemoryPolicyResolver` keeps the same convention but filters memory file sets rather than tools. It has two paths: registered memory files for a mode and explicit memory lists from pipeline/flow configuration.

`PipelineTranscriptPolicy` bends the convention because transcript persistence is scoped to a pipeline run, not to an individual agent tool or memory file. It reads flow config, then pipeline config, then the site option.

## Adding Another Resolver

Use this pattern when adding a new policy question:

1. Pick the locus: agent config, flow config, pipeline config, site option, or request context.
2. Pick the return shape that answers the question directly.
3. Normalize invalid or no-op policy to `null` where possible.
4. Write the precedence order in the class docblock before implementing it.
5. Keep the public method named after the question, such as `resolve()`, `resolveForTool()`, or `shouldPersist()`.
6. Add a final filter for extension points.
7. Call the resolver from the one runtime path that consumes the answer.

Avoid a shared base class unless a real consumer needs to handle multiple policies polymorphically and they share both input and output shapes. That is not true for the current resolvers.

## Files

```text
inc/Engine/AI/Tools/ToolPolicyResolver.php
inc/Engine/AI/Tools/ToolSourceRegistry.php
inc/Engine/AI/Memory/MemoryPolicyResolver.php
inc/Engine/AI/Directives/DirectivePolicyResolver.php
inc/Engine/AI/Actions/ActionPolicyResolver.php
inc/Engine/AI/Actions/PendingActionHelper.php
inc/Engine/AI/PipelineTranscriptPolicy.php
inc/Core/Steps/AI/ToolPolicy/PipelineToolPolicyArgs.php
```

## Related Docs

- [Tool Manager](../core-system/tool-manager.md)
- [Tool Execution Architecture](../core-system/tool-execution.md)
- [Memory Policy](../core-system/memory-policy.md)
