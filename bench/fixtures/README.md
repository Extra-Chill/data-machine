# Data Machine Benchmark Fixtures

Benchmark fixtures are repository-owned setup helpers for profiling environments. They are not plugin runtime APIs and are not registered as WP-CLI commands.

## Admin Scale

`admin-scale.php` creates bounded Data Machine pipelines, flows, and step configs for admin profiling environments.

Run setup through `wp eval-file` from a WordPress install with Data Machine active:

```bash
wp eval-file /path/to/data-machine/bench/fixtures/admin-scale.php -- setup \
  --seed-slug=profile-run \
  --pipeline-count=25 \
  --flows-per-pipeline=10 \
  --steps-per-flow=4 \
  --payload-size=512
```

Run cleanup with the same seed:

```bash
wp eval-file /path/to/data-machine/bench/fixtures/admin-scale.php -- cleanup --seed-slug=profile-run
```

The fixture defines the benchmark setup and cleanup contract. Callers should invoke this file from benchmark setup and cleanup phases instead of constructing Data Machine database classes or writing `datamachine_*` tables directly. Setup deletes existing records for the seed, then recreates them through Data Machine repository APIs; cleanup deletes records for that same seed.
