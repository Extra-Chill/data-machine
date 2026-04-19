# Parameter Systems

Unified flat parameter architecture for all Data Machine components, providing consistent interfaces across steps, handlers, and tools while maintaining extensibility and simplicity.

## Architecture Overview

Data Machine uses an engine data filter architecture that provides clean data separation and consistent parameter access across all components.

### Core Design Principles

1. **Engine Data Propagation** - Fetch handlers store engine data via centralized filters; engine bundles the data into the payload passed to every step and tool
2. **Clean Data Separation** - AI receives clean data packets without URLs; handlers receive engine parameters via the payload-supplied engine data
3. **Unified Interface** - All steps, handlers, and tools use consistent parameter formats with required `job_id` for engine data access
4. **Tool-Based Parameter Building** - ToolParameters class (Universal Engine) provides standardized parameter construction
5. **Required Job ID** - All tools must include `job_id` parameter to enable engine data access and workflow continuity

## Core Payload Structure

### Universal Payload Keys
These payload keys are provided to ALL steps and components:

```php
$payload = [
    'job_id' => $job_id,                    // Unique job identifier
    'flow_step_id' => $flow_step_id,        // Flow step identifier ({pipeline_step_id}_{flow_id})
    'data' => $data,                        // Data packet array
    'flow_step_config' => $flow_step_config,// Step configuration
    'engine_data' => $engine_data           // Engine metadata stored by fetch handlers
];
```

### Engine Data
Engine data is stored in the database by fetch handlers using the centralized `datamachine_engine_data` filter. During execution the engine collects that data and injects it into the payload, so every step receives the same metadata packet:

```php
// 1. Fetch handlers store data for later payload injection
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}

// 2. Steps read the injected engine data directly from the payload
public function execute(array $payload): array {
    $engine_data = $payload['engine_data'] ?? [];

    // Optional: refresh if handler updated engine data mid-flow
    if (!$engine_data) {
        $engine_data = apply_filters('datamachine_engine_data', [], $payload['job_id']);
    }

    $source_url = $engine_data['source_url'] ?? null;
    $image_url = $engine_data['image_url'] ?? null;

    // ...
}
```

## Step Implementation Pattern

All steps follow the same payload extraction pattern:

```php
class MyStep {
    public function execute(array $payload): array {
        // Extract core parameters
        $job_id = $payload['job_id'];
        $flow_step_id = $payload['flow_step_id'];
        $data = $payload['data'] ?? [];
        $flow_step_config = $payload['flow_step_config'] ?? [];
        $engine_data = $payload['engine_data'] ?? [];

        // Extract step-specific parameters
        $custom_setting = $payload['custom_setting'] ?? null;
        $source_url = $engine_data['source_url'] ?? null;

        // Step processing logic
        $result = $this->process_data($data, $flow_step_config);

        // Mark items processed
        do_action('datamachine_mark_item_processed', $flow_step_id, 'my_step', $item_id, $job_id);

        // Return updated data packet array
        return $result;
    }
}
```

## Handler Parameter Patterns

### Fetch Handlers
Generate clean data packets and store engine parameters in database:

```php
class MyFetchHandler {
    public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        $flow_step_id = $handler_config['flow_step_id'] ?? null;

        // Create clean data packet (no URLs)
        $clean_data = [
            'data' => ['content_string' => $content],
            'metadata' => ['source_type' => 'my_handler', 'original_id' => $item_id]
        ];

        // Store engine parameters in database via centralized datamachine_engine_data filter
        if ($job_id) {
            apply_filters('datamachine_engine_data', null, $job_id, [
                'source_url' => $source_url,
                'image_url' => $image_url
            ]);
        }

        return ['processed_items' => [$clean_data]];
    }
}
```

### Publish Handlers (Tool-Based)
Use `ToolParameters::buildForHandlerTool()` for parameter building with engine data access:

```php
class MyPublishHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Parameters built by ToolParameters::buildParameters()
        // Already contain: content, title, job_id, flow_step_id, handler_config
        // Engine data accessed via filter for handler tools

        $content = $parameters['content'] ?? '';
        $handler_config = $tool_def['handler_config'] ?? [];

        // Access engine data via centralized filter
        $job_id = $parameters['job_id'] ?? null;
        $engine_data = apply_filters('datamachine_engine_data', [], $job_id);
        $source_url = $engine_data['source_url'] ?? null;
        $image_url = $engine_data['image_url'] ?? null;

        return ['success' => true, 'data' => ['id' => $id]];
    }
}
```

### Upsert Handlers (Engine Data)
Require `source_url` from engine data stored by fetch handlers and delivered on the payload:

```php
class MyUpdateHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Access engine data via centralized filter
        $job_id = $parameters['job_id'] ?? null;
        $engine_data = apply_filters('datamachine_engine_data', [], $job_id);
        $source_url = $engine_data['source_url'] ?? null;

        if (empty($source_url)) {
            return ['success' => false, 'error' => 'Missing required source_url from engine data'];
        }

        $content = $parameters['content'] ?? '';

        // Update existing content at source_url
        return ['success' => true, 'data' => ['updated_id' => $id]];
    }
}
```

