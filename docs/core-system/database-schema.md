# Database Schema

Data Machine uses eight core tables for managing pipelines, flows, jobs, agents, access control, deduplication tracking, chat sessions, and centralized logging.

## Core Tables

### `wp_datamachine_pipelines`

**Purpose**: Reusable workflow templates

```sql
CREATE TABLE wp_datamachine_pipelines (
    pipeline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    agent_id bigint(20) unsigned DEFAULT NULL,
    pipeline_name varchar(255) NOT NULL,
    pipeline_config longtext NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pipeline_id),
    KEY user_id (user_id),
    KEY agent_id (agent_id),
    KEY pipeline_name (pipeline_name),
    KEY created_at (created_at),
    KEY updated_at (updated_at)
);
```

**Fields**:
- `pipeline_id` - Auto-increment primary key
- `user_id` - WordPress user who created the pipeline
- `agent_id` - Agent this pipeline belongs to (multi-agent scoping, @since v0.36.1)
- `pipeline_name` - Human-readable pipeline name
- `pipeline_config` - JSON configuration containing step definitions
- `created_at` - Creation timestamp
- `updated_at` - Last modification timestamp

### `wp_datamachine_flows`

**Purpose**: Scheduled instances of pipelines with specific configurations

```sql
CREATE TABLE wp_datamachine_flows (
    flow_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    pipeline_id bigint(20) unsigned NOT NULL,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    agent_id bigint(20) unsigned DEFAULT NULL,
    flow_name varchar(255) NOT NULL,
    flow_config longtext NOT NULL,
    scheduling_config longtext NOT NULL,
    PRIMARY KEY (flow_id),
    KEY pipeline_id (pipeline_id),
    KEY user_id (user_id),
    KEY agent_id (agent_id)
);
```

**Fields**:
- `flow_id` - Auto-increment primary key
- `pipeline_id` - Reference to parent pipeline
- `user_id` - WordPress user who created the flow
- `agent_id` - Agent this flow belongs to (multi-agent scoping, @since v0.36.1)
- `flow_name` - Instance-specific name
- `flow_config` - JSON configuration with flow-specific settings
- `scheduling_config` - Scheduling rules and automation settings

### `wp_datamachine_jobs`

**Purpose**: Individual execution records

```sql
CREATE TABLE wp_datamachine_jobs (
    job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    agent_id bigint(20) unsigned DEFAULT NULL,
    pipeline_id varchar(20) NULL DEFAULT NULL,
    flow_id varchar(20) NULL DEFAULT NULL,
    source varchar(50) NOT NULL DEFAULT 'pipeline',
    label varchar(255) NULL DEFAULT NULL,
    parent_job_id bigint(20) unsigned NULL DEFAULT NULL,
    status varchar(255) NOT NULL,
    engine_data longtext NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (job_id),
    KEY status (status),
    KEY pipeline_id (pipeline_id),
    KEY flow_id (flow_id),
    KEY source (source),
    KEY parent_job_id (parent_job_id),
    KEY user_id (user_id),
    KEY agent_id (agent_id)
);
```

**Fields**:
- `job_id` - Auto-increment primary key
- `user_id` - WordPress user who triggered the job
- `agent_id` - Agent this job belongs to (multi-agent scoping, @since v0.36.1)
- `pipeline_id` - Reference to source pipeline, or `'direct'` for ephemeral execution mode
- `flow_id` - Reference to flow that created this job, or `'direct'` for ephemeral execution mode
- `source` - Execution source: `'pipeline'` (standard), `'system'` (system tasks), or `'direct'` (ephemeral)
- `label` - Human-readable label for the job (used by system tasks)
- `parent_job_id` - Reference to parent job for batch execution (child jobs link back to parent)
- `status` - Current execution status (varchar(255) supports compound statuses like `agent_skipped - reason`)
- `engine_data` - Engine parameters (source_url, image_url, effects for undo) stored by handlers for downstream use
- `created_at` - Job creation timestamp
- `completed_at` - Completion timestamp

**Note:** `pipeline_id` and `flow_id` are `varchar(20)` to support both numeric IDs and the `'direct'` sentinel value for ephemeral workflows.

### `wp_datamachine_processed_items`

**Purpose**: Deduplication tracking to prevent duplicate processing

```sql
CREATE TABLE wp_datamachine_processed_items (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    flow_step_id VARCHAR(255) NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    item_identifier VARCHAR(255) NOT NULL,
    job_id BIGINT(20) UNSIGNED NOT NULL,
    processed_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY `flow_source_item` (flow_step_id, source_type, item_identifier(191)),
    KEY `flow_step_id` (flow_step_id),
    KEY `source_type` (source_type),
    KEY `job_id` (job_id),
    KEY `flow_source_ts` (flow_step_id, source_type, processed_timestamp)
);
```

