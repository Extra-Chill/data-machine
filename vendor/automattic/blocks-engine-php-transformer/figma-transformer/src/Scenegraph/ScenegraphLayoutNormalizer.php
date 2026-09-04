<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes Figma geometry boxes and Auto Layout metadata.
 */
final class ScenegraphLayoutNormalizer
{
    private const DIMENSION_ZERO_EPSILON = 0.000001;

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function normalizeLayoutBox(array $node): array
    {
        $box = array();
        $sourceKind = null;

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( ! is_array($node[$boundsKey] ?? null) ) {
                continue;
            }

            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                    $box[$dimension] = $this->normalizeBoxNumber((float) $node[$boundsKey][$dimension], $dimension);
                }
            }

            if ( isset($node[$boundsKey]['x']) || isset($node[$boundsKey]['y']) ) {
                $sourceKind = GeometryBox::SOURCE_ABSOLUTE_BOUNDS;
            }
        }

        if ( is_array($node['relativeTransformBounds'] ?? null) ) {
            foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
                if ( ! array_key_exists($dimension, $box) && isset($node['relativeTransformBounds'][$dimension]) && is_numeric($node['relativeTransformBounds'][$dimension]) ) {
                    $box[$dimension] = $this->normalizeBoxNumber((float) $node['relativeTransformBounds'][$dimension], $dimension);
                }
            }

            if ( isset($node['relativeTransformBounds']['x']) || isset($node['relativeTransformBounds']['y']) ) {
                $sourceKind = GeometryBox::SOURCE_TRANSFORM;
            }
        }

        foreach ( array('x', 'y', 'width', 'height') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $box[$dimension] = $this->normalizeBoxNumber((float) $node[$dimension], $dimension);
                if ( 'x' === $dimension || 'y' === $dimension ) {
                    $sourceKind = isset($node[GeometryBox::PROVENANCE_KEY]) && is_scalar($node[GeometryBox::PROVENANCE_KEY])
                        ? (string) $node[GeometryBox::PROVENANCE_KEY]
                        : GeometryBox::SOURCE_EXPLICIT_LOCAL;
                }
            }
        }

        if ( is_array($node['size'] ?? null) ) {
            foreach ( array('x' => 'width', 'y' => 'height') as $source => $target ) {
                if ( ! array_key_exists($target, $box) && isset($node['size'][$source]) && is_numeric($node['size'][$source]) ) {
                    $box[$target] = $this->normalizeBoxNumber((float) $node['size'][$source], $target);
                    $sourceKind ??= GeometryBox::SOURCE_SIZE_ONLY;
                }
            }
        }

        foreach ( array('stackWidth' => 'width', 'stackHeight' => 'height') as $source => $target ) {
            if ( ! array_key_exists($target, $box) && isset($node[$source]) && is_numeric($node[$source]) ) {
                $box[$target] = $this->normalizeBoxNumber((float) $node[$source], $target);
                $sourceKind ??= GeometryBox::SOURCE_SIZE_ONLY;
            }
        }

        $transformBox = $this->layoutBoxFromTransform($node);
        foreach ( array('x', 'y') as $dimension ) {
            if ( ! array_key_exists($dimension, $box) && isset($transformBox[$dimension]) ) {
                $box[$dimension] = $transformBox[$dimension];
                $sourceKind = $transformBox[GeometryBox::PROVENANCE_KEY];
            }
        }

        if ( null !== $sourceKind ) {
            $box = GeometryBox::withProvenance($box, $sourceKind);
        }

        return GeometryBox::withoutProvenance($box);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x?: float, y?: float, _geometry_provenance?: string}
     */
    private function layoutBoxFromTransform(array $node): array
    {
        foreach ( array(
            'relativeTransform' => GeometryBox::SOURCE_TRANSFORM,
            'transform'         => GeometryBox::SOURCE_TRANSFORM,
            'absoluteTransform' => GeometryBox::SOURCE_ABSOLUTE_TRANSFORM,
        ) as $sourceKey => $sourceKind ) {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                continue;
            }

            $box = array(GeometryBox::PROVENANCE_KEY => $sourceKind);
            foreach ( array('m02' => 'x', 'm12' => 'y') as $source => $target ) {
                if ( isset($node[$sourceKey][$source]) && is_numeric($node[$sourceKey][$source]) ) {
                    $box[$target] = (float) $node[$sourceKey][$source];
                }
            }

            if ( isset($box['x']) || isset($box['y']) ) {
                return $box;
            }
        }

        return array();
    }

    private function normalizeBoxNumber(float $value, string $dimension): float
    {
        if ( ! in_array($dimension, array('width', 'height'), true) ) {
            return $value;
        }

        if ( ! is_finite($value) || abs($value) <= self::DIMENSION_ZERO_EPSILON ) {
            return 0.0;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function normalizeLayout(array $node): array
    {
        $layout = array();

        if ( isset($node['layoutMode']) && is_scalar($node['layoutMode']) ) {
            $mode = strtoupper((string) $node['layoutMode']);
            $layout['mode'] = $mode;
        } elseif ( isset($node['stackMode']) && is_scalar($node['stackMode']) ) {
            $mode = strtoupper((string) $node['stackMode']);
            $layout['mode'] = $mode;
        }
        if ( isset($layout['mode']) ) {
            if ( 'HORIZONTAL' === $layout['mode'] ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'row';
            } elseif ( 'VERTICAL' === $layout['mode'] ) {
                $layout['display'] = 'flex';
                $layout['flex_direction'] = 'column';
            }
        }

        foreach ( array(
            'layoutSizingHorizontal' => 'sizing_horizontal',
            'layoutSizingVertical' => 'sizing_vertical',
            'horizontalSizing' => 'sizing_horizontal',
            'verticalSizing' => 'sizing_vertical',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        foreach ( array(
            'primary_axis_sizing' => array('rest' => 'primaryAxisSizingMode', 'kiwi' => 'stackPrimarySizing'),
            'counter_axis_sizing' => array('rest' => 'counterAxisSizingMode', 'kiwi' => 'stackCounterSizing'),
        ) as $target => $sources ) {
            $raw = null;
            if ( isset($node[$sources['rest']]) && is_scalar($node[$sources['rest']]) ) {
                $raw = (string) $node[$sources['rest']];
            } elseif ( isset($node[$sources['kiwi']]) && is_scalar($node[$sources['kiwi']]) ) {
                $raw = (string) $node[$sources['kiwi']];
            }
            if ( null !== $raw ) {
                $layout[$target] = $this->normalizeAxisSizingValue($raw);
            }
        }

        $flexDirection = $layout['flex_direction'] ?? null;
        if ( 'row' === $flexDirection || 'column' === $flexDirection ) {
            $primaryAxisKey = 'row' === $flexDirection ? 'sizing_horizontal' : 'sizing_vertical';
            $counterAxisKey = 'row' === $flexDirection ? 'sizing_vertical' : 'sizing_horizontal';
            if ( isset($layout['primary_axis_sizing']) && ! isset($layout[$primaryAxisKey]) ) {
                $layout[$primaryAxisKey] = $layout['primary_axis_sizing'];
            }
            if ( isset($layout['counter_axis_sizing']) && ! isset($layout[$counterAxisKey]) ) {
                $layout[$counterAxisKey] = $layout['counter_axis_sizing'];
            }
        }

        if ( isset($node['textAutoResize']) && is_scalar($node['textAutoResize']) && '' !== (string) $node['textAutoResize'] ) {
            $autoResize = strtoupper((string) $node['textAutoResize']);
            $layout['text_auto_resize'] = $autoResize;
            [$autoResizeHorizontal, $autoResizeVertical] = match ( $autoResize ) {
                'WIDTH_AND_HEIGHT' => array('HUG', 'HUG'),
                'HEIGHT'           => array(null, 'HUG'),
                default            => array(null, null),
            };
            if ( null !== $autoResizeHorizontal && ! isset($layout['sizing_horizontal']) ) {
                $layout['sizing_horizontal'] = $autoResizeHorizontal;
            }
            if ( null !== $autoResizeVertical && ! isset($layout['sizing_vertical']) ) {
                $layout['sizing_vertical'] = $autoResizeVertical;
            }
            if ( 'TRUNCATE' === $autoResize ) {
                $layout['clips_content'] = true;
            }
        }

        foreach ( array(
            'primaryAxisAlignItems' => 'primary_axis_alignment',
            'counterAxisAlignItems' => 'counter_axis_alignment',
            'stackPrimaryAlignItems' => 'primary_axis_alignment',
            'stackCounterAlignItems' => 'counter_axis_alignment',
        ) as $source => $target ) {
            if ( isset($node[$source]) && is_scalar($node[$source]) ) {
                $layout[$target] = strtoupper((string) $node[$source]);
            }
        }

        if ( isset($layout['primary_axis_alignment']) ) {
            $layout['justify_content'] = $this->cssAxisAlignment((string) $layout['primary_axis_alignment']);
        }
        if ( isset($layout['counter_axis_alignment']) ) {
            $layout['align_items'] = $this->cssAxisAlignment((string) $layout['counter_axis_alignment']);
        }

        $padding = array();
        if ( isset($node['stackPadding']) && is_numeric($node['stackPadding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                $padding[$edge] = (float) $node['stackPadding'];
            }
        }
        foreach ( array('top' => 'paddingTop', 'right' => 'paddingRight', 'bottom' => 'paddingBottom', 'left' => 'paddingLeft') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left' => 'stackPaddingLeft', 'right' => 'stackPaddingRight', 'top' => 'stackPaddingTop', 'bottom' => 'stackPaddingBottom') as $edge => $source ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $padding[$edge] = (float) $node[$source];
            }
        }
        foreach ( array('left', 'right') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingHorizontal']) && is_numeric($node['paddingHorizontal']) ) {
                $padding[$edge] = (float) $node['paddingHorizontal'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackHorizontalPadding']) && is_numeric($node['stackHorizontalPadding']) ) {
                $padding[$edge] = (float) $node['stackHorizontalPadding'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['horizontalPadding']) && is_numeric($node['horizontalPadding']) ) {
                $padding[$edge] = (float) $node['horizontalPadding'];
            }
        }
        foreach ( array('top', 'bottom') as $edge ) {
            if ( ! array_key_exists($edge, $padding) && isset($node['paddingVertical']) && is_numeric($node['paddingVertical']) ) {
                $padding[$edge] = (float) $node['paddingVertical'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['stackVerticalPadding']) && is_numeric($node['stackVerticalPadding']) ) {
                $padding[$edge] = (float) $node['stackVerticalPadding'];
            } elseif ( ! array_key_exists($edge, $padding) && isset($node['verticalPadding']) && is_numeric($node['verticalPadding']) ) {
                $padding[$edge] = (float) $node['verticalPadding'];
            }
        }
        if ( ! empty($padding) ) {
            $layout['padding'] = $padding;
        }

        if ( isset($node['itemSpacing']) && is_numeric($node['itemSpacing']) ) {
            $layout['item_spacing'] = (float) $node['itemSpacing'];
        } elseif ( isset($node['stackSpacing']) && is_numeric($node['stackSpacing']) ) {
            $layout['item_spacing'] = (float) $node['stackSpacing'];
        } elseif ( isset($node['gap']) && is_numeric($node['gap']) ) {
            $layout['item_spacing'] = (float) $node['gap'];
        } elseif ( isset($node['stackHorizontalGap']) && is_numeric($node['stackHorizontalGap']) ) {
            $layout['item_spacing'] = (float) $node['stackHorizontalGap'];
        } elseif ( isset($node['stackVerticalGap']) && is_numeric($node['stackVerticalGap']) ) {
            $layout['item_spacing'] = (float) $node['stackVerticalGap'];
        }
        if ( isset($node['counterAxisSpacing']) && is_numeric($node['counterAxisSpacing']) ) {
            $layout['counter_axis_spacing'] = (float) $node['counterAxisSpacing'];
        } elseif ( isset($node['stackCounterSpacing']) && is_numeric($node['stackCounterSpacing']) ) {
            $layout['counter_axis_spacing'] = (float) $node['stackCounterSpacing'];
        } elseif ( isset($node['counterAxisGap']) && is_numeric($node['counterAxisGap']) ) {
            $layout['counter_axis_spacing'] = (float) $node['counterAxisGap'];
        }

        if ( isset($node['layoutWrap']) && is_scalar($node['layoutWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['layoutWrap']);
        } elseif ( isset($node['stackWrap']) && is_scalar($node['stackWrap']) ) {
            $layout['wrap'] = strtoupper((string) $node['stackWrap']);
        }
        if ( 'WRAP' === ($layout['wrap'] ?? null) ) {
            $layout['flex_wrap'] = 'wrap';
        }

        if ( true === ($node['stackReverseZIndex'] ?? false) || 'true' === strtolower((string) ($node['stackReverseZIndex'] ?? '')) || 1 === ($node['stackReverseZIndex'] ?? null) || '1' === (string) ($node['stackReverseZIndex'] ?? '') ) {
            $layout['reverse_z_index'] = true;
        }

        $positioning = null;
        if ( isset($node['layoutPositioning']) && is_scalar($node['layoutPositioning']) ) {
            $positioning = strtoupper((string) $node['layoutPositioning']);
        } elseif ( isset($node['stackPositioning']) && is_scalar($node['stackPositioning']) ) {
            $positioning = strtoupper((string) $node['stackPositioning']);
        }
        if ( 'ABSOLUTE' === $positioning ) {
            $layout['positioning'] = 'absolute';
        }

        if ( isset($node['layoutGrow']) && is_numeric($node['layoutGrow']) ) {
            $layout['grow'] = (float) $node['layoutGrow'];
        } elseif ( isset($node['stackChildPrimaryGrow']) && is_numeric($node['stackChildPrimaryGrow']) ) {
            $layout['grow'] = (float) $node['stackChildPrimaryGrow'];
        }
        if ( isset($node['layoutAlign']) && is_scalar($node['layoutAlign']) ) {
            $layout['align'] = strtoupper((string) $node['layoutAlign']);
        } elseif ( isset($node['stackChildAlignSelf']) && is_scalar($node['stackChildAlignSelf']) ) {
            $layout['align'] = strtoupper((string) $node['stackChildAlignSelf']);
        }
        foreach ( array('layoutOrder', 'stackChildOrder') as $orderKey ) {
            if ( isset($node[$orderKey]) && is_numeric($node[$orderKey]) ) {
                $layout['order'] = (int) $node[$orderKey];
                break;
            }
        }
        if ( true === ($node['clipsContent'] ?? false) || true === ($node['isClip'] ?? false) || false === ($node['frameMaskDisabled'] ?? null) ) {
            $layout['clips_content'] = true;
        }

        $constraints = array();
        if ( is_array($node['constraints'] ?? null) ) {
            foreach ( array('horizontal', 'vertical') as $axis ) {
                if ( isset($node['constraints'][$axis]) && is_scalar($node['constraints'][$axis]) ) {
                    $constraints[$axis] = strtoupper((string) $node['constraints'][$axis]);
                }
            }
        }
        foreach ( array('horizontal' => 'horizontalConstraint', 'vertical' => 'verticalConstraint') as $axis => $kiwiKey ) {
            if ( isset($constraints[$axis]) || ! isset($node[$kiwiKey]) || ! is_scalar($node[$kiwiKey]) ) {
                continue;
            }
            $translated = $this->normalizeKiwiConstraint(strtoupper((string) $node[$kiwiKey]), $axis);
            if ( null !== $translated ) {
                $constraints[$axis] = $translated;
            }
        }
        if ( ! empty($constraints) ) {
            $layout['constraints'] = $constraints;
        }

        foreach ( array('minSize' => 'min', 'maxSize' => 'max') as $source => $prefix ) {
            if ( ! is_array($node[$source] ?? null) ) {
                continue;
            }
            foreach ( array('x' => 'width', 'y' => 'height') as $axis => $dimension ) {
                if ( isset($node[$source][$axis]) && is_numeric($node[$source][$axis]) ) {
                    $value = (float) $node[$source][$axis];
                    if ( is_finite($value) && $value >= 0.0 ) {
                        $layout[$prefix . '_' . $dimension] = $value;
                    }
                }
            }
        }
        foreach ( array('minWidth' => 'min_width', 'maxWidth' => 'max_width', 'minHeight' => 'min_height', 'maxHeight' => 'max_height') as $source => $target ) {
            if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                $value = (float) $node[$source];
                if ( is_finite($value) && $value >= 0.0 ) {
                    $layout[$target] = $value;
                }
            }
        }

        if ( ! isset($layout['display']) && is_array($node['children'] ?? null) && count($node['children']) > 1 ) {
            $type = strtoupper((string) ($node['type'] ?? ''));
            if ( true === ($node['resizeToFit'] ?? false) || in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) ) {
                $layout['freeform'] = true;
            }
        }

        return $layout;
    }

    private function normalizeAxisSizingValue(string $value): string
    {
        return match ( strtoupper($value) ) {
            'AUTO', 'HUG', 'RESIZE_TO_FIT', 'RESIZE_TO_FIT_WITH_IMPLICIT_SIZE' => 'HUG',
            'FILL', 'STRETCH' => 'FILL',
            default => 'FIXED',
        };
    }

    private function normalizeKiwiConstraint(string $value, string $axis): ?string
    {
        $isHorizontal = 'horizontal' === $axis;

        return match ( strtoupper($value) ) {
            'MIN' => $isHorizontal ? 'LEFT' : 'TOP',
            'MAX' => $isHorizontal ? 'RIGHT' : 'BOTTOM',
            'STRETCH' => $isHorizontal ? 'LEFT_RIGHT' : 'TOP_BOTTOM',
            'CENTER' => 'CENTER',
            'SCALE' => 'SCALE',
            default => null,
        };
    }

    private function cssAxisAlignment(string $alignment): ?string
    {
        return match ( strtoupper($alignment) ) {
            'MIN' => 'flex-start',
            'CENTER' => 'center',
            'MAX' => 'flex-end',
            'SPACE_BETWEEN' => 'space-between',
            'SPACE_EVENLY' => 'space-between',
            'BASELINE' => 'baseline',
            'STRETCH' => 'stretch',
            default => null,
        };
    }
}
