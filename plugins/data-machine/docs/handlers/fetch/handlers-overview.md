# Fetch Handlers Overview

Fetch handlers retrieve content from various sources and convert it into standardized `DataPacket` objects for pipeline processing.

## Available Handlers

### Local Sources

**WordPress Local** (`wordpress`)
- **Purpose**: Fetch posts/pages from local WordPress installation
- **Authentication**: None (uses WordPress database)
- **Data Source**: Local WP_Query results
- **Key Features**: Taxonomy filtering, timeframe limits, specific post targeting

**WordPress Media** (`wordpress_media`)
- **Purpose**: Fetch media files from local WordPress media library with parent post integration
- **Authentication**: None (uses WordPress media functions)
- **Data Source**: Media library attachments with optional parent content
- **Key Features**: Parent post content inclusion, file type filtering, metadata extraction, clean content generation

**Files** (`files`)
- **Purpose**: Process local and remote files
- **Authentication**: None
- **Data Source**: File system or URLs
- **Key Features**: Flow-isolated storage, multiple file format support

### External Sources

**Universal Web Scraper** (`universal_web_scraper`) (@since v0.8.0)
- **Purpose**: High-performance, multi-extractor web content retrieval for events and structured data. [See detailed documentation](universal-web-scraper.md).
- **Authentication**: Varies by site (Public API, API Key, or None)
- **Data Source**: External websites via specialized extractors (AEG/AXS, Squarespace, Wix, JSON-LD, etc.)
- **Key Features**: Consolidates multiple legacy handlers into a unified architecture. Replaces standalone `GoogleCalendar` and `WordPressEventsAPI` handlers. Supports multi-layered extraction with AI fallback and automated pagination.

**RSS** (`rss`)
- **Purpose**: Fetch content from RSS/Atom feeds
- **Authentication**: None
- **Data Source**: XML feed parsing
- **Key Features**: Automatic deduplication, feed validation

**WordPress API** (`wordpress_api`)
- **Purpose**: Fetch content from external WordPress sites
- **Authentication**: None (public REST API)
- **Data Source**: WordPress REST API endpoints
- **Key Features**: Remote site access, structured data retrieval

## Common Interface

**Base Class Architecture** (@since v0.2.1):

All fetch handlers extend [`FetchHandler`](../../../inc/Core/Steps/Fetch/Handlers/FetchHandler.php), which provides:
- Output normalization into `DataPacket` objects
- Deduplication and in-flight claim checks through `ExecutionContext`
- `max_items` capping after dedupe and before claims
- Engine data storage via `storeEngineData()`
- Remote file downloading via `ExecutionContext::downloadFile()`
- Timeframe filtering via `applyTimeframeFilter()`
- Keyword search filtering via `applyKeywordSearch()`
- Standardized logging via `log()`

### `get_fetch_data()` Method

All fetch handlers implement the same public interface:

```php
public function get_fetch_data(int|string $pipeline_id, array $handler_config, ?string $job_id = null): array
```

[`FetchStep`](../../../inc/Core/Steps/Fetch/FetchStep.php) resolves portable `auth_ref` handler config before invoking the handler. Internally, the final base-class entry point creates an [`ExecutionContext`](../../../inc/Core/ExecutionContext.php) and calls:
```php
abstract protected function executeFetch(array $config, ExecutionContext $context): array
```

**Parameters**:
- `$pipeline_id` - Pipeline ID, or `'direct'` for direct execution mode
- `$handler_config` - Flat handler configuration. Flow execution adds `flow_id`, `flow_step_id`, and `pipeline_id`; direct mode may use the `'direct'` sentinel values.
- `$job_id` - Job identifier for engine data storage and in-flight claims

**ExecutionContext provides**:
- `$context->getPipelineId()` - Returns `int|string` (numeric ID or `'direct'`)
- `$context->getFlowId()` - Returns `int|string` (numeric ID or `'direct'`)
- `$context->getJobId()` - Returns `?string`
- `$context->isItemProcessed($id)` - Check deduplication (always false in direct mode)
- `$context->isItemClaimed($id)` - Check whether another in-flight run already claimed the item (always false in direct/standalone modes)
- `$context->claimItemForProcessing($id)` - Atomically claim an item for this job (successful no-op in direct/standalone modes)
- `$context->markItemProcessed($id)` - Mark item processed (no-op in direct/standalone modes)
- `$context->log($level, $message, $extra)` - Contextual logging
- `$context->storeEngineData($data)` - Store data for downstream steps
- `$context->getFileContext()` - Get file storage context array
- `$context->isDirect()` - Check if running in direct execution mode