**Fields**:
- `id` - Auto-increment primary key
- `flow_step_id` - Composite identifier: `{pipeline_step_id}_{flow_id}`
- `source_type` - Handler type (rss, wordpress_local, reddit, etc.)
- `item_identifier` - Unique identifier within source type
- `job_id` - Job that processed this item
- `processed_timestamp` - Processing timestamp

**Indexes**:
- `flow_source_item` (UNIQUE) — point lookups + dedupe constraint.
- `flow_step_id`, `source_type`, `job_id` — bulk deletes and filtered audits.
- `flow_source_ts` (since 0.71.0) — covers time-windowed range scans used by `find_stale()` / `has_been_processed_within()`. `ProcessedItems::ensure_flow_source_ts_index()` backfills the index on existing installs since `dbDelta` does not reliably add indexes to populated tables.

### `wp_datamachine_chat_sessions`

**Purpose**: Persistent conversation state for chat API with multi-turn conversation support

**Implementation**: `inc/Core/Database/Chat/Chat.php`

```sql
CREATE TABLE wp_datamachine_chat_sessions (
    session_id VARCHAR(50) NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    agent_id BIGINT(20) UNSIGNED NULL,
    title VARCHAR(100) NULL,
    messages LONGTEXT NOT NULL COMMENT 'JSON array of conversation messages',
    metadata LONGTEXT NULL COMMENT 'JSON object for session metadata',
    provider VARCHAR(50) NULL COMMENT 'AI provider (anthropic, openai, etc)',
    model VARCHAR(100) NULL COMMENT 'AI model identifier',
    context VARCHAR(20) NOT NULL DEFAULT 'chat',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NULL COMMENT 'Auto-cleanup timestamp',
    PRIMARY KEY (session_id),
    KEY user_id (user_id),
    KEY agent_id (agent_id),
    KEY context (context),
    KEY user_context (user_id, context),
    KEY created_at (created_at),
    KEY updated_at (updated_at),
    KEY expires_at (expires_at)
);
```

**Fields**:
- `session_id` - UUID4 session identifier (primary key)
- `user_id` - WordPress user ID (user-scoped isolation)
- `agent_id` - Agent this session belongs to (multi-agent scoping, @since v0.36.1)
- `title` - Auto-generated session title
- `messages` - JSON array of conversation messages (chronological ordering)
- `metadata` - JSON object with message_count, last_activity timestamps
- `provider` - AI provider used for session (optional, tracked for continuity)
- `model` - AI model used for session (optional, tracked for continuity)
- `context` - Session context: `'chat'` (default) or `'pipeline'`
- `created_at` - Session creation timestamp
- `updated_at` - Last activity timestamp
- `expires_at` - Expiration timestamp (24-hour default timeout)

**Session Management**:
- User-scoped and agent-scoped session isolation
- Automatic session creation on first message
- Session expiration with cleanup mechanism
- Metadata tracking for message count and activity timestamps

### `wp_datamachine_agents`

**Purpose**: Agent registry for multi-agent architecture (@since v0.36.1)

**Implementation**: `inc/Core/Database/Agents/Agents.php`

```sql
CREATE TABLE wp_datamachine_agents (
    agent_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_slug VARCHAR(200) NOT NULL,
    agent_name VARCHAR(200) NOT NULL,
    owner_id BIGINT(20) UNSIGNED NOT NULL,
    agent_config LONGTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id),
    UNIQUE KEY agent_slug (agent_slug),
    KEY owner_id (owner_id),
    KEY status (status)
);
```

**Fields**:
- `agent_id` - Auto-increment primary key
- `agent_slug` - Unique identifier for filesystem directory naming and CLI reference
- `agent_name` - Human-readable display name
- `owner_id` - WordPress user ID of the agent's creator/owner
- `agent_config` - JSON configuration (AI provider preferences, model settings)
- `status` - Agent status: `'active'` or `'inactive'`
- `created_at` - Creation timestamp
- `updated_at` - Last modification timestamp

### `wp_datamachine_agent_access`

**Purpose**: Role-based access control for multi-agent resource sharing (@since v0.36.1)

**Implementation**: `inc/Core/Database/Agents/AgentAccess.php`

