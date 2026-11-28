# Twitter Publish Handler

Posts content to Twitter with media support, authentication via OAuth 1.0a, and comprehensive formatting features.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.2.1)

**Inherited Functionality**:
- Engine data retrieval via `getSourceUrl()` and `getImageFilePath()`
- Image validation via `validateImage()` with comprehensive error checking
- Standardized responses via `successResponse()` and `errorResponse()`
- Centralized logging and error handling

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

## Authentication

### OAuth 1.0a Configuration

**Required Credentials**:
- Consumer Key
- Consumer Secret  
- Access Token
- Access Token Secret

**Setup Process**:
1. Create Twitter Developer App at https://developer.twitter.com/
2. Generate consumer keys and access tokens
3. Configure OAuth in Data Machine settings
4. Test connection via OAuth popup flow

**OAuth URLs**: `/datamachine-auth/twitter/` (requires `manage_options` capability)

## Configuration Options

### Content Settings

**Link Handling** (`link_handling` setting)
- **`'append'`** (default): Appends source_url to tweet content within 280 character limit
- **`'reply'`**: Posts source_url as separate reply tweet after main tweet
- **`'none'`**: No source_url appending (ignores source_url from engine data)

**Include Images**
- Default: `true`
- Purpose: Upload and attach images from data packets
- Formats: JPEG, PNG, GIF, WebP
- Size Limit: Handled automatically (chunked upload for large files)

### Source URL Attribution

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

**Append Mode** (`link_handling: 'append'`):
- Source URL appended to tweet content with space separator
- URL counts as 24 characters (t.co link length)
- Content truncated if total exceeds 280 characters
- Example: `"Great article content https://example.com/article"`

**Reply Mode** (`link_handling: 'reply'`):
- Main tweet posted without URL
- Separate reply tweet created containing only source_url
- Reply uses Twitter API v2 in_reply_to_tweet_id parameter
- Both tweet IDs returned in response data

**None Mode** (`link_handling: 'none'`):
- No source_url processing
- Content posted as-is without URL attribution
- Useful when URL already embedded in content

## Tool Interface

### `handle_tool_call()` Method

**Parameters**:
- `content` (string, required) - Tweet content to post
- `job_id` (string) - Job identifier for engine data access
- `handler_config` (array) - Handler configuration from tool_def

**Engine Data Access** (via `datamachine_engine_data` filter):
- `source_url` (string, optional) - Source URL stored by fetch handlers
- `image_url` (string, optional) - Image URL stored by fetch handlers

**Return Format**:
```php
[
    'success' => true,
    'data' => [
        'tweet_id' => '1234567890',
        'tweet_url' => 'https://twitter.com/username/status/1234567890',
        'content' => 'Final formatted tweet content',
        'reply_tweet_id' => '1234567891', // If URL posted as reply
        'reply_tweet_url' => 'https://twitter.com/username/status/1234567891'
    ],
    'tool_name' => 'twitter_publish'
]
```

## Content Formatting

### Character Limit Handling

**Limit**: 280 characters (hardcoded)
**URL Handling**: t.co links count as 24 characters
**Truncation**: Uses ellipsis (…) when content exceeds limit

### Formatting Logic

1. **Calculate Available Characters** - 280 minus URL space (24 chars if URL enabled)
2. **Content Truncation** - Trim content to fit with ellipsis if needed
3. **URL Appending** - Add source URL (unless URL as reply enabled)
4. **Final Trim** - Remove leading/trailing whitespace

**Example**:
```php
// Input: Long content + URL
$content = "Very long content that exceeds 280 characters...";
$url = "https://example.com/article";

// Output: Truncated content + URL  
$formatted = "Very long content that exceeds 280 charac… https://example.com/article";
```

## Media Upload

### Image Processing

**Accessibility Check**:
- HEAD request validates image URL
- Skips problematic domains (Reddit preview URLs)
- Validates content-type and HTTP status

**Upload Methods**:
- **Simple Upload** - Files < 1MB via TwitterOAuth library
- **Chunked Upload** - Files > 1MB using INIT→APPEND→FINALIZE

### Chunked Upload Process

1. **INIT Phase** - Initialize upload with file size and media type
2. **APPEND Phase** - Upload file in 1MB chunks with base64 encoding  
3. **FINALIZE Phase** - Complete upload and get media ID
4. **Attachment** - Include media_id in tweet payload

**Error Handling**:
- Automatic fallback from simple to chunked upload
- Temporary file cleanup via WordPress file functions
- Comprehensive logging at each phase

## API Integration

### API Version Management

**Tweet Posting** - Uses Twitter API v2 (`/2/tweets`)
**Media Upload** - Uses Twitter API v1.1 (`/1.1/media/upload`)
**Automatic Switching** - Handler manages API version per operation

### Request Format

**Tweet Creation**:
```php
$payload = [
    'text' => $formatted_content,
    'media' => [
        'media_ids' => [$media_id] // If image attached
    ]
];
```

**Reply Tweet**:
```php
$reply_payload = [
    'text' => $source_url,
    'reply' => [
        'in_reply_to_tweet_id' => $original_tweet_id
    ]
];
```

## Error Handling

### Authentication Errors

**Connection Failure**:
- Returns error with `get_connection()` failure message
- Logs error details with authentication context

**Invalid Credentials**:
- OAuth flow redirects to error page
- Configuration validation prevents unconfigured usage

### API Errors

**Rate Limiting**:
- Twitter API errors logged with HTTP codes
- Automatic retry not implemented (relies on Action Scheduler)

**Content Errors**:
- Empty content after formatting returns error
- Malformed URLs skipped gracefully

### Media Upload Errors

**Download Failures**:
- WordPress `download_url()` errors logged
- Continues with text-only tweet

**Upload Failures**:
- Simple upload errors trigger chunked fallback
- Complete failures log detailed error information
- Tweet posts without media attachment

## Integration Examples

### Basic Tweet

```php
$result = $twitter_handler->handle_tool_call([
    'content' => 'Hello from Data Machine!'
], $tool_definition);
```

### Tweet with Media

```php
// Engine data (source_url, image_url) automatically retrieved from database
// via datamachine_engine_data filter - stored by fetch handlers
$result = $twitter_handler->handle_tool_call([
    'content' => 'Check out this image!',
    'job_id' => $job_id  // Used to retrieve engine data
], $tool_definition);

// Internal engine data access:
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;   // From fetch handler
$image_url = $engine_data['image_url'] ?? null;     // From fetch handler
```

### Reply Mode Configuration

```php
$tool_definition = [
    'handler_config' => [
        'twitter' => [
            'twitter_include_source' => true,
            'twitter_url_as_reply' => true,
            'twitter_enable_images' => true
        ]
    ]
];
```

## Performance Considerations

### Image Optimization

**Problematic Domains**: Skips known problematic URLs (Reddit previews)
**Validation**: HEAD requests prevent unnecessary downloads
**Cleanup**: Automatic temporary file removal

### Memory Management

**Chunked Processing**: Large files processed in 1MB segments
**Stream Handling**: File operations use streaming for large media
**Resource Cleanup**: All temporary resources cleaned in finally blocks

### API Efficiency

**Single Request Pattern**: One API call per tweet (plus optional reply)
**Batch Media**: Multiple images not supported (Twitter limitation)
**Connection Reuse**: TwitterOAuth connection reused across operations