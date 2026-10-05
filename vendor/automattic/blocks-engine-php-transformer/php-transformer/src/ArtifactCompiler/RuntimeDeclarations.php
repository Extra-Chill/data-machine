<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use InvalidArgumentException;
use JsonException;

/** Validates caller-declared, destination-independent runtime requirements. */
final class RuntimeDeclarations
{
    private const MAX_DECLARATIONS = 100;
    public const MAX_PROVENANCE_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_KEYS = ArtifactNormalizer::DEFAULT_MAX_FILES;
    public const MAX_PROVENANCE_SCALAR_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    public const MAX_PROVENANCE_DEPTH = 32;
    // Declarations are metadata, so keep their aggregate below one artifact file.
    public const MAX_TOTAL_DECLARATION_BYTES = ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES;
    private const MAX_PAYLOAD_BYTES = self::MAX_TOTAL_DECLARATION_BYTES;
    private const MAX_CANONICAL_DEPTH = self::MAX_PROVENANCE_DEPTH + 1;

    /** @param array<string,mixed> $artifact @return array<int,array<string,mixed>> */
    public static function normalize(array $artifact): array
    {
        $topLevel = $artifact['runtime_declarations'] ?? null;
        $metadata = is_array($artifact['metadata'] ?? null) ? ($artifact['metadata']['runtime_declarations'] ?? null) : null;
        if (null !== $topLevel && null !== $metadata) throw new InvalidArgumentException('Runtime declarations must be provided in exactly one canonical artifact location.');
        $raw = $topLevel ?? $metadata;
        if (null === $raw) return array();
        return self::normalizeList($raw);
    }

    /** @return array<int,array<string,mixed>> */
    public static function normalizeList(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_DECLARATIONS) throw new InvalidArgumentException('Runtime declarations must be a bounded ordered collection.');

