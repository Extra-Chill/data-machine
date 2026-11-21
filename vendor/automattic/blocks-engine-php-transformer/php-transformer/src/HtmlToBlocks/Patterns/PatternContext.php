<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternContext
{
    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @param callable(DOMElement): bool|null $isRuntimeDomTarget
     * @param callable(DOMElement): array<int, array<string, mixed>>|null $convertChildren
     * @param callable(DOMElement, array<int, string>): array<int, array<string, mixed>>|null $convertChildrenWithoutTags
     * @param callable(DOMElement, DOMElement): string|null $navigationUnderlineColor
     * @param callable(DOMElement): string|null $resolvedStyle
     * @param callable(DOMElement): array<string, mixed>|null $convertElement
     * @param callable(DOMElement): list<string>|null $navigationColorInteractionStates
     */
    public function __construct(
        private readonly mixed $presentationAttributes,
        private readonly mixed $innerHtml,
        private readonly mixed $createBlock,
        private readonly mixed $isRuntimeDomTarget = null,
        private readonly mixed $convertChildren = null,
        private readonly mixed $convertChildrenWithoutTags = null,
        private readonly mixed $navigationUnderlineColor = null,
        private readonly mixed $resolvedStyle = null,
        private readonly mixed $convertElement = null,
        private readonly mixed $navigationColorInteractionStates = null
    ) {
    }

    /**
     * @return callable(DOMElement): array<string, mixed>
     */
    public function presentationAttributesCallback(): callable
    {
        return $this->presentationAttributes;
    }

    /**
     * @return callable(DOMElement): string
     */
    public function innerHtmlCallback(): callable
    {
        return $this->innerHtml;
    }

    /**
     * @return callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed>
     */
    public function createBlockCallback(): callable
    {
        return $this->createBlock;
    }

    /**
     * @return callable(DOMElement): bool|null
     */
    public function isRuntimeDomTargetCallback(): ?callable
    {
        return is_callable($this->isRuntimeDomTarget) ? $this->isRuntimeDomTarget : null;
    }

    /**
     * @return callable(DOMElement): array<int, array<string, mixed>>|null
     */
    public function convertChildrenCallback(): ?callable
    {
        return is_callable($this->convertChildren) ? $this->convertChildren : null;
    }

    /**
     * Convert one element to the block the generic pipeline would emit for it.
     * A pattern that keeps a sibling element out of its own block needs the
     * element's own conversion rather than its children's, so the sibling is
     * emitted exactly as it would be anywhere else in the document.
     *
     * @return callable(DOMElement): array<string, mixed>|null
     */
    public function convertElementCallback(): ?callable
    {
        return is_callable($this->convertElement) ? $this->convertElement : null;
    }

    /**
     * @return callable(DOMElement, array<int, string>): array<int, array<string, mixed>>|null
     */
    public function convertChildrenWithoutTagsCallback(): ?callable
    {
        return is_callable($this->convertChildrenWithoutTags) ? $this->convertChildrenWithoutTags : null;
    }

    /**
     * @return callable(DOMElement, DOMElement): string|null
     */
    public function navigationUnderlineColorCallback(): ?callable
    {
        return is_callable($this->navigationUnderlineColor) ? $this->navigationUnderlineColor : null;
    }

    /**
     * @return callable(DOMElement): string|null
     */
    public function resolvedStyleCallback(): ?callable
    {
        return is_callable($this->resolvedStyle) ? $this->resolvedStyle : null;
    }

    /**
     * @return callable(DOMElement): list<string>|null
     */
    public function navigationColorInteractionStatesCallback(): ?callable
    {
        return is_callable($this->navigationColorInteractionStates) ? $this->navigationColorInteractionStates : null;
    }
}
