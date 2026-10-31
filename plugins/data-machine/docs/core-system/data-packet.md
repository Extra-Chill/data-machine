# DataPacket Class

## Overview

The `DataPacket` class (`/inc/Core/DataPacket.php`) provides standardized data packet creation and management across the entire pipeline system. Introduced in version 0.2.1, it replaces scattered array construction with centralized, consistent data packet handling.

## Architecture

**Location**: `/inc/Core/DataPacket.php`
**Since**: 0.2.1

## Purpose

Data packets standardize the data contract between pipeline steps and the execution engine. They ensure consistent data structure and workflow processing across all handlers and steps.

## Constructor

```php
public function __construct(array $data, array $metadata, string $type)
```

### Parameters

- **`$data`** (array): Content data (title, body, file_info, etc.)
- **`$metadata`** (array): Metadata (source_type, timestamps, etc.)
- **`$type`** (string): Packet type (fetch, ai_response, tool_result, etc.)

## addTo() Method

Adds the packet to the data packets array, maintaining pipeline workflow order:

```php
public function addTo(array $dataPackets): array
```

### Workflow Processing

The method adds packets to the **front** of the array so the most recent contributions are easily accessible by subsequent steps:

```php
// Processing flow: Step 1 → Step 2 → Step 3
$dataPackets = [];
$dataPackets = $step1_packet->addTo($dataPackets); // [packet1]
$dataPackets = $step2_packet->addTo($dataPackets); // [packet2, packet1]
$dataPackets = $step3_packet->addTo($dataPackets); // [packet3, packet2, packet1]

// Next step sees packet3 first (most recent)
```

## Data Structure

### Complete Packet Structure

```php
[
    'type' => 'fetch',        // Packet type
    'timestamp' => 1234567890, // Unix timestamp
    'data' => [
        'content_string' => 'Content text...',
        'file_info' => [
            'filename' => 'image.jpg',
            'path' => '/path/to/file',
            'url' => 'https://example.com/file.jpg'
        ]
    ],
    'metadata' => [
        'source_type' => 'wordpress',
        'item_identifier_to_log' => 'post_123',
        'original_id' => '123',
        'original_title' => 'Post Title',
        'original_date_gmt' => '2024-01-01 12:00:00'
    ]
]
```

## Usage Examples

### Fetch Handler

```php
use DataMachine\Core\DataPacket;

$dataPacket = new DataPacket(
    ['content_string' => $content, 'file_info' => null],
    [
        'source_type' => 'rss',
        'item_identifier_to_log' => $item_id,
        'original_title' => $title,
        'original_date_gmt' => $date
    ],
    'fetch'
);

return $this->successResponse([$dataPacket->addTo([])]);
```

### AI Step

```php
$dataPacket = new DataPacket(
    ['content_string' => $ai_generated_content],
    ['source_type' => 'ai_response'],
    'ai_response'
);

return $dataPacket->addTo($this->dataPackets);
```

### Tool Result

```php
$dataPacket = new DataPacket(
    ['tool_result' => $tool_output],
    ['tool_name' => 'web_search', 'query' => $query],
    'tool_result'
);

return $dataPacket->addTo($this->dataPackets);
```

## Data vs Metadata

### Data Section
Contains the actual content that AI agents and handlers work with:

- `content_string`: Clean text content (no URLs)
- `file_info`: File metadata when applicable
- `tool_result`: Tool execution results
- Custom handler-specific data

### Metadata Section
Contains contextual information for logging and processing:

- `source_type`: Handler type (rss, wordpress, twitter, etc.)
- `item_identifier_to_log`: Unique identifier for deduplication
- `original_*`: Original source data (title, date, id, etc.)
- Handler-specific metadata

## Engine Data Separation

Data packets contain **clean data** for AI processing, while **engine parameters** are stored separately in the database:

```php
// Clean data in packet (AI-visible)
'data' => [
    'content_string' => 'Clean content without URLs'
]

// Engine data in database (handler-accessible)
[
    'source_url' => 'https://source.com/post/123',
    'image_url' => 'https://source.com/image.jpg'
]
```

## Benefits

- **Consistency**: Standardized data structure across all pipeline components
- **Type Safety**: Defined packet types and data structure
- **Workflow Clarity**: Clear separation of data and metadata
- **Maintainability**: Centralized packet creation logic
- **Debugging**: Structured data for logging and inspection

## Usage Examples

DataPacket is used throughout the codebase for standardized packet creation:

**Fetch Handlers**: See FetchHandler implementations:
- RSS Handler
- Reddit Handler
- Files Handler

**AI Steps**: For conversation result packaging

**Any Step**: Any component creating or modifying data packets

All handlers extending FetchHandler automatically use DataPacket for packet creation.