<?php

declare(strict_types=1);

/**
 * Shared helpers for one-off Figma data-mining scripts.
 */

function blocks_engine_figma_script_require_input_path(string $input): string
{
    if ( '' === $input ) {
        throw new InvalidArgumentException('Input path is required.');
    }

    $path = realpath($input);
    if ( false === $path || ! is_file($path) || ! is_readable($path) ) {
        throw new InvalidArgumentException("Input path is not a readable file: {$input}");
    }

    return $path;
}

function blocks_engine_figma_script_prepare_output_path(string $output): string
{
    if ( '' === $output ) {
        throw new InvalidArgumentException('Output path is required.');
    }

    $directory = dirname($output);
    if ( ! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory) ) {
        throw new RuntimeException("Unable to create output directory: {$directory}");
    }

    $directoryPath = realpath($directory);
    if ( false === $directoryPath ) {
        throw new RuntimeException("Output directory is not readable: {$directory}");
    }

    return $directoryPath . DIRECTORY_SEPARATOR . basename($output);
}

function blocks_engine_figma_script_int_option(mixed $value, int $default, int $min, int $max): int
{
    if ( null === $value || '' === $value || false === filter_var($value, FILTER_VALIDATE_INT) ) {
        return $default;
    }

    return min($max, max($min, (int) $value));
}

function blocks_engine_figma_script_bool_option(mixed $value): bool
{
    if ( is_bool($value) ) {
        return $value;
    }

    return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
}

function blocks_engine_figma_script_json_encode(array $value): string
{
    try {
        return json_encode(
            blocks_engine_figma_script_json_safe_value($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        throw new RuntimeException('Failed to encode JSON output: ' . $error->getMessage(), 0, $error);
    }
}

function blocks_engine_figma_script_json_safe_value(mixed $value): mixed
{
    if ( is_float($value) ) {
        return is_finite($value) ? $value : null;
    }

    if ( is_int($value) || is_string($value) || is_bool($value) || null === $value ) {
        return $value;
    }

    if ( is_array($value) ) {
        $safe = array();
        foreach ( $value as $key => $child ) {
            $safe[is_int($key) ? $key : (string) $key] = blocks_engine_figma_script_json_safe_value($child);
        }
        return $safe;
    }

    return get_debug_type($value);
}

function blocks_engine_figma_script_limit_list(array $values, int $limit): array
{
    return array_slice(array_values($values), 0, max(0, $limit));
}

function blocks_engine_figma_script_limited_summary_value(mixed $value, int $limit): mixed
{
    if ( ! is_array($value) ) {
        return blocks_engine_figma_script_json_safe_value($value);
    }

    if ( array_is_list($value) ) {
        return array(
            'count' => count($value),
            'sample' => array_map(
                static fn (mixed $child): mixed => blocks_engine_figma_script_limited_summary_value($child, $limit),
                blocks_engine_figma_script_limit_list($value, $limit)
            ),
        );
    }

    $summary = array('count' => count($value), 'sample' => array());
    foreach ( array_slice($value, 0, max(0, $limit), true) as $key => $child ) {
        $summary['sample'][(string) $key] = blocks_engine_figma_script_limited_summary_value($child, $limit);
    }
    return $summary;
}

function blocks_engine_figma_script_bounded_summary_map(array $value, int $limit): array
{
    $summary = array();
    foreach ( $value as $key => $child ) {
        $summary[(string) $key] = is_array($child)
            ? blocks_engine_figma_script_limited_summary_value($child, $limit)
            : blocks_engine_figma_script_json_safe_value($child);
    }
    return $summary;
}

function blocks_engine_figma_script_output_json(array $payload, ?string $outputPath = null, ?array $summary = null): void
{
    $json = blocks_engine_figma_script_json_encode($payload) . "\n";
    if ( null === $outputPath || '' === $outputPath ) {
        fwrite(STDOUT, $json);
        return;
    }

    $path = blocks_engine_figma_script_prepare_output_path($outputPath);
    if ( false === file_put_contents($path, $json) ) {
        throw new RuntimeException("Failed to write JSON output to {$path}");
    }

    fwrite(STDOUT, blocks_engine_figma_script_json_encode(array(
        'schema' => 'blocks-engine/figma-transformer/script-output/v1',
        'output' => $path,
        'summary' => $summary ?? array(),
    )) . "\n");
}

function blocks_engine_figma_script_self_check(): void
{
    $payload = array(
        'schema' => 'blocks-engine/figma-transformer/script-self-check/v1',
        'invalid_utf8' => "bad\xB1string",
        'non_finite' => array(NAN, INF, -INF),
        'nested' => array('sample' => array_fill(0, 3, array('value' => 1.25))),
    );
    $json = blocks_engine_figma_script_json_encode($payload);
    $decoded = json_decode($json, true);
    if ( ! is_array($decoded) || 'blocks-engine/figma-transformer/script-self-check/v1' !== ($decoded['schema'] ?? null) ) {
        throw new RuntimeException('Self-check failed: JSON output is not decodable.');
    }
    if ( array(null, null, null) !== ($decoded['non_finite'] ?? null) ) {
        throw new RuntimeException('Self-check failed: non-finite numbers were not sanitized.');
    }

    fwrite(STDOUT, $json . "\n");
}

function blocks_engine_figma_script_fail(Throwable $error): void
{
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
