<?php

declare(strict_types=1);

/**
 * Generate a PHP lookup of system / web-safe font families and their canonical
 * CSS fallback stacks that FontResolver::systemFonts() can require at runtime.
 *
 * These families render natively on the platforms that ship them (macOS, iOS,
 * Windows, Android) and are NOT served from a web-font CDN. The transformer
 * therefore resolves them as `web_safe` (no `@import`, no operator-font
 * diagnostic) and emits the curated stack so the browser degrades gracefully
 * through sibling system faces before hitting the generic family.
 *
 * Source provenance (declared input below):
 *  - Modern Font Stacks — https://modernfontstacks.com /
 *    https://github.com/system-fonts/modern-font-stacks (system-font-first
 *    stacks grouped by classification: Neo-Grotesque, Transitional, Old Style,
 *    Humanist, Geometric Humanist, Monospace Code, Slab Serif, Didone, etc.).
 *  - The long-standing cross-platform "web-safe" font set (CSS Font Stack /
 *    cssfontstack.com), covering the families historically guaranteed to be
 *    installed across Windows + macOS.
 *
 * This script embeds that curated reference as the declared SOURCE table below
 * and writes the generated file from it. The file-writing logic is generic:
 * to extend coverage, add an entry to SOURCE and regenerate — do not hand-edit
 * the generated output.
 *
 * Usage:
 *   php figma-transformer/scripts/generate-system-fonts.php
 *
 * Output:
 *   figma-transformer/src/Html/generated/system-fonts.php
 */

$outputFile = __DIR__ . '/../src/Html/generated/system-fonts.php';

// ---------------------------------------------------------------------------
// SOURCE (declared input) — curated system / web-safe families and the
// canonical CSS fallback stack each should degrade through. Sourced from
// Modern Font Stacks and the classic cross-platform web-safe set (see header).
//
// Each value is the published fallback stack; the terminal token is the CSS
// generic family the entry maps to. Keys are display family names and are
// lowercased + sorted by the writer below.
// ---------------------------------------------------------------------------

$source = array(
    // --- Sans-serif: Neo-Grotesque / Grotesque (Helvetica/Arial lineage) ---
    'Arial'                 => 'Arial, Helvetica, sans-serif',
    'Arial Black'           => '"Arial Black", "Arial Bold", Gadget, sans-serif',
    'Arial Narrow'          => '"Arial Narrow", Arial, sans-serif',
    'Helvetica'             => 'Helvetica, Arial, sans-serif',
    'Helvetica Neue'        => '"Helvetica Neue", Helvetica, Arial, sans-serif',
    'Franklin Gothic Medium' => '"Franklin Gothic Medium", "Arial Narrow", Arial, sans-serif',
    'Impact'                => 'Impact, Haettenschweiler, "Franklin Gothic Bold", Charcoal, "Arial Black", sans-serif',
    'Charcoal'              => 'Charcoal, Impact, sans-serif',

    // --- Sans-serif: Humanist / UI (system UI faces) ---
    'SF Pro Display'        => '"SF Pro Display", "SF Pro Text", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    'SF Pro Text'           => '"SF Pro Text", "SF Pro Display", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    'Segoe UI'              => '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif',
    'SF Pro Text'           => '"SF Pro Text", "SF Pro Display", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    'SF UI Text'            => '"SF UI Text", "SF Pro Text", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    'Tahoma'                => 'Tahoma, Geneva, Verdana, sans-serif',
    'Geneva'                => 'Geneva, Tahoma, Verdana, sans-serif',
    'Verdana'               => 'Verdana, Geneva, sans-serif',
    'Trebuchet MS'          => '"Trebuchet MS", "Lucida Grande", "Lucida Sans Unicode", "Lucida Sans", Tahoma, sans-serif',
    'Calibri'               => 'Calibri, "Segoe UI", Candara, Optima, sans-serif',
    'Candara'               => 'Candara, Calibri, "Segoe UI", Optima, sans-serif',
    'Avenir'                => 'Avenir, "Avenir Next", "Helvetica Neue", Arial, sans-serif',
    'Optima'                => 'Optima, Candara, "Segoe UI", sans-serif',
    'Gill Sans'             => '"Gill Sans", "Gill Sans MT", Calibri, "Trebuchet MS", sans-serif',
    'Gill Sans MT'          => '"Gill Sans MT", "Gill Sans", Calibri, "Trebuchet MS", sans-serif',
    'Lucida Grande'         => '"Lucida Grande", "Lucida Sans Unicode", "Lucida Sans", Geneva, Verdana, sans-serif',
    'Lucida Sans Unicode'   => '"Lucida Sans Unicode", "Lucida Grande", "Lucida Sans", Geneva, Verdana, sans-serif',
    'Lucida Sans'           => '"Lucida Sans", "Lucida Grande", "Lucida Sans Unicode", Geneva, Verdana, sans-serif',

    // --- Sans-serif: Geometric ---
    'Century Gothic'        => '"Century Gothic", CenturyGothic, "Apple Gothic", AppleGothic, sans-serif',

    // --- Serif: Transitional / Old Style ---
    'Georgia'               => 'Georgia, "Times New Roman", Times, serif',
    'Times New Roman'       => '"Times New Roman", Times, serif',
    'Times'                 => 'Times, "Times New Roman", serif',
    'Cambria'               => 'Cambria, Georgia, serif',
    'Constantia'            => 'Constantia, Georgia, serif',
    'Palatino'              => 'Palatino, "Palatino Linotype", "Book Antiqua", Georgia, serif',
    'Palatino Linotype'     => '"Palatino Linotype", Palatino, "Book Antiqua", Georgia, serif',
    'Book Antiqua'          => '"Book Antiqua", Palatino, "Palatino Linotype", Georgia, serif',
    'Garamond'              => 'Garamond, "Apple Garamond", "ITC Garamond", "Times New Roman", serif',
    'Baskerville'           => 'Baskerville, "Baskerville Old Face", "Hoefler Text", Garamond, "Times New Roman", serif',
    'Hoefler Text'          => '"Hoefler Text", "Baskerville Old Face", Garamond, "Times New Roman", serif',
    'Big Caslon'            => '"Big Caslon", "Book Antiqua", "Palatino Linotype", Georgia, serif',
    'Lucida Bright'         => '"Lucida Bright", Georgia, serif',

    // --- Serif: Didone ---
    'Didot'                 => 'Didot, "Didot LT STD", "Hoefler Text", Garamond, "Times New Roman", serif',

    // --- Serif: Slab ---
    'Rockwell'              => 'Rockwell, "Rockwell Nova", "Roboto Slab", "DejaVu Serif", "Sitka Small", serif',

    // --- Monospace: Code / Slab ---
    'Courier'               => 'Courier, "Courier New", monospace',
    'Courier New'           => '"Courier New", Courier, monospace',
    'Consolas'              => 'Consolas, "Lucida Console", Monaco, monospace',
    'Monaco'                => 'Monaco, Consolas, "Lucida Console", monospace',
    'Menlo'                 => 'Menlo, Monaco, Consolas, "Courier New", monospace',
    'SF Mono'               => '"SF Mono", Menlo, Monaco, Consolas, "Courier New", monospace',
    'Lucida Console'        => '"Lucida Console", Monaco, monospace',
    'Andale Mono'           => '"Andale Mono", AndaleMono, Monaco, monospace',
);

