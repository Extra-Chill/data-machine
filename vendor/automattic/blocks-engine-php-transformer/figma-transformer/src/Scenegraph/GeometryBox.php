<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Coordinate-space names for normalized scenegraph boxes.
 */
final class GeometryBox
{
    public const PROVENANCE_KEY = '_geometry_provenance';

    public const CLASSIFICATION_CANVAS_ABSOLUTE = 'canvas-absolute';
    public const CLASSIFICATION_PARENT_LOCAL = 'parent-local';
    public const CLASSIFICATION_PAGE_LOCAL = 'page-local';

    public const COORDINATE_SPACE_CANVAS_ABSOLUTE = 'absolute';
    public const COORDINATE_SPACE_PARENT_LOCAL = 'local';
    public const COORDINATE_SPACE_PAGE_LOCAL = self::COORDINATE_SPACE_PARENT_LOCAL;

    public const SOURCE_ABSOLUTE_BOUNDS = 'absolute_bounds';
    public const SOURCE_TRANSFORM = 'transform';
    public const SOURCE_ABSOLUTE_TRANSFORM = 'absolute_transform';
    public const SOURCE_EXPLICIT_LOCAL = 'explicit_local';
    public const SOURCE_SIZE_ONLY = 'size_only';
    public const SOURCE_OVERRIDE_TRANSFORM = 'override_transform';
    public const SOURCE_COMPONENT_CLONE = 'component_clone';

    /**
     * @param array<string, mixed> $box
     */
    public static function coordinateSpace(array $box): string
    {
        return isset($box['coordinate_space']) && is_scalar($box['coordinate_space'])
            ? (string) $box['coordinate_space']
            : self::COORDINATE_SPACE_CANVAS_ABSOLUTE;
    }

    public static function coordinateSpaceForClassification(string $classification): string
    {
        return self::CLASSIFICATION_CANVAS_ABSOLUTE === $classification
            ? self::COORDINATE_SPACE_CANVAS_ABSOLUTE
            : self::COORDINATE_SPACE_PARENT_LOCAL;
    }

    public static function classificationForSourceKind(string $sourceKind, bool $isPageLocal = false): ?string
    {
        return match ( $sourceKind ) {
            self::SOURCE_ABSOLUTE_BOUNDS,
            self::SOURCE_ABSOLUTE_TRANSFORM => self::CLASSIFICATION_CANVAS_ABSOLUTE,
            self::SOURCE_EXPLICIT_LOCAL,
            self::SOURCE_TRANSFORM,
            self::SOURCE_OVERRIDE_TRANSFORM,
            self::SOURCE_COMPONENT_CLONE => $isPageLocal ? self::CLASSIFICATION_PAGE_LOCAL : self::CLASSIFICATION_PARENT_LOCAL,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $box
     * @return array<string, mixed>
     */
    public static function withProvenance(array $box, string $sourceKind, bool $isPageLocal = false): array
    {
        $box[self::PROVENANCE_KEY] = $sourceKind;
        $classification = self::classificationForSourceKind($sourceKind, $isPageLocal);
        if ( null !== $classification ) {
            $box['coordinate_space'] = self::coordinateSpaceForClassification($classification);
        }

        return $box;
    }

    /**
     * @param array<string, mixed> $box
     */
    public static function sourceKind(array $box): ?string
    {
        return isset($box[self::PROVENANCE_KEY]) && is_scalar($box[self::PROVENANCE_KEY])
            ? (string) $box[self::PROVENANCE_KEY]
            : null;
    }

    /**
     * @param array<string, mixed> $box
     * @return array<string, mixed>
     */
    public static function withoutProvenance(array $box): array
    {
        unset($box[self::PROVENANCE_KEY]);
        return $box;
    }

    /**
     * @param array<string, mixed> $box
     */
    public static function classifyNormalizedBox(array $box, bool $isPageLocal = false): string
    {
        $sourceKind = self::sourceKind($box);
        if ( null !== $sourceKind ) {
            $classification = self::classificationForSourceKind($sourceKind, $isPageLocal);
            if ( null !== $classification ) {
                return $classification;
            }
        }

        if ( self::COORDINATE_SPACE_CANVAS_ABSOLUTE === self::coordinateSpace($box) ) {
            return self::CLASSIFICATION_CANVAS_ABSOLUTE;
        }

        return $isPageLocal ? self::CLASSIFICATION_PAGE_LOCAL : self::CLASSIFICATION_PARENT_LOCAL;
    }
}
