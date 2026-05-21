# Data Machine REST API

Complete REST API reference for Data Machine.

## Overview

**Base URL**: `/wp-json/datamachine/v1/`

**Authentication**: WordPress application password, WordPress admin cookie authentication, or endpoint-specific bearer-token authentication where noted.

**Permissions**: REST controllers use `DataMachine\Abilities\PermissionHelper`, not a single generic `manage_options` check. WordPress administrators still pass because `manage_options` grants the mapped Data Machine capabilities, but the canonical permissions are scoped actions such as `manage_flows`, `manage_agents`, `manage_settings`, `chat`, `use_tools`, `view_logs`, and `create_own_agent`.

**Implementation**: All REST route registrations live in `inc/Api/`. This inventory is sourced from `register_routes()` implementations in that directory.

## Route Inventory

| Group | Routes | Permission model | Source | Docs |
|-------|--------|------------------|--------|------|
| Agents | `/agents`, `/agents/me`, `/agents/{agent}`, `/agents/{agent_id}`, `/agents/{agent}/access`, `/agents/{agent_id}/access`, `/agents/{agent}/access/{user_id}`, `/agents/{agent_id}/access/{user_id}`, `/agents/{agent}/tokens`, `/agents/{agent_id}/tokens`, `/agents/{agent}/tokens/{token_id}`, `/agents/{agent_id}/tokens/{token_id}` | Scoped agent management. Listing is available to logged-in users and scoped by ownership/access grants. Create requires `manage_agents` or `create_own_agent`. Single-agent, access, and token management require `manage_agents`. `/agents/me` accepts an agent bearer-token context or a logged-in user. | `inc/Api/Agents.php` | [Agents](endpoints/agents.md) |
| Agent Ping | `/agent-ping/confirm`, `/agent-ping/callback/{callback_id}` | Bearer-token callback auth using the configured agent-ping callback token. No WordPress capability check. | `inc/Api/AgentPing.php` | [Agent Ping](endpoints/agent-ping.md) |
| Analytics | `/analytics/gsc`, `/analytics/ga`, `/analytics/pagespeed`; extensions may add more via `datamachine_analytics_ability_map` | `manage_flows` via `PermissionHelper::can( 'manage_flows' )`. | `inc/Api/Analytics.php` | [Analytics](endpoints/analytics.md) |
| Auth | `/auth/providers`, `/auth/{handler_slug}`, `/auth/{handler_slug}/status`, `/auth/{handler_slug}/token`, `/auth/{handler_slug}/refresh` | `manage_settings` through `Auth::check_permission()`. | `inc/Api/Auth.php` | [Auth](endpoints/auth.md) |
| Chat | `/chat`, `/chat/continue`, `/chat/{session_id}`, `/chat/sessions`, `/chat/sessions/{session_id}/read`; `/chat/ping` | Chat routes require `chat`. `/chat/ping` uses the chat ping token verifier. | `inc/Api/Chat/Chat.php` | [Chat](endpoints/chat.md), [Chat Sessions](endpoints/chat-sessions.md) |
| Email | `/email/send`, `/email/fetch`, `/email/{uid}/read`, `/email/reply`, `/email/{uid}`, `/email/{uid}/move`, `/email/{uid}/flag`, `/email/batch/move`, `/email/batch/flag`, `/email/batch/delete`, `/email/{uid}/unsubscribe`, `/email/batch/unsubscribe`, `/email/test-connection` | `PermissionHelper::can_manage()`, meaning any Data Machine management capability: `manage_flows`, `manage_settings`, or `manage_agents`. | `inc/Api/Email.php` | [Email](endpoints/email.md) |
| Execute | `/execute` | `manage_flows` through the execute controller. | `inc/Api/Execute.php` | [Execute](endpoints/execute.md) |
| Files | `/files`, `/files/{filename}`, `/files/agent`, `/files/agent/{filename}`, `/files/agent/daily`, `/files/agent/daily/{year}/{month}/{day}` | Flow files require a logged-in user plus `PermissionHelper::can_manage()`. Agent files allow users to access their own files; `manage_agents` can access another user's files. | `inc/Api/FlowFiles.php`, `inc/Api/AgentFiles.php` | [Files](endpoints/files.md) |
| Flows | `/flows`, `/flows/{flow_id}`, `/flows/{flow_id}/pause`, `/flows/{flow_id}/resume`, `/flows/pause`, `/flows/resume`, `/flows/{flow_id}/duplicate`, `/flows/{flow_id}/memory-files`, `/flows/problems`, `/flows/{flow_id}/queue`, `/flows/{flow_id}/queue/{index}`, `/flows/{flow_id}/queue/mode`, `/flows/{flow_id}/config`, `/flows/steps/{flow_step_id}/config`, `/flows/steps/{flow_step_id}/handler`, `/flows/steps/{flow_step_id}/user-message` | Flow management through scoped `PermissionHelper` checks in the flow controllers. | `inc/Api/Flows/*.php` | [Flows](endpoints/flows.md) |
| Handlers | `/handlers`, `/handlers/{handler_slug}` | Public metadata endpoints. | `inc/Api/Handlers.php` | [Handlers](endpoints/handlers.md) |
| Internal Links | `/links/audit`, `/links/orphans`, `/links/backlinks`, `/links/broken`, `/links/diagnose` | `manage_flows` via `PermissionHelper::can( 'manage_flows' )`. | `inc/Api/InternalLinks.php` | [Internal Links](endpoints/internal-links.md) |
| Jobs | `/jobs`, `/jobs/{id}` | `manage_flows`, with scoped user/agent resolution in list handling. | `inc/Api/Jobs.php` | [Jobs](endpoints/jobs.md) |
| Logs | `/logs`, `/logs/metadata` | `view_logs` through the logs controller permission callback. | `inc/Api/Logs.php` | [Logs](endpoints/logs.md) |
| Pipelines | `/pipelines`, `/pipelines/{pipeline_id}`, `/pipelines/{pipeline_id}/memory-files`, `/pipelines/{pipeline_id}/flows`, `/pipelines/{pipeline_id}/steps`, `/pipelines/{pipeline_id}/steps/{step_id}`, `/pipelines/{pipeline_id}/steps/reorder`, `/pipelines/steps/{pipeline_step_id}/system-prompt`, `/pipelines/steps/{pipeline_step_id}/config` | Pipeline management through scoped `PermissionHelper` checks in the pipeline controllers. | `inc/Api/Pipelines/*.php` | [Pipelines](endpoints/pipelines.md) |
| Processed Items | `/processed-items` | `manage_flows` through `ProcessedItems::check_permission()`. | `inc/Api/ProcessedItems.php` | [Processed Items](endpoints/processed-items.md) |
| Providers | `/providers` | Public provider metadata endpoint. | `inc/Api/Providers.php` | [Providers](endpoints/providers.md) |
| Settings | `/settings`, `/settings/scheduling-intervals`, `/settings/tools/{tool_id}`, `/settings/handler-defaults`, `/settings/generate-ping-secret`, `/settings/handler-defaults/{handler_slug}` | `manage_settings` through `Settings::check_permission()`. | `inc/Api/Settings.php` | [Settings](endpoints/settings.md), [Scheduling Intervals](endpoints/intervals.md) |
| Step Types | `/step-types`, `/step-types/{step_type}` | Public step-type metadata endpoints. | `inc/Api/StepTypes.php` | [Step Types](endpoints/step-types.md) |
| System | `/system/status`, `/system/tasks`, `/system/tasks/{task_type}/run`, `/system/tasks/prompts`, `/system/tasks/prompts/{task_type}/{prompt_key}` | `manage_settings` through inline `PermissionHelper::can( 'manage_settings' )` callbacks. | `inc/Api/System/System.php` | [System](endpoints/system.md) |
| Tools | `/tools` | Public tool metadata endpoint. | `inc/Api/Tools.php` | [Tools](endpoints/tools.md) |
| Users | `/users/{id}`, `/users/me` | User preferences and current-user context. Cross-user access uses `manage_flows`; agent-level access uses `manage_agents`. | `inc/Api/Users.php` | [Users](endpoints/users.md) |
| Webhook Triggers | `/trigger/{flow_id}` | Public route with per-flow bearer or HMAC verification. The callback is `__return_true` because authorization is performed by `WebhookAuthResolver`/`WebhookVerifier`, then ability execution runs inside a bounded authenticated context. | `inc/Api/WebhookTrigger.php`, `inc/Api/WebhookAuthResolver.php`, `inc/Api/WebhookVerifier.php` | [Webhook Triggers](endpoints/webhook-triggers.md) |