        $declarations = array();
        $keys = array();
        $identities = array();
        $totalBytes = 0;
        foreach ($raw as $index => $declaration) {
            if (!is_array($declaration)) throw new InvalidArgumentException("Runtime declaration {$index} must be an object.");
            $kind = $declaration['kind'] ?? null;
            $type = $declaration['type'] ?? null;
            $capability = $declaration['capability'] ?? null;
            $sourcePath = $declaration['source_path'] ?? null;
            if (!is_string($kind) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $kind) || (!is_string($type) && !is_string($capability)) || (is_string($type) && is_string($capability)) || !is_string($sourcePath) || '' === ArtifactPath::safeRelativePath($sourcePath) || ArtifactPath::safeRelativePath($sourcePath) !== $sourcePath) throw new InvalidArgumentException("Runtime declaration {$index} has an unsafe or contradictory identity.");
            $name = is_string($type) ? $type : $capability;
            if (!preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $name)) throw new InvalidArgumentException("Runtime declaration {$index} has an unsupported type or capability.");
            $key = $kind . ':' . $name;
            $identity = hash('sha256', "wordpress-site-plan/runtime-declaration/v1\n{$sourcePath}\n{$key}");
            if (isset($keys[$key]) || isset($identities[$identity])) throw new InvalidArgumentException("Runtime declaration {$index} has a duplicate reconciliation identity.");
            if (isset($declaration['reconciliation_identity']) && $declaration['reconciliation_identity'] !== $identity) throw new InvalidArgumentException("Runtime declaration {$index} reconciliation_identity must derive from its source path and kind.");

            $normalized = array('kind' => $kind, is_string($type) ? 'type' : 'capability' => $name, 'source_path' => $sourcePath, 'reconciliation_identity' => $identity);
            if (isset($declaration['provenance'])) {
                if (!is_array($declaration['provenance']) || (isset($declaration['provenance']['source_path']) && (!is_string($declaration['provenance']['source_path']) || $declaration['provenance']['source_path'] !== $sourcePath))) throw new InvalidArgumentException("Runtime declaration {$index} provenance must retain its safe source path.");
                $normalized['provenance'] = self::canonicalProvenance($declaration['provenance'], $index);
            }
            if (isset($declaration['payload'])) {
                if (!is_array($declaration['payload']) || !is_string($declaration['payload']['schema'] ?? null) || '' === trim($declaration['payload']['schema']) || trim($declaration['payload']['schema']) !== $declaration['payload']['schema'] || strlen($declaration['payload']['schema']) > 255) throw new InvalidArgumentException("Runtime declaration {$index} payload requires a bounded nonblank schema.");
                $payload = self::canonical($declaration['payload']);
                try { $encoded = self::canonicalJson($payload); } catch (InvalidArgumentException) { throw new InvalidArgumentException("Runtime declaration {$index} payload is not serializable."); }
                if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} payload exceeds the byte limit.");
                $normalized['payload'] = $payload;
                if ('entity_collection' === $kind && 'forms' === $name && 'generic/forms/v1' === ($payload['schema'] ?? null)) foreach ($payload['entities'] ?? array() as $entity) if (is_array($entity) && isset($entity['layout_graph'])) { if (!is_array($entity['layout_graph'])) throw new InvalidArgumentException("Runtime declaration {$index} form layout graph must be an object."); FormLayoutGraphBuilder::assertValid($entity['layout_graph']); }
            }
            if ('entity_collection' === $kind && (!isset($normalized['type'], $normalized['payload']['entities']) || !array_is_list($normalized['payload']['entities']))) throw new InvalidArgumentException("Runtime declaration {$index} entity collections require a typed entities payload.");
            if (isset($declaration['required_for'])) {
                if (!is_array($declaration['required_for']) || !array_is_list($declaration['required_for']) || array_filter($declaration['required_for'], static fn(mixed $value): bool => !is_string($value) || '' === $value)) throw new InvalidArgumentException("Runtime declaration {$index} required_for must be a list of declaration keys.");
                if (count($declaration['required_for']) !== count(array_unique($declaration['required_for']))) throw new InvalidArgumentException("Runtime declaration {$index} required_for must not contain duplicates.");
                $normalized['required_for'] = array_values($declaration['required_for']);
                sort($normalized['required_for'], SORT_STRING);
            }
            if ('asset_publication' === $kind) {
                $normalized = array_merge($normalized, self::assetPublication($declaration, $index));
            }
            $totalBytes += strlen(self::canonicalJson($normalized));
            if ($totalBytes > self::MAX_TOTAL_DECLARATION_BYTES) throw new InvalidArgumentException("Runtime declarations exceed the aggregate canonical byte limit of " . self::MAX_TOTAL_DECLARATION_BYTES . " at declaration {$index}.");
            $normalized['payload_hash'] = self::hash($normalized['payload'] ?? null);
            $mutable = $normalized;
            unset($mutable['reconciliation_identity'], $mutable['payload_hash']);
            $normalized['content_hash'] = self::hash($mutable);
            $declarations[] = $normalized;
            $keys[$key] = $identity;
            $identities[$identity] = true;
        }
        foreach ($declarations as $index => $declaration) foreach ($declaration['required_for'] ?? array() as $required) if (!isset($keys[$required])) throw new InvalidArgumentException("Runtime declaration {$index} required_for references unresolved declaration {$required}.");
        usort($declarations, static fn(array $left, array $right): int => strcmp($left['reconciliation_identity'], $right['reconciliation_identity']));
        return $declarations;
    }

    /** @param array<string,mixed> $declaration @return array<string,mixed> */
    private static function assetPublication(array $declaration, int $index): array
    {
        if ('asset' !== ($declaration['type'] ?? null) || !is_array($declaration['destination'] ?? null) || !is_string($declaration['destination']['capability'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $declaration['destination']['capability']) || !is_bool($declaration['destination']['required'] ?? null) || !is_string($declaration['source_role'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $declaration['source_role']) || !is_string($declaration['mime_type'] ?? null) || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $declaration['mime_type']) || !self::isHash($declaration['source_hash'] ?? null) || !self::isHash($declaration['expected_content_hash'] ?? null) || !is_array($declaration['provenance'] ?? null) || ($declaration['provenance']['source_path'] ?? null) !== ($declaration['source_path'] ?? null) || !is_array($declaration['sanitization'] ?? null) || !is_string($declaration['sanitization']['schema'] ?? null) || !self::isHash($declaration['sanitization']['input_hash'] ?? null) || $declaration['sanitization']['input_hash'] !== $declaration['source_hash']) throw new InvalidArgumentException("Runtime declaration {$index} asset publication lacks explicit source, destination, or sanitization proof.");
        if (!is_array($declaration['reference_targets'] ?? null) || !array_is_list($declaration['reference_targets']) || count($declaration['reference_targets']) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} asset publication reference targets must be bounded.");
        $targets = array(); $seen = array();
        foreach ($declaration['reference_targets'] as $target) {
            if (!is_array($target) || !is_string($target['target_path'] ?? null) || '' === ArtifactPath::safeRelativePath($target['target_path']) || ArtifactPath::safeRelativePath($target['target_path']) !== $target['target_path'] || !self::isHash($target['write_reconciliation_identity'] ?? null) || !preg_match('/^asset-[a-f0-9]{16}$/', $target['token'] ?? '') || !is_int($target['count'] ?? null) || $target['count'] < 1 || $target['count'] > self::MAX_DECLARATIONS || 'css_url' !== ($target['context'] ?? null)) throw new InvalidArgumentException("Runtime declaration {$index} asset publication has an invalid reference target.");
            $key = strtolower($target['target_path']) . ':' . $target['token'] . ':' . $target['context']; if (isset($seen[$key])) throw new InvalidArgumentException("Runtime declaration {$index} asset publication has a duplicate reference target.");
            $seen[$key] = true; $targets[] = array('target_path' => $target['target_path'], 'write_reconciliation_identity' => $target['write_reconciliation_identity'], 'token' => $target['token'], 'count' => $target['count'], 'context' => $target['context']);
        }
        sort($targets, SORT_STRING);
        $normalized = array('destination' => array('capability' => $declaration['destination']['capability'], 'required' => $declaration['destination']['required']), 'source_role' => $declaration['source_role'], 'mime_type' => strtolower($declaration['mime_type']), 'source_hash' => $declaration['source_hash'], 'expected_content_hash' => $declaration['expected_content_hash'], 'sanitization' => array('schema' => $declaration['sanitization']['schema'], 'input_hash' => $declaration['sanitization']['input_hash']), 'reference_targets' => $targets);
        if (isset($declaration['transformation'])) $normalized['transformation'] = self::assetTransformation($declaration['transformation'], $index);
        return $normalized;
    }

    /** @return array<string,mixed> */
    private static function assetTransformation(mixed $transformation, int $index): array
    {
        if (!is_array($transformation) || 'svg_font_enrichment' !== ($transformation['kind'] ?? null) || !self::isHash($transformation['input_hash'] ?? null) || !self::isHash($transformation['expected_content_hash'] ?? null)) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation is invalid.");
        $paths = array();
        foreach (array('css_source_paths', 'font_source_paths') as $field) {
            if (!is_array($transformation[$field] ?? null) || !array_is_list($transformation[$field]) || count($transformation[$field]) > self::MAX_DECLARATIONS) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation inputs must be bounded lists.");
            $seen = array(); $values = array(); foreach ($transformation[$field] as $path) { if (!is_string($path) || '' === ArtifactPath::safeRelativePath($path) || ArtifactPath::safeRelativePath($path) !== $path || isset($seen[strtolower($path)])) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation has an unsafe input path."); $seen[strtolower($path)] = true; $values[] = $path; } sort($values, SORT_STRING); $paths[$field] = $values;
        }
        if (array_key_exists('font_faces', $transformation) || array() === $paths['css_source_paths'] || array() === $paths['font_source_paths']) throw new InvalidArgumentException("Runtime declaration {$index} asset transformation requires declared local CSS and font inputs.");
        return array_merge(array('kind' => 'svg_font_enrichment', 'input_hash' => $transformation['input_hash'], 'expected_content_hash' => $transformation['expected_content_hash']), $paths);
    }

    private static function isHash(mixed $value): bool { return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/', $value); }

    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $files @return array<int,array<string,mixed>> */
    public static function bindAssetPublications(array $declarations, array $files): array
    {
        $byPath = array(); foreach ($files as $file) if (is_array($file) && is_string($file['path'] ?? null)) $byPath[$file['path']] = $file;
        $bound = array();
        foreach ($declarations as $declaration) {
            if ('asset_publication' !== ($declaration['kind'] ?? null)) { $bound[] = $declaration; continue; }
            $file = $byPath[$declaration['source_path']] ?? null;
            if (!is_array($file)) throw new InvalidArgumentException('Asset publication provenance references an undeclared normalized artifact file.');
            $provenance = array('source_path' => $file['path'], 'source' => $file['source'], 'hash' => $file['provenance']['hash'] ?? '', 'mime_type' => $file['mime_type'], 'role' => $file['role'], 'bytes' => $file['bytes']);
            if (!is_array($declaration['provenance'] ?? null) || self::canonicalJson($declaration['provenance']) !== self::canonicalJson($provenance)) throw new InvalidArgumentException('Asset publication provenance must exactly match normalized artifact file metadata.');
            unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); $bound[] = $declaration;
        }
        return self::normalizeList($bound);
    }

    /** @param array<int,mixed> $declarations */
    public static function assertNormalized(array $declarations): void
    {
        if ($declarations !== self::normalizeList($declarations)) throw new InvalidArgumentException('Runtime declarations are not canonically normalized or have stale hashes.');
    }

    public static function canonicalJson(mixed $value): string
    {
        try { return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); } catch (JsonException) { throw new InvalidArgumentException('Runtime declaration payload is not serializable.'); }
    }

    public static function hash(mixed $value): string
    {
        $context = hash_init('sha256');
        self::updateCanonicalHash($context, $value);
        return hash_final($context);
    }

    /** @param resource $context */
    private static function updateCanonicalHash($context, mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_CANONICAL_DEPTH || is_resource($value) || is_object($value)) throw new InvalidArgumentException('Runtime declaration payload contains an unsupported value.');
        if (!is_array($value)) {
            if (is_string($value)) {
                self::updateCanonicalStringHash($context, $value);
                return;
            }
            try { hash_update($context, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); } catch (JsonException) { throw new InvalidArgumentException('Runtime declaration payload is not serializable.'); }
            return;
        }
        foreach ($value as $key => $_item) if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('Runtime declaration payload has an unsupported key.');
        $keys = array_keys($value);
        if (!array_is_list($value)) usort($keys, static fn(int|string $left, int|string $right): int => strcmp((string) $left, (string) $right));
        $list = true;
        foreach ($keys as $index => $key) {
            if ($index !== $key) {
                $list = false;
                break;
            }
        }
        hash_update($context, $list ? '[' : '{');
        foreach ($keys as $index => $key) {
            if (0 < $index) hash_update($context, ',');
            if (!$list) {
                self::updateCanonicalStringHash($context, (string) $key);
                hash_update($context, ':');
            }
            self::updateCanonicalHash($context, $value[$key], $depth + 1);
        }
        hash_update($context, $list ? ']' : '}');
    }

    /** @param resource $context */
    private static function updateCanonicalStringHash($context, string $value): void
    {
        if (1 !== preg_match('//u', $value)) throw new InvalidArgumentException('Runtime declaration payload is not serializable.');
        hash_update($context, '"');
        $length = strlen($value);
        $start = 0;
        $escapes = array(8 => '\\b', 9 => '\\t', 10 => '\\n', 12 => '\\f', 13 => '\\r', 34 => '\\"', 92 => '\\\\');
        for ($index = 0; $index < $length; ++$index) {
            $byte = ord($value[$index]);
            $lineTerminator = 0xE2 === $byte && $index + 2 < $length && 0x80 === ord($value[$index + 1]) && in_array(ord($value[$index + 2]), array(0xA8, 0xA9), true);
            if ($lineTerminator || $byte < 32 || isset($escapes[$byte])) {
                if ($index > $start) hash_update($context, substr($value, $start, $index - $start));
                if ($lineTerminator) {
                    hash_update($context, 0xA8 === ord($value[$index + 2]) ? '\\u2028' : '\\u2029');
                    $index += 2;
                } else {
                    hash_update($context, $escapes[$byte] ?? sprintf('\\u%04x', $byte));
                }
                $start = $index + 1;
            } elseif ($index - $start >= 8191) {
                hash_update($context, substr($value, $start, $index - $start + 1));
                $start = $index + 1;
            }
        }
        if ($length > $start) hash_update($context, substr($value, $start));
        hash_update($context, '"');
    }

    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_CANONICAL_DEPTH || is_resource($value) || is_object($value)) throw new InvalidArgumentException('Runtime declaration payload contains an unsupported value.');
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException('Runtime declaration payload has an unsupported key.');
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item, $depth + 1);
        return $value;
    }

    /** @param array<string,mixed> $provenance @return array<string,mixed> */
    private static function canonicalProvenance(array $provenance, int $index): array
    {
        $keys = 0;
        $canonical = self::canonicalProvenanceValue($provenance, $keys, 0, $index);
        if (!is_array($canonical)) throw new InvalidArgumentException("Runtime declaration {$index} provenance must be an object.");
        $bytes = strlen(self::canonicalJson($canonical));
        if ($bytes > self::MAX_PROVENANCE_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the {$bytes}-byte limit of " . self::MAX_PROVENANCE_BYTES . '.');
        return $canonical;
    }

    private static function canonicalProvenanceValue(mixed $value, int &$keys, int $depth, int $index): mixed
    {
        if ($depth > self::MAX_PROVENANCE_DEPTH) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the nesting limit of " . self::MAX_PROVENANCE_DEPTH . '.');
        if (is_resource($value) || is_object($value)) throw new InvalidArgumentException("Runtime declaration {$index} provenance contains an unsupported value.");
        if (!is_array($value)) {
            if (is_string($value) && strlen($value) > self::MAX_PROVENANCE_SCALAR_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance scalar exceeds the byte limit of " . self::MAX_PROVENANCE_SCALAR_BYTES . '.');
            return $value;
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) throw new InvalidArgumentException("Runtime declaration {$index} provenance has an unsupported key.");
            if (++$keys > self::MAX_PROVENANCE_KEYS) throw new InvalidArgumentException("Runtime declaration {$index} provenance exceeds the key limit of " . self::MAX_PROVENANCE_KEYS . '.');
            if (is_string($key) && strlen($key) > self::MAX_PROVENANCE_SCALAR_BYTES) throw new InvalidArgumentException("Runtime declaration {$index} provenance key exceeds the byte limit of " . self::MAX_PROVENANCE_SCALAR_BYTES . '.');
        }
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalProvenanceValue($item, $keys, $depth + 1, $index);
        return $value;
    }
}
