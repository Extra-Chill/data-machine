# Chat Database

**Location**: `/inc/Core/Database/Chat/Chat.php`

**Since**: v0.2.0 (Universal Engine Architecture)

**Namespace**: `DataMachine\Core\Database\Chat`

**Table**: `wp_datamachine_chat_sessions`

## Overview

ChatDatabase provides comprehensive session management for the Chat API, handling CRUD operations, user isolation, and automatic session expiration. Supports persistent conversation storage with 24-hour session lifespans.

## Purpose

Centralizes all database operations for chat sessions, enabling multi-turn conversations with conversation history persistence, user-scoped access control, and automatic cleanup of expired sessions.

## Database Schema

### Table Structure

**Table Name**: `wp_datamachine_chat_sessions`

**Columns**:
```sql
CREATE TABLE wp_datamachine_chat_sessions (
    session_id VARCHAR(50) NOT NULL,              -- UUID4 session identifier
    user_id BIGINT(20) UNSIGNED NOT NULL,         -- WordPress user ID
    messages LONGTEXT NOT NULL,                   -- JSON array of conversation messages
    metadata LONGTEXT NULL,                       -- JSON object for session metadata
    provider VARCHAR(50) NULL,                    -- AI provider (anthropic, openai, google, grok, openrouter)
    model VARCHAR(100) NULL,                      -- AI model identifier (e.g., claude-3-5-sonnet-20241022)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,                     -- Auto-cleanup timestamp (24 hours from creation)
    PRIMARY KEY (session_id),
    KEY user_id (user_id),
    KEY created_at (created_at),
    KEY expires_at (expires_at)
);
```

**Indexes**:
- Primary key on `session_id` for direct session lookup
- Index on `user_id` for user-scoped queries
- Index on `created_at` for chronological ordering
- Index on `expires_at` for efficient cleanup queries

**Character Set**: Uses WordPress database charset and collation

---

## Core Methods

### Table Management

#### create_table()

Create chat sessions table using WordPress dbDelta for safe table creation/updates.

**Signature**:
```php
public static function create_table(): void
```

**Behavior**:
- Uses `dbDelta()` for safe table creation (handles existing tables)
- Creates indexes for efficient querying
- Logs table creation via `datamachine_log` action
- Called during plugin activation

**Example**:
```php
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;

// During plugin activation
ChatDatabase::create_table();
```

**Usage**: Automatic during plugin activation, safe to call multiple times

---

#### table_exists()

Check if chat sessions table exists in database.

**Signature**:
```php
public static function table_exists(): bool
```

**Returns**: True if table exists, false otherwise

**Example**:
```php
if (ChatDatabase::table_exists()) {
    // Table ready for use
} else {
    ChatDatabase::create_table();
}
```

---

#### get_table_name()

Get full table name with WordPress prefix.

**Signature**:
```php
public static function get_table_name(): string
```

**Returns**: Full table name (e.g., `wp_datamachine_chat_sessions`)

**Example**:
```php
$table = ChatDatabase::get_table_name();
// Returns: "wp_datamachine_chat_sessions" (or custom prefix)
```

---

### Session CRUD Operations

#### create_session()

Create new chat session for user.

**Signature**:
```php
public function create_session(int $user_id, array $metadata = []): string
```

**Parameters**:
- `$user_id` (int) - WordPress user ID
- `$metadata` (array) - Optional session metadata (default: empty array)

**Returns**: Session ID (UUID4) or empty string on failure

**Default Values**:
- `session_id`: Generated UUID4
- `messages`: Empty JSON array (`[]`)
- `metadata`: JSON-encoded metadata array
- `provider`: NULL
- `model`: NULL
- `expires_at`: 24 hours from creation time (GMT)

**Example**:
```php
$chat_db = new ChatDatabase();

$session_id = $chat_db->create_session(
    get_current_user_id(),
    ['created_by' => 'chat_interface', 'version' => '1.0']
);

if ($session_id) {
    // Session created successfully
    // Use $session_id for subsequent operations
}
```

**Logging**:
- Success: Debug log with session_id and user_id
- Failure: Error log with user_id and database error

---

#### get_session()

Retrieve session data with automatic expiration checking.

**Signature**:
```php
public function get_session(string $session_id): ?array
```

**Parameters**:
- `$session_id` (string) - Session UUID

**Returns**: Session data array or null if not found/expired

**Returned Array Structure**:
```php
[
    'session_id' => 'uuid-string',
    'user_id' => 123,
    'messages' => [  // Decoded from JSON
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => 'Hi there!']
    ],
    'metadata' => [  // Decoded from JSON
        'message_count' => 2,
        'last_activity' => '2024-01-01 12:00:00'
    ],
    'provider' => 'anthropic',
    'model' => 'claude-3-5-sonnet-20241022',
    'created_at' => '2024-01-01 10:00:00',
    'updated_at' => '2024-01-01 12:00:00',
    'expires_at' => '2024-01-02 10:00:00'
]
```

