# Contributing

This repository is a monorepo of WordPress block tooling. Each package is developed and released independently:

- `packages/blocks-engine` — `@automattic/blocks-engine` (JS/TS engine + CLI)
- `php-transformer` — `automattic/blocks-engine-php-transformer` (Composer)
- `figma-transformer`

## Reporting bugs

Open an issue using the bug-report template. Include the package affected, the version, a minimal reproduction, and what you expected versus what happened.

## Developing `@automattic/blocks-engine`

```sh
cd packages/blocks-engine
pnpm install        # install dependencies (uses the committed lockfile)
pnpm build          # build dist via tsup
pnpm test           # run the vitest suite
```

The worker pool forks Node child processes, so a development environment must support `child_process` fork + IPC. When running tests or tools against the built output, run `pnpm build` first so the worker child file is present in `dist`.

## Pull requests

- Branch off `trunk`.
- Keep changes scoped to one package per PR where practical; CI runs per-package on paths under that package.
- Use Conventional Commits (`feat`, `fix`, `docs`, `build`, `test`, `refactor`, `chore`) with a package scope, e.g. `fix(blocks-engine): …`.
- Add or update tests for behavior changes. The suite must pass before review.
- Public API and `BlocksEngineError` `code` values are a stable contract — call out any change to them in the PR description and the package `CHANGELOG.md`.

## Releasing

Releases are tagged per package (`blocks-engine-v*`, `php-transformer-v*`, `figma-transformer-v*`). See the package's own release notes/tooling for the current process.
