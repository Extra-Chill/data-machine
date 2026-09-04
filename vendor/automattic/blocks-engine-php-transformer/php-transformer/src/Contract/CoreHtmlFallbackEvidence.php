<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Bounded, payload-safe evidence for raw HTML escape-hatch emissions. */
final class CoreHtmlFallbackEvidence
{
    public const SCHEMA = 'blocks-engine/core-html-fallback-evidence/v1';
    private const MAX_EMISSIONS = 100;
    private const MAX_SNIPPET_BYTES = 320;

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>
     */
    public static function fromBlocks(array $blocks, array $fallbacks, array $sourceProvenance): array
    {
        $fallbacksBySelector = array();
        foreach ($fallbacks as $fallback) {
            if (is_array($fallback) && is_string($fallback['selector'] ?? null)) {
                $fallbacksBySelector[$fallback['selector']][] = $fallback;
            }
        }

        $emissions = array();
        self::collect($blocks, 'blocks', $sourceProvenance, $fallbacksBySelector, $emissions);
        $total = count($emissions);
        $truncated = $total > self::MAX_EMISSIONS;

        return array(
            'schema' => self::SCHEMA,
            'taxonomy' => array(
                'schema' => 'blocks-engine/core-html-fallback-reason-taxonomy/v1',
                'reasons' => array('unsupported_element', 'unsupported_attribute', 'unsupported_style', 'runtime_semantics', 'block_grammar', 'sanitization'),
            ),
            'emissions' => array_slice($emissions, 0, self::MAX_EMISSIONS),
            'totals' => array('emissions' => $total, 'reported' => min($total, self::MAX_EMISSIONS), 'omitted' => max(0, $total - self::MAX_EMISSIONS), 'truncated' => $truncated),
        );
    }

    /** @param array<int, array<string, mixed>> $evidence @return array<string, mixed> */
    public static function merge(array $evidence): array
    {
        $emissions = array();
        foreach ($evidence as $entry) foreach (is_array($entry['emissions'] ?? null) ? $entry['emissions'] : array() as $emission) if (is_array($emission)) $emissions[] = $emission;
        usort($emissions, static fn (array $a, array $b): int => strcmp((string) ($a['source_path'] ?? '') . "\0" . (string) ($a['block_path'] ?? ''), (string) ($b['source_path'] ?? '') . "\0" . (string) ($b['block_path'] ?? '')));
        $total = array_sum(array_map(static fn (array $entry): int => (int) ($entry['totals']['emissions'] ?? 0), $evidence));
        $reported = min(count($emissions), self::MAX_EMISSIONS);

        return array(
            'schema' => self::SCHEMA,
            'taxonomy' => array('schema' => 'blocks-engine/core-html-fallback-reason-taxonomy/v1', 'reasons' => array('unsupported_element', 'unsupported_attribute', 'unsupported_style', 'runtime_semantics', 'block_grammar', 'sanitization')),
            'emissions' => array_slice($emissions, 0, self::MAX_EMISSIONS),
            'totals' => array('emissions' => $total, 'reported' => $reported, 'omitted' => max(0, $total - $reported), 'truncated' => $total > $reported),
        );
    }

    /** @param array<int, array<string, mixed>> $blocks @param array<int, array<string, mixed>> $provenance @param array<string, array<int, array<string, mixed>>> $fallbacks @param array<int, array<string, mixed>> $emissions */
    private static function collect(array $blocks, string $path, array $provenance, array $fallbacks, array &$emissions): void
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) continue;
            $blockPath = $path . '.' . $index;
            if ('core/html' === ($block['blockName'] ?? null)) {
                $source = self::sourceForPath($provenance, $blockPath);
                $fallback = $fallbacks[(string) ($source['selector'] ?? '')][0] ?? array();
                $fragment = (string) ($source['source_fragment'] ?? '');
                $content = (string) ($block['attrs']['content'] ?? '');
                $emissions[] = array(
                    'reason' => self::reason($fallback, $source),
                    'source_path' => (string) ($fallback['source'] ?? $source['source_path'] ?? ''),
                    'source_selector' => (string) ($source['selector'] ?? $fallback['selector'] ?? ''),
                    'block_path' => $blockPath,
                    'source_subtree' => array('digest' => (string) ($source['source_digest'] ?? hash('sha256', $fragment)), 'bytes' => (int) ($source['source_bytes'] ?? strlen($fragment)), 'snippet' => self::structuralSnippet($fragment), 'truncated' => strlen($fragment) > self::MAX_SNIPPET_BYTES),
                    'emitted' => array('block_digest' => hash('sha256', json_encode($block, JSON_UNESCAPED_SLASHES) ?: ''), 'content_digest' => hash('sha256', $content), 'content_bytes' => strlen($content)),
                );
            }
            if (is_array($block['innerBlocks'] ?? null)) self::collect($block['innerBlocks'], $blockPath . '.innerBlocks', $provenance, $fallbacks, $emissions);
        }
    }

    /** @param array<int, array<string, mixed>> $provenance @return array<string, mixed> */
    private static function sourceForPath(array $provenance, string $blockPath): array
    {
        foreach ($provenance as $entry) if (is_array($entry) && $blockPath === ($entry['block_path'] ?? null)) return $entry;
        return array();
    }

    /** @param array<string, mixed> $fallback @param array<string, mixed> $source */
    private static function reason(array $fallback, array $source): string
    {
        $code = (string) ($fallback['diagnostic_code'] ?? '');
        $reason = (string) ($fallback['reason'] ?? '');
        if ('html_unsupported_element' === $code || 'unsupported_element' === $reason) return 'unsupported_element';
        if (str_contains($reason, 'runtime') || str_contains($code, 'runtime') || 'html_script_fallback' === $code) return 'runtime_semantics';
        if (str_contains($reason, 'unsafe') || 'svg' === ($source['tag'] ?? null)) return 'sanitization';
        return 'block_grammar';
    }

    private static function structuralSnippet(string $html): string
    {
        preg_match_all('/<\/?[a-zA-Z][^>]*>/', $html, $matches);
        $tokens = array_map(static function (string $tag): string {
            if (str_starts_with($tag, '</')) return '</' . strtolower(trim($tag, '</> ')) . '>';
            preg_match('/^<\s*([a-zA-Z][\w:-]*)/', $tag, $name);
            preg_match_all('/\s+([:\w-]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/', $tag, $attrs);
            return '<' . strtolower($name[1] ?? 'unknown') . (empty($attrs[1]) ? '' : ' ' . implode(' ', array_map('strtolower', $attrs[1]))) . '>';
        }, $matches[0] ?? array());
        return substr(implode('', $tokens), 0, self::MAX_SNIPPET_BYTES);
    }
}
