<?php
declare(strict_types=1);

/**
 * Generate non-comment core attribute declarations for standalone runtime
 * canonicalization. Live WP_Block_Type declarations always take precedence.
 *
 * Usage:
 *   php tools/generate-core-block-attributes.php <wp-includes/blocks> <output.json> <source-ref>
 */

if ( 4 !== count($argv) ) {
    fwrite(STDERR, "Usage: php tools/generate-core-block-attributes.php <wp-includes/blocks> <output.json> <source-ref>\n");
    exit(2);
}

$blocksDirectory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$outputPath = $argv[2];
$sourceRef = trim($argv[3]);
if ( ! is_dir($blocksDirectory) || '' === $sourceRef ) {
    fwrite(STDERR, "A readable WordPress blocks directory and non-empty source ref are required.\n");
    exit(2);
}

$blocks = array();
foreach ( (array) glob($blocksDirectory . '/*/block.json') as $blockJsonPath ) {
    $metadata = json_decode((string) file_get_contents($blockJsonPath), true);
    if ( ! is_array($metadata) || ! is_string($metadata['name'] ?? null) || ! str_starts_with($metadata['name'], 'core/') ) {
        continue;
    }

    $attributes = array();
    foreach ( is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : array() as $name => $schema ) {
        if ( ! is_string($name) || ! is_array($schema) ) {
            continue;
        }
        if ( array_key_exists('source', $schema) ) {
            $attributes[ $name ] = array( 'source' => $schema['source'] );
        } elseif ( 'local' === ($schema['role'] ?? null) ) {
            $attributes[ $name ] = array( 'role' => 'local' );
        }
    }
    $blocks[ $metadata['name'] ] = $attributes;
}
ksort($blocks, SORT_STRING);

$registry = array(
    'schema'      => 'blocks-engine/php-transformer/core-block-attributes/v1',
    'source'      => $sourceRef,
    'block_count' => count($blocks),
    'blocks'      => $blocks,
);
$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ( false === $json ) {
    fwrite(STDERR, "Unable to encode the core-block attribute registry.\n");
    exit(1);
}

$outputDirectory = dirname($outputPath);
if ( ! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory) ) {
    fwrite(STDERR, "Unable to create output directory: {$outputDirectory}\n");
    exit(1);
}
if ( false === file_put_contents($outputPath, $json . "\n") ) {
    fwrite(STDERR, "Unable to write core-block attribute registry: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Core block attribute registry: %d declarations written to %s\n", count($blocks), $outputPath));
