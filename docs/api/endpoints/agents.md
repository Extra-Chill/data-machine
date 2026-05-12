# Agents Endpoints

**Implementation**: `inc/Api/Agents.php`

**Base URL**: `/wp-json/datamachine/v1`

Agent CRUD, access grants, bearer-token management, and self-identity lookup.

## Endpoints

| Method | Route | Purpose | Permission |
|--------|-------|---------|------------|
| `GET` | `/agents` | List agents visible to the caller. | Logged-in user; response is scoped by ownership/access grants. |
| `POST` | `/agents` | Create an agent. | `manage_agents` or `create_own_agent`. |
| `GET` | `/agents/me` | Return the current agent/user identity. | Agent bearer-token context or logged-in user. |
| `GET` | `/agents/{agent}` | Read an agent by slug. | `manage_agents`. |
| `GET` | `/agents/{agent_id}` | Read an agent by ID. | `manage_agents`. |
| `PUT/PATCH` | `/agents/{agent}` | Update an agent by slug. | `manage_agents`. |
| `PUT/PATCH` | `/agents/{agent_id}` | Update an agent by ID. | `manage_agents`. |
| `DELETE` | `/agents/{agent}` | Delete an agent by slug. | `manage_agents`. |
| `DELETE` | `/agents/{agent_id}` | Delete an agent by ID. | `manage_agents`. |
| `GET` | `/agents/{agent}/access` | List access grants. | `manage_agents`. |
| `GET` | `/agents/{agent_id}/access` | List access grants. | `manage_agents`. |
| `POST` | `/agents/{agent}/access` | Grant user access. | `manage_agents`. |
| `POST` | `/agents/{agent_id}/access` | Grant user access. | `manage_agents`. |
| `DELETE` | `/agents/{agent}/access/{user_id}` | Revoke user access. | `manage_agents`. |
| `DELETE` | `/agents/{agent_id}/access/{user_id}` | Revoke user access. | `manage_agents`. |
| `GET` | `/agents/{agent}/tokens` | List agent tokens. | `manage_agents`. |
| `GET` | `/agents/{agent_id}/tokens` | List agent tokens. | `manage_agents`. |
| `POST` | `/agents/{agent}/tokens` | Create an agent bearer token. | `manage_agents`. |
| `POST` | `/agents/{agent_id}/tokens` | Create an agent bearer token. | `manage_agents`. |
| `DELETE` | `/agents/{agent}/tokens/{token_id}` | Revoke an agent token. | `manage_agents`. |
| `DELETE` | `/agents/{agent_id}/tokens/{token_id}` | Revoke an agent token. | `manage_agents`. |

## Notes

- Slug routes are preferred for portable integrations; numeric ID routes remain available for compatibility.
- Bearer-token requests populate `PermissionHelper` agent context. Token capabilities can be restricted by a capability ceiling.
- `/agents/me` is the discovery endpoint for external clients that need to validate a token and learn site/agent metadata.
