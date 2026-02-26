# Fetch Handlers Overview

Fetch handlers retrieve content from various sources and convert it into standardized DataPackets for pipeline processing.

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

All fetch handlers extend `FetchHandler` base class (`/inc/Core/Steps/Fetch/Handlers/FetchHandler.php`) which provides:
- Deduplication checking via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()`
- Remote file downloading via `downloadRemoteFile()`
- Timeframe filtering via `applyTimeframeFilter()`
- Keyword search filtering via `applyKeywordSearch()`
- Standardized logging via `log()`
- Response formatting via `successResponse()` and `emptyResponse()`

### `get_fetch_data()` Method

All fetch handlers implement the same public interface:

```php
public function get_fetch_data(int|string $pipeline_id, array $handler_config, ?string $job_id = null): array
```

Internally, the base class creates an `ExecutionContext` and calls:
```php
abstract protected function executeFetch(array $config, ExecutionContext $context): array
```

**Parameters**:
- `$pipeline_id` - Pipeline ID or `'direct'` for direct execution mode
- `$handler_config` - Handler-specific configuration (must include `flow_id`, `flow_step_id`, `pipeline_id`)
- `$job_id` - Job identifier for deduplication tracking and engine data storage

**ExecutionContext provides**:
- `$context->getPipelineId()` - Returns `int|string` (numeric ID or `'direct'`)
- `$context->getFlowId()` - Returns `int|string` (numeric ID or `'direct'`)
- `$context->getJobId()` - Returns `?string`
- `$context->isItemProcessed($id)` - Check deduplication (always false in direct mode)
- `$context->markItemProcessed($id)` - Mark item processed (no-op in direct mode)
- `$context->log($level, $message, $extra)` - Contextual logging
- `$context->storeEngineData($data)` - Store data for downstream steps
- `$context->getFileContext()` - Get file storage context array
- `$context->isDirect()` - Check if running in direct execution mode

**Return**: Array of DataPacket objects

### Clean DataPacket Format (AI-visible)

```php
[
    'data' => [
        'content_string' => "Source: Site Name\n\nTitle: Content Title\n\nContent body...",
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

All fetch handlers use the processed items system:

```php
// Check if already processed
$is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, $source_type, $item_id);

if (!$is_processed) {
    // Mark as processed
    do_action('datamachine_mark_item_processed', $flow_step_id, $source_type, $item_id, $job_id);
    
    // Process item
    return [$data_packet];
}
```

**Scope**: Per-flow-step deduplication (same item can be processed by different flows)
**Persistence**: Stored in `wp_datamachine_processed_items` table
**Cleanup**: Automatic cleanup with job completion

### Source Type Identifiers

- `wordpress_local` - Local WordPress posts
- `wordpress_media` - Local media files  
- `files` - File processing
- `rss` - RSS feed items
- `reddit` - Reddit posts
- `google_sheets` - Spreadsheet data
- `wordpress_api` - External WordPress API content
- `universal_web_scraper` - Universal web scraper items

## Configuration Patterns

### Handler Config Structure

```php
$handler_config = [
    'flow_step_id' => $flow_step_id, // Added by engine
    'handler_specific_key' => [
        'setting1' => 'value1',
        'setting2' => 'value2'
    ]
];
```

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
    return ['processed_items' => []];
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

### Single Item Execution Model

All fetch handlers follow the system-wide **Single Item Execution Model**:
- Finds first eligible unprocessed item.
- Marks as processed immediately to prevent concurrency issues.
- Returns exactly one DataPacket (or an empty array if no new content exists).
- Ensures job-level isolation and reliability.

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

**Metadata Preservation**: Handler metadata (original titles, dates) flows through pipeline to AI tools via ToolParameters while URLs are accessed via engine data filter.

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
// Publishing tools receive clean content via ToolParameters
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
// Returns: ['processed_items' => [...]]
// Engine data stored separately in database via centralized datamachine_engine_data filter
```

### With Deduplication

```php
foreach ($potential_items as $item) {
    $is_processed = apply_filters('datamachine_is_item_processed', false, 
        $flow_step_id, 'my_source', $item['id']);
    
    if (!$is_processed) {
        do_action('datamachine_mark_item_processed', $flow_step_id, 
            'my_source', $item['id'], $job_id);
        return [$this->create_data_packet($item)];
    }
}
return ['processed_items' => []]; // No unprocessed items
```

## Extension Development

### Custom Fetch Handler

```php
class CustomFetchHandler {
    public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        // Extract configuration
        $config = $handler_config['custom_source'] ?? [];
        $flow_step_id = $handler_config['flow_step_id'] ?? null;

        // Fetch data from source
        $items = $this->fetch_from_source($config);

        // Process first unprocessed item
        foreach ($items as $item) {
            if ($flow_step_id) {
                $is_processed = apply_filters('datamachine_is_item_processed', false,
                    $flow_step_id, 'custom_source', $item['id']);

                if ($is_processed) continue;

                do_action('datamachine_mark_item_processed', $flow_step_id,
                    'custom_source', $item['id'], $job_id);
            }

            // Store engine data via centralized filter (array storage)
            if ($job_id) {
                apply_filters('datamachine_engine_data', null, $job_id, [
                    'source_url' => $item['url'],
                    'image_url' => $item['image'] ?? ''
                ]);
            }

            return ['processed_items' => [$this->create_data_packet($item)]];
        }

        return ['processed_items' => []];
    }
}