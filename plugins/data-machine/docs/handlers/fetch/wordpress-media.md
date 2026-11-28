# WordPress Media Fetch Handler

Retrieves media files from the local WordPress media library with metadata extraction, parent content integration, and file type filtering.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.2.1)

**Inherited Functionality**:
- Automatic deduplication via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()` for downstream handlers
- Standardized responses via `successResponse()`, `emptyResponse()`, `errorResponse()`
- Centralized logging and error handling

**Implementation**: Uses DataPacket class for consistent packet structure

## Media Library Integration

**WP_Query Based**: Uses WordPress's native WP_Query system for attachment post type queries with performance optimizations.

**Attached Media Only**: Fetches only media items that are attached to parent posts (excludes orphaned media files).

**Local Access**: Direct file system access to local WordPress installation media library.

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file_types` | array | No | File type filters: `image`, `video`, `audio`, `document` (default: `['image']`) |
| `include_parent_content` | boolean | No | Include parent post content in output (default: false) |
| `randomize_selection` | boolean | No | Randomize media selection order (default: false, uses modified date DESC) |
| `timeframe_limit` | string | No | Filter by upload date: `all_time`, `24_hours`, `72_hours`, `7_days`, `30_days` |
| `search` | string | No | Search media titles and metadata |

## Usage Examples

**Basic Image Fetch**:
```php
$handler_config = [
    'wordpress_media' => [
        'file_types' => ['image']
    ]
];
```

**Multi-Type Media with Parent Content**:
```php
$handler_config = [
    'wordpress_media' => [
        'file_types' => ['image', 'video', 'document'],
        'include_parent_content' => true,
        'timeframe_limit' => '7_days',
        'search' => 'product photos'
    ]
];
```

## File Type Support

**Image Types**: `image/*` MIME types (JPEG, PNG, GIF, WebP, etc.)

**Video Types**: `video/*` MIME types (MP4, AVI, MOV, etc.)

**Audio Types**: `audio/*` MIME types (MP3, WAV, OGG, etc.)

**Document Types**:
- PDF (`application/pdf`)
- Word Documents (`application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
- Plain Text (`text/plain`)

## Data Processing

**Single Item Selection**: Processes the first eligible media item that passes all filters and deduplication checks.

**Deduplication**: Uses WordPress post ID for tracking previously processed media items.

**Parent Content Integration**: When enabled, includes formatted parent post content with AI instruction prompts.

**Engine Data Storage**: URLs are stored in database via centralized `datamachine_engine_data` filter for access by handlers.

## Content Format

**Basic Media Content**:
```
Source: {site_name}
Media Type: {mime_type}
Title: {media_title}
Alt Text: {alt_text}
Caption: {caption}
Description: {description}
File URL: {media_url}
File Size: {formatted_size}
```

**With Parent Content**:
```
[Basic media content above]

=== SOURCE POST CONTENT ===
Title: {parent_title}

Content:
{parent_content}
=== END SOURCE POST ===

[AI Instructions: Reference and build upon the source post content above when creating social media content. Use the source content as context and inspiration for your response.]
```

## Output Structure

**Clean DataPacket** (AI-visible):
```php
[
    'data' => [
        'content_string' => '...',     // Formatted media + optional parent content
        'file_info' => null            // No file_info for local media
    ],
    'metadata' => [
        'source_type' => 'wordpress_media',
        'original_id' => 'post_id',
        'original_title' => 'media_title',
        'original_date_gmt' => 'upload_timestamp',
        'file_path' => '/local/file/path',      // For AI processing
        'mime_type' => 'mime/type',             // File type
        'file_size' => 'bytes'
    ]
]
```

**Engine Data** (stored in database, accessed via `datamachine_engine_data` filter):
```php
[
    'source_url' => 'parent_post_permalink',    // When include_parent_content enabled
    'image_url' => 'media_attachment_url'       // For publish handlers
]
```

## Media Metadata

**Title**: WordPress attachment title (post_title)
**Caption**: WordPress attachment caption (post_excerpt)  
**Description**: WordPress attachment description (post_content)
**Alt Text**: Image alt text from attachment metadata
**File Information**: URL, local path, MIME type, file size

## Query Optimization

**Performance Features**:
- Disabled post meta caching (`update_post_meta_cache: false`)
- Disabled term caching (`update_post_term_cache: false`)
- No found rows calculation (`no_found_rows: true`)
- Limited result set (10 items per query)

**Ordering Options**:
- Default: Most recently modified first (`modified DESC`)
- Random: Random selection when `randomize_selection` enabled

## Error Handling

**Missing Media**:
- Empty media library
- No items matching file type filters
- Files missing from file system

**Parent Post Issues**:
- Orphaned media files (excluded by design)
- Missing parent post references

**File System Errors**:
- Inaccessible media files
- Missing attachment metadata

**Logging**: Uses `datamachine_log` action with debug/error levels for media discovery, parent content integration, and file system access.