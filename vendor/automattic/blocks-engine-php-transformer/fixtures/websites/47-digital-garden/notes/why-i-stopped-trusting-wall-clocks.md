---
title: "Why I Stopped Trusting Wall Clocks"
aliases: ["Wall Clocks", "Clocks in Distributed Systems", "Logical Clocks"]
created: 2024-05-18
updated: 2026-03-08
tags: [budding, distributed-systems, engineering]
---

# Why I Stopped Trusting Wall Clocks

The most dangerous line in a distributed system is the one that looks the most innocent:

```python
if event_a.timestamp > event_b.timestamp:
    winner = event_a   # "later" event wins
```

`timestamp` here is a **wall clock** — the time-of-day clock on whatever machine produced
the event. And wall clocks across machines are *liars*. They drift. They get corrected
backwards by NTP mid-operation. A clock on server X can read *earlier* than a clock on
server Y for an event that genuinely happened *later*. "Last write wins" becomes "whoever
has the most optimistic clock wins," which is a silent data-corruption machine.

## The real problem: ordering, not time

What that code actually wants to know is not "what time was it?" but **"which event
happened before the other?"** Those are different questions. In a distributed system you
usually cannot answer the first reliably — and you don't need to. You need *causal order*,
which is exactly what the network can't naturally give you (same root cause as
[[The Two Generals Problem]]: no shared global state).

## Logical clocks

Lamport's insight: forget the time of day, just count.

- Each node keeps a counter.
- On every local event, increment.
- On every message *send*, attach the counter.
- On every message *receive*, set `counter = max(local, received) + 1`.

Now if A causally precedes B, then `clock(A) < clock(B)`. The reverse isn't guaranteed
(equal-ish counters mean "concurrent, can't order"), and **that honesty is the point**: a
logical clock will *tell you* when two events are genuinely concurrent instead of inventing
a fake order from drifting hardware.

Vector clocks go further and let you detect concurrency precisely, which is how systems
like Dynamo decide when two writes *conflict* and must be merged rather than silently
clobbered.

## How this connects to the rest of the garden

- It's the same impossibility as [[The Two Generals Problem]] — there is no free shared
  clock for the same reason there's no reliable shared messenger.
- It's why [[Idempotency Is a Superpower]] uses an explicit **idempotency key** rather than
  "the most recent timestamp" to dedup. Identity beats timing.

## The maxim I keep

> Time tells you *when*. It does not tell you *what happened before what.* In a distributed
> system, only the second question is answerable, so stop asking the first.

## See also

- [[The Two Generals Problem]]
- [[Idempotency Is a Superpower]]
- [[index]]
