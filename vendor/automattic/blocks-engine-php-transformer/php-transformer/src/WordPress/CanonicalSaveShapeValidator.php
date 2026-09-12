<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/**
 * Asserts that every emitted static core wrapper block carries only the class
 * and id tokens WordPress' own block `save()` would reproduce, so the block
 * round-trips cleanly through `wp.blocks.validateBlock` in the editor.
 *
 * Gutenberg validates a stored block by re-running its registered `save()` and
 * diffing the result against the saved markup. For a static core block the
 * wrapper element (the one carrying `useBlockProps.save()`) only ever emits:
 *
 *   - the `wp-block-*` / `wp-element-*` base classes,
 *   - support-derived classes (`has-*`, `is-*`, `align*`),
 *   - the verbatim tokens of the block's `className` attribute,
 *   - and an `id` taken solely from the `anchor` attribute.
 *
 * When the transformer puts a source class on a structural child instead of the
 * wrapper (the historic `core/button` leak routed `className` onto the inner
 * `<a>`/`<button>` rather than the `wp-block-button` wrapper), drops the
 * `anchor` id, or omits the generated `wp-block-*` base class that
 * `addGeneratedClassName` always reproduces for a `className`-supporting block
 * (the historic `core/heading` / `core/list` leak), the stored markup diverges
 * from `save()` and the editor flags the block invalid. This validator catches
 * that class of regression in the fast pure-PHP loop — no WordPress runtime
 * required — so most invalid-block detection moves off the editor gate.
 *
 * Scope is deliberately the static structural wrappers. Registry-versioned and
 * dynamic blocks (`core/navigation` family, `core/icon`) are skipped: their
 * `save()` returns a ref/wrapper or is plugin-provided, so a pure-PHP shape
 * assertion would false-flag them. Those stay the editor gate's job.
 *
 * One invariant is asserted for every block, not just the structural wrappers:
 * no element may carry the same class token twice. `addGeneratedClassName`,
 * `useBlockProps.save()`, and the block supports all emit each class exactly
 * once, so a doubled token (the historic `core/button` inner-anchor leak that
 * merged the structural `wp-element-button` class on top of a source className
 * that already carried it) makes the stored markup diverge from `save()` and the
 * editor flags the block invalid. The check scans every element of each block's
 * own `innerHTML`, so it catches duplicates on structural children the wrapper
 * shape assertions never inspect.
 */
final class CanonicalSaveShapeValidator
{
    public const SCHEMA = 'blocks-engine/php-transformer/canonical-save-shape-report/v1';

    public const FINDING_CODE = 'canonical_save_shape_violation';

    public const DUPLICATE_CLASS_TOKEN_CODE = 'duplicate_class_token';