## Endpoint Categories

### Workflow Execution

- [Execute](endpoints/execute.md): Trigger flows and ephemeral workflows.
- [Webhook Triggers](endpoints/webhook-triggers.md): Trigger a flow through bearer or HMAC webhook authentication.
- [Agent Ping](endpoints/agent-ping.md): Agent callback confirmation and polling endpoints.
- [Scheduling Intervals](endpoints/intervals.md): Available scheduling intervals and configuration.

### Pipeline & Flow Management

- [Pipelines](endpoints/pipelines.md)
- [Flows](endpoints/flows.md)
- [Jobs](endpoints/jobs.md)
- [Processed Items](endpoints/processed-items.md)

### Agents, Memory & Chat

- [Agents](endpoints/agents.md)
- [Agent Ping](endpoints/agent-ping.md)
- [Files](endpoints/files.md)
- [Chat](endpoints/chat.md)
- [Chat Sessions](endpoints/chat-sessions.md)

### Tools, Providers & Handlers

- [Handlers](endpoints/handlers.md)
- [Providers](endpoints/providers.md)
- [Tools](endpoints/tools.md)
- [Step Types](endpoints/step-types.md)

### Content, Email & Analytics

- [Analytics](endpoints/analytics.md)
- [Email](endpoints/email.md)
- [Internal Links](endpoints/internal-links.md)

