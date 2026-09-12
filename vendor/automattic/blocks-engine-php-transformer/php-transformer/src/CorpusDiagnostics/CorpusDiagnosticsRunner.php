<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * Drives the HTML transformer over an entire website-fixture corpus and produces
 * a frequency-ranked findings worklist. Pure PHP: no runner, no WordPress
 * runtime, no browser, no network.
 *
 * The corpus layout it expects mirrors fixtures/websites/<fixture>/, where each
 * fixture directory holds one or more *.html files and an optional fixture.json
 * describing its class and tags.
 */
final class CorpusDiagnosticsRunner
{
    public const SCHEMA = 'blocks-engine/php-transformer/corpus-diagnostics-report/v1';

    private HtmlTransformer $transformer;

    public function __construct(?HtmlTransformer $transformer = null)
    {
        $this->transformer = $transformer ?? new HtmlTransformer();
    }

    /**
     * Run the harness over every HTML document under the corpus root.
     *
     * @param string $corpusDir Absolute path to fixtures/websites.
     * @return array<string, mixed> Machine-readable report.
     */
    public function run(string $corpusDir): array
    {
        $documents = $this->discoverDocuments($corpusDir);

        $fixtures = array();
        $clusters = array();
        $totals = array(
            'fixture_count'       => count(array_unique(array_map(static fn (array $doc): string => $doc['fixture'], $documents))),
            'document_count'      => 0,
            'block_count'         => 0,
            'native_count'        => 0,
            'core_html_count'     => 0,
            'freeform_count'      => 0,
            'invalid_block_count' => 0,
            'richtext_invalid_risk_count' => 0,
            'svg_content_lost_count' => 0,
            'layout_direction_misrecognition_count' => 0,
            'var_ref_count'       => 0,
            'var_custom_ref_count' => 0,
            'media_text_count'                               => 0,
            'media_text_decline_media_impure_count'          => 0,
            'media_text_decline_no_text_side_count'          => 0,
            'media_text_decline_vertical_or_reversed_count'  => 0,
            'media_text_decline_unsafe_url_count'            => 0,
            'media_text_width_oob_count'                     => 0,
            'media_text_decline_linked_video_count'          => 0,
            'media_text_decline_other_count'                 => 0,
            'media_text_diagnostic_error_count'              => 0,
            'finding_count'       => 0,
        );

        foreach ( $documents as $document ) {
            $html = (string) file_get_contents($document['path']);
            $result = $this->transformer->transform($html, array())->toArray();
            $collected = CorpusDetectors::collect($result, $html, $this->columnsVerifier());

            $relPath = $document['rel'];
            $fixtures[$relPath] = array(
                'fixture'   => $document['fixture'],
                'class'     => $document['class'],
                'tags'      => $document['tags'],
                'status'    => (string) ($result['status'] ?? 'unknown'),
                'metrics'   => $collected['metrics'],
                'var_names' => $collected['var_names'],
                'clusters'  => $this->summarizeFindings($collected['findings']),
            );

            $metrics = $collected['metrics'];
            $totals['document_count']++;
            $totals['block_count'] += (int) $metrics['block_count'];
            $totals['native_count'] += (int) $metrics['native_count'];
            $totals['core_html_count'] += (int) $metrics['core_html_count'];
            $totals['freeform_count'] += (int) $metrics['freeform_count'];
            $totals['invalid_block_count'] += (int) $metrics['invalid_block_count'];
            $totals['richtext_invalid_risk_count'] += (int) $metrics['richtext_invalid_risk_count'];
            $totals['svg_content_lost_count'] += (int) $metrics['svg_content_lost_count'];
            $totals['layout_direction_misrecognition_count'] += (int) $metrics['layout_direction_misrecognition_count'];
            $totals['var_ref_count'] += (int) $metrics['var_ref_count'];
            $totals['var_custom_ref_count'] += (int) $metrics['var_custom_ref_count'];
            foreach ( array(
                'media_text_count',
                'media_text_decline_media_impure_count',
                'media_text_decline_no_text_side_count',
                'media_text_decline_vertical_or_reversed_count',
                'media_text_decline_unsafe_url_count',
                'media_text_width_oob_count',
                'media_text_decline_linked_video_count',
                'media_text_decline_other_count',
                'media_text_diagnostic_error_count',
            ) as $metricName ) {
                $totals[ $metricName ] += (int) $metrics[ $metricName ];
            }

            foreach ( $collected['findings'] as $finding ) {
                $key = CorpusDetectors::clusterKey($finding);
                $count = (int) ($finding['count'] ?? 1);
                $totals['finding_count'] += $count;

                if ( ! isset($clusters[$key]) ) {
                    $clusters[$key] = array(
                        'key'           => $key,
                        'repair_bucket' => (string) ($finding['repair_bucket'] ?? ''),
                        'detector'      => (string) ($finding['detector'] ?? ''),
                        'source'        => (string) ($finding['source'] ?? ''),
                        'pattern'       => (string) ($finding['pattern'] ?? ''),
                        'severity'      => (string) ($finding['severity'] ?? CorpusDetectors::SEVERITY_MEDIUM),
                        'count'         => 0,
                        'fixtures'      => array(),
                    );
                }
                $clusters[$key]['count'] += $count;
                $clusters[$key]['fixtures'][$relPath] = true;
            }
        }

        $ranked = $this->rankClusters($clusters);
        $totals['native_rate'] = $totals['block_count'] > 0
            ? round($totals['native_count'] / $totals['block_count'], 4)
            : 0.0;

        ksort($fixtures);

        return array(
            'schema'       => self::SCHEMA,
            'generated_at' => gmdate('c'),
            'corpus_dir'   => $corpusDir,
            'totals'       => $totals,
            'clusters'     => $ranked,
            'fixtures'     => $fixtures,
        );
    }

