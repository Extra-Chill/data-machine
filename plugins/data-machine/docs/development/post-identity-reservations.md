# Post Identity Reservations

When `identity_meta` is the selected identity path, `datamachine/upsert-post`
uses a per-site reservation table and a deterministic MySQL advisory lock. A
valid explicit `post_id` remains the highest-priority identity and bypasses the
reservation path and identity metadata mirror for backward compatibility.

The durable reservation is the mathematical one-post-ID guarantee: after a
reservation links a post, every retry uses that post ID and cannot allocate a
second shell. The advisory lock provides best-effort linearization for the
later normal WordPress update, metadata, taxonomy, and hook phase without
holding an SQL transaction across arbitrary WordPress behavior. Because MySQL
named locks are connection-scoped, a wpdb reconnect can lose that ordering
fence; the implementation does not claim full-operation exclusion across a
reconnect. The reservation remains authoritative after such a loss.

## Rolling Deployment

Releases predating the reservation table use lookup followed by
`wp_insert_post()` and do not acquire the advisory lock. There is no generic
legacy lock name or protocol those processes participate in. Operators must
drain or stop in-flight old workers before enabling the new schema guarantee.
The guarantee applies once all writers run the reservation-aware release; it
does not claim cross-version exclusion against old code still executing.

## Hook Semantics

First population preallocates a draft shell and then calls the normal WordPress
write path with its explicit ID. WordPress therefore emits update and draft-to-
target-status transition hooks, while the ability reports the logical action as
`created`. Callers must use the ability result contract rather than relying on
core hook `$update === false` for identity-backed creation.

## Concurrency Evidence

The WordPress integration tests exercise retries through the real allocator and
use independent MySQL connections to prove reservation-row serialization and
advisory-lock exclusion. They do not yet invoke the PHP allocator itself from
two independent worker processes: the repository is bound to WordPress's global
`$wpdb`, and the local Codebox WordPress bootstrap needed for a subprocess test
is not currently available. That process-level allocator evidence belongs in a
working WordPress/Codebox integration environment rather than a mocked test.
