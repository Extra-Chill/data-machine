# PHP Transformer

PHP Transformer is a PHP primitive for converting source content and generated website artifacts into WordPress-native block outputs.

This package is intentionally origin-clean: it exposes transformer primitives and result contracts without publishing compatibility wrappers or product adapters for downstream plugins.

This package's canonical identity is `automattic/blocks-engine-php-transformer`, exposed through the `Automattic\BlocksEngine\PhpTransformer\` namespace.

For local WordPress consumers, the same directory can also be installed as a plugin. The plugin bootstrap is intentionally thin: it loads the canonical library and exposes helper functions that return the same result envelopes as the class APIs.

## Boundary

PHP Transformer owns reusable transformation primitives:

- HTML to parsed WordPress block arrays.
- Markdown, HTML, and blocks conversion through a block-array pivot.
- Generated website artifact normalization.
- Serializable block output, document output, asset manifests, diagnostics, fallbacks, and provenance.
- Generic per-call context and provenance metadata for downstream wrappers.
- Generic conversion report projections for fallback diagnostics, source selectors, asset references, navigation candidates, presentation signals, and metrics.
- WordPress runtime adapters for calls that require WordPress APIs.

PHP Transformer does not own product workflows such as importer admin screens, uploaded ZIP intake, theme activation, Studio-specific orchestration, WordPress.com deployment behavior, or self-improving loop control. Product-specific compatibility wrappers belong in downstream packages, not in the canonical package API.

## Namespace Map

- `HtmlToBlocks` - low-level HTML to core block transforms.
- `FormatBridge` - declared-format normalization and format-to-format conversion.
- `ArtifactCompiler` - generated artifact bundle normalization and compilation.
- `WordPress` - runtime adapters around WordPress functions.
- `Contract` - shared result envelopes and diagnostics.

## Public API Surface

Consumers should treat these classes and interface as the public entrypoints for the current package:

- `Contract\TransformerResult` - stable result envelope. Use `toArray()` when passing results across process, HTTP, fixture, or compatibility boundaries.
- `HtmlToBlocks\HtmlTransformer` - converts supported HTML elements into WordPress block arrays and serialized block markup. Unsupported top-level HTML is reported in `fallbacks`.
- `FormatBridge\FormatBridge` - normalizes and converts declared `html`, `markdown`, and serialized `blocks` content through `convertResult()`.
- `FormatBridge\FormatAdapterInterface` - adapter contract for adding formats to `FormatBridge` when a consumer genuinely needs a package-level extension point.
- `ArtifactCompiler\ArtifactCompiler` - normalizes generated website artifact bundles into the shared result envelope, including block markup, source reports, assets, components, documents, and block type artifacts.
- `WordPress\Runtime` - adapter for WordPress functions used by the transformer when running inside or outside WordPress.

The remaining classes in `src/HtmlToBlocks`, `src/FormatBridge`, and `src/ArtifactCompiler` are implementation details. Concrete bundled adapters, registries, normalizers, and factories may change as the bridge expands.

### Canonical Examples

```php
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$htmlResult = (new HtmlTransformer())->transform('<h1>Hello</h1>', array(
    'source' => 'fixture:home-html',
    'scope' => 'import-preview',
))->toArray();

$formatResult = (new FormatBridge())->convertResult('# Hello', 'markdown', 'blocks', array(
    'context' => array(
        'strict'          => true,
        'allow_fallbacks' => false,
    ),
))->toArray();

$artifactResult = (new ArtifactCompiler())->compile(array(
    'generated_html' => '<main><h1>Hello</h1></main>',
))->toArray();
```

### WordPress Plugin Usage

Install or symlink the `php-transformer/` directory into `wp-content/plugins/blocks-engine-php-transformer/`, run `composer install --no-dev` inside the plugin directory when Markdown conversion is needed, and activate **Blocks Engine PHP Transformer**.

The plugin does not register product workflows. It only makes the canonical transformer available to WordPress plugins that want to depend on it before Composer distribution is settled.

