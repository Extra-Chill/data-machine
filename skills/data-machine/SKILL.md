---
name: data-machine
description: "AI-powered WordPress operations engine. Discover everything via: wp datamachine → wp help datamachine <group> → wp help datamachine <group> <subcommand>"
compatibility: "WordPress 6.9+ with Data Machine plugin. WP-CLI required."
---

# Data Machine

AI-powered WordPress operations engine — system tasks, content pipelines, and agent memory. All inside WordPress.

## Discovery

Data Machine is fully discoverable via WP-CLI. **Do not memorize commands from this file.** Use the CLI:

```bash
wp datamachine                                    # all command groups
wp help datamachine <group>                       # subcommands in a group
wp help datamachine <group> <subcommand>          # flags, options, examples
```

Singular/plural aliases work interchangeably: `flow`/`flows`, `job`/`jobs`, `link`/`links`, etc.

The running plugin is the documentation. When DM adds features, the CLI reflects them immediately. This file never needs updating.

## Concepts

DM has two layers:

**System Tasks** — built-in operations that work on your WordPress site directly. No setup required. Run the command, get the result. Explore command groups to see what's available.

**Pipelines & Flows** — automated workflows that run on schedules. Pipeline = template (defines steps). Flow = instance (adds schedule + config). Job = single execution. Explore with `wp help datamachine pipelines` and `wp help datamachine flows`.

## Memory

Agent files are injected as system context into every DM AI call. Explore with `wp help datamachine agent`.

## Start Here

Run `wp datamachine` and read what comes back. That's the map.
