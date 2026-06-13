# Admin Scale Fixture

Data Machine owns a reusable WP-CLI fixture for profiling admin surfaces at pipeline/flow scale.

The fixture creates bounded pipelines, flows, and step configs through Data Machine repository APIs. Downstream rigs should invoke the fixture command instead of instantiating Data Machine database classes or writing `datamachine_*` tables directly.

## Setup

```bash
wp datamachine fixtures admin-scale setup \
  --seed-slug=profile-run \
  --pipeline-count=25 \
  --flows-per-pipeline=10 \
  --steps-per-flow=4 \
  --payload-size=512 \
  --format=json
```

`setup` replaces existing fixture records for the seed by default, so repeated runs with the same seed are idempotent. Pass `--no-replace` only when intentionally appending another fixture batch.

## Cleanup

```bash
wp datamachine fixtures admin-scale cleanup --seed-slug=profile-run --format=json
```

Cleanup removes only records created for the matching fixture seed slug.

## Config

- `--seed-slug`: URL-safe seed used for idempotent replacement and cleanup.
- `--pipeline-count`: number of fixture pipelines.
- `--flows-per-pipeline`: number of flows attached to each fixture pipeline.
- `--steps-per-flow`: number of step config entries per pipeline and flow.
- `--payload-size`: bytes of deterministic payload attached to each step config.

The fixture bounds these values before writing records so profiling rigs cannot accidentally create unbounded local data.
