# EngineData

**Location**: `/inc/Core/EngineData.php`
**Since**: v0.2.1 (platform-agnostic refactoring in v0.2.7)

## Overview

EngineData encapsulates the engine data array that persists across pipeline execution, providing platform-agnostic data access methods for source URLs, images, and metadata. As of v0.2.7, EngineData is a pure data container with no WordPress-specific operations.

## Architecture

**Purpose**: Unified interface for all engine data operations
**Design Pattern**: Encapsulation with helper methods
**Integration**: Used directly in all handlers for consistent data access

## Core Methods

### Data Retrieval

#### get()

Retrieve a value from engine data with fallback support.

```php
public function get(string $key, $default = null)
```

**Parameters**:
- `$key`: Data key to retrieve
- `$default`: Default value if key not found

**Returns**: Mixed value from engine data or default

**Search Order**:
1. Top-level engine data array
2. Metadata sub-array
3. Default value

**Example**:
```php
$engine = new EngineData($engine_data, $job_id);
$source_url = $engine->get('source_url');
$custom_value = $engine->get('custom_field', 'default_value');
```

#### getSourceUrl()

Retrieve and validate source URL from engine data.

```php
public function getSourceUrl(): ?string
```

**Returns**: Validated source URL or null

**Validation**: Uses `filter_var()` with `FILTER_VALIDATE_URL`

**Example**:
```php
$source_url = $engine->getSourceUrl();
if ($source_url) {
    // Use validated URL
}
```

#### getImagePath()

Retrieve image file path from engine data.

```php
public function getImagePath(): ?string
```

**Returns**: Absolute file path to image or null

**Source**: Returns `image_file_path` from engine data (set by fetch handlers)

**Example**:
```php
$image_path = $engine->getImagePath();
if ($image_path && file_exists($image_path)) {
    // Process image file
}
```



### Context Retrieval

#### all()

Return complete engine data array.

```php
public function all(): array
```

**Returns**: Raw engine data array snapshot

**Example**:
```php
$full_data = $engine->all();
```

#### getJobContext()

Retrieve job execution context (flow_id, pipeline_id, etc.).

```php
public function getJobContext(): array
```

**Returns**: Job context array or empty array

**Example**:
```php
$job_context = $engine->getJobContext();
$flow_id = $job_context['flow_id'] ?? null;
```

#### getFlowConfig()

Retrieve complete flow configuration snapshot.

```php
public function getFlowConfig(): array
```

**Returns**: Flow configuration array or empty array

**Example**:
```php
$flow_config = $engine->getFlowConfig();
```

#### getFlowStepConfig()

Retrieve configuration for specific flow step.

```php
public function getFlowStepConfig(string $flow_step_id): array
```

**Parameters**:
- `$flow_step_id`: Flow step identifier

**Returns**: Step configuration array or empty array

**Example**:
```php
$step_config = $engine->getFlowStepConfig('step_123');
```

#### getPipelineConfig()

Retrieve pipeline configuration snapshot.

```php
public function getPipelineConfig(): array
```

**Returns**: Pipeline configuration array or empty array

**Example**:
```php
$pipeline_config = $engine->getPipelineConfig();
```

#### getPipelineStepConfig()

Retrieve configuration for a specific pipeline step.

Pipeline step config contains AI provider settings (`provider`, `model`, `system_prompt`) while flow step config contains flow-level overrides (`handler_slug`, `handler_config`, `prompt_queue`, `config_patch_queue`, `queue_mode`). Legacy `user_message` inputs are normalized into a one-entry static `prompt_queue` before AIStep reads them.

```php
public function getPipelineStepConfig(string $pipeline_step_id): array
```

**Parameters**:
- `$pipeline_step_id`: Pipeline step identifier (UUID format: `{pipeline_id}_{uuid}`)

**Returns**: Step configuration array or empty array

**Example**:
```php
// Get AI provider config for a pipeline step
$pipeline_step_config = $engine->getPipelineStepConfig($pipeline_step_id);
$provider = $pipeline_step_config['provider'] ?? '';
$model = $pipeline_step_config['model'] ?? '';
$system_prompt = $pipeline_step_config['system_prompt'] ?? '';
```

**Config Location Guide**:
| Setting | Config Location | Method |
|---------|-----------------|--------|
| `provider`, `model`, `system_prompt` | `pipeline_config` | `getPipelineStepConfig()` |
| `handler_slug`, `handler_config`, `prompt_queue`, `config_patch_queue`, `queue_mode` | `flow_config` | `getFlowStepConfig()` |

## Handler Usage

All handlers use EngineData for consistent data access:

```php
use DataMachine\Core\EngineData;

$engine = new EngineData($engine_data_array, $job_id);

// Access data (platform-agnostic)
$source_url = $engine->getSourceUrl();
$image_path = $engine->getImagePath();
$custom_value = $engine->get('custom_field', 'default');

// Access configuration context
$flow_config = $engine->getFlowConfig();
$job_context = $engine->getJobContext();
```

## Data Structure

Engine data array structure:

```php
[
    'source_url' => 'https://example.com/post',
    'image_file_path' => '/path/to/image.jpg',
    'metadata' => [
        'custom_field' => 'value'
    ],
    'job' => [
        'flow_id' => 123,
        'pipeline_id' => 456,
        'job_id' => 789
    ],
    'flow_config' => [
        'step_id' => [
            'handler_slug' => 'wordpress',
            'handler_config' => []
        ]
    ],
    'pipeline_config' => [
        'pipeline_name' => 'Example Pipeline'
    ]
]
```

## Architecture Benefits

EngineData provides a platform-agnostic data access layer:

**Single Responsibility**: Pure data container with no platform-specific operations

**Platform Agnostic**: No WordPress dependencies, usable across all handlers

**Unified Interface**: Consistent API for all engine data retrieval

**Simplified Dependencies**: Minimal class responsibilities

**Centralized Access**: Single source of truth for engine data

**Job Context**: Automatic job ID association for tracking

## Migration from v0.2.6

**v0.2.7 Breaking Changes** - WordPress-specific methods removed from EngineData:

```php
// Before (v0.2.6) - WordPress operations in EngineData
$engine = new EngineData($engine_data, $job_id);
$content = $engine->applySourceAttribution($content, $config);
$attachment_id = $engine->attachImageToPost($post_id, $config);

// After (v0.2.7) - Use WordPressPublishHelper for WordPress operations
use DataMachine\Core\WordPress\WordPressPublishHelper;

$engine = new EngineData($engine_data, $job_id);
$content = WordPressPublishHelper::applySourceAttribution($content, $source_url, $config);
$attachment_id = WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config);
```

**Why This Change**: Restores platform-agnostic architecture. EngineData now matches the pattern used by all social media handlers (Twitter, Threads, Bluesky, Facebook) which only access data, never perform platform-specific operations.

## Related Documentation

- WordPressPublishHelper - WordPress-specific publishing operations
- WordPress Components - Component architecture overview
- WordPress Publish Handler - Integration example with WordPressPublishHelper
- FilesRepository - Image file management

---

**Implementation**: `/inc/Core/EngineData.php`
**Architecture**: Platform-agnostic data container with pure data access methods
