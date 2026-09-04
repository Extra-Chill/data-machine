<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Resolves Figma image/gradient paint stacks into CSS background declarations.
 */
final class PaintStackResolver
{
    /**
     * @param callable(array<string, mixed>): ?string $resolveAndMarkPaintAssetPath
     * @param callable(float): string $numberFormatter
     * @param callable(mixed, mixed=): ?string $color
     */
    public function __construct(
        private readonly mixed $resolveAndMarkPaintAssetPath,
        private readonly mixed $numberFormatter,
        private readonly mixed $color,
    ) {
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $fallbackAssetPaths
     * @return array<int, string>
     */
    public function composedImageBackgroundStyles(array $node, array $fallbackAssetPaths): array
    {
        $styles = array();
        $imageLayers = $this->nodeImagePaintLayers($node);
        if ( empty($imageLayers) ) {
            if ( empty($fallbackAssetPaths) ) {
                return array();
            }

            $styles[] = 'background-image:' . $this->cssUrlList($fallbackAssetPaths);
            $styles[] = 'background-size:cover';
            $styles[] = 'background-position:center';
            return $styles;
        }

        $backgroundLayers = $this->nodeComposedBackgroundLayers($node, $imageLayers);
        $styles[] = 'background-image:' . implode(',', array_map(static fn (array $layer): string => (string) $layer['css'], $backgroundLayers));
        $blendModes = $this->composedBackgroundBlendModes($backgroundLayers);
        if ( ! empty($blendModes) ) {
            $styles[] = 'background-blend-mode:' . implode(',', $blendModes);
        }
        foreach ( $this->composedBackgroundLayerStyles($node, $backgroundLayers) as $style ) {
            $styles[] = $style;
        }

        return $styles;
    }

    /**
     * Return resolved image paint layers ordered top->bottom. Duplicate asset
     * paths are intentionally preserved because Figma can stack the same image
     * with different crops, opacity, or blend modes.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{path: string, paint: array<string, mixed>}>
     */
    public function nodeImagePaintLayers(array $node): array
    {
        $paths = array();

        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = $this->paintCollections($node, $paintKey);
            foreach ( $paintCollections as $paints ) {
                // Figma stores fills bottom->top; reverse so topmost is first
                // (CSS background-image: first url = topmost layer).
                $orderedPaints = array_reverse(array_values($paints));
                foreach ( $orderedPaints as $paint ) {
                    if ( ! $this->isVisibleImagePaint($paint) ) {
                        continue;
                    }

                    $path = $this->resolveAndMarkPaintAssetPath($paint);
                    if ( null === $path ) {
                        continue;
                    }
                    $paths[] = array('path' => $path, 'paint' => $paint);
                }
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{path: string, paint: array<string, mixed>}> $fallbackImageLayers
     * @return array<int, array{type: string, css: string, paint: array<string, mixed>}>
     */
    public function nodeComposedBackgroundLayers(array $node, array $fallbackImageLayers): array
    {
        $layers = array();
        foreach ( array('fills', 'background') as $paintKey ) {
            foreach ( $this->paintCollections($node, $paintKey) as $paints ) {
                foreach ( array_reverse(array_values($paints)) as $paint ) {
                    if ( ! is_array($paint) || false === ($paint['visible'] ?? true) ) {
                        continue;
                    }

                    if ( 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                        $path = $this->resolveAndMarkPaintAssetPath($paint);
                        if ( null !== $path ) {
                            $layers[] = array('type' => 'image', 'css' => 'url("' . $path . '")', 'paint' => $paint);
                        }
                        continue;
                    }

                    if ( in_array(($paint['type'] ?? null), array('GRADIENT_LINEAR', 'GRADIENT_RADIAL', 'GRADIENT_ANGULAR'), true) ) {
                        $gradient = $this->gradientPaint($paint);
                        if ( null !== $gradient ) {
                            $layers[] = array('type' => 'gradient', 'css' => $gradient, 'paint' => $paint);
                        }
                    }
                }
            }
        }

        if ( ! empty($layers) ) {
            return $layers;
        }

        return array_map(static fn (array $layer): array => array(
            'type'  => 'image',
            'css'   => 'url("' . (string) $layer['path'] . '")',
            'paint' => is_array($layer['paint'] ?? null) ? $layer['paint'] : array(),
        ), $fallbackImageLayers);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{type: string, css: string, paint: array<string, mixed>}> $layers
     * @return array<int, string>
     */
    public function composedBackgroundLayerStyles(array $node, array $layers): array
    {
        $sizes = array();
        $repeats = array();
        $positions = array();
        foreach ( $layers as $layer ) {
            if ( 'image' !== ($layer['type'] ?? null) ) {
                $sizes[] = '100% 100%';
                $repeats[] = 'no-repeat';
                $positions[] = 'center';
                continue;
            }

            $paint = is_array($layer['paint'] ?? null) ? $layer['paint'] : array();
            $layerStyles = $this->imagePaintLayerBackgroundStyles($node, $paint, $this->imagePaintScaleMode($paint));
            $sizes[] = $layerStyles['size'];
            $repeats[] = $layerStyles['repeat'];
            $positions[] = $layerStyles['position'];
        }

        if ( empty($sizes) ) {
            return array();
        }

        if ( array('cover') === array_values(array_unique($sizes)) && array('no-repeat') === array_values(array_unique($repeats)) && array('center') === array_values(array_unique($positions)) ) {
            return array('background-size:cover', 'background-position:center');
        }

        return array(
            'background-size:' . implode(',', $sizes),
            'background-repeat:' . implode(',', $repeats),
            'background-position:' . implode(',', $positions),
        );
    }

    /**
     * @param array<int, array{type: string, css: string, paint: array<string, mixed>}> $layers
     * @return array<int, string>
     */
    public function composedBackgroundBlendModes(array $layers): array
    {
        $blendModes = array();
        foreach ( $layers as $layer ) {
            if ( 'image' !== ($layer['type'] ?? null) ) {
                $blendModes[] = 'normal';
                continue;
            }

            $paint = is_array($layer['paint'] ?? null) ? $layer['paint'] : array();
            $blendMode = null;
            if ( isset($paint['blendMode']) && is_scalar($paint['blendMode']) ) {
                $blendMode = $this->blendModeCss((string) $paint['blendMode']);
            }
            $blendModes[] = $blendMode ?? 'normal';
        }

        return in_array(true, array_map(static fn (string $mode): bool => 'normal' !== $mode, $blendModes), true) ? $blendModes : array();
    }

    /**
     * @param array<int, mixed> $paints
     * @return array{css: string, gradient: bool}|null
     */
    public function firstCssPaint(array $paints): ?array
    {
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) ) {
                continue;
            }

            if ( 'SOLID' === ($paint['type'] ?? null) ) {
                $color = $this->color($paint['color'] ?? null, $paint['opacity'] ?? null);
                if ( null !== $color ) {
                    return array('css' => $color, 'gradient' => false);
                }
            }

            if ( in_array(($paint['type'] ?? null), array('GRADIENT_LINEAR', 'GRADIENT_RADIAL', 'GRADIENT_ANGULAR'), true) ) {
                $gradient = $this->gradientPaint($paint);
                if ( null !== $gradient ) {
                    return array('css' => $gradient, 'gradient' => true);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $paint
     */
    public function imagePaintScaleMode(array $paint): string
    {
        foreach ( array('imageScaleMode', 'scaleMode') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                return strtoupper((string) $paint[$key]);
            }
        }

        return 'FILL';
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $paint
     * @return array{size: string, repeat: string, position: string}|array{}
     */
    public function imagePaintTransformStyles(array $node, array $paint): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : (is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array());
        $width = $box['width'] ?? $node['width'] ?? null;
        $height = $box['height'] ?? $node['height'] ?? null;
        if ( ! is_numeric($width) || ! is_numeric($height) || 0 >= (float) $width || 0 >= (float) $height ) {
            return array();
        }

        $matrix = $this->imagePaintTransformMatrix($paint);
        if ( null === $matrix || $this->isIdentityImageTransform($matrix) ) {
            $cropRect = $this->imagePaintCropRect($paint);
            if ( null === $cropRect ) {
                return array();
            }

            $backgroundWidth = (float) $width / $cropRect['width'];
            $backgroundHeight = (float) $height / $cropRect['height'];
            $backgroundX = -1 * $cropRect['x'] * $backgroundWidth;
            $backgroundY = -1 * $cropRect['y'] * $backgroundHeight;

            return array(
                'size'     => $this->number($backgroundWidth) . 'px ' . $this->number($backgroundHeight) . 'px',
                'repeat'   => 'no-repeat',
                'position' => $this->number($backgroundX) . 'px ' . $this->number($backgroundY) . 'px',
            );
        }

        if ( 0.00001 < abs($matrix['m01']) || 0.00001 < abs($matrix['m10']) || 0 >= $matrix['m00'] || 0 >= $matrix['m11'] ) {
            return array();
        }

        $backgroundWidth = (float) $width / $matrix['m00'];
        $backgroundHeight = (float) $height / $matrix['m11'];
        $backgroundX = -1 * $matrix['m02'] * $backgroundWidth;
        $backgroundY = -1 * $matrix['m12'] * $backgroundHeight;

        return array(
            'size'     => $this->number($backgroundWidth) . 'px ' . $this->number($backgroundHeight) . 'px',
            'repeat'   => 'no-repeat',
            'position' => $this->number($backgroundX) . 'px ' . $this->number($backgroundY) . 'px',
        );
    }

    /**
     * @param array<string, mixed> $paint
     */
    public function gradientPaint(array $paint): ?string
    {
        $stops = is_array($paint['stops'] ?? null) ? $paint['stops'] : array();
        if ( empty($stops) ) {
            return null;
        }

        $cssStops = array();
        foreach ( $stops as $stop ) {
            if ( ! is_array($stop) || ! isset($stop['position']) || ! is_numeric($stop['position']) ) {
                continue;
            }

            $opacity = $paint['opacity'] ?? null;
            $color = $stop['color'] ?? null;
            if ( is_numeric($opacity) && is_array($color) && isset($color['a']) && is_numeric($color['a']) ) {
                $opacity = (float) $opacity * (float) $color['a'];
            }

            $cssColor = $this->color($color, $opacity);
            if ( null === $cssColor ) {
                continue;
            }

            $cssStops[] = $cssColor . ' ' . $this->number((float) $stop['position'] * 100) . '%';
        }

        if ( empty($cssStops) ) {
            return null;
        }

        if ( 'GRADIENT_RADIAL' === ($paint['type'] ?? null) ) {
            return 'radial-gradient(circle,' . implode(',', $cssStops) . ')';
        }

        if ( 'GRADIENT_ANGULAR' === ($paint['type'] ?? null) ) {
            $geometry = $this->angularGradientGeometry($paint);

            return 'conic-gradient(from ' . $this->number($geometry['from']) . 'deg at '
                . $this->number($geometry['cx']) . '% ' . $this->number($geometry['cy']) . '%,'
                . implode(',', $cssStops) . ')';
        }

        return 'linear-gradient(' . $this->number($this->linearGradientAngle($paint)) . 'deg,' . implode(',', $cssStops) . ')';
    }

    private function resolveAndMarkPaintAssetPath(array $paint): ?string
    {
        return ($this->resolveAndMarkPaintAssetPath)($paint);
    }

    private function number(float $value): string
    {
        return ($this->numberFormatter)($value);
    }

    private function color(mixed $value, mixed $opacity = null): ?string
    {
        return ($this->color)($value, $opacity);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<int|string, mixed>>
     */
    private function paintCollections(array $node, string $paintKey): array
    {
        $paintCollections = array();
        if ( is_array($node[$paintKey] ?? null) ) {
            $paintCollections[] = $node[$paintKey];
        }
        if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
            $paintCollections[] = $node['figma_paints'][$paintKey];
        }

        return $paintCollections;
    }

    private function isVisibleImagePaint(mixed $paint): bool
    {
        return is_array($paint)
            && 'IMAGE' === strtoupper((string) ($paint['type'] ?? ''))
            && false !== ($paint['visible'] ?? true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $paint
     * @return array{size: string, repeat: string, position: string}
     */
    private function imagePaintLayerBackgroundStyles(array $node, array $paint, string $scaleMode): array
    {
        if ( 'TILE' !== $scaleMode ) {
            $transformStyles = $this->imagePaintTransformStyles($node, $paint);
            if ( ! empty($transformStyles) ) {
                return $transformStyles;
            }
        }

        if ( 'STRETCH' === $scaleMode ) {
            return array('size' => '100% 100%', 'repeat' => 'no-repeat', 'position' => 'center');
        }

        if ( 'TILE' === $scaleMode ) {
            return array('size' => 'auto', 'repeat' => 'repeat', 'position' => 'center');
        }

        return array('size' => 'cover', 'repeat' => 'no-repeat', 'position' => 'center');
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function imagePaintCropRect(array $paint): ?array
    {
        $cropRect = $paint['cropRect'] ?? null;
        if ( ! is_array($cropRect) ) {
            return null;
        }

        $width = $cropRect['width'] ?? $cropRect['w'] ?? null;
        $height = $cropRect['height'] ?? $cropRect['h'] ?? null;
        $x = $cropRect['x'] ?? 0;
        $y = $cropRect['y'] ?? 0;
        if ( ! is_numeric($x) || ! is_numeric($y) || ! is_numeric($width) || ! is_numeric($height) || 0 >= (float) $width || 0 >= (float) $height ) {
            return null;
        }

        return array(
            'x'      => (float) $x,
            'y'      => (float) $y,
            'width'  => (float) $width,
            'height' => (float) $height,
        );
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float}|null
     */
    private function imagePaintTransformMatrix(array $paint): ?array
    {
        $transform = $paint['transform'] ?? $paint['imageTransform'] ?? null;
        if ( ! is_array($transform) ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            $values = array(
                'm00' => $transform['m00'],
                'm01' => $transform['m01'],
                'm02' => $transform['m02'],
                'm10' => $transform['m10'],
                'm11' => $transform['m11'],
                'm12' => $transform['m12'],
            );
        } elseif ( is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array(
                'm00' => $transform[0][0] ?? null,
                'm01' => $transform[0][1] ?? null,
                'm02' => $transform[0][2] ?? null,
                'm10' => $transform[1][0] ?? null,
                'm11' => $transform[1][1] ?? null,
                'm12' => $transform[1][2] ?? null,
            );
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

    /**
     * @param array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float} $matrix
     */
    private function isIdentityImageTransform(array $matrix): bool
    {
        return 0.00001 > abs($matrix['m00'] - 1.0)
            && 0.00001 > abs($matrix['m01'])
            && 0.00001 > abs($matrix['m02'])
            && 0.00001 > abs($matrix['m10'])
            && 0.00001 > abs($matrix['m11'] - 1.0)
            && 0.00001 > abs($matrix['m12']);
    }

    /**
     * @param array<int, string> $paths
     */
    private function cssUrlList(array $paths): string
    {
        return implode(',', array_map(static fn (string $path): string => 'url("' . $path . '")', $paths));
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{from: float, cx: float, cy: float}
     */
    private function angularGradientGeometry(array $paint): array
    {
        $default = array('from' => 0.0, 'cx' => 50.0, 'cy' => 50.0);

        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return $default;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $tx = $this->numericOrNull($matrix[0][2] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        $ty = $this->numericOrNull($matrix[1][2] ?? null);
        if ( null === $a || null === $b || null === $tx || null === $c || null === $d || null === $ty ) {
            return $default;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return $default;
        }

        $dx = $d / $det;
        $dy = -$c / $det;
        $from = 0.0;
        if ( abs($dx) >= 1e-9 || abs($dy) >= 1e-9 ) {
            $from = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
            if ( $from < 0.0 ) {
                $from += 360.0;
            }
        }

        $cx = ($d * (0.5 - $tx) - $b * (0.5 - $ty)) / $det;
        $cy = ($a * (0.5 - $ty) - $c * (0.5 - $tx)) / $det;

        return array(
            'from' => $from,
            'cx'   => $cx * 100.0,
            'cy'   => $cy * 100.0,
        );
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function linearGradientAngle(array $paint): float
    {
        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return 180.0;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        if ( null === $a || null === $b || null === $c || null === $d ) {
            return 180.0;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return 180.0;
        }

        $dx = $d / $det;
        $dy = -$c / $det;
        if ( abs($dx) < 1e-9 && abs($dy) < 1e-9 ) {
            return 180.0;
        }

        $angle = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
        if ( $angle < 0.0 ) {
            $angle += 360.0;
        }

        return $angle;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function blendModeCss(string $blendMode): ?string
    {
        return match ( strtoupper($blendMode) ) {
            'MULTIPLY' => 'multiply',
            'SCREEN' => 'screen',
            'OVERLAY' => 'overlay',
            'DARKEN' => 'darken',
            'LIGHTEN' => 'lighten',
            'COLOR_DODGE' => 'color-dodge',
            'COLOR_BURN' => 'color-burn',
            'HARD_LIGHT' => 'hard-light',
            'SOFT_LIGHT' => 'soft-light',
            'DIFFERENCE' => 'difference',
            'EXCLUSION' => 'exclusion',
            'HUE' => 'hue',
            'SATURATION' => 'saturation',
            'COLOR' => 'color',
            'LUMINOSITY' => 'luminosity',
            default => null,
        };
    }
}
