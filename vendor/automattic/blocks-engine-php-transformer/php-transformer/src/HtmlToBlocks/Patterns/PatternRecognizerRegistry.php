<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternRecognizerRegistry
{
    /**
     * @param array<int, PatternRecognizerInterface> $recognizers
     */
    public function __construct(private readonly array $recognizers)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstMatch(DOMElement $element, PatternContext $context): ?array
    {
        foreach ( $this->recognizers as $recognizer ) {
            $block = $recognizer->match($element, $context);
            if ( null !== $block ) {
                return $block;
            }
        }

        return null;
    }
}
