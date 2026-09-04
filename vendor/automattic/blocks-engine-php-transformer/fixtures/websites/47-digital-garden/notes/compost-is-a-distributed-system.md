---
title: "Compost Is a Distributed System"
aliases: ["Compost", "Composting"]
created: 2025-04-22
updated: 2026-06-15
tags: [budding, gardening, distributed-systems]
---

# Compost Is a Distributed System

I did not expect my compost bin to teach me systems design, but here we are. A compost pile
is a self-organizing, fault-tolerant, eventually-consistent distributed system, and once
you see it you can't unsee it. This is the note that most embodies why I keep a garden the
way I do — see [[On Tending a Digital Garden]] on why a note is allowed to be *both* a
gardening note and a systems note.

## The mapping

| Compost | Distributed system |
|---|---|
| Billions of microbes, no boss | Nodes with no central coordinator |
| Bacteria, fungi, worms, each doing one job | Microservices, single responsibility |
| You add scraps in any order | Commutative, order-independent operations |
| Pile converges to dark crumbly soil | **Eventual consistency** |
| A rotten patch doesn't kill the pile | **Graceful degradation / fault tolerance** |

## Idempotency in the bin

Here's the one that delights me. Composting is **idempotent** in exactly the sense from
[[Idempotency Is a Superpower]]: if you toss the same banana peel in twice (you forgot you
already did it), the pile doesn't break. The end state — soil — is the same. There's no
"this scrap was processed twice" corruption, because the operation's *effect* is
convergent, not counted. The bin doesn't need an idempotency key because decomposition is
naturally convergent. Software has to *engineer* what the compost gets for free.

## No global clock

A compost pile also has no [[Why I Stopped Trusting Wall Clocks]] problem and no
[[The Two Generals Problem]], and the reason is instructive: **it never needs global
agreement.** Each microbe acts on local information only. There's no moment where the whole
pile must "decide" anything in unison. The hardest problems in distributed systems all come
from *requiring* coordination; the compost is robust precisely because it requires none.

The engineering lesson, stolen from a bucket of worms: *the cheapest way to solve a
coordination problem is to redesign the system so it doesn't need coordination.*

## What to actually do (the gardening note part)

- **Carbon : nitrogen ≈ 30:1.** "Browns" (leaves, cardboard) to "greens" (scraps, grass).
  Too much nitrogen → ammonia stink. Too much carbon → nothing happens.
- **Turn it** to re-introduce oxygen; anaerobic pockets are the rotten-node failures.
- **Patience.** Three to six months. You cannot rush eventual consistency.

This patience, by the way, is pure [[Amor Fati]] applied to soil — you don't resent the six
months, you trust the process and let the microbes mingle, which is the same mingling
Ahrens describes for notes in [[Book — How to Take Smart Notes]].

## See also

- [[Idempotency Is a Superpower]]
- [[Three Sisters Planting]]
- [[On Tending a Digital Garden]]
- [[index]]
