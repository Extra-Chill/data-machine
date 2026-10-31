# FetchHandler Base Class

## Overview

The `FetchHandler` class (`/inc/Core/Steps/Fetch/Handlers/FetchHandler.php`) is the abstract base class for all fetch handlers in the Data Machine system. Introduced in version 0.2.1, it provides standardized functionality for data fetching operations including deduplication, engine data storage, filtering, and logging.

## Architecture

**Location**: `/inc/Core/Steps/Fetch/Handlers/FetchHandler.php`
**Inheritance**: Abstract base class extending `Step`
**Since**: 0.2.1

## Core Functionality

### Single Item Execution Model

All fetch handlers implement the **Single Item Execution Model**, processing exactly one item per job execution. This ensures that failures are isolated to individual items and prevents batch processing timeouts.

### Deduplication Management

Automatic deduplication tracking to prevent processing the same items multiple times:

```php
// Check if item was already processed
if ($this->isItemProcessed($item_id, $flow_step_id)) {
    return $this->emptyResponse();
}

// Mark item as processed
$this->markItemProcessed($item_id, $flow_step_id, $job_id);
```

### Engine Data Storage

Store handler-specific parameters for downstream handlers:

```php
$this->storeEngineData($job_id, [
    'source_url' => $source_url,
    'image_url' => $image_url
]);
```

### Standardized Responses

Consistent response methods for success and error cases:

```php
// Success response with data packets
return $this->successResponse([$dataPacket]);

// Empty response (no new items)
return $this->emptyResponse();

// Error response
return $this->errorResponse('Error message', ['details' => $details]);
```

### Exclude Keywords Filtering (@since v0.3.1)

Filter content based on negative keywords to exclude unwanted items:

```php
// Check if content contains any exclude keywords
$exclude_keywords = $config['exclude_keywords'] ?? '';
if (!empty($exclude_keywords) && $this->applyExcludeKeywords($content, $exclude_keywords)) {
    // Content contains excluded keywords, skip this item
    continue;
}
```

The `applyExcludeKeywords()` method returns `true` if any exclude keyword is found in the text (case-insensitive), indicating the item should be filtered out.

## Required Implementation

All fetch handlers must implement the `executeFetch()` method:

```php
abstract protected function executeFetch(
    int $pipeline_id,
    array $config,
    ?string $flow_step_id,
    int $flow_id,
    ?string $job_id
): array;
```

## Standard Implementation Pattern

```php
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;

class MyFetchHandler extends FetchHandler {
    public function __construct() {
        parent::__construct('my_handler');
    }

    protected function executeFetch(
        int $pipeline_id,
        array $config,
        ?string $flow_step_id,
        int $flow_id,
        ?string $job_id
    ): array {
        // Check deduplication
        if ($this->isItemProcessed($item_id, $flow_step_id)) {
            return $this->emptyResponse();
        }

        // Fetch data from source
        $fetched_data = $this->fetch_from_source($config);

        // Mark as processed
        $this->markItemProcessed($item_id, $flow_step_id, $job_id);

        // Store engine data for downstream handlers
        $this->storeEngineData($job_id, [
            'source_url' => $source_url,
            'image_url' => $image_url
        ]);

        // Create standardized data packet
        $dataPacket = new \DataMachine\Core\DataPacket(
            ['content_string' => $content_string, 'file_info' => null],
            ['source_type' => 'my_handler', 'item_identifier_to_log' => $item_id],
            'fetch'
        );

        return $this->successResponse([$dataPacket->addTo([])]);
    }
}
```

## Engine Data Parameters

Fetch handlers should store relevant parameters for publish/upsert handlers:

| Parameter | Description | Used By |
|-----------|-------------|---------|
| `source_url` | Source URL of the content | Update handlers, logging |
| `image_url` | URL of associated image | Publish handlers with image support |

## Handler-Specific Engine Parameters

Different fetch handlers store different engine parameters:

- **Reddit**: `source_url` (post URL), `image_url` (stored image URL)
- **WordPress Local**: `source_url` (permalink), `image_url` (featured image URL)
- **WordPress API**: `source_url` (post link), `image_url` (featured image URL)
- **WordPress Media**: `source_url` (parent post permalink), `image_url` (media URL)
- **RSS**: `source_url` (item link), `image_url` (enclosure URL)
- **Universal Web Scraper**: `source_url` (page URL), `image_url` (detected image)
- **Google Sheets**: `source_url` (empty), `image_url` (empty)
- **Files**: `image_url` (public URL for images only)

## File Handling

For file-based fetch handlers, use the FilesRepository components:

```php
use DataMachine\Core\FilesRepository\FileStorage;

$file_storage = new FileStorage();
$stored_path = $file_storage->store_file($file_content, $filename, $job_id);
```

## Key Methods (@since v0.3.1)

### applyExcludeKeywords()

Filter content based on negative keywords:

```php
protected function applyExcludeKeywords(string $text, string $exclude_keywords): bool
```

**Parameters**:
- `$text`: Text content to search
- `$exclude_keywords`: Comma-separated list of keywords to exclude

**Returns**: `true` if any exclude keyword is found (item should be filtered out), `false` otherwise

**Features**:
- Case-insensitive matching
- Unicode-safe via `mb_stripos()`
- Handles comma-separated keyword lists
- Returns false for empty keyword lists

## Benefits

- **Deduplication**: Automatic prevention of duplicate processing
- **Consistency**: Standardized response patterns across all fetch handlers
- **Engine Integration**: Seamless data flow to downstream handlers
- **Error Handling**: Centralized error response formatting
- **Maintainability**: Reduced code duplication and consistent patterns
- **Negative Filtering** (@since v0.3.1): Built-in exclude keyword filtering

## Implementations

All fetch handlers extend this base class:
- RSS Handler
- Reddit Handler
- Universal Web Scraper Handler
- WordPress Local Handler
- WordPress Media Handler
- WordPress API Handler
- Google Sheets Handler
- Files Handler

See Fetch Handlers Overview for comparison.