    /**
     * Static core wrappers whose canonical save() shape is fully determined by
     * the block name + attributes, so a pure-PHP assertion is sound.
     *
     * @var array<int, string>
     */
    private const TARGET_BLOCKS = array(
        'core/group',
        'core/cover',
        'core/media-text',
        'core/columns',
        'core/column',
        'core/buttons',
        'core/button',
        'core/details',
        'core/heading',
        'core/list',
        'core/quote',
        'core/separator',
    );

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function findings(array $blocks): array
    {
        $findings = array();
        foreach ( $blocks as $index => $block ) {
            if ( is_array($block) ) {
                $this->validateBlock($block, 'blocks.' . $index, $findings);
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $findings
     */
    private function validateBlock(array $block, string $path, array &$findings): void
    {
        $blockName = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;

        if ( null !== $blockName && in_array($blockName, self::TARGET_BLOCKS, true) ) {
            $this->validateWrapper($block, $blockName, $path, $findings);
        }

        // The no-duplicate-class-token invariant holds for every block, not just
        // the structural wrappers, and applies to every element in the block's
        // own markup (the wrapper and any structural children), so it runs over
        // innerHTML for all blocks regardless of name.
        $this->validateNoDuplicateClassTokens($block, $blockName, $path, $findings);

        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        foreach ( $innerBlocks as $index => $innerBlock ) {
            if ( is_array($innerBlock) ) {
                $this->validateBlock($innerBlock, $path . '.innerBlocks.' . $index, $findings);
            }
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $findings
     */
    private function validateWrapper(array $block, string $blockName, string $path, array &$findings): void
    {
        $innerHTML = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        if ( ! preg_match('/<[a-zA-Z][^>]*>/', $innerHTML, $match) ) {
            return;
        }

        $wrapper       = $match[0];
        $wrapperClasses = $this->classTokens($wrapper);
        $wrapperId      = $this->attributeValue($wrapper, 'id');

        $attrs          = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
        $classNameValue = is_string($attrs['className'] ?? null) ? $attrs['className'] : '';
        $classNameTokens = $this->tokens($classNameValue);
        $anchor          = is_string($attrs['anchor'] ?? null) ? $attrs['anchor'] : '';

        // The block base class core's `addGeneratedClassName` always reproduces
        // in save() must be present on the stored wrapper. A block emitted
        // without it (the historic core/heading / core/list leak that dropped
        // the generated wp-block-* class) diverges from the current save() and
        // the editor flags it invalid.
        $generatedClass = GeneratedGutenbergClassPolicy::baseClassForBlock($blockName);
        if ( '' !== $generatedClass && ! in_array($generatedClass, $wrapperClasses, true) ) {
            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'Wrapper is missing the generated class "' . $generatedClass . '" that core save() always emits for this block; the stored markup diverges from save().',
                array( 'reason' => 'missing_generated_class', 'class' => $generatedClass )
            );
        }

        foreach ( GeneratedGutenbergClassPolicy::extraClassesForBlock($blockName) as $extraClass ) {
            if ( in_array($extraClass, $wrapperClasses, true) ) {
                continue;
            }

            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'Wrapper is missing the generated class "' . $extraClass . '" that core save() emits for this block; the stored markup diverges from save().',
                array( 'reason' => 'missing_generated_class', 'class' => $extraClass )
            );
        }

        // Every wrapper class must be either a structural/support class or a
        // token core would reproduce from the className attribute. A leftover
        // source class core's save() never regenerates breaks the round-trip.
        foreach ( $wrapperClasses as $class ) {
            if ( $this->isStructuralClass($class, $blockName, $attrs) || in_array($class, $classNameTokens, true) ) {
                continue;
            }

            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'Wrapper carries class "' . $class . '" that core save() will not reproduce; route it through the block className attribute or downgrade the wrapper to core/html.',
                array( 'reason' => 'unexpected_wrapper_class', 'class' => $class )
            );
        }

        // Every className token must land on the wrapper, because core's
        // save() merges className verbatim onto the wrapper element. A token
        // routed onto a structural child instead (the core/button inner anchor
        // leak) leaves the wrapper missing it and the child carrying it.
        foreach ( $classNameTokens as $class ) {
            if ( in_array($class, $wrapperClasses, true) ) {
                continue;
            }

            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'className token "' . $class . '" is missing from the block wrapper; core save() emits className on the wrapper element, so the stored markup diverges from save().',
                array( 'reason' => 'classname_not_on_wrapper', 'class' => $class )
            );
        }

        // An id on the wrapper is only valid when it equals the anchor
        // attribute; core save() derives the wrapper id solely from anchor.
        if ( null !== $wrapperId && '' !== $wrapperId && $wrapperId !== $anchor ) {
            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'Wrapper id "' . $wrapperId . '" is not derived from the anchor attribute; core save() only emits an id from anchor.',
                array( 'reason' => 'unexpected_wrapper_id', 'id' => $wrapperId )
            );
        }

        // When anchor is set, core save() emits it as the wrapper id. A missing
        // or relocated id diverges from save().
        if ( '' !== $anchor && $anchor !== $wrapperId ) {
            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'anchor "' . $anchor . '" is not emitted as the wrapper id; core save() places the anchor id on the wrapper element.',
                array( 'reason' => 'anchor_not_on_wrapper', 'anchor' => $anchor )
            );
        }
    }

    /**
     * Flag any element in the block's own markup that carries the same class
     * token more than once. No canonical core save() path emits a class twice,
     * so a duplicate token is always a divergence the editor rejects — whether
     * it lands on the wrapper or on a structural child the shape assertions do
     * not inspect.
     *
     * @param array<string, mixed> $block
     * @param array<int, array<string, mixed>> $findings
     */
    private function validateNoDuplicateClassTokens(array $block, ?string $blockName, string $path, array &$findings): void
    {
        $innerHTML = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        if ( '' === $innerHTML || ! preg_match_all('/<[a-zA-Z][a-zA-Z0-9:-]*\b[^>]*>/', $innerHTML, $matches) ) {
            return;
        }

        foreach ( $matches[0] as $openingTag ) {
            $classValue = $this->attributeValue($openingTag, 'class');
            if ( null === $classValue ) {
                continue;
            }

            $seen       = array();
            $duplicates = array();
            foreach ( $this->tokens($classValue) as $token ) {
                if ( isset($seen[$token]) ) {
                    $duplicates[$token] = true;
                    continue;
                }
                $seen[$token] = true;
            }

            if ( array() === $duplicates ) {
                continue;
            }

            $duplicateTokens = array_keys($duplicates);
            $this->addFinding(
                $findings,
                $path,
                $blockName,
                'Element carries duplicate class token(s) "' . implode('", "', $duplicateTokens) . '"; core save() emits each class once, so the stored markup diverges from save() and the editor flags the block invalid.',
                array( 'reason' => 'duplicate_class_token', 'class' => $classValue, 'duplicate_tokens' => $duplicateTokens ),
                self::DUPLICATE_CLASS_TOKEN_CODE
            );
        }
    }

    /**
     * A class WordPress' block supports reproduce in save() without any source
     * input: the block base class, element classes, and attribute-derived
     * support classes (alignment, color/border has-*, and is-* state/style).
     */
    /**
     * @param array<string, mixed> $attrs
     */
    private function isStructuralClass(string $class, string $blockName, array $attrs): bool
    {
        if ( 'core/media-text' === $blockName ) {
            // State classes are structural only when the attribute core's
            // save() derives them from justifies them — an unjustified state
            // class is exactly the divergence this validator exists to catch.
            if ( 'is-stacked-on-mobile' === $class ) {
                return ! array_key_exists('isStackedOnMobile', $attrs) || false !== $attrs['isStackedOnMobile'];
            }
            foreach ( array( 'top', 'center', 'bottom' ) as $alignment ) {
                if ( 'is-vertically-aligned-' . $alignment === $class ) {
                    return $alignment === ($attrs['verticalAlignment'] ?? null);
                }
            }
        }

        return str_starts_with($class, 'wp-block-')
            || str_starts_with($class, 'wp-element-')
            || str_starts_with($class, 'wp-container-')
            || str_starts_with($class, 'has-')
            || str_starts_with($class, 'is-layout-')
            || str_starts_with($class, 'align');
    }

    /**
     * @return array<int, string>
     */
    private function classTokens(string $openingTag): array
    {
        return $this->tokens($this->attributeValue($openingTag, 'class') ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: array();

        return array_values(array_filter($tokens, static fn (string $token): bool => '' !== $token));
    }

    private function attributeValue(string $openingTag, string $attribute): ?string
    {
        if ( preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/i', $openingTag, $match) ) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param array<string, mixed> $details
     */
    private function addFinding(array &$findings, string $path, ?string $blockName, string $summary, array $details, string $code = self::FINDING_CODE): void
    {
        $findings[] = array_filter(
            array(
                'code'       => $code,
                'severity'   => 'warning',
                'category'   => 'wp_block_validity',
                'path'       => $path,
                'block_name' => $blockName,
                'summary'    => $summary,
                'details'    => $details,
            ),
            static fn (mixed $value): bool => null !== $value && array() !== $value
        );
    }
}
