# Bluesky Publish Handler

Posts content to Bluesky using app passwords and AT Protocol integration with media upload support and automatic link detection.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.2.1)

**Inherited Functionality**:
- Engine data retrieval via `getSourceUrl()` and `getImageFilePath()`
- Image validation via `validateImage()` with comprehensive error checking
- Standardized responses via `successResponse()` and `errorResponse()`
- Centralized logging and error handling

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

## Authentication

**App Password Required**: Uses Bluesky app passwords for authentication (username + app password).

**AT Protocol**: Integrates with AT Protocol APIs for native Bluesky functionality.

**Session Management**: Automatic session creation and token management with PDS (Personal Data Server) URL discovery.

**Configuration Fields**: The `BlueskyAuth` class provides `get_config_fields()` method for universal handler settings template integration:
```php
[
    'username' => [
        'label' => 'Bluesky Handle',
        'type' => 'text',
        'required' => true,
        'description' => 'Your Bluesky handle (e.g., user.bsky.social)'
    ],
    'app_password' => [
        'label' => 'App Password',
        'type' => 'password',
        'required' => true,
        'description' => 'Generate an app password at bsky.app/settings/app-passwords'
    ]
]
```

**Simple Auth Pattern**: Bluesky uses simple authentication (app password) rather than OAuth flow, with credentials stored directly via `datamachine_store_oauth_account` filter.

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `bluesky_include_source` | boolean | No | Include source URL in posts (default: true) |
| `bluesky_enable_images` | boolean | No | Enable image upload and embedding (default: true) |

## Source URL Attribution

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

**Link Handling** (`link_handling: 'append'`):
- Source URL appended to post content with double newline separator (`\n\n`)
- URL automatically detected and formatted by Bluesky
- URLs count as 22 characters toward 300 character limit
- Content truncated with ellipsis (…) if total exceeds limit
- Example: `"Great article content\n\nhttps://example.com/article"`

**No Link Mode** (`link_handling: 'none'` or `bluesky_include_source: false`):
- No source_url processing or appending
- Content posted as-is without URL attribution
- Useful when URL already embedded in content

## Character Limits

**Post Limit**: 300 characters maximum per Bluesky post.

**URL Handling**: URLs count as 22 characters regardless of actual length.

**Smart Truncation**: Automatically truncates content with ellipsis (…) when over limit, preserving source links.

## Usage Examples

**Basic Tool Call**:
```php
$parameters = [
    'content' => 'This is my Bluesky post content'
];

$tool_def = [
    'handler_config' => [
        'bluesky_include_source' => true,
        'bluesky_enable_images' => true
    ]
];

$result = $handler->handle_tool_call($parameters, $tool_def);
```

**With Title and Media**:
```php
$parameters = [
    'title' => 'Breaking News',
    'content' => 'Important announcement about new features.',
    'source_url' => 'https://example.com/article',
    'image_url' => 'https://example.com/image.jpg'
];
```

## Content Formatting

**Title Integration**: When title provided, formats as "{title}: {content}".

**Source URL Inclusion**: Appends source URL on new lines when `include_source` enabled.

**Character Budget**: Calculates available characters after reserving space for source URL (24 chars: 2 newlines + 22 for URL).

## Media Support

**Image Upload**: Uploads images to Bluesky blob storage when `enable_images` is true.

**Alt Text**: Uses post title or first 50 characters of content as alt text for accessibility.

**Image Embedding**: Creates proper AT Protocol image embed structure with blob references.

**Format Support**: Handles standard web image formats (JPEG, PNG, GIF, WebP).

## Link Detection

**Automatic Facets**: Detects URLs in post text and creates AT Protocol facets for proper link formatting.

**Clickable Links**: Ensures URLs become clickable links in Bluesky interface.

**Multiple Links**: Supports multiple URLs within single post with proper indexing.

## Tool Call Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'post_url' => 'https://bsky.app/profile/{handle}/post/{id}',
        'post_uri' => 'at://did:plc:xyz/app.bsky.feed.post/{id}',
        'cid' => 'content_identifier',
        'character_count' => 150,
        'includes_image' => true,
        'includes_links' => true
    ],
    'tool_name' => 'bluesky_publish'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'bluesky_publish'
]
```

## AT Protocol Integration

**Post Creation**: Uses `com.atproto.repo.createRecord` API endpoint for post creation.

**Blob Upload**: Uses `com.atproto.repo.uploadBlob` for media file uploads.

**Record Structure**: Creates proper `app.bsky.feed.post` records with required AT Protocol fields.

## Error Handling

**Authentication Errors**:
- Invalid app password credentials
- Session creation failures
- Missing PDS URL or DID

**Content Errors**:
- Empty or missing content parameter
- Content formatting failures
- Character limit violations

**Media Errors**:
- Image upload failures
- Invalid image URLs
- Unsupported image formats

**API Errors**:
- AT Protocol API failures
- Network connectivity issues
- Rate limiting responses

## Logging

**Debug Information**: Logs parameter extraction, configuration usage, and API interactions.

**Error Tracking**: Detailed error logging for authentication, content formatting, and API failures.

**Performance Metrics**: Character count tracking and media upload status logging.