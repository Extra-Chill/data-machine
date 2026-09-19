<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

final class ButtonMenuVisualProbeComparator
{
    public const SCHEMA = 'blocks-engine/php-transformer/visual-parity-probe-comparison/v1';

    private const STYLE_GROUPS = array(
        'fill' => array('background', 'background-color'),
        'border' => array(
            'border',
            'border-bottom-color',
            'border-bottom-style',
            'border-bottom-width',
            'border-color',
            'border-left-color',
            'border-left-style',
            'border-left-width',
            'border-right-color',
            'border-right-style',
            'border-right-width',
            'border-style',
            'border-top-color',
            'border-top-style',
            'border-top-width',
            'border-width',
        ),
        'radius' => array(
            'border-bottom-left-radius',
            'border-bottom-right-radius',
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
        ),
        'padding' => array('padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top'),
        'shadow' => array('box-shadow'),
        'text_color' => array('color'),
        'width' => array('width', 'min-width'),
    );

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    public function compare(array $source, array $target): array
    {
        $sourceProbes = $this->probes($source);
        $targetProbes = $this->probes($target);
        $unmatchedTargetIndexes = array_fill_keys(array_keys($targetProbes), true);
        $matches = array();
        $unmatchedSource = array();

        foreach ( $sourceProbes as $sourceIndex => $sourceProbe ) {
            $targetIndex = $this->bestTargetIndex($sourceProbe, $targetProbes, $unmatchedTargetIndexes);
            if ( null === $targetIndex ) {
                $unmatchedSource[] = $this->candidateSummary($sourceProbe);
                continue;
            }

            unset($unmatchedTargetIndexes[$targetIndex]);
            $targetProbe = $targetProbes[$targetIndex];
            $styleDeltas = $this->styleDeltas($sourceProbe, $targetProbe);
            $missingStyleRisks = $this->missingStyleRisks($sourceProbe, $targetProbe);
            $matches[] = array_filter(array(
                'source' => $this->candidateSummary($sourceProbe, $sourceIndex),
                'target' => $this->candidateSummary($targetProbe, $targetIndex),
                'style_deltas' => $styleDeltas,
                'missing_style_risks' => $missingStyleRisks,
                'mismatch_causes' => $this->mismatchCauses($sourceProbe, $targetProbe, $styleDeltas, $missingStyleRisks),
            ), static fn ($value): bool => array() !== $value);
        }

        $unmatchedTarget = array();
        foreach ( array_keys($unmatchedTargetIndexes) as $targetIndex ) {
            $unmatchedTarget[] = $this->candidateSummary($targetProbes[$targetIndex], $targetIndex);
        }

        $styleDeltaCount = 0;
        $missingStyleRiskCount = 0;
        $causeCounts = array();
        foreach ( $matches as $match ) {
            $styleDeltaCount += count($match['style_deltas'] ?? array());
            $missingStyleRiskCount += count($match['missing_style_risks'] ?? array());
            foreach ( $match['mismatch_causes'] ?? array() as $cause ) {
                if ( ! is_array($cause) ) {
                    continue;
                }
                $code = (string) ($cause['code'] ?? 'unknown');
                $causeCounts[$code] = ($causeCounts[$code] ?? 0) + 1;
            }
        }
        ksort($causeCounts);

        return array(
            'schema' => self::SCHEMA,
            'status' => 'success',
            'summary' => array(
                'source_total' => count($sourceProbes),
                'target_total' => count($targetProbes),
                'matched_total' => count($matches),
                'unmatched_source_total' => count($unmatchedSource),
                'unmatched_target_total' => count($unmatchedTarget),
                'style_delta_total' => $styleDeltaCount,
                'missing_style_risk_total' => $missingStyleRiskCount,
                'mismatch_cause_counts' => $causeCounts,
            ),
            'matches' => $matches,
            'unmatched_source' => $unmatchedSource,
            'unmatched_target' => $unmatchedTarget,
            'diagnostics' => array(),
        );
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private function probes(array $result): array
    {
        $probes = $result['probes'] ?? array();
        return is_array($probes) ? array_values(array_filter($probes, 'is_array')) : array();
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<int, array<string, mixed>> $targetProbes
     * @param array<int, bool> $availableIndexes
     */
    private function bestTargetIndex(array $sourceProbe, array $targetProbes, array $availableIndexes): ?int
    {
        $bestIndex = null;
        $bestScore = 0;
        foreach ( array_keys($availableIndexes) as $index ) {
            $score = $this->matchScore($sourceProbe, $targetProbes[$index]);
            if ( $score > $bestScore ) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= 8 ? $bestIndex : null;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     */
    private function matchScore(array $sourceProbe, array $targetProbe): int
    {
        $score = 0;
        if ( $this->normalizedText($sourceProbe) !== '' && $this->normalizedText($sourceProbe) === $this->normalizedText($targetProbe) ) {
            $score += 5;
        }
        if ( $this->href($sourceProbe) !== '' && $this->href($sourceProbe) === $this->href($targetProbe) ) {
            $score += 5;
        }
        if ( $this->compatibleKind((string) ($sourceProbe['kind'] ?? ''), (string) ($targetProbe['kind'] ?? '')) ) {
            $score += 3;
        }

        return $score;
    }

    private function compatibleKind(string $sourceKind, string $targetKind): bool
    {
        if ( $sourceKind === $targetKind ) {
            return true;
        }

        $interactive = array('button', 'cta', 'menu_button');
        return in_array($sourceKind, $interactive, true) && in_array($targetKind, $interactive, true);
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<int, array<string, mixed>>
     */
    private function styleDeltas(array $sourceProbe, array $targetProbe): array
    {
        $deltas = array();
        $sourceStyle = $this->style($sourceProbe);
        $targetStyle = $this->style($targetProbe);

        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            $sourceValues = $this->styleGroupValues($sourceStyle, $group, $fields);
            $targetValues = $this->styleGroupValues($targetStyle, $group, $fields);
            if ( array() === $sourceValues || $sourceValues === $targetValues ) {
                continue;
            }

            $deltas[] = array_filter(array(
                'group' => $group,
                'source' => $sourceValues,
                'target' => $targetValues,
            ), static fn ($value): bool => array() !== $value);
        }

        return $deltas;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<int, array<string, mixed>>
     */
    private function missingStyleRisks(array $sourceProbe, array $targetProbe): array
    {
        if ( ! $this->isCtaLike($sourceProbe) ) {
            return array();
        }

        $risks = array();
        $sourceStyle = $this->style($sourceProbe);
        $targetStyle = $this->style($targetProbe);
        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            if ( array() === $this->styleGroupValues($sourceStyle, $group, $fields) ) {
                continue;
            }
            if ( array() === $this->styleGroupValues($targetStyle, $group, $fields) ) {
                $risks[] = array(
                    'code' => 'missing_' . $group . '_style',
                    'group' => $group,
                    'source' => $this->styleGroupValues($sourceStyle, $group, $fields),
                );
            }
        }

        if ( in_array('default-button-style-watch', $this->signals($targetProbe), true) && array() !== $this->sourceVisualStyleGroups($sourceStyle) ) {
            array_unshift($risks, array(
                'code' => 'target_default_button_style',
                'signal' => 'default-button-style-watch',
                'source_style_groups' => $this->sourceVisualStyleGroups($sourceStyle),
            ));
        }

        return $risks;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @param array<int, array<string, mixed>> $styleDeltas
     * @param array<int, array<string, mixed>> $missingStyleRisks
     * @return array<int, array<string, mixed>>
     */
    private function mismatchCauses(array $sourceProbe, array $targetProbe, array $styleDeltas, array $missingStyleRisks): array
    {
        $causes = array();
        $missingGroups = $this->missingRiskGroups($missingStyleRisks);

        foreach ( $styleDeltas as $delta ) {
            $group = (string) ($delta['group'] ?? 'style');
            if ( isset($missingGroups[$group]) && array() === ($delta['target'] ?? array()) ) {
                continue;
            }
            $causes[] = array_filter(array(
                'code' => 'button_' . $group . '_mismatch',
                'category' => $group,
                'source' => $delta['source'] ?? array(),
                'target' => $delta['target'] ?? array(),
                'guidance' => $this->guidanceForGroup($group),
            ), static fn ($value): bool => array() !== $value && '' !== $value);
        }

        foreach ( $missingStyleRisks as $risk ) {
            $code = (string) ($risk['code'] ?? 'missing_style');
            $group = (string) ($risk['group'] ?? 'default_style_leakage');
            $causes[] = array_filter(array(
                'code' => 'target_default_button_style' === $code ? 'button_default_style_leakage' : 'button_' . $group . '_missing',
                'category' => $group,
                'source' => $risk['source'] ?? array(),
                'signal' => $risk['signal'] ?? null,
                'guidance' => 'target_default_button_style' === $code ? 'Target looks like browser/core default button chrome; preserve explicit source button styles or remove unintended button promotion.' : $this->guidanceForGroup($group),
            ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value);
        }

        $sourceDepth = (int) ($sourceProbe['hierarchy']['menu_depth'] ?? 0);
        $targetDepth = (int) ($targetProbe['hierarchy']['menu_depth'] ?? 0);
        if ( $sourceDepth !== $targetDepth ) {
            $causes[] = array(
                'code' => 'button_nesting_mismatch',
                'category' => 'nesting',
                'source' => array('menu_depth' => $sourceDepth),
                'target' => array('menu_depth' => $targetDepth),
                'guidance' => 'Button/menu item moved across list or navigation depth; inspect wrapper conversion before tuning styles.',
            );
        }

        $wrapperDelta = $this->wrapperChromeDelta($sourceProbe, $targetProbe);
        if ( array() !== $wrapperDelta ) {
            $causes[] = array_filter(array(
                'code' => 'button_wrapper_chrome_mismatch',
                'category' => 'wrapper_chrome',
                'source' => $wrapperDelta['source'] ?? array(),
                'target' => $wrapperDelta['target'] ?? array(),
                'guidance' => 'Visual chrome lives on a parent wrapper in one side; preserve wrapper block style/supports before changing button attributes.',
            ), static fn ($value): bool => array() !== $value && '' !== $value);
        }

        return $this->uniqueCauses($causes);
    }

    private function guidanceForGroup(string $group): string
    {
        return match ($group) {
            'width' => 'Compare source width/min-width against core/button width support or wrapper layout constraints.',
            'padding' => 'Preserve source padding on the button/link element or its wrapper block spacing support.',
            'radius' => 'Preserve source border radius on the core/button border support.',
            'fill' => 'Preserve source fill/background color and avoid theme default fill leakage.',
            'shadow' => 'Preserve source box-shadow/glow on the core/button shadow support.',
            'border' => 'Preserve border width/style/color together; partial border emission usually changes button shape.',
            'text_color' => 'Preserve link text color separately from fill/background color.',
            default => '',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $missingStyleRisks
     * @return array<string, bool>
     */
    private function missingRiskGroups(array $missingStyleRisks): array
    {
        $groups = array();
        foreach ( $missingStyleRisks as $risk ) {
            $group = (string) ($risk['group'] ?? '');
            if ( '' !== $group ) {
                $groups[$group] = true;
            }
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $sourceProbe
     * @param array<string, mixed> $targetProbe
     * @return array<string, mixed>
     */
    private function wrapperChromeDelta(array $sourceProbe, array $targetProbe): array
    {
        $sourceStyle = $this->wrapperChromeStyle($sourceProbe);
        $targetStyle = $this->wrapperChromeStyle($targetProbe);
        if ( array() === $sourceStyle || $sourceStyle === $targetStyle ) {
            return array();
        }

        return array_filter(array(
            'source' => array_filter(array(
                'selector' => $sourceProbe['wrapper_chrome']['selector'] ?? null,
                'style' => $sourceStyle,
            ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value),
            'target' => array_filter(array(
                'selector' => $targetProbe['wrapper_chrome']['selector'] ?? null,
                'style' => $targetStyle,
            ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value),
        ), static fn ($value): bool => array() !== $value);
    }

    /** @param array<string, mixed> $probe */
    private function wrapperChromeStyle(array $probe): array
    {
        $style = $probe['wrapper_chrome']['style'] ?? array();
        return is_array($style) ? $this->canonicalComparableStyle(array_filter($style, 'is_string')) : array();
    }

    /**
     * @param array<int, array<string, mixed>> $causes
     * @return array<int, array<string, mixed>>
     */
    private function uniqueCauses(array $causes): array
    {
        $unique = array();
        $seen = array();
        foreach ( $causes as $cause ) {
            $key = (string) ($cause['code'] ?? '') . '|' . (string) ($cause['category'] ?? '');
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $cause;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, string>
     */
    private function style(array $probe): array
    {
        $style = $probe['style'] ?? array();
        return is_array($style) ? array_filter($style, 'is_string') : array();
    }

    /**
     * @param array<string, string> $style
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function groupValues(array $style, array $fields): array
    {
        $values = array_intersect_key($style, array_flip($fields));
        ksort($values);
        return array_filter($values, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @param array<string, string> $style
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function styleGroupValues(array $style, string $group, array $fields): array
    {
        $values = $this->groupValues($style, $fields);
        if ( 'fill' === $group ) {
            $fill = trim((string) ($values['background-color'] ?? $values['background'] ?? ''));
            return '' === $fill ? array() : array('background' => $fill);
        }

        if ( 'padding' === $group ) {
            return $this->boxValues('padding', $values);
        }

        if ( 'radius' === $group ) {
            $radius = trim((string) ($values['border-radius'] ?? ''));
            return '' === $radius ? $values : array('border-radius' => $radius);
        }

        if ( 'border' !== $group ) {
            return $values;
        }

        return $this->borderValues($values);
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function borderValues(array $values): array
    {
        $values = array_filter($values, static function (string $value, string $property): bool {
            $normalized = strtolower(trim($value));
            if ( '' === $normalized ) {
                return false;
            }

            if ( in_array($property, array('border', 'border-top', 'border-right', 'border-bottom', 'border-left'), true) ) {
                return ! preg_match('/^(?:none|0(?:\.0+)?(?:px|rem|em)?)(?:\s+none)?$/', $normalized);
            }

            if ( str_ends_with($property, '-width') ) {
                return ! preg_match('/^0(?:\.0+)?(?:px|rem|em)?$/', $normalized);
            }

            if ( str_ends_with($property, '-style') ) {
                return 'none' !== $normalized;
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        $shorthand = $this->parseBorderShorthand((string) ($values['border'] ?? ''));
        if ( array() !== $shorthand ) {
            $values = $shorthand + $values;
        }

        foreach ( array('width', 'style', 'color') as $part ) {
            $property = 'border-' . $part;
            $sideValues = array();
            foreach ( array('top', 'right', 'bottom', 'left') as $side ) {
                $sideProperty = 'border-' . $side . '-' . $part;
                if ( isset($values[$sideProperty]) ) {
                    $sideValues[] = $values[$sideProperty];
                }
            }
            if ( 4 === count($sideValues) && 1 === count(array_unique($sideValues)) ) {
                $values[$property] = $sideValues[0];
            }
        }

        unset(
            $values['border'],
            $values['border-top-color'],
            $values['border-top-style'],
            $values['border-top-width'],
            $values['border-right-color'],
            $values['border-right-style'],
            $values['border-right-width'],
            $values['border-bottom-color'],
            $values['border-bottom-style'],
            $values['border-bottom-width'],
            $values['border-left-color'],
            $values['border-left-style'],
            $values['border-left-width']
        );

        ksort($values);

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function parseBorderShorthand(string $value): array
    {
        $value = trim($value);
        if ( '' === $value || preg_match('/^(?:none|0(?:\.0+)?(?:px|rem|em)?)(?:\s+none)?$/i', $value) ) {
            return array();
        }

        $parts = $this->splitCssValue($value);
        $parsed = array();
        $color = array();
        foreach ( $parts as $part ) {
            $normalized = strtolower($part);
            if ( ! isset($parsed['border-width']) && preg_match('/^(?:thin|medium|thick|\d*\.?\d+(?:px|em|rem|%|vh|vw)?)$/i', $part) ) {
                $parsed['border-width'] = $part;
                continue;
            }
            if ( ! isset($parsed['border-style']) && in_array($normalized, array('none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'), true) ) {
                $parsed['border-style'] = $part;
                continue;
            }
            $color[] = $part;
        }

        if ( array() !== $color ) {
            $parsed['border-color'] = implode(' ', $color);
        }

        return $parsed;
    }

    /**
     * @return array<int, string>
     */
    private function splitCssValue(string $value): array
    {
        $parts = array();
        $buffer = '';
        $depth = 0;
        $length = strlen($value);
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $value[$i];
            if ( '(' === $char ) {
                ++$depth;
            } elseif ( ')' === $char && $depth > 0 ) {
                --$depth;
            }
            if ( 0 === $depth && ctype_space($char) ) {
                if ( '' !== trim($buffer) ) {
                    $parts[] = trim($buffer);
                    $buffer = '';
                }
                continue;
            }
            $buffer .= $char;
        }

        if ( '' !== trim($buffer) ) {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    /**
     * @param array<string, string> $style
     * @return array<string, string>
     */
    private function canonicalComparableStyle(array $style): array
    {
        $canonical = array();
        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            foreach ( $this->styleGroupValues($style, $group, $fields) as $property => $value ) {
                $canonical[$group . ':' . $property] = $value;
            }
        }

        ksort($canonical);

        return $canonical;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function boxValues(string $prefix, array $values): array
    {
        $shorthand = trim((string) ($values[$prefix] ?? ''));
        if ( '' !== $shorthand ) {
            $parts = preg_split('/\s+/', $shorthand) ?: array();
            $parts = array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
            if ( 1 === count($parts) ) {
                $parts = array($parts[0], $parts[0], $parts[0], $parts[0]);
            } elseif ( 2 === count($parts) ) {
                $parts = array($parts[0], $parts[1], $parts[0], $parts[1]);
            } elseif ( 3 === count($parts) ) {
                $parts = array($parts[0], $parts[1], $parts[2], $parts[1]);
            } else {
                $parts = array_slice($parts, 0, 4);
            }
            $values = array(
                $prefix . '-top' => $parts[0],
                $prefix . '-right' => $parts[1],
                $prefix . '-bottom' => $parts[2],
                $prefix . '-left' => $parts[3],
            ) + $values;
        }

        unset($values[$prefix]);
        ksort($values);

        return array_filter($values, static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @param array<string, string> $style
     * @return array<int, string>
     */
    private function sourceVisualStyleGroups(array $style): array
    {
        $groups = array();
        foreach ( self::STYLE_GROUPS as $group => $fields ) {
            if ( array() !== $this->styleGroupValues($style, $group, $fields) ) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @param array<string, mixed> $probe */
    private function isCtaLike(array $probe): bool
    {
        return in_array((string) ($probe['kind'] ?? ''), array('button', 'cta', 'menu_button'), true) || in_array('cta-signal', $this->signals($probe), true);
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<int, string>
     */
    private function signals(array $probe): array
    {
        $signals = $probe['signals'] ?? array();
        return is_array($signals) ? array_values(array_filter($signals, 'is_string')) : array();
    }

    /**
     * @param array<string, mixed> $probe
     * @return array<string, mixed>
     */
    private function candidateSummary(array $probe, ?int $index = null): array
    {
        return array_filter(array(
            'index' => $index,
            'id' => $probe['id'] ?? null,
            'kind' => $probe['kind'] ?? null,
            'text' => $probe['text'] ?? null,
            'href' => $probe['href'] ?? null,
            'selector' => $probe['selector'] ?? null,
            'signals' => $probe['signals'] ?? array(),
        ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value);
    }

    /** @param array<string, mixed> $probe */
    private function normalizedText(array $probe): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) ($probe['text'] ?? '')) ?? ''));
    }

    /** @param array<string, mixed> $probe */
    private function href(array $probe): string
    {
        return trim((string) ($probe['href'] ?? ''));
    }
}