```sql
CREATE TABLE wp_datamachine_agent_access (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'viewer',
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY agent_user (agent_id, user_id),
    KEY agent_id (agent_id),
    KEY user_id (user_id),
    KEY role (role)
);
```

**Fields**:
- `id` - Auto-increment primary key
- `agent_id` - Reference to the agent
- `user_id` - WordPress user who has access
- `role` - Access level: `'viewer'` (read), `'operator'` (read + execute), `'admin'` (full control)
- `granted_at` - When access was granted

**Role Hierarchy**: `viewer` (0) < `operator` (1) < `admin` (2). Higher roles inherit all lower-level permissions.

### `wp_datamachine_logs`

**Purpose**: Centralized system logs for all agent activity (@since v0.4.0)

**Implementation**: `inc/Core/Database/Logs/LogRepository.php`

```sql
CREATE TABLE wp_datamachine_logs (
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

**Fields**:
- `id` - Auto-increment primary key
- `agent_id` - Agent context for the log entry (nullable)
- `user_id` - WordPress user context (nullable)
- `level` - Log severity: `'debug'`, `'info'`, `'warning'`, `'error'`, `'critical'`
- `message` - Human-readable log message
- `context` - JSON context data (job_id, flow_id, stack traces, etc.)
- `created_at` - Log timestamp

## Relationships

### Primary Relationships

```
Agent (1) ──→ Pipeline (many)
           ──→ Flow (many)
           ──→ Job (many)
           ──→ ChatSession (many)

AgentAccess: Agent (1) ←→ User (many)  [role-based]

Pipeline (1) → Flow (many) → Job (many)
                ↓
            ProcessedItems (many)

Job (1) → ChildJob (many)  [via parent_job_id, batch execution]

User (1) → ChatSession (many)
```

### Key Identifiers

**Pipeline Step ID**: UUID4 for cross-flow step referencing
```php
$pipeline_step_id = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
```

**Flow Step ID**: Composite identifier for flow-specific tracking
```php
$flow_step_id = $pipeline_step_id . '_' . $flow_id;
```

**Agent Slug**: Unique string identifier for filesystem and CLI
```php
$agent_slug = 'my-agent'; // maps to agents/{slug}/ directory
```

## Database Operations

### Pipeline Operations

**Create Pipeline**:
```php
$pipeline_id = $db_pipelines->create_pipeline([
    'pipeline_name' => 'RSS to Twitter',
    'pipeline_config' => $config_json
]);
```

**Get Pipeline Config**:
```php
$config = $db_pipelines->get_pipeline_config($pipeline_id);
```

### Flow Operations

**Create Flow**:
```php
$flow_id = $db_flows->create_flow([
    'pipeline_id' => $pipeline_id,
    'flow_name' => 'Morning Posts',
    'flow_config' => $flow_config_json
]);
```

**Get Flow Config**:
```php
$config = apply_filters('datamachine_get_flow_config', [], $flow_id);
```

### Job Operations

**Create Job**:
```php
$job_id = $db_jobs->create_job([
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id
]);
```

**Create Child Job** (batch execution):
```php
$child_job_id = $db_jobs->create_job([
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id,
    'parent_job_id' => $parent_job_id
]);
```

**Fail Job**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/fail-job' );
$ability->execute( [ 'job_id' => $job_id, 'reason' => 'Processing failed: timeout exceeded' ] );

// Action Hook (for extensibility)
do_action('datamachine_update_job_status', $job_id, 'failed', 'Processing failed: timeout exceeded');
```

### Processed Items

**Mark Item Processed**:
```php
do_action('datamachine_mark_item_processed', $flow_step_id, 'rss', $item_id, $job_id);
```

**Check If Processed**:
```php
$is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'rss', $item_id);
```

### Chat Session Operations

**Create Session**:
```php
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;

$chat_db = new ChatDatabase();
$session_id = $chat_db->create_session($user_id, [
    'started_at' => current_time('mysql'),
    'message_count' => 0
]);
```

**Get Session**:
```php
$session = $chat_db->get_session($session_id);
// Returns: ['session_id', 'user_id', 'agent_id', 'title', 'messages', 'metadata',
//           'provider', 'model', 'context', 'created_at', 'updated_at', 'expires_at']
```

**Update Session**:
```php
$chat_db->update_session(
    $session_id,
    $messages,  // Complete messages array
    $metadata,  // Updated metadata
    $provider,  // AI provider
    $model      // AI model
);
```

**Cleanup Expired Sessions**:
```php
$deleted_count = $chat_db->cleanup_expired_sessions();
```

### Agent Operations

