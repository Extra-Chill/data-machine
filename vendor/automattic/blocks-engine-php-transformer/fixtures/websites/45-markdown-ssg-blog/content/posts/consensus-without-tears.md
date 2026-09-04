---
title: "Consensus Without Tears: What Raft Actually Promises"
date: 2024-05-28T08:45:00Z
author: mara
draft: false
description: "Distributed consensus has a reputation for being impossibly hard. Raft made it merely difficult. Here's the mental model that makes Raft click — and the operational truths the papers leave out."
tags: ["distributed-systems", "consensus", "raft", "internals"]
series: ["Distributed Systems"]
images: ["/images/raft-cover.png"]
---

For years, distributed consensus meant Paxos, and Paxos meant a paper so
famously opaque that its author wrote a *second* paper just to explain the
first one. Then in 2014, Diego Ongaro and John Ousterhout published Raft with
an explicit design goal of **understandability**, and the field exhaled.

Raft still isn't *easy*. But it's understandable, and that's a genuine
achievement. Let me give you the mental model I wish I'd had when I started,
and then the operational reality that the paper, being a paper, doesn't dwell
on.

## The problem, stated plainly

You have a value that needs to live on multiple machines so it survives any one
of them dying. The machines must agree on that value — and on the *order* of
changes to it — even though:

- Machines crash and restart at arbitrary times.
- The network drops, delays, duplicates, and reorders messages.
- There is no shared clock you can trust.

That last point is the killer. Without a shared clock, "which write happened
first?" has no objective answer. Consensus is, fundamentally, the art of
manufacturing agreement on order out of a system that has no inherent order.

The theoretical bad news is **FLP impossibility**: in a fully asynchronous
system with even one faulty process, no consensus algorithm can guarantee it
will *always* terminate. Raft doesn't break FLP — it sidesteps it by relying on
timeouts and accepting that during certain failures, it'll pause progress
rather than make a wrong decision. *Safety over liveness, always.*

A Raft node is always in exactly one of three states, and the whole protocol is
the choreography of moving between them:

| State | Sends | Receives | Transitions to |
|-------|-------|----------|----------------|
| Follower | nothing | `AppendEntries`, vote requests | Candidate (on election timeout) |
| Candidate | vote requests | votes | Leader (majority) or Follower (sees newer term) |
| Leader | `AppendEntries` (heartbeats + entries) | acks | Follower (sees a higher term) |

## The three ideas

Raft decomposes consensus into three subproblems, and understanding each in
isolation is the whole trick.

### 1. Leader election

At any time, exactly one node is the **leader**; the rest are **followers**.
All writes go through the leader. This is a deliberate simplification — instead
of every node negotiating with every other node (the Paxos nightmare), you
elect a single coordinator and route everything through it.

Each node has an election timer. If a follower doesn't hear from the leader
before its timer fires, it assumes the leader is dead, increments the **term**
(a logical clock — a monotonically increasing integer), and becomes a
**candidate** asking for votes. A node votes for the first valid candidate it
sees in a given term, and a candidate that wins a **majority** becomes leader.

The elegant detail: election timeouts are **randomized**. If they were fixed,
every follower would time out simultaneously, all become candidates at once,
split the vote, and fail to elect anyone — repeatedly. Randomization makes one
node usually time out first and win cleanly. (Notice this is the same jitter
idea from [the retries post](/posts/retries-make-outages-worse/). Randomization
breaking up synchronized herds is a recurring theme in this business.)

### 2. Log replication

Once elected, the leader takes client writes, appends them to its own log, and
ships them to followers via `AppendEntries` messages. A log entry is considered
**committed** once a majority of nodes have it durably stored. Only then does
the leader apply it to the state machine and tell the client "done."

```
Leader log:   [ x=1 | y=2 | x=3 | z=9 ]
                                  ▲
                            commit index (majority have through here)
```

The majority requirement is the heart of it. With `2f+1` nodes you can tolerate
`f` failures, because any two majorities must overlap in at least one node —
and that overlapping node carries the committed history forward. This is why
consensus clusters are almost always odd-sized: 3, 5, 7. A 4-node cluster
tolerates the same single failure as a 3-node one while needing more nodes to
agree. Even sizes buy you nothing but coordination cost.

### 3. Safety

The subtle guarantees that make it *correct*:

- **Election restriction:** a candidate can only win if its log is at least as
  up-to-date as the voter's. This guarantees a new leader already has every
  committed entry — you can never elect a leader that's missing committed
  history.
- **Log matching:** if two logs contain an entry with the same index and term,
  they're identical up to that point. Followers reject `AppendEntries` that
  don't line up, forcing the leader to back up and re-sync.

Together these ensure that once an entry is committed, it's in the log of every
future leader, forever. That's the promise: **committed means durable across
leadership changes.**

## What the paper doesn't emphasize

The algorithm is correct. Operating it is where the real lessons live.

**A majority must be alive to make progress.** This is the one that surprises
people. A 3-node cluster that loses 2 nodes doesn't run read-only — it stops
accepting writes entirely, because it can't form a majority. Availability for
*writes* requires a live quorum. If you need to survive losing two nodes, you
need a 5-node cluster, full stop.

**Consensus is slow on purpose.** Every committed write requires a network
round trip to a majority and a durable `fsync` on each of them (yes — the
[write-ahead log](/posts/write-ahead-log/) again, this time replicated). This
is *much* slower than a local write. People reach for Raft reflexively and then
act shocked at the latency. Use consensus for the things that genuinely need
it — cluster membership, leader locks, configuration, small critical metadata —
not for your high-throughput data path.

**The leader is a bottleneck and a blast radius.** All writes funnel through
one node. That's a throughput ceiling, and when the leader dies, you eat an
election timeout (typically 150–300 ms) of unavailability before a new one
takes over. Tune those timeouts: too short and a brief network blip triggers
needless elections; too long and failover drags.

**Membership changes are genuinely dangerous.** Adding or removing nodes while
the cluster is live can, done naively, briefly create *two* disjoint
majorities — split brain, the exact thing consensus exists to prevent. Raft
handles this with **joint consensus**, a careful two-phase transition. If your
implementation hand-rolls membership changes, read that section three times.

## When you actually need it

Be honest about whether you need consensus at all. You need it when you require
**strong consistency with automatic failover** — a single source of truth that
stays correct and keeps serving even as individual machines die. etcd,
Consul, CockroachDB, and the control planes of countless systems are built on
exactly this.

You *don't* need it when eventual consistency is fine, or when a single primary
with async replicas (and a human in the failover loop) meets your durability
bar. Consensus is a powerful, expensive tool. Reaching for it when you don't
need strong consistency is how you end up with a slow system *and* a complicated
one.

> Raft's gift wasn't a new capability — Paxos could already do everything Raft
> does. Its gift was making the capability *teachable*, so that ordinary
> engineers could build, operate, and debug consensus systems without a PhD.
> That's not a small thing. Understandability is a feature.

---

*This wraps the spring run of posts. If there's a distributed systems topic
you want torn apart, tell me: [mara@coldstart.dev](mailto:mara@coldstart.dev).*
