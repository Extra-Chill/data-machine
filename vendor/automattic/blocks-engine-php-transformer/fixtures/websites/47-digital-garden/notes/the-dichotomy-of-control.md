---
title: "The Dichotomy of Control"
aliases: ["Dichotomy of Control", "What Is Up To Us"]
created: 2023-12-01
updated: 2026-06-01
tags: [evergreen, stoicism, philosophy]
---

# The Dichotomy of Control

The opening line of Epictetus' *Enchiridion*:

> "Some things are within our power, while others are not. Within our power are opinion,
> motivation, desire, aversion... not within our power are our body, our property,
> reputation, office."

That's it. That's the whole foundation of Stoicism, and arguably the most useful sentence
anyone has ever handed me. The discipline is just: **figure out which bucket a thing is in,
and invest your energy accordingly.**

## The trap is the middle category

The naive version sorts the world into "mine" and "not mine." But the sharp version
(Epictetus is precise here) is about *the verb*, not the noun. You don't control your
**reputation** — that lives in other people's heads. You *do* control the **honest work**
that earns it. Confusing the two is the source of essentially all avoidable suffering:
chasing outcomes (not up to you) instead of efforts (up to you).

## Why an engineer keeps quoting a slave from 100 AD

Because distributed systems *are* the dichotomy of control, formalized.

- You **cannot control** whether the network delivers your message — that's
  [[The Two Generals Problem]], proven impossible.
- You **can control** whether your system is *harmed* by non-delivery — which is exactly
  what [[Idempotency Is a Superpower]] buys you.

Good engineering and Stoic practice are the same motion: identify the uncontrollable
(packet loss, clock drift, other people's opinions), stop fighting it, and instead design
your *response* to be robust to it. "Make the operation safe to retry" is just
"focus on what is up to you," compiled down to code. See also
[[Why I Stopped Trusting Wall Clocks]] — you can't control the clocks, so don't depend on
them.

## The everyday version

When something goes wrong, I run one query:

1. Is this up to me? → Act. Now. Fully.
2. Is this not up to me? → Drop it. Completely. Including the *wanting* it to be different.

Step 2 is where the freedom is, and it's the hardest. [[Amor Fati]] is the advanced move:
not merely *accepting* the uncontrollable but actively *loving* it.

## See also

- [[Amor Fati]]
- [[Idempotency Is a Superpower]]
- [[The Two Generals Problem]]
- [[On Tending a Digital Garden]]
- [[index]]
