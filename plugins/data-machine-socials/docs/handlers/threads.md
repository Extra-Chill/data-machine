# Threads Publish Handler

Posts content to Meta's Threads platform using OAuth2 authentication with two-step publishing process and media support.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.2.1)

**Inherited Functionality**:
- Engine data retrieval via `getSourceUrl()` and `getImageFilePath()`
- Image validation via `validateImage()` with comprehensive error checking
- Standardized responses via `successResponse()` and `errorResponse()`
- Centralized logging and error handling

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

## Authentication

**OAuth2 Required**: Uses Threads API with OAuth2 authentication (same credentials as Facebook).

**Page-Based Publishing**: Requires Threads-enabled Facebook Page for posting content.

**Meta Integration**: Part of Meta's ecosystem, sharing authentication infrastructure with Facebook.

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `include_images` | boolean | No | Enable image upload and embedding (default: true) |
| `link_handling` | string | No | Link inclusion mode: `append`, `none` (default: "append") |

## Source URL Attribution

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

**Link Handling** (`link_handling: 'append'`):
- Source URL appended to post content with double newline separator (`\n\n`)
- URL counts toward 500 character limit (no shortening)
- Content truncated with "..." if total exceeds limit
- Example: `"Great article content\n\nhttps://example.com/article"`

**No Link Mode** (`link_handling: 'none'`):
- No source_url processing or appending
- Content posted as-is without URL attribution
- Useful when URL already embedded in content

## Character Limits

**Post Limit**: 500 characters maximum per Threads post.

**Simple Truncation**: Automatically truncates content with "..." when over limit.

**No URL Shortening**: Uses byte-based truncation without special URL handling.

## Usage Examples

**Basic Tool Call**:
```php
$parameters = [
    'content' => 'This is my Threads post content'
];

$tool_def = [
    'handler_config' => [
        'include_images' => true
    ]
];

$result = $handler->handle_tool_call($parameters, $tool_def);
```

**With Title and Media**:
```php
$parameters = [
    'title' => 'Breaking News',
    'content' => 'Important announcement about new features.',
    'image_url' => 'https://example.com/image.jpg'
];

$tool_def = [
    'handler_config' => [
        'include_images' => true
    ]
];
```

## Content Formatting

**Title Integration**: When title provided, formats as "{title}\n\n{content}".

**Simple Truncation**: Cuts content at 497 characters and adds "..." when over 500-character limit.

**Text Priority**: Preserves text content integrity without complex URL handling.

## Two-Step Publishing Process

**Step 1 - Media Container Creation**:
- Creates media container with content and optional image
- Returns `creation_id` for publishing step
- Handles both TEXT and IMAGE media types

**Step 2 - Container Publishing**:
- Publishes the created media container
- Returns final `media_id` for post URL construction
- Completes the posting process

## Media Support

**Image Integration**: When `include_images` is true and valid image URL provided:
- Changes media type from TEXT to IMAGE
- Includes `image_url` parameter in container creation
- Threads handles image download and processing

**Media Types**:
- `TEXT`: Text-only posts
- `IMAGE`: Posts with attached images

## Tool Call Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'media_id' => 'threads_media_id',
        'post_url' => 'https://www.threads.net/t/{media_id}',
        'content' => 'actual_posted_content'
    ],
    'tool_name' => 'threads_publish'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'threads_publish'
]
```

## API Integration

**Container Endpoint**: Uses Threads API container creation endpoint for media preparation.

**Publishing Endpoint**: Uses separate publishing endpoint to make containers live.

**URL Construction**: Builds post URLs using format `https://www.threads.net/t/{media_id}`.

## Error Handling

**Authentication Errors**:
- Missing access tokens
- Invalid or expired OAuth credentials
- Page ID unavailability

**Container Creation Errors**:
- Invalid media container parameters
- Image URL accessibility issues
- API quota or rate limiting

**Publishing Errors**:
- Container publishing failures
- Invalid creation IDs
- Temporary API unavailability

**Content Errors**:
- Missing required content parameter
- Empty formatted post content
- Character encoding issues

## Media Container Types

**TEXT Containers**:
```php
[
    'media_type' => 'TEXT',
    'text' => 'post_content'
]
```

**IMAGE Containers**:
```php
[
    'media_type' => 'IMAGE',
    'text' => 'post_content',
    'image_url' => 'image_url'
]
```

## Publishing Flow

1. **Parameter Validation**: Validates required content parameter and configuration
2. **Authentication Check**: Verifies access token and page ID availability
3. **Content Formatting**: Applies title formatting and character limit truncation
4. **Container Creation**: Creates media container with appropriate type and content
5. **Publishing**: Publishes the container to make it live on Threads
6. **Response**: Returns media ID and constructed post URL

**Logging**: Uses `datamachine_log` action with debug/error levels for API interactions, authentication checks, and error handling.