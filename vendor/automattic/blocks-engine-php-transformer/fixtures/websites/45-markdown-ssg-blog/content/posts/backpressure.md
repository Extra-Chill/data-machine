---
title: "Backpressure, or How to Say No Before You Fall Over"
date: 2024-05-07T07:30:00Z
author: devin
draft: false
description: "Unbounded queues are not resilience — they're a slow-motion outage with extra latency. A practical look at backpressure, load shedding, and the art of failing on purpose."
tags: ["reliability", "distributed-systems", "sre", "queues", "performance"]
series: ["Operational Reality"]
images: ["/images/backpressure-cover.png"]
---

There's a seductive lie in software design that goes: *if a component is too
busy, just add a queue.* The queue absorbs the burst, the slow consumer
catches up later, everyone's happy. It works beautifully in the demo. Then it
meets real traffic, the queue grows without bound, latency climbs into the
stratosphere, memory runs out, and the whole thing falls over.

The missing concept is **backpressure**: the ability of an overloaded system
to push back on its producers and say *slow down, I can't take more right now.*
A system without backpressure doesn't get more resilient under load. It just
fails later, and worse.

## Little's Law is not optional

Before any of the patterns, the physics. **Little's Law** says:

```
L = λ × W
```

The number of requests in your system (`L`) equals the arrival rate (`λ`)
times the average time each spends in the system (`W`). It's not a guideline;
it's arithmetic. You can't cheat it.

The implication is brutal and clarifying: **if arrivals outpace your service
rate, the only variable left to absorb the difference is time-in-system —
i.e., latency — and queue depth.** Both grow without bound until something
physical stops them (memory, timeouts, the heat death of the universe). A queue
doesn't fix an overload; it converts it into latency and then into a crash.

> An unbounded queue is just a memory leak with good PR.

## Bound everything

Rule one of backpressure: **every queue has a limit.** When the limit is hit,
something has to give, and you get to choose what:

- **Block the producer** — the producer waits until there's room. This
  propagates backpressure *upstream*, which is exactly what you want in a
  pipeline. The slowness flows back to the source, which can then slow its own
  intake.
- **Reject the request** — return a `503` or `429` immediately. The producer
  finds out *now*, while it can still do something useful, instead of after a
  30-second timeout.
- **Drop the oldest** — in some streaming workloads, stale data is worthless,
  so you'd rather discard old items than reject new ones.

What you must *not* do is the implicit fourth option: let the queue grow until
the OOM killer makes the decision for you. The OOM killer is not a load-shedding
strategy.

Each choice propagates differently, and the right one depends on the shape of
the work:

| Strategy | What the producer sees | Best for |
|----------|------------------------|----------|
| Block the producer | A slow call | Pipelines where the source can slow down |
| Reject (`503`/`429`) | A fast error | Request/response APIs with retrying clients |
| Drop oldest | Silence (data lost) | Streaming where stale data is worthless |
| Let it grow (don't) | Eventually, a crash | Nothing — this is the default you must remove |

## Bounded concurrency: the semaphore

The cleanest way to bound work in flight is a **concurrency limit** — a
semaphore that caps how many operations run at once. If the limit is full, new
work either waits or is rejected.

```python
import asyncio

semaphore = asyncio.Semaphore(50)  # at most 50 concurrent calls

async def handle(request):
    if semaphore.locked() and semaphore._value == 0:
        raise TooBusy()  # shed load instead of queueing forever
    async with semaphore:
        return await do_work(request)
```

The hard question is *what number?* Pick it too low and you waste capacity;
too high and you're back to unbounded behavior. The right value follows from
Little's Law again: it's roughly the concurrency at which latency starts
climbing nonlinearly — your system's actual saturation point. Find it with a
load test, not a guess. Better yet, make it **adaptive**: systems like Netflix's
concurrency-limits library adjust the limit at runtime based on observed
latency, the same way TCP congestion control adjusts its window.

## Load shedding: choose what to drop

When you're genuinely over capacity, you *will* drop work. The only question
is whether you drop it deliberately or chaotically. Deliberate dropping is
**load shedding**, and the key insight is that not all requests are equal:

- A health check from a load balancer is cheap and important — never shed it.
- A request that's already been waiting 29 seconds against a 30-second timeout
  is **dead on arrival**; doing the work just wastes capacity on a result
  nobody will receive. Shed it.
- A low-priority batch job can wait; an interactive user request can't.

Shedding the right things keeps the system useful under overload instead of
uniformly broken. The cruelest failure mode is one where every request gets
*just enough* service to consume resources but *not enough* to succeed — a
system busily doing nothing. Good load shedding prevents exactly that.

## The deadline propagation trick

One of my favorite cheap wins: propagate **deadlines** through the call chain.
When a request enters with a 5-second budget, every downstream call inherits
the remaining time. If a call would take a service past the deadline, it
doesn't even start — it returns immediately, because the caller has already
given up.

gRPC does this natively with deadlines that flow across service boundaries.
The effect is that work for doomed requests gets cancelled early, freeing
capacity for requests that can still succeed. It's backpressure expressed as
time rather than count, and it composes beautifully across a distributed
system.

## Queues are buffers, not solutions

None of this means queues are bad. A bounded queue is a wonderful **buffer** —
it smooths out short bursts so you don't reject work you could easily have
handled a moment later. The distinction is:

- A **buffer** absorbs *transient* mismatches between arrival and service rate.
  It's small, bounded, and drains quickly.
- A **dam** is what you've built when the queue is large and the consumer is
  *chronically* slower than the producer. It doesn't smooth anything; it just
  delays the failure and makes it bigger.

If your queue depth has a healthy sawtooth — filling during bursts, draining
between them — it's a buffer doing its job. If queue depth trends monotonically
upward, you don't have a backpressure problem you can tune away. You have a
capacity problem, and the queue is hiding it from you until the crash.

## The mindset shift

The hardest part of backpressure isn't technical. It's accepting that **a fast
`503` is a better outcome than a slow timeout.** Engineers hate returning
errors; it feels like giving up. But under genuine overload, an honest,
immediate "no" lets the caller retry elsewhere, fail over, or degrade
gracefully. A queue that swallows the request and produces a timeout 30 seconds
later gives the caller nothing but wasted time.

> Saying no quickly is a feature. The systems that stay up under load are the
> ones that learned to refuse work *before* they fell over, not after.

Bound your queues. Limit your concurrency. Shed deliberately. Propagate
deadlines. And make peace with the `503` — it's the sound of a system
protecting itself.

---

*Devin Osei keeps databases honest in Toronto. Next time you add a queue, ask
him where the limit is.*
