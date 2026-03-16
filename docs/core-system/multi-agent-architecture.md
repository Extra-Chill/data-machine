# Multi-Agent Architecture

Data Machine supports multiple independent agents on a single WordPress installation. Each agent has its own identity, filesystem directory, database-scoped resources, and access control. This is the foundation for multi-tenant AI operations — different agents can manage different sites, workflows, or personas without interfering with each other.

## Overview

The multi-agent system consists of five layers:

1. **Agents table** — first-class identity records in the database
2. **Agent access control** — role-based grants (admin/operator/viewer) per agent
3. **Filesystem scoping** — isolated directory trees per agent
4. **Database scoping** — agent_id on pipelines, flows, jobs, and chat sessions
5. **Resolution** — CLI and API helpers that resolve the active agent from context

## Agents Table

**Table:** `{prefix}_datamachine_agents`
**Source:** `inc/Core/Database/Agents/Agents.php`
**Since:** v0.36.1

| Column | Type | Description |
|--------|------|-------------|
| `agent_id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `agent_slug` | VARCHAR(200) UNIQUE | URL-safe identifier (e.g. `chubes-bot`) |
| `agent_name` | VARCHAR(200) | Human-readable display name |
| `owner_id` | BIGINT UNSIGNED | WordPress user ID of the agent's owner |
| `agent_config` | LONGTEXT | JSON configuration object |
| `status` | VARCHAR(20) | `active`, `inactive`, or `archived` (default: `active`) |
| `created_at` | DATETIME | Creation timestamp |
| `updated_at` | DATETIME | Last modification (auto-updated) |

**Indexes:** Primary on `agent_id`, unique on `agent_slug`, key on `owner_id`, key on `status`.

### Repository Methods

The `Agents` repository (`DataMachine\Core\Database\Agents\Agents`) extends `BaseRepository`:

| Method | Description |
|--------|-------------|
| `get_agent(int $agent_id)` | Get agent by ID. Decodes `agent_config` JSON. |
| `get_by_slug(string $agent_slug)` | Lookup by slug. Returns null if not found. |
| `get_by_owner_id(int $owner_id)` | Get the first agent owned by a user. |
| `get_all()` | List all agents ordered by `agent_id` ascending. |
| `create_if_missing(string $slug, string $name, int $owner_id, array $config)` | Idempotent create — returns existing agent_id if slug exists. |
| `update_slug(int $agent_id, string $new_slug)` | Pure DB slug update (no filesystem side effects). |
| `update_agent(int $agent_id, array $data)` | Update mutable fields: `agent_name`, `agent_config`, `status`. |

## Agent Access Control

**Table:** `{prefix}_datamachine_agent_access`
**Source:** `inc/Core/Database/Agents/AgentAccess.php`
**Since:** v0.41.0

A many-to-many join table granting WordPress users access to specific agents with role-based permissions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `agent_id` | BIGINT UNSIGNED | References `datamachine_agents.agent_id` |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `role` | VARCHAR(20) | Access level (default: `viewer`) |
| `granted_at` | DATETIME | When access was granted |

**Unique constraint:** `(agent_id, user_id)` — one grant per user per agent.

### Role Hierarchy

Three roles with ascending privilege:

| Role | Level | Capabilities |
|------|-------|-------------|
| `viewer` | 0 | Read-only access to pipelines, flows, jobs |
| `operator` | 1 | Run flows, view jobs, manage queue |
| `admin` | 2 | Full control — create/edit/delete pipelines, flows, agent config |

Role checks use a hierarchy: a user with `admin` access passes checks requiring `operator` or `viewer`. The owner of an agent is automatically bootstrapped with `admin` access on creation.

### Repository Methods

The `AgentAccess` repository provides:

| Method | Description |
|--------|-------------|
| `grant_access(int $agent_id, int $user_id, string $role)` | Grant or update access. Validates role against `VALID_ROLES`. |
| `revoke_access(int $agent_id, int $user_id)` | Remove access grant. |
| `get_access(int $agent_id, int $user_id)` | Get a specific user's access row. |
| `get_agent_ids_for_user(int $user_id, ?string $minimum_role)` | All agent IDs a user can access, optionally filtered by minimum role. |
| `get_users_for_agent(int $agent_id)` | All users with access to an agent. |
| `user_can_access(int $agent_id, int $user_id, string $minimum_role)` | Boolean check — does this user meet the minimum role? |
| `bootstrap_owner_access(int $agent_id, int $owner_id)` | Grant `admin` access to the owner on agent creation. |

## Filesystem Scoping

**Source:** `inc/Core/FilesRepository/DirectoryManager.php`

Agent files live under `wp-content/uploads/datamachine-files/` in a three-layer directory architecture:

```
wp-content/uploads/datamachine-files/
  shared/                         # Shared across all agents
    SITE.md
  agents/
    {agent_slug}/                 # Per-agent directory
      SOUL.md                     # Agent identity
      USER.md                     # User profile
      MEMORY.md                   # Accumulated knowledge
      daily/
        YYYY/MM/DD.md             # Daily memory files
  users/
    {user_id}/                    # Legacy per-user directory
      (same structure)
