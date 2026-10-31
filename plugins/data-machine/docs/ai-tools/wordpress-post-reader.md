# WordPress Post Reader AI Tool

**File Location**: `inc/Engine/AI/Tools/Global/WordPressPostReader.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents - pipeline + chat)

Enables AI models to retrieve complete WordPress post content by URL for detailed analysis and content processing. Perfect complement to Local Search for accessing full post content instead of excerpts.

## Configuration

**No Configuration Required**: Tool is always available as it uses WordPress core functionality without external dependencies.

**Universal Availability**: Accessible to all AI steps without specific enablement requirements.

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `source_url` | string | Yes | WordPress post URL to retrieve content from (use URLs from Local Search results) |
| `include_meta` | boolean | No | Include custom fields in response (default: false) |

## Usage Examples

**Basic Post Reading**:
```php
$parameters = [
    'source_url' => 'https://example.com/my-post'
];
```

**With Custom Fields**:
```php
$parameters = [
    'source_url' => 'https://example.com/my-post',
    'include_meta' => true
];
```

## Post Content Retrieval

**Full Content Access**: Retrieves complete post content including title, body, and metadata.

**URL Resolution**: Uses WordPress `url_to_postid()` for accurate post identification from URLs.

**Status Validation**: Verifies post exists and is not trashed before content retrieval.

## Content Analysis Features

**Content Metrics**: Provides character count and word count for content analysis.

**Publication Details**: Includes publish date, author, post type, and status information.

**Featured Image**: Retrieves featured image URL when available.

**Custom Fields**: Optional inclusion of public custom fields (excludes WordPress internal fields starting with `_`).

## Tool Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'post_id' => 123,
        'title' => 'Post Title',
        'content' => 'Full post content...',
        'content_length' => 1250,
        'content_word_count' => 185,
        'permalink' => 'https://site.com/post-url',
        'post_type' => 'post',
        'post_status' => 'publish',
        'publish_date' => '2024-01-15 10:30:00',
        'author' => 'Author Name',
        'featured_image' => 'https://site.com/image.jpg',
        'meta_fields' => []
    ],
    'tool_name' => 'wordpress_post_reader'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'wordpress_post_reader'
]
```

## Data Structure

**Post ID**: WordPress internal post ID
**Title**: Complete post title
**Content**: Full post content including HTML markup
**Content Length**: Character count for content analysis
**Content Word Count**: Word count excluding HTML tags
**Permalink**: Canonical post URL
**Post Type**: WordPress post type (post, page, custom)
**Post Status**: Publication status (publish, draft, private, etc.)
**Publish Date**: Publication timestamp in Y-m-d H:i:s format
**Author**: Post author display name
**Featured Image**: Full URL to featured image (null if none)
**Meta Fields**: Custom fields array (only when `include_meta` is true)

## Custom Fields Handling

**Public Fields Only**: Excludes WordPress internal fields starting with underscore (`_`).

**Clean Data Structure**: Single values returned as strings, multiple values as arrays.

**Conditional Inclusion**: Meta fields only included when explicitly requested via `include_meta` parameter.

## URL Processing

**WordPress URLs**: Designed for WordPress post URLs from the current site.

**URL Validation**: Sanitizes and validates URLs before processing.

**Post ID Resolution**: Uses WordPress core `url_to_postid()` for accurate identification.

## Error Handling

**Invalid URLs**: Returns error for URLs that don't resolve to valid post IDs.

**Trashed Posts**: Prevents access to trashed content with clear error messages.

**Missing Posts**: Handles non-existent posts with descriptive error responses.

## Use Cases

**Content Analysis**: AI models can analyze complete post content for detailed processing.

**Pre-Update Analysis**: Read existing content before WordPress Update operations.

**Content Enhancement**: Access full content for improvement or modification workflows.

**Research Integration**: Retrieve complete posts found via Local Search for comprehensive analysis.

## Workflow Integration

**Local Search → WordPress Post Reader**: Use Local Search to find relevant posts, then use WordPress Post Reader to access complete content.

**WordPress Update Preparation**: Read existing post content before applying updates or modifications.

**Content Quality Analysis**: Analyze full content for quality, completeness, or optimization opportunities.

## Performance Characteristics

**Direct Database Access**: Uses WordPress post retrieval functions for optimal performance.

**Minimal Processing**: Lightweight content retrieval without complex transformations.

**Memory Efficient**: Retrieves single posts without bulk operations.

## Security Considerations

**Current Site Only**: Only processes URLs from the current WordPress installation.

**Published Content**: Respects WordPress post status and visibility settings.

**Sanitized Input**: All URL parameters are sanitized before processing.