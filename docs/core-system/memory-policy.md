# Memory Policy

**MemoryPolicy** is a per-agent, declarative filter that controls which memory files inject into an AI call. It is the memory-side parallel to [ToolPolicy](./tool-manager.md), and it sits on top of the existing memory layers (registry, pipeline config, flow config) rather than replacing them.

## Why

Not every agent needs the full memory stack. A wiki generator agent's "memory" is the wiki it reads — it does not need `MEMORY.md` accumulating over time, and it does not need the operator's `USER.md` leaking into article output. Before MemoryPolicy, the only ways to suppress memory were to empty the file (fragile), fork the directive stack (heavy), or hope the AI ignored unhelpful context (it does not).

Two shapes of agent:

| Shape | Example | Memory role |
|---|---|---|
| Stateful | `chubes-bot`, a personal assistant | Memory is core to identity — default full injection is correct |
| Stateless | Wiki generator, SEO auditor, one-shot tools | Memory is token cost and context bleed — narrow or disable it |

MemoryPolicy lets the stateless shape declare its posture in config, travel with the agent through bundle export/import, and apply uniformly across every memory injection surface.

Daily memory has a separate opt-in switch at `agent_config.daily_memory`. MemoryPolicy controls registered and scoped memory files such as `MEMORY.md`, `SOUL.md`, `USER.md`, and configured context files; `AgentDailyMemoryDirective` separately decides whether recent daily archive files are injected at priority 35. Both settings travel in `agent_config` so bundles can preserve a stateful agent's memory posture without making daily memory a global default.

## How it fits

Data Machine already has a layered memory injection system. MemoryPolicy does not replace it — it is a subtractive filter that applies across all three existing layers.

```
MemoryFileRegistry                 CoreMemoryFilesDirective (p20)
  core files, contexts, layers  ─► ├── MemoryPolicyResolver::resolveRegistered()
                                   │   (applies agent policy to registered files)
                                   └── read via AgentMemory

pipeline_config.memory_files       PipelineMemoryFilesDirective (p40)
  explicit per-pipeline list    ─► ├── MemoryPolicyResolver::filter()
                                   │   (applies agent policy to scoped filenames)
                                   └── MemoryFilesReader::read()

flow_config.memory_files           FlowMemoryFilesDirective (p45)
  explicit per-flow list        ─► ├── MemoryPolicyResolver::filter()
                                   │   (applies agent policy to scoped filenames)
                                   └── MemoryFilesReader::read()
```

Every memory injection path now routes its candidate file list through the resolver before reading. The per-agent `memory_policy` applies consistently whether the file came from the registry, a pipeline config, or a flow config.

## Config shape

Stored at `agent_config.memory_policy` on the agent record. Travels in agent bundles automatically via `AgentBundler`.

```json
{
  "memory_policy": {
    "mode": "default"
  }
}
```

### Modes

| Mode | Behavior |
|---|---|
| `default` | No restriction. Current/legacy behavior. A missing `memory_policy` is equivalent. |
| `deny` | Every registered and scoped file is injected EXCEPT those in `deny`. |
| `allow_only` | Only files listed in `allow_only` are injected. Empty list means "nothing". |

### Deny example — wiki generator without personal memory

```json
{
  "memory_policy": {
    "mode": "deny",
    "deny": ["MEMORY.md", "USER.md"]
  }
}
```

The agent still gets `SOUL.md`, `SITE.md`, `RULES.md`, context files, and any pipeline/flow memory — but never the personal-memory layer.

### Allow-only example — minimal context agent

```json
{
  "memory_policy": {
    "mode": "allow_only",
    "allow_only": ["SOUL.md", "contexts/woocommerce-docs.md"]
  }
}
```

The agent sees only its identity and one domain context file. Everything else is suppressed.

## Resolution precedence

Highest to lowest:

1. **Explicit `deny` in the resolver call** — a directive or system caller can force-deny specific files for one invocation. Always wins.
2. **Per-agent `memory_policy` deny** — from `agent_config.memory_policy`.
3. **Per-agent `memory_policy` allow_only** — narrows to the agent's subset.
4. **Context-level `allow_only` in the resolver call** — a surface can further narrow.
5. **Context preset** — `MemoryFileRegistry::get_for_context( $context )` honors each file's `contexts` metadata.

A `default` mode short-circuits to null inside `getAgentMemoryPolicy()` so the filter pass is skipped entirely.

## API

### `resolveRegistered( array $context ): array`