```

### DirectoryManager

The `DirectoryManager` class handles path resolution and directory creation:

- **`resolve_agent_directory(array $context)`** — Resolves the correct agent directory from context (agent_id, user_id, or agent_slug). Agents table entries take precedence over user-based resolution.
- **`get_agent_identity_directory(string $slug)`** — Returns the filesystem path for a specific agent slug: `agents/{slug}/`.
- **`get_effective_user_id(int $user_id)`** — Resolves the effective user ID, defaulting to `0` for single-agent mode.
- **`ensure_directory_exists(string $dir)`** — Creates directory with appropriate permissions if it doesn't exist.

### Resolution Priority

When resolving an agent directory:

1. If `agent_id` is provided and non-zero, look up the agent slug from the database and use `agents/{slug}/`
2. If `user_id` is provided and non-zero, check if the user owns an agent and resolve to `agents/{slug}/`; otherwise fall back to `users/{user_id}/`
3. If neither is provided, use the legacy shared directory (`agent/`)

This ensures backward compatibility with single-agent installations (`user_id=0`) while supporting full multi-agent scoping.

## Database Scoping

Resources in Data Machine carry `agent_id` (or `user_id`) to scope them to a specific agent:

| Table | Scope Column | Description |
|-------|-------------|-------------|
| `datamachine_pipelines` | `user_id` | Pipeline ownership |
| `datamachine_flows` | `user_id` | Flow ownership |
| `datamachine_jobs` | `user_id` | Job execution ownership |
| `datamachine_chat_sessions` | `user_id` | Chat session ownership |

**Note:** The scope column is `user_id` (not `agent_id`) for historical reasons. A `user_id` of `0` indicates single-agent mode — a valid operational state where all resources are shared.

In multi-agent mode, queries filter by the resolved `user_id` to ensure agents only see their own resources. The `PermissionHelper` class provides resolution methods:

```php
// Resolve the scoped user_id for queries
$user_id = PermissionHelper::resolve_scoped_user_id();

// Resolve the scoped agent_id
$agent_id = PermissionHelper::resolve_scoped_agent_id();

// Check if a user owns a resource
PermissionHelper::owns_resource($resource_user_id);

