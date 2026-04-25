# Policy Resolvers

Data Machine has four policy resolvers — small classes that read per-agent declarative configuration and answer one specific question about how the agent should run. They look uniform from a docblock distance and divergent up close. This doc names the convention they share, the shapes they don't share, and how to add a fifth without breaking the pattern.

The four resolvers:

1. **`ToolPolicyResolver`** — *which* tools the agent can see.
2. **`MemoryPolicyResolver`** — *which* memory files inject into the prompt.
3. **`ActionPolicyResolver`** — *how* a single tool invocation executes (direct / preview / forbidden).
4. **`PipelineTranscriptPolicy`** — *whether* the AI conversation transcript is persisted.

All four read from `agent_config` on the agent row. All four use the same precedence ladder shape. None of them share a return type, and that's deliberate.

## Why this isn't a base class

Each resolver answers a structurally different question:

| Resolver | Question | Returns |
|---|---|---|
| `ToolPolicyResolver` | Which tools are visible? | `array<tool_name, tool_def>` (filtered set) |
| `MemoryPolicyResolver` | Which memory files inject? | `array<filename, metadata>` (filtered set) |
| `ActionPolicyResolver` | How does this invocation execute? | `string` — one of `direct` / `preview` / `forbidden` |
| `PipelineTranscriptPolicy` | Persist this run's transcript? | `bool` |

The method names give it away: `resolve()`, `resolveRegistered()`, `resolveForTool()`, `shouldPersist()`. Forcing all four through `AbstractAgentPolicy::resolve(): array` would either pick a useless lowest-common-denominator return, require generics PHP can't enforce at runtime, or distort `ActionPolicyResolver` and `PipelineTranscriptPolicy` into set-filter shapes they shouldn't be.

What the four resolvers share is **a convention** — per-agent declarative config with layered precedence and a final filter hook. Conventions are documented; identities are inherited. These are not the same kind of thing.

## The convention

A policy resolver in Data Machine has six properties. Each property is reproduced explicitly in each resolver, not factored out into a parent class.

### 1. Per-agent config under a named key

The policy lives in the `agent_config` JSON blob on the agent row, under a key named `<thing>_policy`:

```php
$config = $agent['agent_config'] ?? array();
$policy = $config['tool_policy']      ?? null; // ToolPolicyResolver
$policy = $config['memory_policy']    ?? null; // MemoryPolicyResolver
$policy = $config['action_policy']    ?? null; // ActionPolicyResolver
// PipelineTranscriptPolicy reads pipeline_config / flow_config keys instead
// of agent_config — see "When to bend the convention" below.
```

The reader is a method on the resolver itself (`getAgentToolPolicy()`, `getAgentMemoryPolicy()`, `getAgentActionPolicy()`), not a shared utility. Each reader validates the policy shape and normalizes it before returning.

### 2. Null = no-op

`getAgent*Policy()` returns `null` when:

- The agent doesn't exist.
- The config key is missing or not an array.
- The policy is structurally invalid (unknown mode, wrong shape).
- The policy is a recognized but effectively-empty no-op (e.g. `mode=deny` with empty deny list).

The caller short-circuits on `null` instead of running an empty filter pass. This is a small but real perf and clarity win — the hot path stays cheap when no policy is configured.

### 3. Layered precedence, highest first

Every resolver runs the same shape of ladder. Higher rules override lower ones. The exact rungs differ per resolver, but the order is always: explicit context → per-agent → category → tool/file default → mode preset → global default → final filter.

`ToolPolicyResolver`:

```
1. Explicit deny list (always wins)
2. Per-agent tool policy (deny/allow mode, supports categories)
3. Ability category filter (narrows by linked ability category)
4. Context-level allow_only (narrows to explicit subset)
5. Context preset (pipeline / chat / system)
6. Global enablement settings
7. Tool configuration requirements
8. apply_filters('datamachine_resolved_tools', ...)
```

`MemoryPolicyResolver`:

```
1. Explicit deny list passed in context (always wins)
2. Per-agent policy deny list (from agent_config.memory_policy)
3. Per-agent policy allow-only (narrows to subset)
4. Context-level allow_only (narrows to subset)
5. Mode preset (registry's get_for_mode)
6. apply_filters('datamachine_resolved_memory_files', ...)
```

`ActionPolicyResolver`:

```
1. Explicit deny list (any listed tool → 'forbidden')
2. Per-agent action_policy.tools[<tool_name>] override
3. Per-agent action_policy.categories[<category>] override
4. Tool-declared default (tool_def['action_policy'])
5. Mode preset (chat → 'preview' for publish-family, else 'direct')
6. Global default: 'direct'
7. apply_filters('datamachine_tool_action_policy', ...)
```

