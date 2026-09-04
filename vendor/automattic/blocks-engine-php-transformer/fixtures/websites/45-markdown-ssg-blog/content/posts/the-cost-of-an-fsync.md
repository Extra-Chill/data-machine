---
title: "The Cost of an fsync, Measured"
date: 2024-06-18T09:15:00Z
author: mara
draft: false
description: "Everyone says fsync is expensive. Few say how expensive, or why the number swings by four orders of magnitude depending on your hardware. A short, numbers-first field note."
tags: ["storage", "durability", "performance", "internals"]
series: ["Storage Internals"]
images: ["/images/fsync-cover.png"]
---

A short one this time — a field note rather than an essay. In the
[write-ahead log post](/posts/write-ahead-log/) I claimed `fsync` is "the whole
ballgame" for durability performance. A reader asked, reasonably: *fine, but
how expensive is it actually?* The honest answer is "it depends, by about four
orders of magnitude," and that range is worth understanding.

## What fsync actually does

`fsync(fd)` tells the kernel: *do not return until every dirty page for this
file, and the file's metadata, is on stable storage.* It's a barrier. Your
thread blocks until the bytes are durable. Everything about the cost flows from
what "durable" requires on your particular hardware.

## The numbers, roughly

These are order-of-magnitude figures from my own benchmarking over the years —
treat them as a mental model, not a spec sheet:

| Storage | Per-`fsync` latency | Durable writes/sec (single thread) |
|---------|--------------------:|-----------------------------------:|
| 7200 RPM HDD | ~8–10 ms | ~100 |
| Consumer SSD (no PLP) | ~1–5 ms | a few hundred to ~1,000 |
| Datacenter SSD (with PLP) | ~50–200 µs | ~5,000–20,000 |
| Battery-backed RAID cache | ~20–100 µs | tens of thousands |
| `fsync` lying (cache, no flush) | ~1–10 µs | "fast" and **wrong** |

That last row is the trap. A drive or filesystem that acknowledges `fsync`
before the data is truly durable looks fantastic on benchmarks and loses your
data on power failure. The gap between the bottom row and the rows above it is
the gap between a benchmark and a database.

**PLP** — power-loss protection — is the differentiator. Datacenter SSDs ship
with capacitors that hold enough charge to flush their internal cache when
power drops. That lets them *honestly* acknowledge `fsync` as soon as the data
hits their cache, because the cache itself is now durable. Consumer drives
without PLP must either flush all the way to flash (slow) or cheat (fast,
unsafe).

## Why this dictates architecture

Look at the HDD row: ~100 durable writes per second on a single thread. If
every transaction does its own `fsync`, that's your transaction ceiling. A
hundred TPS. This single number explains a huge amount of database design:

- **Group commit** (from the WAL post) exists precisely to break this ceiling
  by amortizing one `fsync` across many transactions.
- **The WAL itself** exists so you only pay for *sequential* `fsync`s, not a
  random one per dirtied page.
- **`fdatasync`** is preferred over `fsync` in hot paths because it skips the
  metadata flush when the file size hasn't changed, shaving a second seek.

Once you know the cost of an `fsync` on your hardware, you can predict your
durable write ceiling with a napkin. That's a powerful thing to be able to do
before you've written a line of code.

## The one-line takeaway

> Find out your hardware's honest `fsync` latency before you design anything
> durable. It's the speed of light for your write path — and if the number
> looks too good, your storage is probably lying to you.

Measure it yourself. `fio` with `--fsync=1` on the actual disk your database
will use, then divide:

```bash
fio --name=fsynctest --filename=/data/testfile \
    --rw=write --bs=4k --size=512M \
    --fsync=1 --direct=1 --runtime=30 --time_based
```

Read the reported `iops` line — that's your honest durable-write ceiling per
thread. Don't trust the spec sheet, and *really* don't trust the benchmark that
didn't flush.

---

*A short one, as promised. Back to longer essays next time.*