// Check if a user can access a specific agent
PermissionHelper::can_access_agent($agent_id);
```

## Abilities

**Source:** `inc/Abilities/AgentAbilities.php`
**Since:** v0.38.0

Six abilities registered under the `datamachine` category:

| Ability | Description |
|---------|-------------|
| `datamachine/rename-agent` | Rename agent slug — updates database and moves filesystem directory. Includes rollback on DB failure. |
| `datamachine/list-agents` | List all registered agent identities with ID, slug, name, owner, and status. |
| `datamachine/create-agent` | Create a new agent with filesystem directory, database record, and owner access grant. |
| `datamachine/get-agent` | Retrieve a single agent by slug or ID with access grants and directory info. |
| `datamachine/update-agent` | Update mutable fields: `agent_name`, `agent_config`, `status`. |
| `datamachine/delete-agent` | Delete agent record and access grants. Optionally removes the filesystem directory. |

### Rename Operation

Renaming is the most complex operation because it touches both the database and filesystem:

1. Validate the old slug exists and the new slug is free
2. Move the filesystem directory first (easier to roll back than a DB change)
3. Update the database slug
4. If the DB update fails, roll back the directory move

```php
// Via Abilities API
wp_execute_ability('datamachine/rename-agent', [
    'old_slug' => 'my-old-agent',
    'new_slug' => 'my-new-agent',
]);
```

### Create Operation

Creating an agent:

1. Sanitizes the slug and validates the owner exists
2. Inserts the database record via `create_if_missing()`
3. Bootstraps `admin` access for the owner
4. Creates the filesystem directory with starter templates (SOUL.md, USER.md, MEMORY.md)

## REST API

**Source:** `inc/Api/Agents.php`
**Since:** v0.41.0 (full CRUD + access management in v0.43.0)

### Agent CRUD

| Method | Endpoint | Description | Permission |
|--------|----------|-------------|------------|
| `GET` | `/datamachine/v1/agents` | List agents (scoped by access grants for non-admins) | Logged-in user |
| `POST` | `/datamachine/v1/agents` | Create new agent | `manage_agents` |
| `GET` | `/datamachine/v1/agents/{agent_id}` | Get single agent with details | `manage_agents` |
| `PUT/PATCH` | `/datamachine/v1/agents/{agent_id}` | Update agent fields | `manage_agents` |
| `DELETE` | `/datamachine/v1/agents/{agent_id}` | Delete agent (optional `delete_files`) | `manage_agents` |

### Access Management

| Method | Endpoint | Description | Permission |
|--------|----------|-------------|------------|
| `GET` | `/datamachine/v1/agents/{agent_id}/access` | List access grants (enriched with user display names) | `manage_agents` |
| `POST` | `/datamachine/v1/agents/{agent_id}/access` | Grant access (`user_id` + `role`) | `manage_agents` |
| `DELETE` | `/datamachine/v1/agents/{agent_id}/access/{user_id}` | Revoke access (blocked for owner) | `manage_agents` |

**List scoping:** The `GET /agents` endpoint is accessible to any logged-in user. Admins see all agents. Non-admin users only see agents they have explicit access grants for — the endpoint queries `AgentAccess::get_agent_ids_for_user()` to filter results.

**Owner protection:** The `DELETE /agents/{agent_id}/access/{user_id}` endpoint prevents revoking the owner's access. Ownership must be transferred before the owner's grant can be removed.

## CLI

**Source:** `inc/Cli/Commands/AgentsCommand.php`

### Commands

```bash
# List all agents
wp datamachine agents list [--format=table|json|csv]

# Create a new agent
wp datamachine agents create <slug> --owner=<user_id> [--name=<display_name>]

# Delete an agent
wp datamachine agents delete <slug> [--delete-files]

# Rename an agent
wp datamachine agents rename <old_slug> <new_slug>

# Manage access grants
wp datamachine agents access <agent_slug> list
wp datamachine agents access <agent_slug> grant <user_id> [--role=admin|operator|viewer]
wp datamachine agents access <agent_slug> revoke <user_id>
```

### AgentResolver

**Source:** `inc/Cli/AgentResolver.php`

The `AgentResolver` is a CLI helper that resolves the `--agent` flag into an `agent_id` for commands that need agent scoping:

```php
use DataMachine\Cli\AgentResolver;

// In a CLI command
$agent_id = AgentResolver::resolve($assoc_args);
```

Resolution logic:

1. If `--agent` is provided as a slug, look up the agent by slug
2. If `--agent` is provided as a numeric ID, use it directly
3. If no `--agent` flag, fall back to the default agent (resolved from `--user` or the current context)

This is used across many CLI commands (`memory`, `workspace`, `flows`, etc.) to scope operations to a specific agent.

## Architecture Diagram

```
                    REST API                CLI                  AI Chat
                       |                    |                      |
                       v                    v                      v
               inc/Api/Agents.php   AgentsCommand.php      (tools via Abilities)
                       |                    |                      |
                       +--------+-----------+----------------------+
                                |
                                v
                       AgentAbilities.php
                    (WordPress 6.9 Abilities)
                                |
                    +-----------+-----------+
                    |                       |
                    v                       v
            Agents Repository       DirectoryManager
          (Database CRUD)         (Filesystem CRUD)
                    |                       |
                    v                       v
        datamachine_agents        agents/{slug}/
        datamachine_agent_access    SOUL.md, USER.md, MEMORY.md
```

## Source Files

| File | Purpose |
|------|---------|
| `inc/Core/Database/Agents/Agents.php` | Agents table repository (CRUD) |
| `inc/Core/Database/Agents/AgentAccess.php` | Agent access grants repository |
| `inc/Api/Agents.php` | REST API controller for agents and access management |
| `inc/Abilities/AgentAbilities.php` | WordPress 6.9 Abilities registration (6 abilities) |
| `inc/Abilities/PermissionHelper.php` | Permission checks with agent/user scoping |
| `inc/Cli/Commands/AgentsCommand.php` | WP-CLI commands for agent management |
| `inc/Cli/AgentResolver.php` | CLI helper for `--agent` flag resolution |
| `inc/Core/FilesRepository/DirectoryManager.php` | Filesystem directory resolution and creation |
