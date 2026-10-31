# Chat Endpoint

**Implementation**: `inc/Api/Chat/Chat.php`

**Base URL**: `/wp-json/datamachine/v1/chat`

The chat API sends messages to the Data Machine chat agent and manages turn-by-turn continuation. Session CRUD is documented in [Chat Sessions](chat-sessions.md).

## Authentication

`POST /chat` and `POST /chat/continue` require the Data Machine `chat` permission (`PermissionHelper::can( 'chat' )`). `POST /chat/ping` uses a bearer token that must match the `chat_ping_secret` setting.

## Response Envelope

Chat routes return a REST envelope:

```json
{
  "success": true,
  "data": {}
}
```

`data` is the send-message, continue, or ping result. The top-level response does not expose `session_id`, `response`, or `conversation`; read those from `data` when the underlying ability returns them.

## Routes

### POST `/wp-json/datamachine/v1/chat`

Send a message. The first message creates a session; include `session_id` to continue a persisted session.

**Body parameters**:

- `message` (string, required): user message.
- `session_id` (string, optional): existing session ID.
- `provider` (string, optional): registered AI provider; defaults to resolved settings.
- `model` (string, optional): model identifier; defaults to resolved settings.
- `selected_pipeline_id` (integer, optional): pipeline context for chat directives.
- `attachments` (array, optional): media attachments, each with `url`, `media_id`, `mime_type`, and/or `filename`.
- `client_context` (object, optional): arbitrary client context injected as a system message.

**Headers**:

- `X-Request-Id` (string, optional): enables a 60-second idempotency cache for duplicate requests.

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"message":"Create a pipeline from RSS to Bluesky"}'
```

### POST `/wp-json/datamachine/v1/chat/continue`

Continue turn-by-turn execution for a session.

**Body parameters**:

- `session_id` (string, required): session ID to continue.

### POST `/wp-json/datamachine/v1/chat/ping`

Send a bearer-token-authenticated ping to the chat agent. This route is intended for external webhook-style notifications, not normal WordPress user requests.

**Authorization**: `Authorization: Bearer <chat_ping_secret>`.

**Body parameters**:

- `message` (string, required): message for the chat agent.
- `prompt` (string, optional): extra system-level instructions prepended to the message.
- `context` (object, optional): flow, pipeline, job, or other context appended to the message.

**Errors**:

- `ping_not_configured` (403): `chat_ping_secret` is empty.
- `missing_authorization` (401): no authorization header.
- `invalid_token` (403): bearer token mismatch.

## Session Routes

- `GET /chat/sessions`: list sessions.
- `GET /chat/{session_id}`: get a session.
- `DELETE /chat/{session_id}`: delete a session.
- `POST /chat/sessions/{session_id}/read`: mark a session read.
