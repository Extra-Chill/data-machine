# Amazon Associates Reports

`datamachine/amazon-associates-reports` reports Amazon Associates affiliate revenue
from imported Associates Central reports. It requires `PermissionHelper::can_manage`,
belongs to `datamachine-analytics`, is not exposed in REST, and makes no network calls.

## Why import-based

Amazon Associates has no public earnings or reporting API. The Creators API and
PA-API 5 (used by the `amazon_affiliate_link` tool) cover the product catalog only.
Reports come from **Associates Central → Reports → Download** as CSV, TSV or XML.
Automated logins to Associates Central are deliberately out of scope (ToS and 2FA
risk to the paying account).

The parser (`ReportParser::parse`) converts text to records. A separate
normalizer (`ReportParser::normalizeRecords`) converts records to storage rows,
so a future live fetcher can feed the normalizer directly.

## Importing

```bash
wp datamachine analytics amazon import --file=Fee-Earnings-2026-10.csv
wp datamachine analytics amazon import --file=Fee-Orders-2026-10.tsv --dry-run
```

The ability also accepts `content` (raw report text) instead of `file`.

- CSV and TSV are accepted. XML is rejected with a clear error.
- A UTF-8 BOM and the leading title line (`Fee-Earnings reports from 2026-10-01 to 2026-10-31`) are handled.
- Headers match case-insensitively. `($)`-style suffixes are ignored, and the symbol sets the currency. Without a symbol, the currency comes from the marketplace.
- The report type is detected from the headers, or set with `--report-type`. Unknown columns are ignored. A missing required column produces an error naming it.
  - Earnings: `Category, Name, ASIN, Seller, Tracking ID, Date Shipped, Price($), Items Shipped, Returns, Revenue($), Ad Fees($), Device Type Group`. Required: ASIN, Tracking ID, Date Shipped, Items Shipped, Revenue($), Ad Fees($).
  - Orders: `Category, Name, ASIN, Date, Qty, Price($), Link Type, Tag, Indirect Sales, Device Type Group`. Required: ASIN, Date, Qty.
- Lines without an ASIN or a parseable date, such as total rows, are skipped and counted.

**Idempotency.** Each import covers one window for its report type and
marketplace. The window comes from `--start-date`/`--end-date`, then the title
line, then the row dates, and is widened to include every row date. All
stored rows for that report type, marketplace and window are deleted before the
new rows are inserted. Re-importing the same report gives the same row count.

## Reading

```bash
wp datamachine analytics amazon summary --start-date=2026-10-01 --end-date=2026-10-31 --format=json
wp datamachine analytics amazon earnings --tracking-id=extrachill-20 --limit=25
wp datamachine analytics amazon orders
wp datamachine analytics amazon coverage
```

Reads default to the window from 28 days ago through yesterday (UTC). `--tracking-id`
and `--marketplace` filter all reads.

| Action | Result |
|---|---|
| `summary` | `totals` per currency and `by_tracking_id`: `ad_fees` (our commission), `revenue` (shipped sales), `items_shipped`, `returns`, `ordered_items`, plus row counts |
| `earnings` / `orders` | Row-level reads, newest first, bounded by `--limit` (default 100, max 1000) |
| `coverage` | Imported date ranges per report type and marketplace, plus first/last dates per tracking ID |

Money is returned as decimal strings with a currency. Totals are summed exactly
and never converted between currencies.

## Provenance and coverage

Every result includes `source: amazon_associates`, `action`, `date_range`,
`row_count`, `fetched_at`, `imported_at` and a `coverage` block. For a requested
window, the coverage block reports `complete`, `covered_through` and `gaps` for each
report type. When the window isn't fully covered, `warnings` say so. A missing
import is never reported as a real $0. Top-level `coverage.complete` follows the
earnings report, which carries the commission.

## Storage

Two network-scoped tables (base prefix) are created lazily on first use:

- `{base_prefix}datamachine_amazon_associates_rows`: one row per report line (`report_type`, `report_date`, `tracking_id`, `asin`, `name`, `category`, `seller`, `price`, `items`, `returns`, `revenue`, `ad_fees`, `device_type`, `link_type`, `indirect`, `currency`, `marketplace`, `import_batch`, `imported_at`).
- `{base_prefix}datamachine_amazon_associates_imports`: the import-window ledger behind coverage. It's needed because days with no sales have no rows.
