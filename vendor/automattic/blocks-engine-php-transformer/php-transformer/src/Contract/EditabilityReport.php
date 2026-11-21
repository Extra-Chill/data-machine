<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

/** Observes block-tree complexity without changing or grading compiler output. */
final class EditabilityReport
{
    public const SCHEMA = 'blocks-engine/php-transformer/editability-report/v1';
    private const MAX_REPORTED_SIGNALS = 100;
    private const INLINE_RICH_TEXT_TAGS = array('a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'img', 'ins', 'kbd', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u');
    private const RICH_TEXT_ATTRIBUTES = array(
        'core/heading' => array('content'),
        'core/list-item' => array('content'),
        'core/paragraph' => array('content'),
    );

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed> */
    public function fromBlocks(array $blocks, string $sourcePath = '', string $serializedBlocks = ''): array
    {
        $metrics = array(
            'block_count' => 0,
            'leaf_block_count' => 0,
            'content_block_count' => 0,
            'wrapper_block_count' => 0,
            'empty_wrapper_count' => 0,
            'max_nesting_depth' => 0,
            'raw_html_block_count' => 0,
            'html_bearing_attribute_count' => 0,
            'html_bearing_table_cell_count' => 0,
            'structural_rich_text_attribute_count' => 0,
            'structural_rich_text_attribute_bytes' => 0,
            'source_marker_class_count' => 0,
            'generated_geometry_class_count' => 0,
            'serialized_bytes' => '' === $serializedBlocks ? 0 : strlen($serializedBlocks),
        );
        $blockTypes = array();
        $signals = array();
        $this->walk($blocks, 1, array(), $metrics, $blockTypes, $signals, $sourcePath);
        ksort($blockTypes, SORT_STRING);
        $metrics['wrapper_to_content_ratio'] = 0 === $metrics['content_block_count']
            ? (float) $metrics['wrapper_block_count']
            : round($metrics['wrapper_block_count'] / $metrics['content_block_count'], 4);

        $signalCount = count($signals);
        return array(
            'schema' => self::SCHEMA,
            'status' => 'observed',
            'enforcement' => 'report_only',
            'scope' => array_filter(array('source_path' => $sourcePath), static fn(string $value): bool => '' !== $value),
            'metrics' => $metrics,
            'block_types' => $blockTypes,
            'signals' => array_slice($signals, 0, self::MAX_REPORTED_SIGNALS),
            'signal_totals' => array(
                'observed' => $signalCount,
                'reported' => min($signalCount, self::MAX_REPORTED_SIGNALS),
                'omitted' => max(0, $signalCount - self::MAX_REPORTED_SIGNALS),
                'truncated' => $signalCount > self::MAX_REPORTED_SIGNALS,
            ),
        );
    }

