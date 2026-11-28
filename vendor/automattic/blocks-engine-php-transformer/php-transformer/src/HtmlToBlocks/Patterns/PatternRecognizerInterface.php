<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

interface PatternRecognizerInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, PatternContext $context): ?array;
}
