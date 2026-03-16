# AI Directive System

Data Machine uses a modular directive system to provide context and guidance to AI agents. These directives are combined to form the system prompt for every AI request.

## Architecture

The directive system is built on a modular architecture using the following core components:

- **DirectiveInterface**: Standard interface for all directive classes — defines `get_outputs()`.
- **PromptBuilder**: Unified manager that collects, sorts, filters, and renders directives for AI requests.
- **DirectiveRenderer**: Renders directive outputs into system messages (`{role: 'system', content: ...}`).
- **DirectiveOutputValidator**: Ensures directive output follows the expected schema (`system_text`, `system_json`, or `system_file`).

## Directive Priority & Layering

Directives are layered by priority (lowest number = highest priority) to create a cohesive context:

| Priority | Directive | Contexts | Purpose |
|----------|-----------|----------|---------|
| **10** | PipelineCoreDirective | pipeline | Pipeline agent identity and operational principles |
| **15** | ChatAgentDirective | chat | Chat agent identity and behavioral instructions |
| **20** | SystemAgentDirective | system | System agent identity and capabilities |
| **20** | CoreMemoryFilesDirective | **all** | SITE.md, RULES.md, SOUL.md, MEMORY.md, USER.md, custom files |
| **40** | PipelineMemoryFilesDirective | pipeline | Per-pipeline selectable memory files |
| **45** | ChatPipelinesDirective | chat | Pipeline/flow/handler inventory |
| **45** | FlowMemoryFilesDirective | pipeline | Per-flow selectable memory files (additive) |
| **46** | DailyMemorySelectorDirective | pipeline | Daily memory files by selection mode |
| **50** | PipelineSystemPromptDirective | pipeline | User-configured task instructions + workflow visualization |
| **80** | SiteContextDirective | **all** | WordPress site metadata |

**Note**: Tools are injected by `RequestBuilder` via `PromptBuilder::setTools()`, not as a directive class.

### ChatAgentDirective (Priority 15)
Specialized directive for the conversational chat interface. It instructs the agent on discovery and configuration patterns, emphasizing querying existing workflows before creating new ones. Includes error handling taxonomy and action-oriented behavioral guidelines.

### SystemAgentDirective (Priority 20)
Defines the system agent identity for internal operations — session title generation, GitHub issue creation, and system maintenance tasks. Dynamically lists available GitHub repos at runtime.

### CoreMemoryFilesDirective (Priority 20)
Reads memory files from three directory layers (site shared, per-agent, per-user) and injects them as system messages. Self-healing — creates missing agent files before reading. Applied to **all** agent types.

### PipelineMemoryFilesDirective (Priority 40)
Injects agent memory files selected for a specific pipeline. Files are stored in the agent directory and selected per-pipeline via the admin UI. SOUL.md is excluded (always injected separately at Priority 20). This enables pipelines to access strategy documents, reference material, or other persistent context.

### FlowMemoryFilesDirective (Priority 45)
Injects additional memory files configured per-flow (additive to pipeline memory files at P40). Different flows on the same pipeline can reference different memory files.

### DailyMemorySelectorDirective (Priority 46)
Injects daily memory files based on flow-level configuration. Supports four modes: `recent_days`, `specific_dates`, `date_range`, and `months`. Size-capped at 100KB with newest-first ordering.

### ChatPipelinesDirective (Priority 45)
Provides the conversational agent with a JSON inventory of available pipelines. When a pipeline is selected in the UI, `selected_pipeline_id` is used to prioritize and expand context for that specific pipeline, including its flow summaries and handler configurations.

### PipelineSystemPromptDirective (Priority 50)
Injects the user-configured pipeline system prompt along with a workflow visualization showing the step sequence and a "YOU ARE HERE" marker for spatial awareness.

### SiteContextDirective (Priority 80)
Injects comprehensive WordPress site metadata as JSON. Toggleable, cached, and filterable. Applied to **all** agent types as the final directive.

## Context-Specific Stacks

**Chat**: ChatAgentDirective → CoreMemoryFiles → ChatPipelines → SiteContext

**Pipeline**: PipelineCore → CoreMemoryFiles → PipelineMemoryFiles → FlowMemoryFiles → DailyMemorySelector → PipelineSystemPrompt → SiteContext

**System**: SystemAgent → CoreMemoryFiles → SiteContext

## Memory System Integration

The directive system integrates with Data Machine's file-based agent memory:

- **SITE.md** and **RULES.md** are always injected (Priority 20) from the shared directory
- **SOUL.md** and **MEMORY.md** are always injected (Priority 20) from the agent directory
- **USER.md** is always injected (Priority 20) from the user directory
- **Pipeline Memory Files** (Priority 40) are selectable per-pipeline via the admin UI
- **Flow Memory Files** (Priority 45) are selectable per-flow
- **Daily Memory** (Priority 46) is configurable per-flow with mode selection
- Files are stored in `{wp-content}/uploads/datamachine-files/{shared,agents,users}/`

## Registration

Directives are registered via WordPress filters:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => MyCustomDirective::class,
        'priority'    => 25,
        'contexts'    => ['pipeline', 'chat', 'all'],
    ];
    return $directives;
});
```

## Implementation Notes

- Directives should be read-only and never mutate the AI request structure directly.
- Use `DirectiveOutputValidator` to ensure responses from the AI follow the correct `system_text`, `system_json`, or `system_file` formats.
- Context injection should be minimal and focused on what the agent needs for the current task.
- See `docs/core-system/ai-directives.md` for detailed implementation reference including agent-specific behavior, caching strategy, and extensibility hooks.
