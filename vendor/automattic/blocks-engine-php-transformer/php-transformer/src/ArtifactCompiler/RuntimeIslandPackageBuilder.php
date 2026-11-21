<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;

/**
 * Generic, product-neutral producer for the runtime-island package.
 *
 * The HTML transformer preserves DOM regions it cannot losslessly convert to
 * native blocks as "runtime islands" — verbatim source markup plus the script
 * assets and behavior metadata that drive them (see
 * {@see \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackEmitter}).
 * That data is preserved as measurement/diagnostic metadata; nothing packages it
 * into an actionable carry-forward envelope.
 *
 * This class is that seam. It projects the compiled-site runtime-island data
 * into a generic package a downstream materializer can map to its own runtime:
 * per island it carries a stable id, the verbatim markup, the associated script
 * assets (external src + scoped inline JS), a preserve-vs-rebuild signal, and a
 * role classification so telemetry scripts can be dropped rather than carried.
 *
 * Boundary: this package is intentionally product-neutral. It names no consumer,
 * no plugin, no host product. A downstream consumer maps this generic data to its
 * own materialization payload — per-block render + scoped script enqueue — on its
 * own side. Keeping product semantics out of the engine is a hard rule.
 *
 * Preserve-vs-rebuild (blocks-engine #224): runtime islands carry verbatim source
 * markup, so the JS associated with them may be carried verbatim too
 * (`js_handling = preserve_verbatim`). Transformed regions are NOT islands — they
 * became native blocks and their original JS is dropped by the transformer — so
 * they never reach this package. Telemetry/analytics scripts are classified by
 * role and marked droppable: their disposition is `drop`, not `preserve`.
 */
