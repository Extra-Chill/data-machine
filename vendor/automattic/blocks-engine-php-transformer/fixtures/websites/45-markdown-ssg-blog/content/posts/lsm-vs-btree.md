---
title: "LSM Trees vs. B-Trees: A Bet About Your Workload"
date: 2024-03-04T07:45:00Z
author: mara
draft: false
description: "B-trees and LSM trees aren't competitors so much as opposite wagers on whether you read more than you write. Understanding the bet tells you which to reach for."
tags: ["storage", "databases", "internals", "performance"]
series: ["Storage Internals"]
images: ["/images/lsm-cover.png"]
---

If the [write-ahead log](/posts/write-ahead-log/) is how a database stays
durable, the **storage layout** is how it decides what to be good at. And the
two dominant layouts — the B-tree and the log-structured merge tree — make
nearly opposite bets.

You can summarize the whole debate in one sentence: *B-trees optimize for
reads and pay on writes; LSM trees optimize for writes and pay on reads.*
Everything else is detail. Let's get into the detail anyway.

## The B-tree bet: writes are rare, reads are everything

The B-tree (really the B+tree) has run the database world for fifty years
because it's a beautiful fit for spinning disks and read-heavy workloads. The
data lives sorted, in fixed-size pages, in a shallow balanced tree. Looking up
a key is a handful of page reads — `O(log n)` with a comfortably large base.

The cost shows up on writes. To update a key, you find its page, modify it,
and eventually write that page back. But pages are, say, 8 KB. If you change a
single 100-byte row, you still rewrite the whole 8 KB page. That's **write
amplification**, and under a random-write workload it compounds: scattered
updates dirty pages all over the tree, each flush forcing a full-page write.

B-trees also suffer **fragmentation** and need to update in place, which makes
them write to the same physical locations repeatedly — historically rough on
flash endurance.

So the B-tree's wager is: *I'll assume you read far more than you write, and
I'll make reads cheap and predictable.* For an OLTP system serving lots of
point lookups, that's a great bet. Postgres, MySQL/InnoDB, and SQLite all make
it.

## The LSM bet: absorb writes now, sort them out later

The log-structured merge tree starts from the opposite premise: *writes are
the bottleneck, so never do a random write at all.*

Every write goes into an in-memory sorted structure called the **memtable**
(usually a skip list or balanced tree). It's also appended to a WAL for
durability. Reads check the memtable first. When the memtable fills up, it's
frozen and flushed to disk **sequentially** as an immutable, sorted file
called an **SSTable** (Sorted String Table).

```
write ─▶ memtable (in RAM, sorted) ─┐
                                     ├─▶ flush ─▶ SSTable L0 ─▶ compaction ─▶ L1 ─▶ L2 ...
write ─▶ WAL (durability) ──────────┘
```

Crucially, you never modify an SSTable. Updates and deletes are just *new*
writes — a delete is a special marker called a **tombstone**. The newest value
wins. This means writes are always sequential appends, which is exactly what
both spinning disks and SSDs love.

The catch is reads. A key might live in the memtable, or in *any* of the
SSTables on disk. A naive read would have to check all of them. Two mechanisms
rescue this:

- **Bloom filters** — a tiny probabilistic index per SSTable that can say
  "definitely not here" without touching the file. They turn most negative
  lookups into a memory check.
- **Compaction** — a background process that merges SSTables together,
  discarding overwritten values and tombstones, keeping the number of files
  bounded.

## Compaction is where the bodies are buried

Compaction is the LSM tree's defining feature and its biggest operational
liability. It's the price you deferred at write time, coming due later.

There are two main strategies, and choosing between them is choosing your pain:

| | Leveled compaction | Tiered (size-tiered) compaction |
|---|---|---|
| **Read amplification** | low | higher |
| **Write amplification** | higher | lower |
| **Space amplification** | low | higher (transient duplicates) |
| **Good for** | read-heavy, space-constrained | write-heavy, space to spare |

RocksDB defaults to leveled; Cassandra historically defaulted to tiered.
Neither is wrong — they're tuned for different bets within the LSM family.

The operational gotcha: compaction competes with your live traffic for disk
I/O and CPU. If write volume outruns compaction's ability to keep up, the
number of L0 files grows, read amplification spikes, and latency falls off a
cliff. This shows up in real life as a database that's been fine for months
and then mysteriously falls over under a traffic spike. The culprit is almost
always **write stalls** triggered by compaction debt.

## So which do you pick?

Don't pick in the abstract. Pick by interrogating your workload:

1. **What's your read/write ratio?** Mostly reads → lean B-tree. Mostly
   writes → lean LSM.
2. **Are your writes random or sequential by key?** Heavy random inserts
   (time-series, event logs, IoT) are the LSM sweet spot.
3. **How tight is your space budget?** LSM with tiered compaction can use a
   lot of transient extra space. B-trees are more predictable.
4. **Can you tolerate latency tails from compaction?** If p99 matters more
   than throughput, the B-tree's steadier behavior may win.

A useful gut check: most general-purpose **relational** databases pick
B-trees because OLTP is read-skewed and latency-sensitive. Most **write-heavy
NoSQL and time-series** stores (Cassandra, ScyllaDB, RocksDB-backed systems,
InfluxDB) pick LSM because ingestion is the hard part.

## The honest summary

There is no free lunch in storage. The **RUM conjecture** makes it formal: you
can optimize at most two of **R**ead overhead, **U**pdate overhead, and
**M**emory (space) overhead — improving one of the three tends to cost you
another.

> A B-tree spends write effort to make reads cheap.
> An LSM tree defers write effort to make writes cheap, then pays it back
> during compaction.

Neither is "better." They're different answers to the question *what is this
system going to spend most of its time doing?* Answer that honestly and the
choice usually makes itself.

---

*Next up: why your retry logic is probably making outages worse, not better.*
