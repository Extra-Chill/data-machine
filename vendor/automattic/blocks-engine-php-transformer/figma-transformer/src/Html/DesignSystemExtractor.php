<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Extracts a global design system (color, typography and spacing tokens) from
 * Figma "style guide" / "design system" frames and authors it as global CSS.
 *
 * A Figma style-guide frame is the SOURCE of a design system: its color
 * swatches, type specimens and spacing/component galleries describe the tokens
 * the rest of the file is built from. Rather than rendering such a frame as a
 * page, this extractor mines it for tokens and emits them as authored global
 * CSS — `:root{--color-…;--font-size-…;--space-…}` custom properties plus a
 * reusable type scale (`.type-heading-1`, `.type-body`, …) — that the generated
 * pages can reference. The work is purely data-driven: detection comes from the
 * frame name and its content shape, never from file-specific names.
 *
 * The emitted CSS complements {@see StaticHtmlEmitter}'s shared-class work — it
 * sits at the top of `style.css`, before the per-page rules — and builds on top
 * of the font @font-face emission rather than duplicating it.
 */
final class DesignSystemExtractor
{
    private TypographyModel $typographyModel;

    public function __construct(?FontResolver $fontResolver = null)
    {
        $this->typographyModel = new TypographyModel($fontResolver ?? new FontResolver());
    }

    /**
     * Frame-name signals that mark a frame as the design-system source.
     */
    private const NAME_SIGNALS = array(
        'style guide',
        'styleguide',
        'design system',
        'design-system',
        'designsystem',
        'tokens',
        'design tokens',
        'components',
        'styles',
        'typography',
        'color palette',
        'colour palette',
        'color styles',
        'foundations',
    );

    /**
     * Build the authored design-system CSS for a normalized scenegraph.
     *
     * Walks the scenegraph, locates style-guide/design-system frames (by name or
     * content shape), extracts color/typography/spacing tokens from them, and
     * returns both the global CSS block and a coverage diagnostic describing how
     * many tokens of each kind were extracted.
     *
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @return array{css: string, coverage: array<string, int>, frame_names: array<int, string>, type_token_map: array<string, string>, materialized_node_classes: array<int, string>}
     */
    public function extract(array $scenegraph): array
    {
        $frames = array();
        foreach ( $this->nodeList($scenegraph) as $node ) {
            if ( is_array($node) ) {
                $this->collectDesignSystemFrames($node, 0, $frames);
            }
        }

        $empty = array(
            'css'         => '',
            'coverage'    => array('color_tokens' => 0, 'type_tokens' => 0, 'spacing_tokens' => 0, 'frame_count' => 0),
            'frame_names' => array(),
            'type_token_map' => array(),
            'materialized_node_classes' => array(),
        );
        if ( empty($frames) ) {
            return $empty;
        }

        $colors = array();
        $textStyles = array();
        $spacings = array();
        $frameNames = array();
        foreach ( $frames as $frame ) {
            $frameNames[] = (string) ($frame['name'] ?? '');
            $this->collectColorTokens($frame, $colors);
            $this->collectTextStyleTokens($frame, $textStyles);
            $this->collectSpacingTokens($frame, $spacings);
        }

        $colorTokens   = $this->buildColorTokens($colors);
        $typeTokens    = $this->buildTypeTokens($textStyles);
        $spacingTokens = $this->buildSpacingTokens($spacings);

        $css = $this->renderCss($colorTokens, $typeTokens['variables'], $spacingTokens, $typeTokens['classes']);

        return array(
            'css'         => $css,
            'coverage'    => array(
                'color_tokens'   => count($colorTokens),
                'type_tokens'    => count($typeTokens['classes']),
                'spacing_tokens' => count($spacingTokens),
                'frame_count'    => count($frames),
                'materialized_type_nodes' => count($typeTokens['materialized_node_classes']),
            ),
            'frame_names' => array_values(array_filter($frameNames, static fn (string $name): bool => '' !== $name)),
            'type_token_map' => $typeTokens['token_map'],
            'materialized_node_classes' => $typeTokens['materialized_node_classes'],
        );
    }

