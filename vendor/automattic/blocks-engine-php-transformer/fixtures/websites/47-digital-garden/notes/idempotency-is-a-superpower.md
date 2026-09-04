---
title: "Idempotency Is a Superpower"
aliases: ["Idempotency", "Idempotent Operations"]
created: 2024-03-01
updated: 2026-06-10
tags: [evergreen, distributed-systems, engineering]
---

# Idempotency Is a Superpower

An operation is **idempotent** if doing it twice has the same effect as doing it once.
`DELETE /users/42` is idempotent. `POST /charge?amount=50` usually is not — and that
difference is the cause of a depressing fraction of all production incidents.

This is, for my money, the single highest-leverage idea in distributed systems, because
it dissolves a problem that is otherwise *unsolvable*: in any system that can drop or
duplicate messages, you cannot know whether your request actually happened. See
[[The Two Generals Problem]] for the proof that this uncertainty is fundamental, not a bug
you can fix with a better network.

## Why it's unavoidable

The classic failure: a client sends "charge $50," the charge succeeds, but the
*acknowledgement* is lost. The client, seeing no ACK, retries. Now the customer is charged
twice. You cannot remove this risk by retrying *less* (you'll lose legitimate charges) or
retrying *more* (you'll double-charge). The only escape is to make the *operation* safe to
repeat.

## The pattern: idempotency keys

```python
def charge(idempotency_key: str, amount_cents: int):
    # First writer wins; the unique index is the source of truth.
    existing = db.charges.find_one({"key": idempotency_key})
    if existing:
        return existing            # replay the original result, do nothing new

    result = payment_gateway.charge(amount_cents)
    db.charges.insert_one({        # unique index on `key` makes the race safe
        "key": idempotency_key,
        "amount": amount_cents,
        "result": result,
    })
    return result
```

The client generates the key *once* (a UUID per logical attempt) and reuses it across
retries. The server promises: same key → same outcome, exactly once. Stripe's API is built
entirely on this idea, and it's why you can hammer retry on a flaky connection without fear.

## The deeper move

Idempotency works by **shifting the burden from delivery to identity**. You stop trying to
guarantee "this message arrives exactly once" (impossible — see
[[The Two Generals Problem]]) and instead guarantee "this *intent* is applied exactly
once." Delivery becomes at-least-once, which networks *can* provide, and dedup happens at
the destination.

## Where else this shows up

- **Database migrations** should be re-runnable. `CREATE TABLE IF NOT EXISTS`.
- **Infrastructure-as-code** (Terraform) is desired-state, i.e. idempotent by design.
- **My compost bin**, genuinely — see [[Compost Is a Distributed System]]. Throwing in a
  banana peel twice doesn't break the pile; the system converges on the same end state.

## The Stoic footnote

There's a quiet [[The Dichotomy of Control]] lesson here. You can't control whether the
network delivers your message. You *can* control whether your system is harmed by
not knowing. Designing for "I may have to do this again and that must be fine" is the
engineering form of "focus on what's up to you."

## See also

- [[The Two Generals Problem]]
- [[Why I Stopped Trusting Wall Clocks]]
- [[The Dichotomy of Control]]
- [[index]]
