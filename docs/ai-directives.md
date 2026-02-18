# AI Directive System

Data Machine uses a modular directive system to provide context and guidance to AI agents. These directives are combined to form the system prompt for every AI request.

## Architecture

The directive system is built on a modular architecture using the following core components:

- **DirectiveInterface**: Standard interface for all directive classes.
- **PromptBuilder**: Unified manager that collects and orders directives for AI requests.
- **DirectiveRenderer**: Renders directives into the final prompt structure.
- **DirectiveOutputValidator**: Ensures directive output follows the expected schema.

## Directive Priority & Layering

Directives are layered by priority (lowest number = highest priority) to create a cohesive context:

1. **Priority 10** - Plugin Core Directive (agent identity)
2. **Priority 15** - Chat Agent Directive (chat-specific identity)
3. **Priority 20** - Agent Soul Directive (SOUL.md from agent memory)
4. **Priority 25** - Pipeline Memory Files (per-pipeline selected agent memory files)
5. **Priority 30** - Pipeline System Prompt (pipeline instructions)
6. **Priority 35** - Pipeline Context Files (uploaded reference materials)
7. **Priority 40** - Tool Definitions (available tools and workflow)
8. **Priority 45** - Chat Pipelines Inventory (pipeline discovery)
9. **Priority 50** - Site Context (WordPress metadata)

### ChatAgentDirective (Priority 15)
Specialized directive for the conversational chat interface. It instructs the agent on discovery and configuration patterns, emphasizing querying existing workflows before creating new ones.

### AgentSoulDirective (Priority 20)
Reads `SOUL.md` from the agent memory directory (`{uploads}/datamachine-files/agent/SOUL.md`) and injects it as a system message. This defines the agent's personality, tone, and behavioral guidelines globally across all agent types. Migrated from the old `global_system_prompt` database setting in v0.13.0.

### PipelineMemoryFilesDirective (Priority 25)
Injects agent memory files selected for a specific pipeline. Files are stored in the shared agent directory and selected per-pipeline via the admin UI. SOUL.md is excluded (always injected separately at Priority 20). This enables pipelines to access strategy documents, reference material, or other persistent context.

### ChatPipelinesDirective (Priority 45)
Provides the conversational agent with an inventory of available pipelines. When a pipeline is selected in the UI, `selected_pipeline_id` is used to prioritize and expand context for that specific pipeline, including its flow summaries and handler configurations.

## Memory System Integration

The directive system integrates with Data Machine's file-based agent memory:

- **SOUL.md** is always injected (Priority 20) for all agent types
- **Pipeline Memory Files** (Priority 25) are selectable per-pipeline via the admin UI
- Files are stored in `{wp-content}/uploads/datamachine-files/agent/`
- SOUL.md is excluded from the pipeline memory file picker (it's always present)

## Registration

Directives are registered via WordPress filters:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => MyCustomDirective::class,
        'priority'    => 25,
        'agent_types' => ['pipeline', 'chat', 'all'],
    ];
    return $directives;
});
```

## Implementation Notes

- Directives should be read-only and never mutate the AI request structure directly.
- Use `DirectiveOutputValidator` to ensure responses from the AI follow the correct `system_text` or `system_json` formats.
- Context injection should be minimal and focused on what the agent needs for the current task.
- See `docs/core-system/ai-directives.md` for detailed implementation reference including agent-specific behavior, caching strategy, and extensibility hooks.
