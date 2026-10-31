# HTML email invoices

`datamachine/create-invoice` previews or sends an invoice using Data Machine's
`datamachine/send-email` ability and the business-owned `invoice` email template.
No PDF renderer is needed; recipients can print their email to PDF.

## CLI

```sh
wp datamachine invoice create --data='{
  "bill_to": {"name":"Building 64","email":"billing@example.com"},
  "pay_to": {"name":"Contest winner","note":"Pay the winner directly"},
  "line_items": [{"description":"Skinful Halloween costume contest prize","unit_amount":1000}],
  "due":"Upon receipt",
  "payment_instructions":"Send $1,000 directly to the winner via Zelle.",
  "contact":"accounts@example.com"
}' --preview-file=/tmp/invoice.html
```

Add `--send` to deliver the invoice. `--preview-file` also works with `--send`:
it saves the HTML rendered for that delivery, not a separately numbered preview.
The CLI's `--send` flag controls delivery regardless of a `send` field in JSON.

## Input

- `bill_to`: object with `name`, `attn`, `email`, `address` (plain text, newlines allowed).
- `from`: optional object with the same fields and `url`; name and URL default to the current site.
- `pay_to`: optional third-party payee object with `name` and `note`.
- `line_items`: nonempty array of `description`, optional `detail`, `quantity`
  (positive, default 1), and `unit_amount` (nonnegative). Each extended line amount
  is rounded to cents before summing. Caller-supplied totals are not accepted.
- `currency`: three uppercase letters, default `USD`. Amounts use two decimals.
- `issued_date`: `YYYY-MM-DD`, defaults to today in the site timezone.
- `due`, `payment_instructions`, `notes`, `contact`: plain text strings.
  Contact defaults to the site administrator email.
- `invoice_number`: optional explicit number.
- `send`: boolean, default false. `to` defaults to `bill_to.email`; `cc` is optional.
  Both recipient fields accept the comma-separated strings supported by send-email.

The ability requires Data Machine management permission. Preview results contain
`invoice_number`, `total` (formatted string), `subject`, and `html`. Delivery returns
the send-email result unchanged. Delivery success means accepted by the mailer,
not confirmed receipt by the recipient.

## Numbering and branding

Automatic numbers use `INV-YYYY-NNNN`, with the current site-timezone year and a
per-site sequence stored in `datamachine_invoice_counter`. The sequence does not
reset at year boundaries. Only successful automatic sends advance it. Explicit
numbers do not affect the sequence. Preview numbers are tentative, not reserved.

Automatic sends serialize using `datamachine_invoice_counter_lock`. A competing
send returns an error and may be retried. A killed mail process deliberately
leaves the lock closed: verify whether the email was delivered and reconcile the
counter before removing the lock with `wp option delete datamachine_invoice_counter_lock`.
Do not blindly retry a delivery after a process crash.

The logo comes from `BrandTokens::get()['logo_path']`. Local upload, content, and
WordPress-root paths are mapped to their public URLs. Unmappable paths fall back
to site-name text. No filesystem paths are exposed to recipients.

## Tests

```sh
php tests/invoice-ability-smoke.php
```

These isolated unit checks mock the Abilities API and send-email delivery. They
exercise registration, computed totals, validation, numbering, escaped HTML,
logo resolution, recipient delegation, locking, failure recovery, and CLI output.
