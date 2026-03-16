# Logs API

**Implementation**: `inc/Api/Logs.php`

**Base URL**: `/wp-json/datamachine/v1/logs`

## Overview

Data Machine uses a database-backed logging system with all operational logs stored in the `datamachine_logs` table. This replaced the earlier Monolog file-based logging system in v0.39.0. The database approach provides structured SQL-based filtering, pagination, agent_id scoping, and JSON context storage for rich log metadata.

**Key components**:
- **LogRepository** (`inc/Core/Database/Logs/LogRepository.php`) — Database storage layer with SQL queries (@since v0.43.0)
- **LogAbilities** (`inc/Abilities/LogAbilities.php`) — Abilities API interface for write, clear, readLogs, getMetadata, and readDebugLog
- **Logs API** (`inc/Api/Logs.php`) — REST endpoints that delegate to LogAbilities

## Authentication

Requires `view_logs` permission (checked via `PermissionHelper`).

## Endpoints

### GET /datamachine/v1/logs

Retrieve paginated log entries with structured filtering.

**Permission**: `view_logs` capability required

**Parameters**:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `agent_id` | integer | No | Filter by agent ID (0+ valid, null for all agents) |
| `level` | string | No | Filter by log level: `debug`, `info`, `warning`, `error`, `critical` |
| `since` | string | No | ISO datetime — entries after this time |
| `before` | string | No | ISO datetime — entries before this time |
| `job_id` | integer | No | Filter by job_id (searches JSON context) |
| `flow_id` | integer | No | Filter by flow_id (searches JSON context) |
| `pipeline_id` | integer | No | Filter by pipeline_id (searches JSON context) |
| `search` | string | No | Free-text search within log messages |
| `per_page` | integer | No | Items per page (default: 50, max: 500) |
| `page` | integer | No | Page number, 1-indexed (default: 1) |

**Example Requests**:

```bash
# Get recent logs
curl https://example.com/wp-json/datamachine/v1/logs \
  -u username:application_password

# Filter by error level
curl "https://example.com/wp-json/datamachine/v1/logs?level=error&per_page=100" \
  -u username:application_password

# Filter by job ID (searches JSON context)
curl "https://example.com/wp-json/datamachine/v1/logs?job_id=1523" \
  -u username:application_password

# Filter by agent and date range
curl "https://example.com/wp-json/datamachine/v1/logs?agent_id=1&since=2026-03-01T00:00:00&before=2026-03-16T00:00:00" \
  -u username:application_password

# Free-text search
curl "https://example.com/wp-json/datamachine/v1/logs?search=handler+error" \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 4521,
        "agent_id": 1,
        "user_id": 1,
        "level": "info",
        "message": "Flow 42 completed successfully",
        "context": {
          "flow_id": 42,
          "pipeline_id": 5,
          "job_id": 1523,
          "handler_slug": "wordpress"
        },
        "created_at": "2026-03-15 14:30:15"
      },
      {
        "id": 4520,
        "agent_id": 1,
        "user_id": 1,
        "level": "error",
        "message": "Handler configuration missing",
        "context": {
          "flow_id": 42,
          "pipeline_id": 5,
          "job_id": 1522
        },
        "created_at": "2026-03-15 14:00:05"
      }
    ],
    "total": 4521,
    "page": 1,
    "pages": 91
  }
}
```

**Log Entry Fields**:
- `id` (integer): Unique log entry ID (auto-incrementing)
- `agent_id` (integer|null): Agent ID (null for unscoped/system logs)
- `user_id` (integer|null): Acting WordPress user ID
- `level` (string): Log level — `debug`, `info`, `warning`, `error`, `critical`
- `message` (string): Log message text
- `context` (object): Structured context data (stored as JSON in database, decoded in response)
- `created_at` (string): UTC timestamp of the log entry

### GET /datamachine/v1/logs/metadata

Get log metadata including total entry counts and time range.

**Permission**: `view_logs` capability required

**Parameters**:
- `agent_id` (integer, optional): Agent ID to get metadata for. Omit for global metadata.

**Example Request**:

```bash
# Global metadata
curl https://example.com/wp-json/datamachine/v1/logs/metadata \
  -u username:application_password

# Agent-specific metadata
curl "https://example.com/wp-json/datamachine/v1/logs/metadata?agent_id=1" \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "total_entries": 4521,
    "oldest": "2026-01-15 08:00:00",
    "newest": "2026-03-15 14:30:15"
  }
}
```

### DELETE /datamachine/v1/logs

Clear log entries from the database.

**Permission**: `view_logs` capability required

**Parameters**:
- `agent_id` (integer, optional): Agent ID to clear logs for. Omit to clear all logs.

**Example Requests**:

```bash
# Clear all logs
curl -X DELETE https://example.com/wp-json/datamachine/v1/logs \
  -u username:application_password

# Clear logs for specific agent
curl -X DELETE "https://example.com/wp-json/datamachine/v1/logs?agent_id=1" \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Logs cleared successfully.",
  "deleted_count": 4521
}
```

## Database Schema

The `datamachine_logs` table (`{wp_prefix}datamachine_logs`):

