<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Normalizes Figma vector geometry and decodes vector command blobs.
 */
final class VectorGeometryNormalizer
{
    private const MAX_VECTOR_COMMAND_BLOB_COMMANDS = 5000;
    private const MAX_VECTOR_COMMAND_BLOB_PATH_BYTES = 131072;
    private const MAX_VECTOR_NETWORK_OBJECT_VERTICES = 20000;
    private const MAX_VECTOR_NETWORK_OBJECT_SEGMENTS = 40000;
    private const MAX_VECTOR_NETWORK_OBJECT_REGIONS = 20000;

    /**
     * @param array<string, mixed> $node
     * @return array{x: float, y: float}|null
     */
    public function normalizedVectorScale(array $node): ?array
    {
        $size = is_array($node['vectorData']['normalizedSize'] ?? null) ? $node['vectorData']['normalizedSize'] : array();
        $normalizedWidth = $this->readPointCoordinate($size, 'x') ?? $this->readPointCoordinate($size, 'width');
        $normalizedHeight = $this->readPointCoordinate($size, 'y') ?? $this->readPointCoordinate($size, 'height');
        if ( null === $normalizedWidth || null === $normalizedHeight || $normalizedWidth <= 0.0 || $normalizedHeight <= 0.0 ) {
            return null;
        }

        $width = $this->rawNodeDimension($node, 'width');
        $height = $this->rawNodeDimension($node, 'height');
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return null;
        }

        $scaleX = $width / $normalizedWidth;
        $scaleY = $height / $normalizedHeight;
        if ( ! is_finite($scaleX) || ! is_finite($scaleY) || ( abs($scaleX - 1.0) < 0.0001 && abs($scaleY - 1.0) < 0.0001 ) ) {
            return null;
        }

        return array('x' => $scaleX, 'y' => $scaleY);
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    public function compactUnsupportedVectorNetworkBlobDiagnostics(array $diagnostics): array
    {
        $compacted = array();
        $groups = array();

        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || 'unsupported_vector_network_blob' !== ($diagnostic['code'] ?? null) ) {
                $compacted[] = $diagnostic;
                continue;
            }

