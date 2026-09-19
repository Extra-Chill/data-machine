<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Bounded cache for immutable stylesheet analysis shared by one site compile.
 */
final class HtmlTransformerAnalysisCache
{
    /** @var array{key: string, static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array}|null */
    public ?array $style = null;

    public int $styleBuilds = 0;

    /** @var array{key: string, source_tags: array<string, bool>, selectors: list<array{selector: string, parsed: array<string, mixed>}>}|null */
    public ?array $authorSelectors = null;

    public int $authorSelectorBuilds = 0;
}
