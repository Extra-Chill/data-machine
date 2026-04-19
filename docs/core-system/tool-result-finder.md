# ToolResultFinder

**File**: `/inc/Engine/AI/Tools/ToolResultFinder.php`
**Since**: 0.2.0

Universal utility for finding AI tool execution results in data packets. Part of the Universal Engine infrastructure providing reusable data packet interpretation for all step types.

## Purpose

The `ToolResultFinder` class provides centralized logic for locating AI tool execution results within data packet arrays. Update handlers and other components use this utility to find specific handler tool results without duplicating search logic across the codebase.

## Architecture

```
Data Packet Array Flow:
┌─────────────────────────────────────────────────────┐
│          Data Packets (chronological order)         │
│                                                      │
│  [0] Most recent entry                              │
│  [1] Previous entry                                 │
│  [2] Older entry                                    │
│  ...                                                 │
│                                                      │
│  Each entry may have:                               │
│  • type: 'tool_result' or 'ai_handler_complete'    │
│  • metadata.handler_tool: 'twitter', 'wordpress'   │
│  • metadata.tool_result: {success, data, error}    │
└─────────────────────────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────┐
│          ToolResultFinder::findHandlerResult()      │
│                                                      │
│  Searches data packets for:                         │
│  • type = 'tool_result' OR 'ai_handler_complete'   │
│  • metadata.handler_tool matches requested handler  │
│                                                      │
│  Returns: First matching entry or null              │
└─────────────────────────────────────────────────────┘
```

## Methods

### findHandlerResult()

Find AI tool execution result by exact handler slug match.

**Signature**:
```php
public static function findHandlerResult(array $data, string $handler): ?array
```

**Parameters**:
- `$data` (array) - Data packet array from pipeline execution (chronological order, index 0 = newest)
- `$handler` (string) - Handler slug to match (e.g., 'twitter', 'wordpress', 'wordpress_update')

**Returns**: (array|null) Complete tool result entry or null if no match found

**Search Logic**:
1. Iterates through data packet array
2. Checks each entry's `type` field for 'tool_result' or 'ai_handler_complete'
3. Compares entry's `metadata.handler_tool` against requested handler slug
4. Returns first exact match or null if no matches found

**Example Usage**:
```php
use DataMachine\Engine\AI\ToolResultFinder;

// Search for WordPress Update handler result
$data = [
    [
        'type' => 'tool_result',
        'metadata' => [
            'handler_tool' => 'wordpress_update',
            'tool_name' => 'wordpress_update',
            'tool_result' => [
                'success' => true,
                'data' => [
                    'updated_id' => 123,
                    'post_url' => 'https://example.com/post/'
                ]
            ]
        ],
        'content' => ['update_result' => [...]]
    ],
    // ... other entries
];

$result = ToolResultFinder::findHandlerResult($data, 'wordpress_update');

if ($result) {
    $tool_result = $result['metadata']['tool_result'] ?? [];
    $success = $tool_result['success'] ?? false;
    $updated_id = $tool_result['data']['updated_id'] ?? null;

    echo "Found result: Post ID {$updated_id} updated successfully";
} else {
    echo "No wordpress_update tool result found";
}
```

## Integration Patterns

### Upsert Step Integration

The Upsert step (`/inc/Core/Steps/Upsert/UpsertStep.php`) uses ToolResultFinder to locate handler tool results:

```php
use DataMachine\Engine\AI\ToolResultFinder;

class UpsertStep {
    public function execute(array $payload): array {
        $data = $payload['data'] ?? [];
        $flow_step_config = $payload['flow_step_config'] ?? [];
        $handler_slug = $flow_step_config['handler_slug'] ?? '';

        // Use ToolResultFinder to locate handler tool result
        $tool_result_entry = ToolResultFinder::findHandlerResult($data, $handler_slug);

        if ($tool_result_entry) {
            // AI successfully executed handler tool
            return $this->create_update_entry_from_tool_result(
                $tool_result_entry,
                $data,
                $handler_slug,
                $flow_step_id
            );
        }

        // AI did not execute handler tool - fail cleanly
        do_action('datamachine_log', 'error', 'UpsertStep: AI did not execute handler tool', [
            'expected_handler' => $handler_slug
        ]);

        return [];
    }
}
```

### Publish Step Pattern (Future Use)

While Publish steps currently don't use ToolResultFinder (they execute tools directly), the pattern is extensible:

```php
// Example: Verify tool execution in Publish step
$tool_result = ToolResultFinder::findHandlerResult($data, 'twitter');

if ($tool_result) {
    $tweet_url = $tool_result['metadata']['tool_result']['data']['url'] ?? null;
    // Use tweet URL for logging or follow-up actions
}
```

## Data Packet Structure

