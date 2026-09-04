<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Builds source visual boxes used by DOM-box parity diagnostics.
 */
final class VisualNodeMapBuilder
{
    private readonly LayoutIntentClassifier $layoutIntentClassifier;

    private readonly VisualGeometryResolver $visualGeometryResolver;

    private readonly SourceGeometryFlexGapResolver $sourceGeometryFlexGapResolver;

    /**
     * @param array<string, array<string, mixed>> $assetsById
     */
    public function __construct(
        private readonly array $assetsById = array(),
        private readonly bool $renderTextGlyphPaths = false,
        private readonly array $emittedNodeMetadata = array()
    ) {
        $this->layoutIntentClassifier = new LayoutIntentClassifier($assetsById);
        $this->visualGeometryResolver = new VisualGeometryResolver($this->layoutIntentClassifier);
        $this->sourceGeometryFlexGapResolver = new SourceGeometryFlexGapResolver(
            $this->layoutIntentClassifier,
            fn (array $node, array $parentNode): bool => $this->normalFlexFlowChild($node, $parentNode),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    public function build(array $nodes): array
    {
        $map = array();
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->appendVisualNodeMap($node, $map, 0.0, 0.0, null, null, $this->identityVisualTransformMatrix());
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $map
     */
    private function appendVisualNodeMap(array $node, array &$map, float $x, float $y, ?array $parentNode, ?array $clipRect, array $parentTransform): void
    {
        if ( $this->isFullyTransparentVisualNode($node) ) {
            return;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();

        if ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
            $x += $this->positionOffset($box, $parentBox, 'x', $parentNode) ?? 0.0;
            $y += $this->positionOffset($box, $parentBox, 'y', $parentNode) ?? 0.0;
        } elseif ( null !== $parentNode && ('absolute' === ($layout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($node, $parentNode)) ) {
            $x += $this->positionOffset($box, $parentBox, 'x', $parentNode) ?? 0.0;
            $y += $this->positionOffset($box, $parentBox, 'y', $parentNode) ?? 0.0;
        }

        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        $nodeTransform = null !== $width && null !== $height ? $this->visualTransformMatrix($parentTransform, $x, $y, $width, $height, $node) : null;
        $nodeRect = null !== $width && null !== $height && null !== $nodeTransform ? $this->transformedVisualRect($width, $height, $nodeTransform) : null;
        $visibleRect = null;
        if ( null !== $nodeRect && null !== $clipRect && $this->isClippableDecorativeVisualNode($node) ) {
            $visibleRect = $this->rectIntersection($nodeRect, $clipRect);
            if ( null === $visibleRect ) {
                return;
            }
        }

        if ( null !== $width && null !== $height ) {
            $imagePaint = $this->firstImagePaint($node);
            $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
            $entry = array(
                'id' => (string) ($node['id'] ?? ''),
                'parent_id' => null !== $parentNode ? (string) ($parentNode['id'] ?? '') : '',
                'name' => (string) ($node['name'] ?? ''),
                'type' => strtoupper((string) ($node['type'] ?? '')),
                'rect' => $nodeRect,
                'layout' => array(
                    'display' => $layout['display'] ?? null,
                    'flex_direction' => $layout['flex_direction'] ?? null,
                    'positioning' => $layout['positioning'] ?? null,
                    'coordinate_space' => $box['coordinate_space'] ?? null,
                ),
                'image' => null === $imagePaint ? null : $this->visualImageMetadata($imagePaint),
                'paints' => is_array($node['figma_paints'] ?? null) ? $node['figma_paints'] : null,
                'text' => empty($text) ? null : $this->visualTextMetadata($text),
                // Figma Dev Mode status (#280) surfaced for the diagnostics map.
                'dev_status' => isset($node['dev_status']) && is_string($node['dev_status']) ? $node['dev_status'] : null,
            );
            $geometryConfidence = $this->visualGeometryConfidence($node, $box);
            if ( null !== $geometryConfidence ) {
                $entry['coordinate_space'] = $box['coordinate_space'] ?? null;
                $entry['geometry_confidence'] = $geometryConfidence;
            }
            $emittedMetadata = $this->emittedMetadataForNode((string) ($node['id'] ?? ''));
            if ( ! empty($emittedMetadata) ) {
                $entry['emitted_class'] = $emittedMetadata['class'] ?? null;
                $entry['emitted_tag'] = $emittedMetadata['tag'] ?? null;
                $entry['page_path'] = $emittedMetadata['page_path'] ?? null;
            }
            if ( null !== $visibleRect && $visibleRect !== $nodeRect ) {
                $entry['visible_rect'] = $visibleRect;
                $entry['clip'] = array('source' => 'parent_clips_content');
            }
            $map[] = $entry;
        }

        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return;
        }

        $childClipRect = $clipRect;
        if ( null !== $nodeRect && true === ($layout['clips_content'] ?? false) ) {
            $childClipRect = null === $childClipRect ? $nodeRect : $this->rectIntersection($childClipRect, $nodeRect);
            if ( null === $childClipRect ) {
                return;
            }
        }

        $paddingLeft = $this->visualPaddingValue($node, 'left');
        $paddingRight = $this->visualPaddingValue($node, 'right');
        $paddingTop = $this->visualPaddingValue($node, 'top');
        $paddingBottom = $this->visualPaddingValue($node, 'bottom');
        $childX = $paddingLeft;
        $childY = $paddingTop;
        $gap = isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ? (float) $layout['item_spacing'] : 0.0;
        $justifyContent = (string) ($layout['justify_content'] ?? '');
        if ( in_array($justifyContent, array('space-between', 'space-around', 'space-evenly'), true) ) {
            $gap = 0.0;
        }
        $isRow = 'row' === ($layout['flex_direction'] ?? null);
        $isFlex = in_array($layout['display'] ?? null, array('flex', 'inline-flex'), true);
        $contentWidth = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width'] - $paddingLeft - $paddingRight) : null;
        $contentHeight = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height'] - $paddingTop - $paddingBottom) : null;
        $mainAxis = $isRow ? 'width' : 'height';
        $crossAxis = $isRow ? 'height' : 'width';
        $contentMainSize = $isRow ? $contentWidth : $contentHeight;
        $contentCrossSize = $isRow ? $contentHeight : $contentWidth;
        $flowChildren = array();

        foreach ( $children as $child ) {
            if ( ! is_array($child) || $this->isFullyClippedDecorativeChild($child, $node) ) {
                continue;
            }
            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( $this->isFreeformContainer($node) || 'absolute' === ($childLayout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }
            $flowChildren[] = $child;
        }

        $counterAxisGap = isset($layout['counter_axis_spacing']) && is_numeric($layout['counter_axis_spacing']) && is_finite((float) $layout['counter_axis_spacing'])
            ? max(0.0, (float) $layout['counter_axis_spacing'])
            : $gap;
        $flowPositions = $this->visualFlexChildPositions($flowChildren, $layout, $isFlex, $isRow, $mainAxis, $crossAxis, $contentMainSize, $contentCrossSize, $gap, $counterAxisGap);
        $reservedSourceGap = 0.0;

        foreach ( $children as $child ) {
            if ( ! is_array($child) || $this->isFullyClippedDecorativeChild($child, $node) ) {
                continue;
            }
            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( $this->isFreeformContainer($node) || 'absolute' === ($childLayout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                $this->appendVisualNodeMap($child, $map, 0.0, 0.0, $node, $childClipRect, $nodeTransform ?? $parentTransform);
                continue;
            }

            $nodeId = (string) ($child['id'] ?? '');
            $position = '' !== $nodeId && isset($flowPositions[$nodeId]) ? $flowPositions[$nodeId] : array('main' => 0.0, 'cross' => 0.0);
            $sourceGap = $this->sourceGeometryFlexGapResolver->resolve($child, $node);
            if ( null !== $sourceGap ) {
                $reservedSourceGap += $sourceGap['value'];
            }
            $position['main'] += $reservedSourceGap;
            if ( $isRow ) {
                $this->appendVisualNodeMap($child, $map, $childX + $position['main'], $childY + $position['cross'], $node, $childClipRect, $nodeTransform ?? $parentTransform);
            } else {
                $this->appendVisualNodeMap($child, $map, $childX + $position['cross'], $childY + $position['main'], $node, $childClipRect, $nodeTransform ?? $parentTransform);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emittedMetadataForNode(string $nodeId): array
    {
        if ( '' === $nodeId || ! isset($this->emittedNodeMetadata[$nodeId]) || ! is_array($this->emittedNodeMetadata[$nodeId]) ) {
            return array();
        }

        return $this->emittedNodeMetadata[$nodeId];
    }

    private function visualFlexChildPositions(array $children, array $layout, bool $isFlex, bool $isRow, string $mainAxis, string $crossAxis, ?float $contentMainSize, ?float $contentCrossSize, float $mainAxisGap, float $counterAxisGap): array
    {
        $positions = array();
        if ( empty($children) ) {
            return $positions;
        }

        $wrap = $isFlex && null !== $contentMainSize && 'wrap' === ($layout['flex_wrap'] ?? null);
        $lines = array();
        $currentLine = array('children' => array(), 'main_size' => 0.0, 'cross_size' => 0.0);
        foreach ( $children as $child ) {
            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $childMainSize = isset($childBox[$mainAxis]) && is_numeric($childBox[$mainAxis]) ? (float) $childBox[$mainAxis] : 0.0;
            $childCrossSize = isset($childBox[$crossAxis]) && is_numeric($childBox[$crossAxis]) ? (float) $childBox[$crossAxis] : 0.0;
            $lineChildCount = count($currentLine['children']);
            $candidateMainSize = (float) $currentLine['main_size'] + ($lineChildCount > 0 ? $mainAxisGap : 0.0) + $childMainSize;
            if ( $wrap && $lineChildCount > 0 && $candidateMainSize > $contentMainSize + 0.001 ) {
                $lines[] = $currentLine;
                $currentLine = array('children' => array(), 'main_size' => 0.0, 'cross_size' => 0.0);
                $lineChildCount = 0;
                $candidateMainSize = $childMainSize;
            }

            $currentLine['children'][] = array('node' => $child, 'main_size' => $childMainSize, 'cross_size' => $childCrossSize);
            $currentLine['main_size'] = $candidateMainSize;
            $currentLine['cross_size'] = max((float) $currentLine['cross_size'], $childCrossSize);
        }
        if ( ! empty($currentLine['children']) ) {
            $lines[] = $currentLine;
        }

        $lineCrossOffset = 0.0;
        foreach ( $lines as $line ) {
            $lineChildren = is_array($line['children'] ?? null) ? $line['children'] : array();
            $lineMainSize = (float) ($line['main_size'] ?? 0.0);
            $lineCrossSize = (float) ($line['cross_size'] ?? 0.0);
            $cursorMain = 0.0;
            $visualGap = $mainAxisGap;
            if ( $isFlex && null !== $contentMainSize ) {
                $freeMainSpace = $contentMainSize - $lineMainSize;
                $distributedMainSpace = max(0.0, $freeMainSpace);
                $justifyContent = (string) ($layout['justify_content'] ?? 'flex-start');
                if ( 'flex-end' === $justifyContent ) {
                    $cursorMain = $freeMainSpace;
                } elseif ( 'center' === $justifyContent ) {
                    $cursorMain = $freeMainSpace / 2.0;
                } elseif ( 'space-between' === $justifyContent && count($lineChildren) > 1 ) {
                    $visualGap += $distributedMainSpace / (count($lineChildren) - 1);
                } elseif ( 'space-around' === $justifyContent ) {
                    $visualGap += $distributedMainSpace / count($lineChildren);
                    $cursorMain = $visualGap / 2.0;
                } elseif ( 'space-evenly' === $justifyContent ) {
                    $visualGap += $distributedMainSpace / (count($lineChildren) + 1);
                    $cursorMain = $visualGap;
                }
            }

            foreach ( $lineChildren as $lineChild ) {
                $child = is_array($lineChild['node'] ?? null) ? $lineChild['node'] : array();
                $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
                $childMainSize = (float) ($lineChild['main_size'] ?? 0.0);
                $childCrossSize = (float) ($lineChild['cross_size'] ?? 0.0);
                $nodeId = (string) ($child['id'] ?? '');
                if ( '' !== $nodeId ) {
                    $positions[$nodeId] = array(
                        'main' => $cursorMain,
                        'cross' => $lineCrossOffset + $this->visualFlexCrossAxisOffset($layout, $childLayout, $wrap ? $lineCrossSize : $contentCrossSize, $childCrossSize),
                    );
                }
                $cursorMain += $childMainSize + $visualGap;
            }

            $lineCrossOffset += $lineCrossSize + ($wrap ? $counterAxisGap : 0.0);
        }

        return $positions;
    }

    /**
     * Component-source clone descendants retain source-local coordinates unless
     * the source supplies a page-space transform we can prove and apply.
     */
    private function visualGeometryConfidence(array $node, array $box): ?string
    {
        if ( true !== ($node['_component_source_clone_geometry'] ?? false)
            || 'local' !== ($box['coordinate_space'] ?? null) ) {
            return null;
        }

        foreach ( array($box, is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array()) as $geometry ) {
            $sourceKind = $geometry['component_clone_source_kind'] ?? $geometry['_geometry_provenance'] ?? null;
            if ( in_array($sourceKind, array('transform', 'absolute_transform', 'override_transform'), true) ) {
                return null;
            }
        }

        return 'unresolved_component_local';
    }

    private function visualFlexCrossAxisOffset(array $layout, array $childLayout, ?float $contentCrossSize, float $childCrossSize): float
    {
        if ( null === $contentCrossSize || ! in_array($layout['display'] ?? null, array('flex', 'inline-flex'), true) ) {
            return 0.0;
        }
        $alignment = (string) ($layout['align_items'] ?? 'flex-start');
        if ( isset($childLayout['align']) && is_scalar($childLayout['align']) ) {
            $alignment = match ( strtoupper((string) $childLayout['align']) ) {
                'CENTER' => 'center',
                'MAX' => 'flex-end',
                'STRETCH' => 'stretch',
                default => $alignment,
            };
        }
        $freeCrossSpace = $contentCrossSize - $childCrossSize;
        return match ( $alignment ) {
            'center' => $freeCrossSpace / 2.0,
            'flex-end' => $freeCrossSpace,
            default => 0.0,
        };
    }

    private function visualPaddingValue(array $node, string $edge): float
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $value = isset($padding[$edge]) && is_numeric($padding[$edge]) ? (float) $padding[$edge] : 0.0;
        $axis = in_array($edge, array('left', 'right'), true) ? 'horizontal' : 'vertical';
        $dimension = 'horizontal' === $axis ? 'width' : 'height';
        $sizingKey = 'horizontal' === $axis ? 'sizing_horizontal' : 'sizing_vertical';
        if ( in_array(strtoupper((string) ($layout[$sizingKey] ?? '')), array('HUG', 'FILL'), true) ) {
            return $value;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return $value;
        }

        $start = 'horizontal' === $axis ? 'left' : 'top';
        $end = 'horizontal' === $axis ? 'right' : 'bottom';
        $startValue = isset($padding[$start]) && is_numeric($padding[$start]) ? (float) $padding[$start] : 0.0;
        $endValue = isset($padding[$end]) && is_numeric($padding[$end]) ? (float) $padding[$end] : 0.0;
        $sum = $startValue + $endValue;
        $available = max(0.0, (float) $box[$dimension]);
        if ( $sum <= 0.0 || $sum <= $available ) {
            return $value;
        }

        return $value * ($available / $sum);
    }

    private function isClippableDecorativeVisualNode(array $node): bool
    {
        return $this->layoutIntentClassifier->isClippableDecorativeVisualNode($node);
    }

    private function isFullyClippedDecorativeChild(array $node, array $parentNode): bool
    {
        return $this->visualGeometryResolver->isFullyClippedDecorativeChild($node, $parentNode);
    }

    private function normalFlexFlowChild(array $node, array $parentNode): bool
    {
        return false !== ($node['visible'] ?? null)
            && ! $this->isFullyClippedDecorativeChild($node, $parentNode)
            && ! $this->isDecorativeFlexUnderlay($node, $parentNode);
    }

    private function isFullyTransparentVisualNode(array $node): bool
    {
        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $opacity = $box['opacity'] ?? $node['opacity'] ?? null;
        return is_numeric($opacity) && 0.001 >= (float) $opacity;
    }

    private function rectIntersection(array $rect, array $clipRect): ?array
    {
        return $this->visualGeometryResolver->rectIntersection($rect, $clipRect);
    }

    private function transformedVisualRect(float $width, float $height, array $matrix): array
    {
        return $this->visualGeometryResolver->transformedRect($width, $height, $matrix);
    }

    private function visualTransformMatrix(array $parentTransform, float $x, float $y, float $width, float $height, array $node): array
    {
        return $this->multiplyVisualTransformMatrices(
            $parentTransform,
            $this->multiplyVisualTransformMatrices($this->translationVisualTransformMatrix($x, $y), $this->nodeCssVisualTransformMatrix($node, $width, $height))
        );
    }

    private function nodeCssVisualTransformMatrix(array $node, float $width, float $height): array
    {
        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $matrix = $this->cssTransformMatrixValues(is_array($box['transform'] ?? null) ? $box['transform'] : null);
        if ( null !== $matrix ) {
            return $matrix;
        }
        if ( isset($box['rotation']) && is_numeric($box['rotation']) ) {
            $radians = deg2rad((float) $box['rotation']);
            $rotation = array(cos($radians), sin($radians), -sin($radians), cos($radians), 0.0, 0.0);
            $originX = $width / 2.0;
            $originY = $height / 2.0;
            return $this->multiplyVisualTransformMatrices(
                $this->translationVisualTransformMatrix($originX, $originY),
                $this->multiplyVisualTransformMatrices($rotation, $this->translationVisualTransformMatrix(-$originX, -$originY))
            );
        }
        return $this->identityVisualTransformMatrix();
    }

    private function identityVisualTransformMatrix(): array
    {
        return array(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    private function translationVisualTransformMatrix(float $x, float $y): array
    {
        return array(1.0, 0.0, 0.0, 1.0, $x, $y);
    }

    private function multiplyVisualTransformMatrices(array $left, array $right): array
    {
        return array(
            $left[0] * $right[0] + $left[2] * $right[1],
            $left[1] * $right[0] + $left[3] * $right[1],
            $left[0] * $right[2] + $left[2] * $right[3],
            $left[1] * $right[2] + $left[3] * $right[3],
            $left[0] * $right[4] + $left[2] * $right[5] + $left[4],
            $left[1] * $right[4] + $left[3] * $right[5] + $left[5],
        );
    }

    private function isFreeformContainer(array $node): bool
    {
        return $this->layoutIntentClassifier->isFreeformContainer($node);
    }

    private function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        return $this->layoutIntentClassifier->positionOffset($box, $parentBox, $dimension, $parentNode);
    }

    private function relativeOffset(array $box, array $parentBox, string $dimension): ?float
    {
        return $this->layoutIntentClassifier->relativeOffset($box, $parentBox, $dimension);
    }

    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        return $this->layoutIntentClassifier->isDecorativeFlexUnderlay($node, $parentNode);
    }

    private function firstImagePaint(array $node): ?array
    {
        return VisualLayerEvidence::firstImagePaint($node);
    }

    private function visualImageMetadata(array $paint): array
    {
        $transform = $this->imagePaintTransformMatrix($paint);
        $metadata = array(
            'scale_mode' => strtoupper((string) ($paint['imageScaleMode'] ?? $paint['scaleMode'] ?? 'FILL')),
            'has_transform' => null !== $transform && ! $this->isIdentityImageTransform($transform),
            'has_crop_rect' => is_array($paint['cropRect'] ?? null),
            'color_managed' => true === ($paint['imageShouldColorManage'] ?? false),
        );
        if ( is_array($paint['cropRect'] ?? null) ) {
            $metadata['crop_rect'] = $paint['cropRect'];
        }
        foreach ( array('ref', 'imageHash', 'imageName', 'originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'thumbHash', 'animationFrame') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                $metadata[$key] = $paint[$key];
            }
        }
        return $metadata;
    }

    private function visualTextMetadata(array $text): array
    {
        $metadata = array(
            'character_count' => isset($text['characters']) && is_scalar($text['characters']) ? strlen((string) $text['characters']) : 0,
            'segment_count' => is_array($text['segments'] ?? null) ? count($text['segments']) : 0,
        );
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        if ( ! empty($derivedLayout) ) {
            $metadata['derived_layout'] = $derivedLayout;
            $metadata['has_derived_layout'] = true;
            $metadata['baseline_count'] = $derivedLayout['baseline_count'] ?? 0;
            $metadata['glyph_count'] = $derivedLayout['glyph_count'] ?? 0;
            $metadata['glyph_path_count'] = is_array($derivedLayout['glyph_paths'] ?? null) ? count($derivedLayout['glyph_paths']) : 0;
            $characters = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : '';
            $metadata['glyph_rendering'] = $this->renderTextGlyphPaths && ! empty($derivedLayout['glyph_paths']) && $this->textAllowsGlyphRendering($characters, $text) ? 'svg_paths' : 'dom_text';
        } else {
            $metadata['has_derived_layout'] = false;
            $metadata['glyph_rendering'] = 'dom_text';
        }
        return $metadata;
    }

    private function nodeAssetPath(array $node): ?string
    {
        foreach ( $this->nodeAssetReferences($node) as $assetId ) {
            $path = $this->resolveAssetPath($assetId);
            if ( null !== $path ) {
                return $path;
            }
        }
        return null;
    }

    private function nodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref', 'id', 'name') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }
        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            foreach ( is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array() as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }
                $references = array_merge($references, $this->paintAssetReferences($paint));
            }
        }
        return array_values(array_unique(array_filter($references, static fn (string $reference): bool => '' !== $reference)));
    }

    private function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            $references = array_merge($references, $this->imageAssetReferences($node['image']));
        }
        return array_values(array_unique($references));
    }

    private function resolveAssetPath(string $assetId): ?string
    {
        if ( isset($this->assetsById[$assetId]) ) {
            return (string) $this->assetsById[$assetId]['path'];
        }

        $slugged = $this->slug($assetId);
        return isset($this->assetsById[$slugged]) ? (string) $this->assetsById[$slugged]['path'] : null;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<int, string>
     */
    private function paintAssetReferences(array $paint): array
    {
        $references = array();
        foreach ( array('imageRef', 'imageHash', 'ref', 'asset_id', 'assetId', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                $references[] = (string) $paint[$key];
            }
        }
        if ( is_array($paint['assetRef'] ?? null) ) {
            $references = array_merge($references, $this->assetRefReferences($paint['assetRef']));
        }
        foreach ( array('image', 'thumbnail', 'imageThumbnail', 'sourceImage') as $imageKey ) {
            if ( is_array($paint[$imageKey] ?? null) ) {
                $references = array_merge($references, $this->imageAssetReferences($paint[$imageKey]));
            }
        }
        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $image
     * @return array<int, string>
     */
    private function imageAssetReferences(array $image): array
    {
        $references = array();
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename') as $key ) {
            if ( isset($image[$key]) && is_scalar($image[$key]) && '' !== (string) $image[$key] ) {
                $references[] = (string) $image[$key];
            }
        }
        if ( is_array($image['assetRef'] ?? null) ) {
            $references = array_merge($references, $this->assetRefReferences($image['assetRef']));
        }
        if ( is_array($image['sourceImage'] ?? null) ) {
            $references = array_merge($references, $this->imageAssetReferences($image['sourceImage']));
        }
        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $assetRef
     * @return array<int, string>
     */
    private function assetRefReferences(array $assetRef): array
    {
        $references = array();
        foreach ( array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($assetRef[$key]) && is_scalar($assetRef[$key]) && '' !== (string) $assetRef[$key] ) {
                $references[] = (string) $assetRef[$key];
            }
        }
        if ( is_array($assetRef['guid'] ?? null) && isset($assetRef['guid']['sessionID'], $assetRef['guid']['localID']) ) {
            $references[] = (string) $assetRef['guid']['sessionID'] . ':' . (string) $assetRef['guid']['localID'];
        } elseif ( isset($assetRef['guid']) && is_scalar($assetRef['guid']) && '' !== (string) $assetRef['guid'] ) {
            $references[] = (string) $assetRef['guid'];
        }
        return array_values(array_unique($references));
    }

    private function nodeImagePaints(array $node): array
    {
        return VisualLayerEvidence::imagePaints($node);
    }

    private function imagePaintTransformMatrix(array $paint): ?array
    {
        $transform = $paint['transform'] ?? null;
        if ( ! is_array($transform) ) {
            return null;
        }
        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            $values = array('m00' => $transform['m00'], 'm01' => $transform['m01'], 'm02' => $transform['m02'], 'm10' => $transform['m10'], 'm11' => $transform['m11'], 'm12' => $transform['m12']);
        } elseif ( is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array('m00' => $transform[0][0] ?? null, 'm01' => $transform[0][1] ?? null, 'm02' => $transform[0][2] ?? null, 'm10' => $transform[1][0] ?? null, 'm11' => $transform[1][1] ?? null, 'm12' => $transform[1][2] ?? null);
        } else {
            return null;
        }
        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }
        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    private function isIdentityImageTransform(array $transform): bool
    {
        return 0.00001 > abs((float) $transform['m00'] - 1.0)
            && 0.00001 > abs((float) $transform['m01'])
            && 0.00001 > abs((float) $transform['m02'])
            && 0.00001 > abs((float) $transform['m10'])
            && 0.00001 > abs((float) $transform['m11'] - 1.0)
            && 0.00001 > abs((float) $transform['m12']);
    }

    private function textContent(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            $characters = (string) $text['characters'];
            if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
                return '';
            }
            return htmlspecialchars($characters, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $characters = (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return '';
        }
        return htmlspecialchars($characters, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isUnresolvedComponentPlaceholderText(array $node, string $characters): bool
    {
        $placeholder = strtolower(trim($characters));
        if ( ! in_array($placeholder, array('button label', 'label'), true) ) {
            return false;
        }
        $id = (string) ($node['id'] ?? '');
        return str_contains($id, '/') || isset($node['figma_component_source_id']);
    }

    private function textAllowsGlyphRendering(string $characters, array $text): bool
    {
        if ( $this->textNeedsDomSymbolFallback($characters) ) {
            return false;
        }
        if ( mb_strlen($characters) > 80 ) {
            return false;
        }
        if ( mb_strlen($characters) > 45 && 1 === preg_match('/[.!?。！？]$/u', trim($characters)) ) {
            return false;
        }
        if ( str_contains($characters, "\n") && ! $this->textLooksLikeDisplayText($text) ) {
            return false;
        }
        if ( ! empty($text['segments'] ?? array()) ) {
            return false;
        }
        return true;
    }

    private function textNeedsDomSymbolFallback(string $characters): bool
    {
        return 1 === preg_match('/[\\x{2190}-\\x{21FF}\\x{2600}-\\x{27BF}]/u', $characters);
    }

    private function textLooksLikeDisplayText(array $text): bool
    {
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        return isset($style['font_size']) && is_numeric($style['font_size']) && (float) $style['font_size'] >= 32.0;
    }

    private function cssTransformMatrixValues(?array $transform): ?array
    {
        return $this->visualGeometryResolver->cssTransformMatrixValues($transform);
    }

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
}
