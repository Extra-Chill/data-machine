# Data Machine

[![Try Data Machine in WordPress Playground](https://img.shields.io/badge/Try_Data_Machine_in-WordPress_Playground-3858e9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/Extra-Chill/data-machine/main/plugins/data-machine/blueprints/playground.json)

**Data Machine turns a WordPress site into a place where AI agents do real work.** Agents get a persistent identity and memory, pipelines that fetch, process and publish content, typed abilities they can call, and human approval for anything risky. All of it is native WordPress: plugins, the Abilities API, REST, WP-CLI and wp-admin.

Data Machine runs [Extra Chill](https://extrachill.com), an independent music platform, in production. Its agents import shows from venue sites into an events calendar, publish to social accounts, report on analytics and search, write daily memory, and handle the operational busywork across an 11-site network. The generic agent runtime underneath it, [Agents API](https://github.com/Automattic/agents-api), started inside Data Machine and was extracted so any WordPress plugin can build on it.

Click the badge above to try core in your browser. Nothing is installed, and nothing persists after you close the tab.

## What's in this repository

One core plugin and three extensions built on it. Each is a separate WordPress plugin with its own version, releases and activation, so a site installs only what it needs.

| Plugin | What it adds |
|---|---|
| [`data-machine`](plugins/data-machine) | **Core.** Pipelines, flows and jobs; agent identity and layered markdown memory; chat, pipeline and system agent modes; policy-gated tools with pending-action approvals; abilities, REST, WP-CLI and admin. |
| [`data-machine-events`](plugins/data-machine-events) | **Events.** AI-assisted import from Ticketmaster, Eventbrite, ICS, venue websites and more, with deduplication, venue geocoding and a block-based calendar. |
| [`data-machine-socials`](plugins/data-machine-socials) | **Social.** Publishing, reading and engagement across X, Facebook, Instagram, Threads, Bluesky, Mastodon, LinkedIn, Pinterest, Tumblr, TikTok, YouTube and Reddit. |
| [`data-machine-business`](plugins/data-machine-business) | **Business.** Google Analytics, Search Console, Bing Webmaster Tools, PageSpeed, Google Sheets and Drive, Slack, Discord, Sendy, Mediavine, Amazon Associates and media maintenance, as abilities and agent tools. |

The extensions double as the reference for building your own: each registers handlers, abilities and tools through core's public hooks, the same way a third-party plugin would.

## How the pieces fit

```text
data-machine-events  data-machine-socials  data-machine-business  (your extension)
          \                    |                    /                   /
           +---------- public hooks, abilities, handlers -------------+
                                   |
                         data-machine (core)
          pipelines, flows, jobs, memory, modes, tools, approvals
                                   |
                        Agents API (substrate)
          agent contracts, conversation loop, transcripts, memory stores
                                   |
                    wp-ai-client / AI provider plugins
```

Core never knows which extensions exist, and extensions depend on core alone, never on each other. `tools/check-boundaries.sh` enforces both rules on every pull request. `tools/boundary-allowlist.txt` lists the core files that already crossed that line when the monorepo was created, and the list only shrinks.

Having everything in one repository means a change that spans core and an extension is one pull request, and every extension's tests run against the core in the same tree.

## Installing

Install from release zips. The repository root is not itself a plugin.

- **Core:** [`releases/latest/download/data-machine.zip`](https://github.com/Extra-Chill/data-machine/releases/latest/download/data-machine.zip). Only core claims GitHub's "Latest" release, so this URL always serves core.
- **Extensions:** the `<plugin>.zip` asset on that plugin's newest `<plugin>-vX.Y.Z` release, for example [`data-machine-events-v0.79.0`](https://github.com/Extra-Chill/data-machine/releases/tag/data-machine-events-v0.79.0).

Each plugin's directory is its WordPress slug: `plugins/data-machine-events` installs to `wp-content/plugins/data-machine-events`. Start with the core [README](plugins/data-machine/README.md) and its [docs](plugins/data-machine/docs).

## Development

- **CI:** each plugin has its own workflow (`.github/workflows/<plugin>.yml`, plus `data-machine-test.yml` for core's MySQL suite) that runs its lint and tests using that plugin's `homeboy.json`. A change to core re-runs every extension's suite. `monorepo.yml` runs the boundary check.
- **Releases:** on every merge, `release.yml` releases each plugin with unreleased commits since its own `<plugin>-vX.Y.Z` tag: a version bump, changelog, GitHub Release and zip for each. Only commits under a plugin's directory count toward its release. Core's history before the monorepo is tagged `vX.Y.Z`.
- **Issues:** file them here and label them with the plugin, for example `plugin: data-machine-events`.

## History

`data-machine-events`, `data-machine-socials` and `data-machine-business` began as separate repositories. Their full histories, tags (renamed `<plugin>-vX.Y.Z`) and open issues moved here, and the original repositories are archived. The move followed the same playbook as the [Extra Chill monorepo](https://github.com/Extra-Chill/extrachill).

## License

GPL v2 or later, declared in each plugin's header.
