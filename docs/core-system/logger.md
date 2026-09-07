# Logger System

**Implementation**: `inc/Engine/Logger.php` and `inc/Abilities/LogAbilities.php`
**Since**: 0.4.0 (Unified Logging System)
**React Admin**: `inc/Core/Admin/Pages/Logs/` (@since v0.8.0)

## Overview

Data Machine uses a centralized file-based logging system managed by Monolog for consistent, performant logging across all agents (Pipeline, Chat, System, CLI). Individual log files are maintained per agent type in the uploads directory.

## Architecture

**Design Pattern**: Abilities-based logging with Monolog file persistence.
**Integration**: Custom WordPress action `datamachine_log` for decoupled logging from any component.
**Log Storage**: Per-agent log files in uploads directory with Monolog StreamHandler.
**Agent Context**: Automatically captures the executing agent type (Pipeline, Chat, System, CLI) and associated job/session context.

## LogAbilities

The `LogAbilities` class provides the WordPress 6.9 Abilities API interface for logging operations.

### Methods

#### `log(string $level, string $message, array $context = [])`
Records a log entry to the appropriate agent log file.
- **$level**: `debug`, `info`, `warning`, `error`, `critical`.
- **$message**: Human-readable log message.
- **$context**: Array of metadata (e.g., `pipeline_id`, `flow_id`, `job_id`, `session_id`, `agent_type`).

#### `get_logs(array $args = [])`
Retrieves logs with filtering and pagination. Supports filtering by `level`, `context`, `search` string, and date ranges.

#### `clear_logs(?int $days = null)`
Deletes log entries. If `$days` is provided, deletes logs older than that many days.

## Log Levels

- **Critical**: System-critical failures that require immediate attention
- **Debug**: Detailed execution flow, AI processing steps, and tool validation logic.
- **Info**: Successful triggers, job completions, and handler operations.
- **Warning**: Potential issues, missing optional configuration, or deprecated usage.
- **Error**: Execution failures, API errors, and critical system issues.

## Integration

### Action-Based Logging

Components should ideally use the `datamachine_log` action to ensure loose coupling.

```php
// Record info during a chat session
do_action('datamachine_log', 'info', 'Chat session started', [
    'session_id' => $session_id,
    'agent_type' => 'chat'
]);

// Record system-level operation
do_action('datamachine_log', 'critical', 'Database connection failed', [
    'agent_type' => 'system',
    'error_code' => 500
]);
```

## React Interface (@since v0.8.0)

The Data Machine admin UI provides a dedicated **Logs** page built with React. It features real-time monitoring of system activity, powerful filtering by context and severity, and deep links to associated jobs and flows. Built with TanStack Query, the interface ensures that log data stays current with minimal overhead.

## Performance & Maintenance

### File-Based Storage
Logs are stored in separate files per agent type in the WordPress uploads directory. Context is stored as JSON in log entries for flexible metadata without schema changes.

### Automated Cleanup
The system includes routine cleanup of old logs to prevent table bloat. This can be configured in settings or triggered via the `datamachine_clear_logs` action.

## Multisite Support

The logging system is multisite-aware, maintaining separate log files per site with site-specific paths to ensure log isolation across the network.

---

**Implementation**: `inc/Engine/Logger.php`, `inc/Abilities/LogAbilities.php`
**Abilities**: `datamachine/read-logs`, `datamachine/get-log-metadata`, `datamachine/clear-logs` (REST-visible; run through `/wp-abilities/v1/abilities/datamachine/<slug>/run`)
**Related**: [Logs API](../api/endpoints/logs.md)
