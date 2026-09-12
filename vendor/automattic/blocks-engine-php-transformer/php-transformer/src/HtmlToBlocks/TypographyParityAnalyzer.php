<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;

/**
 * Detects source typefaces (linked web-font families and heading/body
 * `font-family` declarations) and reports the ones that are not represented in
 * the materialized output typography.
 *
 * "Represented" is defined by the font materialization plan the transformer can
 * derive from the same sources (see FontMaterializationPlanBuilder), so a font
 * that DOES materialize never produces a finding and the diagnostic stays in
 * agreement with the materialization layer.
 *
 * Generic only — no provider-, fixture-, or family-specific strings.
 */
final class TypographyParityAnalyzer
{
    private const SNIPPET_BYTE_LIMIT = 2000;

    /** @var array<int,string> */
    private const WEB_SAFE_FAMILIES = array(
        'arial', 'courier new', 'georgia', 'helvetica', 'monospace',
        'sans-serif', 'serif', 'system-ui', 'times new roman', 'verdana',
        'cursive', 'fantasy', 'ui-monospace', 'ui-sans-serif', 'ui-serif',
        'inherit', 'initial', 'unset',
    );

    public function __construct(
        private readonly FontMaterializationPlanBuilder $planBuilder = new FontMaterializationPlanBuilder()
    ) {
    }

    /**
     * @param array<int,array{family:string,selector:string,source_snippet:string}> $inlineHeadingDeclarations
     *   Heading-element inline `style="font-family:…"` declarations resolved from the DOM.
     * @return array<int,array<string,mixed>>
     */
    public function findings(string $html, string $css, array $inlineHeadingDeclarations = array()): array
    {
        $materialized = $this->materializedFamilyKeys($html, $css);

        $findings = array();
        $seen = array();

        foreach ( $this->linkedWebFontFamilies($html) as $linked ) {
            $key = 'web_font_not_materialized|' . $this->familyKey($linked['family']);
            if ( isset($seen[$key]) || $this->isRepresented($linked['family'], $materialized) ) {
                continue;
            }
            $seen[$key] = true;
            $findings[] = array(
                'code'           => 'web_font_not_materialized',
                'severity'       => 'warning',
                'font_family'    => $linked['family'],
                'selector'       => 'head > link[href]',
                'source_snippet' => $linked['source_snippet'],
                'observed_block' => 'none',
                'summary'        => 'Source linked web-font family was not materialized into output typography.',
            );
        }

        $typographyCss = $this->planBuilder->resolveCssVariables(trim($this->styleBlockCss($html) . "\n" . $css));
        $headingDeclarations = array_merge(
            $this->headingFamiliesFromCss($typographyCss),
            $this->normalizeInlineHeadingDeclarations($inlineHeadingDeclarations)
        );

        foreach ( $headingDeclarations as $declaration ) {
            $family = (string) $declaration['family'];
            $key = 'typography_font_family_dropped|' . $this->familyKey($family);
            if ( '' === $family || isset($seen[$key]) || $this->isRepresented($family, $materialized) ) {
                continue;
            }
            $seen[$key] = true;
            $findings[] = array(
                'code'           => 'typography_font_family_dropped',
                'severity'       => 'warning',
                'font_family'    => $family,
                'font_role'      => (string) ($declaration['role'] ?? 'heading'),
                'selector'       => (string) ($declaration['selector'] ?? ''),
                'source_snippet' => $this->boundSnippet((string) ($declaration['source_snippet'] ?? '')),
                'observed_block' => 'none',
                'summary'        => 'Source ' . ((string) ($declaration['role'] ?? 'heading')) . ' font-family was not represented in output typography.',
            );
        }

        return $findings;
    }

    /**
     * Families the materialization plan derived from the same sources retains.
     *
     * @return array<string,true>
     */
    private function materializedFamilyKeys(string $html, string $css): array
    {
        $plan = $this->planBuilder->fromWebFontSources($html, $css);
        $keys = array();
        foreach ( (array) ($plan['fonts'] ?? array()) as $font ) {
            if ( is_array($font) && '' !== (string) ($font['family'] ?? '') ) {
                $keys[$this->familyKey((string) $font['family'])] = true;
            }
        }

        return $keys;
    }

