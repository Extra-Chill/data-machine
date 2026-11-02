# Stripe Reports (Phase 1)

The `datamachine/stripe-reports` ability provides bounded, read-only reporting via `DataMachine\Core\HttpClient`, using GET requests to `https://api.stripe.com/v1`. It requires `PermissionHelper::can_manage`, belongs to `datamachine-analytics`, and is not exposed in REST.

## Configuration

Create a **read-only restricted key** in Stripe with read access to subscriptions, customers, invoices, charges, and payouts. Grant no write permissions. Both `rk_live_` and `rk_test_` keys are accepted; `sk_` keys are rejected. Prefix validation cannot verify dashboard permissions.

```bash
wp datamachine analytics stripe config --api-key=<rk_...>
```

This stores `{api_key}` in the server-side `datamachine_stripe_config` option, with autoload disabled. Avoid exposing keys in shell history or process listings; securely provisioning the option is an alternative. Keys are never returned in reports/errors. Authenticated response-body previews are disabled.

## Reports

```bash
wp datamachine analytics stripe subscriptions --limit=25
wp datamachine analytics stripe mrr --limit=100 --format=json
wp datamachine analytics stripe renewals --days=7
wp datamachine analytics stripe past_due --format=json
wp datamachine analytics stripe charges --format=csv
wp datamachine analytics stripe payouts
```

All actions accept `--limit`, `--days`, and `--format=table|json|csv`. Days applies only to renewals (default 7, clamped to 1–365). Limit defaults to 25 and clamps to 1–100. For `subscriptions`, `charges` and `payouts` it bounds the source records fetched. `mrr`, `renewals` and `past_due` instead scan up to 1,000 source records per collection (ten pages of 100) so aggregates and filters cover the account, then apply the limit to output rows; `mrr` is never row-limited. Stripe cursors (`starting_after`) are followed up to ten pages per collection, and no request asks Stripe for more than 100 records.

| Action | Source and row grain |
|---|---|
| `subscriptions` | Active subscriptions; one row per subscription item with customer ID/email/name, quantity-adjusted amount, currency, interval/count, status, current period end and cancellation flag |
| `mrr` | Active subscription items aggregated by currency, in major units |
| `renewals` | Active subscription items with period end between now and now + days (inclusive) |
| `past_due` | Open and uncollectible invoices with due date before now, plus past_due subscription items; `type` distinguishes rows |
| `charges` | Most recent charges in Stripe list order: amount, currency, status, paid, refunded, customer ID, created |
| `payouts` | Most recent payouts in Stripe list order: amount, currency, status, arrival_date |

Mixed-interval subscriptions retain item grain. Customer email/name can be null for deleted/unexpanded customers. Period end uses the subscription item field on newer Stripe API versions, falling back to the subscription field. Truncated embedded item lists produce an error rather than silently undercounting.

MRR uses quantity-adjusted fixed recurring prices: monthly / interval_count; yearly / (12 × interval_count); weekly × 52 / (12 × interval_count); daily × 365 / (12 × interval_count). Zero-decimal currencies such as JPY are not divided by 100. Three-decimal currencies are divided by 1000. Currencies are never combined or converted. Discounts, taxes, usage, one-time charges and exchange rates are not modeled. Metered/tiered prices without a fixed amount cause a clear error rather than a guessed MRR.

## Output and completeness

Every successful action returns `success`, `action`, `results`, `results_count`, `counts` and `provenance`. JSON preserves the full envelope; table/CSV emits rows. Provenance identifies the source ability/API/method, as-of timestamp, limit, scan limit, page cap, and each collection's endpoint, status, fetched records, pages and truncation flag. `counts.source_records` is the number scanned and `counts.matched` the rows that matched before the output limit.

MRR, renewals and overdue items are account-wide up to the 1,000-record scan window. Beyond that, `provenance.complete=false` and the result is a lower bound; non-JSON CLI output warns on truncation.

Stripe authentication, permission, rate-limit and other API failures return sanitized `WP_Error` objects with HTTP status and stable codes. Raw Stripe/transport error text is not echoed.

No Stripe writes, invoice creation/changes, payment collection, or phase 2/3 behavior is implemented.

## Smoke test

```bash
php tests/stripe-reports-smoke.php
```