**Create Agent**:
```php
$result = wp_execute_ability('datamachine/create-agent', [
    'slug' => 'my-agent',
    'name' => 'My Agent',
]);
```

**Check Access**:
```php
$agent_access = new \DataMachine\Core\Database\Agents\AgentAccess();
$can_access = $agent_access->user_can_access($agent_id, $user_id, 'operator');
```

**Grant Access**:
```php
$agent_access->grant_access($agent_id, $user_id, 'operator');
```

## Configuration Storage

### Pipeline Config Structure

```json
{
    "step_uuid_1": {
        "step_type": "fetch",
        "handler": "rss",
        "execution_order": 0,
        "system_prompt": "AI instructions...",
        "handler_config": {
            "rss_url": "https://example.com/feed.xml"
        }
    },
    "step_uuid_2": {
        "step_type": "publish",
        "handler": "twitter",
        "execution_order": 1,
        "handler_config": {
            "twitter_include_source": true
        }
    }
}
```

### Flow Config Structure

```json
{
    "step_uuid_1_123": {
        "user_message": "Custom prompt for this flow instance...",
        "execution_order": 0
    },
    "step_uuid_2_123": {
        "execution_order": 1
    }
}
```

## Data Access Patterns

### Service Discovery

All database operations use direct repository instantiation:

```php
$db_pipelines      = new \DataMachine\Core\Database\Pipelines\Pipelines();
$db_flows          = new \DataMachine\Core\Database\Flows\Flows();
$db_jobs           = new \DataMachine\Core\Database\Jobs\Jobs();
$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
$db_agents         = new \DataMachine\Core\Database\Agents\Agents();
$db_agent_access   = new \DataMachine\Core\Database\Agents\AgentAccess();
$db_logs           = new \DataMachine\Core\Database\Logs\LogRepository();
$db_chat           = new \DataMachine\Core\Database\Chat\Chat();
```

### Transactional Operations

Database operations maintain referential integrity through foreign key constraints and cascading deletes.

**Pipeline Deletion**: Automatically removes associated flows, jobs, and processed items
**Flow Deletion**: Automatically removes associated jobs and processed items
**Job Deletion**: Sets processed items job_id to NULL
**Agent Deletion**: Removes agent_access grants; optionally removes filesystem directory

### Multi-Agent Scoping

All major tables carry `user_id` and `agent_id` columns for resource isolation:
- Queries filter by `agent_id` when in multi-agent context
- Single-agent mode uses `agent_id = NULL` or `0` (valid state)
- Migration (`datamachine_backfill_agent_ids`) backfills existing resources when agents are created

## Indexing Strategy

### Performance Indexes

- **Pipeline Name** - Fast pipeline lookups by name
- **Flow Pipeline ID** - Efficient flow-to-pipeline joins
- **Job Status** - Quick job status filtering
- **Job Parent ID** - Fast batch child-job lookups
- **Job Source** - Filter by execution source (pipeline, system, direct)
- **Processed Items Composite** - Fast deduplication checks
- **Timestamp Indexes** - Chronological queries and cleanup
- **Agent Slug (unique)** - Fast agent lookups by slug
- **Agent/Time Composite** - Log filtering by agent and time range
- **Level/Time Composite** - Log filtering by severity

### Query Optimization

- **Prepared Statements** - All queries use wpdb::prepare()
- **Selective Columns** - Only required columns retrieved
- **Proper Limits** - Pagination for large result sets
- **Index Hints** - Strategic use of composite indexes

## Migration History

### Key Schema Migrations

| Version | Migration | Description |
|---------|-----------|-------------|
| v0.4.0 | `LogRepository::create_table()` | Added centralized logs table |
| v0.36.1 | `datamachine_migrate_to_layered_architecture()` | Created agents table, migrated filesystem to three-layer dirs |
| v0.36.1 | `AgentAccess::create_table()` | Added role-based access control |
| v0.36.1 | `Pipelines::migrate_columns()` | Added `user_id`, `agent_id` to pipelines |
| v0.36.1 | `Flows::migrate_columns()` | Added `user_id`, `agent_id` to flows |
| v0.36.1 | `Jobs::migrate_columns()` | Added `user_id`, `agent_id`, `source`, `label`, `parent_job_id` to jobs |
| v0.36.1 | `Chat::ensure_agent_id_column()` | Added `agent_id`, `title`, `context` to chat_sessions |
| v0.36.1 | `datamachine_backfill_agent_ids()` | Backfilled agent_id on existing resources |

Migrations run automatically via `datamachine_maybe_run_migrations()` on `init` (priority 5) whenever code version exceeds stored DB version.
