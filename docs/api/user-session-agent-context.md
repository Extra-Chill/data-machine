# Human REST agent context

Cookie (with REST nonce), OAuth, and application-password callers keep their authenticated WordPress user and capabilities. Authentication must succeed before agent context is resolved.

Send `X-Datamachine-Agent: <slug-or-ID>` to select an agent. Otherwise, the per-site `default_agent` string in `datamachine_settings` is used. Configure it with `datamachine/update-settings`, for example `{"default_agent":"my-agent"}`. An empty setting disables the default. This is separate from `system_agent_slug`, which attributes system tasks rather than human requests.

Resolution requires `PermissionHelper::can_access_agent($agent_id, 'operator')`: existing owner/admin access and grant rules still apply. Viewer grants do not permit this execution scope. An explicit empty, unknown, or inaccessible selector leaves the request without a principal; it never falls back to the default. A selector grants no capabilities.

The resulting Agents API user-session principal scopes `datamachine/write-self-memory` to the human plus agent for `USER_MEMORY.md`. Supplying another `user_id` cannot select another person's private memory. Shared `MEMORY.md` retains the existing durable-fact, approval, bundle-ownership, and cross-agent delegation policy. Without context, self-memory writes still return `missing_agent_context`.

Agent bearer-token authentication and its owner identity, scopes, and capability ceilings are unchanged. The selector does not override a token principal. Human scope is removed after REST callbacks.

Regression tests:

```sh
php tests/user-session-agent-context-smoke.php
php tests/principal-memory-ability-scope-smoke.php
php tests/memory-bundle-policy-smoke.php
```
