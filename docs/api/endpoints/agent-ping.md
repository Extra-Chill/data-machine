# Agent Ping Endpoints

**Implementation**: `inc/Api/AgentPing.php`

**Base URL**: `/wp-json/datamachine/v1/agent-ping`

## Overview

Agent ping endpoints let an external agent callback confirm completion of an asynchronous ping and let the originating site poll callback status.

The callback data is stored in a WordPress transient. New callbacks default to a one-hour TTL; processed callbacks are retained for a short polling grace period.

## Authentication

Both routes require a bearer token in the `Authorization` header:

```text
Authorization: Bearer <datamachine_agent_ping_callback_token option value>
```

The expected token is read from the `datamachine_agent_ping_callback_token` option. If no token is configured, all requests are rejected with HTTP 403. Missing or invalid bearer tokens return HTTP 401.

This token is separate from agent runtime bearer tokens created under `/agents/{agent}/tokens`.

## Route Table

| Method | Route | Purpose |
|---|---|---|
| POST | `/agent-ping/confirm` | Store the completion status for a callback ID and fire `datamachine_agent_ping_confirmed`. |
| GET | `/agent-ping/callback/{callback_id}` | Poll the stored callback status. |

## Core Parameters

| Parameter | Route | Type | Required | Notes |
|---|---|---|---|---|
| `callback_id` | both | string | yes | Unique callback ID from the original ping. URL param for polling, body param for confirm. |
| `status` | confirm | string | yes | `success`, `failed`, or `timeout`. |
| `message_preview` | confirm | string | no | Short preview of the agent response. |
| `error_message` | confirm | string | no | Error details when `status` is `failed`. |

## Response Shape

Confirmation response:

```json
{
  "success": true,
  "message": "Confirmation received.",
  "callback_id": "cb_123",
  "job_id": 456,
  "flow_step_id": 789
}
```

If the callback was already processed, confirm returns success with `processed: true` and the previous status.

Polling response:

```json
{
  "callback_id": "cb_123",
  "job_id": 456,
  "flow_step_id": 789,
  "status": "pending",
  "message_preview": "",
  "error_message": "",
  "processed_at": null,
  "created_at": "2026-01-18 12:00:00",
  "expires_at": "2026-01-18 13:00:00"
}
```

Unknown or expired callback IDs return `callback_not_found` with HTTP 404.

## Agent Usage Examples

Confirm a successful run:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/agent-ping/confirm \
  -H "Authorization: Bearer callback-secret" \
  -H "Content-Type: application/json" \
  -d '{"callback_id":"cb_123","status":"success","message_preview":"Task completed"}'
```

Poll for completion:

```bash
curl https://example.com/wp-json/datamachine/v1/agent-ping/callback/cb_123 \
  -H "Authorization: Bearer callback-secret"
```
