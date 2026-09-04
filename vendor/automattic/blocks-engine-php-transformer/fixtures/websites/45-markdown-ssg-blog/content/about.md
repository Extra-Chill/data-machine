---
title: "About Cold Start"
date: 2024-01-05T09:00:00Z
author: mara
draft: false
description: "Who writes Cold Start, why it exists, and what you can expect from it."
type: "page"
---

## Why this blog exists

Most writing about software engineering lives at one of two altitudes. Way up
high, you get think-pieces about culture, hiring, and the death of whatever
was popular last year. Way down low, you get API docs and Stack Overflow
answers. The middle layer — *how do real systems actually behave, and why* —
is weirdly under-served.

Cold Start lives in that middle layer. The name comes from the worst kind of
incident: the one where everything is down, the caches are empty, and you have
to bring a system back from nothing while it's getting hammered with traffic.
The cold start is where your design decisions finally get graded.

## What you'll find here

- **Storage internals.** Write-ahead logs, LSM trees, B-trees, page caches,
  and what "fsync" really costs you.
- **Distributed systems, honestly.** Consensus, replication, and the failure
  modes the papers gloss over.
- **Operational reality.** Backpressure, retries, timeouts, and the boring
  work of staying up.
- **Post-incident notes.** Sanitized, fictionalized, but grounded in the kinds
  of failures that actually happen.

## Who writes it

Cold Start is written mostly by **Mara Quintero**, a principal storage
engineer based in Lisbon, with occasional guest posts from **Devin Osei**, a
staff SRE in Toronto. Between us we've operated databases that you've probably
indirectly used and broken a few you haven't.

We are not selling anything. There's no course, no newsletter funnel, no
"book a call." If a post helped you avoid a 2 a.m. page, that's the entire
business model.

## Get in touch

Corrections are welcome and credited. Email
[mara@coldstart.dev](mailto:mara@coldstart.dev) or find us on
[Mastodon](https://hachyderm.io/@coldstart). Code samples on
[GitHub](https://github.com/coldstart-dev).

> The reward for operating a reliable system is that nobody notices it.
> The penalty for operating an unreliable one is that everybody does.
