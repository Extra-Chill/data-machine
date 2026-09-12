<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

trait ButtonLinkDispatchTrait
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertAnchorDispatchElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->isRuntimeDomTarget($element) ) {
            return $this->htmlPreservationBlock($element);
        }

        $linkedLogo = $this->linkedSvgLogoBlockFromAnchor($element, $fallbacks);
        if ( null !== $linkedLogo ) {
            return $linkedLogo;
        }

        $button = $this->buttonsPattern->matchAnchor(
            $element,
            fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor),
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)),
            fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
        );
        if ( null !== $button ) {
            return $button;
        }

        $logo = $this->logoPattern->match(
            $element,
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
            fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
        );
        if ( null !== $logo ) {
            return $logo;
        }

        $linkedImage = $this->imageBlockFromAnchor($element);
        if ( null !== $linkedImage ) {
            return $linkedImage;
        }

        if ( '' === trim($element->textContent ?? '') && '' !== $this->safeLinkUrl($this->attr($element, 'href')) && '' !== trim($this->attr($element, 'aria-label')) ) {
            return $this->createBlock('core/paragraph', array_merge($this->nonButtonAnchorWrapperAttributes($element), array( 'content' => $this->outerHtml($element) )), array(), $element);
        }

        if ( '' === trim($element->textContent ?? '') ) {
            return null;
        }

        if ( $this->hasBlockContentChildren($element) ) {
            $linkWrapper = $this->convertLinkWrapperGroup($element, $fallbacks);
            if ( null !== $linkWrapper ) {
                return $linkWrapper;
            }
        }

        // A non-button anchor has no native width support. Promote its source
        // presentation to the paragraph wrapper so generated geometry remains
        // attached to the rendered block rather than being silently discarded.
        // Its id remains on the inner link, the node that source selectors and
        // fragment navigation actually address.
        return $this->createBlock('core/paragraph', array_merge($this->nonButtonAnchorWrapperAttributes($element), array( 'content' => $this->outerHtml($element) )), array(), $element);
    }

    /** @return array<string, mixed> */
    private function nonButtonAnchorWrapperAttributes(DOMElement $anchor): array
    {
        $attrs = $this->presentationAttributes($anchor);
        unset($attrs['anchor']);

        if ( $this->isPositionedFragmentLink($anchor) ) {
            $attrs['className'] = $this->mergeClassNames(
                (string) ($attrs['className'] ?? ''),
                self::POSITIONED_FRAGMENT_LINK_CARRIER_CLASS
            );
        }

        // Source class identity belongs exclusively to the saved link. Keep only
        // generated geometry classes and mapped presentation on its paragraph host.
        $sourceClasses = preg_split('/\s+/', trim($this->attr($anchor, 'class'))) ?: array();
        $classes = array_values(array_filter(
            preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(),
            static fn (string $class): bool => ! in_array($class, $sourceClasses, true)
        ));
        if ( array() === $classes ) {
            unset($attrs['className']);
        } else {
            $attrs['className'] = implode(' ', $classes);
        }

        return $attrs;
    }

    private function isPositionedFragmentLink(DOMElement $anchor): bool
    {
        $href = trim($this->attr($anchor, 'href'));
        if ( ! str_starts_with($href, '#') || '#' === $href || 'button' === strtolower($this->attr($anchor, 'role')) ) {
            return false;
        }

        $position = strtolower(trim((string) ($this->structuralPresentationDeclarations($anchor)['position'] ?? '')));
        return in_array($position, array( 'absolute', 'fixed' ), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertButtonDispatchElement(DOMElement $element): ?array
    {
        if ( $this->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeControlIsland($element);
            return $this->htmlPreservationBlock($element);
        }

        return $this->buttonsPattern->matchButton(
            $element,
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)),
            fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (DOMElement $sourceElement): bool => $sourceElement->parentNode instanceof DOMElement && in_array($this->authoredDisplay($sourceElement->parentNode), array( 'grid', 'inline-grid' ), true),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
    }
}
