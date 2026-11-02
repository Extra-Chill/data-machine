# Pending Actions

Data Machine implements the Agents API pending-action approval storage contract with WordPress-backed durable storage.

Pending actions are approval and audit records. Normal runtime requires the durable `datamachine_pending_actions` table; if database access is unavailable, store/resolution operations fail closed and emit `datamachine_pending_action_store_unavailable`. The transient store path is reserved for pure-PHP smoke tests or explicit pre-table boot by defining `DATAMACHINE_PENDING_ACTION_TRANSIENT_FALLBACK`.

Resolution atomically claims a row as `pending -> applying -> accepted|rejected|failed`. Accepted handlers receive an `authorization_receipt` in resolver context (or as the third argument for callable compatibility handlers). Mutators must consume that receipt before mutation, not merely validate it. See [Staging and authorization](#staging-and-authorization) for the binding and single-use requirements.

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

The compatibility seam remains the existing `datamachine_pending_action_handlers` map. Handler objects that implement `AgentsAPI\AI\Approvals\PendingAction_Handler` can be placed under the same `apply` key and Data Machine will dispatch to the Agents API handler method. Callable handlers receive `($apply_input, $payload, $receipt)`. In `plugins/data-machine/inc/Engine/AI/Actions/ResolvePendingActionAbility.php`, `applyHandler()` dispatches interface objects to `handle_pending_action()` with the receipt in resolver context, while `canResolveWithHandlerContract()` uses `can_resolve_pending_action()`. See the [handler filter contract](../development/hooks/core-filters.md#datamachine_pending_action_handlers) for callable signatures and return handling.

## Staging and authorization

### Staging arguments and result

Call `PendingActionHelper::stage( array $args )` rather than constructing a store
record yourself. Source:
`plugins/data-machine/inc/Engine/AI/Actions/PendingActionHelper.php::stage()`.

| Argument | Meaning |
| --- | --- |
| `kind` | Handler dispatch key, sanitized with `sanitize_key()`; must be non-empty. |
| `summary` | Human-readable description, stripped of HTML tags; supply it for the preview (the implementation defaults it to an empty string). |
| `apply_input` | Non-empty, self-contained array replayed to the apply handler. Recheck domain constraints at apply time. |
| `preview_data` | Optional array shown as `payload.preview`; defaults to an empty array. |
| `ttl` | Optional integer lifetime in seconds, forwarded to the store. |
| `resolver_grants` | Optional array of explicit resolver grants, filtered by `datamachine_pending_action_resolver_grants` and stored with the action. |

Other supported arguments include `agent_id`, `user_id` (defaults to the current
user), `context`, `metadata`, `action_id`, `expires_at`, and `authorization`.
The helper records agent/creator identities from the supplied IDs. It returns
the canonical Agents API `approval_required` envelope plus top-level
`staged => true` and `action_id`. The envelope's `payload` carries `action_id`,
`kind`, `summary`, `preview`, `apply_input`, identities, workspace, metadata,
status, timestamps, and `instruction`; non-empty grants are exposed as `grants`.
Read `payload.action_id` (also available at the top level), not a nested pending
action object. On failure it returns
`array( 'staged' => false, 'error' => ..., 'error_code' => ... )` with
`invalid_kind`, `missing_apply_input`, or `store_failed`.

### Receipt binding and replay prevention

The [handler filter reference](../development/hooks/core-filters.md#datamachine_pending_action_handlers)
defines when permission and apply callbacks run. Once acceptance is claimed,
`ResolvePendingActionAbility` issues a receipt for the claimed action and passes
it to the handler. Before any mutation, call:

```php
PendingActionAuthorizationReceipt::consume(
    $receipt, $kind, $operation, $target, $apply_input, $subject, $workspace
);
```

Return immediately if this returns `WP_Error`. `consume()` validates the signed
claims and atomically consumes the receipt through the store. `validate()` only
checks validity and the live claim; repeated validation can succeed before
consumption, so **validation alone allows replay**. It is not a mutation gate.

`consume()` returns `true` on success or `WP_Error` on failure; never continue to
write after an error. Both `validate()` and `consume()` check the binding, but
only `consume()` spends the authorization.

Default bindings are `operation = kind`, `target = apply_input`,
`subject = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' )`, and
`workspace` from the stored payload (an array, otherwise `array()`). The input
binding is the exact stored `apply_input`, not a modified/reconstructed request.
The helper accepts explicit `authorization.operation` and `authorization.target`
overrides, stored under `metadata.datamachine.authorization`; handlers using
these must consume with the same binding. `PendingActionAuthorizationReceipt::authorization()`
reads those overrides with the same defaults. The signed receipt also records
action ID, kind, input/target digests, resolver, issue/expiry times, and claim
nonce. Do not replace stored identities or workspace with caller-supplied values.

Sources:
`plugins/data-machine/inc/Engine/AI/Actions/PendingActionAuthorizationReceipt.php`
(`issue()`, `authorization()`, `validated_claims()`, `validate()`, `consume()`),
`plugins/data-machine/inc/Engine/AI/Actions/PendingActionHelper.php::stage()`, and
`plugins/data-machine/inc/Engine/AI/Actions/ResolvePendingActionAbility.php::resolve_with_datamachine_handlers()`.

### Resolver grants

Grants are an additional gate, not a substitute for scope checks or
`can_resolve`. In `ResolvePendingActionAbility::canResolveWithGrant()`, human
`user:` resolvers and verified signed-URL resolution pass the grant gate by
default. Agent and system resolutions require a matching **stored grant**;
an agent cannot approve its own staged request merely because it staged it.

Resolver classification uses `context.resolution_transport === 'signed_url'`
first, then identifier prefixes: `user:`, `agent:`, `system_task:`/`system:`, and
`cli:`/`operator:` (the last pair has type `operator`). Other identifiers are
`unknown` and also need a matching grant. Thus an operator grant is distinct
from an agent grant:

```php
array( 'type' => 'operator', 'kinds' => array( 'my_kind' ) ) // CLI/operator identity.
array( 'type' => 'agent', 'kinds' => array( 'my_kind' ) )    // Agent identity.
```

An operator grant does not authorize agents or bypass an administrator-only
`can_resolve`; CLI transport alone does not establish an operator identity.
Grants can constrain resolver type, decision, resolver identifier, kind, agent
ID, and session ID, and require payload/context evidence fields. Omitted
constraints are unrestricted, so avoid empty or overly broad grants. Supported
keys are `types`/`type`/`resolver_type`, `decisions`/`decision`,
`resolvers`/`resolver`, `kinds`/`kind`, `agent_id`, `session_id`,
`required_payload_fields`, and `required_context_fields`. List constraints also
accept a scalar. Required evidence fields must exist and be neither `null` nor
an empty string. Session matching uses resolver context first, falling back to
the stored payload's context. A matching grant still does not skip the callable
[permission callback](../development/hooks/core-filters.md#datamachine_pending_action_handlers).
The resolver reads both payload and `metadata.datamachine.resolver_grants`; legacy
`agent_resolvable`/`system_resolvable` flags also synthesize grants.

Source: `plugins/data-machine/inc/Engine/AI/Actions/ResolvePendingActionAbility.php`
(`canResolveWithGrant()`, `resolverType()`, `resolverGrants()`, `grantMatches*()`,
`missingRequiredGrantFields()`). Signed-URL context is supplied after token
verification by
`plugins/data-machine/inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php::resolve_token()`;
do not treat an untrusted transport string as token verification.

### Complete mutating handler example

This illustrative plugin handler stages a post-title change, permits a verified
signed URL or an administrator, and consumes the default-bound receipt before
writing. The callable signatures and resolver failure response shapes are defined
in the [handler filter contract](../development/hooks/core-filters.md#datamachine_pending_action_handlers).
This example uses the default operation/target binding; if you stage explicit
authorization overrides, read them with `authorization()` and pass the same values
to `consume()` instead. Call `ExampleTitleApproval::stage()` from an
already-authorized preview entry point; staging does not mutate the post. The operator-only grant mirrors
the reference implementations and deliberately supplies no agent grant.

```php
use DataMachine\Engine\AI\Actions\PendingActionHelper;
use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;

final class ExampleTitleApproval {
    public const KIND = 'example_post_title';

    public static function stage( int $post_id, string $title ) {
        if ( ! current_user_can( 'manage_options' ) || ! get_post( $post_id ) ) {
            return new \WP_Error( 'invalid_title_request', 'Administrator and existing post required.' );
        }
        $input = array( 'post_id' => $post_id, 'title' => sanitize_text_field( $title ) );
        $staged = PendingActionHelper::stage( array(
            'kind'            => self::KIND,
            'summary'         => 'Change post title to: ' . $input['title'],
            'apply_input'     => $input,
            'preview_data'    => $input,
            'ttl'             => DAY_IN_SECONDS,
            'resolver_grants' => array(
                array( 'type' => 'operator', 'kinds' => array( self::KIND ) ),
            ),
        ) );
        if ( false === ( $staged['staged'] ?? false ) ) {
            return new \WP_Error( 'stage_failed', $staged['error'] ?? 'Staging failed.' );
        }
        return $staged;
    }

    public static function can_resolve( array $payload, string $decision, int $user_id, array $context ) {
        if ( 'signed_url' === ( $context['resolution_transport'] ?? '' ) ) {
            return true;
        }
        if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
            return true;
        }
        return new \WP_Error( 'requires_admin', 'Administrator or signed approval required.' );
    }

    public static function apply( array $apply_input, array $payload, array $receipt ) {
        // Consume first; never write after a failed receipt check.
        $subject = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
        $workspace = is_array( $payload['workspace'] ?? null ) ? $payload['workspace'] : array();
        $authorized = PendingActionAuthorizationReceipt::consume(
            $receipt, self::KIND, self::KIND, $apply_input, $apply_input, $subject, $workspace
        );
        if ( is_wp_error( $authorized ) ) {
            return $authorized;
        }
        $post_id = (int) ( $apply_input['post_id'] ?? 0 );
        if ( ! get_post( $post_id ) || ! is_string( $apply_input['title'] ?? null ) ) {
            return new \WP_Error( 'invalid_title_request', 'Post or title is no longer valid.' );
        }
        $result = wp_update_post( wp_slash( array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field( $apply_input['title'] ),
        ) ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return array( 'success' => true, 'post_id' => $result );
    }
}

add_filter( 'datamachine_pending_action_handlers', function ( array $handlers ): array {
    $handlers[ ExampleTitleApproval::KIND ] = array(
        'can_resolve' => array( ExampleTitleApproval::class, 'can_resolve' ),
        'apply'       => array( ExampleTitleApproval::class, 'apply' ),
    );
    return $handlers;
} );

// In the authorized preview entry point, for an existing post:
// return ExampleTitleApproval::stage( $post_id, 'Approved new title' );
```

Real implementations to compare (paths relative to the workspace):

- `plugins/data-machine-business/inc/Abilities/Stripe/StripePaymentLinkAbility.php::stage()`
  stages normalized input with TTL and operator grants and handles staging failure;
  its `approval_links()` calls `SignPendingActionResolutionAbility::execute()` to
  create signed approval links.
- `plugins/data-machine-business/inc/Abilities/Stripe/StripePaymentLinkApproval.php`
  (`resolver_grants()`, `can_resolve()`, `apply()`) allows signed URL or admin,
  consumes first, rechecks current limits, and propagates `WP_Error` failures
  before/through Stripe writes.
- `plugins/data-machine-socials/inc/Abilities/Facebook/FacebookPromotionApproval.php`
  (`resolver_grants()`, `can_resolve()`, `apply()`, `apply_update()`) uses the same
  authorization pattern before promotion writes.

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

The public token route validates the signature and expiry. GET only previews a confirmation page; resolution requires POST with the confirmation signature. `resolve_token()` delegates to the canonical `agents/resolve-pending-action` path with `context.resolution_transport = signed_url` (source: `plugins/data-machine/inc/Engine/AI/Actions/SignPendingActionResolutionAbility.php`, `handle_rest()`, `preview_token()`, `resolve_token()`). Already-resolved accepted/rejected rows return their existing decision instead of being overwritten. Expired, deleted, missing, or otherwise non-resolvable rows return `410`.

`datamachine/list-pending-actions`, `datamachine/get-pending-action`, `datamachine/summarize-pending-actions`, `datamachine/resolve-pending-action`, and `POST /datamachine/v1/actions/resolve` remain compatibility surfaces for existing Data Machine clients. New clients should call the canonical `agents/*pending-action*` abilities. Data Machine supplies the host store/resolver through `wp_agent_pending_action_store` and `wp_agent_pending_action_resolver`; Agents API does not own Data Machine's storage table.

Use `SignPendingActionResolutionAbility::rotate_secret()` to invalidate existing signed URLs during a security incident.