            $context = is_array($diagnostic['context'] ?? null) ? $diagnostic['context'] : array();
            $signatureHex = isset($context['signature_hex']) && is_scalar($context['signature_hex']) ? (string) $context['signature_hex'] : '';
            $byteLength = isset($context['byte_length']) && is_numeric($context['byte_length']) ? (int) $context['byte_length'] : null;
            $networkCounts = is_array($context['network_counts'] ?? null) ? array_values($context['network_counts']) : null;
            $key = json_encode(array($signatureHex, $byteLength, $networkCounts), JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) ) {
                $key = $signatureHex . ':' . (string) $byteLength;
            }

            if ( ! isset($groups[$key]) ) {
                $groups[$key] = array(
                    'severity' => $diagnostic['severity'] ?? 'warning',
                    'message'  => $diagnostic['message'] ?? 'Unsupported Figma vector network blob was omitted from SVG output.',
                    'source'   => $diagnostic['source'] ?? 'ScenegraphNormalizer',
                    'context'  => array(
                        'occurrence_count' => 0,
                        'affected_node_count' => 0,
                        'sample_node_ids'  => array(),
                        'sample_blob_refs' => array(),
                    ),
                    'node_ids' => array(),
                    'blob_refs' => array(),
                    'blob_ref_seen' => array(),
                    'risk_node_seen' => array(),
                );

                foreach ( array('geometry', 'blob_ref', 'byte_length', 'signature_hex', 'network_counts', 'vector_network_blob_kind', 'decoder_blocker', 'render_risk', 'single_region_loop_candidate', 'candidate_layout', 'candidate_vertex_points_sample', 'candidate_decoder_requirement') as $contextKey ) {
                    if ( array_key_exists($contextKey, $context) ) {
                        $groups[$key]['context'][$contextKey] = $context[$contextKey];
                    }
                }
            }

            $groups[$key]['context']['occurrence_count']++;
            $nodeId = isset($context['node_id']) && is_scalar($context['node_id']) ? (string) $context['node_id'] : '';
            if ( '' !== $nodeId ) {
                $groups[$key]['node_ids'][$nodeId] = true;
            }
            if ( isset($context['render_risk']) && is_scalar($context['render_risk']) ) {
                $currentRisk = isset($groups[$key]['context']['render_risk']) && is_scalar($groups[$key]['context']['render_risk']) ? (string) $groups[$key]['context']['render_risk'] : 'low';
                $nextRisk = (string) $context['render_risk'];
                $rank = array('low' => 0, 'medium' => 1, 'high' => 2);
                if ( ($rank[$nextRisk] ?? 0) > ($rank[$currentRisk] ?? 0) ) {
                    $groups[$key]['context']['render_risk'] = $nextRisk;
                }
            }
            $riskNodeId = is_array($context['render_risk_node'] ?? null) && isset($context['render_risk_node']['node_id']) && is_scalar($context['render_risk_node']['node_id']) ? (string) $context['render_risk_node']['node_id'] : '';
            if ( '' !== $riskNodeId && ! isset($groups[$key]['risk_node_seen'][$riskNodeId]) && count($groups[$key]['context']['sample_render_risk_nodes'] ?? array()) < 10 ) {
                $groups[$key]['context']['sample_render_risk_nodes'][] = $context['render_risk_node'];
                $groups[$key]['risk_node_seen'][$riskNodeId] = true;
            }
            $blobRef = isset($context['blob_ref']) && is_scalar($context['blob_ref']) ? (string) $context['blob_ref'] : '';
            if ( '' !== $blobRef && ! isset($groups[$key]['blob_ref_seen'][$blobRef]) ) {
                $groups[$key]['blob_refs'][] = $blobRef;
                $groups[$key]['blob_ref_seen'][$blobRef] = true;
            }
        }

        foreach ( $groups as $group ) {
            $context = $group['context'];
            $context['affected_node_count'] = count($group['node_ids']);
            $context['sample_node_ids'] = array_slice(array_keys($group['node_ids']), 0, 10);
            $context['sample_blob_refs'] = array_slice($group['blob_refs'], 0, 10);
            $compacted[] = array(
                'severity' => $group['severity'],
                'code'     => 'unsupported_vector_network_blob',
                'message'  => $group['message'],
                'source'   => $group['source'],
                'context'  => $context,
            );
        }

        return $compacted;
    }

    /**
     * @param array<int, array<string, mixed>> $paths
     * @return array{width: float, height: float}|null
     */
    public function normalizedVectorPathBounds(array $paths): ?array
    {
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;
        foreach ( $paths as $path ) {
            if ( ! is_array($path) || ! isset($path['data']) || ! is_scalar($path['data']) || ! preg_match_all('/-?\d+(?:\.\d+)?(?:e[+-]?\d+)?/i', (string) $path['data'], $matches) ) {
                continue;
            }
            $numbers = array_map('floatval', $matches[0]);
            for ( $i = 0; $i + 1 < count($numbers); $i += 2 ) {
                $x = $numbers[$i];
                $y = $numbers[$i + 1];
                $minX = null === $minX ? $x : min($minX, $x);
                $minY = null === $minY ? $y : min($minY, $y);
                $maxX = null === $maxX ? $x : max($maxX, $x);
                $maxY = null === $maxY ? $y : max($maxY, $y);
            }
        }

        if ( null === $minX || null === $minY || null === $maxX || null === $maxY || $maxX <= $minX || $maxY <= $minY ) {
            return null;
        }

        return array('width' => $maxX - $minX, 'height' => $maxY - $minY);
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    public function normalizeVectorPaths(array $node, array $blobs, string $nodeId, array &$diagnostics): array
    {
        $paths = array();
        foreach ( array('fillGeometry', 'strokeGeometry') as $geometryKey ) {
            if ( ! is_array($node[$geometryKey] ?? null) ) {
                continue;
            }

            foreach ( $node[$geometryKey] as $geometry ) {
                if ( ! is_array($geometry) ) {
                    continue;
                }

                $readyPath = $this->extractReadyVectorPath($geometry);
                if ( null !== $readyPath ) {
                    $normalized = array('data' => $readyPath, 'source' => $geometryKey . '.path');
                } elseif ( isset($geometry['commandsBlob']) ) {
                    $normalized = $this->normalizeVectorCommandBlob($geometry['commandsBlob'], $blobs, $nodeId, $geometryKey, $diagnostics);
                } else {
                    continue;
                }

                if ( null === $normalized ) {
                    continue;
                }

                if ( isset($geometry['windingRule']) && is_scalar($geometry['windingRule']) ) {
                    $normalized['windingRule'] = (string) $geometry['windingRule'];
                }
                $styleId = $this->readStyleId($geometry['styleID'] ?? $geometry['styleId'] ?? null);
                if ( null !== $styleId ) {
                    $normalized['styleID'] = $styleId;
                }
                $paths[] = $normalized;
            }
        }

        if ( empty($paths) && is_array($node['vectorData']['vectorNetwork'] ?? null) ) {
            $normalized = $this->normalizeVectorNetwork($node['vectorData']['vectorNetwork']);
            if ( null !== $normalized ) {
                $paths[] = $normalized;
            }
        }

        if ( empty($paths) && isset($node['vectorData']['vectorNetworkBlob']) ) {
            $normalized = $this->normalizeVectorNetworkBlob($node['vectorData']['vectorNetworkBlob'], $node, $blobs, $nodeId, $diagnostics);
            if ( null !== $normalized ) {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $network
     * @return array<string, mixed>|null
     */
    private function normalizeVectorNetwork(array $network): ?array
    {
        $vertices = $this->normalizeVectorNetworkObjectVertices($network['vertices'] ?? null);
        $segments = $this->normalizeVectorNetworkObjectSegments($network['segments'] ?? null, count($vertices));
        if ( empty($vertices) || empty($segments) ) {
            return null;
        }

        $regions = is_array($network['regions'] ?? null) ? $network['regions'] : array();
        if ( count($regions) > self::MAX_VECTOR_NETWORK_OBJECT_REGIONS ) {
            return null;
        }

        $subpaths = array();
        $windingRule = 'NONZERO';
        foreach ( $regions as $region ) {
            if ( ! is_array($region) ) {
                return null;
            }
            $entries = $this->normalizeVectorNetworkRegionEntries($region['segments'] ?? $region['segmentIndices'] ?? $region['loop'] ?? null, count($segments));
            if ( empty($entries) ) {
                return null;
            }
            $subpath = $this->vectorNetworkRegionSubpath($vertices, $segments, $entries);
            if ( null === $subpath ) {
                return null;
            }
            $subpaths[] = $subpath;
            $rule = strtoupper((string) ($region['windingRule'] ?? $region['fillRule'] ?? ''));
            if ( in_array($rule, array('EVENODD', 'EVEN_ODD'), true) ) {
                $windingRule = 'EVENODD';
            }
        }

        if ( empty($subpaths) ) {
            $subpath = $this->vectorNetworkObjectClosedLoopSubpath($vertices, $segments);
            if ( null === $subpath ) {
                return null;
            }
            $subpaths[] = $subpath;
        }

        return array(
            'data'        => implode(' ', $subpaths),
            'source'      => 'vectorData.vectorNetwork',
            'windingRule' => $windingRule,
        );
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function normalizeVectorNetworkObjectVertices(mixed $rawVertices): array
    {
        if ( ! is_array($rawVertices) || count($rawVertices) > self::MAX_VECTOR_NETWORK_OBJECT_VERTICES ) {
            return array();
        }

        $vertices = array();
        foreach ( $rawVertices as $vertex ) {
            if ( ! is_array($vertex) ) {
                return array();
            }
            $point = is_array($vertex['position'] ?? null) ? $vertex['position'] : $vertex;
            $x = $this->readPointCoordinate($point, 'x') ?? $this->readPointCoordinate($point, 0);
            $y = $this->readPointCoordinate($point, 'y') ?? $this->readPointCoordinate($point, 1);
            if ( null === $x || null === $y || ! is_finite($x) || ! is_finite($y) ) {
                return array();
            }
            $vertices[] = array($x, $y);
        }

        return $vertices;
    }

    /**
     * @return array<int, array{start: int, end: int, tangentStart: array{0: float, 1: float}, tangentEnd: array{0: float, 1: float}}>
     */
    private function normalizeVectorNetworkObjectSegments(mixed $rawSegments, int $vertexCount): array
    {
        if ( ! is_array($rawSegments) || count($rawSegments) > self::MAX_VECTOR_NETWORK_OBJECT_SEGMENTS ) {
            return array();
        }

        $segments = array();
        foreach ( $rawSegments as $segment ) {
            if ( ! is_array($segment) ) {
                return array();
            }
            $start = $this->readIndex($segment['start'] ?? $segment['startVertex'] ?? $segment[0] ?? null);
            $end = $this->readIndex($segment['end'] ?? $segment['endVertex'] ?? $segment[1] ?? null);
            if ( null === $start || null === $end || $start < 0 || $end < 0 || $start >= $vertexCount || $end >= $vertexCount || $start === $end ) {
                return array();
            }

            $segments[] = array(
                'start' => $start,
                'end' => $end,
                'tangentStart' => $this->readTangent($segment['tangentStart'] ?? $segment['startTangent'] ?? null),
                'tangentEnd' => $this->readTangent($segment['tangentEnd'] ?? $segment['endTangent'] ?? null),
            );
        }

        return $segments;
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function normalizeVectorNetworkRegionEntries(mixed $rawEntries, int $segmentCount): array
    {
        if ( ! is_array($rawEntries) || empty($rawEntries) || count($rawEntries) > $segmentCount ) {
            return array();
        }

        $entries = array();
        foreach ( $rawEntries as $entry ) {
            $segmentIndex = is_array($entry) ? $this->readIndex($entry['segment'] ?? $entry['segmentIndex'] ?? $entry['index'] ?? $entry[0] ?? null) : $this->readIndex($entry);
            $direction = 0;
            if ( is_array($entry) ) {
                if ( isset($entry['direction']) && is_numeric($entry['direction']) ) {
                    $direction = (int) $entry['direction'];
                } elseif ( false === ($entry['forward'] ?? true) || true === ($entry['reverse'] ?? false) ) {
                    $direction = 1;
                }
            }
            if ( null === $segmentIndex || $segmentIndex < 0 || $segmentIndex >= $segmentCount || ( 0 !== $direction && 1 !== $direction ) ) {
                return array();
            }
            $entries[] = array($segmentIndex, $direction);
        }

        return $entries;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $vertices
     * @param array<int, array{start: int, end: int, tangentStart: array{0: float, 1: float}, tangentEnd: array{0: float, 1: float}}> $segments
     */
    private function vectorNetworkObjectClosedLoopSubpath(array $vertices, array $segments): ?string
    {
        if ( count($segments) < 3 ) {
            return null;
        }

        $entries = array_map(static fn (int $index): array => array($index, 0), array_keys($segments));
        return $this->vectorNetworkRegionSubpath($vertices, $segments, $entries);
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private function extractReadyVectorPath(array $geometry): ?string
    {
        foreach ( array('path', 'pathData', 'd', 'data') as $key ) {
            if ( ! isset($geometry[$key]) || ! is_scalar($geometry[$key]) ) {
                continue;
            }

            $candidate = trim(preg_replace('/\s+/', ' ', (string) $geometry[$key]) ?? '');
            if ( '' === $candidate ) {
                continue;
            }

            if ( 1 !== preg_match('/^[Mm][\s,]*-?\d/', $candidate) ) {
                continue;
            }
            if ( 1 !== preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9,\.\-+\s]+$/', $candidate) ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $blobs
     */
    public function readCommandBlobBytes(mixed $commandsBlob, array $blobs): ?string
    {
        if ( is_array($commandsBlob) && isset($commandsBlob['bytes']) && is_scalar($commandsBlob['bytes']) ) {
            return (string) $commandsBlob['bytes'];
        }

        if ( is_numeric($commandsBlob) ) {
            $blob = $blobs[(int) $commandsBlob] ?? null;
            if ( is_array($blob) && isset($blob['bytes']) && is_scalar($blob['bytes']) ) {
                return (string) $blob['bytes'];
            }
            if ( is_scalar($blob) ) {
                return (string) $blob;
            }
        }

        if ( is_string($commandsBlob) ) {
            return $commandsBlob;
        }

        return null;
    }

    /**
     * @return array{status: 'path'|'empty'|'unsupported', path: ?string}
     */
    public function classifyVectorCommandBlob(string $bytes): array
    {
        $offset = 0;
        $length = strlen($bytes);
        $parts = array();
        $commandCount = 0;
        $pathBytes = 0;

        while ( $offset < $length ) {
            $opcode = ord($bytes[$offset]);
            $offset++;
            $commandCount++;
            if ( $commandCount > self::MAX_VECTOR_COMMAND_BLOB_COMMANDS ) {
                return array('status' => 'unsupported', 'path' => null);
            }

            if ( 0 === $opcode ) {
                if ( empty($parts) ) {
                    continue;
                }
                if ( ! $this->appendVectorPathPart($parts, $pathBytes, 'Z') ) {
                    return array('status' => 'unsupported', 'path' => null);
                }
                continue;
            }

            if ( 1 === $opcode || 2 === $opcode ) {
                $point = $this->readFloatPair($bytes, $offset);
                if ( null === $point ) {
                    return array('status' => 'unsupported', 'path' => null);
                }
                if ( ! $this->appendVectorPathPart($parts, $pathBytes, ( 1 === $opcode ? 'M ' : 'L ' ) . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1])) ) {
                    return array('status' => 'unsupported', 'path' => null);
                }
                $offset += 8;
                continue;
            }

            if ( 3 === $opcode ) {
                $points = array();
                for ( $i = 0; $i < 2; $i++ ) {
                    $point = $this->readFloatPair($bytes, $offset + ( $i * 8 ));
                    if ( null === $point ) {
                        return array('status' => 'unsupported', 'path' => null);
                    }
                    $points[] = $point;
                }
                if ( ! $this->appendVectorPathPart($parts, $pathBytes, 'Q ' . $this->svgNumber($points[0][0]) . ' ' . $this->svgNumber($points[0][1]) . ' ' . $this->svgNumber($points[1][0]) . ' ' . $this->svgNumber($points[1][1])) ) {
                    return array('status' => 'unsupported', 'path' => null);
                }
                $offset += 16;
                continue;
            }

            if ( 4 === $opcode ) {
                $points = array();
                for ( $i = 0; $i < 3; $i++ ) {
                    $point = $this->readFloatPair($bytes, $offset + ( $i * 8 ));
                    if ( null === $point ) {
                        return array('status' => 'unsupported', 'path' => null);
                    }
                    $points[] = $point;
                }
                if ( ! $this->appendVectorPathPart($parts, $pathBytes, 'C ' . $this->svgNumber($points[0][0]) . ' ' . $this->svgNumber($points[0][1]) . ' ' . $this->svgNumber($points[1][0]) . ' ' . $this->svgNumber($points[1][1]) . ' ' . $this->svgNumber($points[2][0]) . ' ' . $this->svgNumber($points[2][1])) ) {
                    return array('status' => 'unsupported', 'path' => null);
                }
                $offset += 24;
                continue;
            }

            return array('status' => 'unsupported', 'path' => null);
        }

        return empty($parts)
            ? array('status' => 'empty', 'path' => null)
            : array('status' => 'path', 'path' => implode(' ', $parts));
    }

    /**
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>|null
     */
    private function normalizeVectorCommandBlob(mixed $blobReference, array $blobs, string $nodeId, string $source, array &$diagnostics): ?array
    {
        $bytes = $this->readCommandBlobBytes($blobReference, $blobs);
        if ( null === $bytes ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'figma_vector_command_blob_missing',
                'message'  => 'Figma vector command blob reference could not be resolved.',
                'context'  => array('node_id' => $nodeId, 'geometry' => $source, 'blob_ref' => is_scalar($blobReference) ? (string) $blobReference : null),
            );
            return null;
        }

        $path = $this->decodeVectorCommandBlob($bytes);
        if ( null === $path ) {
            $isVectorNetworkBlob = 'vectorData.vectorNetworkBlob' === $source;
            $context = array('node_id' => $nodeId, 'geometry' => $source);
            if ( $isVectorNetworkBlob ) {
                $context += $this->vectorNetworkBlobDiagnosticContext($blobReference, $bytes);
            }
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => $isVectorNetworkBlob ? 'unsupported_vector_network_blob' : 'unsupported_vector_command_blob',
                'message'  => $isVectorNetworkBlob ? 'Unsupported Figma vector network blob was omitted from SVG output.' : 'Unsupported Figma vector command blob was omitted from SVG output.',
                'context'  => $context,
            );
            return null;
        }

        return array('data' => $path, 'source' => $source);
    }

    /**
     * @param array<int|string, mixed>         $blobs
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, mixed>|null
     */
    private function normalizeVectorNetworkBlob(mixed $blobReference, array $node, array $blobs, string $nodeId, array &$diagnostics): ?array
    {
        $bytes = $this->readCommandBlobBytes($blobReference, $blobs);
        if ( null === $bytes ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'figma_vector_command_blob_missing',
                'message'  => 'Figma vector command blob reference could not be resolved.',
                'context'  => array('node_id' => $nodeId, 'geometry' => 'vectorData.vectorNetworkBlob', 'blob_ref' => is_scalar($blobReference) ? (string) $blobReference : null),
            );
            return null;
        }

        $path = $this->decodeSimpleChevronVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeSingleClosedLoopVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.singleClosedLoop', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeSimpleRectVectorNetworkBlob($bytes, $node);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.simpleRectFallback', 'windingRule' => 'NONZERO');
        }

        $path = $this->decodeClosedRectVectorNetworkBlob($bytes);
        if ( null !== $path ) {
            return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob.closedRectFallback', 'windingRule' => 'NONZERO');
        }

        $general = $this->decodeGeneralVectorNetworkBlob($bytes);
        if ( null !== $general ) {
            return $general;
        }

        if ( ! $this->looksLikeVectorNetworkBlob($bytes) ) {
            $path = $this->decodeVectorCommandBlob($bytes);
            if ( null !== $path ) {
                return array('data' => $path, 'source' => 'vectorData.vectorNetworkBlob');
            }
        }

        $diagnostics[] = array(
            'severity' => 'warning',
            'code'     => 'unsupported_vector_network_blob',
            'message'  => 'Unsupported Figma vector network blob was omitted from SVG output.',
            'context'  => array('node_id' => $nodeId, 'geometry' => 'vectorData.vectorNetworkBlob') + $this->vectorNetworkBlobDiagnosticContext($blobReference, $bytes, $node),
        );
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeGeneralVectorNetworkBlob(string $bytes): ?array
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return null;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 2 || $vertexCount > 20000 || $segmentCount < 1 || $segmentCount > 40000 || $regionCount < 1 || $regionCount > 20000 ) {
            return null;
        }

        $vertexBytes = $vertexCount * 20;
        if ( strlen($bytes) < 12 + $vertexBytes ) {
            return null;
        }

        $vertices = array();
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $point = $this->readFloatPair($bytes, 12 + ( $index * 20 ) + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $vertices[] = $point;
        }

        $segmentOffset = 12 + $vertexBytes;
        foreach ( array(24, 16, 8) as $stride ) {
            $decoded = $this->decodeVectorNetworkWithSegmentStride($bytes, $vertices, $segmentOffset, $segmentCount, $regionCount, $stride);
            if ( null !== $decoded ) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $vertices
     * @return array<string, mixed>|null
     */
    private function decodeVectorNetworkWithSegmentStride(string $bytes, array $vertices, int $segmentOffset, int $segmentCount, int $regionCount, int $stride): ?array
    {
        $vertexCount = count($vertices);
        $regionOffset = $segmentOffset + ( $segmentCount * $stride );
        if ( strlen($bytes) < $regionOffset ) {
            return null;
        }

        $segments = array();
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $base = $segmentOffset + ( $index * $stride );
            $start = $this->readUint32($bytes, $base);
            if ( 24 === $stride ) {
                $tangentStart = $this->readFloatPair($bytes, $base + 4);
                $end = $this->readUint32($bytes, $base + 12);
                $tangentEnd = $this->readFloatPair($bytes, $base + 16);
            } elseif ( 8 === $stride ) {
                $end = $this->readUint32($bytes, $base + 4);
                $tangentStart = array(0.0, 0.0);
                $tangentEnd = array(0.0, 0.0);
            } else {
                $tangentStart = array(0.0, 0.0);
                $end = $this->readUint32($bytes, $base + 4);
                $tangentEnd = array(0.0, 0.0);
            }

            if ( null === $start || null === $end || null === $tangentStart || null === $tangentEnd ) {
                return null;
            }
            if ( $start < 0 || $start >= $vertexCount || $end < 0 || $end >= $vertexCount || $start === $end ) {
                return null;
            }
            if ( ! is_finite($tangentStart[0]) || ! is_finite($tangentStart[1]) || ! is_finite($tangentEnd[0]) || ! is_finite($tangentEnd[1]) ) {
                return null;
            }

            $segments[] = array('start' => $start, 'end' => $end, 'tangentStart' => $tangentStart, 'tangentEnd' => $tangentEnd);
        }

        $offset = $regionOffset;
        $subpaths = array();
        $windingRule = 'NONZERO';
        for ( $region = 0; $region < $regionCount; $region++ ) {
            $entryCount = $this->readUint32($bytes, $offset);
            $rule = $this->readUint32($bytes, $offset + 4);
            $reserved = $this->readUint32($bytes, $offset + 8);
            if ( null === $entryCount || null === $rule || null === $reserved ) {
                return null;
            }
            if ( $entryCount < 1 || $entryCount > $segmentCount || ( 0 !== $rule && 1 !== $rule ) ) {
                return null;
            }
            $offset += 12;
            if ( strlen($bytes) < $offset + ( $entryCount * 8 ) ) {
                return null;
            }

            $entries = array();
            for ( $entry = 0; $entry < $entryCount; $entry++ ) {
                $segmentIndex = $this->readUint32($bytes, $offset);
                $direction = $this->readUint32($bytes, $offset + 4);
                $offset += 8;
                if ( null === $segmentIndex || null === $direction || $segmentIndex < 0 || $segmentIndex >= $segmentCount || ( 0 !== $direction && 1 !== $direction ) ) {
                    return null;
                }
                $entries[] = array($segmentIndex, $direction);
            }

            $subpath = $this->vectorNetworkRegionSubpath($vertices, $segments, $entries);
            if ( null === $subpath ) {
                return null;
            }
            $subpaths[] = $subpath;
            if ( 1 === $rule ) {
                $windingRule = 'EVENODD';
            }
        }

        if ( $offset !== strlen($bytes) || empty($subpaths) ) {
            return null;
        }

        return array(
            'data'        => implode(' ', $subpaths),
            'source'      => 'vectorData.vectorNetworkBlob.network',
            'windingRule' => $windingRule,
        );
    }

    /**
     * @param array<int, array{0: float, 1: float}> $vertices
     * @param array<int, array{start: int, end: int, tangentStart: array{0: float, 1: float}, tangentEnd: array{0: float, 1: float}}> $segments
     * @param array<int, array{0: int, 1: int}> $entries
     */
    private function vectorNetworkRegionSubpath(array $vertices, array $segments, array $entries): ?string
    {
        $first = null;
        $cursor = null;
        $parts = array();

        foreach ( $entries as $entry ) {
            [$segmentIndex, $direction] = $entry;
            $segment = $segments[$segmentIndex];
            $start = $segment['start'];
            $end = $segment['end'];
            $tangentStart = $segment['tangentStart'];
            $tangentEnd = $segment['tangentEnd'];
            if ( 1 === $direction ) {
                [$start, $end] = array($end, $start);
                [$tangentStart, $tangentEnd] = array($tangentEnd, $tangentStart);
            }

            if ( null === $first ) {
                $first = $start;
                $cursor = $start;
                $point = $vertices[$start];
                $parts[] = 'M ' . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
            } elseif ( $start !== $cursor ) {
                return null;
            }

            $from = $vertices[$start];
            $to = $vertices[$end];
            $hasCurve = abs($tangentStart[0]) > 0.000001 || abs($tangentStart[1]) > 0.000001 || abs($tangentEnd[0]) > 0.000001 || abs($tangentEnd[1]) > 0.000001;
            if ( $hasCurve ) {
                $parts[] = 'C ' . $this->svgNumber($from[0] + $tangentStart[0]) . ' ' . $this->svgNumber($from[1] + $tangentStart[1])
                    . ' ' . $this->svgNumber($to[0] + $tangentEnd[0]) . ' ' . $this->svgNumber($to[1] + $tangentEnd[1])
                    . ' ' . $this->svgNumber($to[0]) . ' ' . $this->svgNumber($to[1]);
            } else {
                $parts[] = 'L ' . $this->svgNumber($to[0]) . ' ' . $this->svgNumber($to[1]);
            }
            $cursor = $end;
        }

        if ( null === $first || count($parts) < 3 || $cursor !== $first ) {
            return null;
        }

        return implode(' ', $parts) . ' Z';
    }

    private function decodeSimpleChevronVectorNetworkBlob(string $bytes): ?string
    {
        if ( 288 !== strlen($bytes) ) {
            return null;
        }

        $counts = $this->vectorNetworkCounts($bytes);
        if ( array(6, 6, 1) !== $counts ) {
            return null;
        }

        $signature = bin2hex(substr($bytes, 0, 32));
        return match ( $signature ) {
            '0600000006000000010000000000000000000041000080410000000000000000' => 'M 8 16 L 0 8 L 8 0 L 9.414 1.414 L 2.828 8 L 9.414 14.586 L 8 16 Z',
            '06000000060000000100000000000000f4fdb43f0000804100000000be9f1641' => 'M 1.414 16 L 9.414 8 L 1.414 0 L 0 1.414 L 6.586 8 L 0 14.586 L 1.414 16 Z',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function decodeSimpleRectVectorNetworkBlob(string $bytes, array $node): ?string
    {
        if ( 172 !== strlen($bytes) ) {
            return null;
        }

        if ( array(4, 4, 0) !== $this->vectorNetworkCounts($bytes) ) {
            return null;
        }

        $signature = bin2hex(substr($bytes, 0, 32));
        if ( '0400000004000000000000000000000000000000000000000000000000008043' !== $signature ) {
            return null;
        }

        $width = $this->rawNodeDimension($node, 'width');
        $height = $this->rawNodeDimension($node, 'height');
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return null;
        }

        return 'M 0 0 L ' . $this->svgNumber($width) . ' 0 L ' . $this->svgNumber($width) . ' ' . $this->svgNumber($height) . ' L 0 ' . $this->svgNumber($height) . ' Z';
    }

    private function decodeClosedRectVectorNetworkBlob(string $bytes): ?string
    {
        if ( 200 !== strlen($bytes) ) {
            return null;
        }

        if ( array(4, 4, 1) !== $this->vectorNetworkCounts($bytes) ) {
            return null;
        }

        $points = $this->closedRectVectorNetworkPoints($bytes);
        if ( null === $points ) {
            return null;
        }

        $xs = array_values(array_unique(array_map(static fn (array $point): string => sprintf('%.6F', $point[0]), $points)));
        $ys = array_values(array_unique(array_map(static fn (array $point): string => sprintf('%.6F', $point[1]), $points)));
        if ( 2 !== count($xs) || 2 !== count($ys) ) {
            return null;
        }

        $minX = min(array_map('floatval', $xs));
        $maxX = max(array_map('floatval', $xs));
        $minY = min(array_map('floatval', $ys));
        $maxY = max(array_map('floatval', $ys));
        if ( $maxX <= $minX || $maxY <= $minY ) {
            return null;
        }

        return 'M ' . $this->svgNumber($minX) . ' ' . $this->svgNumber($minY)
            . ' L ' . $this->svgNumber($maxX) . ' ' . $this->svgNumber($minY)
            . ' L ' . $this->svgNumber($maxX) . ' ' . $this->svgNumber($maxY)
            . ' L ' . $this->svgNumber($minX) . ' ' . $this->svgNumber($maxY)
            . ' Z';
    }

    private function decodeSingleClosedLoopVectorNetworkBlob(string $bytes): ?string
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return null;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 3 || $vertexCount > 32 || $vertexCount !== $segmentCount || 1 !== $regionCount ) {
            return null;
        }

        if ( strlen($bytes) !== 24 + ( $vertexCount * 44 ) ) {
            return null;
        }

        $vertices = array();
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $vertexOffset = 12 + ( $index * 20 );
            if ( ! $this->bytesAreZero($bytes, $vertexOffset, 4) || ! $this->bytesAreZero($bytes, $vertexOffset + 12, 8) ) {
                return null;
            }

            $point = $this->readFloatPair($bytes, $vertexOffset + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $vertices[] = $point;
        }

        $segments = array();
        $degree = array_fill(0, $vertexCount, 0);
        $segmentOffset = 12 + ( $vertexCount * 20 );
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $currentSegmentOffset = $segmentOffset + ( $index * 16 );
            if ( ! $this->bytesAreZero($bytes, $currentSegmentOffset + 8, 8) ) {
                return null;
            }

            $start = $this->readUint32($bytes, $currentSegmentOffset);
            $end = $this->readUint32($bytes, $currentSegmentOffset + 4);
            if ( null === $start || null === $end || $start < 0 || $start >= $vertexCount || $end < 0 || $end >= $vertexCount || $start === $end ) {
                return null;
            }
            $segments[] = array($start, $end);
            $degree[$start]++;
            $degree[$end]++;
        }

        foreach ( $degree as $vertexDegree ) {
            if ( 2 !== $vertexDegree ) {
                return null;
            }
        }

        $regionOffset = $segmentOffset + ( $segmentCount * 16 );
        $regionSegmentCount = $this->readUint32($bytes, $regionOffset);
        if ( $segmentCount !== $regionSegmentCount || ! $this->bytesAreZero($bytes, $regionOffset + 4, 8) ) {
            return null;
        }

        $orderedVertexIndexes = array();
        $usedSegments = array();
        for ( $index = 0; $index < $segmentCount; $index++ ) {
            $entryOffset = $regionOffset + 12 + ( $index * 8 );
            $segmentIndex = $this->readUint32($bytes, $entryOffset);
            $direction = $this->readUint32($bytes, $entryOffset + 4);
            if ( null === $segmentIndex || null === $direction || $segmentIndex < 0 || $segmentIndex >= $segmentCount || isset($usedSegments[$segmentIndex]) || ( 0 !== $direction && 1 !== $direction ) ) {
                return null;
            }

            $usedSegments[$segmentIndex] = true;
            [$start, $end] = $segments[$segmentIndex];
            if ( 1 === $direction ) {
                [$start, $end] = array($end, $start);
            }

            if ( 0 === $index ) {
                $orderedVertexIndexes[] = $start;
                $orderedVertexIndexes[] = $end;
                continue;
            }

            if ( $orderedVertexIndexes[count($orderedVertexIndexes) - 1] !== $start ) {
                return null;
            }
            $orderedVertexIndexes[] = $end;
        }

        if ( count($orderedVertexIndexes) !== $vertexCount + 1 || $orderedVertexIndexes[0] !== $orderedVertexIndexes[count($orderedVertexIndexes) - 1] || count(array_unique(array_slice($orderedVertexIndexes, 0, -1))) !== $vertexCount ) {
            return null;
        }

        $windingArea = 0.0;
        for ( $index = 0; $index < $vertexCount; $index++ ) {
            $current = $vertices[$orderedVertexIndexes[$index]];
            $next = $vertices[$orderedVertexIndexes[$index + 1]];
            $windingArea += ( $current[0] * $next[1] ) - ( $next[0] * $current[1] );
        }
        if ( abs($windingArea) < 0.000001 ) {
            return null;
        }

        $parts = array();
        foreach ( array_slice($orderedVertexIndexes, 0, -1) as $index => $vertexIndex ) {
            $point = $vertices[$vertexIndex];
            $parts[] = ( 0 === $index ? 'M ' : 'L ' ) . $this->svgNumber($point[0]) . ' ' . $this->svgNumber($point[1]);
        }

        return implode(' ', $parts) . ' Z';
    }

    /**
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function closedRectVectorNetworkPoints(string $bytes): ?array
    {
        $points = array();
        for ( $index = 0; $index < 4; $index++ ) {
            $offset = 12 + ( $index * 20 );
            $point = $this->readFloatPair($bytes, $offset + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return null;
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rawNodeDimension(array $node, string $dimension): float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
            return (float) $box[$dimension];
        }

        if ( isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
            return (float) $node[$dimension];
        }

        $sizeKey = 'width' === $dimension ? 'x' : 'y';
        if ( is_array($node['size'] ?? null) && isset($node['size'][$sizeKey]) && is_numeric($node['size'][$sizeKey]) ) {
            return (float) $node['size'][$sizeKey];
        }

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $boundsKey ) {
            if ( is_array($node[$boundsKey] ?? null) && isset($node[$boundsKey][$dimension]) && is_numeric($node[$boundsKey][$dimension]) ) {
                return (float) $node[$boundsKey][$dimension];
            }
        }

        return 0.0;
    }

    private function looksLikeVectorNetworkBlob(string $bytes): bool
    {
        $counts = $this->vectorNetworkCounts($bytes);
        if ( null === $counts ) {
            return false;
        }

        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 1 || $vertexCount > 100000 || $segmentCount < 0 || $segmentCount > 200000 || $regionCount < 0 || $regionCount > 100000 ) {
            return false;
        }

        return $regionCount <= max(1, $segmentCount) && $segmentCount <= max(4, $vertexCount * 8);
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private function vectorNetworkCounts(string $bytes): ?array
    {
        if ( strlen($bytes) < 12 ) {
            return null;
        }

        $counts = unpack('V3', substr($bytes, 0, 12));
        return false === $counts ? null : array_values(array_map('intval', $counts));
    }

    /**
     * @return array<string, mixed>
     */
    private function vectorNetworkBlobDiagnosticContext(mixed $blobReference, string $bytes, ?array $node = null): array
    {
        $context = array(
            'blob_ref'      => is_scalar($blobReference) ? (string) $blobReference : null,
            'byte_length'   => strlen($bytes),
            'signature_hex' => bin2hex(substr($bytes, 0, 32)),
        );

        $counts = $this->vectorNetworkCounts($bytes);
        if ( null !== $counts ) {
            $context['network_counts'] = $counts;
            $context['vector_network_blob_kind'] = $this->classifyVectorNetworkBlobKind($bytes, $counts);
            $decoderBlocker = $this->vectorNetworkBlobDecoderBlocker($bytes, $counts);
            if ( null !== $decoderBlocker ) {
                $context['decoder_blocker'] = $decoderBlocker;
            }
            $context += $this->vectorNetworkSingleRegionCandidateContext($bytes, $counts);
        } else {
            $context['vector_network_blob_kind'] = 'unknown_binary_blob';
            $context['decoder_blocker'] = 'missing_vector_network_counts';
        }

        if ( null !== $node ) {
            $context['render_risk'] = $this->unsupportedVectorNetworkRenderRisk($node);
            $context['render_risk_node'] = $this->unsupportedVectorNetworkRenderRiskNode($node);
        }

        return $context;
    }

    /**
     * @param array{0:int,1:int,2:int} $counts
     */
    private function classifyVectorNetworkBlobKind(string $bytes, array $counts): string
    {
        [$vertexCount, $segmentCount, $regionCount] = $counts;
        $length = strlen($bytes);
        if ( $vertexCount < 1 || $segmentCount < 0 || $regionCount < 0 ) {
            return 'invalid_counts';
        }
        if ( $vertexCount === $segmentCount && 1 === $regionCount && $length === 24 + ( $vertexCount * 44 ) ) {
            return 'single_region_equal_count_44_byte_loop';
        }
        if ( $length === 12 + ( $vertexCount * 20 ) + ( $segmentCount * 8 ) + ( $regionCount * 12 ) + ( $segmentCount * 8 ) ) {
            return 'compact_segment_network';
        }
        if ( $length === 12 + ( $vertexCount * 20 ) + ( $segmentCount * 24 ) + ( $regionCount * 12 ) + ( $segmentCount * 8 ) ) {
            return 'tangent_segment_network';
        }
        if ( 0 === $regionCount ) {
            return 'regionless_network';
        }

        return 'unclassified_vector_network_blob';
    }

    /**
     * @param array{0:int,1:int,2:int} $counts
     */
    private function vectorNetworkBlobDecoderBlocker(string $bytes, array $counts): ?string
    {
        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 2 || $segmentCount < 1 || $regionCount < 1 ) {
            return 'insufficient_network_topology';
        }

        $vertexBytes = $vertexCount * 20;
        if ( strlen($bytes) < 12 + $vertexBytes ) {
            return 'truncated_vertex_table';
        }

        $segmentOffset = 12 + $vertexBytes;
        foreach ( array(24, 16, 8) as $stride ) {
            $regionOffset = $segmentOffset + ( $segmentCount * $stride );
            if ( strlen($bytes) < $regionOffset ) {
                continue;
            }
            $validSegmentTable = true;
            for ( $index = 0; $index < $segmentCount; $index++ ) {
                $base = $segmentOffset + ( $index * $stride );
                $start = $this->readUint32($bytes, $base);
                $end = $this->readUint32($bytes, 24 === $stride ? $base + 12 : $base + 4);
                if ( null === $start || null === $end || $start < 0 || $start >= $vertexCount || $end < 0 || $end >= $vertexCount || $start === $end ) {
                    $validSegmentTable = false;
                    break;
                }
            }
            if ( ! $validSegmentTable ) {
                continue;
            }

            $entryCount = $this->readUint32($bytes, $regionOffset);
            $rule = $this->readUint32($bytes, $regionOffset + 4);
            if ( null === $entryCount || null === $rule || $entryCount < 1 || $entryCount > $segmentCount || ( 0 !== $rule && 1 !== $rule ) ) {
                return 'region_header_not_valid_for_known_layout';
            }

            return 'region_entries_do_not_form_closed_loop';
        }

        return 'segment_endpoints_not_valid_for_known_layout';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function unsupportedVectorNetworkRenderRisk(array $node): string
    {
        $width = $this->rawNodeDimension($node, 'width');
        $height = $this->rawNodeDimension($node, 'height');
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return 'low';
        }
        if ( ! empty($node['children']) && is_array($node['children']) ) {
            return 'medium';
        }

        return $this->nodeHasVisiblePaint($node) ? 'high' : 'medium';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function unsupportedVectorNetworkRenderRiskNode(array $node): array
    {
        return array(
            'node_id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '',
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '',
            'type' => isset($node['type']) && is_scalar($node['type']) ? (string) $node['type'] : '',
            'width' => $this->rawNodeDimension($node, 'width'),
            'height' => $this->rawNodeDimension($node, 'height'),
            'painted' => $this->nodeHasVisiblePaint($node),
            'has_children' => ! empty($node['children']) && is_array($node['children']),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasVisiblePaint(array $node): bool
    {
        foreach ( array('fillPaints', 'fills', 'strokePaints', 'strokes') as $paintKey ) {
            if ( ! is_array($node[$paintKey] ?? null) ) {
                continue;
            }
            foreach ( $node[$paintKey] as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }
                if ( false === ($paint['visible'] ?? true) ) {
                    continue;
                }
                $opacity = isset($paint['opacity']) && is_numeric($paint['opacity']) ? (float) $paint['opacity'] : 1.0;
                if ( $opacity > 0.0 ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{0:int,1:int,2:int} $counts
     * @return array<string, mixed>
     */
    private function vectorNetworkSingleRegionCandidateContext(string $bytes, array $counts): array
    {
        [$vertexCount, $segmentCount, $regionCount] = $counts;
        if ( $vertexCount < 3 || $vertexCount > 32 || $vertexCount !== $segmentCount || 1 !== $regionCount ) {
            return array();
        }

        $expectedLength = 24 + ( $vertexCount * 44 );
        if ( strlen($bytes) !== $expectedLength ) {
            return array();
        }

        return array(
            'single_region_loop_candidate' => true,
            'candidate_layout' => array(
                'vertex_stride' => 20,
                'segment_stride' => 16,
                'region_bytes'  => 12 + ( $vertexCount * 8 ),
            ),
            'candidate_vertex_points_sample' => $this->vectorNetworkVertexPointSample($bytes, $vertexCount),
            'candidate_decoder_requirement' => 'Decode only after segment endpoints and region winding/order are validated as one closed non-branching loop.',
        );
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function vectorNetworkVertexPointSample(string $bytes, int $vertexCount): array
    {
        $points = array();
        $limit = min($vertexCount, 8);
        for ( $index = 0; $index < $limit; $index++ ) {
            $point = $this->readFloatPair($bytes, 12 + ( $index * 20 ) + 4);
            if ( null === $point || ! is_finite($point[0]) || ! is_finite($point[1]) ) {
                return array();
            }
            $points[] = array($point[0], $point[1]);
        }

        return $points;
    }

    private function decodeVectorCommandBlob(string $bytes): ?string
    {
        return $this->classifyVectorCommandBlob($bytes)['path'];
    }

    /**
     * @param array<int, string> $parts
     */
    private function appendVectorPathPart(array &$parts, int &$pathBytes, string $part): bool
    {
        $pathBytes += strlen($part) + ( empty($parts) ? 0 : 1 );
        if ( $pathBytes > self::MAX_VECTOR_COMMAND_BLOB_PATH_BYTES ) {
            return false;
        }

        $parts[] = $part;
        return true;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function readFloatPair(string $bytes, int $offset): ?array
    {
        if ( strlen($bytes) < $offset + 8 ) {
            return null;
        }

        $x = unpack('g', substr($bytes, $offset, 4));
        $y = unpack('g', substr($bytes, $offset + 4, 4));
        if ( false === $x || false === $y ) {
            return null;
        }

        return array((float) $x[1], (float) $y[1]);
    }

    private function bytesAreZero(string $bytes, int $offset, int $length): bool
    {
        if ( strlen($bytes) < $offset + $length ) {
            return false;
        }

        return str_repeat("\0", $length) === substr($bytes, $offset, $length);
    }

    private function readUint32(string $bytes, int $offset): ?int
    {
        if ( strlen($bytes) < $offset + 4 ) {
            return null;
        }

        $value = unpack('V', substr($bytes, $offset, 4));
        return false === $value ? null : (int) $value[1];
    }

    private function readPointCoordinate(array $point, string|int $key): ?float
    {
        return isset($point[$key]) && is_numeric($point[$key]) ? (float) $point[$key] : null;
    }

    private function readIndex(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function readTangent(mixed $value): array
    {
        if ( ! is_array($value) ) {
            return array(0.0, 0.0);
        }

        $x = $this->readPointCoordinate($value, 'x') ?? $this->readPointCoordinate($value, 0) ?? 0.0;
        $y = $this->readPointCoordinate($value, 'y') ?? $this->readPointCoordinate($value, 1) ?? 0.0;
        return is_finite($x) && is_finite($y) ? array($x, $y) : array(0.0, 0.0);
    }

    private function readStyleId(mixed $value): ?string
    {
        if ( is_scalar($value) && '' !== trim((string) $value) ) {
            return (string) $value;
        }

        if ( ! is_array($value) ) {
            return null;
        }

        if ( is_array($value['guid'] ?? null) ) {
            $value = $value['guid'];
        }
        $session = $value['sessionID'] ?? null;
        $local = $value['localID'] ?? null;
        if ( is_scalar($session) && is_scalar($local) ) {
            return (string) $session . ':' . (string) $local;
        }

        return is_scalar($local) ? (string) $local : null;
    }

    private function svgNumber(float $value): string
    {
        $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        return '' === $number || '-0' === $number ? '0' : $number;
    }
}