**Return**: Array of `DataPacket` objects. Child handlers return plain arrays; `FetchHandler::get_fetch_data()` normalizes those arrays into packets.

### Handler Output Shapes

Child handlers should return one of these plain-array shapes from `executeFetch()`:

```php
// Preferred: explicit list, supports zero, one, or many items.
return [
    'items' => [
        [
            'title' => 'Content Title',
            'content' => 'Content body...',
            'file_info' => null,
            'metadata' => [
                'item_identifier' => 'stable-source-id',
                'original_id' => 'source-id',
            ],
        ],
    ],
];

// Also accepted: one item as the whole result.
return [
    'title' => 'Content Title',
    'content' => 'Content body...',
    'metadata' => [ 'item_identifier' => 'stable-source-id' ],
];

// Empty result.
return [ 'items' => [] ];
```

Do not return legacy `processed_items` arrays. They are not the fetch base-class contract.

### Clean DataPacket Format (AI-visible)

```php
[
    'data' => [
        'title' => 'Content Title',
        'body' => "Source: Site Name\n\nTitle: Content Title\n\nContent body...",
        'file_info' => null // or file information array
    ],
    'metadata' => [
        'source_type' => 'handler_name',
        'item_identifier_to_log' => 'unique_id',
        'original_id' => 'source_id',
        'original_title' => 'Content Title',
        'original_date_gmt' => '2023-01-01 12:00:00'
        // URLs stored separately in engine data
    ]
]
```

### Engine Data (Database Storage)

Fetch handlers store engine parameters in database for centralized access via `datamachine_engine_data` filter:

```php
// Stored by fetch handlers via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}

// Retrieved by handlers via centralized filter
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;
```

## Deduplication System

### Processed Items Tracking

Fetch handlers use the processed items system through `ExecutionContext` and `FetchHandler::filterProcessed()`:

```php
if (!$context->isItemProcessed($item_identifier)) {
    return [
        'items' => [[
            'title' => $title,
            'content' => $content,
            'metadata' => [
                'item_identifier' => $item_identifier,
            ],
        ]],
    ];
}
```

Fetch handlers identify candidate items. The base class filters already-processed items and active claims, applies `max_items`, then claims only the selected items for the current job. Completed items are marked processed after the full pipeline finishes successfully so downstream failures do not permanently drop content.

**Scope**: Per-flow-step deduplication (same item can be processed by different flows)
**Persistence**: Stored in `wp_datamachine_processed_items` table
**Cleanup**: Automatic cleanup with job completion

### `max_items` and Claims

- `max_items` is a flat handler config value. If omitted, `FetchHandler::getDefaultMaxItems()` defaults to `1`.
- `max_items = 0` means unlimited.
- Claims are created after dedupe and after `max_items`, so items that do not fit in the current batch remain eligible for future runs.
- Final processed marking is deferred to successful pipeline completion, not fetch completion.
- Direct and standalone execution modes skip persisted dedupe and claims; the methods return safe no-op defaults.

### Source Type Identifiers

- `wordpress_local` - Local WordPress posts
- `wordpress_media` - Local media files  
- `email` - IMAP email messages
- `files` - File processing
- `rss` - RSS feed items
- `wordpress_api` - External WordPress API content

Extension plugins can register additional fetch handlers and source type identifiers.

## Configuration Patterns

### Handler Config Structure

```php
$handler_config = [
    'flow_step_id' => $flow_step_id, // Added by engine
    'pipeline_id' => $pipeline_id,   // Added by engine
    'flow_id' => $flow_id,           // Added by engine
    'setting1' => 'value1',          // Handler-specific settings are flat
    'setting2' => 'value2',
    'max_items' => 5,
];
```

The base class passes this flat array to `executeFetch()` as `$config`. Do not wrap handler settings under the handler slug unless the handler explicitly documents that nested shape.

### Common Settings

**Timeframe Filtering**:
- `all_time` - No time restriction
- `24_hours`, `72_hours`, `7_days`, `30_days` - Recent content

**Content Selection**:
- Specific ID targeting
- Random vs. chronological selection
- Search/keyword filtering

**Authentication**:
- OAuth credentials stored separately
- Handler-specific auth configuration
- Automatic token refresh

