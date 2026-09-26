<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\BackgroundImageExtractor;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;

/**
 * Derives core/cover attributes and recognition gates from resolved CSS.
 */
final class CoverStyleResolver
{
    private const MAX_STYLE_BYTES = 65536;
    private const DECLARATION_CACHE_LIMIT = 32;

    /**
     * @var array<string, array<string, string>>
     */
    private array $declarationCache = array();

    /**
     * @return array<string, string>
     */
    public function declarations(string $style): array
    {
        if ( strlen($style) > self::MAX_STYLE_BYTES ) {
            return array();
        }

        if ( array_key_exists($style, $this->declarationCache) ) {
            return $this->declarationCache[ $style ];
        }

        $declarations = array();
        foreach ( $this->splitTopLevel($style, array( ';' )) as $declaration ) {
            $parts = $this->splitTopLevel($declaration, array( ':' ));
            if ( count($parts) < 2 ) {
                continue;
            }

            $name  = strtolower(trim((string) array_shift($parts)));
            $value = trim(implode(':', $parts));
            $value = trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value, 1));
            if ( '' !== $name && '' !== $value ) {
                if ( array_key_exists($name, $declarations) ) {
                    unset($declarations[ $name ]);
                }
                $declarations[ $name ] = $value;
            }
        }

        if ( count($this->declarationCache) >= self::DECLARATION_CACHE_LIMIT ) {
            $oldest = array_key_first($this->declarationCache);
            if ( null !== $oldest ) {
                unset($this->declarationCache[ $oldest ]);
            }
        }
        $this->declarationCache[ $style ] = $declarations;

        return $declarations;
    }

    public function backgroundUrlFromStyle(string $style): string
    {
        $declaration = $this->winningBackgroundDeclaration($style);
        if ( null === $declaration || ! preg_match('/\burl\s*\(/i', $declaration['value']) ) {
            return '';
        }

        return (new BackgroundImageExtractor())->urlFromStyle(
            $declaration['name'] . ':' . $declaration['value']
        );
    }

    /**
     * @return array{dimRatio:int, customOverlayColor:string}
     */
    public function dimFromStyle(string $style): array
    {
        $default = array(
            'dimRatio'           => 0,
            'customOverlayColor' => '',
        );

        $declarations = $this->declarations($style);
        $property     = null;
        foreach ( array_reverse(array_keys($declarations)) as $name ) {
            if ( in_array($name, array( 'background', 'background-image' ), true) ) {
                $property = $name;
                break;
            }
        }
        if ( null === $property ) {
            return $default;
        }

        $value    = (string) $declarations[ $property ];
        $layers   = $this->splitTopLevel($value, array( ',' ));
        $urlIndex = null;
        foreach ( $layers as $index => $layer ) {
            if ( preg_match('/\burl\s*\(/i', $layer) ) {
                $urlIndex = $index;
                break;
            }
        }

        if ( null === $urlIndex ) {
            return $default;
        }

        for ( $index = 0; $index < $urlIndex; ++$index ) {
            if ( ! preg_match('/^linear-gradient\s*\((.*)\)$/is', trim($layers[ $index ]), $matches) ) {
                continue;
            }

            $stops = $this->splitTopLevel($matches[1], array( ',' ));
            if ( 3 === count($stops) && $this->isVerticalGradientDirection($stops[0]) ) {
                array_shift($stops);
            }
            if ( 2 !== count($stops) ) {
                continue;
            }

            $first  = $this->overlayColor($stops[0]);
            $second = $this->overlayColor($stops[1]);
            if ( null === $first || $first !== $second ) {
                continue;
            }

            $dimRatio = ( (int) round($first['alpha'] * 10) ) * 10;
            if ( 0 === $dimRatio || 100 === $dimRatio ) {
                return $default;
            }

            return array(
                'dimRatio'           => $dimRatio,
                'customOverlayColor' => sprintf('#%02x%02x%02x', $first['red'], $first['green'], $first['blue']),
            );
        }

        return $default;
    }

    /**
     * @return array{minHeight:float|int, minHeightUnit:string}|null
     */
    public function minHeightFromStyle(string $style): ?array
    {
        $declarations = $this->declarations($style);
        $value        = array_key_exists('min-height', $declarations)
            ? $declarations['min-height']
            : (string) ($declarations['height'] ?? '');
        $value        = strtolower(trim($value));
        if ( ! preg_match('/^(\d+(?:\.\d+)?)(px|vh|rem|dvh|svh|lvh)$/', $value, $matches) ) {
            return null;
        }

        $number = str_contains($matches[1], '.') ? (float) $matches[1] : (int) $matches[1];

        return array(
            'minHeight'     => $number,
            'minHeightUnit' => $matches[2],
        );
    }

    /**
     * @return array{x:float, y:float}|null
     */
    public function focalPointFromStyle(string $style): ?array
    {
        $declarations = $this->declarations($style);
        $value        = strtolower(trim((string) ($declarations['background-position'] ?? '')));
        if ( '' === $value ) {
            return null;
        }

        $parts = $this->splitTopLevelWhitespace($value);
        if ( count($parts) < 1 || count($parts) > 2 ) {
            return null;
        }

        $horizontalKeywords = array(
            'left'   => 0.0,
            'center' => 0.5,
            'right'  => 1.0,
        );
        $verticalKeywords = array(
            'top'    => 0.0,
            'center' => 0.5,
            'bottom' => 1.0,
        );

        if ( 1 === count($parts) ) {
            $x = $this->positionValue($parts[0], $horizontalKeywords);
            $y = 0.5;
            if ( null === $x ) {
                $x = 0.5;
                $y = $this->positionValue($parts[0], $verticalKeywords);
            }
        } else {
            $x = $this->positionValue($parts[0], $horizontalKeywords);
            $y = $this->positionValue($parts[1], $verticalKeywords);
            if ( null === $x || null === $y ) {
                $x = $this->positionValue($parts[1], $horizontalKeywords);
                $y = $this->positionValue($parts[0], $verticalKeywords);
            }
        }
        if ( null === $x || null === $y || ( 0.5 === $x && 0.5 === $y ) ) {
            return null;
        }

        return array(
            'x' => $x,
            'y' => $y,
        );
    }

    public function meetsHeroSizeGate(string $style): bool
    {
        $declarations = $this->declarations($style);
        foreach ( array_reverse(array_keys($declarations)) as $name ) {
            if ( 'background-size' === $name ) {
                $size = strtolower(trim((string) $declarations[ $name ]));
                foreach ( $this->splitTopLevel($size, array( ',' )) as $layerSize ) {
                    if ( array( 'cover' ) === $this->splitTopLevelWhitespace($layerSize) ) {
                        return true;
                    }
                }
                break;
            }

            if ( 'background' === $name ) {
                $background = (string) $declarations[ $name ];
                foreach ( $this->splitTopLevel($background, array( ',' )) as $layer ) {
                    $slashParts = $this->splitTopLevel($layer, array( '/' ));
                    foreach ( array_slice($slashParts, 1) as $sizeAndRepeat ) {
                        $tokens = $this->splitTopLevelWhitespace(strtolower($sizeAndRepeat));
                        if ( 'cover' === ($tokens[0] ?? '') ) {
                            return true;
                        }
                    }
                }
                break;
            }
        }

        $minHeight = $this->minHeightFromStyle($style);
        if ( null === $minHeight ) {
            return false;
        }

        $thresholds = array(
            'px'  => 200,
            'vh'  => 30,
            'rem' => 12.5,
            'dvh' => 30,
            'svh' => 30,
            'lvh' => 30,
        );

        return $minHeight['minHeight'] >= $thresholds[ $minHeight['minHeightUnit'] ];
    }

    public function hasRepeatingBackground(string $style): bool
    {
        $declarations = $this->declarations($style);
        foreach ( array( 'background-repeat', 'background' ) as $property ) {
            $value           = strtolower(trim((string) ($declarations[ $property ] ?? '')));
            $repeatingTokens = array( 'repeat', 'repeat-x', 'repeat-y' );
            if ( 'background' === $property ) {
                $repeatingTokens[] = 'round';
                $repeatingTokens[] = 'space';
            }
            foreach ( $this->splitTopLevel($value, array( ',' )) as $layer ) {
                $tokens = $this->splitTopLevelWhitespace($layer);
                foreach ( $tokens as $token ) {
                    if ( in_array($token, $repeatingTokens, true) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array{name:string, value:string}|null
     */
    private function winningBackgroundDeclaration(string $style): ?array
    {
        $declarations = $this->declarations($style);
        foreach ( array_reverse(array_keys($declarations)) as $name ) {
            if ( in_array($name, array( 'background', 'background-image' ), true) ) {
                return array(
                    'name'  => $name,
                    'value' => (string) $declarations[ $name ],
                );
            }
        }

        return null;
    }

    /**
     * @return array{red:int, green:int, blue:int, alpha:float}|null
     */
    private function overlayColor(string $literal): ?array
    {
        $literal = strtolower(trim($literal));
        $parts   = $this->splitTopLevelWhitespace($literal);
        if ( count($parts) < 1 || count($parts) > 3 ) {
            return null;
        }

        $color = (string) array_shift($parts);
        foreach ( $parts as $position ) {
            if ( ! $this->isGradientStopPosition($position) ) {
                return null;
            }
        }

        if ( preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $color, $matches) ) {
            $hex = $matches[1];
            if ( 4 === strlen($hex) ) {
                $red   = hexdec(str_repeat($hex[0], 2));
                $green = hexdec(str_repeat($hex[1], 2));
                $blue  = hexdec(str_repeat($hex[2], 2));
                $alpha = hexdec(str_repeat($hex[3], 2)) / 255;
            } else {
                $red   = hexdec(substr($hex, 0, 2));
                $green = hexdec(substr($hex, 2, 2));
                $blue  = hexdec(substr($hex, 4, 2));
                $alpha = hexdec(substr($hex, 6, 2)) / 255;
            }

            if ( $alpha >= 1 ) {
                return null;
            }

            return array(
                'red'   => $red,
                'green' => $green,
                'blue'  => $blue,
                'alpha' => $alpha,
            );
        }

        if (
            ! preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*((?:0(?:\.\d+)?|1(?:\.0+)?|\.\d+))\s*\)$/', $color, $matches)
            && ! preg_match('/^rgba?\(\s*(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})\s*\/\s*((?:0(?:\.\d+)?|1(?:\.0+)?|\.\d+))\s*\)$/', $color, $matches)
        ) {
            return null;
        }

        $red   = (int) $matches[1];
        $green = (int) $matches[2];
        $blue  = (int) $matches[3];
        $alpha = (float) $matches[4];
        if ( $red > 255 || $green > 255 || $blue > 255 || $alpha >= 1 ) {
            return null;
        }

        return array(
            'red'   => $red,
            'green' => $green,
            'blue'  => $blue,
            'alpha' => $alpha,
        );
    }

    private function isVerticalGradientDirection(string $value): bool
    {
        return (bool) preg_match('/^(?:0deg|180deg|to\s+(?:top|bottom))$/i', trim($value));
    }

    private function isGradientStopPosition(string $value): bool
    {
        return (bool) preg_match(
            '/^(?:-?(?:\d+(?:\.\d+)?|\.\d+)(?:%|[a-z]+)?|(?:calc|min|max|clamp|var)\(.*\))$/is',
            trim($value)
        );
    }

    /**
     * @param array<int, string> $delimiters
     * @return array<int, string>
     */
    private function splitTopLevel(string $input, array $delimiters): array
    {
        $masked = $this->maskQuotedAndEscapedCharacters($input);
        $parts  = CssValueSplitter::splitTopLevel($masked, $delimiters);

        return $this->restoreSplitParts($input, $masked, $parts);
    }

    /**
     * @return array<int, string>
     */
    private function splitTopLevelWhitespace(string $input): array
    {
        $masked = $this->maskQuotedAndEscapedCharacters($input);
        $parts  = CssValueSplitter::splitTopLevelWhitespace($masked);

        return $this->restoreSplitParts($input, $masked, $parts);
    }

    private function maskQuotedAndEscapedCharacters(string $input): string
    {
        $masked = '';
        $quote  = null;
        $length = strlen($input);

        for ( $index = 0; $index < $length; ++$index ) {
            $character = $input[ $index ];
            if ( '\\' === $character ) {
                $masked .= 'x';
                if ( $index + 1 < $length ) {
                    $masked .= 'x';
                    ++$index;
                }
                continue;
            }

            if ( null !== $quote ) {
                if ( $quote === $character ) {
                    $masked .= $character;
                    $quote   = null;
                } else {
                    $masked .= 'x';
                }
                continue;
            }

            if ( '"' === $character || "'" === $character ) {
                $quote  = $character;
                $masked .= $character;
                continue;
            }

            $masked .= $character;
        }

        return $masked;
    }

    /**
     * @param array<int, string> $maskedParts
     * @return array<int, string>
     */
    private function restoreSplitParts(string $input, string $masked, array $maskedParts): array
    {
        $parts  = array();
        $offset = 0;

        foreach ( $maskedParts as $maskedPart ) {
            $start = strpos($masked, $maskedPart, $offset);
            if ( false === $start ) {
                return array();
            }

            $parts[] = substr($input, $start, strlen($maskedPart));
            $offset  = $start + strlen($maskedPart);
        }

        return $parts;
    }

    /**
     * @param array<string, float> $keywords
     */
    private function positionValue(string $value, array $keywords): ?float
    {
        if ( array_key_exists($value, $keywords) ) {
            return $keywords[ $value ];
        }

        if ( ! preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))%$/', $value, $matches) ) {
            return null;
        }

        return max(0.0, min(1.0, (float) $matches[1] / 100));
    }
}
