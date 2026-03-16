# AI Directives System

Data Machine uses a hierarchical directive system to provide contextual information to AI agents during conversation and workflow execution. Directives are injected into AI requests in priority order, ensuring consistent behavior and context across all interactions.

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

Each directive self-registers via the `datamachine_directives` WordPress filter, appending an array with `class`, `priority`, and `contexts` keys.

### Output Types

Directive outputs are validated by `DirectiveOutputValidator`:

- **`system_text`** — requires `content` (string). Rendered as `{role: 'system', content: ...}`.
- **`system_json`** — requires `label` (string) and `data` (array). Rendered as `{role: 'system', content: "LABEL:\n\n{json}"}`.
- **`system_file`** — requires `file_path` and `mime_type`. Rendered as file attachment in system message.

### Priority System

Directives are applied in ascending priority order (lowest number = highest priority):

| Priority | Directive | Contexts | Purpose |
|----------|-----------|----------|---------|
| **10** | `PipelineCoreDirective` | pipeline | Pipeline agent identity and operational principles |
| **15** | `ChatAgentDirective` | chat | Chat agent identity and behavioral instructions |
| **20** | `SystemAgentDirective` | system | System agent identity and capabilities |
| **20** | `CoreMemoryFilesDirective` | **all** | SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom files |
| **40** | `PipelineMemoryFilesDirective` | pipeline | Per-pipeline selectable memory files |
| **45** | `ChatPipelinesDirective` | chat | Pipeline/flow/handler inventory for discovery |
| **45** | `FlowMemoryFilesDirective` | pipeline | Per-flow selectable memory files (additive) |
| **46** | `DailyMemorySelectorDirective` | pipeline | Daily memory files by selection mode |
| **50** | `PipelineSystemPromptDirective` | pipeline | User-configured task instructions + workflow visualization |
| **80** | `SiteContextDirective` | **all** | WordPress site metadata (post types, taxonomies, etc.) |

**Note**: Tools are injected by `RequestBuilder` via `PromptBuilder::setTools()`, not as a directive class.

## Individual Directives

### PipelineCoreDirective (Priority 10)

**Location**: `inc/Core/Steps/AI/Directives/PipelineCoreDirective.php`
**Contexts**: Pipeline only
**Purpose**: Establishes foundational agent identity for pipeline AI agents

Provides the static core directive covering:
- **Core Role** — Identifies the AI as a "content processing agent in the Data Machine WordPress plugin pipeline system"
- **Operational Principles** — Execute tasks systematically, use tools strategically, maintain pipeline consistency
- **Workflow Approach** — Analyze before acting; handler tools produce final results
- **Data Packet Structure** — Describes guaranteed JSON packet fields (`type`, `timestamp`)

### ChatAgentDirective (Priority 15)

**Location**: `inc/Api/Chat/ChatAgentDirective.php`
**Contexts**: Chat only
**Since**: 0.2.0
**Purpose**: Defines chat agent identity and capabilities

Provides comprehensive behavioral instructions for the chat interface:
- **Architecture** — Explains Handlers, Pipelines, Flows, and AI Steps concepts
- **Discovery** — Use `api_query` for detailed config; query existing flows before creating new ones
- **Configuration** — Only use documented handler_config fields; act first instead of asking
- **Scheduling** — Intervals only (daily, hourly), never specific times
- **Site Context** — Post types, taxonomy metadata, term management tools
- **Error Recovery** — Taxonomy of error types (`not_found`, `validation`, `permission`, `system`)

### SystemAgentDirective (Priority 20)

**Location**: `inc/Api/System/SystemAgentDirective.php`
**Contexts**: System only
**Since**: 0.13.7
**Purpose**: Defines system agent identity for internal operations

Covers:
- **Session Title Generation** — Rules for concise chat session titles (3-6 words, under 100 chars)
- **GitHub Issue Creation** — Instructions for clear titles, detailed bodies, labels, routing
- **Dynamic Repository Listing** — If `GitHubAbilities` exists, dynamically lists available repos at runtime
- **System Operations** — Execute with precision, log appropriately, handle errors gracefully

### CoreMemoryFilesDirective (Priority 20)

**Location**: `inc/Engine/AI/Directives/CoreMemoryFilesDirective.php`
**Contexts**: All
**Since**: 0.30.0
**Purpose**: Injects core memory files from the agent registry

Reads core memory files from three directory layers and injects them as system messages:

**Site Layer** (shared):
- `SITE.md` — Site identity and configuration
- `RULES.md` — Global rules and constraints

**Agent Layer** (per-agent):
- `SOUL.md` — Agent personality and behavioral guidelines
- `MEMORY.md` — Agent long-term memory

**User Layer** (per-user):
- `USER.md` — User-specific preferences and context
- `MEMORY.md` — User persistent memory

**Custom registered files** — Any additional files registered via `MemoryFileRegistry` are also loaded from the agent directory.

