# Delegated Operations

Delegated operations let an authenticated caller invoke one action registered by
its owning plugin without receiving Data Machine workflow, task, job, or agent
management authority.

## Register An Action

Register actions on `datamachine_delegated_operation_actions`. Action IDs use
`owner/action` syntax and are part of the durable operation identity.

```php
add_filter(
	'datamachine_delegated_operation_actions',
	static function ( array $actions ): array {
		$actions['owner/create-record'] = array(
			'version'         => '1',
			'normalize_input' => static function ( array $input, array $context ) {
				// Return a bounded, deterministic array or WP_Error.
				return array( 'record_key' => sanitize_key( $input['record_key'] ?? '' ) );
			},
			'authorize'       => static function ( array $context ) {
				// Authorize every submit, reconcile, retry, and cancel phase.
				// Data Machine management capabilities do not bypass this callback.
				return current_user_can( 'create_owner_records' )
					? true
					: new WP_Error( 'owner_action_forbidden', 'Not authorized.' );
			},
			'prepare'         => static function ( array $input, array $context ): array {
				return array(
					'owner_user_id' => 123,
					'agent_slug'    => 'stable-owner', // Optional; its owner becomes user_id.
					'label'         => 'Create owner record',
					'workflow'      => array( 'steps' => array( /* owner workflow */ ) ),
					'initial_data'  => array( 'owner_request' => $input ),
				);
			},
			'project'         => static function ( array $run_result, array $context ): array {
				// Return only owner-approved identifiers, codes, and references.
				return array(
					'effect_count' => 1,
					'record_ref'   => 'rec_123',
				);
			},
			'retry'           => static function ( array $run_result, array $context ) {
				// Reconcile the owner's durable effect receipt. Return true only when
				// replaying the frozen request cannot duplicate consequential work.
				return true;
			},
		);
		return $actions;
	}
);
```

`normalize_input` and `prepare` execute only on submission. Their deterministic
output, the registration version, stable execution owner, workflow, initial
data, and requested timestamp form the frozen request fingerprint. The first
successful submission attests the initiating user/agent separately from the
stable execution owner. Initiator identity does not affect deduplication.

`authorize` receives `phase`, `action`, `operation_id`, `operation_ref`, `actor`,
and normalized `input`. It must return `true` or `WP_Error`. The owner remains
responsible for resource authorization in every phase.

Callback signatures are:

```php
normalize_input( array $input, array $context ): array|WP_Error
authorize( array $context ): true|WP_Error
prepare( array $normalized_input, array $context ): array|WP_Error
project( array $canonical_run_result, array $context ): array|WP_Error
retry( array $failed_canonical_run_result, array $context ): true|WP_Error // Optional.
```

`prepare` returns `workflow`, `owner_user_id` and/or `agent_id`/`agent_slug`,
plus optional `initial_data` and `label`. When an agent is supplied, its
registered owner is the authoritative execution user. Callers cannot provide or
override any execution descriptor field.

`project` receives the canonical `datamachine.run_result.v1` envelope and must
return a redacted JSON-safe object no larger than 32 KiB. Raw jobs, diagnostics,
workflow state, task classes, and scheduler records are never public inputs or
outputs. Set `effect_count` to `0` when successful execution produced no
consequential effect; Data Machine then reports `no-op`.

Data Machine also reports `no-op` for canonical skipped/no-items statuses and
for a canonical integer `outputs.effect_count` of `0`.

`retry` is optional. Without it, explicit retry fails closed. When registered,
it receives the failed canonical run result and frozen operation context. It
must reconcile the owner's durable effect receipt and return `true` only when
rerunning cannot duplicate consequential work. Automatic engine retries retain
their existing generation fence and owner handler idempotency requirements.

## Submit

Execute `datamachine/submit-delegated-operation` with:

```json
{
  "action": "owner/create-record",
  "operation_id": "caller-stable-request-42",
  "input": { "record_key": "example" },
  "timestamp": 1780000000
}
```

The response contains an opaque `operation_ref`, `status`, replay flag, and the
owner projection. Repeating the same action and operation ID from any authorized
WordPress user atomically returns the same operation. Reuse with changed input,
policy, owner, workflow, or schedule returns `delegated_operation_conflict`.

Successful responses have `success`, `operation_ref`, `status`, and `replayed`,
with optional `projection` and bounded `retry` metadata. Failures have `success:
false`, `error_code`, `error`, and optional `retryable`. No ability accepts or
returns a job ID, workflow specification, task type, class name, or scheduler
record.

## Reconcile, Retry, And Cancel

Use these abilities with only `action` and `operation_ref`:

- `datamachine/get-delegated-operation`
- `datamachine/retry-delegated-operation`
- `datamachine/cancel-delegated-operation`

Statuses are `submitted`, `executing`, `executed`, `no-op`, `failed`,
`cancelled`, and `retrying`. Automatic retry metadata is bounded to attempt,
maximum attempts, and next retry time. Explicit retry reopens only failed work
after its owner retry callback proves replay safety, and preserves its operation
identity. Cancellation succeeds only before work
starts; it atomically fences the queued generation before removing its scheduled
action. An executing operation cannot claim safe cancellation.

Delegated operation envelopes are excluded from short-window terminal
`engine_data` shedding because the frozen authorization context and redacted
canonical reconciliation remain part of the durable operation contract. Normal
whole-row retention still defines the operation's final storage lifetime.