ToolResultFinder expects data packets with this structure:

```php
$data = [
    [
        'type' => 'tool_result',  // or 'ai_handler_complete'
        'metadata' => [
            'handler_tool' => 'wordpress_update',  // Handler slug
            'tool_name' => 'wordpress_update',     // Tool name
            'tool_result' => [                     // Tool execution result
                'success' => true,
                'data' => [
                    'updated_id' => 123,
                    'post_url' => 'https://example.com/post/',
                    'modifications' => ['title', 'content']
                ],
                'error' => null
            ]
        ],
        'content' => [
            'update_result' => [...]  // Content representation
        ],
        'timestamp' => 1704153600
    ],
    // ... additional entries
];
```

## Tool Result Entry Types

ToolResultFinder recognizes two entry types:

### tool_result

Standard tool execution result from AI step:

```php
[
    'type' => 'tool_result',
    'metadata' => [
        'handler_tool' => 'twitter',
        'tool_name' => 'twitter_publish',
        'tool_result' => [
            'success' => true,
            'data' => ['id' => '123', 'url' => 'https://twitter.com/user/status/123'],
            'tool_name' => 'twitter_publish'
        ]
    ],
    'content' => ['tool_name' => 'twitter_publish', 'result' => 'Published to Twitter'],
    'timestamp' => 1704153600
]
```

### ai_handler_complete

Legacy format for handler completion:

```php
[
    'type' => 'ai_handler_complete',
    'metadata' => [
        'handler_tool' => 'wordpress',
        'tool_result' => [
            'success' => true,
            'data' => ['id' => 456, 'url' => 'https://example.com/post-456/']
        ]
    ],
    'content' => ['post_id' => 456],
    'timestamp' => 1704153600
]
```

## Error Handling

ToolResultFinder returns `null` when no matching result is found:

```php
$result = ToolResultFinder::findHandlerResult($data, 'nonexistent_handler');

if ($result === null) {
    // No result found - handle gracefully
    do_action('datamachine_log', 'warning', 'Handler result not found', [
        'handler' => 'nonexistent_handler',
        'data_entries' => count($data)
    ]);
}
```

**Common Scenarios**:
- Handler slug mismatch (requested 'twitter' but data has 'twitter_publish')
- AI did not execute handler tool
- Data packet array is empty
- Tool execution failed before creating entry

## Logging

ToolResultFinder does not log internally - callers should log search results:

```php
$result = ToolResultFinder::findHandlerResult($data, $handler_slug);

if ($result) {
    do_action('datamachine_log', 'info', 'ToolResultFinder: Found handler result', [
        'handler' => $handler_slug,
        'tool_name' => $result['metadata']['tool_name'] ?? 'unknown'
    ]);
} else {
    do_action('datamachine_log', 'error', 'ToolResultFinder: No handler result found', [
        'handler' => $handler_slug,
        'data_entries' => count($data),
        'entry_types' => array_unique(array_column($data, 'type'))
    ]);
}
```

## Benefits

### Code Reuse

Single implementation of tool result search logic eliminates duplication across:
- Update steps
- Custom step types
- Handler verification utilities
- Debugging tools

### Consistency

All components use identical search logic ensuring predictable behavior:
- Same handler slug matching
- Same entry type recognition
- Same return value structure

### Maintainability

Centralized location for search logic improvements:
- Enhanced matching algorithms
- Additional entry type support
- Performance optimizations

### Extensibility

Filter-based architecture allows customization:

```php
// Future enhancement: Custom result finding logic
add_filter('datamachine_tool_result_finder', function($result, $data, $handler) {
    // Custom search logic
    return $custom_result ?? $result;
}, 10, 3);
```

## Future Enhancements

**Partial Matching** (not currently implemented):
```php
// Find results by partial handler name match
$result = ToolResultFinder::findHandlerResult($data, 'word', $partial_match = true);
// Could match 'wordpress', 'wordpress_update', etc.
```

**Multiple Result Search**:
```php
// Find all matching results
$results = ToolResultFinder::findAllHandlerResults($data, 'twitter');
// Returns array of all twitter tool results
```

**Type-Specific Search**:
```php
// Search only 'tool_result' entries
$result = ToolResultFinder::findHandlerResult($data, 'twitter', $type = 'tool_result');
```

## Related Components

- Universal Engine Architecture - Overall engine structure
- Tool Execution Architecture - Tool execution and result creation
- WordPress Update Handler - Primary ToolResultFinder user
- Data Flow Architecture - Data packet structure and flow

---

**File**: `/inc/Engine/AI/Tools/ToolResultFinder.php`
**Since**: 0.2.0
**Methods**: `findHandlerResult(array $data, string $handler): ?array`
**Usage**: Update steps, custom step types, handler verification utilities
