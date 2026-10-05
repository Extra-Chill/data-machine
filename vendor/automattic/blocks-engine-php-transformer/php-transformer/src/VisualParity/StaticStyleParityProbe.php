<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Render-free static visual-parity probe.
 *
 * Extracts a normalized style fingerprint for every styleable element in a
 * document by statically resolving the author CSS cascade ({@see StaticCssCascade})
 * for a fixed set of visually load-bearing properties. No browser, no
 * rasterization, no layout: parsing the same HTML+CSS always yields
 * byte-identical probes.
 *
 * Paired with {@see StaticStyleParityComparator} this answers the deterministic
 * parity question "does the same effective styling apply to the same content?"
 * between a SOURCE document and a transformed CANDIDATE document, matching
 * elements by stable identity (selector path / class set / structural position)
 * rather than by pixels.
 *
 * Generic: no fixture-specific strings or product assumptions.
 */
final class StaticStyleParityProbe
{
    public const SCHEMA = 'blocks-engine/php-transformer/static-style-parity-probes/v1';
    public const GEOMETRY_SCHEMA = 'blocks-engine/php-transformer/static-style-parity-probes/geometry-v3';

    /**
     * Visually load-bearing, statically resolvable author properties. Sorted and
     * frozen so the fingerprint is stable. Layout-computed geometry is excluded
     * by design — only cascaded declared values are deterministic without a
     * browser.
     */
    public const TRACKED_PROPERTIES = array(
        'background-color',
        'border-color',
        'border-radius',
        'border-style',
        'border-width',
        'color',
        'display',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'letter-spacing',
        'line-height',
        'text-align',
        'text-decoration',
        'text-transform',
    );

    public const GEOMETRY_PROPERTIES = array(
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'aspect-ratio', 'flex-basis',
        'flex', 'flex-grow', 'flex-shrink',
    );

    /**
     * Properties that inherit through the cascade per CSS rules.
     */
    private const INHERITABLE_PROPERTIES = array(
        'color',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'letter-spacing',
        'line-height',
        'text-align',
        'text-transform',
    );

    /**
     * Tags that never carry meaningful visual content and are skipped.
     */
    private const SKIP_TAGS = array(
        'script', 'style', 'head', 'meta', 'link', 'title', 'base', 'noscript', 'template',
    );

    private bool $includeGeometry;

    public function __construct(bool $includeGeometry = false)
    {
        $this->includeGeometry = $includeGeometry;
    }

    /**
     * @param string $html     Document (or fragment) HTML to probe.
     * @param string $extraCss Optional external CSS (e.g. a linked stylesheet the
     *                         caller inlined) merged into the cascade.
     * @return array<string, mixed>
     */
    public function extract(string $html, string $extraCss = ''): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Fragments (e.g. the transform candidate, which is body-level block
        // markup with no document scaffold) are wrapped in a full <html><body>
        // root so that author rules targeting the document root — `html { ... }`,
        // `body { ... }` — bind to a real element and their inheritable values
        // (font-family, font-size, color, line-height, …) cascade into the
        // fragment's elements exactly as they do in a full source document. A
        // bare <body> wrapper left `html { font-family }` with no element to
        // match, so every candidate element silently lost the root-inherited
        // typography the source kept — a measurement asymmetry that depressed
        // coverage for faithfully-preserved content. Full documents already carry
        // their own root and are probed as-is.
        $sourceHtml = preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ? $html : '<html><body>' . $html . '</body></html>';
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $sourceHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return $this->failed();
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $this->result(array());
        }

        $cascade = new StaticCssCascade($document, $extraCss);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('.//*', $body);
        $probes = array();
        $seen = array();

