# OAuth Account Scope API

## Status

Proposed. This document defines the migration plan for issue #2117. It is a
design agreement target before runtime callsites move away from the current
context-array account API.

## Problem

`BaseAuthProvider` exposes two account access patterns that share storage but
mean different things:

```php
// Policy-resolved, context-array API.
$provider->get_account( array( 'user_id' => 42 ) );
$provider->save_account( $account, array( 'user_id' => 42 ) );
$provider->clear_account( array( 'user_id' => 42 ) );

// Explicit, no-fallback per-user API.
$provider->get_account_for_user( 42 );
$provider->save_account_for_user( 42, $account );
$provider->delete_account_for_user( 42 );
```

Both forms can address `principals[user:<id>][account]`, but they differ in
scope resolution and fallback behavior.

The context-array form first consults `datamachine_auth_scope_policy`. If the
provider policy is `site`, which is the default, a contextual user read such as
`get_account( array( 'user_id' => 42 ) )` reads the site-wide account instead
of user 42's account. If the policy is `user` or `principal`, it reads the user
account when present and falls back to site-wide storage when the scoped slot is
empty.

The explicit per-user form never consults provider policy and never falls back
to the site-wide account. Missing per-user credentials return `null`.

Both behaviors are valid. The problem is that the method names do not make the
policy boundary obvious, so vendor plugins can accidentally choose fallback or
no-fallback semantics without realizing it.

## Goal

Make account scope a method-level choice:

```php
$provider->get_site_account();
$provider->get_account_for_user( $user_id );
$provider->get_account_for_agent( $agent_id );
```

Callers should be able to pick the account scope by reading the method name.
Policy-resolved lookup remains available for ambient cases, but it should be a
helper/shim rather than the primary storage API.

## Non-Goals

- Change the `datamachine_auth_data` storage layout.
- Change encryption-at-rest behavior.
- Remove `datamachine_resolve_oauth_account_for_user`.
- Break existing providers during the first migration release.
- Decide product policy for which providers should be site, user, agent, or
  principal scoped.

## Proposed API

### Site-Wide Accounts

```php
public function get_site_account(): ?array;
public function save_site_account( array $account ): bool;
public function delete_site_account(): bool;
```

These methods read and write the existing top-level provider account slot:

```php
datamachine_auth_data[<provider_slug>][account]
```

`get_site_account()` returns `null` when no site-wide account exists. This is a
small but intentional improvement over `get_account()`, which returns an empty
array for missing accounts.

### Per-User Accounts

```php
public function get_account_for_user( int $user_id ): ?array;
public function save_account_for_user( int $user_id, array $account ): bool;
public function delete_account_for_user( int $user_id ): bool;
```

These methods already exist. They remain explicit, no-fallback APIs for human
user credentials and continue to use:

```php
datamachine_auth_data[<provider_slug>][principals][user:<id>][account]
```

### Per-Agent Accounts

```php
public function get_account_for_agent( int $agent_id ): ?array;
public function save_account_for_agent( int $agent_id, array $account ): bool;
public function delete_account_for_agent( int $agent_id ): bool;
```

These methods should mirror the per-user API, without policy consultation and
without site fallback. They use:

```php
datamachine_auth_data[<provider_slug>][principals][agent:<id>][account]
```

## Policy-Resolved Lookup

The current `datamachine_auth_scope_policy` filter remains useful when a caller
is asking for the account that applies to an ambient execution context.

That behavior should be expressed as a named resolver instead of hiding inside
all account reads:

```php
public function get_account_for_context( array $context = array() ): ?array;
```

`get_account_for_context()` consults the same policy inputs as the current
`get_account( array $context )` implementation:

- Explicit `agent_id` in context.
- Explicit `user_id` in context.
- `PermissionHelper::get_acting_agent_id()`.
- `PermissionHelper::acting_user_id()`.
- `get_current_user_id()`.
- The provider's `datamachine_auth_scope_policy` result.

The resolver may preserve the current site fallback behavior for one release
cycle so existing policy-scoped providers keep working while callsites migrate.

## Deprecation Plan

`get_account( array $context = array() )`, `save_account( array $data, array
$context = array() )`, and `clear_account( array $context = array() )` become
compatibility shims.

They should emit `_deprecated_function()` when called with a non-empty context
array. Context-free calls can continue without warning for the first migration
release because they are the common site-wide account API today.

Recommended shim behavior:

```php
// Site-wide compatibility.
$provider->get_account();
$provider->save_account( $account );
$provider->clear_account();

// Deprecated context-array compatibility.
$provider->get_account( array( 'user_id' => 42 ) );
$provider->save_account( $account, array( 'user_id' => 42 ) );
$provider->clear_account( array( 'user_id' => 42 ) );
```

The first implementation PR should avoid warning on context-free calls to keep
existing site-wide providers quiet. A later major release can deprecate the
context-free aliases after `get_site_account()` is widely adopted.

## Migration Guide

| Current call | Replacement | Behavior |
| --- | --- | --- |
| `get_account()` | `get_site_account()` | Site-wide account only. |
| `save_account( $account )` | `save_site_account( $account )` | Site-wide account only. |
| `clear_account()` | `delete_site_account()` | Site-wide account only. |
| `get_account( array( 'user_id' => $id ) )` | `get_account_for_user( $id )` | User account only, no fallback. |
| `save_account( $account, array( 'user_id' => $id ) )` | `save_account_for_user( $id, $account )` | User account only. |
| `clear_account( array( 'user_id' => $id ) )` | `delete_account_for_user( $id )` | User account only. |
| `get_account( array( 'agent_id' => $id ) )` | `get_account_for_agent( $id )` | Agent account only, no fallback. |
| Ambient policy lookup | `get_account_for_context( $context )` | Provider policy decides scope. |

Vendor plugins should prefer explicit named methods when the caller already
knows the desired principal. Use policy-resolved lookup only when the desired
scope is genuinely delegated to provider policy.

## Rollout Slices

1. Add this RFC and link it from OAuth handler docs.
2. Add site-wide named methods and tests in `BaseAuthProvider`. Shipped in
   v0.128.0.
3. Add agent named methods and tests in `BaseAuthProvider`. Shipped in
   v0.129.0.
4. Add `get_account_for_context()`. Shipped in v0.130.0.
5. Reduce context-array `get_account()` to a deprecated shim for non-empty
   contexts. Shipped in v0.131.0.
6. Reduce context-array `save_account()` and `clear_account()` to deprecated
   shims for non-empty contexts.
7. Update internal site-wide callsites to `get_site_account()` /
   `save_site_account()` / `delete_site_account()`.
8. Update internal scoped callsites to `get_account_for_user()` or
   `get_account_for_agent()` when the scope is explicit.
9. Publish vendor migration notes and coordinate downstream plugin updates.
10. After at least one release cycle, decide whether context-free `get_account()`
   should also become a deprecated alias for `get_site_account()`.

## Open Questions

- Should config storage get the same named-scope treatment, or should this
  migration stay account-only until vendor account reads are cleaned up?
- Should `get_account_for_context()` preserve site fallback permanently, or only
  during the deprecation window?
- Should per-agent account resolution expose a filter equivalent to
  `datamachine_resolve_oauth_account_for_user`?
- Should missing site-wide accounts return `null` only in new methods while old
  methods keep returning empty arrays forever?
