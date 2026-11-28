# Step Base Class

## Overview

The `Step` class (`/inc/Core/Steps/Step.php`) is the abstract base class for all step types in the Data Machine pipeline system. Introduced in version 0.2.1, it provides standardized inheritance patterns that reduce code duplication and ensure consistent functionality across all step implementations.

## Architecture

**Location**: `/inc/Core/Steps/Step.php`
**Inheritance**: Abstract base class
**Since**: 0.2.1

## Core Properties

The base `Step` class provides access to all essential pipeline execution data, supporting the **Single Item Execution Model** by providing a standardized interface for processing a single unit of work:

```php
protected int $job_id;              // Current job ID
protected string $flow_step_id;     // Flow-specific step identifier
protected array $dataPackets;       // Data packets from previous steps (typically containing one primary item)
protected array $flow_step_config;  // Step configuration
protected array $engine_data;       // Engine data from database storage
```

## Required Implementation

All step classes must implement the `executeStep()` method:

```php
abstract protected function executeStep(): array;
```

This method should:
- Process the current data packet (following the Single Item Execution Model)
- Perform step-specific logic
- Return updated data packets array
- Use `DataPacket` class for standardized packet creation

## Standard Implementation Pattern

```php
use DataMachine\Core\Steps\Step;

class MyStep extends Step {
    public function __construct() {
        parent::__construct('my_step');
    }

    protected function executeStep(): array {
        // Access base class properties
        $job_id = $this->job_id;
        $flow_step_id = $this->flow_step_id;
        $dataPackets = $this->dataPackets;
        $config = $this->flow_step_config;
        $engine_data = $this->engine_data;

        // Step-specific processing logic
        // ...

        // Create standardized data packet
        $dataPacket = new \DataMachine\Core\DataPacket(
            ['content_string' => $processed_content],
            ['source_type' => 'my_step', 'item_identifier_to_log' => $item_id],
            'my_step'
        );

        // Return updated data packets
        return $dataPacket->addTo($this->dataPackets);
    }
}
```

## Data Packet Integration

Steps should use the `DataPacket` class for consistent data structure:

```php
$dataPacket = new \DataMachine\Core\DataPacket(
    ['content_string' => $content, 'file_info' => $file_info],
    ['source_type' => $type, 'item_identifier_to_log' => $id],
    'step_type'
);

return $dataPacket->addTo($this->dataPackets);
```

## Engine Data Access

Steps can access engine data stored by previous handlers:

```php
$engine_data = $this->engine_data;
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;
```

## Logging

Steps inherit centralized logging capabilities:

```php
do_action('datamachine_log', 'debug', 'Step processing started', [
    'job_id' => $this->job_id,
    'step_type' => 'my_step'
]);
```

## Benefits

- **Code Deduplication**: Eliminates repetitive payload handling code
- **Consistency**: Standardized access to pipeline data
- **Maintainability**: Centralized validation and logging
- **Extensibility**: Easy to add new step types following established patterns

## Used By

All step types extend this base class:
- **Fetch Steps**: See FetchHandler for fetch-specific base class

- **Publish Steps**: See PublishHandler for publish-specific base class

## Related Documentation

See Fetch Handlers and Publish Handlers documentation for specific implementations.