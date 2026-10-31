# WebFetch AI Tool

**File Location**: `inc/Engine/AI/Tools/Global/WebFetch.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents - pipeline + chat)

Enables AI models to retrieve and process web page content for analysis, research, and content extraction from external websites with built-in content processing and safety features.

## Configuration

**No Configuration Required**: Tool is always available without external API keys or authentication setup.

**Universal Availability**: Accessible to all AI steps as a general-purpose tool for web content retrieval.

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | Yes | Valid HTTP or HTTPS URL to fetch |

## Usage Examples

**Basic Web Fetch**:
```php
$parameters = [
    'url' => 'https://example.com/article'
];
```

**Article Analysis**:
```php
$parameters = [
    'url' => 'https://techcrunch.com/2024/01/15/ai-breakthrough'
];
```

## Content Processing

**HTML Processing**: Retrieves complete HTML content and processes it for AI consumption.

**Content Limits**: 50,000 character limit to prevent excessive response sizes and processing overhead.

**Safety Validation**: Validates URL format and restricts to HTTP/HTTPS protocols only.

## URL Validation

**Format Validation**: Uses PHP's `filter_var()` with `FILTER_VALIDATE_URL` for strict URL validation.

**Protocol Restrictions**: Only HTTP and HTTPS URLs are accepted for security.

**Error Handling**: Clear error messages for invalid URL formats or unsupported protocols.

## Tool Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'url' => 'https://example.com/page',
        'content' => 'Retrieved web page content...',
        'content_length' => 15420,
        'content_truncated' => false,
        'fetch_timestamp' => '2024-01-15 14:30:00'
    ],
    'tool_name' => 'web_fetch'
]
```

**Success Response (Truncated)**:
```php
[
    'success' => true,
    'data' => [
        'url' => 'https://example.com/very-long-page',
        'content' => 'Retrieved content truncated to 50,000 characters...',
        'content_length' => 50000,
        'content_truncated' => true,
        'original_length' => 87234,
        'fetch_timestamp' => '2024-01-15 14:30:00'
    ],
    'tool_name' => 'web_fetch'
]
```

**Error Responses**:
```php
// Missing URL
[
    'success' => false,
    'error' => 'URL parameter is required',
    'tool_name' => 'web_fetch'
]

// Invalid URL format
[
    'success' => false,
    'error' => 'Invalid URL format. Must be a valid HTTP or HTTPS URL',
    'tool_name' => 'web_fetch'
]

// Fetch failure
[
    'success' => false,
    'error' => 'Failed to fetch URL: Connection timeout',
    'tool_name' => 'web_fetch'
]
```

## Content Features

**Complete HTML**: Retrieves entire HTML content including markup, scripts, and styles.

**Raw Content**: No content filtering or extraction - provides complete page source for AI processing.

**Character Limit**: Automatic truncation at 50,000 characters with truncation indicators.

**Timestamp Tracking**: Records fetch time for content freshness verification.

## Network Handling

**WordPress HTTP API**: Uses WordPress's built-in `wp_remote_get()` for consistent network handling.

**Timeout Management**: Inherits WordPress default timeout settings for reliability.

**Error Reporting**: Clear error messages for network failures, timeouts, and HTTP errors.

## Use Cases

**Content Analysis**: Retrieve web pages for AI content analysis and insights.

**Competitive Research**: Analyze competitor websites and content strategies.

**Reference Material**: Fetch source material for fact-checking and reference.

**Content Inspiration**: Retrieve content from external sources for inspiration and ideas.

**URL Processing**: Extract and analyze content from URLs found in data sources.

**Research Enhancement**: Gather external information to enhance AI responses with current web data.

## Performance Considerations

**Content Limits**: 50K character limit prevents excessive memory usage and processing time.

**Single Request**: One HTTP request per tool call for efficient resource usage.

**No Caching**: Fresh content retrieval on every request for current information.

**Truncation Handling**: Graceful content truncation with clear indicators when limits are reached.

## Safety Features

**URL Validation**: Strict URL format and protocol validation prevents malformed requests.

**Protocol Restrictions**: Only HTTP/HTTPS allowed - blocks file://, ftp://, and other protocols.

**Error Boundaries**: Comprehensive error handling prevents tool failures from breaking AI workflows.

**Content Limits**: Prevents resource exhaustion from extremely large web pages.

## Success Messaging

**Custom Success Messages**: Implements `datamachine_tool_success_message` filter for enhanced AI conversation formatting.

**Content Length Reporting**: Clear indication of content size and truncation status.

**URL Confirmation**: Confirms successful retrieval with original URL reference.

**Timestamp Information**: Provides fetch time for content freshness awareness.