## AI Tool Parameter Building

### ToolParameters Class (Universal Engine)
**File**: `/inc/Engine/AI/Tools/ToolParameters.php`
**Since**: 0.2.0

Centralized parameter building for AI tool execution:

```php
use DataMachine\Engine\AI\Tools\ToolParameters;

// Unified parameter building for all tool types
$parameters = ToolParameters::buildParameters(
    $ai_tool_parameters,     // Parameters from AI tool call
    $payload,                // Step payload (job_id, flow_step_id, data, flow_step_config, engine_data)
    $tool_definition         // Tool definition array
);
```

### Parameter Building Process

1. **Start with Payload** - Copy the complete payload (job_id, flow_step_id, data, flow_step_config, engine_data)
2. **Merge AI Parameters** - Add AI-provided parameters on top (content, title, query, etc.)
3. **Preserve Structure** - Maintain unified flat parameter structure for all tool types

### Example Built Parameters

```php
[
    // Original payload parameters
    'job_id' => 'uuid-job-123',
    'flow_step_id' => 'step_uuid_flow_123',
    'data' => [...], // Complete data packet array
    'flow_step_config' => [...], // Step configuration
    'engine_data' => [...], // Engine metadata from fetch handlers

    // AI-provided parameters (merged on top)
    'content' => 'AI-generated content',
    'title' => 'AI-generated title',
    'hashtags' => '#ai #automation'
]
```

## Handler Configuration Defaults

The system employs a priority-based default application logic via `HandlerService::applyDefaults()`. This ensures consistent configuration across flows while allowing granular overrides.

### Priority Order (Highest to Lowest)

1.  **Explicit Configuration**: Values explicitly provided in the flow step configuration.
2.  **Site-wide Defaults**: Site-level defaults managed via Settings → Handler Defaults (stored in `datamachine_handler_defaults` option).
3.  **Schema Defaults**: Default values defined in the handler's settings class field definitions (`get_fields()`).

### Configuration Merging Logic

When a flow step executes, the system merges these layers:

- If a key exists in **Explicit Configuration**, that value is used.
- Otherwise, if it exists in **Site-wide Defaults**, that value is used.
- Otherwise, the **Schema Default** is used if defined.
- Keys not present in the handler's schema are preserved for forward compatibility.

### Benefits

- **Efficiency**: Standard settings (like default post author or image settings) only need to be configured once per site.
- **Flexibility**: Individual flows can still override site defaults when specific behavior is required.
- **Stability**: Schema defaults provide a safe fallback for all configuration keys.

## Handler-Specific Engine Parameters

### Database Storage by Fetch Handlers
Each fetch handler stores specific engine parameters in the database using array storage:

```php
// Reddit Handler - stores via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => 'https://reddit.com' . $item_data['permalink'],
        'image_url' => $stored_image['url'] ?? ''
    ]);
}

// WordPress Local Handler - stores via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => get_permalink($post_id),
        'image_url' => $this->extract_image_url($post_id)
    ]);
}

// RSS Handler - stores via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $item_link,
        'image_url' => $enclosure_url
    ]);
}
```

### Parameter Validation
Steps can validate required parameters:

```php
public function execute(array $payload): array {
    $required = ['job_id', 'flow_step_id', 'custom_required_param'];

    foreach ($required as $param) {
        if (!isset($payload[$param])) {
            throw new InvalidArgumentException("Missing required parameter: {$param}");
        }
    }

    return $this->process($payload);
}
```

## Flow Step Configuration Access

Payloads include complete flow step configuration with pipeline inheritance:

```php
$flow_step_config = $payload['flow_step_config'];

// Available configuration keys:
$step_type = $flow_step_config['step_type'];           // From pipeline
$execution_order = $flow_step_config['execution_order']; // From pipeline
$system_prompt = $flow_step_config['system_prompt'];   // From pipeline (AI steps)
$user_message = $flow_step_config['user_message'];     // From flow (AI steps)
$handler_config = $flow_step_config['handler_config']; // Handler settings
```

## Data Packet Integration

The `data` key contains the complete workflow history:

```php
$data = $payload['data']; // Array of data packets

// Each data packet structure:
[
    'type' => 'fetch|ai|publish|upsert',
    'handler' => 'rss|twitter|etc',
    'content' => ['title' => $title, 'body' => $content],
    'metadata' => ['source_type' => $type, 'source_url' => $url],
    'timestamp' => time()
]
```

## Benefits of Flat Parameter Architecture

### Simplicity
- Single payload extraction pattern across all components
- No complex nested structure navigation
- Clear parameter availability and access

### Extensibility
- New parameter types automatically available to all components
- Filter-based parameter injection
- No signature changes required for new functionality

### Consistency
- Unified payload interface across steps, handlers, and tools
- Consistent parameter naming and structure
- Predictable parameter availability

### Debugging
- Easy parameter inspection and logging
- Clear parameter source identification
- Simplified debugging of parameter flow

This parameter system enables flexible, extensible component development while maintaining consistency and simplicity across the entire Data Machine architecture.