    /**
     * Walk the tree collecting frames that read as a design-system source. A
     * matched frame's subtree is not descended into for further matches — the
     * whole subtree is the system, and its inner sections are not separate
     * systems.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $frames
     */
    private function collectDesignSystemFrames(array $node, int $depth, array &$frames): void
    {
        if ( $depth <= 4 && $this->isDesignSystemFrame($node, $depth) ) {
            $frames[] = $node;
            return;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectDesignSystemFrames($child, $depth + 1, $frames);
            }
        }
    }

    /**
     * A frame is a design-system source when its name signals one, or when its
     * content shape does: a grid of solid-color swatches, and/or a set of
     * distinct type specimens. Detection is generic — it never matches on
     * file-specific names.
     *
     * @param array<string, mixed> $node
     */
    private function isDesignSystemFrame(array $node, int $depth): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('FRAME', 'GROUP', 'SECTION', 'CANVAS', 'PAGE'), true) ) {
            return false;
        }

        if ( $this->nameSignalsDesignSystem((string) ($node['name'] ?? '')) ) {
            return true;
        }

        // Content-shape detection only applies to reasonably top-level frames so
        // an inner swatch row of a real page is not mistaken for a whole system.
        if ( $depth > 2 ) {
            return false;
        }

        $swatchCount = $this->solidSwatchCount($node);
        $specimenCount = count($this->distinctTextStyleKeys($node));

        return $swatchCount >= 4 || ( $swatchCount >= 2 && $specimenCount >= 3 );
    }

    private function nameSignalsDesignSystem(string $name): bool
    {
        $lower = strtolower(trim($name));
        if ( '' === $lower ) {
            return false;
        }
        foreach ( self::NAME_SIGNALS as $signal ) {
            if ( str_contains($lower, $signal) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count solid-filled leaf rectangles/ellipses/frames that read as color
     * swatches (small, square-ish, solid background, no text).
     *
     * @param array<string, mixed> $node
     */
    private function solidSwatchCount(array $node): int
    {
        $count = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( $this->isColorSwatch($child) ) {
                ++$count;
            }
            $count += $this->solidSwatchCount($child);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isColorSwatch(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('RECTANGLE', 'ROUNDED_RECTANGLE', 'ELLIPSE', 'FRAME', 'COMPONENT', 'INSTANCE'), true) ) {
            return false;
        }
        if ( null === $this->solidFill($node) ) {
            return false;
        }
        // A swatch carries a flat color, not nested text/imagery.
        if ( $this->subtreeHasText($node) ) {
            return false;
        }

        $width = $this->boxValue($node, 'width');
        $height = $this->boxValue($node, 'height');
        if ( null === $width || null === $height || $width <= 0.0 || $height <= 0.0 ) {
            // Solid fill with no usable box still reads as a swatch token.
            return true;
        }
        if ( $width > 480.0 || $height > 480.0 ) {
            return false;
        }
        $ratio = $width / $height;

        return $ratio >= 0.25 && $ratio <= 4.0;
    }

    /**
     * Collect every distinct swatch color in the frame, paired with the best
     * nearby text label so named tokens can use the author's intended name.
     *
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $colors keyed by css color
     */
    private function collectColorTokens(array $node, array &$colors): void
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        foreach ( $children as $index => $child ) {
            if ( $this->isColorSwatch($child) ) {
                $css = $this->solidFill($child);
                if ( null !== $css && ! isset($colors[$css]) ) {
                    $colors[$css] = array(
                        'css'   => $css,
                        'label' => $this->swatchLabel($child, $children, $index),
                    );
                }
            }
            $this->collectColorTokens($child, $colors);
        }
    }

    /**
     * Find a human label for a swatch: its own name (when meaningful), a text
     * child, or a sibling text node positioned beside/below it.
     *
     * @param array<string, mixed> $swatch
     * @param array<int, array<string, mixed>> $siblings
     */
    private function swatchLabel(array $swatch, array $siblings, int $index): string
    {
        $ownName = trim((string) ($swatch['name'] ?? ''));
        if ( '' !== $ownName && ! $this->isGenericName($ownName) ) {
            return $ownName;
        }

        $childText = trim($this->subtreePlainText($swatch));
        if ( '' !== $childText ) {
            return $childText;
        }

        // A swatch is often a grouping frame containing the rect + a caption
        // text node; otherwise the caption is the next sibling.
        $next = $siblings[$index + 1] ?? null;
        if ( is_array($next) && 'TEXT' === strtoupper((string) ($next['type'] ?? '')) ) {
            $label = trim($this->subtreePlainText($next));
            if ( '' !== $label ) {
                return $label;
            }
        }

        return '';
    }

    /**
     * Collect distinct text styles in the frame as type specimens, keyed by a
     * stable signature (family + size + weight + line-height).
     *
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $textStyles keyed by signature
     */
    private function collectTextStyleTokens(array $node, array &$textStyles): void
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $style = $this->textStyle($node);
            if ( null !== $style ) {
                $key = $this->textStyleKey($style);
                if ( '' !== $key && ! isset($textStyles[$key]) ) {
                    $style['label'] = trim((string) ($node['name'] ?? ''));
                    $style['node_class'] = $this->nodeClass($node);
                    $textStyles[$key] = $style;
                }
            }
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectTextStyleTokens($child, $textStyles);
            }
        }
    }

    /**
     * Collect consistent auto-layout gaps and paddings as spacing candidates.
     *
     * @param array<string, mixed> $node
     * @param array<int, float> $spacings
     */
    private function collectSpacingTokens(array $node, array &$spacings): void
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ) {
            $value = (float) $layout['item_spacing'];
            if ( $value > 0.0 ) {
                $spacings[] = $value;
            }
        }
        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        foreach ( $padding as $edge ) {
            if ( is_numeric($edge) && (float) $edge > 0.0 ) {
                $spacings[] = (float) $edge;
            }
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectSpacingTokens($child, $spacings);
            }
        }
    }

    /**
     * Distinct text-style signatures found in a subtree — used by content-shape
     * detection to count type specimens.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function distinctTextStyleKeys(array $node): array
    {
        $styles = array();
        $this->collectTextStyleTokens($node, $styles);

        return array_keys($styles);
    }

    /**
     * Mint deterministic color tokens: stable order (by appearance), unique
     * `--color-*` names derived from labels (falling back to an index), values
     * are the swatch CSS colors.
     *
     * @param array<string, array<string, mixed>> $colors
     * @return array<int, array{name: string, value: string}>
     */
    private function buildColorTokens(array $colors): array
    {
        $tokens = array();
        $used = array();
        $autoIndex = 1;
        foreach ( $colors as $color ) {
            $css = (string) $color['css'];
            $label = (string) ($color['label'] ?? '');
            $base = $this->colorTokenBaseName($label);
            if ( '' === $base ) {
                $base = (string) $autoIndex;
                ++$autoIndex;
            }
            $name = 'color-' . $base;
            $suffix = 2;
            while ( isset($used[$name]) ) {
                $name = 'color-' . $base . '-' . $suffix;
                ++$suffix;
            }
            $used[$name] = true;
            $tokens[] = array('name' => $name, 'value' => $css);
        }

        return $tokens;
    }

    private function colorTokenBaseName(string $label): string
    {
        $slug = $this->slug($label);
        if ( 'node' === $slug || '' === $slug ) {
            return '';
        }
        // A label that is just the hex value carries no semantic name.
        if ( 1 === preg_match('/^[0-9a-f]{3}([0-9a-f]{3})?$/', $slug) ) {
            return '';
        }

        return $slug;
    }

    /**
     * Mint a type scale from the distinct text styles. The most-common-looking
     * specimens are ordered largest-first; each becomes a `--font-size-*` var
     * plus a reusable `.type-*` class carrying family/size/weight/line-height.
     * Heading-sized specimens become `heading-1..n`; the smallest common size is
     * the body class.
     *
     * @param array<string, array<string, mixed>> $textStyles
     * @return array{variables: array<int, array{name: string, value: string}>, classes: array<int, array{name: string, declarations: array<int, string>, node_classes: array<int, string>}>, token_map: array<string, string>, materialized_node_classes: array<int, string>}
     */
    private function buildTypeTokens(array $textStyles): array
    {
        if ( empty($textStyles) ) {
            return array('variables' => array(), 'classes' => array(), 'token_map' => array(), 'materialized_node_classes' => array());
        }

        $styles = array_values($textStyles);
        // Largest size first; heavier weight wins ties so display styles lead.
        usort($styles, static function (array $a, array $b): int {
            $sizeCmp = ($b['font_size'] ?? 0.0) <=> ($a['font_size'] ?? 0.0);
            if ( 0 !== $sizeCmp ) {
                return $sizeCmp;
            }

            return ($b['font_weight'] ?? 0.0) <=> ($a['font_weight'] ?? 0.0);
        });

        $count = count($styles);
        $variables = array();
        $classes = array();
        $tokenMap = array();
        $materializedNodeClasses = array();
        $usedVarNames = array();
        $headingLevel = 1;

        foreach ( $styles as $index => $style ) {
            // The single smallest specimen reads as body copy; everything above
            // it is a descending heading level.
            $isBody = ( $index === $count - 1 ) && $count > 1;
            $role = $isBody ? 'body' : 'heading-' . $headingLevel;
            if ( ! $isBody ) {
                ++$headingLevel;
            }

            $size = isset($style['font_size']) && is_numeric($style['font_size']) ? (float) $style['font_size'] : null;
            $declarations = array();

            foreach ( $this->typographyModel->declarations($style) as $declaration ) {
                if ( ! str_starts_with($declaration, 'font-size:') ) {
                    $declarations[] = $declaration;
                }
            }
            if ( null !== $size ) {
                $varName = 'font-size-' . $role;
                $unique = $varName;
                $dupe = 2;
                while ( isset($usedVarNames[$unique]) ) {
                    $unique = $varName . '-' . $dupe;
                    ++$dupe;
                }
                $usedVarNames[$unique] = true;
                $variables[] = array('name' => $unique, 'value' => $this->number($size) . 'px');
                $tokenMap[$this->textStyleKey($style)] = $unique;
                $declarations[] = 'font-size:var(--' . $unique . ')';
            }
            if ( empty($declarations) ) {
                continue;
            }

            $nodeClasses = array();
            if ( isset($style['node_class']) && is_string($style['node_class']) && '' !== $style['node_class'] ) {
                $nodeClasses[] = $style['node_class'];
                $materializedNodeClasses[] = $style['node_class'];
            }
            $classes[] = array('name' => 'type-' . $role, 'declarations' => $declarations, 'node_classes' => $nodeClasses);
        }

        return array(
            'variables' => $variables,
            'classes' => $classes,
            'token_map' => $tokenMap,
            'materialized_node_classes' => array_values(array_unique($materializedNodeClasses)),
        );
    }

    /**
     * Mint spacing tokens from spacing values that recur often enough to be a
     * deliberate scale step. Returns ascending `--space-*` tokens.
     *
     * @param array<int, float> $spacings
     * @return array<int, array{name: string, value: string}>
     */
    private function buildSpacingTokens(array $spacings): array
    {
        if ( empty($spacings) ) {
            return array();
        }

        $counts = array();
        foreach ( $spacings as $value ) {
            $key = $this->number($value);
            if ( ! isset($counts[$key]) ) {
                $counts[$key] = array('value' => $value, 'count' => 0);
            }
            ++$counts[$key]['count'];
        }

        // A spacing step is a token when it recurs (a real scale value) — a
        // one-off gap is layout noise, not a token. If nothing recurs, fall back
        // to the distinct values so a sparse system still yields a scale.
        $recurring = array_filter($counts, static fn (array $entry): bool => $entry['count'] >= 2);
        $source = ! empty($recurring) ? $recurring : $counts;

        $values = array();
        foreach ( $source as $entry ) {
            $values[] = (float) $entry['value'];
        }
        sort($values);

        $tokens = array();
        $step = 1;
        foreach ( $values as $value ) {
            $tokens[] = array('name' => 'space-' . $step, 'value' => $this->number($value) . 'px');
            ++$step;
        }

        return $tokens;
    }

    /**
     * Assemble the authored global CSS: a `:root` block of custom properties
     * followed by the reusable type-scale classes. Empty when nothing was
     * extracted.
     *
     * @param array<int, array{name: string, value: string}> $colorTokens
     * @param array<int, array{name: string, value: string}> $typeVariables
     * @param array<int, array{name: string, value: string}> $spacingTokens
     * @param array<int, array{name: string, declarations: array<int, string>}> $typeClasses
     */
    private function renderCss(array $colorTokens, array $typeVariables, array $spacingTokens, array $typeClasses): string
    {
        $rootDeclarations = array();
        foreach ( $colorTokens as $token ) {
            $rootDeclarations[] = '--' . $token['name'] . ':' . $token['value'];
        }
        foreach ( $typeVariables as $token ) {
            $rootDeclarations[] = '--' . $token['name'] . ':' . $token['value'];
        }
        foreach ( $spacingTokens as $token ) {
            $rootDeclarations[] = '--' . $token['name'] . ':' . $token['value'];
        }

        $blocks = array();
        if ( ! empty($rootDeclarations) ) {
            $blocks[] = ':root{' . implode(';', $rootDeclarations) . '}';
        }
        foreach ( $typeClasses as $class ) {
            $selectors = array('.' . $class['name']);
            foreach ( is_array($class['node_classes'] ?? null) ? $class['node_classes'] : array() as $nodeClass ) {
                if ( is_string($nodeClass) && '' !== $nodeClass ) {
                    $selectors[] = '.' . $nodeClass;
                }
            }
            $blocks[] = implode(',', array_values(array_unique($selectors))) . '{' . implode(';', $class['declarations']) . '}';
        }

        return empty($blocks) ? '' : implode("\n", $blocks) . "\n";
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function textStyle(array $node): ?array
    {
        return $this->typographyModel->styleFromNode($node);
    }

    /**
     * @param array<string, mixed> $style
     */
    private function textStyleKey(array $style): string
    {
        return $this->typographyModel->signature($style);
    }

    /**
     * The first solid-paint fill of a node as a CSS color, if any.
     *
     * @param array<string, mixed> $node
     */
    private function solidFill(array $node): ?string
    {
        $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) || 'SOLID' !== ($paint['type'] ?? null) ) {
                continue;
            }
            $color = $this->color($paint['color'] ?? null, $paint['opacity'] ?? null);
            if ( null !== $color ) {
                return $color;
            }
        }

        // Fall back to the simpler color carriers the normalizer may leave in
        // place, mirroring StaticHtmlEmitter::backgroundColor.
        return $this->color($node['backgroundColor'] ?? $node['background'] ?? $node['fill'] ?? null);
    }

    private function color(mixed $value, mixed $opacity = null): ?string
    {
        if ( is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ) {
            return strtolower($value);
        }
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->colorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->colorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->colorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $alpha = $opacity;
        if ( null === $alpha && isset($value['a']) ) {
            $alpha = $value['a'];
        }
        if ( is_numeric($alpha) && (float) $alpha < 1 ) {
            return sprintf('rgba(%d,%d,%d,%s)', $red, $green, $blue, $this->number(max(0, (float) $alpha)));
        }

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    private function colorChannel(mixed $value): ?int
    {
        if ( ! is_numeric($value) ) {
            return null;
        }
        $channel = (float) $value;
        if ( $channel <= 1 ) {
            $channel *= 255;
        }

        return max(0, min(255, (int) round($channel)));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeClass(array $node): string
    {
        return 'figma-node-' . $this->slug((string) ($node['id'] ?? '') . '-' . (string) ($node['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasText(array $node): bool
    {
        return '' !== trim($this->subtreePlainText($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreePlainText(array $node): string
    {
        $parts = array();
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $own = $this->nodePlainText($node);
            if ( '' !== $own ) {
                $parts[] = $own;
            }
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childText = $this->subtreePlainText($child);
            if ( '' !== $childText ) {
                $parts[] = $childText;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodePlainText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }
        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return '';
    }

    /**
     * A generic node name (e.g. "Rectangle", "Frame 12") carries no token
     * semantics and should not become a token base name.
     */
    private function isGenericName(string $name): bool
    {
        $lower = strtolower(trim($name));

        return 1 === preg_match('/^(rectangle|ellipse|frame|group|vector|component|instance|shape|swatch|color|colour)(\s*\d+)?$/', $lower);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function boxValue(array $node, string $key): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$key]) && is_numeric($box[$key]) ) {
            return (float) $box[$key];
        }
        if ( isset($node[$key]) && is_numeric($node[$key]) ) {
            return (float) $node[$key];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }
        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