        if ( false !== $nodes ) {
            foreach ( $nodes as $node ) {
                if ( ! $node instanceof DOMElement ) {
                    continue;
                }
                $tag = strtolower($node->tagName);
                if ( in_array($tag, self::SKIP_TAGS, true) || $this->isInsideSkippedTag($node) ) {
                    continue;
                }

                $style = $cascade->resolve($node, $this->trackedProperties(), self::INHERITABLE_PROPERTIES);
                $hasIdentity = $node->hasAttribute('class') || $node->hasAttribute('id') || $node->hasAttribute('style');
                if ( array() === $style && ! $hasIdentity ) {
                    continue;
                }

                $selector = $this->selector($node);
                if ( isset($seen[$selector]) ) {
                    continue;
                }
                $seen[$selector] = true;

                $classes = $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : '');
                sort($classes);

                $probe = array(
                    'id' => 'static-parity-probe-' . ( count($probes) + 1 ),
                    'tag' => $tag,
                    'selector' => $selector,
                    'structural_path' => $this->structuralPath($node),
                    'classes' => $classes,
                    'text' => mb_substr($this->normalizedText($node), 0, 80),
                    'style' => $style,
                );
                $probes[] = $probe;
            }
        }

        return $this->result($probes);
    }

    /**
     * @param array<int, array<string, mixed>> $probes
     * @return array<string, mixed>
     */
    private function result(array $probes): array
    {
        $byTag = array();
        $styledCount = 0;
        foreach ( $probes as $probe ) {
            $tag = (string) ($probe['tag'] ?? '');
            $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
            if ( array() !== ($probe['style'] ?? array()) ) {
                ++$styledCount;
            }
        }
        ksort($byTag);

        return array(
            'schema' => $this->schema(),
            'status' => 'success',
            'probes' => $probes,
            'summary' => array(
                'total' => count($probes),
                'styled_total' => $styledCount,
                'by_tag' => $byTag,
                'tracked_properties' => $this->trackedProperties(),
            ),
            'diagnostics' => array(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(): array
    {
        return array(
            'schema' => $this->schema(),
            'status' => 'failed',
            'probes' => array(),
            'summary' => array(
                'total' => 0,
                'styled_total' => 0,
                'by_tag' => array(),
                'tracked_properties' => $this->trackedProperties(),
            ),
            'diagnostics' => array(
                array('code' => 'html_parse_failed', 'message' => 'Unable to parse HTML for static style parity probes.'),
            ),
        );
    }

    /** @return array<int, string> */
    private function trackedProperties(): array
    {
        return $this->includeGeometry ? array_values(array_unique(array_merge(self::TRACKED_PROPERTIES, self::GEOMETRY_PROPERTIES))) : self::TRACKED_PROPERTIES;
    }

    private function schema(): string
    {
        return $this->includeGeometry ? self::GEOMETRY_SCHEMA : self::SCHEMA;
    }

    /**
     * Stable, human-readable selector path: tag + id (anchors and stops) or
     * tag + up to two classes + :nth-of-type when needed, walked to <body>.
     */
    private function selector(DOMElement $element): string
    {
        $segments = array();
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $segment = strtolower($node->tagName);
            if ( $node->hasAttribute('id') && '' !== trim($node->getAttribute('id')) ) {
                $segment .= '#' . trim($node->getAttribute('id'));
                array_unshift($segments, $segment);
                break;
            }
            $classes = $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : '');
            sort($classes);
            if ( array() !== $classes ) {
                $segment .= '.' . implode('.', array_slice($classes, 0, 2));
            }
            $index = $this->elementIndex($node);
            if ( $index > 1 ) {
                $segment .= ':nth-of-type(' . $index . ')';
            }
            array_unshift($segments, $segment);
        }

        return implode(' > ', $segments);
    }

    /**
     * Class-free structural identity: tag + :nth-of-type chain to <body>. Used as
     * the final deterministic matching tier when classes/text drift.
     */
    private function structuralPath(DOMElement $element): string
    {
        $segments = array();
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $segments[] = strtolower($node->tagName) . ':' . $this->elementIndex($node);
        }

        return implode('/', array_reverse($segments));
    }

    private function elementIndex(DOMElement $element): int
    {
        $index = 1;
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && strtolower($node->tagName) === strtolower($element->tagName) ) {
                ++$index;
            }
        }

        return $index;
    }

    private function normalizedText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $element->textContent, ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: array(), static fn (string $token): bool => '' !== $token));
    }

    private function isInsideSkippedTag(DOMElement $element): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), self::SKIP_TAGS, true) ) {
                return true;
            }
        }

        return false;
    }
}
