# ConversationManager

**Location**: `/inc/Engine/AI/ConversationManager.php`

**Since**: v0.2.0 (Universal Engine Architecture)

**Namespace**: `DataMachine\Engine\AI`

## Overview

ConversationManager provides standardized message formatting utilities for all AI agents (Pipeline and Chat). All methods are static with no state management, enabling consistent conversation structure across the Data Machine ecosystem.

## Purpose

Centralizes conversation message building, tool call formatting, result messaging, and duplicate detection logic used by AIConversationLoop, RequestBuilder, and both Pipeline and Chat agents.

## Core Methods

### Message Building

#### buildConversationMessage()

Build standardized conversation message structure.

**Signature**:
```php
public static function buildConversationMessage(string $role, string $content): array
```

**Parameters**:
- `$role` (string) - Role identifier: `user`, `assistant`, `system`
- `$content` (string) - Message content

**Returns**: Array with `role` and `content` keys

**Example**:
```php
$message = ConversationManager::buildConversationMessage('user', 'Create a pipeline');
// Returns: ['role' => 'user', 'content' => 'Create a pipeline']
```

**Usage**: Foundation for all message formatting methods

---

### Tool Call Formatting

#### formatToolCallMessage()

Format tool call as conversation message with turn tracking.

**Signature**:
```php
public static function formatToolCallMessage(
    string $tool_name,
    array $tool_parameters,
    int $turn_count
): array
```

**Parameters**:
- `$tool_name` (string) - Tool identifier (e.g., `google_search`, `wordpress_publish`)
- `$tool_parameters` (array) - Tool call parameters
- `$turn_count` (int) - Current conversation turn (0 = no turn display)

**Returns**: Formatted assistant message with tool call details

**Message Format**:
```
AI ACTION (Turn {$turn_count}): Executing {Tool Display Name} with parameters: key: value, key2: value2
```

**Parameter Truncation**: String values over 50 characters are truncated to `50...`

**Example**:
```php
$message = ConversationManager::formatToolCallMessage(
    'google_search',
    ['query' => 'WordPress best practices', 'num_results' => 5],
    1
);
// Returns: ['role' => 'assistant', 'content' => 'AI ACTION (Turn 1): Executing Google Search with parameters: query: WordPress best practices, num_results: 5']
```

**Integration**: Used by AIConversationLoop during multi-turn conversation execution

---

### Tool Result Formatting

#### formatToolResultMessage()

Format tool execution result as conversation message.

**Signature**:
```php
public static function formatToolResultMessage(
    string $tool_name,
    array $tool_result,
    array $tool_parameters,
    bool $is_handler_tool = false,
    int $turn_count = 0
): array
```

**Parameters**:
- `$tool_name` (string) - Tool identifier
- `$tool_result` (array) - Tool execution result with `success`, `data`, `error` keys
- `$tool_parameters` (array) - Original tool parameters
- `$is_handler_tool` (bool) - Whether tool is handler-specific (affects data inclusion)
- `$turn_count` (int) - Current conversation turn (0 = no turn display)

**Returns**: Formatted user message with tool result

**Message Format**:
```
TOOL RESPONSE (Turn {$turn_count}): {Success Message}

{JSON-encoded data for non-handler tools}
```

**Data Inclusion**:
- Handler tools: Success message only (no raw data)
- Non-handler tools: Success message + JSON-encoded `data` field

**Example**:
```php
$result = [
    'success' => true,
    'data' => ['id' => 123, 'title' => 'New Post']
];

$message = ConversationManager::formatToolResultMessage(
    'wordpress_publish',
    $result,
    ['content' => 'Post content'],
    true, // is_handler_tool
    2
);
// Returns user message with success message only (no raw data)

$search_result = [
    'success' => true,
    'data' => ['results' => [...]]
];

$message2 = ConversationManager::formatToolResultMessage(
    'google_search',
    $search_result,
    ['query' => 'test'],
    false, // not a handler tool
    3
);
// Returns user message with success message + JSON data
```

