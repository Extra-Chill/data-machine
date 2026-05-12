# Agent Ping Endpoints

**Implementation**: `inc/Api/AgentPing.php`

**Base URL**: `/wp-json/datamachine/v1/agent-ping`

Agent Ping endpoints receive and expose completion callbacks for outbound agent pings.

## Endpoints

| Method | Route | Purpose | Permission |
|--------|-------|---------|------------|
| `POST` | `/agent-ping/confirm` | Record a callback result for an agent ping. | Bearer token in `Authorization`. |
| `GET` | `/agent-ping/callback/{callback_id}` | Poll callback status. | Bearer token in `Authorization`. |

## Authentication

These routes do not use WordPress capabilities. `AgentPing::check_permission()` validates an `Authorization: Bearer <token>` header against the configured `datamachine_agent_ping_callback_token` option.

If the option is empty, callback requests are rejected. If the header is missing or the token does not match, the route returns `401` or `403` depending on the failure.

## Callback Payload

`POST /agent-ping/confirm` requires:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `callback_id` | string | Yes | Callback ID from the original ping. |
| `status` | string | Yes | One of `success`, `failed`, or `timeout`. |
| `message_preview` | string | No | Preview of the agent response. |
| `error_message` | string | No | Error message when status is `failed`. |