    /** @param array<string,array<string,mixed>> $documents @return array<string,mixed> */
    public function fromDocuments(array $documents): array
    {
        ksort($documents, SORT_STRING);
        $reports = array();
        $totals = array();
        $blockTypes = array();
        $signals = array();
        $signalCount = 0;
        foreach ($documents as $sourcePath => $document) {
            $report = $this->fromBlocks(
                is_array($document['blocks'] ?? null) ? $document['blocks'] : array(),
                (string) $sourcePath,
                is_string($document['serialized_blocks'] ?? null) ? $document['serialized_blocks'] : ''
            );
            $reports[] = array(
                'source_path' => (string) $sourcePath,
                'metrics' => $report['metrics'],
                'block_types' => $report['block_types'],
                'signals' => $report['signals'],
                'signal_totals' => $report['signal_totals'],
            );
            foreach ($report['metrics'] as $key => $value) {
                if ('max_nesting_depth' === $key) {
                    $totals[$key] = max((int) ($totals[$key] ?? 0), (int) $value);
                } elseif ('wrapper_to_content_ratio' !== $key) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
            }
            foreach ($report['block_types'] as $name => $count) $blockTypes[$name] = ($blockTypes[$name] ?? 0) + $count;
            $signals = array_merge($signals, $report['signals']);
            $signalCount += $report['signal_totals']['observed'];
        }
        $totals['document_count'] = count($reports);
        $totals['wrapper_to_content_ratio'] = 0 === ($totals['content_block_count'] ?? 0)
            ? (float) ($totals['wrapper_block_count'] ?? 0)
            : round($totals['wrapper_block_count'] / $totals['content_block_count'], 4);
        ksort($blockTypes, SORT_STRING);

        return array(
            'schema' => self::SCHEMA,
            'status' => 'observed',
            'enforcement' => 'report_only',
            'metrics' => $totals,
            'block_types' => $blockTypes,
            'documents' => $reports,
            'signals' => array_slice($signals, 0, self::MAX_REPORTED_SIGNALS),
            'signal_totals' => array(
                'observed' => $signalCount,
                'reported' => min($signalCount, self::MAX_REPORTED_SIGNALS),
                'omitted' => max(0, $signalCount - self::MAX_REPORTED_SIGNALS),
                'truncated' => $signalCount > self::MAX_REPORTED_SIGNALS,
            ),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,int> $path
     * @param array<string,int|float> $metrics
     * @param array<string,int> $blockTypes
     * @param array<int,array<string,mixed>> $signals
     */
    private function walk(array $blocks, int $depth, array $path, array &$metrics, array &$blockTypes, array &$signals, string $sourcePath): void
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) continue;
            $blockPath = array_merge($path, array($index));
            $name = is_string($block['blockName'] ?? null) && '' !== $block['blockName'] ? $block['blockName'] : 'core/freeform';
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array();
            $metrics['block_count']++;
            $metrics['max_nesting_depth'] = max($metrics['max_nesting_depth'], $depth);
            $blockTypes[$name] = ($blockTypes[$name] ?? 0) + 1;
            if (array() === $innerBlocks) $metrics['leaf_block_count']++;
            if ($this->isWrapper($name)) {
                $metrics['wrapper_block_count']++;
                if (array() === $innerBlocks && '' === trim(strip_tags((string) ($block['innerHTML'] ?? '')))) {
                    $metrics['empty_wrapper_count']++;
                    $signals[] = $this->signal('empty_wrapper', $sourcePath, $blockPath, $name);
                }
            } else {
                $metrics['content_block_count']++;
            }
            if ('core/html' === $name || 'core/freeform' === $name) {
                $metrics['raw_html_block_count']++;
                $signals[] = $this->signal('raw_html_block', $sourcePath, $blockPath, $name);
            }
            $className = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
            foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
                if (str_starts_with($class, 'blocks-engine-source-')) $metrics['source_marker_class_count']++;
                if (str_starts_with($class, 'be-inline-geometry-')) $metrics['generated_geometry_class_count']++;
            }
            $this->inspectAttributes($attrs, $name, $sourcePath, $blockPath, $metrics, $signals);
            if (array() !== $innerBlocks) $this->walk($innerBlocks, $depth + 1, $blockPath, $metrics, $blockTypes, $signals, $sourcePath);
        }
    }

    private function isWrapper(string $name): bool
    {
        return in_array($name, array('core/group', 'core/columns', 'core/column', 'core/buttons'), true);
    }

    /** @param array<string,mixed> $attrs @param array<int,int> $path @param array<string,int|float> $metrics @param array<int,array<string,mixed>> $signals */
    private function inspectAttributes(array $attrs, string $blockName, string $sourcePath, array $path, array &$metrics, array &$signals): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($attrs), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $key => $value) {
            if (!is_string($value) || !$this->containsStructuralHtml($value)) continue;
            $metrics['html_bearing_attribute_count']++;
            $kind = 'html_bearing_attribute';
            if ('core/table' === $blockName && 'content' === (string) $key) {
                $metrics['html_bearing_table_cell_count']++;
                $kind = 'html_bearing_table_cell';
            } elseif (in_array((string) $key, self::RICH_TEXT_ATTRIBUTES[$blockName] ?? array(), true)) {
                $metrics['structural_rich_text_attribute_count']++;
                $metrics['structural_rich_text_attribute_bytes'] += strlen($value);
                $kind = 'structural_rich_text_attribute';
            }
            $signals[] = $this->signal($kind, $sourcePath, $path, $blockName, (string) $key);
        }
    }

    private function containsStructuralHtml(string $value): bool
    {
        if (!preg_match_all('/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/i', $value, $matches)) return false;
        foreach ($matches[1] as $tag) if (!in_array(strtolower((string) $tag), self::INLINE_RICH_TEXT_TAGS, true)) return true;
        return false;
    }

    /** @param array<int,int> $path @return array<string,mixed> */
    private function signal(string $kind, string $sourcePath, array $path, string $blockName, string $attribute = ''): array
    {
        return array_filter(array(
            'kind' => $kind,
            'source_path' => $sourcePath,
            'block_path' => implode('.', $path),
            'block_name' => $blockName,
            'attribute' => $attribute,
        ), static fn(string $value): bool => '' !== $value);
    }
}
