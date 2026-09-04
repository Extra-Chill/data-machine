<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes Figma paint collections into the scenegraph contract.
 */
final class PaintNormalizer
{
    private const PAINT_COLLECTION_SOURCES = array(
        'fills'            => 'fills',
        'fillPaints'       => 'fills',
        'paints'           => 'fills',
        'strokes'          => 'strokes',
        'strokePaints'     => 'strokes',
        'background'       => 'background',
        'backgroundPaints' => 'background',
    );

    private const FALLBACK_COLOR_SOURCES = array(
        'fill'            => 'fills',
        'backgroundColor' => 'background',
    );

    private const STYLE_REFERENCE_FIELDS = array(
        'fills'   => array('styleIdForFill'),
        'strokes' => array('styleIdForStrokeFill', 'styleIdForStroke'),
    );

    private const STYLE_PAINT_COLLECTION = array(
        'fills'   => 'fills',
        'strokes' => 'fills',
    );

    private const NON_VECTOR_PAINT_SOURCE_NODE_TYPES = array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION', 'CANVAS', 'DOCUMENT');

    private const VECTOR_GEOMETRY_FIELDS = array(
        'fills'   => array('fillGeometry'),
        'strokes' => array('strokeGeometry'),
    );

    private const VECTOR_PATH_COLLECTION_FIELDS = array('vectorPaths', 'paths');

