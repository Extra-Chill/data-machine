# Merged-bill test fixtures

Real production examples of the merged-bill duplicate pattern, pulled from
events.extrachill.com (issue #256). Each pair describes the same show
scraped twice — once per headliner — with mutual lineup mentions in the
post bodies.

| File | Verdict | Notes |
|---|---|---|
| `pair-maraluso-emma-grace-a.txt` / `-b.txt` | `merge` | Posts 5366 + 6504. Royal American, 2026-05-15 21:00. Mutual mention, identical end_datetime, identical price. |
| `pair-local-nomad-babe-club-a.txt` / `-b.txt` | `merge` | Posts 12359 + 219469. Royal American, 2026-06-05 21:00. Mutual mention. |

Use these in PHPUnit tests via `file_get_contents( __DIR__ . '/...' )` and
either feed them as `post_content` to `wp_insert_post()` or feed the
extracted body text directly to scoring helpers.