## Error Handling

### Configuration Errors

**Missing Required Settings**:
```php
if (empty($required_setting)) {
    do_action('datamachine_log', 'error', 'Handler: Missing required setting', [
        'handler' => 'handler_name',
        'setting' => 'required_setting'
    ]);
    return ['items' => []];
}
```

### API Errors

**External Service Failures**:
- Network timeouts logged and handled gracefully
- Authentication failures return empty results
- Rate limiting respected with appropriate delays

### Data Processing Errors

**Malformed Content**:
- Invalid data structures handled gracefully
- Missing required fields use fallback values
- Logging provides detailed error context

## Performance Considerations

### Bounded Batch Execution Model

Fetch handlers follow a bounded batch model:
- Child handlers may return zero, one, or many candidate items.
- The base class filters processed and claimed items.
- The base class applies `max_items` before claiming work.
- The base class returns one `DataPacket` per selected item.
- Final processed marking happens only after the downstream pipeline completes successfully.

### Memory Optimization

- Minimal data structures in memory
- Stream processing for large files
- Immediate garbage collection after processing

### API Efficiency

- Minimal API calls per execution
- Efficient queries with proper filtering
- Connection pooling where available

## Integration with AI Steps

### Pipeline Context Integration

Fetch handlers provide essential metadata that AI steps use for content processing and tool execution:

**Source URL Storage**: WordPress Local, WordPress API, and WordPress Media handlers store `source_url` in database via centralized `datamachine_engine_data` filter enabling both publish handlers (link attribution) and update handlers (post identification) to access URLs.

**Content Structure**: All handlers structure content in consistent format that AI steps process through the modular AI directive system.

**Metadata Preservation**: Handler metadata (original titles, dates) flows through pipeline to AI tools via WP_Agent_Tool_Parameters while URLs are accessed via engine data filter.

## Core vs Extension Boundaries

- Core owns the handler base classes, `ExecutionContext`, processed-item ledger, claims, engine data storage, `auth_ref` resolution, and handler-tool resolution.
- Extensions own source/platform-specific API calls, settings classes, auth providers, and handler registration.
- Extensions should register handlers and tools through `HandlerRegistrationTrait` where possible instead of reimplementing core filters.
- Extensions should return plain handler output arrays and let core normalize packets, apply dedupe, create claims, resolve `auth_ref`, and track posts.

### Tool-First Architecture Support

Fetch handlers seamlessly integrate with the tool-first AI architecture using centralized engine data storage:

```php
// Fetch stores engine data via centralized filter (separate from AI data, array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}

// AI step processes clean content without URL pollution
// Update tools access source_url via centralized datamachine_engine_data filter
// Publishing tools receive clean content via WP_Agent_Tool_Parameters
```

## Integration Examples

### Basic Fetch Handler Usage

```php
$wordpress_handler = new WordPress();
$result = $wordpress_handler->get_fetch_data(
    $pipeline_id,
    ['wordpress_posts' => ['post_type' => 'post']],
    $job_id
);
// Returns: DataPacket[]
// Engine data stored separately in database via centralized datamachine_engine_data filter
```

### With Deduplication

```php
foreach ($potential_items as $item) {
    if (!$context->isItemProcessed((string) $item['id'])) {
        return [
            [
                'title' => $item['title'],
                'content' => $item['content'],
                'metadata' => [
                    'item_identifier' => (string) $item['id'],
                ],
            ],
        ];
    }
}
return ['items' => []]; // No unprocessed items
```

## Extension Development

### Custom Fetch Handler

```php
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;

class CustomFetchHandler extends FetchHandler {
    protected function executeFetch(array $config, ExecutionContext $context): array {
        // Extract configuration
        $source_config = $config['custom_source'] ?? [];

        // Fetch data from source
        $items = $this->fetch_from_source($source_config);

        // Return candidate items. The base class applies dedupe, max_items, and claims.
        $candidates = [];
        foreach ($items as $item) {
            // Store engine data via centralized filter (array storage)
            $context->storeEngineData([
                'source_url' => $item['url'],
                'image_url' => $item['image'] ?? ''
            ]);

            $candidates[] = [
                'title' => $item['title'],
                'content' => $item['content'],
                'metadata' => [
                    'item_identifier' => (string) $item['id'],
                ],
            ];
        }

        return ['items' => $candidates];
    }
}