**Features**:
- Self-healing: calls `DirectoryManager::ensure_agent_files()` before reading
- Three-layer directory resolution via `DirectoryManager`
- File size warning: logs warning if any file exceeds `AgentMemory::MAX_FILE_SIZE` (8KB)
- Empty files are silently skipped

**Configuration**: Edit files via the Agent admin page file browser or REST API (`PUT /datamachine/v1/files/agent/{filename}`).

### PipelineMemoryFilesDirective (Priority 40)

**Location**: `inc/Core/Steps/AI/Directives/PipelineMemoryFilesDirective.php`
**Contexts**: Pipeline only
**Purpose**: Injects per-pipeline selected agent memory files

Reads the pipeline's `memory_files` configuration (an array of filenames) and injects each file's content from the agent directory as a system message prefixed with `## Memory File: {filename}`.

**Features**:
- Files sourced from the agent's memory directory (`wp-content/uploads/datamachine-files/agents/{agent_slug}/`)
- Missing files logged as warnings but don't fail the request
- Empty files are silently skipped
- Supports multi-agent partitioning via `user_id` and `agent_id` from payload
- Uses shared `MemoryFilesReader` helper

**Configuration**: Select memory files per-pipeline via the "Agent Memory Files" section in the pipeline settings UI. SOUL.md is excluded from the picker (it's always injected separately at Priority 20).

### ChatPipelinesDirective (Priority 45)

**Location**: `inc/Api/Chat/ChatPipelinesDirective.php`
**Contexts**: Chat only
**Purpose**: Injects pipeline inventory and flow summaries

Provides a lightweight JSON inventory of all pipelines, their steps, and flow summaries (active handlers), labeled as `"DATAMACHINE PIPELINES INVENTORY"`.

**Context Awareness**:
When `selected_pipeline_id` is provided (e.g., from the Integrated Chat Sidebar), the agent prioritizes context for that specific pipeline.

### FlowMemoryFilesDirective (Priority 45)

**Location**: `inc/Core/Steps/AI/Directives/FlowMemoryFilesDirective.php`
**Contexts**: Pipeline only
**Purpose**: Injects per-flow selected memory files (additive to pipeline memory files)

Reads the flow's `memory_files` configuration and injects each file's content. Different flows on the same pipeline can reference different memory files.

**Features**:
- Additive to pipeline memory files (Priority 40), not a replacement
- Uses shared `MemoryFilesReader` helper
- Supports multi-agent partitioning

### DailyMemorySelectorDirective (Priority 46)

**Location**: `inc/Engine/AI/Directives/DailyMemorySelectorDirective.php`
**Contexts**: Pipeline only
**Since**: 0.40.0
**Purpose**: Injects daily memory files based on flow-level configuration

Supports four selection modes configured per-flow:

| Mode | Config Fields | Behavior |
|------|--------------|----------|
| `recent_days` | `days` (int, default 7) | Last N days (capped at 90) |
| `specific_dates` | `dates` (string[]) | Specific YYYY-MM-DD dates |
| `date_range` | `from`, `to` (YYYY-MM-DD) | All daily files within range |
| `months` | `months` (string[], YYYY/MM) | All daily files for selected months |
| `none` | — | No daily memory injection (default) |

**Features**:
- Size limit: `MAX_TOTAL_SIZE = 100KB` — stops injecting when exceeded
- Newest-first ordering: most recent content prioritized within size cap
- Legacy compatibility: normalizes old `include_daily: true` to `{mode: 'recent_days', days: 7}`
- Each output formatted as `"## Daily Memory: {YYYY-MM-DD}\n{content}"`

### PipelineSystemPromptDirective (Priority 50)

**Location**: `inc/Core/Steps/AI/Directives/PipelineSystemPromptDirective.php`
**Contexts**: Pipeline only
**Purpose**: Injects user-configured pipeline system prompt with workflow visualization

Two parts:

1. **Workflow Visualization** — Compact string showing step sequence with "YOU ARE HERE" marker:
   ```
   WORKFLOW: REDDIT FETCH -> AI (YOU ARE HERE) -> WORDPRESS PUBLISH
   ```

2. **Pipeline Goals** — The `system_prompt` text from the pipeline step configuration, prefixed with `"PIPELINE GOALS:\n"`.

**Features**:
- Returns empty if no system_prompt is configured
- Provides spatial awareness (where in the pipeline the AI currently sits)
- Multi-handler steps shown as `"LABEL1+LABEL2 STEPTYPE"`

### SiteContextDirective (Priority 80)

**Location**: `inc/Engine/AI/Directives/SiteContextDirective.php`
**Contexts**: All agents
**Purpose**: Provides comprehensive WordPress site metadata

Injects structured JSON data about the WordPress site including post types, taxonomies, terms, and site configuration, labeled as `"WORDPRESS SITE CONTEXT"`. This is the final directive in the hierarchy.

**Features**:
- Cached site metadata for performance
- Automatic cache invalidation on content changes
- Toggleable via `site_context_enabled` setting
- Directive class swappable via `datamachine_site_context_directive` filter
- Extensible through `datamachine_site_context` filter

## Site Context Data Structure

The site context directive provides the following structured data:

```json
{
  "site": {
    "name": "Site Title",
    "tagline": "Site Description",
    "url": "https://example.com",
    "admin_url": "https://example.com/wp-admin",
    "language": "en_US",
    "timezone": "America/New_York"
  },
  "post_types": {
    "post": {
      "label": "Posts",
      "singular_label": "Post",
      "count": 150,
      "hierarchical": false
    }
  },
  "taxonomies": {
    "category": {
      "label": "Categories",
      "singular_label": "Category",
      "terms": {
        "news": 45,
        "updates": 23
      },
      "hierarchical": true,
      "post_types": ["post"]
    }
  }
}
```

## Directive Injection Process

### Request Flow

1. **Request Building**: `RequestBuilder` initiates AI request construction
2. **Directive Collection**: Gathers all registered directives via `apply_filters('datamachine_directives', [])`
3. **Priority Sorting**: `PromptBuilder` sorts directives by priority (ascending)
4. **Context Filtering**: Only directives matching current context are applied (`'all'` matches everything)
5. **Output Generation**: Each directive's `get_outputs()` is called
6. **Validation**: `DirectiveOutputValidator` ensures outputs follow expected schema
7. **Rendering**: `DirectiveRenderer` converts validated outputs to system messages
8. **Final Request**: System messages prepended before conversation messages

### Message Ordering

Directives maintain consistent message ordering by using `array_push()` to append system messages. This ensures:
- Core directives appear first
- Context accumulates predictably
- Site context appears last

## Context-Specific Directive Stacks

### Pipeline Agents

Receive directives in order:
1. P10 — PipelineCoreDirective (identity)
2. P20 — CoreMemoryFilesDirective (SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom)
3. P40 — PipelineMemoryFilesDirective (pipeline-selected memory files)
4. P45 — FlowMemoryFilesDirective (flow-selected memory files)
5. P46 — DailyMemorySelectorDirective (daily memory by mode)
6. P50 — PipelineSystemPromptDirective (task instructions + workflow viz)
7. P80 — SiteContextDirective (WordPress metadata)

### Chat Agents

Receive directives in order:
1. P15 — ChatAgentDirective (identity)
2. P20 — CoreMemoryFilesDirective (SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom)
3. P45 — ChatPipelinesDirective (pipeline inventory)
4. P80 — SiteContextDirective (WordPress metadata)

### System Agents

Receive directives in order:
1. P20 — SystemAgentDirective (identity)
2. P20 — CoreMemoryFilesDirective (SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom)
3. P80 — SiteContextDirective (WordPress metadata)

### Universal Directives

CoreMemoryFilesDirective (P20) and SiteContextDirective (P80) apply to all agent types, ensuring consistent behavior across the system.

## Configuration & Extensibility

### Plugin Settings Integration

Several directives integrate with plugin settings:

- **Agent Memory Files**: File-based in agent memory directory (migrated from `global_system_prompt`)
- **Pipeline Memory Files**: Per-pipeline `memory_files` array in pipeline config
- **Flow Memory Files**: Per-flow `memory_files` array in flow config
- **Daily Memory**: Per-flow `daily_memory_config` with mode and parameters
- **Site Context**: `site_context_enabled` toggle

### Filter Hooks

**`datamachine_directives`**: Register new directives
```php
$directives[] = [
    'class' => 'My\Directive\Class',
    'priority' => 25,
    'contexts' => ['chat', 'pipeline', 'all']
];
```

**`datamachine_site_context`**: Extend site context data
```php
add_filter('datamachine_site_context', function($context) {
    $context['custom_data'] = get_my_custom_data();
    return $context;
});
```

**`datamachine_site_context_directive`**: Override site context directive class
```php
add_filter('datamachine_site_context_directive', function($class) {
    return 'My\Custom\SiteContextDirective::class';
});
```

## Performance Considerations

### Caching Strategy

- **Site Context**: Cached with automatic invalidation on content changes
- **Memory Files**: Read from filesystem on each request (no caching)
- **Pipeline Inventory**: Queried from database on each chat request

### Cache Invalidation Triggers

Site context cache clears automatically when:
- Posts are created, updated, or deleted
- Terms are created, edited, or deleted
- Users are registered or deleted
- Theme is switched
- Site options (name, description, URL) change

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

All directives integrate with the Data Machine logging system:

```php
do_action('datamachine_log', 'debug', 'Directive: Context files injected', [
    'pipeline_id' => $pipeline_id,
    'file_count' => count($files)
]);
```

### Error Handling

Directives include comprehensive error handling:
- Empty content detection and logging
- Graceful degradation when optional features fail
- File size warnings for oversized memory files
