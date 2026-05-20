# Pending Actions

Data Machine implements the Agents API pending-action approval storage contract with WordPress-backed durable storage.

## Boundary

- Agents API owns generic approval vocabulary and contracts.
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
- `datamachine_pending_action_resolved( string $decision, string $action_id, string $kind, array $payload, mixed $result )`
- `datamachine_pending_action_expired( WP_Agent_Pending_Action $action )`

The resolved hook keeps its legacy signature for compatibility. Object-oriented observers receive the upstream value-object signature from `WP_Agent_Pending_Action_Observer`.

## Surfaces

- Ability: `datamachine/list-pending-actions`
- Ability: `datamachine/get-pending-action`
- Ability: `datamachine/summarize-pending-actions`
- Ability: `datamachine/sign-pending-action-resolution`
- Resolver ability: `datamachine/resolve-pending-action`
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

The public token route validates the signature and expiry before delegating to the same resolver path as `datamachine/resolve-pending-action`. Already-resolved accepted/rejected rows return their existing decision instead of being overwritten. Expired, deleted, missing, or otherwise non-resolvable rows return `410`.

Use `SignPendingActionResolutionAbility::rotate_secret()` to invalidate existing signed URLs during a security incident.
