<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use const XML_TEXT_NODE;

final class GalleryPattern
{
    use PatternDomHelpersTrait;

    /**
     * @param callable(DOMElement, DOMElement|null, DOMElement|null, DOMElement|null): (array<string, mixed>|null) $convertImageElement
     * @param callable(DOMElement, DOMElement|null, DOMElement|null): (array<string, mixed>|null) $convertPictureElement
     * @param callable(DOMElement): (DOMElement|null) $figureLinkedMediaAnchor
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $convertImageElement, callable $convertPictureElement, callable $figureLinkedMediaAnchor, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        $images = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName ) {
                continue;
            }

            if ( 'figure' === $tagName ) {
                $linkedMedia = $figureLinkedMediaAnchor($child);
                if ( $linkedMedia instanceof DOMElement ) {
                    $linkedPicture = $this->firstChildElement($linkedMedia, 'picture');
                    if ( $linkedPicture instanceof DOMElement ) {
                        $images[] = $convertPictureElement($linkedPicture, $child, $linkedMedia);
                        continue;
                    }

                    $linkedImage = $this->firstChildElement($linkedMedia, 'img');
                    if ( $linkedImage instanceof DOMElement ) {
                        $images[] = $convertImageElement($linkedImage, $child, null, $linkedMedia);
                        continue;
                    }
                }

                $image = $this->firstChildElement($child, 'img');
                if ( $image instanceof DOMElement ) {
                    $images[] = $convertImageElement($image, $child, null, null);
                    continue;
                }

                $picture = $this->firstChildElement($child, 'picture');
                if ( $picture instanceof DOMElement ) {
                    $images[] = $convertPictureElement($picture, $child, null);
                    continue;
                }
            }

            if ( 'img' === $tagName ) {
                $images[] = $convertImageElement($child, null, null, null);
                continue;
            }

            if ( 'picture' === $tagName ) {
                $images[] = $convertPictureElement($child, null, null);
                continue;
            }

            return null;
        }

        $images = array_values(array_filter($images));
        if ( count($images) < 2 ) {
            return null;
        }

        $attrs = $presentationAttributes($element);
        $caption = $this->firstChildElement($element, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $innerHtml($caption);
        }

        return $createBlock('core/gallery', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $images, $element);
    }

}