**Expiration Handling**:
- Checks `expires_at` timestamp against current time
- Automatically deletes expired sessions
- Returns null for expired sessions

**JSON Decoding**:
- `messages` field decoded to PHP array
- `metadata` field decoded to PHP array
- Invalid JSON defaults to empty array

**Example**:
```php
$chat_db = new ChatDatabase();

$session = $chat_db->get_session('uuid-abc-123');

if ($session) {
    $messages = $session['messages'];
    $user_id = $session['user_id'];
    // Continue conversation
} else {
    // Session not found or expired
    // Create new session
}
```

**Security**: No user isolation check in this method - caller must verify user_id matches current user

---

#### update_session()

Update session with new messages and metadata.

**Signature**:
```php
public function update_session(
    string $session_id,
    array $messages,
    array $metadata = [],
    string $provider = '',
    string $model = ''
): bool
```

**Parameters**:
- `$session_id` (string) - Session UUID
- `$messages` (array) - Complete messages array (replaces existing)
- `$metadata` (array) - Updated metadata (replaces existing)
- `$provider` (string) - AI provider (optional, only updated if non-empty)
- `$model` (string) - AI model (optional, only updated if non-empty)

**Returns**: True on success, false on failure

**Update Behavior**:
- `messages`: Always replaced with provided array (JSON-encoded)
- `metadata`: Always replaced with provided array (JSON-encoded)
- `provider`: Only updated if non-empty string provided
- `model`: Only updated if non-empty string provided
- `updated_at`: Automatically updated by database

**Example**:
```php
$chat_db = new ChatDatabase();

// Get current session
$session = $chat_db->get_session('uuid-abc-123');

// Add new message to conversation
$messages = $session['messages'];
$messages[] = ['role' => 'user', 'content' => 'New message'];

// Update metadata
$metadata = $session['metadata'];
$metadata['message_count'] = count($messages);
$metadata['last_activity'] = gmdate('Y-m-d H:i:s');

// Save updates
$success = $chat_db->update_session(
    'uuid-abc-123',
    $messages,
    $metadata,
    'anthropic',
    'claude-sonnet-4'
);
```

**Logging**:
- Failure: Error log with session_id and database error

**Important**: Caller must retrieve existing messages/metadata and merge changes - this method replaces entire arrays

---

#### delete_session()

Delete session from database.

**Signature**:
```php
public function delete_session(string $session_id): bool
```

**Parameters**:
- `$session_id` (string) - Session UUID

**Returns**: True on success, false on failure

**Example**:
```php
$chat_db = new ChatDatabase();

$deleted = $chat_db->delete_session('uuid-abc-123');

if ($deleted) {
    // Session deleted successfully
}
```

**Logging**:
- Success: Debug log with session_id
- Failure: Error log with session_id and database error

**Usage**:
- Manual session termination
- Automatic expiration cleanup (called by get_session)
- User logout cleanup

---

### Maintenance

#### cleanup_expired_sessions()

Cleanup all expired sessions from database.

**Signature**:
```php
public function cleanup_expired_sessions(): int
```

**Returns**: Number of deleted sessions

**Cleanup Logic**:
- Deletes sessions where `expires_at IS NOT NULL AND expires_at < NOW()`
- Uses GMT time for comparison
- Batch deletion for efficiency

**Example**:
```php
$chat_db = new ChatDatabase();

$deleted_count = $chat_db->cleanup_expired_sessions();

// Result: 15 expired sessions deleted
```

**Logging**:
- Logs info message if any sessions deleted (includes count)

**Recommended Schedule**: Run via WordPress cron job (hourly or daily)

**Cron Setup Example**:
```php
// Register cron event
add_action('init', function() {
    if (!wp_next_scheduled('datamachine_cleanup_chat_sessions')) {
        wp_schedule_event(time(), 'hourly', 'datamachine_cleanup_chat_sessions');
    }
});

// Hook cleanup function
add_action('datamachine_cleanup_chat_sessions', function() {
    $chat_db = new \DataMachine\Core\Database\Chat\Chat();
    $chat_db->cleanup_expired_sessions();
});
```

---

## Integration with Chat API

### Chat Endpoint Usage

The Chat API endpoint (`/inc/Api/Chat/Chat.php`) uses ChatDatabase for session management:

**Session Creation**:
```php
// First message (no session_id provided)
$chat_db = new ChatDatabase();
$session_id = $chat_db->create_session(get_current_user_id());
```

**Session Retrieval**:
```php
// Subsequent messages (session_id provided)
$session = $chat_db->get_session($session_id);

// Verify user ownership
if ($session['user_id'] !== get_current_user_id()) {
    return new WP_Error('session_access_denied', 'Access denied to this session', ['status' => 403]);
}
```

**Session Update**:
```php
// After AI response
$messages = $session['messages'];
$messages[] = ['role' => 'user', 'content' => $user_message];
$messages[] = ['role' => 'assistant', 'content' => $ai_response];

$metadata = [
    'message_count' => count($messages),
    'last_activity' => gmdate('Y-m-d H:i:s')
];

$chat_db->update_session($session_id, $messages, $metadata, $provider, $model);
```

