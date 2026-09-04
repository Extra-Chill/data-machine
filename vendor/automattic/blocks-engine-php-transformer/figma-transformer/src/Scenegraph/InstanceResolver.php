<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Resolves Figma component instance data into the normalized scenegraph shape.
 */
final class InstanceResolver
{
    /**
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, array<string, mixed>>|null
     */
    public function normalizeInstanceOverrides(array $node, string $instanceId, array &$diagnostics): ?array
    {
        $rawOverrides = $this->collectRawOverrides($node);

        if ( empty($rawOverrides) ) {
            return array();
        }

        $overrides = array();
        foreach ( $rawOverrides as $key => $override ) {
            if ( ! is_array($override) ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'figma_instance_override_unsupported',
                    'message'  => 'Figma instance override shape is unsupported and was not applied.',
                    'context'  => array('instance_id' => $instanceId),
                );
                return null;
            }

            $nodeId = $this->readString($override, array('nodeId', 'node_id', 'id')) ?? $this->readOverrideGuidPathTarget($override) ?? (is_string($key) ? $key : null);
            if ( null === $nodeId || '' === $nodeId ) {
                return null;
            }

            foreach ( array('characters', 'text', 'name') as $field ) {
                if ( isset($override[$field]) && is_scalar($override[$field]) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
            }
            if ( isset($override['textData']['characters']) && is_scalar($override['textData']['characters']) ) {
                $overrides[$nodeId]['characters'] = (string) $override['textData']['characters'];
            }
            foreach ( array('derivedTextData', 'overrideKey', 'fontName', 'fontFamily', 'fontPostScriptName', 'fontWeight', 'fontSize', 'lineHeight', 'lineHeightPx', 'lineHeightPercent', 'letterSpacing', 'listSpacing', 'textCase', 'styleIdForText', 'size', 'relativeTransform', 'absoluteTransform', 'transform', 'fillPaints', 'fills', 'strokes', 'strokePaints', 'strokeWeight', 'strokeAlign', 'dashPattern', 'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight', 'borderLeftWeight', 'borderRightWeight', 'effects', 'styleIdForFill', 'styleIdForStrokeFill', 'styleIdForStroke', 'styleIdForEffect', 'fillGeometry', 'strokeGeometry', 'vectorPaths', 'paths', 'pathData', 'path', 'd', 'arcData', 'cornerRadius', 'rectangleCornerRadiiIndependent', 'rectangleTopLeftCornerRadius', 'rectangleTopRightCornerRadius', 'rectangleBottomLeftCornerRadius', 'rectangleBottomRightCornerRadius', 'proportionsConstrained', 'targetAspectRatio', 'stackMode', 'stackPrimarySizing', 'stackCounterSizing', 'stackPositioning', 'stackChildAlignSelf', 'stackChildPrimaryGrow', 'stackWidth', 'stackHeight', 'stackSpacing', 'stackCounterSpacing', 'stackWrap', 'stackReverseZIndex', 'stackPadding', 'stackPaddingLeft', 'stackPaddingRight', 'stackPaddingTop', 'stackPaddingBottom', 'stackHorizontalPadding', 'stackVerticalPadding', 'layoutMode', 'primaryAxisSizingMode', 'counterAxisSizingMode', 'primaryAxisAlignItems', 'counterAxisAlignItems', 'itemSpacing', 'counterAxisSpacing', 'layoutWrap', 'layoutGrow', 'layoutAlign', 'layoutPositioning', 'layoutSizingHorizontal', 'layoutSizingVertical', 'horizontalSizing', 'verticalSizing', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'paddingHorizontal', 'paddingVertical', 'constraints', 'horizontalConstraint', 'verticalConstraint', 'minSize', 'maxSize', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight', 'componentPropAssignments') as $field ) {
                if ( array_key_exists($field, $override) ) {
                    $overrides[$nodeId][$field] = $override[$field];
                }
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int|string, mixed>
     */
    private function collectRawOverrides(array $node): array
    {
        $rawOverrides = array();
        foreach ( array('overrides', 'symbolOverrides', 'symbolOverride') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $rawOverrides = array_merge($rawOverrides, $this->normalizeRawOverrideList($node[$key]));
            }
        }

        foreach ( array('symbolData', 'derivedSymbolData') as $key ) {
            $payload = $node[$key] ?? null;
            if ( ! is_array($payload) ) {
                continue;
            }

            foreach ( array('symbolOverrides', 'symbolOverride', 'overrides') as $overrideKey ) {
                if ( is_array($payload[$overrideKey] ?? null) ) {
                    $rawOverrides = array_merge($rawOverrides, $this->normalizeRawOverrideList($payload[$overrideKey]));
                    continue 2;
                }
            }

            // Older normalized fixtures stored DerivedSymbolData as the override
            // list itself. Keep accepting that shape while preferring the real
            // Kiwi struct shape above.
            if ( 'derivedSymbolData' === $key && array_is_list($payload) ) {
                $rawOverrides = array_merge($rawOverrides, $this->normalizeRawOverrideList($payload));
            }
        }

        return $rawOverrides;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<int|string, mixed>
     */
    private function normalizeRawOverrideList(array $raw): array
    {
        if ( array_is_list($raw) ) {
            return $raw;
        }

        $looksLikeSingleOverride = false;
        foreach ( array('nodeId', 'node_id', 'nodeID', 'id', 'guid', 'guidPath', 'textData', 'characters', 'fillPaints', 'fills', 'componentPropAssignments', 'overrideKey', 'proportionsConstrained', 'targetAspectRatio') as $key ) {
            if ( array_key_exists($key, $raw) ) {
                $looksLikeSingleOverride = true;
                break;
            }
        }

        return $looksLikeSingleOverride ? array($raw) : $raw;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function readOverrideGuidPathTarget(array $override): ?string
    {
        $directGuid = $this->readGuidId($override['guid'] ?? $override['nodeID'] ?? $override['nodeGuid'] ?? null);
        if ( null !== $directGuid ) {
            return $directGuid;
        }

        $guidPath = $override['guidPath'] ?? null;
        if ( ! is_array($guidPath) ) {
            return null;
        }

        if ( isset($guidPath['guid']) ) {
            $pathGuid = $this->readGuidId($guidPath['guid']);
            if ( null !== $pathGuid ) {
                return $pathGuid;
            }
        }

        $guids = is_array($guidPath['guids'] ?? null) ? $guidPath['guids'] : $guidPath;
        $ids = array();
        foreach ( $guids as $guid ) {
            $id = $this->readGuidId($guid);
            if ( null !== $id ) {
                $ids[] = $id;
            }
        }

        return empty($ids) ? null : implode('/', $ids);
    }

    private function readGuidId(mixed $guid): ?string
    {
        if ( is_array($guid) && isset($guid['guid']) ) {
            return $this->readGuidId($guid['guid']);
        }

        if ( is_array($guid) && isset($guid['sessionID'], $guid['localID']) ) {
            return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
        }

        if ( is_scalar($guid) && '' !== (string) $guid ) {
            return (string) $guid;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>   $keys
     */
    private function readString(array $node, array $keys): ?string
    {
        foreach ( $keys as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                return (string) $node[$key];
            }
        }

        return null;
    }
}