    private const VECTOR_PATH_SCALAR_FIELDS = array('pathData', 'path', 'd');

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function normalizePaintCollections(array $node, string $nodeId, array &$diagnostics, array $paintStyles = array()): array
    {
        $collections = $this->normalizeLocalPaintCollections($node, $nodeId, $diagnostics);
        foreach ( array_keys(self::STYLE_REFERENCE_FIELDS) as $collection ) {
            $this->applyReferencedPaintStyle($collections, $node, $nodeId, $collection, $paintStyles, $diagnostics);
        }

        foreach ( self::FALLBACK_COLOR_SOURCES as $sourceKey => $targetKey ) {
            if ( ! isset($node[$sourceKey]) ) {
                continue;
            }

            $color = $this->normalizeColor($node[$sourceKey]);
            if ( null !== $color ) {
                $collections[$targetKey][] = array('type' => 'SOLID', 'color' => $color);
            }
        }

        return $collections;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeLocalPaintCollections(array $node, string $nodeId, array &$diagnostics): array
    {
        $collections = array();
        $sources = self::PAINT_COLLECTION_SOURCES;
        foreach ( array('fills', 'strokes') as $collection ) {
            foreach ( $this->explicitInstancePaintOverrideFields($node, $collection) as $sourceKey ) {
                if ( isset($sources[$sourceKey]) ) {
                    // Apply explicit aliases after retained component paint fields.
                    $sources[$sourceKey] = null;
                }
            }
        }

        foreach ( $sources as $sourceKey => $targetKey ) {
            if ( null === $targetKey ) {
                continue;
            }

            $this->normalizeLocalPaintCollection($collections, $node, $nodeId, $sourceKey, $targetKey, $diagnostics);
        }
        foreach ( array('fills', 'strokes') as $collection ) {
            foreach ( $this->explicitInstancePaintOverrideFields($node, $collection) as $sourceKey ) {
                $targetKey = self::PAINT_COLLECTION_SOURCES[$sourceKey] ?? null;
                if ( null !== $targetKey ) {
                    $this->normalizeLocalPaintCollection($collections, $node, $nodeId, $sourceKey, $targetKey, $diagnostics);
                }
            }
        }

        return $collections;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $collections
     * @param array<string, mixed>                            $node
     * @param array<int, array<string, mixed>>                $diagnostics
     */
    private function normalizeLocalPaintCollection(array &$collections, array $node, string $nodeId, string $sourceKey, string $targetKey, array &$diagnostics): void
    {
            if ( ! is_array($node[$sourceKey] ?? null) ) {
                return;
            }

            $paints = $this->normalizePaintList($node[$sourceKey], $nodeId, $sourceKey, $diagnostics);
            if ( ! empty($paints) ) {
                $collections[$targetKey] = $paints;
            }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $collections
     * @param array<string, mixed>                            $node
     * @param array<string, array<string, mixed>>             $paintStyles
     * @param array<int, array<string, mixed>>                $diagnostics
     */
    private function applyReferencedPaintStyle(array &$collections, array $node, string $nodeId, string $collection, array $paintStyles, array &$diagnostics): void
    {
        $styleId = $this->readStyleReferenceId($node, $collection);
        if ( null === $styleId ) {
            return;
        }

        $stylePaints = $this->readStylePaints($paintStyles, $styleId, $collection);
        if ( empty($stylePaints) ) {
            $this->appendMissingPaintStyleDiagnostic($diagnostics, $nodeId, $collection, $styleId, ! empty($collections[$collection]));
            return;
        }

        $precedence = $this->resolveLocalStylePaintPrecedence($node, $collection, ! empty($collections[$collection]));
        if ( ! empty($collections[$collection]) && $collections[$collection] !== $stylePaints ) {
            $this->appendLocalStylePaintConflictDiagnostic($diagnostics, $nodeId, $collection, $styleId, $collections[$collection], $stylePaints, $precedence);
        }

        if ( empty($collections[$collection]) || 'style' === $precedence['winner'] ) {
            $collections[$collection] = $stylePaints;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function readStyleReferenceId(array $node, string $collection): ?string
    {
        foreach ( self::STYLE_REFERENCE_FIELDS[$collection] ?? array() as $field ) {
            $id = $this->readStyleGuidId($node[$field] ?? null);
            if ( null !== $id ) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $paintStyles
     * @return array<int, array<string, mixed>>
     */
    private function readStylePaints(array $paintStyles, string $styleId, string $collection): array
    {
        $styleCollection = self::STYLE_PAINT_COLLECTION[$collection] ?? $collection;
        $paints = $paintStyles[$styleId][$styleCollection] ?? null;

        return is_array($paints) ? $paints : array();
    }

    /**
     * @param array<string, mixed> $node
     * @return array{winner: string, local_vector_source: bool, local_provenance: string, local_source_fields: array<int, string>, reason: string}
     */
    private function resolveLocalStylePaintPrecedence(array $node, string $collection, bool $hasLocalPaints): array
    {
        $instanceOverrideFields = $this->explicitInstancePaintOverrideFields($node, $collection);
        $hasExplicitInstanceOverride = $hasLocalPaints && ! empty($instanceOverrideFields);
        $hasLocalVectorSource = $hasLocalPaints && $this->hasLocalVectorPaintSource($node, $collection);

        if ( $hasExplicitInstanceOverride ) {
            return array(
                'winner'              => 'local',
                'local_vector_source' => $hasLocalVectorSource,
                'local_provenance'    => 'instance_override',
                'local_source_fields' => $instanceOverrideFields,
                'reason'              => 'explicit_instance_paint_override',
            );
        }

        return array(
            'winner'              => $hasLocalVectorSource ? 'local' : 'style',
            'local_vector_source' => $hasLocalVectorSource,
            'local_provenance'    => $hasLocalVectorSource ? 'vector_geometry' : 'unproven_node_field',
            'local_source_fields' => array(),
            'reason'              => $hasLocalVectorSource ? 'local_vector_geometry' : 'referenced_style_without_explicit_local_override',
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitInstancePaintOverrideFields(array $node, string $collection): array
    {
        $fields = $node['_figma_instance_paint_override_fields'][$collection] ?? null;
        if ( ! is_array($fields) ) {
            return array();
        }

        $fields = array_values(array_filter($fields, static fn (mixed $field): bool => is_string($field) && '' !== $field));
        sort($fields, SORT_STRING);
        return array_values(array_unique($fields));
    }

    /**
     * @param array<int, mixed> $paints
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    public function normalizePaintList(array $paints, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $normalizedPaints = array();
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) ) {
                continue;
            }

            if ( ! $this->looksLikePaint($paint) && $this->looksLikePaintList($paint) ) {
                array_push($normalizedPaints, ...$this->normalizePaintList($paint, $nodeId, $paintKey, $diagnostics));
                continue;
            }

            $normalized = $this->normalizePaint($paint, $nodeId, $paintKey, $diagnostics);
            if ( ! empty($normalized) ) {
                $normalizedPaints[] = $normalized;
            }
        }

        return $normalizedPaints;
    }

    /**
     * @param array<int|string, mixed> $paint
     */
    private function looksLikePaint(array $paint): bool
    {
        return isset($paint['type']) || isset($paint['color']) || isset($paint['image']) || isset($paint['gradientStops']) || isset($paint['stops']);
    }

    /**
     * @param array<int|string, mixed> $paints
     */
    private function looksLikePaintList(array $paints): bool
    {
        foreach ( $paints as $paint ) {
            if ( is_array($paint) && $this->looksLikePaint($paint) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $overridePaints
     * @param array<int, mixed> $sourcePaints
     * @return array<int, mixed>
     */
    public function removeSourceImagePaintsFromOverrideList(array $overridePaints, array $sourcePaints): array
    {
        $sourceRefs = array();
        foreach ( $sourcePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref ) {
                $sourceRefs[$ref] = true;
            }
        }

        if ( empty($sourceRefs) ) {
            return $overridePaints;
        }

        $hasReplacementImage = false;
        foreach ( $overridePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref && ! isset($sourceRefs[$ref]) ) {
                $hasReplacementImage = true;
                break;
            }
        }

        if ( ! $hasReplacementImage ) {
            return $overridePaints;
        }

        $filtered = array();
        foreach ( $overridePaints as $paint ) {
            $ref = is_array($paint) ? $this->readImagePaintRef($paint) : null;
            if ( null !== $ref && isset($sourceRefs[$ref]) ) {
                continue;
            }

            $filtered[] = $paint;
        }

        return $filtered;
    }

    private function readStyleGuidId(mixed $style): ?string
    {
        if ( is_array($style) && isset($style['guid']) ) {
            return $this->readGuidId($style['guid']);
        }

        return $this->readGuidId($style);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasLocalVectorPaintSource(array $node, string $collection): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( in_array($type, self::NON_VECTOR_PAINT_SOURCE_NODE_TYPES, true) ) {
            return false;
        }

        foreach ( self::VECTOR_GEOMETRY_FIELDS[$collection] ?? array() as $key ) {
            if ( $this->hasAuthoredVectorPathGeometry($node[$key] ?? null) ) {
                return true;
            }
        }

        foreach ( self::VECTOR_PATH_COLLECTION_FIELDS as $key ) {
            if ( ! empty($node[$key]) ) {
                return true;
            }
        }

        foreach ( self::VECTOR_PATH_SCALAR_FIELDS as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== trim((string) $node[$key]) ) {
                return true;
            }
        }

        return isset($node['vectorData']) && is_array($node['vectorData']) && ! empty($node['vectorData']);
    }

    private function hasAuthoredVectorPathGeometry(mixed $geometry): bool
    {
        if ( ! is_array($geometry) ) {
            return false;
        }

        foreach ( $geometry as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }

            foreach ( array('path', 'pathData', 'd', 'data') as $key ) {
                if ( isset($entry[$key]) && is_scalar($entry[$key]) && '' !== trim((string) $entry[$key]) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<int, array<string, mixed>> $localPaints
     * @param array<int, array<string, mixed>> $stylePaints
     * @param array{winner: string, local_vector_source: bool, local_provenance: string, local_source_fields: array<int, string>, reason: string} $precedence
     */
    private function appendLocalStylePaintConflictDiagnostic(array &$diagnostics, string $nodeId, string $collection, string $styleId, array $localPaints, array $stylePaints, array $precedence): void
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'figma_local_style_paint_conflict' !== ($diagnostic['code'] ?? null) || ! is_array($diagnostic['context'] ?? null) ) {
                continue;
            }

            $context = $diagnostic['context'];
            if ( $nodeId === ($context['node_id'] ?? null) && $collection === ($context['collection'] ?? null) && $styleId === ($context['style_id'] ?? null) && $precedence['winner'] === ($context['precedence'] ?? null) ) {
                return;
            }
        }

        $diagnostics[] = array(
            'severity' => 'local' === $precedence['winner'] ? 'info' : 'warning',
            'code'     => 'figma_local_style_paint_conflict',
            'message'  => 'Figma node has local paints and a paint style reference that normalize to different paint values.',
            'source'   => 'PaintNormalizer',
            'context'  => array(
                'node_id'         => $nodeId,
                'collection'      => $collection,
                'style_id'        => $styleId,
                'geometry_backed' => $precedence['local_vector_source'],
                'precedence'      => $precedence['winner'],
                'precedence_rule' => $precedence['reason'],
                'local_paint_provenance' => $precedence['local_provenance'],
                'local_paint_source_fields' => $precedence['local_source_fields'],
                'style_paint_provenance' => 'referenced_style',
                'resolution_reason' => $precedence['reason'],
                'local_paints'    => $localPaints,
                'style_paints'    => $stylePaints,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function appendMissingPaintStyleDiagnostic(array &$diagnostics, string $nodeId, string $collection, string $styleId, bool $hasLocalPaints): void
    {
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'figma_missing_paint_style_reference' !== ($diagnostic['code'] ?? null) || ! is_array($diagnostic['context'] ?? null) ) {
                continue;
            }

            $context = $diagnostic['context'];
            if ( $nodeId === ($context['node_id'] ?? null) && $collection === ($context['collection'] ?? null) && $styleId === ($context['style_id'] ?? null) ) {
                return;
            }
        }

        $diagnostics[] = array(
            'severity' => $hasLocalPaints ? 'info' : 'warning',
            'code'     => 'figma_missing_paint_style_reference',
            'message'  => 'Figma node references a paint style that is not present in the decoded source graph.',
            'source'   => 'PaintNormalizer',
            'context'  => array(
                'node_id'    => $nodeId,
                'collection' => $collection,
                'style_id'   => $styleId,
                'local_paints_preserved' => $hasLocalPaints,
            ),
        );
    }

    /**
     * @param array<string, mixed>             $paint
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>
     */
    private function normalizePaint(array $paint, string $nodeId, string $paintKey, array &$diagnostics): array
    {
        $type = strtoupper((string) ($paint['type'] ?? 'SOLID'));
        if ( false === ($paint['visible'] ?? true) ) {
            return array();
        }

        if ( 'SOLID' === $type ) {
            $color = $this->normalizeColor($paint['color'] ?? $paint);
            $variableBindings = $this->normalizePaintVariableBindings($paint);
            if ( null === $color && empty($variableBindings) ) {
                return array();
            }

            $normalized = array('type' => 'SOLID');
            if ( null !== $color ) {
                $normalized['color'] = $color;
            }
            if ( ! empty($variableBindings) ) {
                $normalized['variable_bindings'] = $variableBindings;
            }
            if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                $normalized['opacity'] = (float) $paint['opacity'];
            }
            if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
            }

            return $normalized;
        }

        if ( 'IMAGE' === $type ) {
            $normalized = array('type' => 'IMAGE');
            $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? $paint['ref'] ?? null;
            if ( is_scalar($ref) && '' !== (string) $ref ) {
                $normalized['ref'] = $this->normalizeImageHash((string) $ref);
            }

            if ( is_array($paint['image'] ?? null) ) {
                $imageRef = $this->readNestedImageHash($paint['image']);
                if ( null !== $imageRef ) {
                    $normalized['ref'] = $imageRef;
                    $normalized['imageHash'] = $imageRef;
                }
                $imageMetadata = $this->normalizeNestedImageMetadata($paint['image']);
                if ( ! empty($imageMetadata) ) {
                    $normalized['image'] = $imageMetadata;
                    if ( isset($imageMetadata['name']) ) {
                        $normalized['imageName'] = $imageMetadata['name'];
                    }
                }
            }

            if ( is_array($paint['imageThumbnail'] ?? null) ) {
                $thumbnailRef = $this->readNestedImageHash($paint['imageThumbnail']);
                if ( null !== $thumbnailRef ) {
                    $normalized['thumbnailRef'] = $thumbnailRef;
                    $normalized['thumbnailHash'] = $thumbnailRef;
                }
                if ( isset($paint['imageThumbnail']['name']) && is_scalar($paint['imageThumbnail']['name']) ) {
                    $normalized['thumbnailName'] = (string) $paint['imageThumbnail']['name'];
                }
                $thumbnailMetadata = $this->normalizeNestedImageMetadata($paint['imageThumbnail']);
                if ( ! empty($thumbnailMetadata) ) {
                    $normalized['thumbnail'] = $thumbnailMetadata;
                }
            }

            foreach ( array('imageScaleMode', 'scaleMode', 'altText', 'publishID', 'sourceLibraryKey', 'libraryKey') as $key ) {
                if ( isset($paint[$key]) && is_scalar($paint[$key]) ) {
                    $normalized[$key] = (string) $paint[$key];
                }
            }
            if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
            }
            foreach ( array('originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'opacity') as $key ) {
                if ( isset($paint[$key]) && is_numeric($paint[$key]) ) {
                    $normalized[$key] = (float) $paint[$key];
                }
            }
            if ( isset($paint['animationFrame']) && is_numeric($paint['animationFrame']) ) {
                $normalized['animationFrame'] = (int) $paint['animationFrame'];
            }
            if ( isset($paint['imageShouldColorManage']) && is_bool($paint['imageShouldColorManage']) ) {
                $normalized['imageShouldColorManage'] = $paint['imageShouldColorManage'];
            }
            if ( isset($paint['thumbHash']) && is_scalar($paint['thumbHash']) && '' !== (string) $paint['thumbHash'] ) {
                $normalized['thumbHash'] = $this->normalizeByteString((string) $paint['thumbHash']);
            }
            if ( is_array($paint['assetRef'] ?? null) ) {
                $assetRef = $this->normalizeAssetRef($paint['assetRef']);
                if ( ! empty($assetRef) ) {
                    $normalized['assetRef'] = $assetRef;
                }
            }
            if ( is_array($paint['sourceImage'] ?? null) ) {
                $sourceImage = $this->normalizeNestedImageMetadata($paint['sourceImage']);
                if ( ! empty($sourceImage) ) {
                    $normalized['sourceImage'] = $sourceImage;
                }
            }
            if ( is_array($paint['exportSettings'] ?? null) ) {
                $exportSettings = $this->normalizeExportSettings($paint['exportSettings']);
                if ( ! empty($exportSettings) ) {
                    $normalized['exportSettings'] = $exportSettings;
                }
            }
            foreach ( array('transform', 'imageTransform', 'cropTransform') as $transformKey ) {
                if ( is_array($paint[$transformKey] ?? null) ) {
                    $normalized['transform'] = $paint[$transformKey];
                    break;
                }
            }
            if ( is_array($paint['cropRect'] ?? null) ) {
                $normalized['cropRect'] = $paint['cropRect'];
            }

            return $normalized;
        }

        if ( in_array($type, array('GRADIENT_LINEAR', 'GRADIENT_RADIAL', 'GRADIENT_ANGULAR'), true) ) {
            $stops = $this->normalizeGradientStops($paint['gradientStops'] ?? $paint['stops'] ?? array());
            $variableBindings = $this->normalizePaintVariableBindings($paint);
            if ( ! empty($stops) || ! empty($variableBindings) ) {
                $normalized = array('type' => $type);
                if ( ! empty($stops) ) {
                    $normalized['stops'] = $stops;
                }
                if ( ! empty($variableBindings) ) {
                    $normalized['variable_bindings'] = $variableBindings;
                }
                if ( isset($paint['opacity']) && is_numeric($paint['opacity']) ) {
                    $normalized['opacity'] = (float) $paint['opacity'];
                }
                if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                    $normalized['blendMode'] = strtoupper((string) $paint['blendMode']);
                }
                foreach ( array('gradientTransform', 'transform') as $transformKey ) {
                    if ( is_array($paint[$transformKey] ?? null) ) {
                        $normalized['gradientTransform'] = $paint[$transformKey];
                        break;
                    }
                }
                $interpolation = $this->normalizeGradientInterpolation($paint);
                if ( ! empty($interpolation) ) {
                    $normalized['gradient_interpolation'] = $interpolation;
                }

                return $normalized;
            }
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_figma_paint_type',
            'message'  => 'Unsupported Figma paint type was omitted from static CSS.',
            'context'  => array(
                'node_id' => $nodeId,
                'paint'   => $paintKey,
                'type'    => $type,
            ),
        );

        return array();
    }

    /**
     * @param mixed $stops
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGradientStops(mixed $stops): array
    {
        if ( ! is_array($stops) ) {
            return array();
        }

        $normalizedStops = array();
        foreach ( $stops as $stop ) {
            if ( ! is_array($stop) || ! isset($stop['position']) || ! is_numeric($stop['position']) ) {
                continue;
            }

            $color = $this->normalizeColor($stop['color'] ?? null);
            $variableBindings = $this->normalizePaintVariableBindings($stop);
            if ( null === $color && empty($variableBindings) ) {
                continue;
            }

            $normalizedStop = array('position' => max(0.0, min(1.0, (float) $stop['position'])));
            if ( null !== $color ) {
                $normalizedStop['color'] = $color;
            }
            if ( ! empty($variableBindings) ) {
                $normalizedStop['variable_bindings'] = $variableBindings;
            }
            $interpolation = $this->normalizeGradientInterpolation($stop);
            if ( ! empty($interpolation) ) {
                $normalizedStop['interpolation'] = $interpolation;
            }
            $normalizedStops[] = $normalizedStop;
        }

        return $normalizedStops;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<string, mixed>
     */
    private function normalizePaintVariableBindings(array $paint): array
    {
        $bindings = array();
        foreach ( array('colorVar' => 'color', 'stopsVar' => 'stops') as $sourceKey => $target ) {
            if ( ! array_key_exists($sourceKey, $paint) ) {
                continue;
            }

            $binding = $this->normalizeVariableBindingValue($paint[$sourceKey], $target);
            if ( ! empty($binding) ) {
                $bindings[$target] = $binding;
            }
        }

        return $bindings;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeVariableBindingValue(mixed $value, string $target): array
    {
        if ( is_scalar($value) && '' !== (string) $value ) {
            return array(
                'target_field'        => $target,
                'variable_id'         => (string) $value,
                'css_custom_property' => $this->cssCustomPropertyName((string) $value),
            );
        }

        if ( ! is_array($value) ) {
            return array();
        }

        $variableId = null;
        foreach ( array('id', 'variableId', 'variableID', 'key') as $key ) {
            if ( isset($value[$key]) && is_scalar($value[$key]) && '' !== (string) $value[$key] ) {
                $variableId = (string) $value[$key];
                break;
            }
        }
        if ( null === $variableId ) {
            foreach ( array('guid', 'alias', 'assetRef') as $key ) {
                $variableId = $this->readGuidId($value[$key] ?? null);
                if ( null !== $variableId ) {
                    break;
                }
            }
        }

        $binding = array('target_field' => $target);
        if ( null !== $variableId ) {
            $binding['variable_id'] = $variableId;
            $binding['css_custom_property'] = $this->cssCustomPropertyName($variableId);
        }
        foreach ( array('name', 'dataType', 'resolvedDataType', 'modeID', 'collectionID') as $key ) {
            if ( isset($value[$key]) && is_scalar($value[$key]) ) {
                $binding[$this->normalizeMetadataKey($key)] = (string) $value[$key];
            }
        }

        return 1 === count($binding) ? array() : $binding;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<string, mixed>
     */
    private function normalizeGradientInterpolation(array $paint): array
    {
        $interpolation = array();
        foreach ( array('gradientInterpolation', 'interpolation', 'interpolationMode', 'colorInterpolation', 'colorSpace', 'interpolationColorSpace') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                $interpolation[$this->normalizeMetadataKey($key)] = (string) $paint[$key];
            }
        }

        return $interpolation;
    }

    private function readNestedImageHash(array $image): ?string
    {
        if ( ! isset($image['hash']) || ! is_scalar($image['hash']) || '' === (string) $image['hash'] ) {
            return null;
        }

        return $this->normalizeImageHash((string) $image['hash']);
    }

    /**
     * @param array<string, mixed> $image
     * @return array<string, mixed>
     */
    private function normalizeNestedImageMetadata(array $image): array
    {
        $metadata = array();

        foreach ( array('hash' => 'hash', 'name' => 'name', 'thumbHash' => 'thumbHash', 'publishID' => 'publishID', 'sourceLibraryKey' => 'sourceLibraryKey', 'libraryKey' => 'libraryKey') as $source => $target ) {
            if ( ! isset($image[$source]) || ! is_scalar($image[$source]) || '' === (string) $image[$source] ) {
                continue;
            }

            $metadata[$target] = 'hash' === $source
                ? $this->normalizeImageHash((string) $image[$source])
                : ('thumbHash' === $source ? $this->normalizeByteString((string) $image[$source]) : (string) $image[$source]);
        }

        foreach ( array('width', 'height') as $key ) {
            if ( isset($image[$key]) && is_numeric($image[$key]) ) {
                $metadata[$key] = (float) $image[$key];
            }
        }

        if ( is_array($image['assetRef'] ?? null) ) {
            $assetRef = $this->normalizeAssetRef($image['assetRef']);
            if ( ! empty($assetRef) ) {
                $metadata['assetRef'] = $assetRef;
            }
        }

        if ( is_array($image['sourceImage'] ?? null) ) {
            $sourceImage = $this->normalizeNestedImageMetadata($image['sourceImage']);
            if ( ! empty($sourceImage) ) {
                $metadata['sourceImage'] = $sourceImage;
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $assetRef
     * @return array<string, mixed>
     */
    private function normalizeAssetRef(array $assetRef): array
    {
        $normalized = array();
        foreach ( array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($assetRef[$key]) && is_scalar($assetRef[$key]) && '' !== (string) $assetRef[$key] ) {
                $normalized[$key] = (string) $assetRef[$key];
            }
        }

        $guid = $this->readGuidId($assetRef['guid'] ?? null);
        if ( null !== $guid ) {
            $normalized['guid'] = $guid;
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $settings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeExportSettings(array $settings): array
    {
        $items = array_is_list($settings) ? $settings : array($settings);
        $normalized = array();

        foreach ( $items as $setting ) {
            if ( ! is_array($setting) ) {
                continue;
            }

            $item = array();
            foreach ( array('format', 'suffix') as $key ) {
                if ( isset($setting[$key]) && is_scalar($setting[$key]) ) {
                    $item[$key] = (string) $setting[$key];
                }
            }
            foreach ( array('contentsOnly', 'useAbsoluteBounds') as $key ) {
                if ( isset($setting[$key]) && is_bool($setting[$key]) ) {
                    $item[$key] = $setting[$key];
                }
            }
            if ( is_array($setting['constraint'] ?? null) ) {
                $constraint = array();
                if ( isset($setting['constraint']['type']) && is_scalar($setting['constraint']['type']) ) {
                    $constraint['type'] = (string) $setting['constraint']['type'];
                }
                if ( isset($setting['constraint']['value']) && is_numeric($setting['constraint']['value']) ) {
                    $constraint['value'] = (float) $setting['constraint']['value'];
                }
                if ( ! empty($constraint) ) {
                    $item['constraint'] = $constraint;
                }
            }
            if ( ! empty($item) ) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function readImagePaintRef(array $paint): ?string
    {
        $type = strtoupper((string) ($paint['type'] ?? ''));
        if ( '' !== $type && 'IMAGE' !== $type ) {
            return null;
        }

        $ref = $paint['imageRef'] ?? $paint['imageHash'] ?? $paint['ref'] ?? null;
        if ( is_scalar($ref) && '' !== (string) $ref ) {
            return $this->normalizeImageHash((string) $ref);
        }

        if ( is_array($paint['image'] ?? null) ) {
            return $this->readNestedImageHash($paint['image']);
        }

        return null;
    }

    private function normalizeImageHash(string $hash): string
    {
        if ( 1 === preg_match('/^[a-f0-9]{40}$/i', $hash) ) {
            return strtolower($hash);
        }

        if ( 20 === strlen($hash) ) {
            return bin2hex($hash);
        }

        return $hash;
    }

    private function normalizeByteString(string $value): string
    {
        return 1 === preg_match('//u', $value) ? $value : bin2hex($value);
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
        }

        return null;
    }

    private function cssCustomPropertyName(string $variableId): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $variableId) ?? $variableId);
        $slug = trim($slug, '-');
        return '--figma-var-' . ('' !== $slug ? $slug : 'unknown');
    }

    private function normalizeMetadataKey(string $key): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key);
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeColor(mixed $value): ?array
    {
        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->normalizeColorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->normalizeColorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->normalizeColorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $color = array('r' => $red, 'g' => $green, 'b' => $blue);
        if ( isset($value['a']) && is_numeric($value['a']) ) {
            $color['a'] = (float) $value['a'];
        }

        return $color;
    }

    private function normalizeColorChannel(mixed $value): ?float
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel > 1 ) {
            $channel /= 255;
        }

        return max(0, min(1, $channel));
    }
}