// ---------------------------------------------------------------------------
// Build the lookup: lowercased family => ['generic' => ..., 'stack' => ...].
// The generic is derived from the stack's terminal CSS generic token.
// ---------------------------------------------------------------------------

$validGenerics = array('sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui');

$fonts = array();
foreach ( $source as $family => $stack ) {
    $family = trim((string) $family);
    $stack  = trim((string) $stack);
    if ( '' === $family || '' === $stack ) {
        continue;
    }

    $tokens  = array_map('trim', explode(',', $stack));
    $generic = strtolower((string) end($tokens));
    if ( ! in_array($generic, $validGenerics, true) ) {
        fwrite(STDERR, "ERROR: stack for '{$family}' does not end in a CSS generic family: {$stack}\n");
        exit(1);
    }

    $fonts[strtolower($family)] = array('generic' => $generic, 'stack' => $stack);
}

if ( empty($fonts) ) {
    fwrite(STDERR, "ERROR: SOURCE produced no font entries.\n");
    exit(1);
}

ksort($fonts, SORT_STRING | SORT_FLAG_CASE);

// ---------------------------------------------------------------------------
// Render PHP source. Values are written in single-quoted PHP string literals,
// so only backslashes and single quotes are escaped — the double quotes inside
// the stacks are preserved verbatim.
// ---------------------------------------------------------------------------

$singleQuoteEscape = static function (string $value): string {
    return str_replace(array('\\', "'"), array('\\\\', "\\'"), $value);
};

$maxKeyLen = 0;
foreach ( array_keys($fonts) as $key ) {
    $maxKeyLen = max($maxKeyLen, strlen($key));
}
$padWidth = min($maxKeyLen, 32);

$lines   = array();
$lines[] = '<?php';
$lines[] = '// Generated by scripts/generate-system-fonts.php — do not edit manually.';
$lines[] = '// Regenerate: php figma-transformer/scripts/generate-system-fonts.php';
$lines[] = 'return array(';

$count = count($fonts);
$i     = 0;
foreach ( $fonts as $key => $entry ) {
    $i++;
    $comma          = ( $i < $count ) ? ',' : '';
    $quotedKey      = "'" . $key . "'";
    $quotedKeyPad   = str_pad($quotedKey, $padWidth + 2);
    $generic        = $singleQuoteEscape($entry['generic']);
    $stack          = $singleQuoteEscape($entry['stack']);
    $lines[]        = "    {$quotedKeyPad} => array('generic' => '{$generic}', 'stack' => '{$stack}'){$comma}";
}

$lines[] = ');';

$output = implode("\n", $lines) . "\n";

$dir = dirname($outputFile);
if ( ! is_dir($dir) && ! mkdir($dir, 0755, true) ) {
    fwrite(STDERR, "ERROR: Could not create directory {$dir}\n");
    exit(1);
}

if ( false === file_put_contents($outputFile, $output) ) {
    fwrite(STDERR, "ERROR: Could not write {$outputFile}\n");
    exit(1);
}

echo "Wrote {$count} system / web-safe font families to {$outputFile}\n";
