<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Shared evidence helpers for deciding how visual layers should render.
 */
final class VisualLayerEvidence
{
    /** @var array<int, string> */
    private const PAINT_COLLECTION_KEYS = array('fills', 'strokes', 'background');

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    public static function imagePaints(array $node): array
    {
        $imagePaints = array();
        foreach ( self::PAINT_COLLECTION_KEYS as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                foreach ( $paints as $paint ) {
                    if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $imagePaints[] = $paint;
                    }
                }
            }
        }

        return $imagePaints;
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function firstImagePaint(array $node): ?array
    {
        foreach ( self::imagePaints($node) as $paint ) {
            return $paint;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function hasImagePaint(array $node): bool
    {
        return null !== self::firstImagePaint($node);
    }
}
