<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use InvalidArgumentException;

/** Resolves declared asset tokens using explicit runtime destination context. */
final class WordPressSitePlanResolver
{
    public const RESOLUTION_SCHEMA = 'blocks-engine/wordpress-site-plan-resolution/v1';
    /** @param array<string,mixed> $plan @param array<string,mixed> $context @return array<string,mixed> */
    public function resolve(array $plan, array $context): array
    {
        WordPressSitePlan::assertValid($plan);
        if (isset($plan['resolution'])) throw new InvalidArgumentException('WordPress site plan is already a resolved projection.');
        if (true === ($context['require_proven_dynamic_client_assets'] ?? false) && 'not_proven' === ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null)) throw new InvalidArgumentException('WordPress site plan cannot prove dynamic client asset references.');
        $capabilities = self::normalizeRuntimeCapabilities($context['runtime_capabilities'] ?? array());
        $unsupportedOptional = self::unsupportedOptionalCapabilities($plan['runtime_declarations'], $capabilities);
        $themeUri = self::normalizeThemeUri($context['theme_uri'] ?? null);
        $references = self::references($plan['reference_tokens'], $themeUri);
        foreach ($plan['pages'] as &$page) $page['resolved_block_markup'] = self::resolvePayload($page['canonical_block_markup'], $references);
        unset($page);
        foreach ($plan['template_parts'] as &$part) $part['resolved_block_markup'] = self::resolvePayload($part['canonical_block_markup'], $references);
        unset($part);
        foreach ($plan['templates'] as &$template) $template['resolved_block_markup'] = self::resolvePayload($template['canonical_block_markup'], $references);
        unset($template);
        // Provider bindings replace page markup, so their anchors must use the
        // same destination projection as the page materialized by consumers.
        $plan['runtime_declarations'] = self::resolveEntityBindings($plan['runtime_declarations'], $plan['pages'], $references);
        foreach ($plan['writes'] as &$write) if ('utf8' === $write['payload']['encoding']) { $write['canonical_payload'] = $write['payload']['data']; $write['canonical_payload_hash'] = WordPressSitePlan::contentHash($write['canonical_payload']); $write['payload']['data'] = self::resolvePayload($write['canonical_payload'], $references); $write['payload_hash'] = WordPressSitePlan::contentHash($write['payload']['data']); }
        unset($write);
        foreach (array('pages', 'template_parts') as $documents) foreach ($plan[$documents] as &$document) foreach (array('links', 'scripts') as $kind) { if (!is_array($document['document_metadata'][$kind] ?? null)) continue; foreach ($document['document_metadata'][$kind] as &$declaration) if (is_string($declaration['asset_reference'] ?? null)) $declaration['resolved_url'] = self::resolvePayload($declaration['asset_reference'], $references); }
        unset($declaration, $document);
        $plan['resolution'] = array('schema' => self::RESOLUTION_SCHEMA, 'theme_uri' => $themeUri, 'runtime_capabilities' => $capabilities, 'asset_publication_references' => self::publicationReferences($plan['runtime_declarations'], $references), 'unsupported_optional_capabilities' => $unsupportedOptional);
        WordPressSitePlan::assertValid($plan);
        return $plan;
    }

    /** @param array<string,string> $references */
    public static function resolvePayload(string $content, array $references): string
    {
        $resolved = strtr($content, $references);
        if (str_contains($resolved, WordPressSitePlan::TOKEN_PREFIX)) throw new InvalidArgumentException('WordPress site plan contains unresolved reference tokens.');
        return $resolved;
    }
    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $pages @param array<string,string> $references @return array<int,array<string,mixed>> */
    private static function resolveEntityBindings(array $declarations, array $pages, array $references): array
    {
        $pagesBySource = array_column($pages, null, 'source_path');
        foreach ($declarations as $declarationIndex => $declaration) {
            if (!is_array($declaration['payload']['entities'] ?? null)) continue;
            foreach ($declaration['payload']['entities'] as $entityIndex => $entity) {
                if (!is_array($entity['bindings'] ?? null)) continue;
                foreach ($entity['bindings'] as $bindingIndex => $binding) {
                    if (!is_array($binding) || 'generic/block-binding/v1' !== ($binding['schema'] ?? null) || !is_string($binding['source_path'] ?? null) || !is_string($binding['search_block_markup'] ?? null) || !is_int($binding['occurrence'] ?? null)) continue;
                    $page = $pagesBySource[$binding['source_path']] ?? null;
                    if (!is_array($page) || !is_string($page['canonical_block_markup'] ?? null) || !is_string($page['resolved_block_markup'] ?? null)) continue;
                    $canonical = $page['canonical_block_markup']; $resolved = $page['resolved_block_markup'];
                    $canonicalOffset = self::occurrenceOffset($canonical, $binding['search_block_markup'], $binding['occurrence']);
                    $blockIndex = null;
                    foreach (self::blockRanges($canonical) as $index => $range) if ($range['offset'] === $canonicalOffset && $binding['search_block_markup'] === substr($canonical, $range['offset'], $range['length'])) { $blockIndex = $index; break; }
                    if (!is_int($blockIndex)) throw new InvalidArgumentException('WordPress site plan runtime binding cannot be resolved from its canonical source-page anchor.');
                    $search = self::resolvePayload($binding['search_block_markup'], $references);
                    // A token can change byte length, and filtered malformed ranges can
                    // shift a serialized block index. Project the canonical byte prefix
                    // instead of assuming indexes remain numerically stable.
                    $resolvedOffset = strlen(self::resolvePayload(substr($canonical, 0, $canonicalOffset), $references));
                    $range = null;
                    foreach (self::blockRanges($resolved) as $index => $candidate) if ($candidate['offset'] === $resolvedOffset && $search === substr($resolved, $candidate['offset'], $candidate['length'])) { $range = $candidate; $blockIndex = $index; break; }
                    if (!is_array($range)) throw new InvalidArgumentException('WordPress site plan runtime binding resolved anchor is detached from its source page.');
                    $binding['search_block_markup'] = $search;
                    $binding['occurrence'] = self::occurrenceAtOffset($resolved, $search, $range['offset']);
                    $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                    $declarations[$declarationIndex]['payload']['entities'][$entityIndex]['bindings'][$bindingIndex] = $binding;
                }
            }
        }
        foreach ($declarations as &$declaration) unset($declaration['payload_hash'], $declaration['content_hash']);
        unset($declaration);
        return RuntimeDeclarations::normalizeList($declarations);
    }
    /** @return array<int,array{offset:int,length:int}> */
    private static function blockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $match) { $token = $match[0]; $offset = $match[1]; if (str_starts_with($token, '<!-- /wp:')) { $open = array_pop($stack); if (is_array($open)) $ranges[$open['index']]['length'] = $offset + strlen($token) - $open['offset']; } elseif (str_ends_with(rtrim($token), '/-->')) $ranges[] = array('offset' => $offset, 'length' => strlen($token)); else { $index = count($ranges); $ranges[] = array('offset' => $offset, 'length' => 0); $stack[] = array('index' => $index, 'offset' => $offset); } }
        return array_values(array_filter($ranges, static fn(array $range): bool => 0 < $range['length']));
    }
    private static function occurrenceOffset(string $markup, string $search, int $occurrence): ?int
    {
        if ('' === $search || $occurrence < 1) return null;
        $offset = 0; for ($index = 0; $index < $occurrence; ++$index) { $offset = strpos($markup, $search, $offset); if (false === $offset) return null; if ($index + 1 < $occurrence) $offset += strlen($search); }
        return $offset;
    }
    private static function occurrenceAtOffset(string $markup, string $search, int $offset): int
    {
        $occurrence = 0; $cursor = 0; while (false !== ($found = strpos($markup, $search, $cursor))) { ++$occurrence; if ($found === $offset) return $occurrence; $cursor = $found + strlen($search); } return 0;
    }

    public static function normalizeThemeUri(mixed $value): string
    {
        if (!is_string($value) || '' === $value || preg_match('/[\x00-\x20\x7f]/', $value) || false === ($parts = parse_url($value))) throw new InvalidArgumentException('WordPress site plan resolution requires a valid theme_uri.');
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true) || '' === $parts['host']) throw new InvalidArgumentException('WordPress site plan resolution requires an absolute http(s) theme_uri without credentials, query, or fragment.');
        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an invalid port.');
        $path = $parts['path'] ?? '';
        if (!is_string($path) || ('' !== $path && !str_starts_with($path, '/')) || str_contains($path, '\\') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|%2f|%5c|%2e~i', $path)) throw new InvalidArgumentException('WordPress site plan resolution theme_uri has an ambiguous path.');
        $authority = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return strtolower($parts['scheme']) . '://' . $authority . rtrim($path, '/');
    }
    /** @param array<int,array<string,mixed>> $tokens @return array<string,string> */
    public static function references(array $tokens, string $themeUri): array { $references = array(); foreach ($tokens as $reference) if (is_array($reference) && is_string($reference['token'] ?? null) && is_string($reference['target_path'] ?? null)) $references['{{wordpress-site-plan:asset:' . $reference['token'] . '}}'] = $themeUri . '/' . $reference['target_path']; return $references; }
    /** @return array<int,string> */
    public static function normalizeRuntimeCapabilities(mixed $capabilities): array
    {
        if (!is_array($capabilities) || !array_is_list($capabilities) || array_filter($capabilities, static fn(mixed $capability): bool => !is_string($capability) || !preg_match('/^[a-z][a-z0-9_-]{0,127}$/', $capability)) || count($capabilities) !== count(array_unique($capabilities))) throw new InvalidArgumentException('WordPress site plan runtime capabilities must be a unique bounded list.');
        sort($capabilities, SORT_STRING); return $capabilities;
    }
    /** @param array<int,array<string,mixed>> $declarations @param array<int,string> $capabilities @return array<int,string> */
    public static function unsupportedOptionalCapabilities(array $declarations, array $capabilities): array
    {
        $unsupported = array(); foreach ($declarations as $declaration) if ('asset_publication' === ($declaration['kind'] ?? null) && !in_array($declaration['destination']['capability'], $capabilities, true)) { if ($declaration['destination']['required']) throw new InvalidArgumentException('WordPress site plan requires an unsupported runtime capability.'); $unsupported[] = $declaration['reconciliation_identity']; }
        sort($unsupported, SORT_STRING); return $unsupported;
    }
    /** @param array<int,array<string,mixed>> $declarations @param array<string,string> $references @return array<int,array<string,mixed>> */
    public static function publicationReferences(array $declarations, array $references): array
    {
        $resolved = array();
        foreach ($declarations as $declaration) if ('asset_publication' === ($declaration['kind'] ?? null)) foreach ($declaration['reference_targets'] as $target) { $canonical = WordPressSitePlan::TOKEN_PREFIX . $target['token'] . '}}'; $url = $references[$canonical] ?? null; if (!is_string($url)) throw new InvalidArgumentException('Asset publication reference token is not declared.'); $resolved[] = array('declaration_reconciliation_identity' => $declaration['reconciliation_identity'], 'target_path' => $target['target_path'], 'write_reconciliation_identity' => $target['write_reconciliation_identity'], 'canonical_token' => $canonical, 'count' => $target['count'], 'context' => $target['context'], 'expected_resolved_url' => $url); }
        return $resolved;
    }
}
