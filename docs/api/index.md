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
| Agents | Retired in #3456 — agent CRUD, access grants, and tokens are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run`; `/agents/me` is `datamachine/get-agent` with `me: true` (see [Agents](endpoints/agents.md)). The browser authorization routes (`/agent/authorize`, `/agent/auth/*`) remain on `datamachine/v1`. | Each ability's permission callback (`manage_agents`, `create_own_agent`, or `chat` through `PermissionHelper`, plus per-agent access checks on tokens and access management). | `inc/Abilities/AgentAbilities.php`, `inc/Abilities/AgentAccessAbilities.php`, `inc/Abilities/AgentTokenAbilities.php` | [Agents](endpoints/agents.md) |
| Agent Ping | `/agent-ping/confirm`, `/agent-ping/callback/{callback_id}` | Bearer-token callback auth using the configured agent-ping callback token. No WordPress capability check. | `inc/Api/AgentPing.php` | [Agent Ping](endpoints/agent-ping.md) |
| Analytics | Extension-provided analytics routes via `datamachine_analytics_ability_map` | `manage_flows` via `PermissionHelper::can( 'manage_flows' )`. | `inc/Api/Analytics.php` | [Analytics](endpoints/analytics.md) |
| Auth | `/auth/providers`, `/auth/{handler_slug}`, `/auth/{handler_slug}/status`, `/auth/{handler_slug}/token`, `/auth/{handler_slug}/refresh` | `manage_settings` through `Auth::check_permission()`. | `inc/Api/Auth.php` | [Auth](endpoints/auth.md) |
| Chat | `/chat`, `/chat/continue`, `/chat/{session_id}`, `/chat/sessions`, `/chat/sessions/{session_id}/read`; `/chat/ping` | Chat routes require `chat`. `/chat/ping` uses the chat ping token verifier. | `inc/Api/Chat/Chat.php` | [Chat](endpoints/chat.md), [Chat Sessions](endpoints/chat-sessions.md) |
| Email | Retired in #3456 — email send/fetch/manage operations are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Email](endpoints/email.md)). | Each ability's permission callback (email family: `PermissionHelper::can( 'use_tools' )` or `PermissionHelper::can_manage()`, plus agent-token ability scopes). | `inc/Abilities/Email/`, `inc/Abilities/Fetch/FetchEmailAbility.php`, `inc/Abilities/Publish/SendEmailAbility.php` | [Email](endpoints/email.md) |
| Execute | `/execute` | `manage_flows` through the execute controller. | `inc/Api/Execute.php` | [Execute](endpoints/execute.md) |
| Files | `/files` (multipart upload) | Multipart upload is a retained transport route; it requires a logged-in user plus `PermissionHelper::can_manage()`. Flow-file and agent-file listing/reads/writes are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Files](endpoints/files.md)). | `inc/Api/FlowFiles.php`, `inc/Abilities/File/AgentFileAbilities.php`, `inc/Abilities/DailyMemoryAbilities.php` | [Files](endpoints/files.md) |
| Flows | Retired in #3456 — flow CRUD, steps, queues, and memory files are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Flows](endpoints/flows.md)). | Each ability's permission callback (flow family: `PermissionHelper::can_manage()`). | `inc/Abilities/Flow/`, `inc/Abilities/FlowStep/` | [Flows](endpoints/flows.md) |
| Handlers | Retired in #3456 — handler discovery and detail are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Handlers](endpoints/handlers.md)). | Each ability's permission callback (`PermissionHelper::can_manage()`); the public wrapper routes are gone. | `inc/Abilities/HandlerAbilities.php`, `inc/Abilities/Handler/HandlerDetailAbility.php` | [Handlers](endpoints/handlers.md) |
| Internal Links | Retired in #3456 — link audit, orphans, backlinks, broken-link checks, and diagnostics are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Internal Links](endpoints/internal-links.md)). | Each ability's permission callback (internal-links family: `PermissionHelper::can_manage()`). | `inc/Abilities/InternalLinkingAbilities.php` | [Internal Links](endpoints/internal-links.md) |
| Jobs | Retired in #3456 — job listing, lookup, and deletion are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Jobs](endpoints/jobs.md)). | Each ability's permission callback (`PermissionHelper::can_manage()`) plus row-level ownership checks inside the abilities. | `inc/Abilities/Job/GetJobsAbility.php`, `inc/Abilities/Job/DeleteJobsAbility.php` | [Jobs](endpoints/jobs.md) |
| Logs | Retired in #3456 — log read/metadata/clear are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Logs](endpoints/logs.md)). | Each ability's permission callback allows `view_logs`, matching the retired wrapper routes. | `inc/Abilities/LogAbilities.php` | [Logs](endpoints/logs.md) |
| Pipelines | Retired in #3456 — pipeline CRUD, steps, and memory files are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Pipelines](endpoints/pipelines.md)). | Each ability's permission callback (pipeline family: `PermissionHelper::can_manage()`). | `inc/Abilities/Pipeline/`, `inc/Abilities/PipelineStepAbilities.php` | [Pipelines](endpoints/pipelines.md) |
| Processed Items | Retired in #3456 — processed-items clearing is the REST-visible `datamachine/clear-processed-items` ability (see [Processed Items](endpoints/processed-items.md)). | Ability permission callback (`PermissionHelper::can_manage()`). | `inc/Abilities/ProcessedItemsAbilities.php` | [Processed Items](endpoints/processed-items.md) |
| Providers | `/providers` | Public provider metadata endpoint. | `inc/Api/Providers.php` | [Providers](endpoints/providers.md) |
| Settings | Retired in #3456 — settings, scheduling intervals, tool config, handler defaults, and ping-secret generation are REST-visible abilities run through `/wp-abilities/v1/abilities/datamachine/<slug>/run` (see [Settings](endpoints/settings.md)). | Each ability's permission callback (`PermissionHelper::can_manage()`). | `inc/Abilities/SettingsAbilities.php` | [Settings](endpoints/settings.md), [Scheduling Intervals](endpoints/intervals.md) |
| Step Types | Retired in #3456 — step-type discovery is the REST-visible `datamachine/get-step-types` ability (see [Step Types](endpoints/step-types.md)). | Ability permission callback (`PermissionHelper::can_manage()`); the public wrapper routes are gone. | `inc/Abilities/StepTypeAbilities.php` | [Step Types](endpoints/step-types.md) |
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
