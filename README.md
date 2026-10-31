# Data Machine

[![Try Data Machine in WordPress Playground](https://img.shields.io/badge/Try_Data_Machine_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Extra-Chill/data-machine/main/plugins/data-machine/blueprints/playground.json)

Agentic workflow automation for WordPress: Data Machine core and its first-party extensions in one repository. Each one is still a separate WordPress plugin with its own version, changelog, release zip and activation. A site can run core on its own, and third parties can build on core alone. The monorepo makes the system easier to work on as a whole without merging the plugins together.

| Plugin | Path | What it is |
|---|---|---|
| `data-machine` | [`plugins/data-machine`](plugins/data-machine) | Core: pipelines, flows, jobs, abilities, agent memory, REST, WP-CLI and admin. Builds on [Agents API](https://github.com/Automattic/agents-api). |
| `data-machine-events` | [`plugins/data-machine-events`](plugins/data-machine-events) | Event import automation and a block-first calendar. |
| `data-machine-socials` | [`plugins/data-machine-socials`](plugins/data-machine-socials) | Social publishing, reading and engagement across platforms. |
| `data-machine-business` | [`plugins/data-machine-business`](plugins/data-machine-business) | Analytics, search, revenue, messaging and media-maintenance integrations. |

Directory names are WordPress plugin slugs: `plugins/data-machine-events` installs to `wp-content/plugins/data-machine-events`.

## Boundaries

Core is the generic layer. It must not know its extensions exist, and an extension depends on core only, never on a sibling. `tools/check-boundaries.sh` enforces both on every pull request. `tools/boundary-allowlist.txt` lists the core files that already crossed the line when the monorepo was created. That list only shrinks.

## Installing

Install from the release zips, not from a clone of this repository (its root is not a plugin):

- Core: `https://github.com/Extra-Chill/data-machine/releases/latest/download/data-machine.zip`. Only core claims GitHub's "Latest" release, so this URL keeps working.
- Extensions: the `<plugin>.zip` asset of that plugin's newest `<plugin>-vX.Y.Z` release, for example `data-machine-events-v0.79.0`.

## CI

Each plugin has its own workflow (`.github/workflows/<plugin>.yml`, plus `data-machine-test.yml` for core's sharded MySQL suite) that runs that plugin's own lint and tests with its own `homeboy.json`. Extensions are tested against the core in this tree, so a core change re-runs every extension's suite. `monorepo.yml` runs the boundary check.

## Releases

On every merge, `release.yml` releases each plugin that has unreleased commits since its own `<plugin>-vX.Y.Z` tag, one after another, each with its own version bump, changelog and GitHub Release. Only commits under a plugin's directory count toward its release. Core's history before the monorepo is tagged `vX.Y.Z`. The extensions' histories were imported with their tags renamed to `<plugin>-vX.Y.Z`.

## History

`data-machine-events`, `data-machine-socials` and `data-machine-business` were separate repositories. Their full histories were imported into this one, and the original repositories are frozen and will be archived. This follows the same playbook as the [Extra Chill monorepo](https://github.com/Extra-Chill/extrachill).
