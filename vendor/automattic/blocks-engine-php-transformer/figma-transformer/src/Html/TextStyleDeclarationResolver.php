<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Converts normalized Figma text style arrays into CSS declarations.
 */
final class TextStyleDeclarationResolver
{
    /** @var callable(float): string */
    private $number;

    /** @var callable(mixed, mixed=): ?string */
    private $color;

    public function __construct(
        private readonly TypographyModel $typographyModel,
        callable $number,
        callable $color,
    ) {
        $this->number = $number;
        $this->color = $color;
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, string> $typographyTokenVars
     * @return array<int, string>
     */
    public function declarations(array $style, array $typographyTokenVars): array
    {
        $styles = array();
        $lineHeightStyles = array();

        $typographyStyle = $this->typographyModel->styleFromNormalizedStyle($style);
        if ( null !== $typographyStyle ) {
            foreach ( $this->typographyModel->declarations($typographyStyle, $typographyTokenVars) as $declaration ) {
                if ( str_starts_with($declaration, 'line-height:') ) {
                    $lineHeightStyles[] = $declaration;
                    continue;
                }
                $styles[] = $declaration;
            }
        }

        if ( is_array($style['font_variation_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_variation_settings'] as $axis => $value ) {
                if ( is_string($axis) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $axis) && is_numeric($value) ) {
                    $settings[] = '"' . $axis . '" ' . ($this->number)((float) $value);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-variation-settings:' . implode(',', $settings);
            }
        }

        if ( is_array($style['font_feature_settings'] ?? null) ) {
            $settings = array();
            foreach ( $style['font_feature_settings'] as $feature => $enabled ) {
                if ( is_string($feature) && 1 === preg_match('/^[A-Za-z0-9 ]{4}$/', $feature) && is_numeric($enabled) ) {
                    $settings[] = '"' . $feature . '" ' . ((int) $enabled);
                }
            }
            if ( ! empty($settings) ) {
                $styles[] = 'font-feature-settings:' . implode(',', $settings);
            }
        }

        foreach ( $lineHeightStyles as $lineHeightStyle ) {
            $styles[] = $lineHeightStyle;
        }

        if ( isset($style['letter_spacing']) && is_numeric($style['letter_spacing']) ) {
            $styles[] = 'letter-spacing:' . ($this->number)((float) $style['letter_spacing']) . 'px';
        } elseif ( isset($style['letter_spacing_em']) && is_numeric($style['letter_spacing_em']) ) {
            $styles[] = 'letter-spacing:' . ($this->number)((float) $style['letter_spacing_em']) . 'em';
        }

        // Figma `paragraphIndent` maps to CSS first-line indent. Zero is implicit.
        if ( isset($style['paragraph_indent']) && is_numeric($style['paragraph_indent']) && 0.0 !== (float) $style['paragraph_indent'] ) {
            $styles[] = 'text-indent:' . ($this->number)((float) $style['paragraph_indent']) . 'px';
        }

        $color = ($this->color)($style['color'] ?? null);
        if ( null !== $color ) {
            $styles[] = 'color:' . $color;
        } elseif ( isset($style['css_color']) && is_scalar($style['css_color']) ) {
            $styles[] = 'color:' . (string) $style['css_color'];
        }

        if ( isset($style['text_align_horizontal']) && is_scalar($style['text_align_horizontal']) ) {
            $align = strtolower((string) $style['text_align_horizontal']);
            $align = 'justified' === $align ? 'justify' : $align;
            if ( in_array($align, array('left', 'center', 'right', 'justify'), true) ) {
                $styles[] = 'text-align:' . $align;
            }
        }

        if ( isset($style['text_align_vertical']) && is_scalar($style['text_align_vertical']) ) {
            $align = strtolower((string) $style['text_align_vertical']);
            if ( in_array($align, array('top', 'middle', 'bottom'), true) ) {
                $styles[] = 'vertical-align:' . $align;
            }
        }

        $decorations = array();
        if ( isset($style['text_decoration']) && is_scalar($style['text_decoration']) ) {
            $decoration = strtolower((string) $style['text_decoration']);
            if ( in_array($decoration, array('underline', 'line-through'), true) ) {
                $decorations[] = $decoration;
            }
        }
        if ( true === ($style['underline'] ?? false) ) {
            $decorations[] = 'underline';
        }
        if ( true === ($style['strikethrough'] ?? false) ) {
            $decorations[] = 'line-through';
        }
        if ( ! empty($decorations) ) {
            $styles[] = 'text-decoration:' . implode(' ', array_values(array_unique($decorations)));
        }

        if ( isset($style['text_transform']) && is_scalar($style['text_transform']) ) {
            $transform = strtolower((string) $style['text_transform']);
            if ( in_array($transform, array('uppercase', 'lowercase', 'capitalize', 'none'), true) ) {
                $styles[] = 'text-transform:' . $transform;
            }
        }

        if ( isset($style['font_variant']) && is_scalar($style['font_variant']) ) {
            $variant = strtolower((string) $style['font_variant']);
            if ( in_array($variant, array('small-caps', 'normal'), true) ) {
                $styles[] = 'font-variant:' . $variant;
            }
        }

        if ( isset($style['max_lines']) && is_numeric($style['max_lines']) && 0 < (int) $style['max_lines'] ) {
            $styles[] = '-webkit-line-clamp:' . ((int) $style['max_lines']);
            $styles[] = 'display:-webkit-box';
            $styles[] = '-webkit-box-orient:vertical';
            $styles[] = 'overflow:hidden';
        }

        return $styles;
    }
}