final class RuntimeIslandPackageBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/runtime-island-package/v1';

    /**
     * Generic third-party telemetry/analytics signals. Matched against script
     * src and inline body so analytics beacons can be dropped rather than carried
     * into the materialized site. These are generic vendor signals, not
     * host-product names.
     *
     * @var array<int, string>
     */
    private const TELEMETRY_SIGNALS = array(
        'googletagmanager',
        'google-analytics',
        'gtag',
        'gtm.js',
        'analytics.js',
        'ga.js',
        'doubleclick',
        'segment.com',
        'segment.io',
        'mixpanel',
        'hotjar',
        'fullstory',
        'amplitude',
        'plausible',
        'matomo',
        'piwik',
        'fbevents',
        'facebook.net',
        'fbq(',
        'clarity.ms',
        'newrelic',
        'nr-data',
        'rum',
        'sentry',
        'datadog',
        'cdn.heapanalytics',
    );

    /**
     * Build the runtime-island package from preserved runtime-island metadata.
     *
     * @param array<int, array<string, mixed>> $runtimeIslands Preserved islands from the compiled result.
     * @param array<int, array<string, mixed>> $files          Normalized artifact files (resolve external scripts).
     * @param string                           $sourcePath     Source document path (resolve relative script src).
     * @return array<string, mixed> Empty array when there are no runtime islands.
     */
    public function fromRuntimeIslands(array $runtimeIslands, array $files = array(), string $sourcePath = ''): array
    {
        $islands = array();
        foreach ( $runtimeIslands as $runtimeIsland ) {
            if ( ! is_array($runtimeIsland) ) {
                continue;
            }
            $island = $this->buildIsland($runtimeIsland, $files, $sourcePath);
            if ( array() !== $island ) {
                $islands[] = $island;
            }
        }

        if ( array() === $islands ) {
            return array();
        }

        return array(
            'schema'  => self::SCHEMA,
            'islands' => $islands,
            'totals'  => $this->totals($islands),
        );
    }

    /**
     * @param array<string, mixed>             $runtimeIsland One preserved runtime island.
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function buildIsland(array $runtimeIsland, array $files, string $sourcePath): array
    {
        $kind = is_scalar($runtimeIsland['kind'] ?? null) ? (string) $runtimeIsland['kind'] : '';
        $selector = is_scalar($runtimeIsland['selector'] ?? null) ? (string) $runtimeIsland['selector'] : '';
        $markup = is_scalar($runtimeIsland['source_snippet'] ?? null) ? (string) $runtimeIsland['source_snippet'] : '';
        if ( '' === $kind && '' === $selector && '' === $markup ) {
            return array();
        }

        $scripts = $this->scriptsForIsland($kind, $runtimeIsland, $files, $sourcePath);
        $disposition = $this->disposition($kind, $scripts);

        $island = array(
            'id'                  => $this->islandId($kind, $selector, $markup),
            'kind'                => $kind,
            'selector'            => $selector,
            'source_path'         => $sourcePath,
            'tag'                 => is_scalar($runtimeIsland['tag'] ?? null) ? (string) $runtimeIsland['tag'] : '',
            'markup'              => $markup,
            'markup_truncated'    => (bool) ($runtimeIsland['source_truncated'] ?? false),
            'markup_fidelity'     => 'verbatim',
            'preservation_reason' => is_scalar($runtimeIsland['preservation_reason'] ?? null) ? (string) $runtimeIsland['preservation_reason'] : '',
            'runtime_requirement' => is_scalar($runtimeIsland['runtime_requirement'] ?? null) ? (string) $runtimeIsland['runtime_requirement'] : '',
            'disposition'         => $disposition,
            'js_handling'         => 'drop' === $disposition ? 'drop' : 'preserve_verbatim',
            'handle_hint'         => $this->handleHint($kind, $selector, $markup),
            'attributes'          => is_array($runtimeIsland['attributes'] ?? null) ? $runtimeIsland['attributes'] : array(),
            'scripts'             => $scripts,
        );

        return array_filter($island, static fn (mixed $value): bool => '' !== $value && array() !== $value || is_bool($value));
    }

    /**
     * Resolve the script assets associated with an island.
     *
     * For a `script` island the island element IS the script — one entry built
     * from its attributes (external src) and verbatim inline body. For other
     * island kinds (canvas, form, template, control, ...) the associated scripts
     * are the transformer-recorded `required_scripts` references.
     *
     * @param array<string, mixed>             $runtimeIsland
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function scriptsForIsland(string $kind, array $runtimeIsland, array $files, string $sourcePath): array
    {
        $scripts = array();

        if ( 'script' === $kind ) {
            $attributes = is_array($runtimeIsland['attributes'] ?? null) ? $runtimeIsland['attributes'] : array();
            $sourceKind = is_scalar($runtimeIsland['script_source_kind'] ?? null) ? (string) $runtimeIsland['script_source_kind'] : '';
            if ( '' === $sourceKind ) {
                $sourceKind = '' !== trim((string) ($attributes['src'] ?? '')) ? 'external' : 'inline';
            }
            $scriptRole = is_scalar($runtimeIsland['script_role'] ?? null) ? (string) $runtimeIsland['script_role'] : 'runtime';
            $inline = '';
            if ( 'inline' === $sourceKind ) {
                // The island carries the verbatim inline body directly (see
                // FallbackEmitter::captureScriptFallback); fall back to parsing
                // the bounded snippet only if an older island shape omits it.
                $inline = is_scalar($runtimeIsland['script_body'] ?? null) ? trim((string) $runtimeIsland['script_body']) : '';
                if ( '' === $inline ) {
                    $inline = $this->inlineScriptBody((string) ($runtimeIsland['source_snippet'] ?? ''));
                }
            }

            $scripts[] = $this->buildScript($sourceKind, $attributes, $scriptRole, $inline, $files, $sourcePath);

            return $this->dedupeRows($scripts);
        }

        $required = is_array($runtimeIsland['required_scripts'] ?? null) ? $runtimeIsland['required_scripts'] : array();
        foreach ( $required as $requiredScript ) {
            if ( ! is_array($requiredScript) ) {
                continue;
            }
            $attributes = is_array($requiredScript['attributes'] ?? null) ? $requiredScript['attributes'] : array();
            $sourceKind = is_scalar($requiredScript['script_source_kind'] ?? null) ? (string) $requiredScript['script_source_kind'] : '';
            if ( '' === $sourceKind ) {
                $sourceKind = '' !== trim((string) ($attributes['src'] ?? '')) ? 'external' : 'inline';
            }
            $scriptRole = is_scalar($requiredScript['script_role'] ?? null) ? (string) $requiredScript['script_role'] : 'runtime';
            $inline = '';
            if ( 'inline' === $sourceKind ) {
                foreach ( array('script_body', 'body', 'content') as $field ) {
                    if ( isset($requiredScript[$field]) && is_scalar($requiredScript[$field]) && '' !== trim((string) $requiredScript[$field]) ) {
                        $inline = trim((string) $requiredScript[$field]);
                        break;
                    }
                }
            }
            $scripts[] = $this->buildScript($sourceKind, $attributes, $scriptRole, $inline, $files, $sourcePath);
        }

        return $this->dedupeRows($scripts);
    }

    /**
     * @param array<string, mixed>             $attributes
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function buildScript(string $sourceKind, array $attributes, string $scriptRole, string $inline, array $files, string $sourcePath): array
    {
        $src = trim((string) ($attributes['src'] ?? ''));
        $script = array(
            'source_kind' => '' !== $sourceKind ? $sourceKind : ('' !== $src ? 'external' : 'inline'),
            'script_role' => '' !== $scriptRole ? $scriptRole : 'runtime',
            'attributes'  => $attributes,
        );

        if ( 'external' === $script['source_kind'] && '' !== $src ) {
            $script['src'] = $src;
            $resolved = ArtifactPath::resolveRelativePath($src, $sourcePath);
            $content = $this->externalScriptContent($src, $resolved, $files);
            if ( '' !== $resolved ) {
                $script['resolved_path'] = $resolved;
            }
            $script['materialized'] = null !== $content;
            if ( null !== $content ) {
                $script['content'] = $content;
            }
        } elseif ( '' !== $inline ) {
            $script['content'] = $inline;
        }

        $role = $this->scriptRole($scriptRole, $src, (string) ($script['content'] ?? ''));
        $script['role'] = $role;
        if ( 'telemetry' === $role ) {
            $script['droppable'] = true;
        }

        return array_filter($script, static fn (mixed $value): bool => '' !== $value && array() !== $value || is_bool($value));
    }

    /**
     * Locate the verbatim content of an external script that was materialized as
     * an artifact file. Returns null when the script is third-party / not carried
     * (so the consumer knows to reference the original src instead of inlining).
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function externalScriptContent(string $src, string $resolved, array $files): ?string
    {
        $candidates = array_filter(array($resolved, ltrim($src, '/')), static fn (string $value): bool => '' !== $value);
        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! empty($file['binary']) ) {
                continue;
            }
            $path = is_scalar($file['path'] ?? null) ? (string) $file['path'] : '';
            if ( '' === $path || ! in_array($path, $candidates, true) ) {
                continue;
            }
            if ( is_scalar($file['content'] ?? null) ) {
                return (string) $file['content'];
            }
        }

        return null;
    }

    /**
     * Extract the verbatim inline JS body from a bounded `<script>` snippet. When
     * the snippet was truncated the closing tag may be absent, so fall back to
     * everything after the opening tag.
     */
    private function inlineScriptBody(string $snippet): string
    {
        if ( 1 === preg_match('/<script\b[^>]*>(.*)<\/script>/is', $snippet, $matches) ) {
            return trim((string) $matches[1]);
        }
        if ( 1 === preg_match('/<script\b[^>]*>(.*)$/is', $snippet, $matches) ) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    /**
     * Classify a script's role so telemetry can be dropped. `data` scripts
     * (JSON-LD, importmap, speculation rules) carried by the transformer keep
     * their data role; everything else is first-party unless it matches a generic
     * telemetry signal.
     */
    private function scriptRole(string $scriptRole, string $src, string $content): string
    {
        if ( 'data' === $scriptRole ) {
            return 'data';
        }

        $haystack = strtolower($src . "\n" . substr($content, 0, 4000));
        foreach ( self::TELEMETRY_SIGNALS as $signal ) {
            if ( str_contains($haystack, $signal) ) {
                return 'telemetry';
            }
        }

        return 'first_party';
    }

    /**
     * The island disposition: drop a telemetry-only `script` island, otherwise
     * preserve the verbatim island. Non-script islands always preserve their
     * markup; their individual telemetry scripts are marked droppable per entry.
     *
     * @param array<int, array<string, mixed>> $scripts
     */
    private function disposition(string $kind, array $scripts): string
    {
        if ( 'script' === $kind && array() !== $scripts ) {
            foreach ( $scripts as $script ) {
                if ( 'telemetry' !== ($script['role'] ?? '') ) {
                    return 'preserve';
                }
            }

            return 'drop';
        }

        return 'preserve';
    }

    /**
     * Stable, content-addressed island id so a consumer can key per-island state
     * deterministically across runs.
     */
    private function islandId(string $kind, string $selector, string $markup): string
    {
        return 'island_' . substr(hash('sha256', $kind . '|' . $selector . '|' . $markup), 0, 16);
    }

    /**
     * Stable generic enqueue-handle hint so a consumer can name a scoped script
     * deterministically without inventing a scheme. Product-neutral.
     */
    private function handleHint(string $kind, string $selector, string $markup): string
    {
        return 'runtime-island-' . substr(hash('sha256', $kind . '|' . $selector . '|' . $markup), 0, 12);
    }

    /**
     * @param array<int, array<string, mixed>> $islands
     * @return array<string, mixed>
     */
    private function totals(array $islands): array
    {
        $byDisposition = array();
        $byKind = array();
        foreach ( $islands as $island ) {
            $disposition = (string) ($island['disposition'] ?? '');
            if ( '' !== $disposition ) {
                $byDisposition[$disposition] = ($byDisposition[$disposition] ?? 0) + 1;
            }
            $kind = (string) ($island['kind'] ?? '');
            if ( '' !== $kind ) {
                $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
            }
        }

        return array_filter(
            array(
                'islands'         => count($islands),
                'by_disposition'  => $byDisposition,
                'by_kind'         => $byKind,
            ),
            static fn (mixed $value): bool => 0 !== $value && array() !== $value
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }
}