```sql
CREATE TABLE {prefix}datamachine_logs (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED DEFAULT NULL,
    user_id BIGINT(20) UNSIGNED DEFAULT NULL,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agent_time (agent_id, created_at),
    KEY idx_level_time (level, created_at),
    KEY idx_created_at (created_at)
);
```

**Indexes**:
- `idx_agent_time` — Fast queries for agent-scoped logs ordered by time
- `idx_level_time` — Fast queries filtering by level
- `idx_created_at` — Fast time-range queries and pruning

**Context Storage**: The `context` column stores JSON-encoded metadata. Context keys like `job_id`, `flow_id`, and `pipeline_id` are searched via SQL `LIKE` against the JSON string for filtering.

## Log Levels

| Level | Description | Examples |
|---|---|---|
| `critical` | System-critical failures requiring immediate attention | Database connection lost, unrecoverable state |
| `error` | Execution failures, API errors, database errors | Handler execution failed, API timeout |
| `warning` | Deprecated functionality, missing optional configuration | Legacy field used, optional config absent |
| `info` | Flow triggers, job completions, handler operations | Flow started, job completed, file uploaded |
| `debug` | Detailed execution flow, AI processing steps, tool validation | Tool call parameters, step progression |

## Abilities Interface

Logging operations are handled via the Abilities API (`DataMachine\Abilities\LogAbilities`):

```php
// Write a log entry
LogAbilities::write([
    'level'    => 'info',
    'message'  => 'Executing flow',
    'context'  => ['flow_id' => 42, 'pipeline_id' => 5],
    'agent_id' => 1,
]);

// Read logs with filtering
$result = LogAbilities::readLogs([
    'agent_id' => 1,
    'level'    => 'error',
    'per_page' => 50,
    'page'     => 1,
]);

// Get log metadata
$metadata = LogAbilities::getMetadata(['agent_id' => 1]);

// Clear logs for an agent
LogAbilities::clear(['agent_id' => 1]);

// Read WordPress debug.log (separate from DM logs)
$debugLog = LogAbilities::readDebugLog();
```

**Available abilities**:
- `write` — Write a log entry to the database
- `clear` — Clear log entries (scoped by agent_id or all)
- `readLogs` — Query logs with filtering and pagination
- `getMetadata` — Get total counts and time range
- `readDebugLog` — Read the WordPress debug.log file (separate from DM database logs)

## LogRepository Methods

The `LogRepository` (`inc/Core/Database/Logs/LogRepository.php`) provides the storage layer:

```php
$repo = new LogRepository();

// Insert a log entry
$id = $repo->log('info', 'Flow completed', ['flow_id' => 42], $agent_id, $user_id);

// Query logs with filters
$result = $repo->get_logs([
    'agent_id'    => 1,
    'level'       => 'error',
    'since'       => '2026-03-01 00:00:00',
    'before'      => '2026-03-16 00:00:00',
    'job_id'      => 1523,
    'flow_id'     => 42,
    'pipeline_id' => 5,
    'search'      => 'handler',
    'per_page'    => 50,
    'page'        => 1,
]);
// Returns: { items: [...], total: int, page: int, pages: int }

// Get metadata
$meta = $repo->get_metadata($agent_id);
// Returns: { total_entries: int, oldest: string|null, newest: string|null }

// Prune old entries
$repo->prune_before('2026-01-01 00:00:00');

// Clear logs for a specific agent
$repo->clear_for_agent($agent_id);

// Truncate all logs
$repo->clear_all();
```

## React Interface (@since v0.8.0)

The Data Machine admin UI includes a dedicated Logs page built with React that consumes these endpoints to provide real-time monitoring of system activity, powerful filtering by context and severity, and deep links to associated jobs and flows. Status updates are managed via TanStack Query for optimal performance and zero page reloads.

## Migration from File-Based Logging

The database-backed logging system replaced the Monolog file-based system:

| Aspect | Before (Monolog) | After (Database) |
|---|---|---|
| Storage | Per-agent-type log files in uploads | Single `datamachine_logs` database table |
| Scoping | `agent_type` parameter (pipeline, chat, system, cli) | `agent_id` integer (specific agent instance) |
| Filtering | Date-based file filtering | SQL WHERE with indexes |
| Context | Inline in log messages | Structured JSON column |
| Clearing | `agent_type` + optional `days` parameter | `agent_id` scoping or clear all |
| Pagination | File offset/seek | SQL LIMIT/OFFSET with total count |

**Breaking changes from the migration**:
- The `agent_type` parameter is no longer used — use `agent_id` instead
- The `days` parameter for clearing old logs is no longer supported — use `prune_before()` at the repository level
- Log file paths no longer apply — all logs are in the database

## Related Documentation

- [Jobs](jobs.md) - Job execution monitoring
- [Flows](flows.md) - Flow management
- [System](system.md) - System health and status

---

**Base URL**: `/wp-json/datamachine/v1/logs`
**Permission**: `view_logs` capability required
**Implementation**: `inc/Api/Logs.php`
**Storage**: `{wp_prefix}datamachine_logs` database table
**Since**: v0.39.0 (database-backed), v0.43.0 (LogRepository)
