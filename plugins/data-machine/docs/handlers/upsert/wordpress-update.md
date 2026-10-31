# WordPress Update Handler

Updates existing WordPress posts and pages in the local installation using the `datamachine/update-wordpress` ability with selective field updates and taxonomy management.

## Architecture

**Base Class**: Extends [`UpsertHandler`](../../../inc/Core/Steps/Upsert/Handlers/UpsertHandler.php)

**Inherited Functionality**:
- Engine data retrieval for source_url matching
- Standardized response formatting
- Centralized error handling
- Final `handle_tool_call()` entry point
- Runtime `auth_ref` resolution via `AuthRefHandlerConfig::resolve_runtime_config()`

**Registration**: The handler registers as slug `wordpress_update` with handler type `upsert` via [`HandlerRegistrationTrait`](../../../inc/Core/Steps/HandlerRegistrationTrait.php).

**Requirements**: Requires `job_id` and `source_url`. The `job_id` is added by tool execution; `source_url` is normally available from the prior fetch step's engine data and should be provided to the tool call for post identification.

## Local WordPress Integration

**Ability Delegation**: The handler delegates modification work to the `datamachine/update-wordpress` ability.

**wp_update_post**: The ability uses WordPress's native `wp_update_post()` function for content modification.

**URL-based Identification**: Extracts post ID from source URLs using `url_to_postid()` function.

**Selective Updates**: Only updates specified fields, leaving other post data unchanged.

## Required Parameters

**Tool Call Parameters**:
- `job_id`: Required by `UpsertHandler::handle_tool_call()` and injected by the tool executor during pipeline runs
- `source_url`: WordPress post/page URL, required for post identification

**Optional Update Parameters**:
- `title`: New post title (updates `post_title`)
- `content`: New post content (updates `post_content`)  
- `category`: Category assignment
- `tags`: Tag assignment (comma-separated)
- Custom taxonomy parameters

## Usage Examples

**Basic Content Update**:
```php
// Note: source_url is automatically provided via centralized datamachine_engine_data filter
// from the fetch handler that originally retrieved the content
$parameters = [
    'job_id' => 456,
    'source_url' => 'https://site.com/existing-post/',  // From engine data
    'content' => 'Updated post content with <strong>HTML formatting</strong>.'
];

$tool_def = [
    'handler_config' => [] // No configuration required
];

$result = $handler->handle_tool_call($parameters, $tool_def);
```

**Full Post Update**:
```php
$parameters = [
    'source_url' => 'https://site.com/existing-post/',
    'title' => 'Updated Post Title',
    'content' => 'Completely refreshed content.',
    'category' => 'Updated Category',
    'tags' => 'new tag, updated tag',
    'custom_taxonomy' => 'custom term'
];
```

## Post Identification Process

1. **URL Validation**: Validates that `source_url` parameter is provided
2. **Post ID Extraction**: Uses `url_to_postid()` to convert URL to post ID
3. **Post Existence Check**: Verifies that post exists using `get_post()`
4. **Update Authorization**: Inherits WordPress permission checks from `wp_update_post()`

## Content Processing

**Selective Updates**: Only fields with provided parameters are updated:
- Title updates only if `title` parameter provided
- Content updates only if `content` parameter provided
- Taxonomies updated based on parameter availability

**Content Sanitization**: Applies WordPress security functions:
- `sanitize_text_field()` for titles
- `wp_kses_post()` for content
- `wp_unslash()` for proper encoding