### Configuration & Operations

- [Auth](endpoints/auth.md)
- [Authentication](endpoints/authentication.md)
- [Settings](endpoints/settings.md)
- [System](endpoints/system.md)
- [Users](endpoints/users.md)
- [Logs](endpoints/logs.md)
- [AI Directives](../core-system/ai-directives.md)

## Common Patterns

### Authentication

Data Machine supports three authentication shapes:

1. **Application Password** for external WordPress REST clients.
2. **Cookie Authentication** for WordPress admin sessions.
3. **Endpoint-specific Bearer/HMAC auth** for webhook-style callbacks that do not map cleanly to a logged-in WordPress user.

See [Authentication](endpoints/authentication.md).

### Permission Resolution

`PermissionHelper::can()` maps Data Machine actions to concrete WordPress capabilities:

| Action | WordPress capability |
|--------|----------------------|
| `manage_agents` | `datamachine_manage_agents` |
| `manage_flows` | `datamachine_manage_flows` |
| `manage_settings` | `datamachine_manage_settings` |
| `chat` | `datamachine_chat` |
| `use_tools` | `datamachine_use_tools` |
| `view_logs` | `datamachine_view_logs` |
| `create_own_agent` | `datamachine_create_own_agent` |

Administrators retain access through `manage_options`, but docs and integrations should refer to the scoped Data Machine actions above.

### Error Handling

All endpoints return standardized error responses following WordPress REST API conventions. Common error codes include:

- `rest_forbidden` (403) - Insufficient permissions.
- `rest_invalid_param` (400) - Invalid parameters.
- Resource-specific errors (404, 422, 500).

See [Error Handling Reference](endpoints/errors.md) for complete error code documentation.

### Pagination

Endpoints returning lists commonly support pagination parameters:

- `per_page` - Number of items per page.
- `offset` or `page` - Pagination offset.

## Implementation Guide

REST handlers should stay thin: validate request shape, call the service or ability that owns the behavior, and return a WordPress REST response.

```php
register_rest_route( 'datamachine/v1', '/pipelines', array(
    'methods'             => 'GET',
    'callback'            => array( Pipelines::class, 'get_pipelines' ),
    'permission_callback' => array( Pipelines::class, 'check_permission' ),
) );
```

For detailed implementation patterns, see the [Development](../development/) section for hooks and extension guides.

## Related Documentation

- [Authentication](endpoints/authentication.md)
- [Errors](endpoints/errors.md)
- [Engine Execution](../core-system/engine-execution.md)
- [Settings](endpoints/settings.md)
- [Development Guides](../development/)

---

**API Version**: v1
**Last Updated**: 2026-05-12
