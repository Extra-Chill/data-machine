# Chat Sessions Endpoints

**Implementation**: `inc/Api/Chat/Chat.php`

## Overview

Data Machine persists chat conversations as sessions. Sessions are user-scoped and stored in `wp_datamachine_chat_sessions`.

## Authentication

Requires WordPress authentication with `manage_options` capability.

## Endpoints

### GET `/wp-json/datamachine/v1/chat/sessions`

List chat sessions for the current user.

**Query Parameters**:
- `limit` (integer, optional, default: `20`): Maximum sessions to return (capped at 100)
- `offset` (integer, optional, default: `0`): Pagination offset
- `agent_type` (string, optional, default: `chat`): Deprecated. Use `agent_id` to filter by specific agent.

**Success Response**:

```json
{
  "success": true,
  "data": {
    "sessions": [],
    "total": 0,
    "limit": 20,
    "offset": 0,
    "agent_type": "chat"
  }
}
```

### GET `/wp-json/datamachine/v1/chat/{session_id}`

Retrieve a single session by ID.

**Success Response**:

```json
{
  "success": true,
  "data": {
    "session_id": "<uuid>",
    "conversation": [],
    "metadata": {}
  }
}
```

### DELETE `/wp-json/datamachine/v1/chat/{session_id}`

Delete a session by ID.

**Success Response**:

```json
{
  "success": true,
  "data": {
    "session_id": "<uuid>",
    "deleted": true
  }
}
```

**Errors**:
- `session_not_found` (404): Session does not exist
- `session_access_denied` (403): Session exists but belongs to a different user
- `session_delete_failed` (500): Database deletion failed
