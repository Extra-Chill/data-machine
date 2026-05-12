# Agents Endpoints

**Implementation**: `inc/Api/Agents.php`, `inc/Core/Auth/AgentAuthorize.php`, `inc/Core/Auth/AgentAuthCallback.php`

**Base URL**: `/wp-json/datamachine/v1`

## Overview

Agent endpoints manage agent records, user access grants, runtime bearer tokens, and the browser authorization flow used by external agent clients.

## Authentication

There are three auth modes:

- Agent CRUD, access, and token management use the current WordPress user and Data Machine agent capabilities.
- `GET /agents/me` accepts either an agent bearer token context or a logged-in WordPress user.
- `/agent/authorize` and `/agent/auth/callback` are browser-facing flow endpoints with open REST permissions, then validate login cookies, nonces, redirect URIs, or callback payloads inside the handler.

Token values are sensitive. `POST /agents/{agent}/tokens` returns `raw_token` once; list endpoints return token metadata only.

Agent bearer-token requests populate `PermissionHelper` agent context. Token capabilities can be restricted by a capability ceiling, so a token can be narrower than the owning agent's full Data Machine capability set.

## Route Table

| Method | Route | Auth model | Purpose |
|---|---|---|---|
| GET | `/agents` | Logged-in user | List agents visible to the caller. |
| POST | `/agents` | `manage_agents` or `create_own_agent` | Create an agent. |
| GET | `/agents/me` | Agent token or logged-in user | Discover the current agent identity. |
| GET | `/agents/{agent}` | `manage_agents` | Fetch an agent by slug or numeric ID. |
| PUT/PATCH | `/agents/{agent}` | `manage_agents` | Update agent display name or config. |
| DELETE | `/agents/{agent}` | `manage_agents` | Delete an agent. |
| GET | `/agents/{agent}/access` | `manage_agents` | List user access grants. |
| POST | `/agents/{agent}/access` | `manage_agents` | Grant user access. |
| DELETE | `/agents/{agent}/access/{user_id}` | `manage_agents` | Revoke user access. |
| GET | `/agents/{agent}/tokens` | `manage_agents` plus agent access check | List token metadata. |
| POST | `/agents/{agent}/tokens` | `manage_agents` plus admin access to agent | Create a bearer token. |
| DELETE | `/agents/{agent}/tokens/{token_id}` | `manage_agents` plus admin access to agent | Revoke a token. |
| GET | `/agent/authorize` | Browser session | Show consent or redirect to login. |
| POST | `/agent/authorize` | Browser session plus nonce | Approve or deny authorization. |
| GET | `/agent/auth/callback` | Callback payload | Receive and store an external token. |
| GET | `/agent/auth/tokens` | `manage_options` | List stored external token metadata. |
| GET | `/agent/auth/tokens/{key}` | `manage_options` | Return one stored external token record. |

`{agent}` accepts an agent slug (`sarai`) or numeric agent ID (`42`). Slug routes are preferred.

## Core Parameters

### Agent CRUD

| Parameter | Routes | Type | Notes |
|---|---|---|---|
| `agent_slug` | `POST /agents`, authorize flow | string | Required when creating or authorizing. Sanitized as a slug. |
| `agent_name` | `POST /agents`, `PUT/PATCH /agents/{agent}` | string | Display name. Defaults to slug on create. |
| `config` | `POST /agents` | object | Initial config object. |
| `agent_config` | `PUT/PATCH /agents/{agent}` | object | Replaces existing config. |
| `delete_files` | `DELETE /agents/{agent}` | boolean | Also remove the agent filesystem directory. Default `false`. |
| `scope` | `GET /agents` | string | `mine` or `all`; `all` requires admin privileges. |
| `user_id` | `GET /agents`, access routes | integer | Admin-only filter for list, required for grants/revokes. |
| `include_role` | `GET /agents` | boolean | Include caller role data. Enabled by default for REST UI payloads. |

### Access And Tokens

| Parameter | Routes | Type | Notes |
|---|---|---|---|
| `role` | `POST /agents/{agent}/access` | string | `admin`, `operator`, or `viewer`. Default `viewer`. |
| `label` | `POST /agents/{agent}/tokens`, authorize flow | string | Human-readable token label such as `kimaki-prod`. |
| `capabilities` | `POST /agents/{agent}/tokens` | array | Optional allowed capability subset. Omit/null for all agent capabilities. |
| `expires_in` | `POST /agents/{agent}/tokens` | integer | Expiry in seconds from now. Omit/null for no expiry. |
| `token_id` | `DELETE /agents/{agent}/tokens/{token_id}` | integer | Token metadata ID to revoke. |

### Browser Authorization

| Parameter | Routes | Type | Notes |
|---|---|---|---|
| `redirect_uri` | `/agent/authorize` | string | Required. Validated against the agent allowlist or localhost rules. |
| `action` | `POST /agent/authorize` | string | `authorize` or `deny`. |
| `_authorize_nonce` | `POST /agent/authorize` | string | WordPress nonce from the consent form. |
| `code_challenge` | `/agent/authorize` | string | Optional PKCE-style challenge. |
| `code_challenge_method` | `/agent/authorize` | string | Optional challenge method. |
| `state` | `/agent/authorize` | string | Optional opaque client state echoed through redirects. |
| `token`, `agent_slug`, `agent_id`, `error` | `/agent/auth/callback` | mixed | Callback result fields from the remote authorizing site. |
| `key` | `/agent/auth/tokens/{key}` | string | Storage key in the form `remote-site/agent-slug`. |

## Response Shape

Most agent management responses use:

```json
{
  "success": true,
  "data": {}
}
```

List responses return `data` as an array of agent or grant objects. Token ability responses return ability-native objects such as:

```json
{
  "success": true,
  "token_id": 123,
  "raw_token": "datamachine_...",
  "token_prefix": "datamachine_abcd",
  "message": "Token created. Save it now - it cannot be retrieved again."
}
```

`GET /agents/me` returns identity metadata:

```json
{
  "success": true,
  "data": {
    "agent_id": 2,
    "agent_slug": "sarai",
    "agent_name": "Sarai",
    "owner_id": 1,
    "site_url": "https://example.com",
    "site_name": "Example"
  }
}
```

## Agent Usage Examples

Create a token for an external agent client:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/agents/sarai/tokens \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"label":"kimaki-prod","expires_in":2592000}'
```

Use the returned bearer token to discover the active identity:

```bash
curl https://example.com/wp-json/datamachine/v1/agents/me \
  -H "Authorization: Bearer datamachine_..."
```

Start the browser authorization flow for a local client:

```text
https://example.com/wp-json/datamachine/v1/agent/authorize?agent_slug=sarai&redirect_uri=http://localhost:31337/callback&label=local-cli&state=abc123
```
