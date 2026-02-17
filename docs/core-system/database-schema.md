# Database Schema

Data Machine uses five core tables for managing pipelines, flows, jobs, deduplication tracking, and chat sessions.

## Core Tables

### `wp_datamachine_pipelines`

**Purpose**: Reusable workflow templates

```sql
CREATE TABLE wp_datamachine_pipelines (
    pipeline_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    pipeline_name varchar(255) NOT NULL,
    pipeline_config longtext NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pipeline_id),
    KEY pipeline_name (pipeline_name),
    KEY created_at (created_at),
    KEY updated_at (updated_at)
);
```

**Fields**:
- `pipeline_id` - Auto-increment primary key
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
    flow_name varchar(255) NOT NULL,
    flow_config longtext NULL,
    scheduling_config longtext NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (flow_id),
    KEY pipeline_id (pipeline_id),
    KEY flow_name (flow_name),
    FOREIGN KEY (pipeline_id) REFERENCES wp_datamachine_pipelines(pipeline_id) ON DELETE CASCADE
);
```

**Fields**:
- `flow_id` - Auto-increment primary key
- `pipeline_id` - Reference to parent pipeline
- `flow_name` - Instance-specific name
- `flow_config` - JSON configuration with flow-specific settings
- `scheduling_config` - Scheduling rules and automation settings

### `wp_datamachine_jobs`

**Purpose**: Individual execution records

```sql
CREATE TABLE wp_datamachine_jobs (
    job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    pipeline_id varchar(20) NOT NULL,
    flow_id varchar(20) NOT NULL,
    status varchar(100) NOT NULL,
    engine_data longtext NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (job_id),
    KEY status (status),
    KEY pipeline_id (pipeline_id),
    KEY flow_id (flow_id)
);
```

**Fields**:
- `job_id` - Auto-increment primary key
- `pipeline_id` - Reference to source pipeline, or `'direct'` for direct execution mode
- `flow_id` - Reference to flow that created this job, or `'direct'` for direct execution mode
- `status` - Current execution status (varchar(100) supports compound statuses like `agent_skipped - reason`)
- `engine_data` - Engine parameters (source_url, image_url) stored by fetch handlers for downstream use
- `created_at` - Job creation timestamp
- `completed_at` - Completion timestamp

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
    KEY `job_id` (job_id)
);
```

**Fields**:
- `id` - Auto-increment primary key
- `flow_step_id` - Composite identifier: `{pipeline_step_id}_{flow_id}`
- `source_type` - Handler type (rss, wordpress_local, reddit, etc.)
- `item_identifier` - Unique identifier within source type
- `job_id` - Job that processed this item
- `processed_timestamp` - Processing timestamp

### `wp_datamachine_chat_sessions`

**Purpose**: Persistent conversation state for chat API with multi-turn conversation support

**Implementation**: `inc/Core/Database/Chat/Chat.php` (unified database component)

```sql
CREATE TABLE wp_datamachine_chat_sessions (
    session_id VARCHAR(50) NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    messages LONGTEXT NOT NULL COMMENT 'JSON array of conversation messages',
    metadata LONGTEXT NULL COMMENT 'JSON object for session metadata',
    provider VARCHAR(50) NULL COMMENT 'AI provider (anthropic, openai, etc)',
    model VARCHAR(100) NULL COMMENT 'AI model identifier',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NULL COMMENT 'Auto-cleanup timestamp',
    PRIMARY KEY (session_id),
    KEY user_id (user_id),
    KEY created_at (created_at),
    KEY expires_at (expires_at)
);
```

**Fields**:
- `session_id` - UUID4 session identifier (primary key)
- `user_id` - WordPress user ID (user-scoped isolation)
- `messages` - JSON array of conversation messages (chronological ordering)
- `metadata` - JSON object with message_count, last_activity timestamps
- `provider` - AI provider used for session (optional, tracked for continuity)
- `model` - AI model used for session (optional, tracked for continuity)
- `created_at` - Session creation timestamp
- `updated_at` - Last activity timestamp
- `expires_at` - Expiration timestamp (24-hour default timeout)

**Session Management**:
- User-scoped session isolation (users can only access their own sessions)
- Automatic session creation on first message
- Session expiration with cleanup mechanism
- Metadata tracking for message count and activity timestamps

## Relationships

### Primary Relationships

```
Pipeline (1) → Flow (many) → Job (many)
                ↓
            ProcessedItems (many)

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

**Update Status**:
```php
// Abilities API
$ability = wp_get_ability( 'datamachine/fail-job' );
$ability->execute( [ 'job_id' => $job_id, 'reason' => 'Success message' ] );

// Action Hook (for extensibility)
do_action('datamachine_update_job_status', $job_id, 'completed', 'Success message');
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
// Returns: ['session_id', 'user_id', 'messages', 'metadata', 'provider', 'model', 'created_at', 'updated_at', 'expires_at']
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

All database operations use filter-based discovery:

```php
$db_pipelines = new \DataMachine\Core\Database\Pipelines\Pipelines();
$db_flows = new \DataMachine\Core\Database\Flows\Flows();
$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();
$db_processed_items = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
```

### Transactional Operations

Database operations maintain referential integrity through foreign key constraints and cascading deletes.

**Pipeline Deletion**: Automatically removes associated flows, jobs, and processed items
**Flow Deletion**: Automatically removes associated jobs and processed items
**Job Deletion**: Sets processed items job_id to NULL

## Indexing Strategy

### Performance Indexes

- **Pipeline Name** - Fast pipeline lookups by name
- **Flow Pipeline ID** - Efficient flow-to-pipeline joins
- **Job Status** - Quick job status filtering
- **Processed Items Composite** - Fast deduplication checks
- **Timestamp Indexes** - Chronological queries and cleanup

### Query Optimization

- **Prepared Statements** - All queries use wpdb::prepare()
- **Selective Columns** - Only required columns retrieved
- **Proper Limits** - Pagination for large result sets
- **Index Hints** - Strategic use of composite indexes