```php
$result = blocks_engine_php_transformer_transform_html('<h1>Hello</h1>', array(
    'source' => 'plugin:consumer',
    'scope'  => 'import-preview',
));

$artifact = blocks_engine_php_transformer_compile_artifact(array(
    'generated_html' => '<main><h1>Hello</h1></main>',
));
```

Available plugin helpers:

- `blocks_engine_php_transformer_version()`
- `blocks_engine_php_transformer_path()`
- `blocks_engine_php_transformer_transform_html()`
- `blocks_engine_php_transformer_convert_format()`
- `blocks_engine_php_transformer_compile_artifact()`

### Diagnostics And Unsupported Paths

Public transformation entrypoints return `TransformerResult` wherever a conversion can partially succeed or needs structured diagnostics. Result diagnostics include a stable `code`, human-readable `message`, and `source` class.

### Transformation Options

Public entrypoints accept a generic options array. `source` and `scope` are copied into provenance metadata so wrappers can identify the caller-owned source without making the transformer package depend on that wrapper. The same values can be nested under `provenance`.

`context.strict` and `context.allow_fallbacks` are normalized into the result `context`. Top-level `strict` and `allow_fallbacks` are also accepted for simple callers. `HtmlTransformer` keeps default fallback behavior unchanged; callers that pass `allow_fallbacks => false` receive `success_with_warnings`, or `failed` when `strict` is also true and unsupported HTML is encountered.

`FormatBridge::convertResult()` forwards the original options array to adapters and exposes the normalized context/provenance metadata on the returned `TransformerResult`.

The result envelope includes generic `metrics` for wrapper reporting: `input_bytes`, `block_count`, `fallback_count`, `diagnostic_count`, `transform_duration_ms`, and `output_bytes`.

`source_reports.html.source_provenance` exposes bounded source context for converted blocks: selector, safe source attributes, sanitized source fragment, ancestor context, nearby heading text, safe `data-*` attributes, and generic structure hints such as card-like or grid-like wrappers. `source_reports.html.structure_signals` records those card/grid/static layout hints separately so callers can inspect them without relying on block attributes.

`source_reports.conversion_report` exposes a compact generic projection for wrappers that previously reconstructed report slices from lower-level result fields. It includes fallback diagnostics, sanitized fallback context, event attribute projections, source/selector summaries, asset references, navigation candidates, presentation and structure signals, and metrics. `source_reports.materialization_plan` exposes generic site-structure planning rows for routes, navigation links, and menus using source paths, target paths/slugs, titles/labels, parent/source relations, order, and kind. These reports remain product-neutral: callers still own route rewrites, media imports, theme assembly, navigation entity creation, visual repair policy, and acceptance gates.

`HtmlTransformer` preserves syntax-highlight spans inside `<pre><code>` when they use safe inline tags and bounded attributes, while plain code remains escaped as text. Figure-wrapped testimonials and quote shapes are normalized to core quote or pullquote blocks with attribution from `cite`, `footer`, or `figcaption` content.

Use `FormatBridge::convertResult()` for format conversions and unsupported source or target format diagnostics:

```php
$result = (new FormatBridge())->convertResult($html, 'html', 'blocks')->toArray();

if ('failed' === $result['status']) {
    $diagnosticCode = $result['diagnostics'][0]['code'] ?? '';
}
```

`FormatBridge::normalize()`, `FormatBridge::toBlocks()`, and `FormatBridge::convert()` remain available for compatibility wrappers that must preserve older string or array return types. New consumers should prefer `convertResult()` and read `documents`, `blocks`, `serialized_blocks`, and `diagnostics` from the result envelope.

## Artifact Compiler Fallbacks

The artifact compiler accepts loose generated-site bundles and normalizes them into an explicit result envelope. HTML entries are preserved as `core/html` serialized block markup, Markdown falls back to `core/html` when a Markdown adapter is not loaded, and MDX support is partial: source documents are preserved while imports and JSX component references are exposed as inspectable metadata and warnings.

Unsupported or unsafe artifact inputs are reported through diagnostics instead of hidden best-effort behavior. Empty, absolute, or root-escaping paths are rejected; oversized files are ignored according to the source report limits; and a bundle with neither an HTML entry nor source documents fails with `missing_entry_html`.

