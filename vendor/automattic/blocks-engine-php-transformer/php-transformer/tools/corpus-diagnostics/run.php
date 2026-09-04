<?php
/**
 * Corpus-diagnostics harness.
 *
 * Runs the HTML transformer over the entire website-fixture corpus and emits a
 * frequency-ranked findings worklist. Pure PHP — no runner, no WordPress
 * runtime, no browser, no network — so the whole corpus processes in seconds.
 *
 * Usage:
 *   composer corpus-diagnostics -- [--corpus <dir>] [--output <file>] [--top <n>]
 *
 * Options:
 *   --corpus <dir>  Corpus root (default: <repo>/fixtures/websites).
 *   --output <file> Write the machine-readable JSON report to this path.
 *   --top <n>       Number of ranked clusters to print to stdout (default: 25).
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\CorpusDiagnostics\CorpusDiagnosticsRunner;

$options = parseArguments($argv);

$repoRoot = dirname(__DIR__, 3);
$corpusDir = $options['corpus'] ?? $repoRoot . '/fixtures/websites';
if ( ! is_dir($corpusDir) ) {
    fwrite(STDERR, "Corpus directory not found: {$corpusDir}\n");
    exit(1);
}

$runner = new CorpusDiagnosticsRunner();
$report = $runner->run($corpusDir);

if ( null !== ($options['output'] ?? null) ) {
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ( false === $json ) {
        fwrite(STDERR, "Failed to encode report as JSON.\n");
        exit(1);
    }
    if ( false === file_put_contents($options['output'], $json . "\n") ) {
        fwrite(STDERR, "Failed to write report to: {$options['output']}\n");
        exit(1);
    }
    fwrite(STDOUT, "Wrote machine-readable report to: {$options['output']}\n\n");
}

fwrite(STDOUT, $runner->renderSummary($report, (int) ($options['top'] ?? 25)));
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
        foreach ( array('corpus', 'output', 'top') as $name ) {
            if ( $arg === '--' . $name && $i + 1 < $count ) {
                $options[$name] = $argv[++$i];
                continue 2;
            }
            if ( str_starts_with($arg, '--' . $name . '=') ) {
                $options[$name] = substr($arg, strlen($name) + 3);
                continue 2;
            }
        }
    }

    return $options;
}
