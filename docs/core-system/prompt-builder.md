# PromptBuilder Pattern

**Since**: 0.2.5

The PromptBuilder provides unified directive management for AI requests with priority-based ordering and agent-specific targeting. It replaces the previous scattered filter-based directive application with a structured builder pattern.

## Overview

Prior to v0.2.5, AI directives were applied through separate `datamachine_global_directives` and `datamachine_agent_directives` filters, creating inconsistent ordering and maintenance overhead. The PromptBuilder centralizes this functionality into a single, priority-ordered system.

## Architecture

```
┌─────────────────────────────────────────────────────┐
│              PromptBuilder Pattern                 │
│  (/inc/Engine/AI/PromptBuilder.php)                │
│                                                    │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ Directive        │      │ Priority-Based   │   │
│  │ Registration     │      │ Ordering         │   │
│  │ (addDirective)   │      │ (usort by pri)   │   │
│  └──────────────────┘      └──────────────────┘   │
│                                                    │
│  ┌──────────────────┐      ┌──────────────────┐   │
│  │ Agent Type       │      │ Sequential       │   │
│  │ Targeting        │      │ Application      │   │
│  │ ('all', 'pipeline│      │ (build method)   │   │
│  │  'chat')         │      └──────────────────┘   │
│  └──────────────────┘                             │
└─────────────────────────────────────────────────────┘
```

## Key Features

- **Priority-Based Ordering**: Lower priority numbers applied first (10=core, 20=global, 30=pipeline, 40=context, 50=site)
- **Agent Targeting**: Directives can target 'all' agents or specific types ('pipeline', 'chat')
- **Unified Registration**: Single `datamachine_directives` filter replaces multiple filter types
- **Structured Builder Pattern**: Fluent interface for directive configuration and request building

## Usage

### Basic Usage

```php
use DataMachine\Engine\AI\PromptBuilder;

// Create builder instance
$builder = new PromptBuilder();

// Set initial messages and tools
$builder->setMessages($messages)
        ->setTools($tools);

// Add directives with priorities
$builder->addDirective(MyDirective::class, 20, ['all'])
        ->addDirective(PipelineDirective::class, 30, ['pipeline']);

// Build final request
$request = $builder->build('pipeline', 'openai', $payload);
```

### Directive Registration

Directives are registered via the `datamachine_directives` filter:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => GlobalSystemPromptDirective::class,
        'priority' => 20,
        'agent_types' => ['all']
    ];

    return $directives;
});
```

## Priority Guidelines

**Priority Order** (lower = applied first):

- **10-19**: Core agent identity and foundational instructions
- **20-29**: Global system prompts and universal behavior
- **30-39**: Agent-specific system prompts and context
- **40-49**: Workflow and execution context directives
- **50+**: Environmental and site-specific directives

## Current Directive Implementations

### Global Directives (apply to all agents)
- `GlobalSystemPromptDirective` - User-configured global AI behavior (priority 20)
- `SiteContextDirective` - WordPress site context injection (priority 50)

### Pipeline Directives
- `PipelineCoreDirective` - Foundational pipeline agent identity (priority 10)
- `PipelineSystemPromptDirective` - User-defined pipeline prompts (priority 30)

### Chat Directives
- `ChatAgentDirective` - Chat agent identity and capabilities (priority 10)

## Integration with RequestBuilder

The PromptBuilder is integrated into the RequestBuilder for seamless directive application:

```php
// In RequestBuilder.php
$promptBuilder = new PromptBuilder();
$promptBuilder->setMessages($messages)
              ->setTools($structured_tools);

// Apply directives via PromptBuilder
$request = $promptBuilder->build($agent_type, $provider, $context);
```

## Migration from Legacy System

### Before (v0.2.4 and earlier)
```php
// Separate filters with inconsistent ordering
add_filter('datamachine_global_directives', 'apply_global_directive', 10, 5); // LEGACY - use datamachine_directives instead
add_filter('datamachine_agent_directives', 'apply_agent_directive', 10, 5); // LEGACY - use datamachine_directives with agent_types to target 'pipeline'/'chat'
```

### After (v0.2.5+)
```php
// Unified registration with explicit priorities
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => MyDirective::class,
        'priority' => 25,
        'agent_types' => ['all']
    ];
    return $directives;
});
```

## Benefits

- **Consistent Ordering**: Priority-based system ensures predictable directive application
- **Agent Targeting**: Fine-grained control over which directives apply to which agents
- **Maintainability**: Single registration point reduces filter complexity
- **Extensibility**: Easy to add new directives with proper ordering
- **Debugging**: Clear priority logging helps troubleshoot directive conflicts

## Related Components

- RequestBuilder Pattern - Uses PromptBuilder for directive application
- Universal Engine Architecture - Overall AI infrastructure
- AI Conversation Loop - Multi-turn conversation execution