## Tool Call Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'updated_id' => 123,
        'post_url' => 'https://site.com/post-permalink',
        'modifications' => ['title', 'content', 'category'],
        'taxonomy_results' => [
            'category' => ['success' => true, 'assigned_terms' => ['Updated Category']],
            'tags' => ['success' => true, 'assigned_terms' => ['new tag', 'updated tag']]
        ]
    ],
    'tool_name' => 'wordpress_update'
]
```

**No Updates Response**:
```php
[
    'success' => true,
    'data' => [
        'updated_id' => 123,
        'post_url' => 'https://site.com/post-permalink',
        'modifications' => [],
        'message' => 'No updates applied - post unchanged'
    ],
    'tool_name' => 'wordpress_update'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'wordpress_update'
]
```

## Taxonomy Management

**Core Taxonomies**:
- `category`: Updates post categories
- `tags`: Updates post tags (comma-separated string)

**Dynamic Taxonomies**: Automatically processes additional string parameters as potential taxonomy terms.

**Assignment Logic**:
1. Validates taxonomy exists for post type
2. Creates terms if they don't exist (where supported)
3. Replaces existing terms with new assignments
4. Returns detailed success/error status

## Update Validation

**Pre-Update Checks**:
- Post existence validation
- URL-to-ID conversion validation
- Parameter presence validation

**Update Execution**:
- Uses WordPress native functions for security
- Maintains post metadata and relationships
- Preserves unmodified fields

## Error Handling

**URL Errors**:
- Missing `source_url` parameter
- Invalid URLs that don't resolve to post IDs
- Non-existent post references

**WordPress Errors**:
- `wp_update_post()` failures
- Permission denied errors
- Database connection issues

**Taxonomy Errors**:
- Invalid taxonomy references
- Term assignment failures
- Non-existent custom taxonomies

## Security Features

**Input Sanitization**: All input sanitized using WordPress security functions.

**Permission Inheritance**: Relies on WordPress's built-in capability checks in `wp_update_post()`.

**Content Filtering**: Uses `wp_kses_post()` to maintain safe HTML while blocking dangerous content.

## Workflow Integration

**Source URL Requirement**: Requires source URL from centralized engine data via datamachine_engine_data filter for post identification, making it suitable for content update workflows.

**Base-Class Entry Point**: Upsert tools should route through `UpsertHandler::handle_tool_call()`. The base class validates `job_id`, creates an `EngineData` wrapper, resolves runtime `auth_ref` config, and then calls `executeUpsert()`.

**Centralized Post Tracking**: Post origin tracking is applied centrally in `ToolExecutor::executeTool()` after successful tool calls. Upsert handlers should return an extractable post ID in their result and should not write origin tracking metadata themselves.

**ToolResultFinder Integration** (@since v0.2.0): UpsertStep uses the `ToolResultFinder` utility class for locating handler tool execution results in data packets.

**Tool Result Search Pattern**:
```php
use DataMachine\Engine\AI\ToolResultFinder;

// UpsertStep.php
$tool_result_entry = ToolResultFinder::findHandlerResult($data, $handler_slug);

if ($tool_result_entry) {
    // AI successfully executed handler tool
    return $this->create_update_entry_from_tool_result(
        $tool_result_entry,
        $data,
        $handler_slug,
        $flow_step_id
    );
}

// AI did not execute handler tool - fail cleanly
do_action('datamachine_log', 'error', 'UpsertStep: AI did not execute handler tool');
return [];
```

**Search Logic**:
- Searches data packets for `type` = 'tool_result' or 'ai_handler_complete'
- Matches `metadata.handler_tool` against handler slug (e.g., 'wordpress_update')
- Returns first matching entry or null if no match found
- Centralizes search logic eliminating code duplication

**Benefits**:
- Universal search utility shared across all upsert handlers
- Consistent tool result detection across step types
- Simplified upsert handler implementation
- Centralized maintenance for search improvements

**Metadata Preservation**: Maintains existing post metadata, publication dates, and author information.

**Selective Modification**: Allows targeted updates without affecting other post aspects.

**Logging**: Detailed debug logging for URL resolution, update operations, taxonomy assignments, and error conditions.

## Core vs Extension Boundaries

- Core owns [`UpsertHandler`](../../../inc/Core/Steps/Upsert/Handlers/UpsertHandler.php), `UpsertStep`, handler-tool execution, runtime `auth_ref` resolution, and centralized post-origin tracking.
- The WordPress upsert handler owns only the WordPress-specific tool definition and `executeUpsert()` implementation.
- The handler delegates mutation logic to the `datamachine/update-wordpress` ability instead of duplicating post update behavior in the handler layer.
- Extension upsert handlers should register with handler type `upsert`, expose tools through deferred `_handler_callable` entries, route execution through the base `handle_tool_call()`, and return standard success/error tool results.
