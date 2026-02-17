# Logs API

Centralized system logging via the `LogsManager` service.

## Overview

Data Machine uses a centralized file-based logging system managed by Monolog for consistent, performant logging across all agents (Pipeline, Chat, System, CLI). Individual log files are maintained per agent type in the uploads directory.

## Endpoints

### GET /datamachine/v1/logs

Retrieves a paginated list of system logs with advanced filtering.

**Permission**: `manage_options` capability required

**Parameters**:

- `agent_type` (string): Filter by agent type (`pipeline`, `chat`, `system`, `cli`)
- `per_page` (int): Number of items (default: 20)
- `page` (int): Page number
- `level` (string): Filter by log level (`debug`, `info`, `warning`, `error`, `critical`)
- `context` (string): Filter by context (e.g., `flow_id`, `pipeline_id`, `handler_slug`)
- `search` (string): Search within log messages
- `date_start` (string): ISO 8601 start date
- `date_end` (string): ISO 8601 end date

**Returns**: Standardized list of log entries with metadata.

### DELETE /datamachine/v1/logs

Clears log files.

**Permission**: `manage_options` capability required

**Parameters**:
- `agent_type` (string): Agent type to clear (`pipeline`, `chat`, `system`, `cli`, or `all`)
- `days` (int): Optional. Clear logs older than X days (file-based logs filtered by date).

## Implementation

For technical details on the logging architecture and the service layer, see the [Logger System Documentation](../../core-system/logger.md).

### Log Abilities

Logging operations are handled via the Abilities API (`DataMachine\Abilities\LogAbilities`).

```php
// Write a log entry
$ability = wp_get_ability( 'datamachine/write-to-log' );
$ability->execute( [
    'level'   => 'info',
    'message' => 'Executing flow',
    'agent'   => 'pipeline',
] );

// Read logs
$ability = wp_get_ability( 'datamachine/read-logs' );
$logs = $ability->execute( [ 'agent' => 'pipeline', 'limit' => 50 ] );
```

### Log Levels

- **Critical**: System-critical failures that require immediate attention
- **Debug**: Detailed execution flow, AI processing steps, tool validation.
- **Info**: Flow triggers, job completions, handler operations.
- **Warning**: Deprecated functionality, missing optional configuration.
- **Error**: Execution failures, API errors, database errors.

## React Interface (@since v0.8.0)

The Data Machine admin UI includes a dedicated Logs page built with React that consumes these endpoints to provide real-time monitoring of system activity, powerful filtering by context and severity, and deep links to associated jobs and flows. Status updates are managed via TanStack Query for optimal performance and zero page reloads.
