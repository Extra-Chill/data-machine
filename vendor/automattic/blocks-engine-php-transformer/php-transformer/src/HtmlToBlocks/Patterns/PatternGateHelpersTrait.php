<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

trait PatternGateHelpersTrait
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function containsTextBearingBlock(array $blocks): bool
    {
        $textBearingNames = array( 'core/heading', 'core/paragraph', 'core/list', 'core/buttons', 'core/quote' );

        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( in_array($block['blockName'] ?? null, $textBearingNames, true) ) {
                return true;
            }

            $innerBlocks = $block['innerBlocks'] ?? array();
            if ( is_array($innerBlocks) && $this->containsTextBearingBlock($innerBlocks) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the resolved style declares a flex container whose main axis is
     * vertical (flex-direction: column / column-reverse). flex-direction only has
     * meaning on a flex container, so both display:flex and the column direction
     * are required before redirecting away from horizontal columns.
     */
    private function isVerticalFlexContainer(string $style): bool
    {
        if ( ! preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) ) {
            return false;
        }

        return (bool) preg_match('/(?:^|;)\s*flex-direction\s*:\s*column(?:-reverse)?\b/', $style);
    }
}
