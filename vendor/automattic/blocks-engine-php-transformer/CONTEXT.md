# blocks-engine (JS engine)

The deterministic engine for WordPress blocks, shipped as one pluggable package (`@automattic/blocks-engine`) with a React-free default entry and an opt-in in-process `/wp` entry. It owns two generic, consumer-agnostic capabilities — **HTML→blocks translation** and **deterministic theme assembly** (static directory → block theme) — and exposes extension seams; consumers (the first being the data-liberation-agent static-site importer) plug their own choices, including non-deterministic quality steps, into those seams. Sibling to the existing `php-transformer`, with which it does **not** target output parity.

(Identity note: the engine's scope deliberately expanded from "translation only" to "translation + theme assembly" — see ADR 0004, which supersedes the translation-only framing of ADR 0001.)

## Language

**Engine**:
The translation + theme-assembly core plus its extension seams. Owns generic logic; knows nothing about any one consumer.
_Avoid_: importer (that's the consumer)

**Theme assembly / Assembler**:
The engine's multi-**stage** process that turns a static site directory into a block theme on disk (ingest → foundation → section-extract → reconstruct → chrome → assets → plan → assemble). Run by the `siteToTheme` convenience command or by composing the stages directly.
_Avoid_: pipeline (reserved for the **consumer's** end-to-end run, e.g. DLA's). The engine has an *assembler*, the consumer has a *pipeline*.

**Stage**:
One isolated unit of the assembler with a frozen input→output contract (e.g. `foundation`, `sectionExtract`, `assemble`). Stages are public and composable; `siteToTheme` chains them.

**Hook**:
An optional async seam at a named stage (`onFoundation`/`onSection`/`onAssets`/`onRefine`) where a consumer injects a **non-deterministic** quality step (visual polish, asset triage, repair). Absent hook = deterministic identity (byte-identical to no hook).
_Avoid_: plugin, middleware.

**SectionSpec**:
The engine's shared contract describing one visual section (structure + style facts) consumed by reconstruction. Produced deterministically two ways: the engine's browser-free **cheerio** `sectionExtract` (best-effort, from static HTML), or **injected** by a consumer that captured richer computed-style specs (e.g. DLA's Playwright `extractFull`). Injected via the `sections` data input — NOT a hook (it is data, not a non-deterministic step).

**ThemeModel**:
The pure in-memory result of assembly (`styleCss`, `themeJson`, `templates`, `parts`, `patterns`, `assets`). Materialized to disk by `writeTheme`. Keeps the assembler snapshot-testable without disk.

**Converter**:
A pluggable unit (`(html, context) → block markup | null`) the engine applies to translate an HTML fragment. Built-ins ship with the engine; consumers may supply their own.
_Avoid_: recipe (a consumer's word for its platform-specific converters)

**ConversionContext**:
The context object threaded through conversion (e.g. source URL, media-URL map). Carries only generic fields — never consumer-specific state.
_Avoid_: BlockRecipeContext (the old consumer-coupled name)

**Fallback block**:
The block the engine emits for a fragment it can't convert. Defaults to `core/html`; a consumer overrides it via `htmlFallback` (a block name, or an emitter function).
_Avoid_: island (a consumer's term for its own fallback shape)

**Canonicalize**:
Normalizing block markup through `@wordpress/blocks` so WordPress's parser/validator accepts it on the way in. One of the two `@wordpress/blocks`-backed operations (with the rawHandler conversion); both are process-isolated because they pull in React.
_Avoid_: fix, normalize (use "canonicalize")

**Transformer**:
The PHP sibling package (`php-transformer`) — a separate engine with its own `TransformerResult` contract. Referenced only to say the JS engine does not mirror it.
