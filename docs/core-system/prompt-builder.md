# PromptBuilder Pattern

The PromptBuilder provides unified directive management for AI requests with priority-based ordering and agent-specific targeting.

## Overview

PromptBuilder centralizes directive application in a single, priority-ordered system.

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
- **Unified Registration**: Directives register through `datamachine_directives`
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
        'class' => MyDirective::class,
        'priority' => 20,
        'modes' => ['all']
    ];

    return $directives;
});
```

## Priority Guidelines

**Priority Order** (lower = applied first):

- **20**: Registered memory files
- **22**: Runtime agent-mode guidance
- **25-35**: Caller, daily memory, and client-reported context
- **40-50**: Pipeline, flow, chat inventory, and workflow-specific directives

## Current Directive Implementations

- `CoreMemoryFilesDirective` - Registered memory files from shared, agent, and user layers (priority 20)
- `AgentModeDirective` - Runtime mode guidance for chat, pipeline, system, and extension modes (priority 22)
- `CallerContextDirective` - Authenticated cross-site caller identity (priority 25)
- `AgentDailyMemoryDirective` and `ClientContextDirective` - Optional daily memory and client context (priority 35)
- `PipelineMemoryFilesDirective`, `FlowMemoryFilesDirective`, and `PipelineSystemPromptDirective` - Pipeline-specific context (priorities 40, 45, 50)
- `ChatPipelinesDirective` - Pipeline and flow inventory for chat (priority 45)

## Integration with RequestBuilder

The PromptBuilder is integrated into the RequestBuilder for seamless directive application:

```php
// In RequestBuilder.php
$promptBuilder = new PromptBuilder();
$promptBuilder->setMessages($messages)
              ->setTools($structured_tools);

// Apply directives via PromptBuilder
$request = $promptBuilder->build($agent_modes, $provider, $context);
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