Returns a `filename => metadata` map of registered files after policy is applied. Used by `CoreMemoryFilesDirective`.

```php
$resolver = new MemoryPolicyResolver();
$files    = $resolver->resolveRegistered( array(
    'mode'     => MemoryPolicyResolver::MODE_CHAT,
    'agent_id' => $agent_id,
) );
```

### `filter( array $filenames, array $context ): array`

Filters an explicit filename list through the policy. Used by `PipelineMemoryFilesDirective` and `FlowMemoryFilesDirective`.

```php
$resolver    = new MemoryPolicyResolver();
$memory_files = $resolver->filter( $memory_files, array(
    'agent_id' => $agent_id,
    'scope'    => 'pipeline',
) );
```

### `getAgentMemoryPolicy( int $agent_id ): ?array`

Reads and validates the policy from an agent's `agent_config`. Returns `null` for invalid, missing, or no-op (`mode=default` / `mode=deny` with empty list) policies.

## Extensibility

Two filters, one per resolution path:

- `datamachine_resolved_memory_files` — fires at the end of `resolveRegistered()`. Receives `( $files, $context_type, $context )`.
- `datamachine_resolved_scoped_memory_files` — fires at the end of `filter()`. Receives `( $filenames, $context )`.

Use these to inject cross-cutting policy logic (org-wide deny lists, environment-specific overrides, etc.) without touching `agent_config`.

## Context constants

Match `ToolPolicyResolver` for consistency across the policy layer:

```php
MemoryPolicyResolver::MODE_PIPELINE  // pipeline step AI execution
MemoryPolicyResolver::MODE_CHAT      // admin chat session
MemoryPolicyResolver::MODE_SYSTEM    // system task execution
```

Custom contexts (e.g. `'editor'`, `'automation'`) are supported everywhere the registry accepts them — the resolver just passes them through.

## Relationship to ToolPolicy

Parallel structure, parallel mental model:

| Concern | ToolPolicy | MemoryPolicy |
|---|---|---|
| What is scoped | Tools available to agent | Memory files injected into agent |
| Storage | `agent_config.tool_policy` | `agent_config.memory_policy` |
| Contexts | pipeline / chat / system | pipeline / chat / system |
| Modes | `deny`, `allow` | `default`, `deny`, `allow_only` |
| Resolver | `ToolPolicyResolver` | `MemoryPolicyResolver` |
| Export path | Travels via `agent_config` in `AgentBundler` | Travels via `agent_config` in `AgentBundler` |

Same precedence model, same extension points, same per-agent config home. An agent's portable definition bundles both policies together with its pipelines, flows, and identity files.

## Safe self-memory writes

The `datamachine/agent-memory-self-write` ability is narrower than general file editing. It exists so an acting agent can record operational lessons without silently rewriting durable knowledge or another agent's memory.

Policy gates in `SelfMemoryWritePolicy` are applied before `AgentMemory` writes:

- An active acting-agent context is required.
- The target defaults to the current agent; cross-agent writes require `datamachine_self_memory_allow_cross_agent_write` delegation.
- Durable fact section types (`fact`, `domain_fact`, `wiki_fact`, `knowledge`) are rejected. Those belong in wiki or graph tools.
- Allowed operational section types default to `operating_note`, `source_quirk`, `run_lesson`, and `task_note`, filterable through `datamachine_self_memory_allowed_section_types`.
- Bundle-owned memory sections are staged as PendingActions instead of overwritten directly.
- Sensitive section types or explicit `requires_approval` requests are also staged as PendingActions.

Section ownership is represented by `MemorySectionArtifact` with owners `bundle`, `user`, `runtime`, and `compaction`. Bundle-owned sections carry `bundle_slug`, `bundle_version`, `installed_hash`, `current_hash`, and `local_status`, so clean bundle sections can auto-update while modified sections are preserved for review.

## See also

- [WordPress as Agent Memory](./wordpress-as-agent-memory.md) — the full memory architecture
- [AI Directives](./ai-directives.md) — directive priorities and execution
- [Tool Manager](./tool-manager.md) — tool resolution and ToolPolicy reference
- [Multi-Agent Architecture](./multi-agent-architecture.md) — `agent_config` and per-agent settings
- [Import/Export](./import-export.md) — `AgentBundler` and bundle round-trips
- [Agent Bundles](./agent-bundles.md) — bundle artifact tracking, memory section artifacts, and upgrade conflict handling
- [Daily Memory System](./daily-memory-system.md) — daily archive storage, directive opt-in, and the `agent_daily_memory` tool
