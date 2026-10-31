<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

trait FormDispatchTrait
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFormDispatchElement(DOMElement $element, array &$fallbacks): ?array
    {
        $searchBlock = $this->searchBlockFromForm($element);
        if ( null !== $searchBlock ) {
            return $searchBlock;
        }

        $readableFormBlock = $this->readableFormBlockFromForm($element);
        if ( null !== $readableFormBlock && ! $this->formRequiresRuntimePreservation($element) ) {
            if ( $this->formHasDataEntryControls($element) ) {
                $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
            }

            return $readableFormBlock;
        }

        if ( $this->formHasDataEntryControls($element) ) {
            $composition = $this->compositionalFormBlock($element, $fallbacks);
            if ( null !== $composition ) {
                $fallbacks[] = $this->formFallbackFinding($element, $composition['block'], $composition['slot']);
                $this->recordFormRuntimeIsland($element, $composition['block']);
                return $composition['block'];
            }
            $preservationBlock = $this->htmlPreservationBlock($element);
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock, $preservationBlock);
            $this->recordFormRuntimeIsland($element, $readableFormBlock);

            return $preservationBlock;
        }

        $readableFormBlock = $this->readableFormBlockFromForm($element, true);
        $this->recordFormRuntimeIsland($element, $readableFormBlock);

        // Surface a form fallback finding so a downstream consumer can map the
        // preserved control structure onto a working form provider.
        if ( null === $readableFormBlock || $this->formHasDataEntryControls($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $readableFormBlock);
        }

        return $readableFormBlock;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureDivBasedPseudoFormFallback(DOMElement $element, array &$fallbacks): void
    {
        // Some signup/contact widgets pair data-entry controls with a submit-like
        // control inside a plain container. Emit the same finding as a real form.
        if ( $this->isDivBasedPseudoForm($element) ) {
            $fallbacks[] = $this->formFallbackFinding($element, $this->readableFormBlockFromForm($element, true));
        }
    }

    /**
     * @param array<string, mixed>|null $readableFormBlock
     */
    private function recordFormRuntimeIsland(DOMElement $element, ?array $readableFormBlock): void
    {
        $controls = $this->formControls($element);
        $this->recordRuntimeIsland($element, 'form', 'form_requires_runtime', 'server_or_client_form_handler', array(
            'form'             => $this->formMetadata($element),
            'controls'         => $controls,
            'control_count'    => count($controls),
            'events'           => $this->eventMetadata($element),
            'readable_blocks'  => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));
    }
}
