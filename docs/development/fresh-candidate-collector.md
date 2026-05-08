# Fresh Candidate Collector

Source-agnostic primitive for fetch handlers that paginate or scan an external
source and need to skip already-processed and currently-claimed candidates
while continuing to look for fresh work.

Located at `DataMachine\Core\Steps\Fetch\FreshCandidateCollector`.

## Why this exists

Data Machine core owns final dedupe/claim/cap in
`FetchHandler::get_fetch_data()`. That layer is authoritative — but it only
runs *after* a handler returns. When the top of a paginated feed is full of
items the flow has already processed, naive handlers come back with "no new
work" even when fresh items live two pages deeper.

Multiple downstream consumers grew their own ad-hoc workarounds for the same
shape — overfetch windows, ability-level pagination filters, handler-level
processed-list prefilters. The fresh candidate collector is the generic core
primitive those consumers can converge on.

## Selection-time vs authoritative dedupe

| Layer | Where | What it does |
|---|---|---|
| Selection-time (this primitive) | Inside `executeFetch()` | Lets the handler stop scanning once it has enough fresh candidates. Skips items that are processed or actively claimed. |
| Authoritative dedupe + claim + cap | `FetchHandler::get_fetch_data()` | Final filter that runs after the handler returns. Re-checks processed/claim state, applies `max_items`, atomically claims. |

The two layers run on the same `ExecutionContext` and the same
`datamachine_should_reprocess_item` filter, so they agree on what counts as
"fresh". The collector is purely an early-exit aid — handlers stay correct
even if they skip it entirely.

## Usage

```php
use DataMachine\Core\Steps\Fetch\FreshCandidateCollector;

protected function executeFetch( array $config, ExecutionContext $context ): array {
    $max_items = (int) ( $config['max_items'] ?? 5 );
    $collector = new FreshCandidateCollector( $context, $max_items );

    foreach ( $this->paginate( $config ) as $candidate ) {
        $collector->offer(
            (string) $candidate['id'],
            array(
                'title'    => $candidate['title'],
                'content'  => $candidate['body'],
                'metadata' => array( 'item_identifier' => (string) $candidate['id'] ),
            )
        );

        if ( $collector->isFull() ) {
            break;
        }
    }

    // If pagination terminated naturally, mark exhaustion so diagnostics reflect it.
    $collector->markExhausted();

    $context->log( 'debug', 'Fresh candidate scan complete', $collector->getDiagnostics() );

    return array(
        'items' => $collector->getAccepted(),
    );
}
```

## API summary

- `offer( string $identifier, mixed $payload = null ): bool` — submit a candidate. Returns true when accepted.
- `isFull(): bool` — true when `max_items` reached. Always false when `max_items` is 0 (unlimited).
- `markExhausted(): void` — handler signals that pagination walked the source end-to-end.
- `isExhausted(): bool`
- `count(): int` — accepted count.
- `getMaxItems(): int`
- `getAccepted(): array<int,mixed>` — payloads in offer order.
- `getDiagnostics(): array` — counters and exhaustion flag (see below).

## Diagnostics

`getDiagnostics()` returns:

| Key | Meaning |
|---|---|
| `raw_seen` | Total candidates offered (excluding empty identifiers). |
| `accepted` | Candidates that passed all checks. |
| `processed_skipped` | Candidates skipped by `ExecutionContext::isItemProcessed()`. |
| `claimed_skipped` | Candidates skipped by `ExecutionContext::isItemClaimed()`. |
| `duplicate_skipped` | Same identifier offered twice in this scan. |
| `reprocess_accepted` | Accepted candidates whose row already existed in the processed table — i.e. the `datamachine_should_reprocess_item` filter forced a revisit. |
| `max_items` | Configured target. 0 = unlimited. |
| `source_exhausted` | True when the handler called `markExhausted()`. |

These are intentionally cheap integers + a bool. Handlers can log them
verbatim, push them into engine data, or surface them through the fetch
result envelope without further shaping.

## Non-goals

- Not a replacement for final fetch dedupe — `FetchHandler` still claims and
  caps after `executeFetch()` returns.
- Not aware of source-specific concepts (Reddit subreddits, MGS event types,
  RSS GUID formats, etc.). Identifiers are opaque strings.
- Not concerned with how the handler paginates. The collector only sees the
  candidates the handler chooses to offer.
