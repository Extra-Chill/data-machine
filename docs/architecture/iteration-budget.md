# Iteration Budget

Generic primitive for bounded iteration with a configurable ceiling. Counts a named dimension (conversation turns, A2A chain depth, retry attempts) and exposes a uniform API for checking exceedance, formatting warnings, and surfacing response flags.

**Source**: `inc/Engine/AI/IterationBudget.php`, `inc/Engine/AI/IterationBudgetRegistry.php`
**Since**: v0.71.0

## Why a primitive

Before this primitive, every bounded loop in the codebase invented its own counter and its own "did we hit the limit" check — turn limits in the conversation loop, retry caps in async tasks, chain-depth limits in cross-site A2A. The result was inconsistent thresholds, copy-pasted clamping logic, and four different ways to surface "you ran out of budget" to the AI.

`IterationBudget` is one value object covering all of them. Site config registers a named budget with a default, a site-setting override key, and clamp bounds; runtime code instantiates the budget for the current run and uses the same five methods on every consumer.

## Two-stage lifecycle

Budgets are **registered at boot** and **instantiated per run**.

### Registration (boot time)

Static, side-effect free, idempotent. Lives in `inc/bootstrap.php`:

```php
IterationBudgetRegistry::register( 'conversation_turns', array(
    'default' => PluginSettings::DEFAULT_MAX_TURNS,
    'min'     => 1,
    'max'     => 50,
    'setting' => 'max_turns',
) );

IterationBudgetRegistry::register( 'chain_depth', array(
    'default' => 3,
    'min'     => 1,
    'max'     => 10,
    'setting' => 'max_chain_depth',
) );
```

Each registration declares:

- `default` — Built-in fallback ceiling.
- `min`, `max` — Hard clamp bounds. Resolved ceiling is always inside `[min, max]`.
- `setting` — `PluginSettings` key that, when set, overrides `default`.

Registrations are static. Calling `register()` twice with the same name overwrites — useful in tests, harmless in production.

### Instantiation (per run)

Each loop creates its own counter instance:

```php
$budget = IterationBudgetRegistry::create( 'conversation_turns', $startingCount = 0 );

while ( ! $budget->exceeded() ) {
    $budget->increment();
    // ... do work ...
}
```

`create()` reads the registered config, applies the ceiling-resolution chain, and returns a fresh `IterationBudget` value object.

## Ceiling resolution

Order, in `IterationBudgetRegistry::create()`:

1. `$ceiling_override` argument — caller-supplied. Always wins. Used in tests and in execution paths that already received an override from a CallerContext header.
2. `PluginSettings::get( config['setting'] )` — site-setting override.
3. `config['default']` — registered default.

Then clamped to `[config['min'], config['max']]`. The clamp is non-negotiable — even a misconfigured site setting can't push the ceiling out of safe bounds.

## API surface

`IterationBudget` exposes:

| Method | Purpose |
|--------|---------|
| `increment()` | Bump the counter by one. Call at the top of each iteration. |
| `current(): int` | Current counter value. |
| `ceiling(): int` | Resolved ceiling for this run. |
| `remaining(): int` | `ceiling - current`, never negative. |
| `exceeded(): bool` | `current >= ceiling`. The loop's exit condition. |
| `name(): string` | Budget name (e.g. `conversation_turns`). |
| `toResponseFlag(): string` | Returns `"max_{name}_reached"` — the canonical flag for telling the AI / API consumer that this budget tripped. |

`exceeded()` and `toResponseFlag()` are the integration points: every consumer surfaces budget exhaustion the same way.

## Built-in budgets

### conversation_turns

Bounds how many turns a single AI conversation can run before the loop bails out.

- **Default**: `PluginSettings::DEFAULT_MAX_TURNS`
- **Site-setting key**: `max_turns`
- **Clamp**: `[1, 50]`
- **Consumer**: `AIConversationLoop`

When exceeded, the loop returns with the response flag `max_conversation_turns_reached`, allowing the caller to detect "stopped because of turn limit" vs "stopped because the AI is done".

### chain_depth

Bounds how many cross-site agent hops a single A2A chain can contain before being refused. Prevents runaway recursion when agents on different sites can call each other's `/chat` endpoints.

- **Default**: `3`
- **Site-setting key**: `max_chain_depth`
- **Clamp**: `[1, 10]`
- **Consumer**: A2A middleware via `CallerContext`

When exceeded, the request is refused with HTTP 429 and the error code `datamachine_chain_depth_exceeded`. The chain-depth header (`X-Datamachine-Chain-Depth`) is incremented by the receiving middleware before the budget check, so the budget reflects the count *after* this hop would land.

## Adding a new budget

1. Register at boot in `inc/bootstrap.php` (or in your extension's bootstrap):

   ```php
   IterationBudgetRegistry::register( 'my_budget', array(
       'default' => 5,
       'min'     => 1,
       'max'     => 20,
       'setting' => 'my_budget_max',
   ) );
   ```

2. Instantiate per run wherever the loop lives:

   ```php
   $budget = IterationBudgetRegistry::create( 'my_budget' );
   while ( $work_remaining && ! $budget->exceeded() ) {
       $budget->increment();
       // ...
   }
   if ( $budget->exceeded() ) {
       $response['flags'][] = $budget->toResponseFlag();
   }
   ```

3. Surface the response flag — `max_my_budget_reached` — wherever your loop returns control to its caller. Consumers (CLI, REST, agents) already know how to interpret `max_*_reached` flags because every bounded loop emits them the same way.

## Why this isn't a Trait or base class

Budgets don't share inheritance — they share *shape*. Different consumers count different things and integrate at different layers (an HTTP middleware, an AI loop, a retry helper). A trait or abstract class would force them to agree on lifecycle methods they don't actually share.

`IterationBudget` is a single value-object class with a registry. Every consumer constructs its own, calls the same five methods, and emits the same response-flag convention. That is the contract. Inheritance would add nothing.

## Related

- [Policy Resolvers](policy-resolvers.md) — Same "single-purpose primitive over abstraction" principle applied to per-call decisioning.
- [Pipeline Execution Axes](pipeline-execution-axes.md) — How `conversation_turns` and the pipeline's queue/fan-out axes compose.
- `PluginSettings::DEFAULT_MAX_TURNS` — Default value source for `conversation_turns`.
