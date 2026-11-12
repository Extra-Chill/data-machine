<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\ClassificationContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SubtreeClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\FallbackDiagnostic;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMDocument;
use DOMElement;

/**
 * Constructs the per-element fallback / behavior-loss emission entries that
 * previously lived inline in HtmlTransformer: the `capture*Fallback` family
 * (`html_inline_svg_fallback` / `html_unsafe_inline_svg`,
 * `html_canvas_runtime_fallback`, `html_script_fallback`,
 * `html_template_runtime_fallback` / `html_template_metadata`,
 * `script_static_metadata`) plus the runtime-island construction
 * (`preserved_runtime_island`) and the runtime-script requirement metadata
 * that backs those findings.
 *
 * This is a behavior-preserving extraction (slice 4 of #242): the bodies are
 * moved verbatim, so the emitted `FallbackDiagnostic`/runtime-island arrays are
 * byte-identical to the inline implementation.
 *
 * Decoupling notes:
 *  - The accumulators (`$fallbacks`, `$runtimeIslands`, `$scriptMetadata`) stay
 *    owned by HtmlTransformer (they are read at output time and from
 *    non-fallback code such as `isPreservedRuntimeIslandElement`); the emitter
 *    receives them by reference rather than reaching into transformer state.
 *  - Per-document configuration (`fallbackProvenance`, `runtimeScriptMetadata`,
 *    `runtimeCanvasSelectors`) is injected via {@see configure()} once per
 *    transform.
 *  - `sourceContext()` enrichment is deeply entangled with the transformer's
 *    DOM-classification subsystem (structure signals, interactive attributes,
 *    etc.) which is *not* a fallback concern; rather than dragging that whole
 *    tree into this module it is injected as a resolver closure so the canonical
 *    implementation stays in HtmlTransformer.
 *  - Small shared leaves (`eventMetadata`, `isSafeSvgContent`, `dedupeArrayRows`,
 *    `runtimeIslandSelector`) are duplicated here (HtmlTransformer keeps its own
 *    copies for non-fallback callers) so this module needs no transformer
 *    reference, mirroring the slice-3 `safeNavigationUrl` duplication.
 *
 * Shared DOM helpers come from {@see DomHelpersTrait}.
 */
final class FallbackEmitter
{
    use DomHelpersTrait;

    /**
     * @var array<string, string>
     */
    private array $fallbackProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $runtimeScriptMetadata = array();

    /**
     * @var array<string, bool>
     */
    private array $runtimeCanvasSelectors = array();

    /**
     * DOM identity for each emitted runtime island, kept aligned by index with
     * the transformer's accumulator so nested findings can be compared without
     * exposing implementation-only node paths in diagnostics.
     *
     * @var array<int, array{document: int, path: string}>
     */
    private array $runtimeIslandOrigins = array();

    private readonly SubtreeClassifier $classifier;

    private readonly CustomBlockGenerator $blockGenerator;

    /**
     * Minimum classifier confidence for a `custom_block` verdict to actually
     * trigger generation. Conservative: the classifier's own MIN_SCORE/MARGIN
     * gates already reject weak/ambiguous verdicts (capping UNKNOWN at 0.4), and
     * this floor keeps generation to the high-confidence custom_block tail. On
     * anything below it we keep the existing core/html fallback behavior.
     */
    private const GENERATION_CONFIDENCE_THRESHOLD = 0.7;

    /**
     * Per-transform dedup registry: structure-and-content identity => generated
     * local block name. Only references with identical sanitized render HTML
     * reuse a generated block type.
     *
     * @var array<string, string>
     */
    private array $generatedBlockNames = array();

    /** @var array<string, string> */
    private array $generatedBlockIdentities = array();

    /**
     * @param Closure(DOMElement): array<string, mixed> $sourceContextResolver
     *        Resolves the shared `sourceContext` enrichment for an element. The
     *        canonical implementation lives in HtmlTransformer because it spans
     *        the broader DOM-classification subsystem rather than the fallback
     *        concern.
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Closure $sourceContextResolver
    ) {
        $this->classifier     = new SubtreeClassifier();
        $this->blockGenerator = new CustomBlockGenerator();
    }

    /**
     * Reset the per-transform custom-block dedup registry. Called once per
     * transform so generated-block names/dedup never leak across documents.
     */
    public function resetGeneratedBlocks(): void
    {
        $this->generatedBlockNames = array();
        $this->generatedBlockIdentities = array();
    }

