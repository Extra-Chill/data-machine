# AI Conversation Loop

**File**: `/inc/Engine/AI/AIConversationLoop.php`
**Since**: 0.2.0

Multi-turn conversation execution engine for AI agents. Handles automatic tool execution, result feedback, and conversation completion detection for both Pipeline AI and Chat API agents.

## Purpose

The `AIConversationLoop` class provides centralized multi-turn conversation management, eliminating duplicate conversation logic between Pipeline and Chat agents. It orchestrates the conversation flow, executes tools, manages turn limits, and determines when conversations are complete.

## Architecture

```
Conversation Flow:
┌─────────────────────────────────────────────────────┐
│                 AIConversationLoop                  │
│                                                      │
│  ┌────────────────────────────────────────────┐    │
│  │ Turn 1: AI Request → Tool Calls → Execute │    │
│  └────────────────────────────────────────────┘    │
│                        │                            │
│  ┌────────────────────────────────────────────┐    │
│  │ Turn 2: AI Request → Tool Calls → Execute │    │
│  └────────────────────────────────────────────┘    │
│                        │                            │
│                       ...                           │
│                        │                            │
│  ┌────────────────────────────────────────────┐    │
│  │ Turn N: AI Request → No Tool Calls = Done  │    │
│  └────────────────────────────────────────────┘    │
│                                                      │
│  Components Used:                                   │
│  • RequestBuilder - Build AI requests               │
│  • ToolExecutor - Execute tool calls                │
│  • ConversationManager - Format messages            │
└─────────────────────────────────────────────────────┘
```

## Key Features

### Automatic Tool Execution

The conversation loop automatically detects tool calls in AI responses and executes them via `ToolExecutor`, adding both tool call and tool result messages to the conversation history.

```php
foreach ($tool_calls as $tool_call) {
    $tool_name = $tool_call['name'];
    $tool_parameters = $tool_call['parameters'];

    // Execute tool
    $tool_result = ToolExecutor::executeTool(
        $tool_name,
        $tool_parameters,
        $tools,
        $data,
        $flow_step_id,
        $context
    );

    // Add tool result to conversation
    $tool_result_message = ConversationManager::formatToolResultMessage(
        $tool_name,
        $tool_result,
        $tool_parameters,
        $is_handler_tool,
        $turn_count
    );
    $messages[] = $tool_result_message;
}
```

### Completion Detection

Conversations complete naturally when the AI returns a response with no tool calls. This signals the AI has finished its workflow objectives.

```php
if (empty($tool_calls)) {
    $conversation_complete = true;
}
```

### State Management

The loop maintains conversation state across turns, tracking:

- Total message count
- Current turn number
- Final AI content response
- Last tool calls (for debugging)
- Completion status

### Turn Limiting

Configurable maximum turns (default: 8) prevent infinite loops. If max turns are reached, the loop terminates and logs a warning.

```php
if ($turn_count >= $max_turns && !$conversation_complete) {
    do_action('datamachine_log', 'warning', 'AIConversationLoop: Max turns reached', [
        'agent_type' => $agent_type,
        'max_turns' => $max_turns,
        'final_turn_count' => $turn_count,
        'still_had_tool_calls' => !empty($last_tool_calls)
    ]);
}
```

## Usage

### Canonical entry point

