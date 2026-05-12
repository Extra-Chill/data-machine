# Chat Sessions Endpoints

**Implementation**: `inc/Api/Chat/Chat.php`

Data Machine stores chat conversations as user-scoped sessions in `wp_datamachine_chat_sessions`.

## Authentication

Requires the Data Machine `chat` permission (`PermissionHelper::can( 'chat' )`).

## Response Envelope

Session routes return:

```json
{
  "success": true,
  "data": {}
}
```

The `data` shape is the corresponding Chat Session ability result.

## Routes

### GET `/wp-json/datamachine/v1/chat/sessions`

List sessions for the current user and scoped agent.

**Query parameters**:

- `limit` (integer, optional, default `20`): maximum sessions to return.
- `offset` (integer, optional, default `0`): pagination offset.
- `mode` (string, optional): `chat`, `pipeline`, or `system`.
- `agent_id` (integer, optional): filter sessions by agent.

`agent_type` is not a REST parameter for this route.

### GET `/wp-json/datamachine/v1/chat/{session_id}`

Retrieve one session by UUID-like session ID (`[a-f0-9-]+`).

### DELETE `/wp-json/datamachine/v1/chat/{session_id}`

Delete one session by UUID-like session ID.

### POST `/wp-json/datamachine/v1/chat/sessions/{session_id}/read`

Mark a session read for the current user.

## Errors

- `session_not_found` (404): session does not exist.
- `session_access_denied` (403): session belongs to another user.
- `ability_not_found` (500): session ability is not registered.