    /**
     * Producer link of the classify -> route -> generate chain (issue #497).
     *
     * At a `core/html` fallback decision (a subtree that mapped to nothing
     * native/Automattic), consult the structural classifier. When it returns
     * `custom_block` with high confidence, generate a static-render block
     * definition, register it on the passed-by-reference `$generatedBlocks`
     * accumulator (deduped by structure and sanitized content), and return a self-closing
     * block REFERENCE (block name + per-instance attrs, no innerHTML) for the
     * caller to emit instead of raw `core/html`. Returns null to keep the
     * existing fallback behavior whenever the conservative gate is not met.
     *
     * @param array<int, array<string, mixed>> $generatedBlocks Accumulator of generated block-type definitions.
     * @return array{blockName: string, attrs: array<string, mixed>}|null
     */
    public function maybeGenerateCustomBlock(DOMElement $element, array &$generatedBlocks, string $namespace): ?array
    {
        $result = $this->classifier->classify($element, $this->classificationContext($element));
        if ( ! $result->is(SubtreeClassifier::BUCKET_CUSTOM_BLOCK) ) {
            return null;
        }
        if ( $result->confidence() < self::GENERATION_CONFIDENCE_THRESHOLD ) {
            return null;
        }

        // Sanitize the subtree markup that becomes the editable, server-rendered
        // content. Nothing to carry => keep the existing fallback behavior.
        $content = $this->sanitizeHtmlString($this->innerHtml($element));
        if ( '' === trim($content) ) {
            return null;
        }

        $namespace = $this->sanitizeNameSegment($namespace);
        if ( '' === $namespace ) {
            $namespace = 'custom';
        }

        $signature = $this->structuralSignature($element);
        $identity = $signature . "\0" . $content;
        if ( isset($this->generatedBlockNames[$identity]) ) {
            $localName = $this->generatedBlockNames[$identity];
        } else {
            $localName = $this->generatedBlockLocalName($result->signals(), $identity);
            $this->generatedBlockNames[$identity] = $localName;
            $generatedBlocks[] = array(
                'name'       => $localName,
                'block_json' => $this->blockGenerator->blockJson($namespace . '/' . $localName, $this->generatedBlockTitle($result->signals())),
                'render'     => $this->blockGenerator->render($content),
                // Diagnostic-only; stripped before the payload reaches SSI.
                'signature'  => $signature,
            );
        }

        return array(
            'blockName' => $namespace . '/' . $localName,
            'attrs'     => $this->blockGenerator->referenceAttributes($content),
        );
    }

    /**
     * Canonical structural signature of a subtree: the tag-only DOM skeleton,
     * ignoring text and attributes. Depth-bounded for safety on pathological
     * trees; sanitized content is added separately to the generated identity.
     */
    private function structuralSignature(DOMElement $element, int $depth = 0): string
    {
        $tag = strtolower($element->tagName);
        if ( $depth >= 6 ) {
            return $tag;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $this->structuralSignature($child, $depth + 1);
            }
        }

