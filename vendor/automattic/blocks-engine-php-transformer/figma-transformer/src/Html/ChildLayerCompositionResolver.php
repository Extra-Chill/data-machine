<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

use Closure;

/**
 * Resolves sibling-layer compositions that should be expressed on a target child
 * instead of emitted as separate visible DOM layers.
 */
final class ChildLayerCompositionResolver
{
    /** @var Closure(array<string, mixed>): ?string */
    private Closure $nodeAssetPath;

    /** @var Closure(float): string */
    private Closure $number;

    /**
     * @param Closure(array<string, mixed>): ?string $nodeAssetPath
     * @param Closure(float): string $number
     */
    public function __construct(Closure $nodeAssetPath, Closure $number)
    {
        $this->nodeAssetPath = $nodeAssetPath;
        $this->number = $number;
    }

    /**
     * @param array<int, mixed> $children
     * @return array{clip_paths: array<string, string>, image_mask_paths: array<string, string>, suppressed_child_ids: array<string, string>}
     */
    public function resolveChildMaps(array $children): array
    {
        $imageMaskComposition = $this->imageMaskComposition($children);

        return array(
            'clip_paths' => $this->simpleMaskClipPathsByTargetId($children),
            'image_mask_paths' => $imageMaskComposition['mask_paths'],
            'suppressed_child_ids' => array_merge(
                $imageMaskComposition['source_ids'],
                $this->samePathVectorStateDuplicateSuppressedChildIds($children)
            ),
        );
    }

    /**
     * @param array<string, mixed> $child
     * @param array{clip_paths: array<string, string>, image_mask_paths: array<string, string>, suppressed_child_ids: array<string, string>} $compositionMaps
     * @return array<string, mixed>
     */
    public function applyToChild(array $child, string $childId, array $compositionMaps): array
    {
        if ( '' !== $childId && isset($compositionMaps['clip_paths'][$childId]) ) {
            $child['_figma_css_clip_path'] = $compositionMaps['clip_paths'][$childId];
        }
        if ( '' !== $childId && isset($compositionMaps['image_mask_paths'][$childId]) ) {
            $child['_figma_css_mask_image_path'] = $compositionMaps['image_mask_paths'][$childId];
        }

        return $child;
    }

    /** @param array<string, mixed> $node */
    public function isMaskOperatorNode(array $node): bool
    {
        $mask = is_array($node['figma_mask'] ?? null) ? $node['figma_mask'] : array();

        return true === ($mask['is_mask'] ?? null) || true === ($node['isMask'] ?? null) || true === ($node['mask'] ?? null);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<string, string>
     */
    private function simpleMaskClipPathsByTargetId(array $children): array
    {
        $nodes = array_values(array_filter($children, 'is_array'));
        $clipPaths = array();
        foreach ( $nodes as $maskNode ) {
            if ( ! $this->isMaskOperatorNode($maskNode) ) {
                continue;
            }
            $targetNode = $this->simpleMaskTargetNode($maskNode, $nodes);
            if ( null === $targetNode ) {
                continue;
            }
            $targetId = isset($targetNode['id']) && is_scalar($targetNode['id']) ? (string) $targetNode['id'] : '';
            $clipPath = $this->simpleMaskClipPath($maskNode, $targetNode);
            if ( '' !== $targetId && null !== $clipPath ) {
                $clipPaths[$targetId] = $clipPath;
            }
        }

        return $clipPaths;
    }

    /**
     * @param array<int, mixed> $children
     * @return array{mask_paths: array<string, string>, source_ids: array<string, string>}
     */
    private function imageMaskComposition(array $children): array
    {
        $nodes = array_values(array_filter($children, 'is_array'));
        $maskPaths = array();
        $sourceIds = array();
        foreach ( $nodes as $node ) {
            if ( $this->isMaskOperatorNode($node) ) {
                continue;
            }

            $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' === $nodeId || null !== ($this->nodeAssetPath)($node) || ! $this->hasVisibleSolidFill($node) ) {
                continue;
            }

            foreach ( $nodes as $candidate ) {
                if ( $candidate === $node || $this->isMaskOperatorNode($candidate) ) {
                    continue;
                }

                $assetPath = ($this->nodeAssetPath)($candidate);
                if ( null !== $assetPath && $this->isSameBoxNode($node, $candidate) && $this->isIconRecolorMaskComposition($node, $candidate, $assetPath) ) {
                    $maskPaths[$nodeId] = $assetPath;
                    $sourceId = isset($candidate['id']) && is_scalar($candidate['id']) ? (string) $candidate['id'] : '';
                    if ( '' !== $sourceId ) {
                        $sourceIds[$sourceId] = 'image_mask_alpha_source_suppressed';
                    }
                    break;
                }
            }
        }

        return array('mask_paths' => $maskPaths, 'source_ids' => $sourceIds);
    }

