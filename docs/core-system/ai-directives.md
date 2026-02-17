# AI Directives System

Data Machine uses a hierarchical directive system to provide contextual information to AI agents during conversation and workflow execution. Directives are injected into AI requests in priority order, ensuring consistent behavior and context across all interactions.

## Directive Architecture

### 5-Tier Priority System

Directives are applied in the following priority order (lowest number = highest priority):

1. **Priority 10** - Plugin Core Directive (agent identity)
2. **Priority 15** - Chat Agent Directive (chat-specific identity)
3. **Priority 20** - Global System Prompt (global AI behavior)
4. **Priority 30** - Pipeline System Prompt (pipeline instructions)
5. **Priority 35** - Pipeline Context Files (reference materials)
6. **Priority 40** - Tool Definitions (available tools and workflow)
7. **Priority 45** - Chat Pipelines Inventory (pipeline discovery)
8. **Priority 50** - Site Context (WordPress metadata)

## Individual Directives

### ChatAgentDirective (Priority 15)

**Location**: `inc/Api/Chat/ChatAgentDirective.php`  
**Agent Types**: Chat only  
**Purpose**: Defines chat agent identity and capabilities

Provides the foundational system prompt for chat interactions, establishing the agent's role in helping users configure and manage Data Machine workflows.

### ChatPipelinesDirective (Priority 45)

**Location**: `inc/Api/Chat/ChatPipelinesDirective.php`  
**Agent Types**: Chat only  
**Purpose**: Injects pipeline inventory and flow summaries

Provides a lightweight inventory of all pipelines, including their configured steps and flow summaries (active handlers).

**Context Awareness**:
When `selected_pipeline_id` is provided (e.g., from the Integrated Chat Sidebar), the agent prioritizes and expands context for that specific pipeline, enabling it to learn from established configuration patterns and provide targeted assistance.

### AgentSoulDirective (Priority 20)

**Location**: `inc/Engine/AI/Directives/AgentSoulDirective.php`  
**Agent Types**: All agents  
**Purpose**: Injects user-configured global AI behavior

Adds a system message containing the `global_system_prompt` setting from plugin configuration. This directive sets the overall tone, personality, and behavioral guidelines for ALL AI interactions across the entire system.

**Configuration**: Set via `global_system_prompt` in plugin settings.

### PipelineContextDirective (Priority 35)

**Location**: `inc/Core/Steps/AI/Directives/PipelineContextDirective.php`  
**Agent Types**: Pipeline agents  
**Purpose**: Provides pipeline-specific reference materials

Injects uploaded context files from pipeline configurations as file attachments in AI requests. Each file is added as a system message with proper MIME type handling.

**Features**:
- Retrieves context files from pipeline configuration
- Validates file existence before injection
- Supports multiple file formats
- Logs injection activity for debugging

### SiteContextDirective (Priority 50)

**Location**: `inc/Engine/AI/Directives/SiteContextDirective.php`  
**Agent Types**: All agents  
**Purpose**: Provides comprehensive WordPress site metadata

Injects structured JSON data about the WordPress site including post types, taxonomies, terms, and site configuration. This is the final directive in the hierarchy, providing complete site context for AI decision-making.

**Features**:
- Cached site metadata for performance
- Automatic cache invalidation on content changes
- Toggleable via `site_context_enabled` setting
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
2. **Directive Collection**: `PromptBuilder` gathers all registered directives
3. **Priority Sorting**: Directives sorted by priority (ascending)
4. **Agent Filtering**: Only directives matching current agent type are applied
5. **Sequential Injection**: Each directive injects its content into the messages array
6. **Final Request**: Complete request sent to AI provider

### Message Ordering

Directives maintain consistent message ordering by using `array_push()` to append system messages. This ensures:
- Core directives appear first
- Context accumulates predictably
- Tool definitions and site context appear last

## Configuration & Extensibility

### Plugin Settings Integration

Several directives integrate with plugin settings:

- **Global System Prompt**: `global_system_prompt` setting
- **Site Context**: `site_context_enabled` toggle

### Filter Hooks

**`datamachine_directives`**: Register new directives
```php
$directives[] = [
    'class' => 'My\Directive\Class',
    'priority' => 25,
    'agent_types' => ['chat', 'pipeline', 'all']
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
- **Global Prompts**: Retrieved directly from settings (no caching needed)
- **Pipeline Context**: Files validated on each request (no caching)

### Cache Invalidation Triggers

Site context cache clears automatically when:
- Posts are created, updated, or deleted
- Terms are created, edited, or deleted
- Users are registered or deleted
- Theme is switched
- Site options (name, description, URL) change

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
- File existence validation for context files
- Empty content detection and logging
- Graceful degradation when optional features fail

## Agent-Specific Behavior

### Pipeline Agents
Receive directives: Core (10), Global Prompt (20), Pipeline Prompt (30), Pipeline Context (35), Tools (40), Site Context (50)

### Chat Agents
Receive directives: Core (10), Chat Agent (15), Global Prompt (20), Tools (40), Chat Pipelines (45), Site Context (50)

### Universal Directives
Global Prompt (20) and Site Context (50) apply to all agent types, ensuring consistent behavior across the system.