    /**
     * Parse `family=` query params out of every `<link href>` generically,
     * regardless of provider host.
     *
     * @return array<int,array{family:string,source_snippet:string}>
     */
    private function linkedWebFontFamilies(string $html): array
    {
        if ( '' === trim($html) || ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return array();
        }

        $families = array();
        foreach ( $matches[0] as $tag ) {
            $href = $this->attributeValue((string) $tag, 'href');
            if ( '' === $href ) {
                continue;
            }
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
            $query = (string) (parse_url($href, PHP_URL_QUERY) ?: '');
            if ( '' === $query ) {
                continue;
            }
            foreach ( explode('&', $query) as $param ) {
                if ( ! preg_match('/^family=(.*)$/i', $param, $match) ) {
                    continue;
                }
                $value = urldecode((string) $match[1]);
                foreach ( explode('|', $value) as $spec ) {
                    $family = $this->primaryFamily(explode(':', trim($spec), 2)[0] ?? '');
                    if ( '' === $family ) {
                        continue;
                    }
                    $families[$this->familyKey($family)] = array(
                        'family'         => $family,
                        'source_snippet' => $this->boundSnippet(trim((string) $tag)),
                    );
                }
            }
        }

        return array_values($families);
    }

    /**
     * Heading/body `font-family` declarations from CSS rules.
     *
     * @return array<int,array{family:string,role:string,selector:string,source_snippet:string}>
     */
    private function headingFamiliesFromCss(string $css): array
    {
        if ( '' === trim($css) || ! preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER) ) {
            return array();
        }

        $declarations = array();
        foreach ( $rules as $rule ) {
            if ( ! preg_match('/font-family\s*:\s*([^;{}]+)/i', (string) $rule[2], $declaration) ) {
                continue;
            }
            $family = $this->primaryFamily((string) $declaration[1]);
            if ( '' === $family ) {
                continue;
            }

            foreach ( array_map('trim', explode(',', (string) $rule[1])) as $selector ) {
                if ( '' === $selector ) {
                    continue;
                }
                $role = $this->roleForSelector($selector);
                if ( '' === $role ) {
                    continue;
                }
                $declarations[] = array(
                    'family'         => $family,
                    'role'           => $role,
                    'selector'       => $selector,
                    'source_snippet' => $this->boundSnippet(trim((string) $rule[0])),
                );
            }
        }

        return $declarations;
    }

    private function roleForSelector(string $selector): string
    {
        if ( preg_match('/(^|[\s>+~])h[1-6]\b/i', $selector) ) {
            return 'heading';
        }
        if ( preg_match('/(^|[\s>+~])(body|html|:root|\*)\b/i', $selector) ) {
            return 'body';
        }

        return '';
    }

    /**
     * @param array<int,array{family?:string,selector?:string,source_snippet?:string}> $declarations
     * @return array<int,array{family:string,role:string,selector:string,source_snippet:string}>
     */
    private function normalizeInlineHeadingDeclarations(array $declarations): array
    {
        $normalized = array();
        foreach ( $declarations as $declaration ) {
            if ( ! is_array($declaration) ) {
                continue;
            }
            $family = $this->primaryFamily((string) ($declaration['family'] ?? ''));
            if ( '' === $family ) {
                continue;
            }
            $normalized[] = array(
                'family'         => $family,
                'role'           => 'heading',
                'selector'       => (string) ($declaration['selector'] ?? ''),
                'source_snippet' => $this->boundSnippet((string) ($declaration['source_snippet'] ?? '')),
            );
        }

        return $normalized;
    }

    private function styleBlockCss(string $html): string
    {
        if ( '' === trim($html) || ! preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches) ) {
            return '';
        }

        return implode("\n", $matches[1]);
    }

    /**
     * @param array<string,true> $materialized
     */
    private function isRepresented(string $family, array $materialized): bool
    {
        if ( $this->isWebSafeFamily($family) ) {
            return true;
        }

        return isset($materialized[$this->familyKey($family)]);
    }

    private function primaryFamily(string $declaration): string
    {
        foreach ( explode(',', $declaration) as $candidate ) {
            $family = $this->normalizeFamily($candidate);
            if ( '' !== $family && ! $this->isWebSafeFamily($family) ) {
                return $family;
            }
        }

        return '';
    }

    private function attributeValue(string $tag, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match) ) {
            return (string) $match[2];
        }
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s"\'>]+)/is', $tag, $match) ) {
            return (string) $match[1];
        }

        return '';
    }

    private function normalizeFamily(string $family): string
    {
        return trim($family, " \t\n\r\0\x0B\"'");
    }

    private function familyKey(string $family): string
    {
        return strtolower($this->normalizeFamily($family));
    }

    private function isWebSafeFamily(string $family): bool
    {
        return in_array(strtolower($this->normalizeFamily($family)), self::WEB_SAFE_FAMILIES, true);
    }

    private function boundSnippet(string $snippet): string
    {
        $snippet = trim($snippet);
        if ( strlen($snippet) > self::SNIPPET_BYTE_LIMIT ) {
            return substr($snippet, 0, self::SNIPPET_BYTE_LIMIT) . '...';
        }

        return $snippet;
    }
}
