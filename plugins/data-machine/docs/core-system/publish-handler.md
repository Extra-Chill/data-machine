# PublishHandler Base Class

## Overview

The `PublishHandler` class (`/inc/Core/Steps/Publish/Handlers/PublishHandler.php`) is the abstract base class for all publish handlers in the Data Machine system. Introduced in version 0.2.1, it provides standardized functionality for content publishing operations including engine data retrieval, image validation, and response formatting.

## Architecture

**Location**: `/inc/Core/Steps/Publish/Handlers/PublishHandler.php`
**Inheritance**: Abstract base class extending `Step`
**Since**: 0.2.1

## Core Functionality

### Single Item Execution Model

In alignment with the **Single Item Execution Model**, publish handlers focus on processing the single data packet passed through the pipeline. This reliability-first approach ensures that each content publication is handled as an isolated transaction.

### Engine Data Retrieval

Access data stored by fetch handlers:

```php
$source_url = $this->getSourceUrl($parameters['job_id'] ?? null);
$image_file_path = $this->getImageFilePath($parameters['job_id'] ?? null);
```

### Image Validation

Built-in image validation with comprehensive error checking:

```php
if ($image_file_path) {
    $validation = $this->validateImage($image_file_path);
    if (!$validation['valid']) {
        return $this->errorResponse('Invalid image', ['errors' => $validation['errors']]);
    }
}
```

### Standardized Responses

Consistent response methods for success and error cases:

```php
// Success response
return $this->successResponse(['id' => $result_id, 'url' => $url]);

// Error response
return $this->errorResponse('Error message', ['details' => $details]);
```

## Required Implementation

All publish handlers must implement the `executePublish()` method:

```php
abstract protected function executePublish(array $parameters, array $handler_config): array;
```

## Standard Implementation Pattern

```php
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;

class MyPublishHandler extends PublishHandler {
    public function __construct() {
        parent::__construct('my_handler');
    }

    protected function executePublish(array $parameters, array $handler_config): array {
        // Access engine data
        $source_url = $this->getSourceUrl($parameters['job_id'] ?? null);
        $image_file_path = $this->getImageFilePath($parameters['job_id'] ?? null);

        // Validate image if present
        if ($image_file_path) {
            $validation = $this->validateImage($image_file_path);
            if (!$validation['valid']) {
                return $this->errorResponse('Invalid image', ['errors' => $validation['errors']]);
            }
        }

        // Handler-specific publishing logic
        $result_id = $this->publish_content($parameters['content'], $handler_config);

        // Return standardized success response
        return $this->successResponse(['id' => $result_id, 'url' => $url]);
    }
}
```

## Engine Data Methods

### getSourceUrl()
Retrieves the source URL stored by the fetch handler:

```php
$source_url = $this->getSourceUrl($job_id);
```

### getImageFilePath()
Retrieves the local file path for images stored by fetch handlers:

```php
$image_path = $this->getImageFilePath($job_id);
```

## Image Validation

The `validateImage()` method performs comprehensive validation:

```php
$validation = $this->validateImage($file_path);

// Returns:
[
    'valid' => bool,
    'errors' => array,  // Error messages if invalid
    'metadata' => array // Image metadata (width, height, mime_type, etc.)
]
```

## Tool Integration

Publish handlers that support AI tool calls should implement `handle_tool_call()`:

```php
public function handle_tool_call(array $parameters, array $tool_def = []): array {
    // Process tool call parameters
    $content = $parameters['content'];
    $handler_config = $tool_def['handler_config'] ?? [];

    // Execute publish logic
    return $this->executePublish(['content' => $content], $handler_config);
}
```

## File Handling

For image uploads or media handling, use the FilesRepository components:

```php
use DataMachine\Core\FilesRepository\FileStorage;

$file_storage = new FileStorage();
// Store or retrieve files as needed
```

## Error Handling

Use standardized error responses with detailed information:

```php
return $this->errorResponse('Authentication failed', [
    'error_code' => 'AUTH_FAILED',
    'details' => 'Invalid API credentials'
]);
```

## Benefits

- **Engine Integration**: Seamless access to data from fetch handlers
- **Image Support**: Built-in validation and processing
- **Consistency**: Standardized response patterns across all publish handlers
- **Error Handling**: Centralized error response formatting
- **Tool-First Architecture**: Native support for AI agent integration

## Implementations

All publish handlers extend this base class:
- Twitter Handler
- Bluesky Handler
- Threads Handler
- Facebook Handler
- WordPress Handler
- Google Sheets Handler

Update handlers also extend this base:
- WordPress Update Handler

See Publish Handlers Overview for comparison.