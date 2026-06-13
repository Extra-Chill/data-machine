# Data Machine Benchmark Fixtures

Benchmark fixtures are repository-owned setup helpers for profiling rigs. They are not plugin runtime APIs and are not registered as WP-CLI commands.

## Admin Scale

`admin-scale.php` creates bounded Data Machine pipelines, flows, and step configs for admin profiling rigs.

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

The fixture deletes existing records for the seed before setup, then recreates them through Data Machine repository APIs. Downstream Homeboy Rigs should call this file from bench setup instead of constructing Data Machine database classes or writing `datamachine_*` tables directly.
