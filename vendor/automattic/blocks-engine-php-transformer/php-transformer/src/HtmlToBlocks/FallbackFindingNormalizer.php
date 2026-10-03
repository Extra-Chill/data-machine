<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

final class FallbackFindingNormalizer
{
    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function normalize(array $fields): array
    {
        $selector = is_string($fields['selector'] ?? null) ? trim($fields['selector']) : '';
        $context = is_array($fields['context'] ?? null) ? $fields['context'] : array();
        $patternFamily = self::patternFamily($fields);

        $metadata = array_filter(array(
            'pattern_family'                 => $patternFamily,
            'pattern_family_detail'          => self::patternFamilyDetail($fields),
            'runtime_island_type'            => self::runtimeIslandType($fields, $patternFamily),
            'source_selector'                => $selector,
            'source_selector_specificity'    => '' !== $selector ? self::selectorSpecificity($selector) : array(),
            'parent_reason'                  => self::parentReason($context),
            'ancestor_reason'                => self::ancestorReason($context),
            'suggested_generic_repair_class' => self::genericRepairClass($fields, $patternFamily),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);

        // Stamp the canonical classification triplet (reason_code / repair_bucket
        // / pattern_family) so every fallback/runtime-island finding clusters by
        // root cause downstream. The contract honors the richer pattern_family and
        // suggested_repair_class computed above and only fills what is missing.
        return ConversionFindingContract::withClassification(array_merge($metadata, $fields));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function patternFamily(array $fields): string
    {
        $code = (string) ($fields['diagnostic_code'] ?? $fields['code'] ?? '');
        $tag = (string) ($fields['tag'] ?? '');
        $kind = (string) ($fields['kind'] ?? '');

        if ( 'preserved_runtime_island' === $code && self::isInlineSemanticHtmlRuntimeIsland($fields) ) {
            return 'inline_semantic_html';
        }

        return match ( $code ) {
            'html_form_fallback' => 'interactive_form',
            'html_product_grid_fallback' => 'commerce_product_grid',
            'html_commerce_controls_fallback' => 'commerce_controls',
            'html_script_fallback' => 'runtime_script',
            'interactive_control_behavior_lost' => 'interactive_control',
            'html_iframe_embed_fallback' => 'external_embed',
            'html_canvas_runtime_fallback' => 'runtime_canvas',
            'html_template_metadata' => 'inert_template_metadata',
            'html_template_runtime_fallback' => 'runtime_template',
            'html_inline_svg_fallback', 'html_unsafe_inline_svg' => 'inline_svg',
            'html_unsupported_element' => '' !== $tag ? 'unsupported_' . $tag : 'unsupported_element',
            default => match ( $kind ) {
                'form', 'control' => 'interactive_form',
                'script' => 'runtime_script',
                'canvas' => 'runtime_canvas',
                default => '' !== $tag ? 'html_' . $tag : 'html_fallback',
            },
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function patternFamilyDetail(array $fields): string
    {
        $parts = array_filter(array(
            (string) ($fields['tag'] ?? ''),
            (string) ($fields['reason'] ?? $fields['preservation_reason'] ?? ''),
            (string) ($fields['runtime_requirement'] ?? ''),
        ));

        return implode(':', $parts);
    }

    /**
     * @return array{ids: int, classes: int, attributes: int, pseudo_classes: int, elements: int, score: string}
     */
    private static function selectorSpecificity(string $selector): array
    {
        preg_match_all('/#[A-Za-z0-9_-]+/', $selector, $ids);
        preg_match_all('/\.[A-Za-z0-9_-]+/', $selector, $classes);
        preg_match_all('/\[[^\]]+\]/', $selector, $attributes);
        preg_match_all('/:nth-of-type\(/', $selector, $pseudoClasses);
        preg_match_all('/(?:^|>\s*)([a-z][a-z0-9-]*)/i', $selector, $elements);

        $idCount = count($ids[0]);
        $classCount = count($classes[0]);
        $attributeCount = count($attributes[0]);
        $pseudoClassCount = count($pseudoClasses[0]);
        $elementCount = count($elements[1]);

        return array(
            'ids'            => $idCount,
            'classes'        => $classCount,
            'attributes'     => $attributeCount,
            'pseudo_classes' => $pseudoClassCount,
            'elements'       => $elementCount,
            'score'          => $idCount . ',' . ($classCount + $attributeCount + $pseudoClassCount) . ',' . $elementCount,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function parentReason(array $context): string
    {
        $parent = is_string($context['parent_tag'] ?? null) ? trim($context['parent_tag']) : '';

        return '' !== $parent ? 'inside_' . $parent : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function ancestorReason(array $context): string
    {
        $ancestors = is_array($context['ancestor_tags'] ?? null) ? array_values(array_filter($context['ancestor_tags'], 'is_string')) : array();

        return array() !== $ancestors ? 'within_' . implode('_', $ancestors) : '';
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function genericRepairClass(array $fields, string $patternFamily): string
    {
        if ( is_string($fields['suggested_repair_class'] ?? null) && '' !== trim($fields['suggested_repair_class']) ) {
            return (string) $fields['suggested_repair_class'];
        }

        if ( 'commerce_product_grid' === $patternFamily ) {
            return 'materialize_commerce_products';
        }

        if ( 'commerce_controls' === $patternFamily ) {
            return 'materialize_commerce_runtime';
        }

        if ( 'interactive_control' === $patternFamily ) {
            return 'restore_interactive_behavior';
        }

        if ( str_starts_with($patternFamily, 'runtime_') || in_array($patternFamily, array('interactive_form', 'external_embed', 'inline_semantic_html'), true) ) {
            return 'preserve_runtime_island';
        }

        if ( str_starts_with($patternFamily, 'unsupported_') ) {
            return 'add_generic_pattern_recognizer';
        }

        if ( 'inline_svg' === $patternFamily ) {
            return 'materialize_static_asset';
        }

        return 'review_generic_mapping';
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function runtimeIslandType(array $fields, string $patternFamily): string
    {
        $runtimeRequirement = (string) ($fields['runtime_requirement'] ?? '');
        $code = (string) ($fields['diagnostic_code'] ?? $fields['code'] ?? '');
        $kind = (string) ($fields['kind'] ?? '');

        if ( 'html_product_grid_fallback' === $code || 'commerce_product_grid' === $patternFamily ) {
            return 'provider_materializable_products';
        }

        if ( 'html_commerce_controls_fallback' === $code || 'commerce_controls' === $patternFamily || 'commerce_cart_runtime' === $runtimeRequirement ) {
            return 'commerce_cart_controls';
        }

        if ( 'html_form_fallback' === $code || 'form' === $kind || 'server_or_client_form_handler' === $runtimeRequirement ) {
            return 'provider_materializable_form';
        }

        if ( 'interactive_control_behavior_lost' === $code || 'interactive_control' === $patternFamily ) {
            return 'unsupported_custom_app_control';
        }

        if ( in_array($runtimeRequirement, array('client_script_execution', 'canvas_element_and_client_script_execution', 'client_template_instantiation'), true) || str_starts_with($patternFamily, 'runtime_') ) {
            return 'runtime_js';
        }

        return '';
    }

    /**
     * Inline elements with semantic/ARIA/class hooks cannot be represented as
     * editable RichText without risking attribute loss, so preserved runtime
     * islands should cluster separately from generic raw-HTML fallbacks.
     *
     * @param array<string, mixed> $fields
     */
    private static function isInlineSemanticHtmlRuntimeIsland(array $fields): bool
    {
        if ( 'dom' !== (string) ($fields['kind'] ?? '') ) {
            return false;
        }

        $tag = strtolower((string) ($fields['tag'] ?? ''));
        if ( ! in_array($tag, array('a', 'abbr', 'b', 'cite', 'code', 'data', 'em', 'i', 'kbd', 'label', 'mark', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var'), true) ) {
            return false;
        }

        $attributes = is_array($fields['attributes'] ?? null) ? $fields['attributes'] : array();
        foreach ( array_keys($attributes) as $name ) {
            $attributeName = strtolower((string) $name);
            if ( 'class' === $attributeName || 'id' === $attributeName || 'role' === $attributeName || str_starts_with($attributeName, 'aria-') || str_starts_with($attributeName, 'data-') ) {
                return true;
            }
        }

        return false;
    }
}
