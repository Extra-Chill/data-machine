# Changelog

All notable changes to `@automattic/blocks-engine` will be documented in this file.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Versioning

This package uses [Semantic Versioning](https://semver.org/). Deprecations are warned one minor version ahead of removal.

## [Unreleased]

### Fixed

- `analyzeRuntimeRegionEffects` (unreleased) now fails closed on unparseable source — the manifest carries a single whole-source unit with `reason: 'parse_failed'` instead of an empty, effect-free-looking unit list — and its shared-state detection registers every binding a top-level statement contributes outside function bodies (destructuring, `function`/`class` declarations, loop heads, nested blocks), which previously escaped it and could mark shared-state effects as independently suppressible. `getElementById` targets that are not plain CSS identifiers are emitted as escaped `[id="…"]` selectors.

## [0.2.2] - 2026-06-30

### Added

- `structuredStrategy` — a selectable reconstruction strategy that interprets the `SectionSpec` into clean, theme-styled canonical blocks first (`nativeDecision` → `renderCover`/`renderCardGrid`/`renderMediaText`/…), falling back to a verbatim `core/html` island only on coverage loss. Unlike the preserve-DOM default it does not preserve source classes or whole-section islands, so its output is self-contained and renders from the theme alone with no dependency on carried source CSS. This restores the pre-preserve-DOM fidelity for no-CSS-carry blocks pipelines (e.g. data-liberation's blocks reconstruct path) while leaving the carried-CSS paths (local-convert, theme-carry) on the existing default. Exposed from `@automattic/blocks-engine/theme`.

### Changed

- No change to default behavior. `reconstructNativeAggregate`'s default remains `defaultReconstructStrategy` (preserve-DOM); `structuredStrategy` is strictly additive and opt-in via `SectionRenderOptions.strategy`. The reconstruct/golden suite is byte-identical for callers that do not select it.

## [0.2.1] - 2026-06-29

### Changed

- The WordPress runtime (`@wordpress/block-library`, `@wordpress/blocks`, `@wordpress/block-serialization-default-parser`) is now compiled into a single self-contained CJS chunk shipped in `dist`, and `@wordpress/*` moved to `devDependencies`. A clean consumer install drops from ~491 MB to ~53 MB with no public API change. The runtime is loaded lazily through an internal resolver that prefers the bundle and falls back to real packages in development.
- Nine edit-only `@wordpress` leaf packages (icons, ui, dataviews, image-cropper, server-side-render, commands, preferences, notices, keyboard-shortcuts) are aliased to an empty stub at build time, since the engine never executes block `edit` components — only `save`, `transforms`, and attribute sourcing. Fidelity is unchanged: the full reconstruct/golden suite passes identically against the bundle.

### Fixed

- Aligned all `@wordpress/*` dependencies to a single coherent release, eliminating nested duplicate `node_modules` (previously a mismatched version set installed ~12 copies of some packages). This alone cut a clean install from ~2.0 GB to ~491 MB.

## [0.2.0] - 2026-06-29

### Added

- Static-HTML-to-block-theme reconstruction pipeline: section extraction, preserve-DOM-first reconstruction (native blocks that keep their source classes, with nested `core/html` islands for un-convertible elements), per-section rich-CSS routing, and content-addressed `lib-i` instance-style dedup.
- Carried source CSS is enqueued on the front end and loaded into the block editor via a generated `functions.php` (`add_editor_style`).
- Section extraction now captures designed non-heading bands such as marquees / ticker strips.
- Local and remote source images are carried into the theme, with SSRF and stored-XSS hardening on remote fetches.

### Fixed

- WordPress block gap is neutralized on carried full-bleed sections so they sit flush, while blocks added in the editor keep the default gap.
- Reveal-gated source CSS is neutralized so content is not left hidden.
- Lossy section source identity is preserved.

### Removed

- Unused `convert-semantic-sections` and `semantic-html` modules.

## [0.1.0] - 2026-06-24

### Added

- Async main convert API.
- `BlocksEngineError` for stable package errors.
- `/internals` subpath for supported internal consumers.
- `npx` CLI entrypoint.

### Fixed

- Export map now points to built `dist` artifacts.
