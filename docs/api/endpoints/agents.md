# Agents

**Implementation**: `inc/Abilities/AgentAbilities.php`, `inc/Abilities/AgentTokenAbilities.php`, `inc/Core/Auth/AgentAuthorize.php`, `inc/Core/Auth/AgentAuthCallback.php`

## Overview

Agent record and token management is exposed as REST-visible Data Machine abilities and executed through WordPress core's ability runner (`POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run`). The former `datamachine/v1` `/agents*` wrapper routes were retired in #3456. Access grants are managed through the Agents API substrate abilities `agents/list-agent-users`, `agents/grant-agent-access`, and `agents/revoke-agent-access` (Automattic/agents-api#538), keyed by agent slug. The browser authorization flow (`/agent/authorize`, `/agent/auth/*`) remains on `datamachine/v1` because it is a browser-facing redirect/callback transport.

## Authentication

- All agent abilities use the current WordPress user and Data Machine agent capabilities via `PermissionHelper` (`manage_agents`, `create_own_agent`, or `chat` depending on the ability).
- Agent bearer tokens populate `PermissionHelper` agent context. Token capabilities can be restricted by a capability ceiling, so a token can be narrower than the owning agent's full Data Machine capability set.
- The `/agent/authorize` and `/agent/auth/callback` routes are browser-facing flow endpoints with open REST permissions, then validate login cookies, nonces, redirect URIs, or callback payloads inside the handler.

Token values are sensitive. `create-agent-token` returns `raw_token` once; `list-agent-tokens` returns token metadata only.

## Ability Reference

| Ability | Permission | Purpose |
|---|---|---|
| `datamachine/list-agents` | `chat` or `manage_agents` | List agents accessible to the caller. `scope=mine` (default, owned + granted) or `scope=all` (admin only). `user_id` for admin queries, `site_id` to filter by site scope, `include_role` to enrich rows with the resolved user's role. |
| `datamachine/get-agent` | `manage_agents` | Fetch one agent by `agent_slug` or `agent_id`, including access grants and directory info. `me: true` resolves the acting principal's own agent (agent-token context, else the user's default agent) and includes `site` metadata — replaces the retired `GET /agents/me`. |
| `datamachine/create-agent` | `manage_agents` or `create_own_agent` | Create an agent. `agent_slug` required; `agent_name` defaults to the slug; `owner_id` defaults to the acting user (admins may pass another user); `config` and `site_scope` optional. Non-admins can only create for themselves, subject to a per-user limit. |
| `datamachine/update-agent` | `manage_agents` | Update `agent_name` and/or `agent_config` on `agent` (slug or ID). |
| `datamachine/delete-agent` | `manage_agents` | Delete an agent and its access grants. `delete_files: true` also removes the filesystem directory. |
| `datamachine/create-agent-token` | `manage_agents` plus admin access to the agent | Create a bearer token. `label`, `capabilities` (null = all agent capabilities), `expires_in` (seconds) optional. |
| `datamachine/list-agent-tokens` | `manage_agents` plus operator access to the agent | List token metadata for an agent. |
| `datamachine/revoke-agent-token` | `manage_agents` plus admin access to the agent | Revoke a token by `token_id`. The token stops working immediately. |

Ability inputs are passed as `{ "input": { ... } }` in the run-request body. Errors surface as native REST errors with HTTP status codes (404 unknown agent, 400 validation, 403 access denied).

## Retained Browser Authorization Routes

| Method | Route | Auth model | Purpose |
|---|---|---|---|
| GET | `/agent/authorize` | Browser session | Show consent or redirect to login. |
| POST | `/agent/authorize` | Browser session plus nonce | Approve or deny authorization. |
| GET | `/agent/auth/callback` | Callback payload | Receive and store an external token. |
| GET | `/agent/auth/tokens` | `manage_options` | List stored external token metadata. |
| GET | `/agent/auth/tokens/{key}` | `manage_options` | Return one stored external token record. |

### Browser Authorization Parameters

| Parameter | Route | Type | Notes |
|---|---|---|---|
| `redirect_uri` | `/agent/authorize` | string | Required. Validated against the agent allowlist or localhost rules. |
| `action` | `/agent/authorize` | string | `authorize` or `deny`. |
| `_authorize_nonce` | `/agent/authorize` | string | WordPress nonce from the consent form. |
| `code_challenge` | `/agent/authorize` | string | Optional PKCE-style challenge. |
| `code_challenge_method` | `/agent/authorize` | string | Optional challenge method. |
| `state` | `/agent/authorize` | string | Optional opaque client state echoed through redirects. |
| `token`, `agent_slug`, `agent_id`, `error` | `/agent/auth/callback` | mixed | Callback result fields from the remote authorizing site. |
| `key` | `/agent/auth/tokens/{key}` | string | Storage key in the form `remote-site/agent-slug`. |

## Response Shapes

`get-agent` returns the full agent record:

```json
{
  "success": true,
  "agent": {
    "agent_id": 2,
    "agent_slug": "sarai",
    "agent_name": "Sarai",
    "owner_id": 1,
    "agent_config": {},
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-01-01 00:00:00",
    "agent_dir": "/path/to/agent",
    "has_files": true,
    "access": [],
    "principal_access": []
  }
}
```

With `me: true`, identity lookups additionally return site metadata:

```json
{
  "success": true,
  "agent": { "agent_id": 2, "agent_slug": "sarai" },
  "site": {
    "site_url": "https://example.com",
    "site_name": "Example"
  }
}
```

`create-agent-token` returns the token once:

```json
{
  "success": true,
  "token_id": 123,
  "raw_token": "datamachine_...",
  "token_prefix": "datamachine_abcd",
  "message": "Token created. Save it now - it cannot be retrieved again."
}
```

## Usage Examples

Create a token for an external agent client:

```bash
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/create-agent-token/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"input":{"agent_id":2,"label":"kimaki-prod","expires_in":2592000}}'
```

Use the returned bearer token to discover the active identity:

```bash
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/get-agent/run \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer datamachine_..." \
  -d '{"input":{"me":true}}'
```

Start the browser authorization flow for a local client:

```text
https://example.com/wp-json/datamachine/v1/agent/authorize?agent_slug=sarai&redirect_uri=http://localhost:31337/callback&label=local-cli&state=abc123
```