All callers should use the static `AIConversationLoop::run()` entry point. It
accepts the same arguments as `execute()` and returns the same result shape,
but first gives a registered runtime adapter (via the `agents_api_conversation_runner`
filter) the opportunity to short-circuit the built-in loop. See
[Runtime Adapters](#runtime-adapters) below.

```php
use DataMachine\Engine\AI\AIConversationLoop;

$result = AIConversationLoop::run(
    $messages,        // Initial conversation messages
    $tools,           // Available tools for AI
    $provider,        // AI provider (openai, anthropic, etc.)
    $model,           // AI model identifier
    $context,         // 'pipeline' or 'chat'
    $payload,         // Agent-specific payload data
    $max_turns,       // Maximum conversation turns (default: 25)
    $single_turn      // Execute exactly one turn (default: false)
);
```

### Pipeline Agent Example

```php
// Pipeline payload includes job_id, flow_step_id, data, flow_step_config
$payload = [
    'job_id'           => $job_id,
    'flow_step_id'     => $flow_step_id,
    'data'             => $data,
    'flow_step_config' => $flow_step_config,
];

$result = AIConversationLoop::run(
    $messages,
    $tools,
    $provider,
    $model,
    'pipeline',
    $payload,
    $max_turns
);

$final_data = $result['messages'];
$turn_count = $result['turn_count'];
$completed  = $result['completed'];
```

### Chat Agent Example

```php
// Chat payload includes session_id, user_id, agent_id
$payload = [
    'session_id' => $session_id,
    'user_id'    => $user_id,
    'agent_id'   => $agent_id,
];

$result = AIConversationLoop::run(
    $messages,
    $tools,
    $provider,
    $model,
    'chat',
    $payload,
    $max_turns,
    $single_turn
);

$final_messages = $result['messages'];
$final_content  = $result['final_content'];
$turn_count     = $result['turn_count'];
```

## Runtime Adapters

Data Machine's built-in loop is the default, but the entire conversation
runtime is swappable via a single filter. This lets a consumer plug Data
Machine's pipelines, flows, tools, and memory into a different agent runtime
(for example, a host platform that already provides its own agent loop,
conversation storage, and channels) without Data Machine knowing anything
about that runtime.

### The filter

```php
apply_filters(
    'agents_api_conversation_runner',
    null,           // Return non-null to short-circuit the built-in loop
    $messages,
    $tools,
    $provider,
    $model,
    $context,
    $payload,
    $max_turns,
    $single_turn
);
```

Return an array matching `AIConversationLoop::execute()`'s documented return
shape to replace the built-in loop. Return `null` (the default) to let Data
Machine run the conversation itself.

### Adapter contract

An adapter is responsible for:

1. Executing tool calls and appending tool-result messages to `$messages`.
2. Managing turn count and termination against `$max_turns`.
3. Returning the exact shape `execute()` returns — `messages`, `final_content`,
   `turn_count`, `completed`, `last_tool_calls`, `tool_execution_results`,
   `has_pending_tools`, `usage`, plus optional `error`, `warning`, and
   `max_turns_reached` keys.

Data Machine makes no assumptions about how the adapter produces that result.
A consumer can delegate to any external runtime — its own `Agent` subclass, a
remote RPC service, a different language — as long as the return shape is
honored. Returned messages may use the legacy `role/content/metadata` shape or
the versioned [Agent Message Envelope](./ai-message-envelope.md); Data Machine
normalizes every returned message to the canonical envelope before callers store
or render the result. Provider-specific `role/content/metadata` arrays are now a
projection at the provider boundary, not the runtime/storage contract.

### Minimal adapter example

```php
add_filter(
    'agents_api_conversation_runner',
    function ( $result, $messages, $tools, $provider, $model, $context, $payload, $max_turns, $single_turn ) {
        // Only take over for a specific context.
        if ( 'chat' !== $context ) {
            return $result;
        }

        // Delegate to an external runtime that returns the expected shape.
        return my_external_runtime_run( [
            'messages'    => $messages,
            'tools'       => $tools,
            'provider'    => $provider,
            'model'       => $model,
            'payload'     => $payload,
            'max_turns'   => $max_turns,
            'single_turn' => $single_turn,
        ] );
    },
    10,
    9
);
```

### Mirrors the AI HTTP Client provider pattern

This is the same extension shape Data Machine's AI HTTP Client uses to allow
different LLM providers (OpenAI, Anthropic, Gemini, Grok, OpenRouter) to be
registered via `chubes_ai_request`. A runtime adapter is the same idea, one
layer up: providers swap how the LLM is called; runtime adapters swap how the
conversation is run.

### Relationship to wp-ai-client migration (#1027)

Runtime adapters and the upcoming wp-ai-client migration operate at different
layers and are independent:

- `agents_api_conversation_runner` replaces the **conversation loop** — turn
  management, tool execution, completion detection.
- The wp-ai-client migration (see Extra-Chill/data-machine#1027) replaces the
  **LLM request layer** that the built-in loop calls internally — a single
  HTTP call to an LLM provider.

When wp-ai-client lands, Data Machine's built-in `execute()` will call
`wp_ai_client_prompt()` in place of `apply_filters('chubes_ai_request', ...)`.
The `run()` entry point and the `agents_api_conversation_runner` filter
contract are unchanged, and adapters that replace the entire loop are
unaffected — they bring their own LLM client as part of their runtime.

## Configuration

### Max Turns

The `$max_turns` parameter controls the maximum number of conversation turns before forced termination:

```php
$result = $loop->execute($messages, $tools, $provider, $model, $agent_type, $context, 10); // 10 turns max
```

**Default**: 8 turns
**Recommended**: 8-12 turns for most workflows

### Turn tracking

Each turn represents one AI request-response cycle. Tool execution within a turn does not increment the turn count. The loop automatically tags messages with `Turn {N}` prefixes via `ConversationManager` to maintain chronological context for the AI.

## Tool Execution Integration

The conversation loop integrates with `ToolExecutor` for unified tool execution:

```php
$tool_result = ToolExecutor::executeTool(
    $tool_name,           // Tool name from AI
    $tool_parameters,     // Parameters from AI
    $tools,               // Available tools array
    [],                   // Data packets (empty for chat, populated for pipeline)
    null,                 // flow_step_id (null for chat, string for pipeline)
    $context              // Unified parameters (session_id or job_id + engine_data)
);
```

### Duplicate Tool Call Prevention

The loop validates tool calls against conversation history to prevent duplicate executions:

```php
$validation_result = ConversationManager::validateToolCall(
    $tool_name,
    $tool_parameters,
    $messages
);

if ($validation_result['is_duplicate']) {
    $correction_message = ConversationManager::generateDuplicateToolCallMessage($tool_name);
    $messages[] = $correction_message;
    continue; // Skip execution
}
```

## Error Handling

### AI Request Failures

If `RequestBuilder::build()` returns an error, the loop terminates immediately and returns error information:

```php
if (!$ai_response['success']) {
    return [
        'messages' => $messages,
        'final_content' => '',
        'turn_count' => $turn_count,
        'completed' => false,
        'last_tool_calls' => [],
        'error' => $ai_response['error'] ?? 'AI request failed'
    ];
}
```

### Tool Execution Failures

Tool execution failures are captured and added to conversation history as tool result messages. The conversation continues, allowing the AI to adapt or retry.

```php
$tool_result = ToolExecutor::executeTool(...);

// Tool result includes success flag and error message if failed
$tool_result_message = ConversationManager::formatToolResultMessage(
    $tool_name,
    $tool_result,  // Contains 'success' => false and 'error' message
    $tool_parameters,
    $is_handler_tool,
    $turn_count
);
$messages[] = $tool_result_message;
```

### Max Turns Reached

If max turns are reached before conversation completion, the loop logs a warning and returns the final state:

```php
do_action('datamachine_log', 'warning', 'AIConversationLoop: Max turns reached', [
    'agent_type' => $agent_type,
    'max_turns' => $max_turns,
    'final_turn_count' => $turn_count,
    'still_had_tool_calls' => !empty($last_tool_calls)
]);
```

## Logging

The conversation loop provides comprehensive logging at each stage:

### Loop Start

```php
do_action('datamachine_log', 'debug', 'AIConversationLoop: Starting conversation loop', [
    'agent_type' => $agent_type,
    'provider' => $provider,
    'model' => $model,
    'initial_message_count' => count($messages),
    'tool_count' => count($tools),
    'max_turns' => $max_turns
]);
```

### Turn Start

```php
do_action('datamachine_log', 'debug', 'AIConversationLoop: Turn started', [
    'agent_type' => $agent_type,
    'turn_count' => $turn_count,
    'message_count' => count($messages)
]);
```

### AI Response

```php
do_action('datamachine_log', 'debug', 'AIConversationLoop: AI returned content', [
    'agent_type' => $agent_type,
    'turn_count' => $turn_count,
    'content_length' => strlen($ai_content),
    'has_tool_calls' => !empty($tool_calls)
]);
```

### Tool Execution

```php
do_action('datamachine_log', 'debug', 'AIConversationLoop: Processing tool calls', [
    'agent_type' => $agent_type,
    'turn_count' => $turn_count,
    'tool_call_count' => count($tool_calls),
    'tools' => array_column($tool_calls, 'name')
]);
```

### Conversation Complete

```php
do_action('datamachine_log', 'debug', 'AIConversationLoop: Conversation complete', [
    'agent_type' => $agent_type,
    'turn_count' => $turn_count,
    'final_message_count' => count($messages)
]);
```

## Best Practices

### Initial Message Structure

Always provide initial messages with proper role/content structure:

```php
$initial_messages = [
    [
        'role' => 'user',
        'content' => 'Process this content and publish to social media.'
    ]
];
```

### Context Parameters

Provide complete context for agent-specific operations:

```php
// Pipeline context
$context = [
    'step_id' => $flow_step_id,
    'payload' => [
        'job_id' => $job_id,
        'flow_step_id' => $flow_step_id,
        'data' => $data,
        'flow_step_config' => $flow_step_config,
        'engine_data' => $engine_data
    ]
];

// Chat context
$context = [
    'session_id' => $session_id
];
```

### Result Handling

Always check completion status and handle partial results:

```php
$result = $loop->execute(...);

if ($result['completed']) {
    // Conversation finished naturally
    $final_messages = $result['messages'];
} else {
    // Max turns reached or error occurred
    if (isset($result['error'])) {
        // Handle error
    } else {
        // Max turns reached
        $partial_messages = $result['messages'];
    }
}
```

### Turn Limits

Set appropriate max turns based on workflow complexity:

- **Simple workflows**: 4-6 turns
- **Standard workflows**: 8 turns (default)
- **Complex workflows**: 10-12 turns
- **Avoid**: Setting max turns > 15 (indicates architectural issue)

## Related Components

- Universal Engine Architecture - Overall engine structure
- Tool Execution Architecture - ToolExecutor details
- RequestBuilder Pattern - AI request construction
- [ConversationManager](universal-engine.md#conversationmanager) - Message formatting utilities
