# Pending Actions

Data Machine implements the Agents API pending-action approval storage contract with WordPress-backed durable storage.

Pending actions are approval and audit records. Normal runtime requires the durable `datamachine_pending_actions` table; if database access is unavailable, store/resolution operations fail closed and emit `datamachine_pending_action_store_unavailable`. The transient store path is reserved for pure-PHP smoke tests or explicit pre-table boot by defining `DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK`.

Resolution atomically claims a row as `pending -> applying -> accepted|rejected|failed`. Accepted handlers receive an `authorization_receipt` in resolver context (or as the third argument for callable compatibility handlers). Mutators can call `PendingActionAuthorizationReceipt::validate( $receipt, $kind, $operation, $target, $input, $subject, $workspace )`; validation binds the receipt to the pending action, kind, operation, target, input, subject, workspace, resolver, expiry, and one-time claim nonce.

## Boundary

- Agents API owns generic approval vocabulary, contracts, and the canonical pending-action abilities.
- Data Machine owns the concrete WordPress table, CLI, REST routes, abilities, and legacy resolver/tool compatibility.
- Domain plugins register action-specific handlers through `datamachine_pending_action_handlers`.

## Available Agents API Contracts

Data Machine directly adapts to these merged contracts from `automattic/agents-api`:

- `AgentsAPI\AI\Approvals\PendingAction_Store`
- `AgentsAPI\AI\Approvals\PendingAction_Resolver`
- `AgentsAPI\AI\Approvals\PendingAction_Handler`
- `AgentsAPI\AI\Approvals\WP_Agent_Pending_Action_Observer`
- `AgentsAPI\AI\Approvals\WP_Agent_Approval_Decision`
- `AgentsAPI\AI\Approvals\PendingAction`
- `AgentsAPI\AI\Tools\ActionPolicy`

The compatibility seam remains the existing `datamachine_pending_action_handlers` map. Handler objects that implement `AgentsAPI\AI\Approvals\PendingAction_Handler` can be placed under the same `apply` key and Data Machine will dispatch to the Agents API handler method. Legacy callable handlers continue to receive the stored `apply_input` and full Data Machine payload.

## Lifecycle Observers

`PendingActionStore` dispatches registered `WP_Agent_Pending_Action_Observer` instances after durable lifecycle transitions complete. Data Machine registers a default WordPress adapter with these hooks:

- `datamachine_pending_action_stored( WP_Agent_Pending_Action $action )`
- `datamachine_pending_action_resolved( WP_Agent_Pending_Action $action, WP_Agent_Approval_Decision $decision, string $resolver )`
- `datamachine_pending_action_expired( WP_Agent_Pending_Action $action )`

The resolved hook receives the same canonical value-object signature as `WP_Agent_Pending_Action_Observer::on_resolved()`.

## Surfaces

- Ability: `agents/list-pending-actions`
- Ability: `agents/get-pending-action`
- Ability: `agents/summary-pending-actions`
- Ability: `agents/resolve-pending-action`
- Ability: `datamachine/sign-pending-action-resolution`
- Compatibility alias: `datamachine/resolve-pending-action`
- Chat tool: `resolve_pending_action`
- REST: `GET /datamachine/v1/actions`
- REST: `GET /datamachine/v1/actions/{action_id}`
- REST: `GET /datamachine/v1/actions/summary`
- REST: `GET /datamachine/v1/actions/resolve-by-token?t={token}`
- REST resolver: `POST /datamachine/v1/actions/resolve`
- CLI: `wp datamachine pending-actions list|get|summary`

`PendingActionStore::get()` remains the live-pending lookup used by legacy callers. Resolved rows are retained for audit and are available through inspect/list/get surfaces.

## Signed Resolution URLs

`datamachine/sign-pending-action-resolution` creates short-lived approve and reject URLs for a pending action. The URLs are stateless: the `t` query parameter contains a JSON payload signed with an HMAC secret stored in the `datamachine_pending_action_resolution_secret` option.

Input:

```php
array(
	'action_id' => 'act_...',
	'lifetime'  => 604800, // Optional, seconds; capped at 30 days.
	'resolver'  => 'email_approval', // Optional audit identifier.
)
```

Output:

```php
array(
	'success'     => true,
	'action_id'   => 'act_...',
	'approve_url' => 'https://example.test/wp-json/datamachine/v1/actions/resolve-by-token?t=...',
	'reject_url'  => 'https://example.test/wp-json/datamachine/v1/actions/resolve-by-token?t=...',
	'expires_at'  => '2026-05-17T12:00:00+00:00',
)
```

The public token route validates the signature and expiry before delegating to the canonical `agents/resolve-pending-action` path. Already-resolved accepted/rejected rows return their existing decision instead of being overwritten. Expired, deleted, missing, or otherwise non-resolvable rows return `410`.

`datamachine/list-pending-actions`, `datamachine/get-pending-action`, `datamachine/summarize-pending-actions`, `datamachine/resolve-pending-action`, and `POST /datamachine/v1/actions/resolve` remain compatibility surfaces for existing Data Machine clients. New clients should call the canonical `agents/*pending-action*` abilities. Data Machine supplies the host store/resolver through `wp_agent_pending_action_store` and `wp_agent_pending_action_resolver`; Agents API does not own Data Machine's storage table.

Use `SignPendingActionResolutionAbility::rotate_secret()` to invalidate existing signed URLs during a security incident.