        return array() === $children ? $tag : $tag . '(' . implode(',', $children) . ')';
    }

    /**
     * Generic, structurally-derived local block name. The stem reflects the
     * dominant structural shape (never fixture/site strings); the full digest of
     * structure plus sanitized content prevents distinct renders from aliasing.
     *
     * @param array<string, mixed> $signals Classifier structural signals.
     */
    private function generatedBlockLocalName(array $signals, string $identity): string
    {
        $stem = $this->generatedBlockStem($signals);
        $name = $stem . '-' . hash('sha256', $identity);
        $attempt = 0;
        while ( isset($this->generatedBlockIdentities[$name]) && $this->generatedBlockIdentities[$name] !== $identity ) {
            ++$attempt;
            $name = $stem . '-' . hash('sha256', $identity . ':' . $attempt);
        }
        $this->generatedBlockIdentities[$name] = $identity;

        return $name;
    }

    /**
     * @param array<string, mixed> $signals Classifier structural signals.
     */
    private function generatedBlockTitle(array $signals): string
    {
        $titles = array(
            'gallery'    => 'Custom Gallery',
            'collection' => 'Custom Collection',
            'panel'      => 'Custom Panel',
            'card'       => 'Custom Card',
            'block'      => 'Custom Content Block',
        );

        return $titles[$this->generatedBlockStem($signals)] ?? 'Custom Content Block';
    }

    /**
     * @param array<string, mixed> $signals Classifier structural signals.
     */
    private function generatedBlockStem(array $signals): string
    {
        if ( ! empty($signals['media_gallery']) ) {
            return 'gallery';
        }
        if ( (int) ($signals['repeatable_children'] ?? 0) >= 2 ) {
            return 'collection';
        }
        if ( ! empty($signals['interactive_role']) ) {
            return 'panel';
        }
        if ( ! empty($signals['cohesive_content_unit']) ) {
            return 'card';
        }

        return 'block';
    }

    /**
     * Sanitize a raw HTML string for safe server-render storage, mirroring the
     * stripping {@see DomHelpersTrait::safeFallbackHtml()} applies to elements
     * (drop script/style bodies, inline event handlers, and javascript: URLs).
     */
    private function sanitizeHtmlString(string $html): string
    {
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(?:href|src|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+srcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

        return trim($html);
    }

    /**
     * Lowercase, hyphen-delimited block-name segment (namespace or local stem),
     * matching WordPress block-name constraints. Portable; WP is not loaded.
     */
    private function sanitizeNameSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * Inject the per-transform configuration so the moved emission bodies keep
     * behaving identically to the inline implementation.
     *
     * @param array<string, string>             $fallbackProvenance
     * @param array<int, array<string, mixed>>  $runtimeScriptMetadata
     * @param array<string, bool>               $runtimeCanvasSelectors
     */
    public function configure(array $fallbackProvenance, array $runtimeScriptMetadata, array $runtimeCanvasSelectors): void
    {
        $this->fallbackProvenance     = $fallbackProvenance;
        $this->runtimeScriptMetadata  = $runtimeScriptMetadata;
        $this->runtimeCanvasSelectors = $runtimeCanvasSelectors;
        $this->runtimeIslandOrigins   = array();
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function captureInlineSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        $rawHtml = $this->outerHtml($element);
        $safe = $this->isSafeSvgContent($rawHtml);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'inline_svg',
            'reason'          => $safe ? 'inline_svg_fallback' : 'unsafe_inline_svg',
            'diagnostic_code' => $safe ? 'html_inline_svg_fallback' : 'html_unsafe_inline_svg',
            'message'         => $safe ? 'Inline SVG was preserved as sanitized bounded fallback metadata.' : 'Inline SVG contains scriptable content and was preserved only as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'svg',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeSvgAttributes($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->classifyFallbackSubtree($element),
            'events'          => $this->eventMetadata($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    public function captureCanvasFallback(DOMElement $element, array &$fallbacks, array &$runtimeIslands): void
    {
        if ( ! $this->isRuntimeCanvasTarget($element) ) {
            return;
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $id = trim($this->attr($element, 'id'));
        $this->recordRuntimeIsland($element, 'canvas', 'canvas_requires_runtime', 'canvas_element_and_client_script_execution', array(
            'script_dependency_hint' => '' !== $id
                ? 'Scripts may target #' . $id . ' and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.'
                : 'Scripts may target this canvas by selector and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.',
            'required_scripts' => $this->requiredScriptsForElement($element),
        ), $runtimeIslands);

        $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
            'type'            => 'html',
            'reason'          => 'canvas_requires_runtime',
            'diagnostic_code' => 'html_canvas_runtime_fallback',
            'message'         => 'Canvas HTML requires a native canvas element and client script runtime; core blocks cannot preserve it without raw HTML.',
            'source_format'   => 'html',
            'tag'             => 'canvas',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeCanvasAttributes($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->classifyFallbackSubtree($element),
            'events'                 => $this->eventMetadata($element),
            'script_dependency_hint' => '' !== $id
                ? 'Scripts may target #' . $id . ' and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.'
                : 'Scripts may target this canvas by selector and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.',
            'text_length'            => strlen(trim($element->textContent ?? '')),
            'child_count'            => $this->childElementCount($element),
            'html'                   => $boundedHtml['html'],
            'html_bytes'             => $boundedHtml['bytes'],
            'html_truncated'         => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value), $this->fallbackProvenance);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    public function captureScriptFallback(DOMElement $element, array &$fallbacks, array &$runtimeIslands): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $boundedBody = $this->boundedFallbackText(trim($element->textContent ?? ''));
        $scriptRole = $this->scriptRole($element);
        $scriptSourceKind = '' !== trim($this->attr($element, 'src')) ? 'external' : 'inline';
        $scriptIslandMetadata = array(
            'attributes'         => $this->safeScriptAttributes($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => $scriptSourceKind,
        );
        if ( 'inline' === $scriptSourceKind ) {
            // safeFallbackHtml() strips <script> bodies from source_snippet for
            // safety, so an inline script island would otherwise carry no JS.
            // Preserve the verbatim inline body (bounded) so a downstream
            // consumer can carry the script forward (issue #224: verbatim JS on
            // verbatim island markup).
            $scriptIslandMetadata['script_body']    = $boundedBody['text'];
            $scriptIslandMetadata['body_bytes']     = $boundedBody['bytes'];
            $scriptIslandMetadata['body_truncated'] = $boundedBody['truncated'];
        }
        $this->recordRuntimeIsland($element, 'script', 'script_requires_runtime', 'client_script_execution', $scriptIslandMetadata, $runtimeIslands);
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'script_requires_runtime',
            'diagnostic_code' => 'html_script_fallback',
            'message'         => 'Script HTML requires runtime behavior and was preserved as scoped safe fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'script',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeScriptAttributes($element),
            'context'         => $this->sourceContext($element),
            'classification'  => $this->classifyFallbackSubtree($element),
            'events'          => $this->eventMetadata($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => '' !== trim($this->attr($element, 'src')) ? 'external' : 'inline',
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
            'body'            => $boundedBody['text'],
            'body_bytes'      => $boundedBody['bytes'],
            'body_truncated'  => $boundedBody['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @param array<int, array<string, mixed>> $scriptMetadata
     */
    public function captureStaticScriptMetadata(DOMElement $element, array &$scriptMetadata): bool
    {
        if ( '' !== trim($this->attr($element, 'src')) ) {
            return false;
        }

        $scriptRole = $this->scriptRole($element);
        if ( 'data' !== $scriptRole ) {
            $scriptRole = $this->staticScriptMetadataRole($element);
        }
        if ( null === $scriptRole ) {
            return false;
        }

        $boundedBody = $this->boundedFallbackText(trim($element->textContent ?? ''));
        $scriptMetadata[] = array(
            'type'               => 'script_metadata',
            'reason'             => 'script_static_metadata',
            'source_format'      => 'html',
            'tag'                => 'script',
            'selector'           => $this->elementSelector($element),
            'attributes'         => $this->safeScriptAttributes($element),
            'context'            => $this->sourceContext($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => 'inline',
            'body'               => $boundedBody['text'],
            'body_bytes'         => $boundedBody['bytes'],
            'body_truncated'     => $boundedBody['truncated'],
        );

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    public function captureTemplateFallback(DOMElement $element, array &$fallbacks, array &$runtimeIslands): void
    {
        $runtimeTemplate = $this->templateRequiresRuntimePreservation($element);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $boundedBody = $this->boundedFallbackHtml($this->innerHtml($element));
        $attributes = $this->safeTemplateAttributes($element);

        if ( $runtimeTemplate ) {
            $this->recordRuntimeIsland($element, 'template', 'template_requires_runtime', 'client_template_instantiation', array(
                'attributes'      => $attributes,
                'template_role'   => $this->templateRole($element),
                'template_body'   => $boundedBody['html'],
                'body_bytes'      => $boundedBody['bytes'],
                'body_truncated'  => $boundedBody['truncated'],
                'required_scripts' => $this->requiredScriptsForElement($element),
            ), $runtimeIslands);
        }

        $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
            'type'            => 'html',
            'reason'          => $runtimeTemplate ? 'template_requires_runtime' : 'template_static_metadata',
            'diagnostic_code' => $runtimeTemplate ? 'html_template_runtime_fallback' : 'html_template_metadata',
            'message'         => $runtimeTemplate
                ? 'HTML template content is inert until client runtime instantiates it and was preserved as bounded runtime metadata.'
                : 'HTML template content is inert and was preserved as bounded metadata without visual output.',
            'source_format'   => 'html',
            'tag'             => 'template',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $attributes,
            'context'         => $this->sourceContext($element),
            'classification'  => $this->classifyFallbackSubtree($element),
            'template_role'   => $this->templateRole($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
            'body'            => $boundedBody['html'],
            'body_bytes'      => $boundedBody['bytes'],
            'body_truncated'  => $boundedBody['truncated'],
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value), $this->fallbackProvenance);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    public function recordRuntimeIsland(DOMElement $element, string $kind, string $reason, string $runtimeRequirement, array $metadata, array &$runtimeIslands): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $island = FallbackDiagnostic::withGenericFindingMetadata(array_filter(array_merge(array(
            'kind'                => $kind,
            'selector'            => $this->runtimeIslandSelector($element),
            'tag'                 => strtolower($element->tagName),
            'diagnostic_code'     => 'preserved_runtime_island',
            'preservation_reason' => $reason,
            'runtime_requirement' => $runtimeRequirement,
            'disposition'         => 'preserve',
            'preservation_status' => 'accepted_runtime_preservation',
            'js_handling'         => 'client_script_execution' === $runtimeRequirement ? 'preserve_verbatim' : '',
            'source_snippet'      => $boundedHtml['html'],
            'source_bytes'        => $boundedHtml['bytes'],
            'source_truncated'    => $boundedHtml['truncated'],
            'attributes'          => $this->htmlAttributes($element),
            'context'             => $this->sourceContext($element),
            'required_assets'     => array(),
            'required_scripts'    => array(),
        ), $metadata), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value));

        $key = json_encode(array(
            'kind'     => $island['kind'] ?? '',
            'selector' => $island['selector'] ?? '',
            'snippet'  => $island['source_snippet'] ?? '',
        ), JSON_UNESCAPED_SLASHES);
        $origin = array(
            'document' => $element->ownerDocument instanceof DOMDocument ? spl_object_id($element->ownerDocument) : 0,
            'path'     => $element->getNodePath() ?? '',
        );
        $nestedIslandIndexes = array();
        foreach ( $runtimeIslands as $index => $existing ) {
            $existingKey = json_encode(array(
                'kind'     => $existing['kind'] ?? '',
                'selector' => $existing['selector'] ?? '',
                'snippet'  => $existing['source_snippet'] ?? '',
            ), JSON_UNESCAPED_SLASHES);
            if ( $key === $existingKey ) {
                return;
            }

            $existingOrigin = $this->runtimeIslandOrigins[$index] ?? null;
            if ( ! is_array($existingOrigin)
                || 0 === $origin['document']
                || $origin['document'] !== ($existingOrigin['document'] ?? 0)
                || '' === $origin['path']
                || '' === ($existingOrigin['path'] ?? '')
            ) {
                continue;
            }

            $existingPath = (string) $existingOrigin['path'];
            if ( str_starts_with($origin['path'], $existingPath . '/') ) {
                return;
            }
            if ( str_starts_with($existingPath, $origin['path'] . '/') ) {
                $nestedIslandIndexes[] = $index;
            }
        }

        foreach ( array_reverse($nestedIslandIndexes) as $index ) {
            array_splice($runtimeIslands, $index, 1);
            array_splice($this->runtimeIslandOrigins, $index, 1);
        }
        $runtimeIslands[] = $island;
        $this->runtimeIslandOrigins[] = $origin;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function requiredScriptsForElement(DOMElement $element): array
    {
        $scripts = $this->runtimeScriptMetadata;

        $owner = $element->ownerDocument;
        if ( ! $owner instanceof DOMDocument ) {
            return $scripts;
        }

        foreach ( $owner->getElementsByTagName('script') as $script ) {
            if ( ! $script instanceof DOMElement || 'runtime' !== $this->scriptRole($script) ) {
                continue;
            }

            $scripts[] = array_filter(array(
                'selector'           => $this->elementSelector($script),
                'attributes'         => $this->safeScriptAttributes($script),
                'script_role'        => 'runtime',
                'script_source_kind' => '' !== trim($this->attr($script, 'src')) ? 'external' : 'inline',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeArrayRows($scripts);
    }

    public function isRuntimeCanvasTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && isset($this->runtimeCanvasSelectors['#' . $id]) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && isset($this->runtimeCanvasSelectors['.' . $class]) ) {
                return true;
            }
        }

        foreach ( array_keys($this->runtimeCanvasSelectors) as $selector ) {
            if ( $this->elementMatchesRuntimeSelector($element, (string) $selector) ) {
                return true;
            }
        }

        return false;
    }

    private function elementMatchesRuntimeSelector(DOMElement $element, string $selector): bool
    {
        $tag = strtolower($element->tagName);
        if ( $selector === $tag && 'canvas' === $tag ) {
            return true;
        }
        if ( preg_match('/^([a-z][a-z0-9-]*)\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            return $tag === strtolower((string) $match[1]) && in_array((string) $match[2], preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
        }
        if ( preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ) {
            return ( '' === (string) ($match[1] ?? '') || $tag === strtolower((string) $match[1]) ) && $element->hasAttribute(strtolower((string) $match[2]));
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(DOMElement $element): array
    {
        return ( $this->sourceContextResolver )($element);
    }

    /**
     * Project the structural subtree-classifier verdict for a subtree that fell
     * back to raw `core/html`. This is MEASUREMENT-ONLY diagnostic metadata
     * (issue #497): it surfaces which raw-HTML fallbacks the classifier believes
     * should have become native blocks, across the whole corpus. It does NOT
     * change routing or block output — the verdict is attached to the existing
     * fallback diagnostic and nothing else consumes it yet.
     *
     * Guarded to the core/html fallback path: it only runs when a fallback
     * diagnostic is actually being emitted, never per converted element.
     *
     * @return array{bucket: string, confidence: float, signals: array<string, mixed>}
     */
    public function classifyFallbackSubtree(DOMElement $element): array
    {
        $result = $this->classifier->classify($element, $this->classificationContext($element));

        return array(
            'bucket'     => $result->bucket(),
            'confidence' => $result->confidence(),
            'signals'    => $this->topClassificationSignals($result->signals()),
        );
    }

    private function classificationContext(DOMElement $element): ClassificationContext
    {
        return new ClassificationContext(
            $this->subtreeInlineCss($element),
            $this->subtreeJsText($element)
        );
    }

    /**
     * Inline CSS declared on the subtree (`style` attributes), which is the CSS
     * association cheaply available at the fallback emission point.
     */
    private function subtreeInlineCss(DOMElement $element): string
    {
        $parts = array();
        foreach ( $this->subtreeElements($element) as $node ) {
            $style = trim($this->attr($node, 'style'));
            if ( '' !== $style ) {
                $parts[] = $style;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * JavaScript associated with the subtree: inline `<script>` bodies plus
     * inline `on*` event-handler attribute source within the subtree.
     */
    private function subtreeJsText(DOMElement $element): string
    {
        $parts = array();
        foreach ( $this->subtreeElements($element) as $node ) {
            if ( 'script' === strtolower($node->tagName) && '' === trim($this->attr($node, 'src')) ) {
                $body = trim($node->textContent ?? '');
                if ( '' !== $body ) {
                    $parts[] = $body;
                }
            }
            foreach ( $node->attributes ?? array() as $attribute ) {
                $name = strtolower($attribute->nodeName);
                if ( str_starts_with($name, 'on') && strlen($name) > 2 ) {
                    $value = trim($attribute->nodeValue ?? '');
                    if ( '' !== $value ) {
                        $parts[] = $value;
                    }
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<int, DOMElement>
     */
    private function subtreeElements(DOMElement $element): array
    {
        $out = array( $element );
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement ) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    /**
     * Condense the raw classifier signals to the diagnostic-relevant top signals:
     * the active boolean flags, the positive structural counts, and the per-bucket
     * scores that drove the verdict.
     *
     * @param array<string, mixed> $signals
     * @return array<string, mixed>
     */
    private function topClassificationSignals(array $signals): array
    {
        $flags  = array();
        $counts = array();
        foreach ( $signals as $name => $value ) {
            if ( 'scores' === $name ) {
                continue;
            }
            if ( true === $value ) {
                $flags[] = $name;
            } elseif ( is_int($value) && $value > 0 ) {
                $counts[$name] = $value;
            }
        }

        return array_filter(array(
            'flags'  => $flags,
            'counts' => $counts,
            'scores' => is_array($signals['scores'] ?? null) ? $signals['scores'] : array(),
        ), static fn (mixed $value): bool => array() !== $value);
    }

    private function templateRequiresRuntimePreservation(DOMElement $element): bool
    {
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            $normalizedName = strtolower($name);
            if ( 'id' === $normalizedName || str_starts_with($normalizedName, 'data-') || preg_match('/^(?:x-|v-|ng-|:|@)/', $normalizedName) ) {
                return true;
            }
            if ( preg_match('/\b(?:template|runtime|component|partial|slot|content)\b/i', $value) ) {
                return true;
            }
        }

        $body = $this->innerHtml($element);
        return preg_match('/<\s*(?:script|canvas|iframe|form|input|select|textarea|button)\b/i', $body) === 1
            || preg_match('/\{\{|\$\{|<\s*slot\b/i', $body) === 1;
    }

    private function templateRole(DOMElement $element): string
    {
        if ( '' !== trim($this->attr($element, 'id')) ) {
            return 'addressable_template';
        }

        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( str_starts_with(strtolower($name), 'data-') && '' !== trim($value) ) {
                return 'data_template';
            }
        }

        return $this->templateRequiresRuntimePreservation($element) ? 'runtime_template' : 'static_template_metadata';
    }

    /**
     * @return array<string, string>
     */
    private function safeTemplateAttributes(DOMElement $element): array
    {
        $safe = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:/i', $value) ) {
                continue;
            }
            $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
        }

        return $safe;
    }

    private function scriptRole(DOMElement $element): string
    {
        $type = strtolower(trim($this->attr($element, 'type')));
        if ( '' === $type || in_array($type, array( 'text/javascript', 'application/javascript', 'module' ), true) ) {
            return 'runtime';
        }

        if ( str_starts_with($type, 'application/ld+json') || in_array($type, array( 'application/json', 'importmap', 'speculationrules' ), true) ) {
            return 'data';
        }

        if ( str_starts_with($type, 'text/') && ! in_array($type, array( 'text/javascript', 'text/ecmascript' ), true) ) {
            return 'data';
        }

        return 'runtime';
    }

    private function staticScriptMetadataRole(DOMElement $element): ?string
    {
        $body = trim($element->textContent ?? '');
        if ( '' === $body || $this->scriptBodyHasExecutableRuntimeSignals($body) ) {
            return null;
        }

        $type = strtolower(trim($this->attr($element, 'type')));
        if ( 'module' === $type && $this->scriptBodyContainsOnlyStaticImports($body) ) {
            return 'static_import';
        }

        if ( $this->scriptBodyContainsOnlyStaticConfig($body) ) {
            return 'static_config';
        }

        return null;
    }

    private function scriptBodyHasExecutableRuntimeSignals(string $body): bool
    {
        return 1 === preg_match('/\b(?:document|location|navigator|history|customElements)\b|\b(?:addEventListener|removeEventListener|querySelector|getElementById|appendChild|insertBefore|replaceChild|removeChild|classList|innerHTML|outerHTML|fetch|XMLHttpRequest|setTimeout|setInterval|requestAnimationFrame|import\s*\()\b|\b(?:function|class|new)\b|=>/', $body);
    }

    private function scriptBodyContainsOnlyStaticImports(string $body): bool
    {
        $withoutImports = preg_replace('/^\s*import\s+(?:(?:[\s\S]*?\s+from\s+)?[\'\"][^\'\"]+[\'\"]|[\'\"][^\'\"]+[\'\"])\s*;?\s*/m', '', $body);

        return is_string($withoutImports) && '' === trim($withoutImports);
    }

    private function scriptBodyContainsOnlyStaticConfig(string $body): bool
    {
        $statementPattern = '(?:const|let|var)\s+[A-Za-z_$][A-Za-z0-9_$]*\s*=\s*(?:\{[\s\S]*?\}|\[[\s\S]*?\]|[\'\"][\s\S]*?[\'\"]|[0-9.]+|true|false|null)\s*;?';
        $globalConfigPattern = '(?:window|globalThis)\.[A-Za-z_$][A-Za-z0-9_$.]*(?:CONFIG|Config|config|SETTINGS|Settings|settings|DATA|Data|data|PROPS|Props|props)[A-Za-z0-9_$.]*\s*=\s*(?:\{[\s\S]*?\}|\[[\s\S]*?\]|[\'\"][\s\S]*?[\'\"]|[0-9.]+|true|false|null)\s*;?';

        return 1 === preg_match('/^\s*(?:' . $statementPattern . '|' . $globalConfigPattern . ')+\s*$/', $body);
    }

    /**
     * @return array<string, string>
     */
    private function safeScriptAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'async', 'class', 'defer', 'id', 'src', 'type' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    private function safeCanvasAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'aria-label', 'class', 'height', 'id', 'role', 'style', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    private function safeSvgAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:/i', $value) ) {
                continue;
            }
            $attributes[$name] = strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value;
        }

        return $attributes;
    }

}