## Parity Checks

Run the package contract, parity fixtures, and clean package-install proof with `composer test`. The checked-in fixtures assert current transformer behavior, and the install proof verifies that Composer can install `automattic/blocks-engine-php-transformer` from the `php-transformer/` package root without symlinking back to the working tree.

## Release Consumption

The package lives in a subtree of the Blocks Engine repository. Composer cannot discover a package whose `composer.json` is below the repository root from a plain monorepo VCS tag. After release, consumers need either a subtree-split/Packagist package whose root is `php-transformer/`, or an explicit Composer `package` repository that points at the release archive and maps autoloading to the subtree.

This package intentionally omits `replace` and `provide` declarations for the older downstream package names. Those packages expose their own WordPress plugin bootstraps, functions, hooks, CLI commands, abilities, and product-shaped reports, so the canonical transformer package should not satisfy their Composer requirements directly.

Preferred downstream constraint once the package is published through Packagist or a subtree-split repository:

```sh
composer require automattic/blocks-engine-php-transformer:^0.1.0
```

If the first release is only available as a Blocks Engine monorepo archive, downstream consumers can avoid local path repositories with this repository entry, replacing `<release-tag>` with the pushed release tag:

```json
{
  "repositories": [
    {
      "type": "package",
      "package": {
        "name": "automattic/blocks-engine-php-transformer",
        "version": "0.1.0",
        "type": "library",
        "dist": {
          "type": "zip",
          "url": "https://codeload.github.com/Automattic/blocks-engine/zip/refs/tags/<release-tag>"
        },
        "autoload": {
          "psr-4": {
            "Automattic\\BlocksEngine\\PhpTransformer\\": "php-transformer/src/"
          }
        },
        "require": {
          "php": ">=8.1",
          "league/commonmark": "^2.5",
          "league/html-to-markdown": "^5.1"
        }
      }
    }
  ],
  "require": {
    "automattic/blocks-engine-php-transformer": "^0.1.0"
  }
}
```

Before the first tag is available, review branches may use a Composer VCS or path repository with an inline alias. Merge-ready downstream PRs should replace those review-only constraints with one of the no-local-path release shapes above.

### Release Readiness Checklist

Reviewer-safe checks before approving the first package release PR:

- Run `composer validate --strict` and `composer test` from `php-transformer/`.
- Confirm `VERSION`, `php-transformer.php`, and the intended tag all resolve to `0.1.0`.
- Confirm Packagist or the subtree split indexes `php-transformer/` as the package root, not the repository root.
- Confirm downstream merge candidates use `automattic/blocks-engine-php-transformer:^0.1.0` instead of path repositories, unpublished branches, or inline aliases.
- Keep the transformer metadata free of downstream wrapper package names, `replace`, and `provide` declarations.

Operator-only release checklist after the release-readiness PR merges:

- Choose the exact merged commit that should own the `0.1.0` tag.
- Run the Homeboy release dry-run from that merged commit.
- Create the package tag from the accepted release path.
- Publish or connect the Packagist/subtree-split package root if that is the chosen distribution path.
- Update downstream wrapper/product PRs from review-only constraints to tagged constraints.
- Decide whether GitHub Releases are part of this first package publication path.

Homeboy owns the local release preflight for this package through `php-transformer/homeboy.json`. The only version target is `VERSION`, currently `0.1.0`; release automation should tag from the package subtree after the upstream PRs are merged, without adding wrapper-package names to this package metadata.

Recommended post-merge dry-run:

```sh
homeboy release php-transformer --dry-run --skip-publish --no-github-release
```

Recommended first release command after the dry-run passes and the merge commit is ready to tag:

```sh
homeboy release php-transformer --skip-publish --no-github-release
```

Use `--skip-publish` because Composer/Packagist consumption should follow the repository tag, and use `--no-github-release` when GitHub Releases are not part of the first package publication path. Do not run release commands from downstream wrapper branches or while path repositories, unpublished branch constraints, or local-only evidence are still required by merge candidates.
