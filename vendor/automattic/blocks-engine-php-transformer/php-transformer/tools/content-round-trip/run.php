<?php
/**
 * Content round-trip harness.
 *
 * Runs the HTML transformer over a directory of real `.html` pages and reports
 * the `content_round_trip` findings — output block text that does not appear in
 * the source content (invented/mangled/merged copy). Pure PHP: no WordPress
 * runtime, no browser, no network.
 *
 * Usage:
 *   php tools/content-round-trip/run.php <dir> [--verbose] [--top <n>] [--output <file>]
 *
 * Options:
 *   <dir>            Directory scanned recursively for *.html / *.htm (required).
 *   --verbose        Print every flagged file with its offending text nodes.
 *   --top <n>        How many ranked rows to print per table (default: 20).
 *   --output <file>  Write the full machine-readable JSON report to this path.
 *
 * Example:
 *   php tools/content-round-trip/run.php ~/Desktop/raw-html --verbose
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$opts = parseArgs($argv);
$dir = $opts['dir'] ?? null;
if ( null === $dir || ! is_dir($dir) ) {
    fwrite(STDERR, "Usage: php tools/content-round-trip/run.php <dir> [--verbose] [--top <n>] [--output <file>]\n");
    exit(1);
}

$top = (int) ($opts['top'] ?? 20);
$verbose = isset($opts['verbose']);

$files = htmlFiles($dir);
if ( array() === $files ) {
    fwrite(STDERR, "No .html files found under: {$dir}\n");
    exit(1);
}

$transformer = new HtmlTransformer();
$perFile = array();
$snippetFreq = array();
$totalFindings = 0;

foreach ( $files as $path ) {
    $html = (string) file_get_contents($path);
    if ( '' === trim($html) ) {
        continue;
    }

    $report = $transformer->transform($html, array())->toArray()['source_reports']['content_round_trip'] ?? array();
    $findings = is_array($report['findings'] ?? null) ? $report['findings'] : array();
    $rel = ltrim(substr($path, strlen($dir)), '/');
    $perFile[$rel] = $findings;
    $totalFindings += count($findings);

    foreach ( $findings as $finding ) {
        $text = trim((string) ($finding['text'] ?? ''));
        $snippetFreq[$text] = ($snippetFreq[$text] ?? 0) + 1;
    }
}

$flagged = array_filter($perFile, static fn (array $f): bool => array() !== $f);
$counts = array_map('count', $flagged);
arsort($counts);
arsort($snippetFreq);

if ( isset($opts['output']) ) {
    $json = json_encode(array(
        'corpus'             => $dir,
        'files_scanned'      => count($perFile),
        'files_with_findings' => count($flagged),
        'total_findings'     => $totalFindings,
        'per_file'           => $perFile,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($opts['output'], (string) $json . "\n");
    fwrite(STDOUT, "Wrote JSON report to: {$opts['output']}\n\n");
}

if ( $verbose ) {
    foreach ( array_keys($counts) as $rel ) {
        fwrite(STDOUT, "\n{$rel}  (" . count($flagged[$rel]) . " finding(s))\n");
        foreach ( $flagged[$rel] as $finding ) {
            fwrite(STDOUT, '   • ' . trim((string) ($finding['text'] ?? '')) . "\n");
        }
    }
}

$scanned = count($perFile);
fwrite(STDOUT, "\n========== CONTENT ROUND-TRIP ==========\n");
fwrite(STDOUT, sprintf("corpus:             %s\n", $dir));
fwrite(STDOUT, sprintf("files scanned:      %d\n", $scanned));
fwrite(STDOUT, sprintf("files w/ findings:  %d  (%.1f%%)\n", count($flagged), $scanned > 0 ? 100 * count($flagged) / $scanned : 0.0));
fwrite(STDOUT, sprintf("total findings:     %d\n", $totalFindings));

fwrite(STDOUT, "\n-- files with most findings --\n");
printRows($counts, $top);

fwrite(STDOUT, "\n-- most frequent flagged snippets --\n");
printRows($snippetFreq, $top, 90);

exit(0);

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function parseArgs(array $argv): array
{
    $opts = array();
    for ( $i = 1, $n = count($argv); $i < $n; $i++ ) {
        $arg = $argv[$i];
        if ( '--verbose' === $arg ) {
            $opts['verbose'] = '1';
        } elseif ( in_array($arg, array( '--top', '--output' ), true) && $i + 1 < $n ) {
            $opts[substr($arg, 2)] = $argv[++$i];
        } elseif ( ! str_starts_with($arg, '-') && ! isset($opts['dir']) ) {
            $opts['dir'] = rtrim($arg, '/');
        }
    }

    return $opts;
}

/**
 * @return array<int, string>
 */
function htmlFiles(string $dir): array
{
    $files = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ( $it as $entry ) {
        if ( $entry->isFile() && preg_match('/\.html?$/i', $entry->getFilename()) ) {
            $files[] = $entry->getPathname();
        }
    }
    sort($files);

    return $files;
}

/**
 * @param array<string, int> $rows
 */
function printRows(array $rows, int $limit, int $width = 0): void
{
    $i = 0;
    foreach ( $rows as $label => $count ) {
        if ( $i++ >= $limit ) {
            break;
        }
        $display = $width > 0 && mb_strlen($label) > $width ? mb_substr($label, 0, $width) . '…' : $label;
        fwrite(STDOUT, sprintf("  %4d  %s\n", $count, $display));
    }
}
