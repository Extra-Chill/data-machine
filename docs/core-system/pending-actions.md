# Pending Actions

Data Machine implements the Agents API pending-action approval storage contract with WordPress-backed durable storage.

## Boundary

- Agents API owns generic approval vocabulary and contracts.
- Data Machine owns the concrete WordPress table, CLI, REST routes, abilities, and legacy resolver/tool compatibility.
- Domain plugins register action-specific handlers through `datamachine_pending_action_handlers`.

## Available Agents API Contracts

Data Machine directly adapts to these merged contracts from `automattic/agents-api`:

- `AgentsAPI\AI\Approvals\PendingActionStoreInterface`
- `AgentsAPI\AI\Approvals\PendingActionResolverInterface`
- `AgentsAPI\AI\Approvals\PendingActionHandlerInterface`
- `AgentsAPI\AI\Approvals\ApprovalDecision`
- `AgentsAPI\AI\Approvals\PendingAction`
- `AgentsAPI\AI\Tools\ActionPolicy`

The compatibility seam remains the existing `datamachine_pending_action_handlers` map. Handler objects that implement `AgentsAPI\AI\Approvals\PendingActionHandlerInterface` can be placed under the same `apply` key and Data Machine will dispatch to the Agents API handler method. Legacy callable handlers continue to receive the stored `apply_input` and full Data Machine payload.

## Surfaces

- Ability: `datamachine/list-pending-actions`
- Ability: `datamachine/get-pending-action`
- Ability: `datamachine/summarize-pending-actions`
- Resolver ability: `datamachine/resolve-pending-action`
- Chat tool: `resolve_pending_action`
- REST: `GET /datamachine/v1/actions`
- REST: `GET /datamachine/v1/actions/{action_id}`
- REST: `GET /datamachine/v1/actions/summary`
- REST resolver: `POST /datamachine/v1/actions/resolve`
- CLI: `wp datamachine pending-actions list|get|summary`

`PendingActionStore::get()` remains the live-pending lookup used by legacy callers. Resolved rows are retained for audit and are available through inspect/list/get surfaces.
