<?php
/**
 * Static visual-parity gate harness.
 *
 * Runs the deterministic, render-free static style parity signal
 * ({@see StaticStyleParityRunner}) over website fixtures: it transforms each
 * source document, rebuilds a candidate from the serialized blocks, and compares
 * the effective author-CSS styling of source vs candidate per element. Pure PHP —
 * no runner, no WordPress runtime, no browser, no network, no rasterization — so
 * the whole corpus processes in seconds and the output is byte-identical run to
 * run.
 *
 * This is the primary parity signal that replaces flaky full-page pixelmatch:
 * it yields a stable 0..1 score plus a per-element / per-property diff naming
 * exactly which CSS property on which selector diverged.
 *
 * Usage:
 *   php tools/static-parity/run.php [--corpus <dir>] [--fixture <id>]
 *                                   [--entry <file>] [--json] [--top <n>]
 *                                   [--fail-under <score>]
 *
 * Options:
 *   --corpus <dir>      Corpus root (default: <repo>/fixtures/websites).
 *   --fixture <id>      Limit to a single fixture directory name (e.g. 15-saas).
 *   --entry <file>      Entry HTML filename within each fixture (default: auto;
 *                       prefers index.html, else the first *.html).
 *   --json              Emit the machine-readable JSON report to stdout.
 *   --top <n>           Number of lowest-scoring fixtures to print (default: 25).
 *   --fail-under <s>    Exit non-zero if any evaluated fixture scores below <s>.
 *                       Omit to run in report-only mode (always exit 0).
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$options = parseArguments($argv);
$repoRoot = dirname(__DIR__, 3);
$corpusDir = $options['corpus'] ?? $repoRoot . '/fixtures/websites';
if ( ! is_dir($corpusDir) ) {
    fwrite(STDERR, "Corpus directory not found: {$corpusDir}\n");
    exit(2);
}

$runner = new StaticStyleParityRunner();
$results = array();

foreach ( discoverFixtures($corpusDir, $options['fixture'] ?? null) as $fixture ) {
    $entry = resolveEntry($fixture['dir'], $options['entry'] ?? null);
    if ( null === $entry ) {
        continue;
    }
    $sourceHtml = (string) file_get_contents($entry);
    $css = authorCss($sourceHtml, dirname($entry));
    $report = $runner->compareSourceToTransform($sourceHtml, $css);
    $parity = is_array($report['parity'] ?? null) ? $report['parity'] : array();
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();
    $results[] = array(
        'fixture' => $fixture['id'],
        'entry' => basename($entry),
        'status' => (string) ($report['status'] ?? 'unknown'),
        'score' => (float) ($parity['score'] ?? 0.0),
        'property_parity' => (float) ($parity['property_parity'] ?? 0.0),
        'coverage' => (float) ($parity['coverage'] ?? 0.0),
        'source_total' => (int) ($summary['source_total'] ?? 0),
        'matched_total' => (int) ($summary['matched_total'] ?? 0),
        'finding_total' => (int) ($summary['finding_total'] ?? 0),
    );
}

usort($results, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: strcmp($a['fixture'], $b['fixture']));

$report = array(
    'schema' => 'blocks-engine/php-transformer/static-parity-gate-report/v1',
    'corpus_dir' => $corpusDir,
    'fixture_count' => count($results),
    'results' => $results,
);

if ( isset($options['json']) ) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, ( false === $json ? '{}' : $json ) . "\n");
} else {
    fwrite(STDOUT, renderSummary($results, (int) ($options['top'] ?? 25)));
}

if ( array_key_exists('fail-under', $options) ) {
    $threshold = (float) $options['fail-under'];
    $failed = array_filter($results, static fn (array $r): bool => $r['score'] < $threshold);
    if ( array() !== $failed ) {
        fwrite(STDERR, sprintf("\nStatic parity gate FAILED: %d fixture(s) below score %.4f.\n", count($failed), $threshold));
        foreach ( $failed as $r ) {
            fwrite(STDERR, sprintf("  %-28s score=%.4f coverage=%.4f findings=%d\n", $r['fixture'], $r['score'], $r['coverage'], $r['finding_total']));
        }
        exit(1);
    }
    fwrite(STDOUT, sprintf("\nStatic parity gate PASSED: all %d fixture(s) >= score %.4f.\n", count($results), $threshold));
}

exit(0);

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $options = array();
    $count = count($argv);
    for ( $i = 1; $i < $count; $i++ ) {
        $arg = $argv[$i];
        if ( '--json' === $arg ) {
            $options['json'] = '1';
            continue;
        }
        if ( str_starts_with($arg, '--') ) {
            $key = substr($arg, 2);
            $value = ($i + 1 < $count && ! str_starts_with($argv[$i + 1], '--')) ? $argv[++$i] : '1';
            $options[$key] = $value;
        }
    }

    return $options;
}

/**
 * @return array<int, array{id: string, dir: string}>
 */
function discoverFixtures(string $corpusDir, ?string $only): array
{
    $fixtures = array();
    foreach ( (array) glob($corpusDir . '/*', GLOB_ONLYDIR) as $dir ) {
        $id = basename((string) $dir);
        if ( null !== $only && $id !== $only ) {
            continue;
        }
        $fixtures[] = array('id' => $id, 'dir' => (string) $dir);
    }
    usort($fixtures, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

    return $fixtures;
}

function resolveEntry(string $dir, ?string $entry): ?string
{
    if ( null !== $entry ) {
        $path = $dir . '/' . $entry;
        return is_file($path) ? $path : null;
    }
    if ( is_file($dir . '/index.html') ) {
        return $dir . '/index.html';
    }
    $candidates = (array) glob($dir . '/*.html');
    sort($candidates);

    return array() !== $candidates ? (string) $candidates[0] : null;
}

function authorCss(string $html, string $dir): string
{
    $css = '';
    if ( preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $styles) ) {
        $css .= implode("\n", $styles[1]);
    }
    if ( preg_match_all('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $html, $links) ) {
        foreach ( $links[0] as $tag ) {
            if ( ! preg_match('/href=["\']([^"\']+)["\']/i', $tag, $href) ) {
                continue;
            }
            if ( preg_match('#^https?://#i', $href[1]) ) {
                continue;
            }
            $path = $dir . '/' . ltrim($href[1], '/');
            if ( is_file($path) ) {
                $css .= "\n" . (string) file_get_contents($path);
            }
        }
    }

    return $css;
}

/**
 * @param array<int, array<string, mixed>> $results
 */
function renderSummary(array $results, int $top): string
{
    $out = sprintf("Static visual-parity gate — %d fixture(s) (render-free, deterministic)\n", count($results));
    $out .= str_repeat('-', 92) . "\n";
    $out .= sprintf("%-28s %-8s %8s %10s %9s %8s %9s\n", 'fixture', 'status', 'score', 'prop-par', 'coverage', 'matched', 'findings');
    $out .= str_repeat('-', 92) . "\n";
    foreach ( array_slice($results, 0, $top) as $r ) {
        $out .= sprintf(
            "%-28s %-8s %8.4f %10.4f %9.4f %8s %9d\n",
            $r['fixture'],
            $r['status'],
            $r['score'],
            $r['property_parity'],
            $r['coverage'],
            $r['matched_total'] . '/' . $r['source_total'],
            $r['finding_total']
        );
    }

    return $out;
}
