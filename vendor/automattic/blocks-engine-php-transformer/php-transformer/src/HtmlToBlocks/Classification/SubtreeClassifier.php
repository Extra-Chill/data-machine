<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use DOMElement;

/**
 * Coarse, structural classifier for source subtrees (issue #497).
 *
 * Given a {@see DOMElement} subtree plus its {@see ClassificationContext} (declared
 * CSS, associated JS, ancestry), it returns the fundamental BUCKET the subtree
 * represents. This is the layer ABOVE the recognizers: it decides *what a thing
 * is* (presentation vs content unit vs application vs site-wide functionality) so
 * downstream routing (theme vs companion plugin, native-vs-carry) can be correct.
 * It does NOT duplicate recognizer-level conversion logic.
 *
 * Design principles:
 *  - GENERIC structural signals only — no fixture/site-specific strings.
 *  - Conservative and confidence-scored: when evidence is weak or conflicting it
 *    returns {@see self::BUCKET_UNKNOWN} (low confidence) so the caller can fall
 *    back to `core/html` + a diagnostic rather than misclassify.
 *  - Pure: no I/O, no global state; the same inputs always yield the same verdict.
 *
 * This module is intentionally standalone and is NOT wired into the conversion
 * flow yet; wiring lands as a follow-up after the HtmlTransformer decomposition
 * (#242). See PR for issue #497.
 */
final class SubtreeClassifier
{
    use DomHelpersTrait;

    public const BUCKET_THEME_PRESENTATION = 'theme_presentation';
    public const BUCKET_CUSTOM_BLOCK       = 'custom_block';
    public const BUCKET_CUSTOM_APPLICATION = 'custom_application';
    public const BUCKET_CUSTOM_PLUGIN      = 'custom_plugin';
    public const BUCKET_UNKNOWN            = 'unknown';

    /**
     * Minimum winning score for a confident verdict; below this we stay UNKNOWN.
     */
    private const MIN_SCORE = 2.0;

    /**
     * Minimum lead a winner must have over the runner-up; ties stay UNKNOWN.
     */
    private const MIN_MARGIN = 1.5;

    private const INTERACTIVE_ROLES = array(
        'tablist',
        'tab',
        'tabpanel',
        'dialog',
        'menu',
        'menubar',
        'combobox',
        'listbox',
        'slider',
        'spinbutton',
        'accordion',
    );

