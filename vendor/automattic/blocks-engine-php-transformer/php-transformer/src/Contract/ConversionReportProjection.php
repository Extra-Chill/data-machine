<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

final class ConversionReportProjection
{
    public const SCHEMA = 'blocks-engine/php-transformer/conversion-report/v1';

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<string, mixed> $sourceReports
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $provenance
     * @param array<string, int|float> $metrics
     * @return array<string, mixed>
     */
    public static function fromResultParts(string $sourceFormat, array $blocks, array $fallbacks, array $sourceReports, array $assets, array $provenance, array $metrics): array
    {
        $report = array(
            'schema'                => self::SCHEMA,
            'source_format'         => $sourceFormat,
            'source'                => self::firstString($provenance, 'source'),
            'scope'                 => self::firstString($provenance, 'scope'),
            'source_summary'        => self::sourceSummary($sourceFormat, $blocks, $fallbacks, $sourceReports, $assets, $metrics),
            'selector_summary'      => self::selectorSummary($sourceReports, $fallbacks),
            'fallback_diagnostics'  => self::fallbackDiagnostics($fallbacks),
            'asset_refs'            => self::assetReferences($blocks, $sourceReports),
            'navigation_candidates' => self::navigationCandidates($blocks, $sourceReports),
            'presentation_gaps'     => self::presentationGaps($sourceReports),
            'metrics'               => $metrics,
        );

        return array_filter($report, static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<string, mixed> $sourceReports
     * @param array<int, array<string, mixed>> $assets
     * @param array<string, int|float> $metrics
     * @return array<string, mixed>
     */
    private static function sourceSummary(string $sourceFormat, array $blocks, array $fallbacks, array $sourceReports, array $assets, array $metrics): array
    {
        $artifact = is_array($sourceReports['artifact'] ?? null) ? $sourceReports['artifact'] : array();
        $compiledSite = is_array($sourceReports['compiled_site'] ?? null) ? $sourceReports['compiled_site'] : array();
        $materializationPlan = is_array($sourceReports['materialization_plan'] ?? null) ? $sourceReports['materialization_plan'] : array();
        $materializationTotals = is_array($materializationPlan['totals'] ?? null) ? $materializationPlan['totals'] : array();

        return array_filter(
            array(
                'source_format'    => $sourceFormat,
                'entry_path'       => $artifact['entry_path'] ?? ($compiledSite['entry_path'] ?? ''),
                'file_count'       => $artifact['file_count'] ?? null,
                'accepted_count'   => $artifact['accepted_count'] ?? null,
                'rejected_count'   => $artifact['rejected_count'] ?? null,
                'page_count'       => $materializationTotals['pages'] ?? (isset($compiledSite['pages']) && is_array($compiledSite['pages']) ? count($compiledSite['pages']) : null),
                'asset_count'      => $materializationTotals['assets'] ?? (0 < count($assets) ? count($assets) : null),
                'route_count'      => $materializationTotals['routes'] ?? null,
                'navigation_link_count' => $materializationTotals['navigation_links'] ?? null,
                'menu_count'       => $materializationTotals['menus'] ?? null,
                'block_count'      => $metrics['block_count'] ?? self::countBlocks($blocks),
                'fallback_count'   => $metrics['fallback_count'] ?? count($fallbacks),
                'diagnostic_count' => $metrics['diagnostic_count'] ?? null,
            ),
            static fn (mixed $value): bool => null !== $value && '' !== $value
        );
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>
     */
    private static function selectorSummary(array $sourceReports, array $fallbacks): array
    {
        $selectors = array();
        $sources = array();

        foreach ( self::sourceProvenance($sourceReports) as $entry ) {
            self::appendSelector($selectors, $entry, 'block');
            self::appendSourcePath($sources, $entry);
        }

        foreach ( self::fallbackDiagnostics($fallbacks) as $entry ) {
            self::appendSelector($selectors, $entry, 'fallback');
            self::appendSourcePath($sources, $entry);
        }

        foreach ( self::referenceReports($sourceReports) as $entry ) {
            self::appendSelector($selectors, $entry, 'reference');
            self::appendSourcePath($sources, $entry);
        }

        return array_filter(
            array(
                'selectors'    => array_values($selectors),
                'source_paths' => array_values(array_unique($sources)),
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private static function fallbackDiagnostics(array $fallbacks): array
    {
        $diagnostics = array();
        foreach ( $fallbacks as $index => $fallback ) {
            $diagnostics[] = array_filter(
                array(
                    'index'           => $index,
                    'type'            => $fallback['type'] ?? '',
                    'reason'          => $fallback['reason'] ?? '',
                    'diagnostic_code' => $fallback['diagnostic_code'] ?? '',
                    'source_format'   => $fallback['source_format'] ?? '',
                    'source'          => $fallback['source'] ?? '',
                    'scope'           => $fallback['scope'] ?? '',
                    'source_path'     => $fallback['source_path'] ?? '',
                    'tag'             => $fallback['tag'] ?? '',
                    'selector'        => $fallback['selector'] ?? '',
                    'child_count'     => $fallback['child_count'] ?? null,
                    'control_count'   => $fallback['control_count'] ?? null,
                    'form'            => $fallback['form'] ?? array(),
                    'control'         => $fallback['control'] ?? array(),
                    'controls'        => $fallback['controls'] ?? array(),
                    'context'         => $fallback['context'] ?? array(),
                    'events'          => $fallback['events'] ?? array(),
                    'html_bytes'      => $fallback['html_bytes'] ?? (isset($fallback['html']) && is_string($fallback['html']) ? strlen($fallback['html']) : null),
                    'html_truncated'  => $fallback['html_truncated'] ?? null,
                ),
                static fn (mixed $value): bool => null !== $value && '' !== $value
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function assetReferences(array $blocks, array $sourceReports): array
    {
        $references = self::artifactAssetReferences($sourceReports);
        if ( array() !== $references ) {
            return self::dedupeRows($references);
        }

        self::collectBlockAssetReferences($blocks, 'blocks', $references);

        return self::dedupeRows($references);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function navigationCandidates(array $blocks, array $sourceReports): array
    {
        $candidates = array();
        foreach ( self::artifactInternalLinks($sourceReports) as $link ) {
            $candidates[] = array_filter(
                array(
                    'source'      => 'artifact_reference',
                    'source_path' => $link['source_path'] ?? '',
                    'selector'    => $link['selector'] ?? '',
                    'url'         => $link['url'] ?? '',
                    'target_path' => $link['target_path'] ?? '',
                ),
                static fn (mixed $value): bool => '' !== $value
            );
        }

        self::collectBlockNavigationCandidates($blocks, 'blocks', $candidates);

        return self::dedupeRows($candidates);
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function presentationGaps(array $sourceReports): array
    {
        $gaps = array();

        $html = is_array($sourceReports['html'] ?? null) ? $sourceReports['html'] : array();
        $signals = is_array($html['presentation_signals'] ?? null) ? $html['presentation_signals'] : array();
        foreach ( $signals as $signal ) {
            if ( ! is_array($signal) ) {
                continue;
            }
            $gaps[] = array_filter(
                array(
                    'type'       => 'source_presentation_signal',
                    'block_name' => $signal['block_name'] ?? '',
                    'tag'        => $signal['tag'] ?? '',
                    'selector'   => $signal['selector'] ?? '',
                    'signals'    => $signal['signals'] ?? array(),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            );
        }

        $structureSignals = is_array($html['structure_signals'] ?? null) ? $html['structure_signals'] : array();
        foreach ( $structureSignals as $signal ) {
            if ( ! is_array($signal) ) {
                continue;
            }
            $gaps[] = array_filter(
                array(
                    'type'       => 'source_structure_signal',
                    'block_name' => $signal['block_name'] ?? '',
                    'tag'        => $signal['tag'] ?? '',
                    'selector'   => $signal['selector'] ?? '',
                    'signals'    => $signal['signals'] ?? array(),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            );
        }

        $compiledSite = is_array($sourceReports['compiled_site'] ?? null) ? $sourceReports['compiled_site'] : array();
        $visualRepair = is_array($compiledSite['visual_repair'] ?? null) ? $compiledSite['visual_repair'] : array();
        $stylesheets = is_array($visualRepair['stylesheets'] ?? null) ? $visualRepair['stylesheets'] : array();
        foreach ( $stylesheets as $stylesheet ) {
            if ( ! is_array($stylesheet) ) {
                continue;
            }
            $gaps[] = array_filter(
                array(
                    'type'      => 'presentation_stylesheet',
                    'path'      => $stylesheet['path'] ?? '',
                    'role'      => $stylesheet['role'] ?? '',
                    'intent'    => $stylesheet['intent'] ?? '',
                    'mime_type' => $stylesheet['mime_type'] ?? '',
                    'bytes'     => $stylesheet['bytes'] ?? null,
                ),
                static fn (mixed $value): bool => null !== $value && '' !== $value
            );
        }

        return $gaps;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private static function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += self::countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $provenance
     */
    private static function firstString(array $provenance, string $key): string
    {
        foreach ( $provenance as $entry ) {
            if ( is_array($entry) && is_string($entry[$key] ?? null) && '' !== $entry[$key] ) {
                return $entry[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function sourceProvenance(array $sourceReports): array
    {
        $html = is_array($sourceReports['html'] ?? null) ? $sourceReports['html'] : array();
        return is_array($html['source_provenance'] ?? null) ? $html['source_provenance'] : array();
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function referenceReports(array $sourceReports): array
    {
        return array_merge(self::artifactInternalLinks($sourceReports), self::artifactAssetReferences($sourceReports));
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function artifactInternalLinks(array $sourceReports): array
    {
        $artifact = is_array($sourceReports['artifact'] ?? null) ? $sourceReports['artifact'] : array();
        return is_array($artifact['internal_links'] ?? null) ? $artifact['internal_links'] : array();
    }

    /**
     * @param array<string, mixed> $sourceReports
     * @return array<int, array<string, mixed>>
     */
    private static function artifactAssetReferences(array $sourceReports): array
    {
        $artifact = is_array($sourceReports['artifact'] ?? null) ? $sourceReports['artifact'] : array();
        return is_array($artifact['asset_references'] ?? null) ? $artifact['asset_references'] : array();
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @param array<string, mixed> $entry
     */
    private static function appendSelector(array &$selectors, array $entry, string $kind): void
    {
        $selector = (string) ($entry['selector'] ?? '');
        if ( '' === $selector ) {
            return;
        }

        $selectors[] = array_filter(
            array(
                'kind'        => $kind,
                'selector'    => $selector,
                'source_path' => $entry['source_path'] ?? '',
                'block_name'  => $entry['block_name'] ?? '',
                'tag'         => $entry['tag'] ?? ($entry['element'] ?? ''),
                'attribute'   => $entry['attribute'] ?? '',
            ),
            static fn (mixed $value): bool => '' !== $value
        );
    }

    /**
     * @param array<int, string> $sources
     * @param array<string, mixed> $entry
     */
    private static function appendSourcePath(array &$sources, array $entry): void
    {
        $sourcePath = (string) ($entry['source_path'] ?? '');
        if ( '' !== $sourcePath ) {
            $sources[] = $sourcePath;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $references
     */
    private static function collectBlockAssetReferences(array $blocks, string $path, array &$references): void
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $blockPath = $path . '.' . $index;
            $blockName = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            foreach ( array('url', 'src', 'href', 'poster') as $attribute ) {
                if ( is_string($attrs[$attribute] ?? null) && '' !== $attrs[$attribute] ) {
                    $references[] = array(
                        'source'     => 'block_attribute',
                        'block_path' => $blockPath,
                        'block_name' => $blockName,
                        'attribute'  => $attribute,
                        'url'        => $attrs[$attribute],
                    );
                }
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                self::collectBlockAssetReferences($block['innerBlocks'], $blockPath . '.innerBlocks', $references);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function collectBlockNavigationCandidates(array $blocks, string $path, array &$candidates): void
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $blockPath = $path . '.' . $index;
            $blockName = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            if ( 'core/navigation-link' === $blockName ) {
                $candidates[] = array_filter(
                    array(
                        'source'     => 'block',
                        'block_path' => $blockPath,
                        'block_name' => $blockName,
                        'label'      => $attrs['label'] ?? '',
                        'url'        => $attrs['url'] ?? '',
                        'kind'       => $attrs['kind'] ?? '',
                    ),
                    static fn (mixed $value): bool => '' !== $value
                );
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                self::collectBlockNavigationCandidates($block['innerBlocks'], $blockPath . '.innerBlocks', $candidates);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function dedupeRows(array $rows): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
