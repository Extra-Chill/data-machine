---
title: "The Two Generals Problem"
aliases: ["Two Generals", "Coordinated Attack Problem"]
created: 2024-03-10
updated: 2026-01-22
tags: [budding, distributed-systems, theory]
---

# The Two Generals Problem

Two generals, A and B, are camped on opposite hills with the enemy in the valley between
them. They can only win if they attack *at the same time*. Their only way to communicate is
to send a messenger *through* the valley — where the messenger may be captured.

The question: can they ever agree on a time to attack?

**No.** Provably not. And the proof is short and devastating.

## The proof (by contradiction)

Suppose some finite exchange of messages `m₁, m₂, … mₙ` is sufficient to coordinate the
attack. Consider the *last* message, `mₙ`. Since any message can be lost, the sender of
`mₙ` cannot know it arrived. So `mₙ` cannot be necessary — the generals must have already
been committed before sending it. But then `mₙ₋₁` is now the last message, and the same
argument applies to it. By induction, *no* message is necessary, which means zero messages
suffice — absurd. Therefore no finite protocol works. ∎

## Why this is the most important "no" in computing

Almost every hard problem in distributed systems is the Two Generals Problem wearing a
costume:

- **"Did my payment go through?"** → see [[Idempotency Is a Superpower]]. We can't
  guarantee delivery, so we make the operation safe to repeat instead.
- **"Are these two database replicas in sync?"** → you can have consistency or
  availability under a partition, not both (CAP). The partition *is* the captured messenger.
- **"Did the other service get my event?"** → at-most-once vs at-least-once delivery.
  You must pick which failure you can tolerate; you cannot have exactly-once *delivery*.

## The practical lesson

You don't *solve* Two Generals. You **route around it.** The two industrial escape hatches:

1. **Make actions idempotent** so duplicate delivery is harmless ([[Idempotency Is a
   Superpower]]). This trades the impossible "exactly-once delivery" for the achievable
   "at-least-once delivery + exactly-once *effect*."
2. **Accept eventual agreement** instead of instantaneous agreement. Most systems don't
   actually need the generals to attack at the *same instant* — they need them to converge.

A surprising amount of engineering maturity is learning to recognize a Two Generals problem
early and stop trying to defeat physics. That recognition is itself a kind of
[[The Dichotomy of Control]]: some uncertainty is simply not up to you.

## See also

- [[Idempotency Is a Superpower]]
- [[Why I Stopped Trusting Wall Clocks]]
- [[index]]