    /**
     * @param array<string, mixed> $solidOverlay
     * @param array<string, mixed> $assetSource
     */
    private function isIconRecolorMaskComposition(array $solidOverlay, array $assetSource, string $assetPath): bool
    {
        $box = is_array($solidOverlay['box'] ?? null) ? $solidOverlay['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        if ( null === $width || null === $height || $width > 128.0 || $height > 128.0 ) {
            return false;
        }

        $name = strtolower((string) ($solidOverlay['name'] ?? '') . ' ' . (string) ($assetSource['name'] ?? '') . ' ' . basename($assetPath));
        return 1 === preg_match('/\b(icon|social|facebook|instagram|linkedin|twitter|youtube|tiktok|pinterest|logo|mask)\b/', $name)
            || 'svg' === strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
    }

    /**
     * @param array<int, mixed> $children
     * @return array<string, string>
     */
    private function samePathVectorStateDuplicateSuppressedChildIds(array $children): array
    {
        $groups = array();
        foreach ( array_values(array_filter($children, 'is_array')) as $child ) {
            if ( false === ($child['visible'] ?? true) || $this->isMaskOperatorNode($child) ) {
                continue;
            }

            $id = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
            $candidate = $this->samePathVectorStateCandidate($child);
            if ( '' === $id || null === $candidate ) {
                continue;
            }

            $candidate['id'] = $id;
            $groups[$candidate['key']][] = $candidate;
        }

        $suppressed = array();
        foreach ( $groups as $candidates ) {
            if ( count($candidates) < 2 ) {
                continue;
            }

            $emitted = array();
            foreach ( $candidates as $candidate ) {
                $duplicate = false;
                foreach ( $emitted as $kept ) {
                    if ( $this->isNearSameVectorStateBox($candidate['box'], $kept['box']) ) {
                        $duplicate = true;
                        break;
                    }
                }

                if ( $duplicate ) {
                    $suppressed[$candidate['id']] = 'same_path_vector_state_duplicate_suppressed';
                } else {
                    $emitted[] = $candidate;
                }
            }
        }

        return $suppressed;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{key: string, box: array{x: float, y: float, width: float, height: float}}|null
     */
    private function samePathVectorStateCandidate(array $node): ?array
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $pathSignature = $this->vectorPathSignature($node);
        if ( ! $this->isUnsupportedVectorType($type) ) {
            $pathSignature = $this->wrappedSingleVectorPathSignature($node, 0);
        } elseif ( ! $this->hasVisiblePaintCollection($node) ) {
            return null;
        }
        if ( null === $pathSignature ) {
            return null;
        }

        $box = $this->vectorStateBox($node);
        if ( null === $box || $box['width'] > 128.0 || $box['height'] > 128.0 ) {
            return null;
        }

        return array(
            'key' => $type . '|width:' . ($this->number)(round($box['width'], 1)) . '|height:' . ($this->number)(round($box['height'], 1)) . '|' . $pathSignature,
            'box' => $box,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function vectorStateBox(array $node): ?array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $resolved = array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            $value = isset($box[$key]) && is_numeric($box[$key]) ? (float) $box[$key] : (in_array($key, array('x', 'y'), true) ? 0.0 : null);
            if ( null === $value ) {
                return null;
            }
            $resolved[$key] = $value;
        }

        return $resolved;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $box
     * @param array{x: float, y: float, width: float, height: float} $candidateBox
     */
    private function isNearSameVectorStateBox(array $box, array $candidateBox): bool
    {
        return abs($box['x'] - $candidateBox['x']) <= 1.5
            && abs($box['y'] - $candidateBox['y']) <= 1.5
            && abs($box['width'] - $candidateBox['width']) <= 0.5
            && abs($box['height'] - $candidateBox['height']) <= 0.5;
    }

    /** @param array<string, mixed> $node */
    private function wrappedSingleVectorPathSignature(array $node, int $depth): ?string
    {
        if ( $depth > 3 ) {
            return null;
        }

        $children = array_values(array_filter(is_array($node['children'] ?? null) ? $node['children'] : array(), 'is_array'));
        if ( 1 !== count($children) ) {
            return null;
        }

        $child = $children[0];
        $childType = strtoupper((string) ($child['type'] ?? ''));
        if ( $this->isUnsupportedVectorType($childType) && $this->hasVisiblePaintCollection($child) ) {
            return $this->vectorPathSignature($child);
        }

        return $this->wrappedSingleVectorPathSignature($child, $depth + 1);
    }

    /** @param array<string, mixed> $node */
    private function vectorPathSignature(array $node): ?string
    {
        $paths = array();
        foreach ( array('pathData', 'path_data', 'd') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== trim((string) $node[$key]) ) {
                $paths[] = trim((string) $node[$key]);
            }
        }

        foreach ( array('fillGeometry', 'strokeGeometry', 'figma_vector_paths') as $key ) {
            if ( ! is_array($node[$key] ?? null) ) {
                continue;
            }
            foreach ( $node[$key] as $entry ) {
                $path = is_array($entry) ? ($entry['data'] ?? $entry['pathData'] ?? $entry['path'] ?? $entry['d'] ?? null) : $entry;
                if ( is_scalar($path) && '' !== trim((string) $path) ) {
                    $paths[] = trim((string) $path);
                }
            }
        }

        if ( empty($paths) ) {
            return null;
        }

        return hash('sha256', implode('|', array_values(array_unique($paths))));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $candidate
     */
    private function isSameBoxNode(array $node, array $candidate): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $candidateBox = is_array($candidate['box'] ?? null) ? $candidate['box'] : array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            $value = isset($box[$key]) && is_numeric($box[$key]) ? (float) $box[$key] : ( in_array($key, array('x', 'y'), true) ? 0.0 : null );
            $candidateValue = isset($candidateBox[$key]) && is_numeric($candidateBox[$key]) ? (float) $candidateBox[$key] : ( in_array($key, array('x', 'y'), true) ? 0.0 : null );
            if ( null === $value || null === $candidateValue || abs($value - $candidateValue) > 0.5 ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    private function hasVisibleSolidFill(array $node): bool
    {
        $paintCollections = array();
        if ( is_array($node['figma_paints']['fills'] ?? null) ) {
            $paintCollections[] = $node['figma_paints']['fills'];
        }
        foreach ( array('fillPaints', 'paints') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $paintCollections[] = $node[$key];
            }
        }

        foreach ( $paintCollections as $fills ) {
            foreach ( $fills as $fill ) {
                if ( ! is_array($fill) || 'SOLID' !== strtoupper((string) ($fill['type'] ?? '')) ) {
                    continue;
                }
                if ( false === ($fill['visible'] ?? null) || (isset($fill['opacity']) && is_numeric($fill['opacity']) && (float) $fill['opacity'] <= 0.0) ) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $maskNode
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private function simpleMaskTargetNode(array $maskNode, array $nodes): ?array
    {
        if ( ! isset($maskNode['_source_order']) || ! is_numeric($maskNode['_source_order']) ) {
            return null;
        }

        $maskOrder = (int) $maskNode['_source_order'];
        $targetNode = null;
        $targetOrder = PHP_INT_MAX;
        foreach ( $nodes as $node ) {
            if ( $this->isMaskOperatorNode($node) || ! isset($node['_source_order']) || ! is_numeric($node['_source_order']) ) {
                continue;
            }
            $nodeOrder = (int) $node['_source_order'];
            if ( $nodeOrder > $maskOrder && $nodeOrder < $targetOrder ) {
                $targetNode = $node;
                $targetOrder = $nodeOrder;
            }
        }

        return $targetNode;
    }

    /**
     * @param array<string, mixed> $maskNode
     * @param array<string, mixed> $targetNode
     */
    private function simpleMaskClipPath(array $maskNode, array $targetNode): ?string
    {
        $maskType = strtoupper((string) ($maskNode['type'] ?? ''));
        if ( ! in_array($maskType, array('RECTANGLE', 'FRAME', 'ELLIPSE'), true) ) {
            return null;
        }

        $maskBox = is_array($maskNode['box'] ?? null) ? $maskNode['box'] : array();
        $targetBox = is_array($targetNode['box'] ?? null) ? $targetNode['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($maskBox[$dimension], $targetBox[$dimension]) || ! is_numeric($maskBox[$dimension]) || ! is_numeric($targetBox[$dimension]) || 0.0 >= (float) $targetBox[$dimension] ) {
                return null;
            }
        }

        $maskLeft = isset($maskBox['x']) && is_numeric($maskBox['x']) ? (float) $maskBox['x'] : 0.0;
        $maskTop = isset($maskBox['y']) && is_numeric($maskBox['y']) ? (float) $maskBox['y'] : 0.0;
        $targetLeft = isset($targetBox['x']) && is_numeric($targetBox['x']) ? (float) $targetBox['x'] : 0.0;
        $targetTop = isset($targetBox['y']) && is_numeric($targetBox['y']) ? (float) $targetBox['y'] : 0.0;
        $maskWidth = (float) $maskBox['width'];
        $maskHeight = (float) $maskBox['height'];
        $targetWidth = (float) $targetBox['width'];
        $targetHeight = (float) $targetBox['height'];
        $relativeLeft = $maskLeft - $targetLeft;
        $relativeTop = $maskTop - $targetTop;

        if ( 'ELLIPSE' === $maskType ) {
            return 'ellipse(' . ($this->number)($maskWidth / 2.0) . 'px ' . ($this->number)($maskHeight / 2.0) . 'px at ' . ($this->number)($relativeLeft + ($maskWidth / 2.0)) . 'px ' . ($this->number)($relativeTop + ($maskHeight / 2.0)) . 'px)';
        }

        $clip = 'inset('
            . ($this->number)($relativeTop) . 'px '
            . ($this->number)($targetWidth - ($relativeLeft + $maskWidth)) . 'px '
            . ($this->number)($targetHeight - ($relativeTop + $maskHeight)) . 'px '
            . ($this->number)($relativeLeft) . 'px';
        $radius = $this->simpleMaskRadius($maskNode);
        if ( null !== $radius && $radius > 0.0 ) {
            $clip .= ' round ' . ($this->number)($radius) . 'px';
        }

        return $clip . ')';
    }

    /** @param array<string, mixed> $maskNode */
    private function simpleMaskRadius(array $maskNode): ?float
    {
        $box = is_array($maskNode['figma_box'] ?? null) ? $maskNode['figma_box'] : array();
        if ( isset($box['corner_radius']) && is_numeric($box['corner_radius']) ) {
            return (float) $box['corner_radius'];
        }
        if ( isset($maskNode['cornerRadius']) && is_numeric($maskNode['cornerRadius']) ) {
            return (float) $maskNode['cornerRadius'];
        }

        return null;
    }

    private function isUnsupportedVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /** @param array<string, mixed> $node */
    private function hasVisiblePaintCollection(array $node): bool
    {
        foreach ( array('fills', 'strokes', 'background') as $key ) {
            $paints = is_array($node['figma_paints'][$key] ?? null) ? $node['figma_paints'][$key] : array();
            foreach ( $paints as $paint ) {
                if ( is_array($paint) && false !== ($paint['visible'] ?? true) && ((isset($paint['opacity']) && is_numeric($paint['opacity'])) ? (float) $paint['opacity'] > 0.0 : true) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
