---
title: "Idempotency Keys: The Cheapest Insurance in Distributed Systems"
date: 2024-04-16T08:00:00Z
author: mara
draft: false
description: "A small, boring pattern that quietly prevents double-charges, duplicate orders, and a whole category of 2 a.m. pages. Here's how to implement idempotency keys correctly."
tags: ["distributed-systems", "reliability", "apis", "patterns"]
series: ["Operational Reality"]
images: ["/images/idempotency-cover.png"]
---

In [the post on retries](/posts/retries-make-outages-worse/) I waved my hands
at idempotency keys as the thing that makes retrying a payment safe. Several
people emailed asking how they actually work. They deserve a real answer,
because this is one of those patterns that looks trivial and is full of sharp
edges.

The premise is simple. The network is unreliable, so requests will get sent
more than once — by retries, by impatient users mashing a button, by flaky
mobile connections. An **idempotency key** lets the server recognize "I've
already seen this exact request" and return the original result instead of
doing the work twice.

The implementation is where it gets interesting.

## The naive version, and why it's not enough

The first thing everyone reaches for:

```sql
-- Check if we've seen this key
SELECT result FROM idempotency_keys WHERE key = $1;
-- If not found, do the work, then:
INSERT INTO idempotency_keys (key, result) VALUES ($1, $2);
```

This has a race condition you could drive a truck through. Two copies of the
same request arrive simultaneously. Both run the `SELECT`, both find nothing,
both proceed to do the work, both insert. Congratulations, you've double-charged
the customer *and* you have a duplicate-key error to clean up.

The check and the claim have to be **atomic**. You can't look first and act
later.

## The correct version: claim first, atomically

The fix is to insert the key *before* doing the work, in a way that fails
loudly if someone else already claimed it:

```sql
INSERT INTO idempotency_keys (key, status, created_at)
VALUES ($1, 'in_progress', now())
ON CONFLICT (key) DO NOTHING
RETURNING key;
```

If the `RETURNING` gives you a row, you won the race — you own this key, go do
the work. If it gives you nothing, someone else owns it. Now you have a
*second* question: is that other request still running, or did it finish?

This is the part people skip, and it's the part that matters.

## The state machine

An idempotency key is not a boolean. It's a small state machine, because the
first request can be in three meaningfully different states when a duplicate
arrives:

| Status | Meaning | What the duplicate should do |
|--------|---------|------------------------------|
| `in_progress` | Original is still running | Wait and poll, or return `409 Conflict` |
| `completed` | Original finished successfully | Return the **stored response**, verbatim |
| `failed` | Original failed cleanly | Safe to retry the operation |

The `in_progress` case is the subtle one. A duplicate that arrives mid-flight
must *not* start the work again, and it also can't return a result that
doesn't exist yet. The honest answer is to make the client wait — either by
blocking briefly and polling, or by returning a `409` that says "this is being
processed, try again shortly."

You also need a **lease timeout**. If the process holding an `in_progress` key
crashes, that key is stuck forever and the customer can never complete their
action. Stamp each claim with an expiry; if a key has been `in_progress`
longer than, say, 60 seconds, treat it as abandoned and reclaimable.

## Store the response, not just the fact

A mistake I've seen in production more than once: the system records that a key
was used but doesn't store *what it returned*. So when the duplicate arrives,
the server knows "yep, did that already" but can't tell the client what
happened. The client, having gotten a useless answer, retries with a *new*
key — and now you've done the work twice anyway.

Store the full response body and status code against the key. When a duplicate
of a `completed` request arrives, replay that stored response exactly. From the
client's perspective, the retry is indistinguishable from the original
succeeding. That's the entire point.

## Scope and key generation

Two decisions that bite teams later:

**Who generates the key?** The *client*, ideally — usually a UUID generated
once per logical operation and reused across retries of that operation. If the
server generates it, the server can't dedupe the very first duplicate, because
the client didn't have a key to send the first time. Stripe, for instance, has
clients pass an `Idempotency-Key` header.

**What's the key's scope?** A key should be unique per *intent*, not per
*payload*. But you also want to defend against a client reusing a key for a
genuinely different request (a bug, or worse). A good practice is to store a
hash of the request body alongside the key and reject mismatches with a `422`:
same key + different body means something is wrong, and silently returning the
old result would be a lie.

## Expiry: keys are not forever

The `idempotency_keys` table grows without bound if you let it. Keys only need
to live as long as a client might plausibly retry — usually 24 to 72 hours is
plenty. Set a TTL and reap old keys. Just make sure the TTL comfortably exceeds
your longest retry window, or you'll expire a key right before a legitimate
retry and reopen the double-execution hole you closed.

## Putting it together

The full lifecycle, end to end:

1. Client generates a key, sends it with the request.
2. Server atomically claims the key as `in_progress` (with a lease).
3. **Won the claim?** Do the work, store the response, mark `completed`.
4. **Lost the claim?** Inspect the existing key's state:
   - `completed` → replay stored response.
   - `in_progress` (lease valid) → tell client to wait / `409`.
   - `in_progress` (lease expired) or `failed` → reclaim and retry.
5. A reaper deletes keys past their TTL.

That's it. A few dozen lines and one extra table.

> Idempotency keys are insurance. You pay a small, boring premium on every
> write, and in exchange you never have to explain to a customer why they were
> charged twice. It's the best deal in distributed systems.

The pattern is unglamorous, which is exactly why it's underused. Nobody gets
promoted for shipping idempotency keys. But the engineer who *didn't* ship them
is the one writing the incident report.

---

*Have a war story about a missing idempotency key? I collect them —
[mara@coldstart.dev](mailto:mara@coldstart.dev).*
