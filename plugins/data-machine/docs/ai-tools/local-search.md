# Local Search AI Tool

**File Location**: `inc/Engine/AI/Tools/Global/LocalSearch.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents - pipeline + chat)

Enables AI models to search the current WordPress site for context gathering, research enhancement, and content discovery using WordPress's built-in search functionality.

## Configuration

**No Configuration Required**: Tool is always available as it uses WordPress core search functionality without external dependencies.

**Universal Availability**: Accessible to all AI steps without specific enablement requirements.

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | Search query terms |
| `post_types` | array | No | Post types to search (default: `['post', 'page']`) |

## Usage Examples

**Basic Search**:
```php
$parameters = [
    'query' => 'artificial intelligence'
];
```

**Targeted Search**:
```php
$parameters = [
    'query' => 'product launch',
    'post_types' => ['post', 'page', 'product']
];
```

## Search Functionality

**WordPress Query Integration**: Uses `WP_Query` with WordPress's native search parameter (`s`) for relevance-based ranking.

**Public Content Only**: Searches only published content that is publicly accessible.

**Performance Optimized**: Disables post meta and term caching for improved search performance.

## Post Type Support

**Default Types**: Searches posts and pages by default.

**Custom Post Types**: Supports any public post type that is not excluded from search.

**Dynamic Discovery**: Automatically adapts to available post types in the WordPress installation.

## Tool Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'query' => 'search_terms',
        'results_count' => 5,
        'total_available' => 23,
        'post_types_searched' => ['post', 'page'],
        'max_results_requested' => 10,
        'results' => [
            [
                'title' => 'Post Title',
                'link' => 'https://site.com/post-url',
                'excerpt' => 'Post excerpt or truncated content...',
                'post_type' => 'post',
                'publish_date' => '2024-01-15 10:30:00',
                'author' => 'Author Name'
            ]
        ]
    ],
    'tool_name' => 'local_search'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'local_search'
]
```

## Result Data Structure

**Title**: WordPress post title
**Link**: Full permalink URL to the post
**Excerpt**: Post excerpt or auto-generated excerpt (25 words with ellipsis)
**Post Type**: WordPress post type (post, page, etc.)
**Publish Date**: Publication timestamp in Y-m-d H:i:s format
**Author**: Post author display name

## Search Capabilities

**Relevance Ranking**: Uses WordPress's built-in relevance ranking algorithm.

**Content Indexing**: Searches post titles, content, and excerpts for query matches.

**Taxonomy Support**: Inherits WordPress's search behavior including taxonomy term matching.

## Result Limits

**Maximum Results**: Hard limit of 20 results per search to prevent excessive resource usage.

**Pagination**: Single-page results only - no pagination support.

**Performance Bounds**: Optimized for quick response times with disabled cache updates.

## Use Cases

**Context Research**: AI models can search site content for background information and context.

**Related Content Discovery**: Find existing content related to topics being processed.

**Site Knowledge**: Enable AI to understand what content already exists on the site.

**Content Gap Analysis**: Identify topics that are covered or missing from the site.

## Search Quality

**WordPress Core**: Leverages WordPress's mature search infrastructure for consistent results.

**Content Freshness**: Only searches published, current content.

**Excerpt Generation**: Automatically generates excerpts from content when none exist, providing meaningful result summaries.