---

## Security Features

### User Isolation

Sessions are scoped to individual WordPress users:
- **Creation**: Requires valid user_id
- **Access**: Caller must verify session user_id matches current user
- **No Cross-User Access**: Database queries don't enforce this - API layer responsibility

**Security Check Pattern**:
```php
$session = $chat_db->get_session($session_id);

if (!$session) {
    return new WP_Error('session_not_found', 'Session not found or expired', ['status' => 404]);
}

if ($session['user_id'] !== get_current_user_id()) {
    return new WP_Error('session_access_denied', 'Access denied to this session', ['status' => 403]);
}
```

### Automatic Expiration

Sessions automatically expire after 24 hours:
- **Expiration Time**: Set to `NOW() + 24 hours` at creation
- **Cleanup**: Automatic via `get_session()` and manual via `cleanup_expired_sessions()`
- **Purpose**: Prevent stale session accumulation

---

## Data Storage Patterns

### Messages Array

**Structure**:
```php
[
    ['role' => 'user', 'content' => 'First user message'],
    ['role' => 'assistant', 'content' => 'AI response', 'tool_calls' => [...]],
    ['role' => 'user', 'content' => 'TOOL RESPONSE: ...'],
    ['role' => 'assistant', 'content' => 'Final response']
]
```

**Storage**: JSON-encoded LONGTEXT
**Retrieval**: JSON-decoded to PHP array
**Validation**: Invalid JSON defaults to empty array

### Metadata Object

**Common Fields**:
```php
[
    'message_count' => 10,
    'last_activity' => '2024-01-01 12:00:00',
    'created_by' => 'chat_interface',
    'version' => '1.0',
    'custom_field' => 'custom_value'
]
```

**Storage**: JSON-encoded LONGTEXT
**Flexibility**: Arbitrary key-value pairs allowed
**Retrieval**: JSON-decoded to PHP array

### Provider and Model Tracking

**Purpose**: Track which AI provider/model used for conversation
**Updates**: Set during first AI call, can be updated on subsequent calls
**Nullable**: Can be NULL if not yet set

---

## Performance Considerations

### Indexes

Optimized for common query patterns:
- **session_id (PRIMARY)**: Direct session lookup (O(1))
- **user_id**: User session queries
- **created_at**: Chronological ordering
- **expires_at**: Efficient cleanup queries

### JSON Storage

**Benefits**:
- Flexible message structure
- No schema changes needed for metadata additions
- Efficient storage for variable-length arrays

**Considerations**:
- JSON decoding overhead (minimal for chat usage)
- Not indexed (full messages not searchable)
- Acceptable for conversation-scoped queries

---

## Usage Examples

### Complete Session Lifecycle

```php
use DataMachine\Core\Database\Chat\Chat as ChatDatabase;

$chat_db = new ChatDatabase();
$user_id = get_current_user_id();

// 1. Create new session
$session_id = $chat_db->create_session($user_id, [
    'created_by' => 'rest_api',
    'version' => '1.0'
]);

// 2. First message exchange
$messages = [
    ['role' => 'user', 'content' => 'Create a pipeline']
];

$chat_db->update_session($session_id, $messages, [
    'message_count' => 1,
    'last_activity' => gmdate('Y-m-d H:i:s')
]);

// 3. Continue conversation
$session = $chat_db->get_session($session_id);
$messages = $session['messages'];
$messages[] = ['role' => 'assistant', 'content' => 'I\'ll create a pipeline for you...'];

$chat_db->update_session($session_id, $messages, [
    'message_count' => count($messages),
    'last_activity' => gmdate('Y-m-d H:i:s')
], 'anthropic', 'claude-sonnet-4');

// 4. Session expires automatically after 24 hours
// Or manual cleanup:
$chat_db->cleanup_expired_sessions();
```

### User Session Management

```php
// Get all user sessions (custom query)
global $wpdb;
$table = ChatDatabase::get_table_name();

$user_sessions = $wpdb->get_results($wpdb->prepare(
    "SELECT session_id, created_at, updated_at FROM {$table}
     WHERE user_id = %d AND expires_at > %s
     ORDER BY updated_at DESC",
    get_current_user_id(),
    current_time('mysql', true)
), ARRAY_A);

// Display active sessions to user
foreach ($user_sessions as $session_info) {
    echo "Session: {$session_info['session_id']} (Updated: {$session_info['updated_at']})\n";
}
```

---

## Related Components

- Chat API Endpoint - REST API using ChatDatabase
- AIConversationLoop - Conversation execution
- Universal Engine Architecture - Shared AI infrastructure
- Database Schema - Complete database documentation

---

**Location**: `/inc/Core/Database/Chat/Chat.php`
**Namespace**: `DataMachine\Core\Database\Chat`
**Table**: `wp_datamachine_chat_sessions`
**Since**: v0.2.0
