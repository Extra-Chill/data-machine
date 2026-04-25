# AI Directives System

Data Machine uses a hierarchical directive system to inject contextual information into AI requests. Directives self-register via the `datamachine_directives` filter, are sorted by priority, filtered by mode, validated, and rendered into system messages before the conversation messages.

## Directive Architecture

All directives implement `DirectiveInterface`, which defines a single static method:

```php
public static function get_outputs(
    string $provider_name,
    array $tools,
    ?string $step_id = null,
    array $payload = array()
): array;
```

Each directive self-registers via the `datamachine_directives` filter, appending an array with `class`, `priority`, and `modes` keys.

### Output Types

Directive outputs are validated by `DirectiveOutputValidator`:

- **`system_text`** — requires `content` (string). Rendered as `{role: 'system', content: ...}`.
- **`system_json`** — requires `label` (string) and `data` (array). Rendered as `{role: 'system', content: "LABEL:\n\n{json}"}`.
- **`system_file`** — requires `file_path` and `mime_type`. Rendered as a file attachment in the system message.

### Modes

Each registered directive declares which agent **modes** it applies to via the `modes` array. The current built-in modes are `chat`, `pipeline`, and `system`. The literal string `all` matches every mode.

> Historical note: prior to v0.71.0 this field was named `contexts`. The internal terminology was renamed during the AgentMode refactor (#1130). RequestBuilder reads `$directive['modes']`; older `'contexts' =>` registrations are silently treated as `all` because they don't match the canonical key.

### Priority System

Directives are applied in ascending priority order (lowest number = highest priority).

| Priority | Directive | Modes | Source | Purpose |
|----------|-----------|-------|--------|---------|
| **20** | `CoreMemoryFilesDirective` | all | `inc/Engine/AI/Directives/CoreMemoryFilesDirective.php` | SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom registered files |
| **22** | `AgentModeDirective` | all | `inc/Engine/AI/Directives/AgentModeDirective.php` | Mode-specific guidance (chat / pipeline / system) injected as runtime directive |
| **25** | `CallerContextDirective` | all (cross-site only) | `inc/Engine/AI/Directives/CallerContextDirective.php` | Authenticated A2A caller identity (peer agent slug, host, chain depth) |
| **35** | `AgentDailyMemoryDirective` | chat, pipeline | `inc/Engine/AI/Directives/AgentDailyMemoryDirective.php` | Recent daily archives for agents that opt in via `agent_config.daily_memory` |
| **35** | `ClientContextDirective` | all | `inc/Engine/AI/Directives/ClientContextDirective.php` | Free-form client-reported context (current screen, post being edited, etc.) |
| **40** | `PipelineMemoryFilesDirective` | pipeline | `inc/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php` | Per-pipeline selectable memory files |
| **45** | `ChatPipelinesDirective` | chat | `inc/Api/Chat/ChatPipelinesDirective.php` | Pipeline / flow / handler inventory for discovery |
| **45** | `FlowMemoryFilesDirective` | pipeline | `inc/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php` | Per-flow selectable memory files (additive to pipeline memory) |
| **50** | `PipelineSystemPromptDirective` | pipeline | `inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php` | User-configured task instructions + workflow visualization |

> Note: Tools are injected by `RequestBuilder` via `PromptBuilder::setTools()`, not as a directive class. The earlier `GlobalSystemPromptDirective`, `SiteContextDirective`, `PipelineCoreDirective`, `ChatAgentDirective`, and `SystemAgentDirective` classes were removed during the AgentMode refactor — their guidance now lives inline in `AgentModeDirective` and in agent memory files (SITE.md, SOUL.md, MEMORY.md).

## Individual Directives

### CoreMemoryFilesDirective (Priority 20)

**Source**: `inc/Engine/AI/Directives/CoreMemoryFilesDirective.php`
**Modes**: All
**Since**: 0.30.0

Reads core memory files from three directory layers and injects them as system messages:

**Site layer** (shared):
- `SITE.md` — Site identity and configuration
- `RULES.md` — Global rules and constraints

**Agent layer** (per-agent):
- `SOUL.md` — Agent personality and behavioral guidelines
- `MEMORY.md` — Agent long-term memory

**User layer** (per-user):
- `USER.md` — User-specific preferences and context

**Custom registered files** — Any additional files registered via `MemoryFileRegistry` are also loaded from the agent directory.

**Features**:
- Self-healing: calls `DirectoryManager::ensure_agent_files()` before reading.
- Three-layer directory resolution via `DirectoryManager`.
- File size warning logged when any file exceeds `AgentMemory::MAX_FILE_SIZE` (8KB).
- Empty files are silently skipped.

**Configuration**: Edit files via the Agent admin page file browser or REST API (`PUT /datamachine/v1/files/agent/{filename}`).

### AgentModeDirective (Priority 22)

**Source**: `inc/Engine/AI/Directives/AgentModeDirective.php`
**Modes**: All
**Since**: 0.68.0 (replaced per-agent context files in #1129/#1130)

Injects execution-mode guidance (chat, pipeline, system) as a runtime directive rather than per-agent disk files. Mode guidance is platform knowledge, not agent-specific state — shipping it as a directive removes per-agent disk clutter and enables hook-based composition.

**Built-in modes**:
- `chat` — Live chat session in the admin UI. Includes Data Machine architecture overview, configuration rules, scheduling rules, and execution protocol.
- `pipeline` — Automated pipeline step execution. Includes data packet structure and analysis-before-action principles.
- `system` — Background system task execution. Includes session title generation rules and "return only what's asked" behavior.

**Extension hook**: `datamachine_agent_mode_{slug}` — extensions can append or modify mode-specific guidance for a given mode (e.g. the editor plugin appends diff workflow instructions when the `editor` mode is active).

**Payload key**: Reads `agent_mode` from the request payload.

### CallerContextDirective (Priority 25)

**Source**: `inc/Engine/AI/Directives/CallerContextDirective.php`
**Modes**: All — but only emits output during cross-site A2A calls
**Since**: 0.72.0

Renders authenticated agent-to-agent caller identity from the four cross-site headers (`X-Datamachine-Caller-Site`, `-Caller-Agent`, `-Chain-Id`, `-Chain-Depth`). The data is server-validated — it cannot be spoofed by the client because the headers are read from the incoming HTTP request and validated by middleware.

When `PermissionHelper::in_cross_site_context()` returns false (local request, top of chain), the directive emits nothing. Distinct from `ClientContextDirective`: caller context is trusted server-side provenance; client context is untrusted frontend state.

### AgentDailyMemoryDirective (Priority 35)

**Source**: `inc/Engine/AI/Directives/AgentDailyMemoryDirective.php`
**Modes**: chat, pipeline
**Since**: 0.71.0

Injects recent daily archive files for agents that explicitly opt in. Replaces the former `DailyMemorySelectorDirective` + per-flow `flow_config.daily_memory` config.

**Opt-in shape (per agent, in `agent_config`):**

```json
{
  "daily_memory": {
    "enabled":     true,
    "recent_days": 3
  }
}
```

When disabled or absent the directive emits nothing — a stateless pipeline agent (alt-text generator, wiki builder) gets zero daily memory noise in its context. When enabled, the directive walks the real files on disk (`agents/{slug}/daily/YYYY/MM/DD.md`) from newest to oldest, injecting each as its own `system_text` block labelled `"## Daily Memory: YYYY-MM-DD"`.

**Features**:
- Opt-in per agent — default off for every agent.
- `recent_days` default 3, hard ceiling 14 (`MAX_RECENT_DAYS`).
- Size-bounded by `AgentMemory::MAX_FILE_SIZE` (8 KB); older days dropped first when the budget would be exceeded.
- One real file = one `system_text` block so the AI can distinguish dates rather than reasoning over a stitched-together blob.
- Precise historical lookups still available via the `agent_daily_memory` tool.

### ClientContextDirective (Priority 35)

**Source**: `inc/Engine/AI/Directives/ClientContextDirective.php`

Renders free-form context reported by the calling client. Examples:

- `{ "tab": "compose", "post_id": 123, "post_title": "My Draft" }` — Gutenberg sidebar
- `{ "screen": "socials", "platform": "instagram" }` — Socials admin page
- `{ "page": "forum", "topic_id": 42 }` — community page

The payload is **untrusted** — it represents what the frontend says it is showing. Pair it with `CallerContextDirective` (priority 25) when authoritative provenance matters.

### PipelineMemoryFilesDirective (Priority 40)

**Source**: `inc/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php`
**Modes**: Pipeline only

Reads the pipeline's `memory_files` configuration (an array of filenames) and injects each file's content from the agent directory as a system message prefixed with `## Memory File: {filename}`.

**Features**:
- Files sourced from the agent's memory directory (`wp-content/uploads/datamachine-files/agents/{agent_slug}/`).
- Missing files logged as warnings but don't fail the request.
- Empty files are silently skipped.
- Supports multi-agent partitioning via `user_id` and `agent_id` from payload.
- Uses the shared `MemoryFilesReader` helper.

**Configuration**: Select memory files per-pipeline via the "Agent Memory Files" section in the pipeline settings UI. SOUL.md is excluded from the picker — it's always injected by `CoreMemoryFilesDirective` (Priority 20).

### ChatPipelinesDirective (Priority 45)

**Source**: `inc/Api/Chat/ChatPipelinesDirective.php`
**Modes**: Chat only

Provides a lightweight JSON inventory of all pipelines, their steps, and flow summaries (active handlers), labeled `"DATAMACHINE PIPELINES INVENTORY"`.

**Context awareness**: When `selected_pipeline_id` is provided (e.g. from the Integrated Chat Sidebar), the agent prioritizes context for that specific pipeline.

### FlowMemoryFilesDirective (Priority 45)

**Source**: `inc/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php`
**Modes**: Pipeline only

Reads the flow's `memory_files` configuration and injects each file's content. Different flows on the same pipeline can reference different memory files.

**Features**:
- Additive to pipeline memory files (Priority 40), not a replacement.
- Uses the shared `MemoryFilesReader` helper.
- Supports multi-agent partitioning.

### PipelineSystemPromptDirective (Priority 50)

**Source**: `inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php`
**Modes**: Pipeline only

Two parts:

1. **Workflow visualization** — Compact string showing step sequence with "YOU ARE HERE" marker:
   ```
   WORKFLOW: REDDIT FETCH -> AI (YOU ARE HERE) -> WORDPRESS PUBLISH
   ```

2. **Pipeline goals** — The `system_prompt` text from the pipeline step configuration, prefixed with `"PIPELINE GOALS:\n"`.

**Features**:
- Returns empty if no `system_prompt` is configured.
- Provides spatial awareness (where in the pipeline the AI currently sits).
- Multi-handler steps shown as `"LABEL1+LABEL2 STEPTYPE"`.

## Directive Injection Process

### Request Flow

1. **Request Building**: `RequestBuilder` initiates AI request construction.
2. **Directive Collection**: Gathers all registered directives via `apply_filters('datamachine_directives', [])`.
3. **Priority Sorting**: `PromptBuilder` sorts directives by priority (ascending).
4. **Mode Filtering**: Only directives matching the current `mode` are applied (`'all'` matches everything).
5. **Output Generation**: Each directive's `get_outputs()` is called.
6. **Validation**: `DirectiveOutputValidator` ensures outputs follow expected schema.
7. **Rendering**: `DirectiveRenderer` converts validated outputs into `{role: 'system', content: ...}` messages.
8. **Final Request**: System messages are prepended before conversation messages.

### Mode-Specific Stacks

**Chat agents** receive (in order):

1. P20 — CoreMemoryFilesDirective
2. P22 — AgentModeDirective (chat guidance)
3. P25 — CallerContextDirective (cross-site only)
4. P35 — AgentDailyMemoryDirective (opt-in per agent)
5. P35 — ClientContextDirective
6. P45 — ChatPipelinesDirective
7. (Tools are injected separately by RequestBuilder)

**Pipeline agents** receive (in order):

1. P20 — CoreMemoryFilesDirective
2. P22 — AgentModeDirective (pipeline guidance)
3. P25 — CallerContextDirective (cross-site only)
4. P35 — AgentDailyMemoryDirective (opt-in per agent)
5. P35 — ClientContextDirective
6. P40 — PipelineMemoryFilesDirective
7. P45 — FlowMemoryFilesDirective
8. P50 — PipelineSystemPromptDirective

**System agents** receive (in order):

1. P20 — CoreMemoryFilesDirective
2. P22 — AgentModeDirective (system guidance)
3. P25 — CallerContextDirective (cross-site only)
4. P35 — ClientContextDirective

System agents do not receive `AgentDailyMemoryDirective` (its registered modes are `chat` and `pipeline` only) and do not receive any pipeline-only memory directives.

## Configuration & Extensibility

### Plugin Settings Integration

- **Agent memory files**: File-based in agent memory directory (migrated from `global_system_prompt`).
- **Pipeline memory files**: Per-pipeline `memory_files` array in pipeline config.
- **Flow memory files**: Per-flow `memory_files` array in flow config.
- **Daily memory**: Per-agent `agent_config.daily_memory = { enabled: bool, recent_days: int }`. Default disabled.

### Filter Hooks

**`datamachine_directives`** — Register a new directive:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'    => MyDirective::class,
        'priority' => 25,
        'modes'    => ['chat', 'pipeline'], // or ['all']
    ];
    return $directives;
});
```

**`datamachine_agent_mode_{slug}`** — Append or modify mode-specific guidance:

```php
add_filter('datamachine_agent_mode_chat', function($content, $payload) {
    return $content . "\n\n# Plugin extension\n\nExtra rules for this site.";
}, 10, 2);
```

## Performance Considerations

### Caching Strategy

- **Memory files**: Read from filesystem on each request (no caching).
- **Pipeline inventory**: Queried from database on each chat request.

## Support Infrastructure

| File | Purpose |
|------|---------|
| `DirectiveInterface.php` | Contract: `get_outputs()` static method |
| `DirectiveOutputValidator.php` | Validates output arrays per type requirements |
| `DirectiveRenderer.php` | Converts validated outputs to `{role: 'system', content: ...}` messages |
| `MemoryFilesReader.php` | Shared helper for reading memory files from agent directory |
| `MemoryFileRegistry.php` | Static registry for custom memory file registration |
| `PromptBuilder.php` | Sorts, filters, calls, validates, renders directives |
| `RequestBuilder.php` | Entry point: collects directives, feeds into PromptBuilder, sends request |

## Debugging & Monitoring

### Logging Integration

Directives integrate with the Data Machine logging system:

```php
do_action('datamachine_log', 'debug', 'Directive: Memory files injected', [
    'pipeline_id' => $pipeline_id,
    'file_count'  => count($files),
]);
```

### Error Handling

- Empty content detection and logging.
- Graceful degradation when optional features fail.
- File size warnings for oversized memory files.
