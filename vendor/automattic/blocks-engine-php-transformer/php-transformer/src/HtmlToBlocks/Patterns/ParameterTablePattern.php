<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ParameterTablePattern
{
    use PatternDomHelpersTrait;

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $innerHtml
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock): ?array
    {
        if ( ! $this->hasClass($element, 'param-table') ) {
            return null;
        }

        $rows = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || ! $this->hasClass($child, 'param-row') ) {
                return null;
            }

            $name = $this->firstDirectChildWithClass($child, 'param-name');
            $type = $this->firstDirectChildWithClass($child, 'param-type');
            $desc = $this->firstDirectChildWithClass($child, 'param-desc');
            if ( ! $name instanceof DOMElement || ! $type instanceof DOMElement || ! $desc instanceof DOMElement ) {
                return null;
            }

            $rows[] = array( 'cells' => array(
                array( 'content' => $innerHtml($name), 'tag' => 'td' ),
                array( 'content' => $innerHtml($type), 'tag' => 'td' ),
                array( 'content' => $innerHtml($desc), 'tag' => 'td' ),
            ) );
        }

        if ( array() === $rows ) {
            return null;
        }

        return $createBlock('core/table', array_merge($presentationAttributes($element), array(
            'head' => array( array( 'cells' => array(
                array( 'content' => 'Parameter', 'tag' => 'th' ),
                array( 'content' => 'Type', 'tag' => 'th' ),
                array( 'content' => 'Description', 'tag' => 'th' ),
            ) ) ),
            'body' => $rows,
        )), array(), $element);
    }

    private function firstDirectChildWithClass(DOMElement $element, string $className): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->hasClass($child, $className) ) {
                return $child;
            }
        }

        return null;
    }
}
