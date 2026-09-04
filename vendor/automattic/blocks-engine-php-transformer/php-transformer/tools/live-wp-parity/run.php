<?php
/**
 * Live-WP visual-parity gate harness.
 *
 * Companion to tools/static-parity/run.php. The render-free static gate builds the
 * candidate from serialized blocks and never exercises WordPress's own block
 * rendering + global-styles layer. This harness instead consumes a candidate HTML
 * document produced by a REAL WordPress render — the rendered DOM HTML that
 * wp-codebox fetches after Static Site Importer import + theme activate — and runs
 * the IDENTICAL deterministic probe + comparator
 * ({@see StaticStyleParityRunner::compareSourceToCandidate}).
 *
 * It is still pure PHP: no browser, no rasterization, no network, no screenshots.
 * The candidate's effective styling is resolved statically from whatever CSS the
 * rendered DOM already carries (WP global-styles / block-supports / layout <style>
 * blocks) plus any explicit --candidate-css the caller inlined. Same inputs ->
 * byte-identical report.
 *
 * Output is the same VisualParityReportContract report as the static gate, under
 * the `live_wp` key. With --with-proxy it also computes the render-free proxy score
 * for the same source so callers (e.g. homeboy-rigs) can surface live-WP parity
 * alongside the render-free proxy parity and read the delta directly.
 *
 * Usage:
 *   php tools/live-wp-parity/run.php --source <file> --candidate <file>
 *       [--source-css <file>] [--candidate-css <file>]
 *       [--with-proxy] [--json] [--fail-under <score>]
 *
 * Options:
 *   --source <file>        Source HTML document (required).
 *   --candidate <file>     Live-WP rendered DOM HTML document (required).
 *   --source-css <file>    CSS for the source side. When omitted, author CSS is
 *                          auto-extracted from the source's own <style> blocks and
 *                          same-origin linked stylesheets (matching the static gate).
 *   --candidate-css <file> Extra CSS for the candidate side. When omitted, empty —
 *                          a self-contained rendered DOM carries its own styles.
 *   --with-proxy           Also compute the render-free proxy score for --source and
 *                          report the live-WP vs proxy comparison.
 *   --json                 Emit the machine-readable JSON report to stdout.
 *   --fail-under <s>       Exit non-zero if the live-WP score is below <s>.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$options = parseArguments($argv);

$sourcePath = $options['source'] ?? null;
$candidatePath = $options['candidate'] ?? null;
if ( null === $sourcePath || null === $candidatePath ) {
    fwrite(STDERR, "Usage: php tools/live-wp-parity/run.php --source <file> --candidate <file> [--source-css <file>] [--candidate-css <file>] [--with-proxy] [--json] [--fail-under <score>]\n");
    exit(2);
}
if ( ! is_file($sourcePath) ) {
    fwrite(STDERR, "Source file not found: {$sourcePath}\n");
    exit(2);
}
if ( ! is_file($candidatePath) ) {
    fwrite(STDERR, "Candidate file not found: {$candidatePath}\n");
    exit(2);
}

$sourceHtml = (string) file_get_contents($sourcePath);
$candidateHtml = (string) file_get_contents($candidatePath);

$sourceCss = isset($options['source-css'])
    ? readCssFile((string) $options['source-css'])
    : authorCss($sourceHtml, dirname($sourcePath));
$candidateCss = isset($options['candidate-css'])
    ? readCssFile((string) $options['candidate-css'])
    : '';

$runner = new StaticStyleParityRunner();

$liveReport = $runner->compareSourceToCandidate($sourceHtml, $candidateHtml, $sourceCss, $candidateCss);
$liveScore = parityScore($liveReport);

$report = array(
    'schema' => 'blocks-engine/php-transformer/live-wp-parity-report/v1',
    'source' => basename($sourcePath),
    'candidate' => basename($candidatePath),
    'live_wp' => $liveReport,
);

if ( isset($options['with-proxy']) ) {
    $proxyReport = $runner->compareSourceToTransform($sourceHtml, $sourceCss);
    $proxyScore = parityScore($proxyReport);
    $report['render_free_proxy'] = $proxyReport;
    $report['comparison'] = array(
        'live_wp_score' => $liveScore,
        'proxy_score' => $proxyScore,
        'delta' => round($liveScore - $proxyScore, 4),
    );
}

if ( isset($options['json']) ) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, ( false === $json ? '{}' : $json ) . "\n");
} else {
    fwrite(STDOUT, renderSummary($report));
}

if ( array_key_exists('fail-under', $options) ) {
    $threshold = (float) $options['fail-under'];
    if ( $liveScore < $threshold ) {
        fwrite(STDERR, sprintf("\nLive-WP parity gate FAILED: score %.4f < %.4f.\n", $liveScore, $threshold));
        exit(1);
    }
    fwrite(STDOUT, sprintf("\nLive-WP parity gate PASSED: score %.4f >= %.4f.\n", $liveScore, $threshold));
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
        if ( '--with-proxy' === $arg ) {
            $options['with-proxy'] = '1';
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

function readCssFile(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * @param array<string, mixed> $report
 */
function parityScore(array $report): float
{
    $parity = is_array($report['parity'] ?? null) ? $report['parity'] : array();

    return (float) ($parity['score'] ?? 0.0);
}

/**
 * Auto-extract author CSS from a source document's own <style> blocks and
 * same-origin linked stylesheets. Mirrors tools/static-parity/run.php so the
 * source side is judged on identical CSS in both gates. Remote stylesheets are
 * skipped — nothing is fetched.
 */
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
 * @param array<string, mixed> $report
 */
function renderSummary(array $report): string
{
    $live = is_array($report['live_wp'] ?? null) ? $report['live_wp'] : array();
    $parity = is_array($live['parity'] ?? null) ? $live['parity'] : array();
    $summary = is_array($live['summary'] ?? null) ? $live['summary'] : array();

    $out = "Live-WP visual-parity gate (real WordPress render, deterministic DOM-HTML)\n";
    $out .= str_repeat('-', 72) . "\n";
    $out .= sprintf("source     : %s\n", (string) ($report['source'] ?? ''));
    $out .= sprintf("candidate  : %s\n", (string) ($report['candidate'] ?? ''));
    $out .= sprintf("status     : %s\n", (string) ($live['status'] ?? 'unknown'));
    $out .= sprintf("score      : %.4f\n", (float) ($parity['score'] ?? 0.0));
    $out .= sprintf("prop-parity: %.4f\n", (float) ($parity['property_parity'] ?? 0.0));
    $out .= sprintf("coverage   : %.4f\n", (float) ($parity['coverage'] ?? 0.0));
    $out .= sprintf(
        "matched    : %d/%d   findings: %d\n",
        (int) ($summary['matched_total'] ?? 0),
        (int) ($summary['source_total'] ?? 0),
        (int) ($summary['finding_total'] ?? 0)
    );

    if ( isset($report['comparison']) && is_array($report['comparison']) ) {
        $cmp = $report['comparison'];
        $out .= str_repeat('-', 72) . "\n";
        $out .= sprintf(
            "live-WP %.4f  vs  render-free proxy %.4f   (delta %+.4f)\n",
            (float) ($cmp['live_wp_score'] ?? 0.0),
            (float) ($cmp['proxy_score'] ?? 0.0),
            (float) ($cmp['delta'] ?? 0.0)
        );
    }

    return $out;
}