---

### Success Message Generation

#### generateSuccessMessage()

Generate success or failure message from tool result with filter-based customization.

**Signature**:
```php
public static function generateSuccessMessage(
    string $tool_name,
    array $tool_result,
    array $tool_parameters
): string
```

**Parameters**:
- `$tool_name` (string) - Tool identifier
- `$tool_result` (array) - Tool execution result
- `$tool_parameters` (array) - Original tool parameters

**Returns**: Human-readable success/failure message

**Default Success Message**:
```
SUCCESS: {Tool Display Name} completed successfully. The requested operation has been finished as requested.
```

**Failure Message**:
```
TOOL FAILED: {tool_name} execution failed - {error}
```

**Filter Hook**: `datamachine_tool_success_message` allows handlers to customize success messages

**Example**:
```php
// Success
$result = ['success' => true, 'data' => []];
$message = ConversationManager::generateSuccessMessage('twitter_publish', $result, []);
// Returns: "SUCCESS: Twitter Publish completed successfully. The requested operation has been finished as requested."

// Failure
$result = ['success' => false, 'error' => 'Invalid credentials'];
$message = ConversationManager::generateSuccessMessage('twitter_publish', $result, []);
// Returns: "TOOL FAILED: twitter_publish execution failed - Invalid credentials"

// Custom via filter
add_filter('datamachine_tool_success_message', function($message, $tool_name, $result, $params) {
    if ($tool_name === 'wordpress_publish' && isset($result['data']['url'])) {
        return "SUCCESS: Post published at {$result['data']['url']}";
    }
    return $message;
}, 10, 4);
```

---

#### generateFailureMessage()

Generate standardized failure message.

**Signature**:
```php
public static function generateFailureMessage(string $tool_name, string $error_message): string
```

**Parameters**:
- `$tool_name` (string) - Tool identifier
- `$error_message` (string) - Error details

**Returns**: Formatted failure message with guidance

**Format**:
```
TOOL FAILED: {Tool Display Name} execution failed - {error_message}. Please review the error and adjust your approach if needed.
```

**Example**:
```php
$message = ConversationManager::generateFailureMessage(
    'google_search',
    'API quota exceeded'
);
// Returns: "TOOL FAILED: Google Search execution failed - API quota exceeded. Please review the error and adjust your approach if needed."
```

---

### Duplicate Tool Call Detection

#### validateToolCall()

Validate if a tool call is a duplicate of the previous tool call in conversation history.

**Signature**:
```php
public static function validateToolCall(
    string $tool_name,
    array $tool_parameters,
    array $conversation_messages
): array
```

**Parameters**:
- `$tool_name` (string) - Tool name to validate
- `$tool_parameters` (array) - Tool parameters to validate
- `$conversation_messages` (array) - Full conversation history

**Returns**: Array with `is_duplicate` (bool) and `message` (string) keys

**Detection Logic**:
1. Search conversation history backwards for most recent assistant message with "AI ACTION" prefix
2. Extract tool name and parameters from that message
3. Compare with current tool call
4. Return duplicate status and correction message if duplicate

**Example**:
```php
$conversation = [
    ['role' => 'user', 'content' => 'Search for WordPress'],
    ['role' => 'assistant', 'content' => 'AI ACTION (Turn 1): Executing Google Search with parameters: query: WordPress, num_results: 5'],
    ['role' => 'user', 'content' => 'TOOL RESPONSE (Turn 1): ...']
];

$validation = ConversationManager::validateToolCall(
    'google_search',
    ['query' => 'WordPress', 'num_results' => 5],
    $conversation
);
// Returns: ['is_duplicate' => true, 'message' => 'You just called the google_search tool with the exact same parameters...']

$validation2 = ConversationManager::validateToolCall(
    'google_search',
    ['query' => 'WordPress plugins', 'num_results' => 5],
    $conversation
);
// Returns: ['is_duplicate' => false, 'message' => '']
```