    /**
     * Render the ranked clusters as a concise human-readable worklist.
     *
     * @param array<string, mixed> $report
     */
    public function renderSummary(array $report, int $limit = 25): string
    {
        $totals = $report['totals'];
        $lines = array();
        $lines[] = 'Corpus diagnostics — ' . $totals['document_count'] . ' document(s) across ' . $totals['fixture_count'] . ' fixture(s)';
        $lines[] = sprintf(
            'blocks=%d native_rate=%.1f%% findings=%d',
            $totals['block_count'],
            ((float) $totals['native_rate']) * 100,
            $totals['finding_count']
        );
        $lines[] = sprintf(
            'EDITOR-INVALID RISK: richtext_invalid_risk=%d block(s) — unsupported styled inline RichText nodes.',
            (int) ($totals['richtext_invalid_risk_count'] ?? 0)
        );
        $lines[] = sprintf(
            '  (structural wp_block_validity=%d is a serialization round-trip count, NOT proof of "no invalid content".)',
            (int) ($totals['invalid_block_count'] ?? 0)
        );
        $lines[] = sprintf(
            'MISSING ARTWORK: svg_content_lost=%d   LAYOUT: columns_from_vertical_flex=%d',
            (int) ($totals['svg_content_lost_count'] ?? 0),
            (int) ($totals['layout_direction_misrecognition_count'] ?? 0)
        );
        $lines[] = sprintf(
            'MEDIA-TEXT: media_text_count=%d media_text_decline_media_impure_count=%d media_text_decline_no_text_side_count=%d media_text_decline_vertical_or_reversed_count=%d media_text_decline_unsafe_url_count=%d media_text_width_oob_count=%d media_text_decline_linked_video_count=%d media_text_decline_other_count=%d media_text_diagnostic_error_count=%d',
            (int) ($totals['media_text_count'] ?? 0),
            (int) ($totals['media_text_decline_media_impure_count'] ?? 0),
            (int) ($totals['media_text_decline_no_text_side_count'] ?? 0),
            (int) ($totals['media_text_decline_vertical_or_reversed_count'] ?? 0),
            (int) ($totals['media_text_decline_unsafe_url_count'] ?? 0),
            (int) ($totals['media_text_width_oob_count'] ?? 0),
            (int) ($totals['media_text_decline_linked_video_count'] ?? 0),
            (int) ($totals['media_text_decline_other_count'] ?? 0),
            (int) ($totals['media_text_diagnostic_error_count'] ?? 0)
        );
        $lines[] = sprintf(
            'INFORMATIONAL var density (materialized downstream by SSI — not a repair gap): var_refs=%d (custom=%d)',
            (int) $totals['var_ref_count'],
            (int) $totals['var_custom_ref_count']
        );
        $lines[] = '';
        $lines[] = 'TOP RANKED CLUSTERS (severity | occurrences :: cluster :: fixtures):';

        $rank = 0;
        foreach ( $report['clusters'] as $cluster ) {
            if ( $rank >= $limit ) {
                break;
            }
            ++$rank;
            $examples = implode(', ', array_slice($cluster['example_fixtures'], 0, 3));
            $lines[] = sprintf(
                '%2d. [%-6s %4d in %3d files] %s',
                $rank,
                strtoupper((string) ($cluster['severity'] ?? CorpusDetectors::SEVERITY_MEDIUM)),
                $cluster['count'],
                $cluster['fixture_count'],
                $cluster['key']
            );
            if ( '' !== $examples ) {
                $lines[] = '      e.g. ' . $examples;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Discover every HTML document and resolve its fixture metadata.
     *
     * @return array<int, array{path: string, rel: string, fixture: string, class: string, tags: array<int, string>}>
     */
    private function discoverDocuments(string $corpusDir): array
    {
        $entries = glob(rtrim($corpusDir, '/') . '/*', GLOB_ONLYDIR);
        if ( false === $entries ) {
            return array();
        }
        sort($entries);

        $documents = array();
        foreach ( $entries as $fixtureDir ) {
            $fixture = basename($fixtureDir);
            $meta = $this->loadFixtureMeta($fixtureDir);
            $htmlFiles = $this->findHtmlFiles($fixtureDir);
            foreach ( $htmlFiles as $htmlFile ) {
                $rel = $fixture . '/' . ltrim(substr($htmlFile, strlen($fixtureDir)), '/');
                $documents[] = array(
                    'path'    => $htmlFile,
                    'rel'     => $rel,
                    'fixture' => $fixture,
                    'class'   => $meta['class'],
                    'tags'    => $meta['tags'],
                );
            }
        }

        return $documents;
    }

    /**
     * @return array{class: string, tags: array<int, string>}
     */
    private function loadFixtureMeta(string $fixtureDir): array
    {
        $path = $fixtureDir . '/fixture.json';
        if ( ! is_file($path) ) {
            return array('class' => '', 'tags' => array());
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if ( ! is_array($decoded) ) {
            return array('class' => '', 'tags' => array());
        }
        $tags = array();
        if ( is_array($decoded['tags'] ?? null) ) {
            foreach ( $decoded['tags'] as $tag ) {
                if ( is_string($tag) ) {
                    $tags[] = $tag;
                }
            }
        }

        return array(
            'class' => is_string($decoded['fixture_class'] ?? null) ? $decoded['fixture_class'] : (is_string($decoded['class'] ?? null) ? $decoded['class'] : ''),
            'tags'  => $tags,
        );
    }

    /**
     * @return array<int, string>
     */
    private function findHtmlFiles(string $fixtureDir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fixtureDir, \FilesystemIterator::SKIP_DOTS)
        );
        $files = array();
        foreach ( $iterator as $file ) {
            if ( $file instanceof \SplFileInfo && 'html' === strtolower($file->getExtension()) ) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Collapse a document's findings into per-cluster counts for the per-fixture
     * detail section.
     *
     * @param array<int, array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function summarizeFindings(array $findings): array
    {
        $summary = array();
        foreach ( $findings as $finding ) {
            $key = CorpusDetectors::clusterKey($finding);
            $summary[$key] = ($summary[$key] ?? 0) + (int) ($finding['count'] ?? 1);
        }
        arsort($summary);

        return $summary;
    }

    /**
     * Sort clusters by total occurrences, then fixture spread, then key, and
     * attach the example-fixture list.
     *
     * @param array<string, array<string, mixed>> $clusters
     * @return array<int, array<string, mixed>>
     */
    private function rankClusters(array $clusters): array
    {
        $ranked = array();
        foreach ( $clusters as $cluster ) {
            $fixtures = array_keys($cluster['fixtures']);
            sort($fixtures);
            $cluster['fixture_count'] = count($fixtures);
            $cluster['example_fixtures'] = array_slice($fixtures, 0, 5);
            unset($cluster['fixtures']);
            $ranked[] = $cluster;
        }

        usort($ranked, static function (array $a, array $b): int {
            $severityA = CorpusDetectors::severityRank((string) ($a['severity'] ?? CorpusDetectors::SEVERITY_MEDIUM));
            $severityB = CorpusDetectors::severityRank((string) ($b['severity'] ?? CorpusDetectors::SEVERITY_MEDIUM));

            return ($severityB <=> $severityA)
                ?: ($b['count'] <=> $a['count'])
                ?: ($b['fixture_count'] <=> $a['fixture_count'])
                ?: strcmp($a['key'], $b['key']);
        });

        return $ranked;
    }

    /**
     * Predicate the layout-direction detector uses to confirm that a vertical
     * flex source fragment really converts to a top-level core/columns block,
     * rather than core/group/core/list. Runs the same transformer over the
     * isolated element so misrecognitions are reported only when they actually
     * occur.
     *
     * @return callable(string): bool
     */
    private function columnsVerifier(): callable
    {
        return function (string $fragment): bool {
            $result = $this->transformer->transform($fragment, array())->toArray();
            $blocks = is_array($result['blocks'] ?? null) ? $result['blocks'] : array();
            $first = $blocks[0] ?? null;

            return is_array($first) && 'core/columns' === ($first['blockName'] ?? '');
        };
    }

}
