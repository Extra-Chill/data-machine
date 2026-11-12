<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

/**
 * Immutable input context for {@see SubtreeClassifier}.
 *
 * Carries the non-DOM evidence associated with a source subtree: the CSS rules
 * declared for it (or its scope) and any JavaScript / handler source linked to
 * it. Ancestry is derived directly from the {@see \DOMElement} passed to the
 * classifier, but callers may supply an explicit ancestor-tag list when the
 * element has been detached from its original document.
 *
 * The classifier consumes this as opaque text and extracts only GENERIC
 * structural signals (no site- or fixture-specific strings), so callers can
 * populate it from whatever CSS/JS association the pipeline already computes.
 */
final class ClassificationContext
{
    /**
     * @param string             $cssText      CSS rules declared for / scoped to the subtree.
     * @param string             $jsText       JavaScript / handler source associated with the subtree.
     * @param array<int, string> $ancestorTags Optional explicit ancestor tag names (outermost-last not required);
     *                                         used only when the element is detached from its document.
     */
    public function __construct(
        private readonly string $cssText = '',
        private readonly string $jsText = '',
        private readonly array $ancestorTags = array()
    ) {
    }

    public function cssText(): string
    {
        return $this->cssText;
    }

    public function jsText(): string
    {
        return $this->jsText;
    }

    /**
     * @return array<int, string>
     */
    public function explicitAncestorTags(): array
    {
        return $this->ancestorTags;
    }
}
