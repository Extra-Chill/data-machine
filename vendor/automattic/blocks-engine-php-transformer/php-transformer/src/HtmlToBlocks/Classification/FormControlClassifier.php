<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use DOMElement;

final class FormControlClassifier
{
    /**
     * Native elements that participate in form control semantics.
     *
     * @var array<int, string>
     */
    public const CONTROL_TAGS = array( 'button', 'input', 'select', 'textarea' );

    /**
     * Controls that collect user-entered values rather than only submitting or
     * carrying hidden/runtime state.
     *
     * @var array<int, string>
     */
    public const DATA_ENTRY_TAGS = array( 'input', 'select', 'textarea' );

    public static function isControlElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), self::CONTROL_TAGS, true);
    }

    public static function controlType(DOMElement $control): string
    {
        $tagName = strtolower($control->tagName);
        if ( 'input' === $tagName ) {
            $type = strtolower(trim($control->hasAttribute('type') ? $control->getAttribute('type') : ''));
            return '' !== $type ? $type : 'text';
        }
        if ( 'button' === $tagName ) {
            $type = strtolower(trim($control->hasAttribute('type') ? $control->getAttribute('type') : ''));
            return '' !== $type ? $type : 'submit';
        }
        if ( 'select' === $tagName && $control->hasAttribute('multiple') ) {
            return 'select-multiple';
        }

        return $tagName;
    }

    public static function isDataEntryControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        if ( 'input' !== $tagName ) {
            return false;
        }

        return ! in_array(
            self::controlType($control),
            array( 'submit', 'reset', 'button', 'image', 'hidden', 'file' ),
            true
        );
    }
}
