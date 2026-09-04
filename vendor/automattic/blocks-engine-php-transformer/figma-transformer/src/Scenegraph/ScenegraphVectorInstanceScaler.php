<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Applies instance-size scaling to vector-only component clone children.
 */
final class ScenegraphVectorInstanceScaler
{
    /**
     * @param array<int, mixed> $children
     * @param array<string, mixed> $component
     * @param array<string, mixed> $instance
     * @return array<int, mixed>
     */
    public function scaleVectorOnlyInstanceChildren(array $children, array $component, array $instance): array
    {
        if ( ! $this->isVectorOnlyComponent($component) ) {
            return $children;
        }

        $componentBox = is_array($component['box'] ?? null) ? $component['box'] : array();
        $instanceBox  = is_array($instance['box'] ?? null) ? $instance['box'] : array();
        $componentWidth = isset($componentBox['width']) && is_numeric($componentBox['width']) ? (float) $componentBox['width'] : 0.0;
        $componentHeight = isset($componentBox['height']) && is_numeric($componentBox['height']) ? (float) $componentBox['height'] : 0.0;
        $instanceWidth = isset($instanceBox['width']) && is_numeric($instanceBox['width']) ? (float) $instanceBox['width'] : 0.0;
        $instanceHeight = isset($instanceBox['height']) && is_numeric($instanceBox['height']) ? (float) $instanceBox['height'] : 0.0;
        if ( $componentWidth <= 0.0 || $componentHeight <= 0.0 || $instanceWidth <= 0.0 || $instanceHeight <= 0.0 ) {
            return $children;
        }

        $scaleX = $instanceWidth / $componentWidth;
        $scaleY = $instanceHeight / $componentHeight;
        if ( abs($scaleX - 1.0) < 0.0001 && abs($scaleY - 1.0) < 0.0001 ) {
            return $children;
        }

        return $this->scaleVectorChildren($children, $scaleX, $scaleY);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, mixed>
     */
    public function scaleVectorChildren(array $children, float $scaleX, float $scaleY): array
    {
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            foreach ( array('box', 'figma_box') as $boxKey ) {
                if ( ! is_array($child[$boxKey] ?? null) ) {
                    continue;
                }
                foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                    if ( isset($child[$boxKey][$key]) && is_numeric($child[$boxKey][$key]) ) {
                        $child[$boxKey][$key] = (float) $child[$boxKey][$key] * $scale;
                    }
                }
            }

            foreach ( array('x' => $scaleX, 'width' => $scaleX, 'y' => $scaleY, 'height' => $scaleY) as $key => $scale ) {
                if ( isset($child[$key]) && is_numeric($child[$key]) ) {
                    $child[$key] = (float) $child[$key] * $scale;
                }
            }

            $child['_component_source_clone_scale'] = array('x' => $scaleX, 'y' => $scaleY);

            if ( $this->isScalableVectorType(strtoupper((string) ($child['type'] ?? ''))) ) {
                $child['figma_vector_scale'] = array('x' => $scaleX, 'y' => $scaleY);
            }

            if ( is_array($child['children'] ?? null) ) {
                $child['children'] = $this->scaleVectorChildren($child['children'], $scaleX, $scaleY);
            }

            $children[$index] = $child;
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $component
     */
    private function isVectorOnlyComponent(array $component): bool
    {
        $children = is_array($component['children'] ?? null) ? $component['children'] : array();
        if ( empty($children) ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isVectorOnlyNode(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( 'INSTANCE' !== $type && ! $this->isScalableVectorType($type) ) {
            return false;
        }

        foreach ( is_array($node['children'] ?? null) ? $node['children'] : array() as $child ) {
            if ( ! is_array($child) || ! $this->isVectorOnlyNode($child) ) {
                return false;
            }
        }

        return true;
    }

    private function isScalableVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }
}
