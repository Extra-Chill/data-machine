<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization;

use InvalidArgumentException;

final class FontMaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/font-materialization-plan/v1';
    private const CSS_WIDE_KEYWORDS = array('inherit', 'initial', 'revert', 'revert-layer', 'unset');

    /**
     * @param array<int,array<string,mixed>> $fontUsage
     * @param array<string,string> $roles
     * @return array<string,mixed>
     */
    public function googleFonts(array $fontUsage, array $roles = array()): array
    {
        $fonts = $this->normalizeFontUsage($fontUsage);
        $css = $this->googleFontsCss($fonts);
        $roles = $this->filterRoles($roles, $fonts);

        return array_filter(array(
            'schema' => self::SCHEMA,
            'provider' => 'google_fonts',
            'fonts' => $fonts,
            'roles' => $roles,
            'css' => $css,
            'stylesheets' => '' === $css ? array() : array(
                array(
                    'path' => 'assets/css/fonts.css',
                    'role' => 'stylesheet',
                    'mime_type' => 'text/css',
                    'content' => $css . "\n",
                ),
            ),
        ), static fn (mixed $value): bool => array() !== $value && '' !== $value);
    }

    /**
     * Build a materialization plan from raw web-font sources.
     *
     * Detects web-font stylesheets (e.g. Google Fonts `css2`/`css` `<link>` and
     * CSS `@import` sources) plus `font-family` declarations, preserving the
     * discovered typefaces and their heading/body roles so that materialized
     * output keeps the source typography.
     *
     * @return array<string,mixed>
     */
    public function fromWebFontSources(string $html = '', string $css = '', array $cssSources = array()): array
    {
        // Source typography is frequently applied through CSS custom properties
        // (`body { font-family: var(--font-body) }` defined by
        // `:root { --font-body: 'Lora', serif }`). Resolve those references to
        // their concrete typefaces before parsing so the plan captures the real
        // family — never a literal `var(--font-body)` token, which would corrupt
        // the materialized Google Fonts request and the body role.
        $resolvedCss = $this->resolveCssVariables($css);

        $imports = $this->webFontImports($css, $cssSources);
        $fontUsage = array_merge(
            $this->fontUsageFromLinkedStylesheets($html),
            ...array_merge(array_column($imports, 'font_usage'), array($this->fontUsageFromCssDeclarations($resolvedCss)))
        );
        $roles = $this->fontRolesFromCss($resolvedCss);

        // The base/body `font-family` is the document's foundational typography
        // and must survive into the materialized output even when it is declared
        // only in an inline `<style>` block (no external stylesheet, no linked
        // web-font). Carry that base font into the plan so the generated base
        // typography keeps the source's body face. Heading-only inline fonts are
        // deliberately NOT materialized here: a custom heading face with no
        // loaded web-font cannot render, so it stays a reported drop.
        $inlineBody = (string) ($this->fontRolesFromCss($this->resolveCssVariables($this->styleBlockCss($html)))['body'] ?? '');
        if ( '' !== $inlineBody ) {
            $fontUsage[] = array('family' => $inlineBody, 'weights' => array(400));
            if ( '' === (string) ($roles['body'] ?? '') ) {
                $roles['body'] = $inlineBody;
            }
        }

        $plan = $this->googleFonts($fontUsage, $roles);
        $faces = array();
        $diagnostics = array();
        foreach ( $imports as $import ) {
            if ( array() === $import['faces'] ) {
                $diagnostics[] = array('code' => $import['supported'] ? 'webfont_import_unresolved' : 'webfont_import_unsupported_provider', 'severity' => 'warning', 'import_ref' => $import['id'], 'source_path' => $import['provenance']['source_path'], 'selector' => $import['provenance']['selector'], 'href_hash' => $import['href_hash']);
                continue;
            }
            foreach ( $import['faces'] as $face ) {
                $face['id'] = 'webfont-face-' . substr(hash('sha256', $import['id'] . "\n" . $face['id']), 0, 20);
                $faces[] = array_merge($face, array('import_ref' => $import['id']));
            }
        }
        usort($faces, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        $importCss = $this->cssFromImports($imports);
        if ( '' !== $importCss ) {
            $plan['css'] = $importCss;
            $plan['stylesheets'] = array(array('path' => 'assets/css/fonts.css', 'role' => 'stylesheet', 'mime_type' => 'text/css', 'content' => $importCss . "\n"));
        }
        if ( isset($plan['stylesheets'][0]) ) {
            $plan['stylesheets'][0]['content_hash'] = hash('sha256', (string) $plan['stylesheets'][0]['content']);
            $plan['stylesheets'][0]['expected_content_hash'] = $plan['stylesheets'][0]['content_hash'];
        }
        $plan['webfont_contract'] = $this->webFontContract($imports, $faces, $diagnostics);
        $plan = array_merge($plan, $this->legacyWebFontProjection($plan['webfont_contract']));
        return $plan;
    }

    /**
     * Bind generated SVG text assets to the typed faces required to render them.
     *
     * @param array<string,mixed> $plan
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,mixed>
     */
    public function withSvgConsumers(array $plan, array $assets): array
    {
        $contract = $plan['webfont_contract'] ?? null;
        if (!is_array($contract)) return $plan;
        $faces = array_column($contract['faces'] ?? array(), null, 'id');
        $receipts = array_column($contract['receipts'] ?? array(), null, 'face_id');
        $consumers = array();
        foreach ($assets as $asset) {
            $content = $asset['content'] ?? null;
            if ('inline-svg' !== ($asset['source'] ?? null) || 'image/svg+xml' !== ($asset['mime_type'] ?? null) || !is_string($content) || !preg_match('/<text\b/i', $content)) continue;
            $families = $this->svgFontFamilies($content);
            $faceIds = array();
            foreach ($families as $family) foreach ($faces as $face) if (0 === strcasecmp($family, (string) ($face['family'] ?? ''))) $faceIds[] = (string) $face['id'];
            $faceIds = array_values(array_unique($faceIds)); sort($faceIds, SORT_STRING);
            if (array() === $faceIds) continue;
            $pairs = array(); foreach ($faceIds as $faceId) $pairs[] = array('face_id' => $faceId, 'receipt_id' => (string) ($receipts[$faceId]['id'] ?? ''));
            usort($pairs, static fn(array $left, array $right): int => strcmp($left['face_id'], $right['face_id']));
            $faceIds = array_column($pairs, 'face_id');
            $receiptIds = array_column($pairs, 'receipt_id');
            $sourcePath = (string) ($asset['path'] ?? '');
            // This is the producer's materialization intent, before a downstream
            // resolver projects it into a platform-specific destination.
            $writePath = (string) ($asset['target_path'] ?? $sourcePath);
            $payloadHash = hash('sha256', $content);
            $consumers[] = array('id' => 'svg-webfont-consumer-' . substr(hash('sha256', $sourcePath . "\n" . $writePath . "\n" . $payloadHash . "\n" . implode("\n", $faceIds)), 0, 20), 'source_path' => $sourcePath, 'write_path' => $writePath, 'pre_transform_payload_hash' => $payloadHash, 'face_ids' => $faceIds, 'receipt_ids' => $receiptIds, 'required' => true);
        }
        usort($consumers, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        $contract['svg_consumers'] = $consumers;
        self::assertWebFontContract($contract, $assets);
        $plan['webfont_contract'] = $contract;
        return $plan;
    }

    /** @param array<string,mixed> $contract @param array<int,array<string,mixed>> $assets */
    public static function assertWebFontContract(array $contract, array $assets): void
    {
        if ('blocks-engine/webfont-materialization/v1' !== ($contract['schema'] ?? null) || !is_array($contract['svg_consumers'] ?? null) || !array_is_list($contract['svg_consumers'])) throw new InvalidArgumentException('Webfont contract SVG consumers are malformed.');
        $faces = array_column($contract['faces'] ?? array(), null, 'id');
        $receipts = array_column($contract['receipts'] ?? array(), null, 'id');
        $assetsByPath = array_column($assets, null, 'path');
        $ids = array();
        foreach ($contract['svg_consumers'] as $consumer) {
            if (!is_array($consumer) || array('id', 'source_path', 'write_path', 'pre_transform_payload_hash', 'face_ids', 'receipt_ids', 'required') !== array_keys($consumer) || !is_string($consumer['id']) || !is_string($consumer['source_path']) || !is_string($consumer['write_path']) || !preg_match('/^[a-f0-9]{64}$/', $consumer['pre_transform_payload_hash']) || true !== $consumer['required'] || !is_array($consumer['face_ids']) || !is_array($consumer['receipt_ids']) || !array_is_list($consumer['face_ids']) || !array_is_list($consumer['receipt_ids'])) throw new InvalidArgumentException('Webfont SVG consumer is malformed.');
            $asset = $assetsByPath[$consumer['source_path']] ?? null;
            if (!is_array($asset) || ($asset['target_path'] ?? $consumer['source_path']) !== $consumer['write_path'] || !is_string($asset['content'] ?? null) || hash('sha256', $asset['content']) !== $consumer['pre_transform_payload_hash'] || $consumer['face_ids'] !== array_values(array_unique($consumer['face_ids'])) || $consumer['receipt_ids'] !== array_values(array_unique($consumer['receipt_ids']))) throw new InvalidArgumentException('Webfont SVG consumer has stale payload or noncanonical references.');
            $faceIds = $consumer['face_ids']; $receiptIds = $consumer['receipt_ids']; $sortedFaces = $faceIds; sort($sortedFaces, SORT_STRING);
            if (array() === $faceIds || $faceIds !== $sortedFaces || count($faceIds) !== count($receiptIds)) throw new InvalidArgumentException('Webfont SVG consumer references are noncanonical.');
            foreach ($faceIds as $index => $faceId) if (!is_string($faceId) || !isset($faces[$faceId]) || !is_string($receiptIds[$index] ?? null) || ($receipts[$receiptIds[$index]]['face_id'] ?? null) !== $faceId) throw new InvalidArgumentException('Webfont SVG consumer references an unknown face or receipt.');
            $expectedId = 'svg-webfont-consumer-' . substr(hash('sha256', $consumer['source_path'] . "\n" . $consumer['write_path'] . "\n" . $consumer['pre_transform_payload_hash'] . "\n" . implode("\n", $faceIds)), 0, 20);
            if ($consumer['id'] !== $expectedId || isset($ids[$consumer['id']])) throw new InvalidArgumentException('Webfont SVG consumer identity is invalid or duplicated.');
            $ids[$consumer['id']] = true;
        }
        $sorted = $contract['svg_consumers']; usort($sorted, static fn(array $left, array $right): int => strcmp($left['id'], $right['id'])); if ($sorted !== $contract['svg_consumers']) throw new InvalidArgumentException('Webfont SVG consumers are not canonically sorted.');
    }

    /** @param array<int,array<string,mixed>> $imports @param array<int,array<string,mixed>> $faces @param array<int,array<string,mixed>> $diagnostics */
    private function webFontContract(array $imports, array $faces, array $diagnostics): array
    {
        $diagnosticsByImport = array();
        foreach ( $diagnostics as $diagnostic ) $diagnosticsByImport[$diagnostic['import_ref'] ?? ''][] = $diagnostic;
        $contractImports = array_map(static fn (array $import): array => array('id' => $import['id'], 'provider' => $import['provider'], 'state' => array() === $import['faces'] ? ($import['supported'] ? 'unresolved' : 'unsupported') : 'declared', 'source' => array('url' => $import['href'], 'format' => 'css', 'expected_digest' => null, 'observed_digest' => null), 'provenance' => $import['provenance'], 'diagnostics' => $diagnosticsByImport[$import['id']] ?? array()), $imports);
        $importsById = array_column($contractImports, null, 'id');
        $contractFaces = array_map(static fn (array $face): array => array('id' => $face['id'], 'import_id' => $face['import_ref'], 'receipt_id' => 'webfont-receipt-' . substr(hash('sha256', $face['id']), 0, 20), 'state' => 'declared', 'family' => $face['family'], 'style' => $face['style'], 'weight' => $face['weight'], 'axes' => $face['axes'], 'unicode_ranges' => array(), 'sources' => array($importsById[$face['import_ref']]['source'])), $faces);
        $contractReceipts = array_map(static fn (array $face): array => array('id' => $face['receipt_id'], 'face_id' => $face['id'], 'import_id' => $face['import_id'], 'required' => true, 'state' => 'pending_browser_readiness'), $contractFaces);
        return array('schema' => 'blocks-engine/webfont-materialization/v1', 'imports' => $contractImports, 'faces' => $contractFaces, 'receipts' => $contractReceipts, 'svg_consumers' => array(), 'browser_readiness' => array('schema' => 'blocks-engine/webfont-browser-readiness/v1', 'required_receipt_ids' => array_column($contractReceipts, 'id'), 'state' => array() === $contractReceipts ? 'not_required' : 'required'), 'diagnostics' => $diagnostics);
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function legacyWebFontProjection(array $contract): array
    {
        $imports = array_map(static fn (array $import): array => array('id' => $import['id'], 'href' => $import['source']['url'], 'href_hash' => hash('sha256', $import['source']['url']), 'provider' => $import['provider'], 'provenance' => $import['provenance']), $contract['imports']);
        $faces = array_map(static fn (array $face): array => array('id' => $face['id'], 'import_ref' => $face['import_id'], 'family' => $face['family'], 'style' => $face['style'], 'weight' => $face['weight'], 'axes' => $face['axes']), $contract['faces']);
        $receipts = array_map(static fn (array $receipt): array => array('id' => $receipt['id'], 'face_ref' => $receipt['face_id'], 'import_ref' => $receipt['import_id'], 'status' => $receipt['state']), $contract['receipts']);
        return array('imports' => $imports, 'face_records' => $faces, 'receipts' => $receipts, 'browser_readiness' => array('schema' => $contract['browser_readiness']['schema'], 'required' => 'required' === $contract['browser_readiness']['state'], 'face_records' => array_column($faces, 'id'), 'receipt_refs' => $contract['browser_readiness']['required_receipt_ids']), 'diagnostics' => $contract['diagnostics'], 'compatibility_projection' => array('schema' => 'blocks-engine/webfont-materialization-legacy-projection/v1', 'source_schema' => $contract['schema']));
    }

    /** @param array<int,array<string,mixed>> $imports */
    private function cssFromImports(array $imports): string
    {
        $urls = array();
        foreach ( $imports as $import ) if ( $import['supported'] ) $urls[] = '@import url("' . $import['href'] . '");';
        return implode("\n", $urls);
    }

    /**
     * Concatenate the CSS inside every `<style>` block of an HTML document.
     */
    private function styleBlockCss(string $html): string
    {
        if ( '' === trim($html) || ! preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) ) {
            return '';
        }

        return implode("\n", $matches[1]);
    }

    /**
     * Parse linked web-font stylesheets out of HTML and return the discovered
     * families with their requested weights.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromLinkedStylesheets(string $html): array
    {
        if ( '' === trim($html) || ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return array();
        }

        $usage = array();
        foreach ( $matches[0] as $tag ) {
            $href = $this->htmlAttributeValue((string) $tag, 'href');
            if ( '' === $href ) {
                continue;
            }
            foreach ( $this->fontUsageFromFontHref($href) as $font ) {
                $usage[] = $font;
            }
        }

        return $usage;
    }

    /**
     * Parse web-font stylesheet URLs from CSS `@import` rules.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    private function fontUsageFromCssImports(string $css): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        if ( '' === trim($css) || ! preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]+)"|\'([^\']+)\'|([^\s\)"\';]+))/i', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $usage = array();
        foreach ( $matches as $match ) {
            $href = (string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? ''));
            foreach ( $this->fontUsageFromFontHref($href) as $font ) {
                $usage[] = $font;
            }
        }

        return $usage;
    }

    /**
     * Resolve CSS imports into stable source records and typed face declarations.
     *
     * @param array<int,array<string,mixed>> $cssSources
     * @return array<int,array<string,mixed>>
     */
    private function webFontImports(string $css, array $cssSources): array
    {
        $sources = array();
        foreach ( $cssSources as $source ) {
            if ( is_array($source) && is_string($source['content'] ?? null) ) $sources[] = array('content' => $source['content'], 'source_path' => (string) ($source['path'] ?? 'css:input'), 'source_hash' => (string) ($source['source_hash'] ?? hash('sha256', $source['content'])));
        }
        if ( array() === $sources && '' !== trim($css) ) $sources[] = array('content' => $css, 'source_path' => 'css:input', 'source_hash' => hash('sha256', $css));

        $imports = array();
        $seen = array();
        foreach ( $sources as $source ) {
            $content = preg_replace('/\/\*.*?\*\//s', '', $source['content']) ?? $source['content'];
            if ( ! preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]+)"|\'([^\']+)\'|([^\s\)"\';]+))/i', $content, $matches, PREG_SET_ORDER) ) continue;
            foreach ( $matches as $index => $match ) {
                $href = html_entity_decode((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), ENT_QUOTES | ENT_HTML5);
                $dedupeKey = $source['source_hash'] . "\n" . $href;
                if ( isset($seen[$dedupeKey]) ) continue;
                $seen[$dedupeKey] = true;
                $supported = 'fonts.googleapis.com' === strtolower((string) parse_url($href, PHP_URL_HOST));
                $imports[] = array(
                    'id' => 'webfont-import-' . substr(hash('sha256', $source['source_path'] . "\n" . ($index + 1) . "\n" . $href), 0, 20),
                    'href' => $href,
                    'href_hash' => hash('sha256', $href),
                    'provider' => $supported ? 'google_fonts' : 'unsupported',
                    'supported' => $supported,
                    'font_usage' => $this->fontUsageFromFontHref($href),
                    'faces' => $supported ? $this->fontFacesFromFontHref($href) : array(),
                    'provenance' => array('source_kind' => 'css_import', 'source_path' => $source['source_path'], 'source_hash' => $source['source_hash'], 'selector' => 'css:@import(' . ($index + 1) . ')'),
                );
            }
        }
        usort($imports, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $imports;
    }

    /** @return array<int,array<string,mixed>> */
    private function fontFacesFromFontHref(string $href): array
    {
        $faces = array();
        foreach ( explode('&', (string) (parse_url($href, PHP_URL_QUERY) ?: '')) as $param ) {
            if ( ! preg_match('/^family=(.*)$/i', $param, $match) ) continue;
            foreach ( explode('|', urldecode((string) $match[1])) as $spec ) {
                [$familySpec, $axes] = array_pad(explode(':', trim($spec), 2), 2, '');
                $family = $this->normalizeFamily($familySpec);
                if ( '' === $family || $this->isWebSafeFontFamily($family) ) continue;
                foreach ( $this->typedFaces($family, $axes) as $face ) $faces[] = $face;
            }
        }
        usort($faces, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return $faces;
    }

    /** @return array<int,array<string,mixed>> */
    private function typedFaces(string $family, string $axes): array
    {
        $declarations = array(array('style' => 'normal', 'weight' => array('kind' => 'static', 'value' => 400), 'axes' => array('wght' => array('kind' => 'static', 'value' => 400))));
        if ( str_contains($axes, '@') ) {
            [$names, $tuples] = explode('@', $axes, 2);
            $names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $names));
            $italIndex = array_search('ital', $names, true);
            $weightIndex = array_search('wght', $names, true);
            $declarations = array();
            foreach ( explode(';', $tuples) as $tuple ) {
                $values = explode(',', $tuple);
                $axisValues = array();
                foreach ( $names as $axisIndex => $name ) $axisValues[$name] = $this->typedWeight((string) ($values[$axisIndex] ?? ''));
                $declarations[] = array('style' => '1' === trim((string) ($values[$italIndex] ?? '0')) ? 'italic' : 'normal', 'weight' => $axisValues['wght'] ?? $this->typedWeight((string) end($values)), 'axes' => $axisValues);
            }
        } elseif ( '' !== $axes ) $declarations = array_map(fn (string $weight): array => array('style' => 'normal', 'weight' => $this->typedWeight($weight), 'axes' => array('wght' => $this->typedWeight($weight))), explode(',', $axes));
        $faces = array();
        foreach ( $declarations as $declaration ) {
            $style = $declaration['style'];
            $weight = $declaration['weight'];
            $weightKey = 'range' === $weight['kind'] ? $weight['min'] . '-' . $weight['max'] : (string) $weight['value'];
            $faces[] = array('id' => 'webfont-face-' . substr(hash('sha256', $family . "\n" . $style . "\n" . $weightKey . "\n" . json_encode($declaration['axes'])), 0, 20), 'family' => $family, 'style' => $style, 'weight' => $weight, 'axes' => $declaration['axes']);
        }
        return array_values(array_unique($faces, SORT_REGULAR));
    }

    /** @return array<string,int|string> */
    private function typedWeight(string $value): array
    {
        $value = trim($value);
        if ( preg_match('/^(\d{2,4})\.\.(\d{2,4})$/', $value, $range) ) return array('kind' => 'range', 'min' => (int) $range[1], 'max' => (int) $range[2]);
        return array('kind' => 'static', 'value' => is_numeric($value) ? (int) $value : 400);
    }

    /**
     * Parse the `family=` query parameters of a Google Fonts `css2`/`css`
     * stylesheet URL. Handles repeated `&family=` parameters, `|`-separated
     * families, and `:wght@…` (and legacy `:400,700`) axis suffixes.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromFontHref(string $href): array
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        $host = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
        $path = strtolower((string) (parse_url($href, PHP_URL_PATH) ?: ''));
        if ( 'fonts.googleapis.com' !== $host || ! in_array($path, array('/css', '/css2'), true) ) {
            return array();
        }

        $query = (string) (parse_url($href, PHP_URL_QUERY) ?: '');
        if ( '' === $query ) {
            return array();
        }

        $usage = array();
        foreach ( explode('&', $query) as $param ) {
            if ( ! preg_match('/^family=(.*)$/i', $param, $match) ) {
                continue;
            }

            // `+` encodes a space in family names; decode percent-escapes too.
            $value = urldecode((string) $match[1]);
            foreach ( explode('|', $value) as $spec ) {
                $spec = trim($spec);
                if ( '' === $spec ) {
                    continue;
                }
                $parts = explode(':', $spec, 2);
                $family = $this->normalizeFamily($parts[0]);
                if ( '' === $family || $this->isWebSafeFontFamily($family) ) {
                    continue;
                }
                $usage[] = array(
                    'family' => $family,
                    'weights' => $this->parseFontWeights($parts[1] ?? ''),
                );
            }
        }

        return $usage;
    }

    /**
     * Collect every typeface referenced by `font-family` declarations in CSS.
     *
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    public function fontUsageFromCssDeclarations(string $css): array
    {
        $usage = array();
        if ( ! preg_match_all('/font-family\s*:\s*([^;{}]+)/i', $css, $matches) ) {
            return $usage;
        }

        foreach ( $matches[1] as $declaration ) {
            $family = $this->primaryFamily((string) $declaration);
            if ( '' !== $family ) {
                $usage[] = array('family' => $family, 'weights' => array(400));
            }
        }

        return $usage;
    }

    /**
     * Map `font-family` declarations to heading/body roles based on selectors.
     *
     * @return array<string,string>
     */
    public function fontRolesFromCss(string $css): array
    {
        if ( '' === $css || ! preg_match('/\S/', $css) ) {
            return array();
        }

        $heading = '';
        $body = '';
        $offset = 0;
        while ( preg_match('/([^{}]+)\{([^{}]*)\}/s', $css, $rule, PREG_OFFSET_CAPTURE, $offset) ) {
            $offset = $rule[0][1] + strlen($rule[0][0]);
            if ( ! preg_match('/font-family\s*:\s*([^;{}]+)/i', (string) $rule[2][0], $declaration) ) {
                continue;
            }
            $family = $this->primaryFamily((string) $declaration[1]);
            if ( '' === $family ) {
                continue;
            }

            $selectors = array_map('trim', explode(',', (string) $rule[1][0]));
            foreach ( $selectors as $selector ) {
                if ( '' === $heading && preg_match('/(^|[\s>+~])h[1-6]\b/i', $selector) ) {
                    $heading = $family;
                }
                if ( '' === $body && preg_match('/(^|[\s>+~])(body|html|:root|\*)\b/i', $selector) ) {
                    $body = $family;
                }
            }
            if ( '' !== $heading && '' !== $body ) {
                break;
            }
        }

        return array_filter(array('heading' => $heading, 'body' => $body), static fn (string $value): bool => '' !== $value);
    }

    /**
     * @param array<int,array<string,mixed>> $fontUsage
     * @return array<int,array{family:string,weights:array<int,int>}>
     */
    private function normalizeFontUsage(array $fontUsage): array
    {
        $weightsByFamily = array();
        foreach ( $fontUsage as $font ) {
            if ( ! is_array($font) ) {
                continue;
            }

            $family = $this->normalizeFamily((string) ($font['family'] ?? $font['font_family'] ?? ''));
            if ( '' === $family || $this->isWebSafeFontFamily($family) || $this->isInvalidFontFamily($family) ) {
                continue;
            }

            $weights = $font['weights'] ?? $font['font_weights'] ?? $font['weight'] ?? $font['font_weight'] ?? array(400);
            $weights = is_array($weights) ? $weights : array($weights);
            foreach ( $weights as $weight ) {
                $weight = is_numeric($weight) ? (int) $weight : 400;
                if ( $weight > 0 ) {
                    $weightsByFamily[$family][] = $weight;
                }
            }
        }

        ksort($weightsByFamily);
        $fonts = array();
        foreach ( $weightsByFamily as $family => $weights ) {
            $weights = array_values(array_unique($weights));
            sort($weights);
            $fonts[] = array(
                'family' => $family,
                'weights' => $weights,
            );
        }

        return $fonts;
    }

    /**
     * @param array<int,array{family:string,weights:array<int,int>}> $fonts
     */
    private function googleFontsCss(array $fonts): string
    {
        $families = array();
        foreach ( $fonts as $font ) {
            $weights = empty($font['weights']) ? array(400) : $font['weights'];
            $families[] = 'family=' . str_replace('%20', '+', rawurlencode($font['family'])) . ':wght@' . implode(';', $weights);
        }

        if ( empty($families) ) {
            return '';
        }

        return '@import url("https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap");';
    }

    /**
     * Return the first non web-safe typeface from a `font-family` value list.
     */
    private function primaryFamily(string $declaration): string
    {
        foreach ( explode(',', $declaration) as $candidate ) {
            $family = $this->normalizeFamily($candidate);
            if ( '' !== $family && ! $this->isWebSafeFontFamily($family) && ! $this->isInvalidFontFamily($family) ) {
                return $family;
            }
        }

        return '';
    }

    /**
     * Expand `var(--name[, fallback])` references within a CSS string using the
     * custom-property definitions found in that same CSS. Bounded recursive
     * passes resolve variables whose values reference other variables. Leaves
     * unresolved references intact so they can be filtered as invalid families.
     */
    public function resolveCssVariables(string $css): string
    {
        if ( '' === trim($css) || ! str_contains($css, 'var(') ) {
            return $css;
        }

        $vars = $this->cssCustomProperties($css);
        if ( array() === $vars ) {
            return $css;
        }

        for ( $pass = 0; $pass < 5; $pass++ ) {
            $expanded = preg_replace_callback(
                '/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/',
                static function (array $match) use ($vars): string {
                    $name = (string) $match[1];
                    if ( isset($vars[$name]) && '' !== $vars[$name] ) {
                        return $vars[$name];
                    }

                    return isset($match[2]) && '' !== trim((string) $match[2]) ? trim((string) $match[2]) : (string) $match[0];
                },
                $css
            );

            if ( ! is_string($expanded) || $expanded === $css ) {
                break;
            }
            $css = $expanded;
        }

        return $css;
    }

    /**
     * Collect `--name: value` custom-property declarations from CSS. Later
     * declarations win, mirroring the cascade for the common single-scope case.
     *
     * @return array<string,string>
     */
    private function cssCustomProperties(string $css): array
    {
        if ( ! preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $vars = array();
        foreach ( $matches as $match ) {
            $vars[(string) $match[1]] = trim((string) $match[2]);
        }

        return $vars;
    }

    /**
     * A real typeface name never contains CSS function syntax or starts with a
     * custom-property prefix. Such tokens (e.g. an unresolved `var(--font-body)`)
     * must never be emitted as a Google Fonts family — they corrupt the request.
     */
    private function isInvalidFontFamily(string $family): bool
    {
        return str_contains($family, '(')
            || str_contains($family, ')')
            || str_starts_with($family, '--')
            || in_array(strtolower($family), self::CSS_WIDE_KEYWORDS, true);
    }

    /**
     * Extract integer weights from a Google Fonts axis suffix.
     *
     * Supports `css2` axis tuples (`wght@400;700`, `ital,wght@0,400;1,700`),
     * Google Fonts ranges (`wght@300..900`), and the legacy `css` weight list
     * (`400,700`). Defaults to `[400]`.
     *
     * @return array<int,int>
     */
    private function parseFontWeights(string $axes): array
    {
        $axes = trim($axes);
        if ( '' === $axes ) {
            return array(400);
        }

        $weights = array();
        if ( str_contains($axes, '@') ) {
            [$axisNames, $tuples] = explode('@', $axes, 2);
            $names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(',', $axisNames));
            $wghtIndex = array_search('wght', $names, true);
            foreach ( explode(';', $tuples) as $tuple ) {
                $values = explode(',', $tuple);
                $value = false === $wghtIndex ? end($values) : ($values[$wghtIndex] ?? null);
                array_push($weights, ...$this->expandFontWeightToken((string) $value));
            }
        } else {
            foreach ( explode(',', $axes) as $token ) {
                array_push($weights, ...$this->expandFontWeightToken($token));
            }
        }

        return array() === $weights ? array(400) : $weights;
    }

    /**
     * @return array<int,int>
     */
    private function expandFontWeightToken(string $token): array
    {
        $token = trim($token);
        if ( preg_match('/^(\d{2,4})\.\.(\d{2,4})$/', $token, $range) ) {
            $start = max(1, min(1000, (int) $range[1]));
            $end = max(1, min(1000, (int) $range[2]));
            if ( $start > $end ) {
                [$start, $end] = array($end, $start);
            }

            $weights = array();
            for ( $weight = (int) (ceil($start / 100) * 100); $weight <= $end; $weight += 100 ) {
                $weights[] = $weight;
            }

            return array() === $weights ? array($start, $end) : $weights;
        }

        return is_numeric($token) ? array((int) $token) : array();
    }

    /**
     * Drop role assignments whose family was filtered out of the plan.
     *
     * @param array<string,string> $roles
     * @param array<int,array{family:string,weights:array<int,int>}> $fonts
     * @return array<string,string>
     */
    private function filterRoles(array $roles, array $fonts): array
    {
        if ( array() === $roles ) {
            return array();
        }

        $families = array_map(static fn (array $font): string => (string) $font['family'], $fonts);
        return array_filter($roles, static fn (string $family): bool => in_array($family, $families, true));
    }

    private function htmlAttributeValue(string $tag, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5);
        }

        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s"\'>]+)/is', $tag, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    /** @return array<int,string> */
    private function svgFontFamilies(string $svg): array
    {
        preg_match_all('/<text\b[^>]*>/i', $svg, $texts);
        $families = array();
        foreach ($texts[0] as $text) {
            $value = $this->htmlAttributeValue($text, 'font-family');
            if ('' === $value && preg_match('/\bfont-family\s*:\s*([^;}]+)/i', $text, $match)) $value = $match[1];
            foreach ($this->fontFamilyList($value) as $family) $families[strtolower($family)] = $family;
        }
        return array_values($families);
    }

    /** @return array<int,string> */
    private function fontFamilyList(string $value): array
    {
        $families = array(); $current = ''; $quote = '';
        foreach (str_split($value) as $character) {
            if ('' !== $quote) { if ($character === $quote) $quote = ''; else $current .= $character; continue; }
            if ('"' === $character || "'" === $character) { $quote = $character; continue; }
            if (',' === $character) { $family = trim($current); if ('' !== $family) $families[] = $family; $current = ''; continue; }
            $current .= $character;
        }
        $family = trim($current); if ('' !== $family) $families[] = $family;
        return $families;
    }

    private function normalizeFamily(string $family): string
    {
        $family = preg_replace('/\s*!important\s*$/i', '', $family) ?? $family;
        return trim($family, " \t\n\r\0\x0B\"'");
    }

    private function isWebSafeFontFamily(string $family): bool
    {
        return in_array(strtolower($family), array('arial', 'courier new', 'georgia', 'helvetica', 'monospace', 'sans-serif', 'serif', 'system-ui', 'times new roman', 'verdana'), true);
    }
}