`PipelineTranscriptPolicy::shouldPersist()`:

```
1. flow.flow_config['persist_transcripts']
2. pipeline.pipeline_config['persist_transcripts']
3. get_option('datamachine_persist_pipeline_transcripts', false)
   (no per-tool / per-category / per-agent layer in v1)
```

The ladders look similar because they are similar. They are not identical, and the differences matter — `PipelineTranscriptPolicy` has no per-agent layer because v1 is scoped to pipelines (see #1226 for the cross-mode generalization that adds one). Don't try to unify the ladder shapes; document each resolver's ladder in its class docblock and keep them visible.

### 4. Categories follow ability linkage

Resolvers that gate by category (`ToolPolicyResolver`, `ActionPolicyResolver`) walk a tool's `ability` and `abilities` keys, look up each slug in `WP_Abilities_Registry::get_instance()`, and read `get_category()` on the ability. A tool's category is whatever its linked ability declares — there is no separate "tool category" registry.

Tools without a linked ability are excluded when category filtering is active (they cannot be categorized) unless explicitly allow-listed. Handler tools (those with a `handler` key but no `ability` key) bypass category filtering — they are dynamically scoped by the pipeline engine to adjacent step handlers.

### 5. Mode-aware (`pipeline` / `chat` / `system`)

All four resolvers know about agent modes. The preset constants are duplicated across the resolvers on purpose:

```php
public const MODE_PIPELINE = 'pipeline';
public const MODE_CHAT     = 'chat';
public const MODE_SYSTEM   = 'system';
```

Each resolver applies the mode where it makes sense:

- `ToolPolicyResolver` filters tools by their declared `modes` array.
- `MemoryPolicyResolver` calls `MemoryFileRegistry::get_for_mode()`.
- `ActionPolicyResolver` defaults publish-family tools to `preview` in `chat` and `direct` in `pipeline` / `system`.
- `PipelineTranscriptPolicy` is currently pipeline-only; #1226 generalizes it across modes.

Custom mode slugs are allowed. Plugins can register tools / files / policies for arbitrary modes; the resolvers route them through the same paths.

### 6. Final `apply_filters()` hook

Every resolver ends with a `apply_filters()` call so plugins can override the resolved value without subclassing or replacing the resolver. The filter is the public extension point for third parties. The filter names are:

- `datamachine_resolved_tools`
- `datamachine_resolved_memory_files`
- `datamachine_resolved_scoped_memory_files` (pipeline/flow-scoped path)
- `datamachine_tool_action_policy`

A new resolver follows suit: register a filter named `datamachine_resolved_<thing>` (or similar) and run it as the last step of `resolve()`. This is how DM stays generic — the filter is the only thing third parties have to know about.

## What divergence looks like in practice

Each resolver invented something the others don't have. None of those inventions are wrong — they're shaped to the question.

`ToolPolicyResolver` has:

- A `gatherByMode()` step that builds the tool pool differently for `pipeline` (handler tools from adjacent steps) vs other modes (registry filter by declared `modes`).
- An ability-permission gate (`filterByAbilityPermissions()`) that runs only in `chat` mode.
- An `access_level` fallback (`public` / `authenticated` / `author` / `editor` / `admin`) for tools without a linked ability.
- A category-aware `applyAgentPolicy()` that supports `tools[]` and `categories[]` composing in either deny or allow mode.

`MemoryPolicyResolver` has:

- Two entry points — `resolveRegistered()` for the core memory file registry and `filter()` for explicit filename lists from `pipeline_config` / `flow_config`. Both share the per-agent policy reader; their precedence ladders diverge slightly.
- A `default` mode that's a no-op (returned as `null` by the reader).
- File metadata preservation through filtering so downstream readers see the same `layer` / `priority` / `path` keys after policy is applied.

`ActionPolicyResolver` has:

- Three policy values, not a set: `direct` / `preview` / `forbidden`.
- Tool-declared defaults via `tool_def['action_policy']` and per-mode overrides via `tool_def['action_policy_<mode>']`.
- A normalization step that returns `''` for unrecognized values so callers can drop them safely.
- A subtle interaction at step 4: the mode preset can *upgrade* a `direct` tool default to `preview` in chat mode if the tool opts in via `action_policy_chat`, but the tool default still wins for everything else. This is documented in `resolveForTool()` and worth preserving when the resolver evolves.

`PipelineTranscriptPolicy` has:

- One method (`shouldPersist()`) returning a `bool`.
- Reads from flow + pipeline config snapshots already in memory (zero extra DB calls).
- No per-agent layer in v1. No category. No tool-level opt-in. The transcript is persisted for the *whole step*, not per-invocation, so per-tool granularity isn't meaningful here.

A future generalization (#1226) plausibly adds a `mode` argument and per-agent override but should *not* try to inherit the precedence ladder of any of the other three. Its question is yes/no, and the answer for a yes/no question lives in a different shape from the answer for "filter this set."

## When to bend the convention

The convention is a guide, not a contract. Two existing resolvers already bend it:

- **`PipelineTranscriptPolicy`** reads `pipeline_config` and `flow_config`, not `agent_config`. The reason is that transcript persistence is a *flow-level* concern (different flows on the same agent should be able to opt in independently), and the resolution order is flow > pipeline > site option, not agent > anything. When the question's natural locus isn't the agent, store the policy where it actually belongs and document the deviation in the class docblock.
- **`ActionPolicyResolver`** allows tools to declare per-mode defaults via `action_policy_chat` / `action_policy_pipeline` / `action_policy_system`. The other resolvers don't have an equivalent — tools don't declare per-mode visibility (`ToolPolicyResolver` reads `modes` on the tool def, but that's a static list, not per-mode behavior). When a tool's default behavior naturally varies by mode, `tool_def[<key>_<mode>]` is the right shape.

Both deviations are documented in the resolver's class docblock. New resolvers should do the same: name what's different from the other three and why.

## Adding a fifth resolver

When a new policy question arises (directive suppression, transcript-across-modes, retention overrides, anything), the recipe is:

1. **Pick the locus.** Where does the policy live? Agent config? Pipeline config? Flow config? Site option? More than one with a precedence ladder? The locus determines the reader.
2. **Pick the shape of the answer.** Filtered set? Scalar enum? Boolean? Per-invocation classifier? Don't force it to match an existing resolver.
3. **Write a single-purpose class** in `inc/Engine/AI/<Thing>/<Thing>Policy[Resolver].php` (the `Resolver` suffix is dropped for boolean-toggle resolvers like `PipelineTranscriptPolicy` — it would read awkwardly).
4. **Write the precedence ladder in the class docblock** before writing code. Higher rules override lower. Document each rung.
5. **Implement the reader** as a method on the class — `getAgent<Thing>Policy()` if agent-scoped, or whatever name fits the locus. Return `null` for no-op.
6. **Implement the resolver method** with a clear name — the verb is the question. `shouldPersist()`, `resolveForTool()`, `resolve()` for set filters.
7. **Register a final `datamachine_resolved_<thing>` filter** as the last step.
8. **Call from one place** — directly from the call site that consumes the answer. Don't build a generic dispatcher unless multiple call sites would loop over registered policies (none currently do).

Resist these temptations:

- ❌ Extracting `AbstractAgentPolicy` / a base class. The resolvers don't share a return type. Inheritance enforces uniformity that the problems reject.
- ❌ Building a "policy registry" or "policy dispatcher" before any consumer iterates over policies generically. Each call site knows which policy it cares about and calls it directly.
- ❌ Forcing the new resolver's ladder shape to match an existing one. The shape should follow the question.
- ❌ Reading from `agent_config` reflexively. If the natural locus is somewhere else, store it somewhere else.

If two resolvers later turn out to share *both* the same return shape *and* the same input shape *and* are consumed polymorphically by some new system that loops over registered policies — *then* extract a trait. Even then, prefer a trait (mixin-style, additive) over a base class (locks the hierarchy).

## Where the resolvers live

```
inc/Engine/AI/Tools/ToolPolicyResolver.php
inc/Engine/AI/Memory/MemoryPolicyResolver.php
inc/Engine/AI/Actions/ActionPolicyResolver.php
inc/Engine/AI/Transcripts/PipelineTranscriptPolicy.php
```

Each is a single file, single class, single responsibility. Class docblocks carry the precedence ladder. Class methods carry the per-step rationale. There is no shared parent, no shared interface, and no shared trait — only a shared shape that's documented here.

## Related issues

- #1101 — DirectivePolicy exploration. Names the "abstraction-on-N=2 risk" trap that this doc encodes at N=4. Keep the warning live; it applies to every Nth resolver.
- #1226 — generalize `PipelineTranscriptPolicy` across agent modes. The PR for that issue is the next chance to set the cross-mode pattern; this doc is the design context for that work.
- #972 — refactor chat tool policy for per-user dynamic toolsets. Belongs to `ToolPolicyResolver`'s evolution; doesn't touch the convention.