    public function classify(DOMElement $element, ?ClassificationContext $context = null): ClassificationResult
    {
        $context = $context ?? new ClassificationContext();
        $signals = $this->gatherSignals($element, $context);
        $scores  = $this->scoreBuckets($signals);

        arsort($scores);
        $buckets = array_keys($scores);
        $max     = $scores[$buckets[0]];
        $second  = $scores[$buckets[1]] ?? 0.0;
        $total   = array_sum($scores);

        $signals['scores'] = $scores;

        if ( $max < self::MIN_SCORE || ( $max - $second ) < self::MIN_MARGIN ) {
            $strength   = min(1.0, $max / 6.0);
            $dominance  = $total > 0.0 ? $max / $total : 0.0;
            $confidence = round(min(0.4, 0.1 + $dominance * 0.3 + $strength * 0.2), 2);

            return new ClassificationResult(self::BUCKET_UNKNOWN, $confidence, $signals);
        }

        $dominance  = $total > 0.0 ? $max / $total : 0.0;
        $strength   = min(1.0, $max / 6.0);
        $confidence = round(min(0.97, 0.35 + $dominance * 0.4 + $strength * 0.25), 2);

        return new ClassificationResult($buckets[0], $confidence, $signals);
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, float>
     */
    private function scoreBuckets(array $s): array
    {
        $scores = array(
            self::BUCKET_THEME_PRESENTATION => 0.0,
            self::BUCKET_CUSTOM_BLOCK       => 0.0,
            self::BUCKET_CUSTOM_APPLICATION => 0.0,
            self::BUCKET_CUSTOM_PLUGIN      => 0.0,
        );

        $formControls   = (int) $s['form_controls'];
        $inputDriven    = $formControls > 0 || $s['js_reads_input'] || $s['canvas'];
        $functionalJs   = $s['js_network'] || $s['js_storage'] || $s['js_state_mutation'];
        $isContentUnit  = $s['repeatable_children'] >= 3
            || $s['media_gallery']
            || $s['interactive_role']
            || $s['cohesive_content_unit'];
        $decorativeJs   = ( $s['js_classlist_toggle'] || $s['js_scroll_listener'] || $s['js_parallax'] )
            && ! $functionalJs
            && ! $s['js_reads_input'];

        // --- custom_application: input drives logic / state, or canvas / Interactivity API.
        if ( $formControls >= 1 ) {
            $scores[self::BUCKET_CUSTOM_APPLICATION] += 2.0;
        }
        if ( $formControls >= 2 ) {
            $scores[self::BUCKET_CUSTOM_APPLICATION] += 1.0;
        }
        if ( $s['js_reads_input'] ) {
            $scores[self::BUCKET_CUSTOM_APPLICATION] += 3.0;
        }
        if ( $s['canvas'] ) {
            $scores[self::BUCKET_CUSTOM_APPLICATION] += 3.0;
        }
        if ( $s['interactivity_api'] ) {
            $scores[self::BUCKET_CUSTOM_APPLICATION] += 2.0;
        }
        // Functional JS only reinforces an application when the subtree is itself
        // input-driven; otherwise site-wide functional JS belongs to custom_plugin.
        if ( $inputDriven || $s['interactivity_api'] ) {
            if ( $s['js_network'] ) {
                $scores[self::BUCKET_CUSTOM_APPLICATION] += 1.0;
            }
            if ( $s['js_storage'] ) {
                $scores[self::BUCKET_CUSTOM_APPLICATION] += 1.0;
            }
            if ( $s['js_state_mutation'] ) {
                $scores[self::BUCKET_CUSTOM_APPLICATION] += 1.0;
            }
        }

        // --- custom_plugin: site-wide functional JS not tied to one component.
        if (
            $s['js_global_scope']
            && ( $functionalJs || $s['js_style_mutation'] || $s['js_classlist_toggle'] )
            && ! $inputDriven
            && ! $isContentUnit
            && ! $decorativeJs
            && ! $s['interactivity_api']
        ) {
            $scores[self::BUCKET_CUSTOM_PLUGIN] += 2.0;
            if ( $s['js_network'] ) {
                $scores[self::BUCKET_CUSTOM_PLUGIN] += 2.0;
            }
            if ( $s['js_storage'] ) {
                $scores[self::BUCKET_CUSTOM_PLUGIN] += 1.0;
            }
            if ( $s['js_state_mutation'] ) {
                $scores[self::BUCKET_CUSTOM_PLUGIN] += 1.0;
            }
        }

        // --- theme_presentation: appearance only (CSS animation / JS that only
        //     toggles classes/styles for visual effect; no input/data/state).
        if ( $s['css_keyframes'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 3.0;
        }
        if ( $s['css_animation'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 2.0;
        }
        if ( $s['css_transition'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 2.0;
        }
        if ( $s['css_transform'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 1.0;
        }
        if ( $s['css_sticky_fixed'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 1.0;
        }
        if ( $s['js_parallax'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 3.0;
        } elseif ( $s['js_scroll_listener'] ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 1.0;
        }
        if ( $s['js_classlist_toggle'] && ! $inputDriven && ! $functionalJs ) {
            $scores[self::BUCKET_THEME_PRESENTATION] += 2.0;
        }
        // Animation on an interactive app is incidental polish, not the essence.
        if ( $inputDriven ) {
            $scores[self::BUCKET_THEME_PRESENTATION] *= 0.3;
        }

        // --- custom_block: cohesive, repeatable content unit a user edits, with
        //     at most component-local interactivity.
        if ( $s['repeatable_children'] >= 3 ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 3.0;
        } elseif ( $s['repeatable_children'] === 2 ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 2.0;
        }
        if ( $s['media_gallery'] ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 2.0;
        }
        if ( $s['interactive_role'] ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 2.0;
        }
        if ( $s['cohesive_content_unit'] ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 1.0;
        }
        // Component-local interactivity that is neither input-driven nor site-wide.
        if (
            ( $s['inline_event_handlers'] || $s['js_component_scoped'] )
            && ! $inputDriven
            && ! ( $s['js_global_scope'] && $functionalJs )
        ) {
            $scores[self::BUCKET_CUSTOM_BLOCK] += 1.0;
        }

        return $scores;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherSignals(DOMElement $element, ClassificationContext $context): array
    {
        $elements = $this->collectElements($element);
        $css      = $context->cssText();
        $js       = $context->jsText();

        $jsClasslist = $this->matches($js, '/classList\s*\.\s*(?:add|remove|toggle|replace)\s*\(/i');
        $jsStyle     = $this->matches($js, '/\.style\s*\.\s*[a-zA-Z$_]/i');
        $jsScroll    = $this->matches($js, '/addEventListener\s*\(\s*[\'"](?:scroll|mousemove|wheel)[\'"]/i')
            || $this->matches($js, '/on(?:scroll|mousemove|wheel)\b/i');
        $jsTransform = $this->matches($js, '/transform/i');
        $jsReadsInput = $this->matches($js, '/\.value\b/i')
            || $this->matches($js, '/\bFormData\b/i')
            || $this->matches($js, '/\.checked\b/i')
            || $this->matches($js, '/\.files\b/i')
            || $this->matches($js, '/addEventListener\s*\(\s*[\'"](?:input|change|submit|keyup|keydown)[\'"]/i');
        $jsNetwork = $this->matches($js, '/\bfetch\s*\(/i')
            || $this->matches($js, '/\bXMLHttpRequest\b/i')
            || $this->matches($js, '/\$\.ajax\b/i')
            || $this->matches($js, '/\.ajax\s*\(/i')
            || $this->matches($js, '/\baxios\s*\./i');
        $jsStorage = $this->matches($js, '/\blocalStorage\b/i')
            || $this->matches($js, '/\bsessionStorage\b/i')
            || $this->matches($js, '/document\s*\.\s*cookie\b/i');
        $jsState = $this->matches($js, '/\bsetState\b/i')
            || $this->matches($js, '/\bthis\s*\.\s*state\b/i')
            || $this->matches($js, '/\bstate\s*\.\s*\w+\s*=/i')
            || $this->matches($js, '/\bstate\s*\[[^\]]+\]\s*=/i');
        $jsGlobal = $this->matches($js, '/document\s*\.\s*(?:addEventListener|querySelector|querySelectorAll|getElementById|getElementsBy)/i')
            || $this->matches($js, '/window\s*\.\s*addEventListener/i');
        $jsScoped = $this->matches($js, '/\b(?:this|el|element|root|container|node|self)\s*\.\s*(?:querySelector|addEventListener|classList|closest)/i');

        return array(
            // DOM-derived structural signals.
            'form_controls'         => $this->countDescendants($elements, FormControlClassifier::DATA_ENTRY_TAGS),
            'submit_or_form'        => $this->hasTag($elements, array( 'form' )) || $this->hasSubmit($elements),
            'canvas'               => $this->hasTag($elements, array( 'canvas' )),
            'contenteditable'      => $this->hasAttributeNamed($elements, 'contenteditable'),
            'interactivity_api'    => $this->hasAttributePrefix($elements, 'data-wp-'),
            'interactive_role'     => $this->hasInteractiveRole($elements),
            'inline_event_handlers' => $this->hasInlineEventHandler($elements),
            'repeatable_children'  => $this->repeatableChildCount($element),
            'media_gallery'        => $this->countDescendants($elements, array( 'img', 'picture', 'figure', 'video' )) >= 2,
            'cohesive_content_unit' => $this->isCohesiveContentUnit($elements),
            'ancestor_form'        => in_array('form', $this->resolveAncestorTags($element, $context), true),

            // CSS-derived signals (appearance only).
            'css_keyframes'    => $this->matches($css, '/@(?:-[a-z]+-)?keyframes\b/i'),
            'css_animation'    => $this->matches($css, '/(?:^|[;{\s])animation(?:-[a-z]+)?\s*:/i'),
            'css_transition'   => $this->matches($css, '/(?:^|[;{\s])transition(?:-[a-z]+)?\s*:/i'),
            'css_transform'    => $this->matches($css, '/(?:^|[;{\s])transform\s*:/i'),
            'css_sticky_fixed' => $this->matches($css, '/position\s*:\s*(?:sticky|fixed)\b/i'),

            // JS-derived signals.
            'js_present'         => trim($js) !== '',
            'js_classlist_toggle' => $jsClasslist,
            'js_style_mutation'  => $jsStyle,
            'js_scroll_listener' => $jsScroll,
            'js_parallax'        => $jsScroll && ( $jsStyle || $jsTransform ),
            'js_reads_input'     => $jsReadsInput,
            'js_network'         => $jsNetwork,
            'js_storage'         => $jsStorage,
            'js_state_mutation'  => $jsState,
            'js_global_scope'    => $jsGlobal,
            'js_component_scoped' => $jsScoped,
        );
    }

    /**
     * @return array<int, DOMElement>
     */
    private function collectElements(DOMElement $element): array
    {
        $out = array( $element );
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement ) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    /**
     * @param array<int, DOMElement> $elements
     * @param array<int, string>     $tags
     */
    private function countDescendants(array $elements, array $tags): int
    {
        $count = 0;
        foreach ( $elements as $element ) {
            if ( in_array(strtolower($element->tagName), $tags, true) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<int, DOMElement> $elements
     * @param array<int, string>     $tags
     */
    private function hasTag(array $elements, array $tags): bool
    {
        return $this->countDescendants($elements, $tags) > 0;
    }

    /**
     * @param array<int, DOMElement> $elements
     */
    private function hasSubmit(array $elements): bool
    {
        foreach ( $elements as $element ) {
            $tag = strtolower($element->tagName);
            if ( 'button' === $tag && 'submit' === strtolower($this->attr($element, 'type')) ) {
                return true;
            }
            if ( 'input' === $tag && in_array(strtolower($this->attr($element, 'type')), array( 'submit', 'button' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, DOMElement> $elements
     */
    private function hasAttributeNamed(array $elements, string $name): bool
    {
        foreach ( $elements as $element ) {
            if ( $element->hasAttribute($name) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, DOMElement> $elements
     */
    private function hasAttributePrefix(array $elements, string $prefix): bool
    {
        foreach ( $elements as $element ) {
            foreach ( $element->attributes ?? array() as $attribute ) {
                if ( str_starts_with(strtolower($attribute->nodeName), $prefix) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, DOMElement> $elements
     */
    private function hasInlineEventHandler(array $elements): bool
    {
        foreach ( $elements as $element ) {
            foreach ( $element->attributes ?? array() as $attribute ) {
                $name = strtolower($attribute->nodeName);
                if ( str_starts_with($name, 'on') && strlen($name) > 2 ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, DOMElement> $elements
     */
    private function hasInteractiveRole(array $elements): bool
    {
        foreach ( $elements as $element ) {
            $role = strtolower(trim($this->attr($element, 'role')));
            if ( '' !== $role && in_array($role, self::INTERACTIVE_ROLES, true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A heading grouped with media or body text — the shape of an editable
     * content unit (card / pricing / feature panel).
     *
     * @param array<int, DOMElement> $elements
     */
    private function isCohesiveContentUnit(array $elements): bool
    {
        $hasHeading = false;
        $hasBody    = false;
        foreach ( $elements as $element ) {
            $tag = strtolower($element->tagName);
            if ( in_array($tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true) ) {
                $hasHeading = true;
            }
            if ( in_array($tag, array( 'p', 'img', 'figure', 'picture' ), true) ) {
                $hasBody = true;
            }
        }

        return $hasHeading && $hasBody;
    }

    /**
     * Largest group of direct child elements that share the same tag + class
     * signature — the structural hallmark of a repeatable content unit. Returns
     * the size of that group (0 or 1 means "not repeatable").
     */
    private function repeatableChildCount(DOMElement $element): int
    {
        $signatures = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $classes = $this->classNames($child);
            sort($classes);
            $signature = strtolower($child->tagName) . '|' . implode('.', $classes);
            $signatures[$signature] = ( $signatures[$signature] ?? 0 ) + 1;
        }

        return empty($signatures) ? 0 : max($signatures);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAncestorTags(DOMElement $element, ClassificationContext $context): array
    {
        $explicit = $context->explicitAncestorTags();
        if ( ! empty($explicit) ) {
            return array_map('strtolower', $explicit);
        }

        return $this->ancestorTags($element);
    }

    private function matches(string $haystack, string $pattern): bool
    {
        return '' !== $haystack && 1 === preg_match($pattern, $haystack);
    }
}
