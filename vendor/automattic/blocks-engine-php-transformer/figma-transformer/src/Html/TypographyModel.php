<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Normalizes typography evidence into one shape for token extraction and CSS emission.
 */
final class TypographyModel
{
    public function __construct(private readonly FontResolver $fontResolver)
    {
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    public function styleFromNode(array $node): ?array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( empty($style) ) {
            return null;
        }

        return $this->styleFromNormalizedStyle($style);
    }

    /**
     * @param array<string, mixed> $style
     * @return array<string, mixed>|null
     */
    public function styleFromNormalizedStyle(array $style): ?array
    {
        $result = array();
        if ( isset($style['font_family']) && is_scalar($style['font_family']) && '' !== trim((string) $style['font_family']) ) {
            $result['font_family'] = (string) $style['font_family'];
        }
        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            $result['font_size'] = (float) $style['font_size'];
        }
        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) ) {
            $result['font_weight'] = (float) $style['font_weight'];
        }
        if ( isset($style['line_height_px']) && is_numeric($style['line_height_px']) && (float) $style['line_height_px'] > 0.0 ) {
            $result['line_height_px'] = (float) $style['line_height_px'];
        } elseif ( isset($style['line_height_raw']) && is_numeric($style['line_height_raw']) && (float) $style['line_height_raw'] > 0.0 ) {
            $result['line_height_raw'] = (float) $style['line_height_raw'];
        } elseif ( isset($style['line_height_percent']) && is_numeric($style['line_height_percent']) && (float) $style['line_height_percent'] > 0.0 ) {
            $result['line_height_percent'] = (float) $style['line_height_percent'];
        }

        return empty($result) ? null : $result;
    }

    /**
     * @param array<string, mixed> $style
     */
    public function signature(array $style): string
    {
        $family = isset($style['font_family']) ? strtolower((string) $style['font_family']) : '';
        $size = isset($style['font_size']) ? $this->number((float) $style['font_size']) : '';
        $weight = isset($style['font_weight']) ? $this->number((float) $style['font_weight']) : '';
        $lineHeight = '';
        foreach ( array('line_height_px', 'line_height_raw', 'line_height_percent') as $key ) {
            if ( isset($style[$key]) && is_numeric($style[$key]) ) {
                $lineHeight = $key . ':' . $this->number((float) $style[$key]);
                break;
            }
        }
        $key = $family . '|' . $size . '|' . $weight . '|' . $lineHeight;

        return '|||' === $key ? '' : $key;
    }

    /**
     * @param array<string, mixed> $style
     * @param array<string, string> $tokenVars Signature => CSS custom property name.
     * @return array<int, string>
     */
    public function declarations(array $style, array $tokenVars = array()): array
    {
        $styles = array();
        if ( isset($style['font_family']) && is_scalar($style['font_family']) ) {
            $styles[] = 'font-family:' . $this->fontResolver->fallbackStack((string) $style['font_family']);
        }

        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            $tokenVar = $tokenVars[$this->signature($style)] ?? null;
            $styles[] = 'font-size:' . ( is_string($tokenVar) && '' !== $tokenVar ? 'var(--' . $tokenVar . ')' : $this->number((float) $style['font_size']) . 'px' );
        }
        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) ) {
            $styles[] = 'font-weight:' . $this->number((float) $style['font_weight']);
        }
        if ( isset($style['line_height_px']) && is_numeric($style['line_height_px']) && 0.0 < (float) $style['line_height_px'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_px']) . 'px';
        } elseif ( isset($style['line_height_raw']) && is_numeric($style['line_height_raw']) && 0.0 < (float) $style['line_height_raw'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_raw']);
        } elseif ( isset($style['line_height_percent']) && is_numeric($style['line_height_percent']) && 0.0 < (float) $style['line_height_percent'] ) {
            $styles[] = 'line-height:' . $this->number((float) $style['line_height_percent']) . '%';
        }

        return $styles;
    }

    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return '-0' === $formatted ? '0' : $formatted;
    }
}
