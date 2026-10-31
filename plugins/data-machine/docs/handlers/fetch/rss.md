# RSS Feed Fetch Handler

Fetches and processes RSS/Atom feed data with automatic deduplication, timeframe filtering, content search capabilities, and clean content processing without URL pollution.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.2.1)

**Inherited Functionality**:
- Automatic deduplication via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()` for downstream handlers
- Standardized responses via `successResponse()`, `emptyResponse()`, `errorResponse()`
- Centralized logging and error handling

**Implementation**: Uses DataPacket class for consistent packet structure

## Feed Format Support

**RSS Formats**:
- RSS 2.0 (`<channel><item>`)
- RSS 1.0 (`<item>`)
- Atom feeds (`<entry>`)

**Content Extraction**:
- Title from `<title>` element
- Description from `<description>`, `<summary>`, `<content>`, or `content:encoded`
- Link from `<link>` element (supports Atom href attribute)
- Publication date from `<pubDate>`, `<published>`, `<updated>`, or Dublin Core `dc:date`
- Author from `<author>` or Dublin Core `dc:creator`
- Categories from `<category>` elements
- Media enclosures from `<enclosure>` elements

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `feed_url` | string | Yes | Valid RSS/Atom feed URL |
| `timeframe_limit` | string | No | Filter items by age: `all_time`, `24_hours`, `72_hours`, `7_days`, `30_days` |
| `search` | string | No | Comma-separated keywords to filter content |

## Usage Examples

**Basic RSS Feed**:
```php
$handler_config = [
    'rss' => [
        'feed_url' => 'https://example.com/feed.xml'
    ]
];
```

**With Time and Search Filters**:
```php
$handler_config = [
    'rss' => [
        'feed_url' => 'https://news.site/rss',
        'timeframe_limit' => '24_hours',
        'search' => 'technology, AI, development'
    ]
];
```

## Data Processing

**Item Selection**: Processes only the first eligible item that passes all filters (timeframe, search, deduplication).

**Deduplication**: Uses GUID, ID, or source URL as unique identifier. Previously processed items are skipped automatically.

**Content Format**:
```
Source: RSS Feed

Title: {item_title}

Content:
{item_description}

Source URL: {item_link}
```

## Output Structure

**DataPacket Content**:
```php
[
    'data' => [
        'content_string' => '...',  // Formatted content
        'file_info' => [            // If enclosure present
            'url' => 'media_url',
            'type' => 'mime_type',
            'mime_type' => 'mime_type'
        ]
    ],
    'metadata' => [
        'source_type' => 'rss',
        'original_id' => 'item_guid',
        'item_identifier_to_log' => 'item_guid',
        'original_title' => 'item_title',
        'original_date_gmt' => 'iso_date',
        'author' => 'item_author',
        'categories' => ['cat1', 'cat2'],
        'feed_url' => 'source_feed_url'
        // Note: source_url and enclosure_url stored in engine data separately
    ]
]
```

## Error Handling

**Validation Errors**:
- Missing or invalid feed URL
- Failed HTTP request to feed
- Invalid XML format
- Unsupported feed structure

**Logging**: Uses `datamachine_log` action with debug/error levels for feed parsing and item processing status.

## Media Support

**Enclosure Detection**: Automatically detects media attachments from RSS enclosures with MIME type detection based on file extension.

**Supported Types**: Images (JPEG, PNG, GIF, WebP), Audio (MP3), Video (MP4), Documents (PDF, ZIP).

## Engine Data Storage

In addition to the clean data packets above, the RSS handler stores engine parameters in the database for access by downstream handlers via the centralized `datamachine_engine_data` filter:

**Stored Engine Data**:
```php
[
    'source_url' => 'item_link',        // For link attribution and content updates
    'image_url' => 'enclosure_url'      // For media handling
]
```

**Access by Steps**:
```php
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;
```