**Integration**: Used by AIConversationLoop to prevent infinite loops from duplicate tool calls

---

#### extractToolCallFromMessage()

Extract tool call details from a conversation message.

**Signature**:
```php
public static function extractToolCallFromMessage(array $message): ?array
```

**Parameters**:
- `$message` (array) - Conversation message to parse

**Returns**: Array with `tool_name` and `parameters` keys, or null if not a tool call message

**Recognition Pattern**: Messages matching `AI ACTION (Turn N): Executing {Tool} with parameters: ...`

**Parameter Parsing**:
- Splits on `, ` delimiter
- Parses `key: value` pairs
- JSON-decodes values where possible
- Handles truncated values (appends `_truncated_{timestamp}` to prevent false duplicates)

**Example**:
```php
$message = [
    'role' => 'assistant',
    'content' => 'AI ACTION (Turn 1): Executing Google Search with parameters: query: WordPress, num_results: 5'
];

$extracted = ConversationManager::extractToolCallFromMessage($message);
// Returns: [
//     'tool_name' => 'google_search',
//     'parameters' => ['query' => 'WordPress', 'num_results' => 5]
// ]
```

**Usage**: Internal utility for duplicate detection

---

#### generateDuplicateToolCallMessage()

Generate a tool-result message for duplicate tool call prevention. The
correction message is **mode-aware** so the AI receives continuation guidance
appropriate to the current execution mode.

**Signature**:
```php
public static function generateDuplicateToolCallMessage(
    string $tool_name,
    int $turn_count = 0,
    string $mode = 'chat'
): array
```

**Parameters**:
- `$tool_name` (string) - Tool name that was duplicated
- `$turn_count` (int) - Current conversation turn (0 = no turn display)
- `$mode` (string) - Execution mode (`'chat'`, `'pipeline'`, `'bridge'`, ...). Defaults to `'chat'` so existing callers retain prior behavior.

**Returns**: Formatted tool-result envelope (`success: false`, `error: <mode-shaped guidance>`)

**Mode behavior**:
- `'chat'` (default) — instructs the AI to **end the conversation**. The tool's success IS the answer to the user's question.
- `'pipeline'` — instructs the AI to **call the publish handler tool now** to complete the step. Pipeline conversations have downstream work after the duplicated tool call.
- `'bridge'` — instructs the AI to **continue its response to the user** using the result it already has.
- Unknown modes fall back to chat semantics.

**Example**:
```php
// Chat: AI is told the task is done.
$msg = ConversationManager::generateDuplicateToolCallMessage('google_search');

// Pipeline: AI is told to move on to the publish handler.
$msg = ConversationManager::generateDuplicateToolCallMessage(
    'queue_validator',
    $turn_count,
    'pipeline'
);
```

