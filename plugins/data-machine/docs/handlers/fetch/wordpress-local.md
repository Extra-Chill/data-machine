# WordPress Local Fetch Handler

Retrieves WordPress post and page content from the local installation using WP_Query. For media files, use the separate WordPress Media handler.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.2.1)

**Inherited Functionality**:
- Automatic deduplication via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()` for downstream handlers
- Standardized responses via `successResponse()`, `emptyResponse()`, `errorResponse()`
- Centralized logging and error handling

**Implementation**: Uses DataPacket class for consistent packet structure

## Configuration Options

### Basic Settings

**Post Type**
- Default: `post`
- Options: Any registered post type (post, page, custom post types)
- Purpose: Determines which content type to fetch

**Post Status** 
- Default: `publish`
- Options: publish, draft, private, pending
- Purpose: Filter by publication status

**Post ID**
- Default: Empty (query-based selection)
- Purpose: Target specific post by ID (bypasses all other filters)

### Selection Options

**Randomize Selection**
- Default: false (chronological by modified date)
- Purpose: Random post selection vs. most recently modified

**Search Terms**
- Default: Empty
- Purpose: Filter posts containing specific keywords

### Timeframe Filtering

**Timeframe Limit**
- Default: `all_time`
- Options:
  - `all_time` - No time restriction
  - `24_hours` - Last 24 hours
  - `72_hours` - Last 72 hours  
  - `7_days` - Last 7 days
  - `30_days` - Last 30 days

### Taxonomy Filtering

**Dynamic Taxonomy Filters**
- Format: `taxonomy_{slug}_filter`
- Value: Term ID
- Purpose: Filter posts by taxonomy terms
- Example: `taxonomy_category_filter` = 5 (category term ID)

## Data Output

### Engine Data Filter Architecture

The WordPress Local handler generates clean data packets for AI processing while storing engine parameters in database for later retrieval via datamachine_engine_data filter.

### Clean Data Packet (AI-Visible)

```php
[
    'data' => [
        'content_string' => "Source: Site Name\n\nTitle: Post Title\n\nPost Content...",
        'file_info' => null
    ],
    'metadata' => [
        'source_type' => 'wordpress_local',
        'item_identifier_to_log' => 123, // Post ID
        'original_id' => 123,
        'original_title' => 'Post Title',
        'original_date_gmt' => '2023-01-01 12:00:00'
        // Clean data packet for AI processing
    ]
]
```

### Engine Parameters Storage

```php
// Stored in database via centralized datamachine_engine_data filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}
```

### Return Structure

```php
return [
    'processed_items' => [$clean_data_packet]
    // Engine parameters stored separately in database
];
```

### Metadata Fields

**Clean Data Packet**:
- `source_type`: Always `wordpress_local`
- `original_title`: Post title as stored in database
- `original_date_gmt`: Post publication date in GMT
- URLs excluded to prevent AI content pollution

**Engine Parameters**:
- `source_url`: Full permalink for link attribution and post identification
- `image_url`: Featured image URL for media handling (null if none)

## Processing Behavior

### Deduplication

Uses processed items tracking to prevent duplicate processing:

```php
$is_processed = $context->isItemProcessed((string) $post_id);
```

**Tracking**: Each processed post is marked by flow step and post ID
**Scope**: Per-flow deduplication (same post can be processed by different flows)

### Query Optimization

**Performance Settings**:
- `posts_per_page` = 10 (finds first eligible item)
- `no_found_rows` = true
- `update_post_meta_cache` = false  
- `update_post_term_cache` = false

### Selection Strategy

1. **Query Execution** - Retrieves up to 10 posts matching criteria
2. **Deduplication Check** - Tests each post against processed items
3. **First Match** - Returns first unprocessed post immediately
4. **Single Item** - Always returns exactly one post or empty array

## Implementation Examples

### Basic WordPress Fetch

```php
$handler_config = [
    'wordpress_posts' => [
        'post_type' => 'post',
        'post_status' => 'publish',
        'timeframe_limit' => '7_days'
    ]
];

$result = $wordpress_handler->get_fetch_data($pipeline_id, $handler_config, $job_id);
// Returns: ['processed_items' => [...]]
// Engine parameters (source_url, image_url) stored in database via centralized engine_data filter
```

### Specific Post Targeting

```php
$handler_config = [
    'wordpress_posts' => [
        'post_id' => 123 // Overrides all other criteria
    ]
];
```

### Advanced Filtering

```php
$handler_config = [
    'wordpress_posts' => [
        'post_type' => 'product',
        'search' => 'keyword',
        'taxonomy_category_filter' => 5,
        'taxonomy_product_tag_filter' => 12,
        'timeframe_limit' => '30_days',
        'randomize_selection' => true
    ]
];
```

## Error Handling

### Missing Configuration

**Invalid Pipeline ID**: Returns empty array
**Missing Flow Step ID**: Disables processed items tracking (logs debug message)

### Database Errors

**No Matching Posts**: Returns empty array (normal operation)
**Invalid Post ID**: Returns empty array with warning log
**Trashed Posts**: Skipped with warning log

### Content Processing

**Missing Title**: Uses 'N/A' as fallback
**Empty Content**: Passes empty string (not filtered)
**Missing Featured Image**: Sets image_source_url to null

## Integration Notes

### WordPress Core Dependency

- Uses `WP_Query` for database operations
- Requires `get_post()`, `get_permalink()`, `get_post_thumbnail_id()`
- Utilizes `wp_get_attachment_image_url()` for featured images
- Leverages `get_bloginfo('name')` for source identification

### Performance Considerations

- **Single Post Strategy** - Returns immediately upon finding eligible post
- **Optimized Query** - Disables unnecessary WordPress features
- **Targeted Selection** - Uses specific criteria to reduce query scope
- **Memory Efficient** - Processes one post at a time

### WordPress Compatibility

- **Post Types** - Works with any registered post type
- **Taxonomies** - Dynamic support for all taxonomies
- **Custom Fields** - Content includes shortcode-expanded custom fields
- **Multisite** - Operates within current site context
