# Plugin Check v01740 Slow-Query Audit

Evidence source: `.homeboy/evidence/plugin-check-v01740/data-machine.json` entries 1866-2118. This dossier is limited to those 15 SlowDBQuery findings; it does not claim coverage of other Plugin Check findings.

## Finding Counts

| Rule | Before | After |
| --- | ---: | ---: |
| `slow_db_query_meta_key` | 8 | 0 |
| `slow_db_query_meta_query` | 4 | 0 |
| `slow_db_query_tax_query` | 2 | 0 |
| `slow_db_query_meta_value` | 1 | 0 |
| Total | 15 | 0 |

## Audit

| Evidence location | Source relationship | Change kind | Review conclusion and evidence boundary |
| --- | --- | --- | --- |
| `UpsertPostAbility.php:854` | Legacy fallback after unusable direct-execute identity input | Exact suppression | Valid identities use the plugin-owned, indexed post-identity-reservations table. This fallback preserves historical direct-execute behavior; no replacement query was added. |
| `QueryWordPressPostsAbility.php:80,338` | Ability schema and defaults | Exact suppression | Both are data declarations, not runtime queries. The actual taxonomy assignment at line 174 already has a local rationale. |
| `PostQueryAbilities.php:28,32,39` | Static filter metadata | Exact suppression | These are configuration fields, not runtime queries. |
| `PostQueryAbilities.php:277` | Runtime recent-managed-post list | Exact suppression plus behavior test | The canonical handler marker lives in WordPress-owned `wp_postmeta`; a plugin index would require write-path, backfill, and legacy-consistency ownership. Coverage verifies only tracked posts and the total. |
| `PostQueryAbilities.php:389` | Runtime combined list filters | Exact suppression plus behavior test | Canonical tracking meta is required; pipeline IDs are already resolved from the owned flows table. Coverage verifies AND semantics and total. |
| `PostQueryAbilities.php:477` | Runtime single filter list | Exact suppression | Canonical tracking meta is required; no independent plugin-owned index can preserve the existing contract without duplicate-state ownership. Existing handler, flow, pipeline, and pagination tests cover this family. |
| `MetaDescriptionCommand.php:81` | CLI response payload | Exact suppression | Response field, not a query. |
| `PostIdentityReservations.php:111,112` | Normalized identity payload | Exact suppression | Data fields, not queries. The reservation table is the indexed ownership boundary for valid identity upserts. |
| `AltTextTask.php:127`, `InternalLinkingTask.php:228`, `SystemTask.php:847` | Undo-effect target/result payloads | Exact suppression | Data fields, not queries. |

## Verification

Executed from the repository root after `composer install --no-interaction --prefer-dist` restored the lockfile dependencies:

```sh
vendor/bin/phpcs inc/Abilities/Content/UpsertPostAbility.php inc/Abilities/Fetch/QueryWordPressPostsAbility.php inc/Abilities/PostQueryAbilities.php inc/Cli/Commands/MetaDescriptionCommand.php inc/Core/Database/PostIdentityReservations/PostIdentityReservations.php inc/Engine/AI/System/Tasks/AltTextTask.php inc/Engine/AI/System/Tasks/InternalLinkingTask.php inc/Engine/AI/System/Tasks/SystemTask.php tests/Unit/Abilities/PostQueryAbilitiesTest.php
vendor/bin/phpunit tests/Unit/Abilities/PostQueryAbilitiesTest.php
git diff --check
```

All three commands passed. `php -l` also passed for every changed PHP file. Plugin Check remains the authoritative confirmation for the declared evidence boundary; the `wordpress.plugin-check` runner is unavailable in this checkout, so no fresh Plugin Check artifact was claimed. Re-run it on the packaged plugin and confirm zero findings for the four listed SlowDBQuery rules.

## AI Disclosure

GPT-5.6 Sol via OpenCode/Homeboy was used for implementation and verification; Chris Huber remains responsible for every line.
