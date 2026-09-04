---
title: "The Write-Ahead Log Is the Database"
date: 2024-02-12T08:30:00Z
lastmod: 2024-02-14T10:00:00Z
author: mara
draft: false
description: "Almost every durable storage system you use is, underneath, a write-ahead log with some indexes bolted on top. Here's why that framing makes everything else click."
tags: ["storage", "durability", "databases", "internals"]
series: ["Storage Internals"]
images: ["/images/wal-cover.png"]
---

There's a moment in every storage engineer's education where the whole field
suddenly collapses into a single idea. For me it was realizing that the
**write-ahead log** isn't a feature of a database. It *is* the database. The
tables, the indexes, the MVCC machinery — those are derived, rebuildable views
over the one thing that's actually authoritative: an append-only log of what
happened.

Once you see it, you can't unsee it.

## The problem WAL solves

Durability has an annoying property: you can't make a write durable and fast
at the same time using the obvious approach. The obvious approach is "update
the data in place and flush it to disk." But your data lives in a B-tree
spread across thousands of pages. A single logical write — increment a
counter, insert a row — might touch several pages in random locations.

Flushing random pages to disk on every commit is catastrophic for
performance. Spinning disks hate random I/O, and even SSDs charge you for it
in write amplification and latency tails.

The write-ahead log is the trick that breaks the tradeoff:

> Before you touch the real data, append a record describing the change to a
> sequential log, and `fsync` *that*. Only the log needs to be durable at
> commit time. The actual pages can be flushed lazily, in the background,
> whenever it's convenient.

You've converted random writes into sequential ones. Commit latency is now
the cost of appending to a file and forcing it to disk — which is about as
cheap as durability gets.

## The recovery contract

The log earns its keep at recovery time. When the process crashes — and it
*will* crash; that's the whole premise — the on-disk data pages are in some
unknown, half-updated state. Some changes made it; some didn't. You can't
trust any of it.

So you don't. You replay the log:

1. Find the last checkpoint (a known-good marker).
2. Walk forward through every log record after it.
3. Re-apply each change to the data pages.
4. Roll back anything from transactions that never committed.

This is the famous **ARIES** algorithm in a nutshell: *redo everything, then
undo the losers.* The log is the source of truth; the data files are just a
cache of the log's effects that happens to be slow to rebuild from scratch.

```python
def recover(log, pages):
    checkpoint = log.last_checkpoint()
    # Redo phase: bring pages up to the end of the log
    for record in log.records_after(checkpoint):
        if record.lsn > pages.get_lsn(record.page_id):
            pages.apply(record)
    # Undo phase: revert uncommitted transactions
    losers = log.transactions_without_commit()
    for record in log.records_reversed():
        if record.txn_id in losers:
            pages.apply(record.compensation())
```

The detail that makes this correct is the **LSN** — the log sequence number.
Every page stores the LSN of the last log record applied to it. During redo
you skip any record whose LSN is already reflected in the page. That's what
makes replay **idempotent**: you can crash *during recovery*, restart, and
replay again without double-applying anything.

## fsync is the whole ballgame

Here's where people get burned. The log only works if the log is actually on
disk when you tell the client "committed." And "on disk" is a slippery
phrase, because there are at least three places your bytes can be hiding:

| Layer | Survives process crash? | Survives power loss? |
|-------|:----:|:----:|
| Application buffer | no | no |
| OS page cache (after `write`) | **yes** | no |
| Physical media (after `fsync`) | yes | **yes** |

A bare `write()` only copies bytes into the kernel's page cache. If the
machine loses power, those bytes evaporate, and your "durable" commit was a
lie. You need `fsync()` (or `fdatasync`, or `O_DSYNC`) to force the data
through to stable storage.

And then there's the truly cursed layer below that: the drive's own write
cache. Consumer SSDs have been caught acknowledging `fsync` before the data
hit flash, which is great for benchmarks and terrible for your data. This is
why serious systems care about whether the drive honors **FUA** (Force Unit
Access) and power-loss protection capacitors.

The lesson Jim Gray gave us decades ago still holds:

> Anyone can make a system fast if it doesn't have to be correct.

## Group commit: the obvious optimization

If every commit does its own `fsync`, your throughput is capped by how many
`fsync`s per second your disk can do — often just a few thousand. That's
brutal when you have thousands of concurrent transactions.

The fix is **group commit**. Instead of each transaction forcing the log
independently, you batch them: a bunch of transactions append their records,
then a *single* `fsync` makes all of them durable at once. The cost of
durability gets amortized across the whole batch.

The tradeoff is latency. A transaction now waits a few milliseconds for the
batch window to fill. You're trading a little per-request latency for a large
gain in aggregate throughput — which is almost always the right call for a
busy system, and exactly the wrong call for a quiet one. Tune accordingly.

## Why this framing pays off

Once you internalize "the log is the database," a lot of modern systems stop
looking exotic:

- **Replication** is just shipping the log to another machine and replaying
  it there. Postgres streaming replication, MySQL binlog, and Raft's
  replicated log are all the same idea at different altitudes.
- **Change Data Capture** is reading the log and turning each record into an
  event for downstream consumers. Debezium is, fundamentally, a log reader.
- **Event sourcing** is the application deciding to *keep* the log forever and
  treat it as the model of record, rather than as a recovery detail.
- **LSM trees** (which I'll cover in [a later post](/posts/lsm-vs-btree/)) lean
  into this even harder: the log-structured part *is* the storage layout.

It's logs all the way down. The next time a storage system confuses you, ask
the question that unlocks everything: *where is the log, and what does replay
look like?* The answer is usually the design.

---

*Corrections welcome — email [mara@coldstart.dev](mailto:mara@coldstart.dev).
Next in this series: how LSM trees and B-trees make opposite bets about your
workload.*
