# WordPress REST API Fetch Handler

Fetches content from public WordPress sites via REST API endpoints, providing structured data access as a modern alternative to RSS feeds.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.2.1)

**Inherited Functionality**:
- Automatic deduplication via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()` for downstream handlers
- Standardized responses via `successResponse()`, `emptyResponse()`, `errorResponse()`
- Centralized logging and error handling

**Implementation**: Uses DataPacket class for consistent packet structure

## API Integration

**REST API v2**: Uses WordPress REST API v2 endpoints (`/wp-json/wp/v2/`) for standardized data access.

**Public Access**: Fetches publicly accessible content without authentication requirements.

**Embedded Data**: Automatically includes embedded data (featured images, author info, etc.) using `_embed` parameter.

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `site_url` | string | Yes | Target WordPress site URL (without trailing slash) |
| `post_type` | string | No | Post type to fetch (default: "posts") |
| `post_status` | string | No | Post status filter (default: "publish") |
| `timeframe_limit` | string | No | Filter by date: `all_time`, `24_hours`, `72_hours`, `7_days`, `30_days` |
| `search` | string | No | Search term for post title and content |
| `orderby` | string | No | Sort field: `date`, `title`, `modified` (default: "date") |
| `order` | string | No | Sort order: `asc`, `desc` (default: "desc") |

## Usage Examples

**Basic Site Content**:
```php
$handler_config = [
    'wordpress_api' => [
        'site_url' => 'https://example.com'
    ]
];
```

**Custom Post Type with Search**:
```php
$handler_config = [
    'wordpress_api' => [
        'site_url' => 'https://news.site.com',
        'post_type' => 'articles',
        'timeframe_limit' => '7_days',
        'search' => 'technology',
        'orderby' => 'modified',
        'order' => 'desc'
    ]
];
```

## Data Processing

**Single Item Selection**: Processes the first eligible post that passes deduplication checks (up to 10 posts queried per request).

**Deduplication**: Uses MD5 hash of `{site_url}_{post_id}` as unique identifier for cross-site content tracking.

**Content Extraction**: Extracts rendered HTML content and strips tags for clean text output.

## Content Format

**Post Content**:
```
Source: {site_name}

Title: {post_title}

{post_content_stripped_of_html}
```

## Featured Image Support

**Automatic Detection**: Extracts featured image URLs from embedded media data in REST API response.

**Fallback Strategy**:
1. Primary: `_embedded['wp:featuredmedia'][0]['source_url']`  
2. Secondary: `_embedded['wp:featuredmedia'][0]['media_details']['sizes']['full']['source_url']`
3. Fallback: Direct `featured_media` URL construction

## Output Structure

**DataPacket Content**:
```php
[
    'data' => [
        'content_string' => '...',     // Formatted post content
        'file_info' => null            // No file info for API content
    ],
    'metadata' => [
        'source_type' => 'wordpress_api',
        'original_id' => 'remote_post_id',
        'source_url' => 'post_permalink',
        'original_title' => 'post_title',
        'image_source_url' => 'featured_image_url',
        'original_date_gmt' => 'post_date_gmt',
        'site_url' => 'source_site_url',
        'site_name' => 'extracted_site_name'
    ]
]
```

## Site Name Detection

**Extraction Methods**:
1. From site title in REST API response metadata
2. Fallback to hostname from site URL
3. Default to domain name if other methods fail

## Date Filtering

**ISO 8601 Format**: Uses RFC 3339 datetime format for `after` parameter in API requests.

**Timezone Handling**: Filters based on GMT timestamps to ensure consistent cross-timezone operation.

**Cutoff Calculation**: Calculates cutoff timestamps relative to current WordPress time.

## Error Handling

**URL Validation**:
- Invalid or malformed site URLs
- Inaccessible WordPress sites
- Non-WordPress sites without REST API

**API Errors**:
- HTTP request failures  
- Invalid JSON responses
- Missing or malformed post data
- REST API endpoint unavailability

**Content Errors**:
- Empty response data
- Missing required post fields
- Inaccessible featured images

**Logging**: Uses `datamachine_log` action with debug/error levels for API calls, data extraction, and error conditions.

## Performance Considerations

**Request Limits**: Fixed at 10 posts per API request to balance performance and content discovery.

**Single Request**: Processes first eligible item from single API call, avoiding unnecessary pagination.

**Efficient Filtering**: Uses REST API native filtering parameters to reduce data transfer and processing.