**Background**: see [data-machine #1441](https://github.com/Extra-Chill/data-machine/issues/1441) — chat-shaped guidance was previously emitted for every mode, which caused pipeline AI steps to silently end mid-conversation after a duplicate tool call instead of finishing the publish step.

---

## Integration with Universal Engine

### AIConversationLoop Integration

AIConversationLoop uses ConversationManager for:
- **Tool Call Messages**: Format each tool call with turn tracking
- **Tool Result Messages**: Format tool execution results with success/failure details
- **Duplicate Detection**: Validate tool calls before execution to prevent infinite loops

**Conversation Flow**:
```php
// 1. Format tool call message
$tool_call_msg = ConversationManager::formatToolCallMessage($tool_name, $params, $turn_count);
$conversation[] = $tool_call_msg;

// 2. Execute tool via ToolExecutor
$result = ToolExecutor::executeTool(...);

// 3. Format tool result message
$result_msg = ConversationManager::formatToolResultMessage($tool_name, $result, $params, $is_handler_tool, $turn_count);
$conversation[] = $result_msg;

// 4. Validate next tool call for duplicates
$validation = ConversationManager::validateToolCall($next_tool, $next_params, $conversation);
if ($validation['is_duplicate']) {
    $conversation[] = ConversationManager::buildConversationMessage('user', $validation['message']);
}
```

### RequestBuilder Integration

RequestBuilder uses ConversationManager for:
- **System Messages**: Build directive messages with proper role assignment
- **User Context**: Format user-provided context as conversation messages

### Handler Tool Integration

Publish/Update handlers use ConversationManager indirectly through:
- **Success Message Filter**: Customize tool result messages via `datamachine_tool_success_message` filter

**Custom Success Message Example**:
```php
add_filter('datamachine_tool_success_message', function($message, $tool_name, $result, $params) {
    if ($tool_name === 'twitter_publish' && isset($result['data']['url'])) {
        $tweet_url = $result['data']['url'];
        return "SUCCESS: Tweet published successfully at {$tweet_url}. The content is now live on Twitter.";
    }
    return $message;
}, 10, 4);
```

---

## Architecture Principles

### Static Methods Only

All methods are static with no instance state:
- **Benefit**: No dependency injection required, simple utility usage
- **Pattern**: Pure functions for message transformation
- **Thread Safety**: No state mutations, safe for concurrent usage

### Standardized Message Format

Consistent message structure across all agents:
- **Role**: `user`, `assistant`, `system`
- **Content**: String message content
- **Turn Tracking**: Explicit turn numbers in tool messages
- **Result Clarity**: Clear SUCCESS/FAILED prefixes

### Filter-Based Extensibility

Handlers can customize messages via WordPress filters:
- **`datamachine_tool_success_message`**: Override default success messages
- **Parameters**: `$message`, `$tool_name`, `$tool_result`, `$tool_parameters`
- **Use Cases**: Platform-specific messaging, URL inclusion, custom formatting

### Duplicate Prevention

Sophisticated duplicate detection prevents infinite loops:
- **Backward Search**: Finds most recent tool call in conversation history
- **Exact Matching**: Compares tool name and parameters
- **Truncation Handling**: Prevents false duplicates from truncated parameters
- **Guidance Messaging**: Provides clear correction instructions to AI

---

## Usage Examples

### Basic Message Building

```php
// Simple user message
$msg = ConversationManager::buildConversationMessage('user', 'Create a pipeline');

// System directive
$directive = ConversationManager::buildConversationMessage('system', 'You are a helpful assistant');
```

### Tool Call Workflow

```php
// 1. Format tool call
$tool_call = ConversationManager::formatToolCallMessage(
    'wordpress_publish',
    ['title' => 'My Post', 'content' => 'Post content'],
    1
);

// 2. Execute tool (via ToolExecutor)
$result = ToolExecutor::executeTool(...);

// 3. Format result
$result_msg = ConversationManager::formatToolResultMessage(
    'wordpress_publish',
    $result,
    ['title' => 'My Post', 'content' => 'Post content'],
    true, // is_handler_tool
    1
);
```

### Duplicate Detection

```php
$conversation = [...]; // Full conversation history

// Validate before execution
$validation = ConversationManager::validateToolCall(
    'google_search',
    ['query' => 'test'],
    $conversation
);

if ($validation['is_duplicate']) {
    $correction = ConversationManager::buildConversationMessage(
        'user',
        $validation['message']
    );
    $conversation[] = $correction;
    // Skip tool execution, continue conversation with correction
}
```

---

## Related Components

- AIConversationLoop - Multi-turn conversation execution using ConversationManager
- ToolExecutor - Tool discovery and execution
- RequestBuilder - AI request construction
- Parameter Systems - Tool parameter building and architecture
- Universal Engine Architecture - Shared AI infrastructure

---

**Location**: `/inc/Engine/AI/ConversationManager.php`
**Namespace**: `DataMachine\Engine\AI`
**Type**: Static utility class
**Since**: v0.2.0
