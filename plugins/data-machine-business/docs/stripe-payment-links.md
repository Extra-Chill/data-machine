# Stripe Payment Links (Phase 3)

`datamachine/stripe-stage-payment-link` stages a one-time or recurring Stripe
Payment Link for **human approval**. Staging makes no Stripe calls. The link is
created only when a human approves the pending action, and the request is checked
against hard limits both when it is staged and again at approval.

The resulting `https://buy.stripe.com/...` URL can be sent directly, or passed as
`payment_url` to `datamachine/create-invoice` to render a pay button. For a
recurring link, Stripe charges the customer automatically after their first payment.

## Configuration

Create a **restricted key** in Stripe with **write** access to **Products**,
**Prices** and **Payment Links**, and nothing else. It is stored in its own
option (`datamachine_stripe_payment_link_config`), separate from the read-only
reporting key. `sk_` secret keys are rejected.

```bash
wp datamachine stripe payment-link config --api-key=<rk_...>
```

### Limits

Stored in `datamachine_stripe_payment_link_limits` and filterable through
`datamachine_stripe_payment_link_limits`:

| Key | Default | Meaning |
|---|---|---|
| `max_amount` | `500` | Largest charge per payment, in major units (inclusive) |
| `currencies` | `['usd']` | Allowed currencies |

```bash
wp option update datamachine_stripe_payment_link_limits '{"max_amount":1000,"currencies":["usd"]}' --format=json
```

Lowering a limit also blocks requests that are already pending, because approval
re-checks against the limits in force at that moment.

## Staging

```bash
wp datamachine stripe payment-link stage --name="Hosting + support" --amount=50 --interval=month
```

| Field | Rules |
|---|---|
| `name` | Required, single line, ≤ 250 characters |
| `description` | Optional, ≤ 500 characters |
| `amount` | Positive, in major units (`50` = $50.00), ≤ `max_amount` |
| `currency` | Default `usd`, must be in `currencies` |
| `interval` | `once` (default), `day`, `week`, `month`, `year` |
| `interval_count` | Integer; at most 365 days, 52 weeks, 12 months or 1 year |

The result includes the `action_id`, a summary, and signed **approve** / **reject**
URLs that expire after two days. Unapproved requests simply expire; nothing was
created in Stripe, so there is nothing to clean up.

## Approval

The request can be approved by opening the signed approve URL, by a site administrator,
or by a CLI operator through `wp datamachine pending-actions`. No agent grant is
staged, so an agent cannot approve its own request.

On approval the handler:

1. consumes the one-time authorization receipt (an approval cannot be replayed),
2. re-validates the request against the current limits and confirms the amount is unchanged,
3. creates a Product, a Price (recurring if requested) and a Payment Link with
   quantity 1.

Each Stripe request carries an `Idempotency-Key` derived from the staged
`request_id` and the step, so a retried apply cannot create a second product,
price or link. Stripe errors are mapped to stable codes (`stripe_authentication`,
`stripe_permission`, `stripe_rate_limit`, `stripe_api_error`) without echoing
Stripe's error text or the key, and the chain stops at the first failure.

## Non-goals

No charging cards directly, no refunds, no customer or card data, and no checkout
of its own. Stripe hosts the payment page.

## Smoke test

```bash
php tests/stripe-payment-link-smoke.php
```
