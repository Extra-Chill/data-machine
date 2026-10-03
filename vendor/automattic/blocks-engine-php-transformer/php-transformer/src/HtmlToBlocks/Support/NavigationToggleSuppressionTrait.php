<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMDocument;
use DOMElement;

trait NavigationToggleSuppressionTrait
{
    /**
     * A JS-only hamburger menu-toggle that is redundant chrome whenever it is
     * associated with a source navigation menu — whether or not that menu
     * converts to core/navigation.
     *
     * The toggle is detected GENERICALLY by structural/semantic signals — never
     * by a specific class string — so any framework's hamburger is recognized:
     * a <button> (or <a role="button">) carrying aria-controls and/or
     * aria-expanded whose visible content is empty/decorative bars (only empty
     * spans or an icon, no text label), or an input-free <label> containing a
     * nested stack of CSS-drawn bars. It is suppressed when it opens, lives
     * inside, or sits beside a source navigation menu. A converted menu already
     * ships its own responsive overlay hamburger; a menu that does NOT convert
     * still must not gain an always-visible dead hamburger the source hid behind
     * responsive CSS/JS the importer cannot carry (the "added UI" defect). Real
     * labeled buttons, and toggle-shaped controls with no associated navigation,
     * still convert to core/button normally.
     */
    private function isRedundantMenuToggleControl(DOMElement $element): bool
    {
        if ( ! $this->isHamburgerMenuToggleControl($element) ) {
            return false;
        }

        return $this->hasAssociatedNavigationMenu($element);
    }

    /**
     * Authoritatively record, in a single deterministic pass over the source
     * document, the selectors made redundant by every hamburger menu-toggle the
     * transformer treats as superseded by native navigation. A redundant
     * menu-toggle is always dropped from the output — whether by the element
     * converter, the navigation pattern's chrome handling, or the buttons
     * container — so scanning the source by the same `isRedundantMenuToggleControl`
     * predicate captures the superseded selectors independently of which drop
     * path executed, with no per-path bookkeeping.
     */
    private function collectSupersededNavToggleSelectors(DOMElement $root): void
    {
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isRedundantMenuToggleControl($element) ) {
                $this->recordSupersededNavToggleSelectors($element);
            }
        }
    }

    /**
     * Record the source selectors made redundant when a hamburger menu-toggle is
     * dropped in favor of the native navigation overlay: the toggle's own id and
     * class selectors, plus the id/class selectors of the menu/overlay it
     * controlled via `aria-controls`. A preserved site script may still reference
     * these selectors (e.g. `.nav-toggle`, `#nav-mobile`); the runtime-dependency
     * parity report uses this set to mark a resulting "missing DOM target"
     * finding as a superseded, acceptable loss rather than a materialization bug.
     * Only selectors of menu-toggles the transformer actually removed are
     * recorded, so genuinely-broken targets stay flagged.
     */
    private function recordSupersededNavToggleSelectors(DOMElement $toggle): void
    {
        $this->recordSupersededSelectorsForElement($toggle);

        foreach ( preg_split('/\s+/', trim($this->attr($toggle, 'aria-controls'))) ?: array() as $controlledId ) {
            $controlledId = ltrim(trim($controlledId), '#');
            if ( '' === $controlledId ) {
                continue;
            }

            $this->supersededRuntimeSelectors['#' . $controlledId] = true;

            $target = $this->elementWithId($toggle, $controlledId);
            if ( $target instanceof DOMElement && ! $target->isSameNode($toggle) ) {
                $this->recordSupersededSelectorsForElement($target);
            }
        }

        $nearbyOverlay = $this->nearbyNavigationOverlayForToggle($toggle);
        if ( $nearbyOverlay instanceof DOMElement ) {
            $this->recordSupersededSelectorsForElement($nearbyOverlay);
        }
    }

    private function nearbyNavigationOverlayForToggle(DOMElement $toggle): ?DOMElement
    {
        $container = $toggle->parentNode;
        while ( $container instanceof DOMElement && 'nav' !== strtolower($container->tagName) ) {
            $container = $container->parentNode;
        }

        if ( ! $container instanceof DOMElement ) {
            return null;
        }

        for ( $sibling = $container->nextSibling; null !== $sibling; $sibling = $sibling->nextSibling ) {
            if ( ! $sibling instanceof DOMElement ) {
                continue;
            }

            if ( $this->isNavigationOverlayCandidate($sibling) ) {
                return $sibling;
            }

            if ( in_array(strtolower($sibling->tagName), array('main', 'section', 'article'), true) ) {
                return null;
            }
        }

        return null;
    }

    private function isNavigationOverlayCandidate(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array('nav', 'ul', 'ol'), true) ) {
            return false;
        }

        $anchorCount = 0;
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && '' !== trim($anchor->textContent ?? '') ) {
                ++$anchorCount;
            }
        }

        if ( $anchorCount < 2 ) {
            return false;
        }

        $label = strtolower($this->attr($element, 'aria-label'));
        if ( str_contains($label, 'navigation') || str_contains($label, 'menu') || str_contains($label, 'mobile') ) {
            return true;
        }

        $role = strtolower($this->attr($element, 'role'));
        return 'navigation' === $role;
    }

    private function recordSupersededSelectorsForElement(DOMElement $element): void
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id ) {
            $this->supersededRuntimeSelectors['#' . $id] = true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                $this->supersededRuntimeSelectors['.' . $class] = true;
            }
        }
    }

    private function isHamburgerMenuToggleControl(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            return $this->isNestedHamburgerBarLabel($element);
        }

        $isButton = 'button' === $tagName;
        $isButtonRoleAnchor = 'a' === $tagName && 'button' === strtolower($this->attr($element, 'role'));
        if ( ! $isButton && ! $isButtonRoleAnchor ) {
            return false;
        }

        if ( '' !== $this->visibleMenuToggleLabel($element) ) {
            return false;
        }

        // ARIA-toggle shape: a labelless control that opens a menu via ARIA
        // state (aria-controls/aria-expanded), regardless of its icon markup.
        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        // Icon-bars shape: a labelless control whose only content is the stacked
        // empty <span> bars that draw a hamburger glyph, with no ARIA toggle
        // wiring. Many themes draw the bars with CSS on empty spans and bind the
        // open/close behavior in JS the importer cannot carry, so the control
        // arrives with no aria-* hooks at all — only its bar-stack shape betrays
        // it. Recognizing that shape (never a class string) lets these toggles be
        // dropped too, instead of surfacing as an empty, always-visible button.
        return $this->isHamburgerBarStackControl($element);
    }

    private function isNestedHamburgerBarLabel(DOMElement $element): bool
    {
        if ( $element->hasAttribute('for')
            || 0 !== $element->getElementsByTagName('input')->length
            || 0 !== $element->getElementsByTagName('select')->length
            || 0 !== $element->getElementsByTagName('textarea')->length ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('*') as $container ) {
            if ( ! $container instanceof DOMElement ) {
                continue;
            }

            $bars = 0;
            foreach ( $container->childNodes as $child ) {
                if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                    continue;
                }
                if ( ! $child instanceof DOMElement
                    || ! in_array(strtolower($child->tagName), array( 'div', 'span' ), true)
                    || '' !== trim($child->textContent ?? '')
                    || 0 !== $child->childNodes->length ) {
                    $bars = 0;
                    break;
                }
                ++$bars;
            }
            if ( $bars >= 2 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the control's only content is a stack of two or more empty <span>
     * bars: the framework-agnostic shape of a CSS-drawn hamburger glyph. A real
     * button carries a text label, an image, or other meaningful content, so it
     * is never matched. Genuinely empty controls (no bars) are not matched
     * either; only the deliberate multi-bar stack qualifies.
     */
    private function isHamburgerBarStackControl(DOMElement $element): bool
    {
        $emptyBars = 0;
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return false;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return false;
            }

            if ( 'span' !== strtolower($child->tagName)
                || '' !== trim($child->textContent ?? '')
                || 0 !== $child->getElementsByTagName('img')->length
                || 0 !== $child->getElementsByTagName('svg')->length ) {
                return false;
            }

            ++$emptyBars;
        }

        return $emptyBars >= 2;
    }

    /**
     * Visible text label of a control with decorative chrome (icons, empty
     * hamburger bars) stripped. Empty means the control shows no text label.
     */
    private function visibleMenuToggleLabel(DOMElement $element): string
    {
        $html = $this->innerHtml($element);
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        return trim($this->runtime->stripAllTags($html));
    }

    /**
     * Whether the toggle is associated with a source navigation menu: it opens
     * one via aria-controls, lives inside a navigation landmark, or sits beside a
     * navigation menu within its enclosing landmark. Association does NOT require
     * the menu to convert to core/navigation — a navbar whose links fail to
     * convert must still drop its dead hamburger rather than emit it as an
     * always-visible core/button.
     */
    private function hasAssociatedNavigationMenu(DOMElement $toggle): bool
    {
        $controlledIds = preg_split('/\s+/', trim($this->attr($toggle, 'aria-controls'))) ?: array();
        foreach ( $controlledIds as $controlledId ) {
            if ( '' === $controlledId ) {
                continue;
            }

            $target = $this->elementWithId($toggle, $controlledId);
            if ( $target instanceof DOMElement && ! $target->isSameNode($toggle) && $this->isAssociatedNavigationTarget($target) ) {
                return true;
            }
        }

        $scope = $this->menuToggleScope($toggle);
        if ( $this->isNavigationLandmark($scope) ) {
            return true;
        }

        foreach ( $scope->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || $candidate->isSameNode($toggle) ) {
                continue;
            }

            if ( $this->isAssociatedNavigationTarget($candidate) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an element is a navigation menu the toggle can be bound to: a
     * structural/semantic navigation menu candidate (nav landmark or signaled
     * list), or any container that converts to core/navigation (e.g. a signaled
     * direct-anchor menu div).
     */
    private function isAssociatedNavigationTarget(DOMElement $element): bool
    {
        return $this->isNavigationMenuCandidate($element) || $this->convertsToCoreNavigation($element);
    }

    private function isNavigationLandmark(DOMElement $element): bool
    {
        return 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role'));
    }

    /**
     * Nearest enclosing navigation/header landmark, or the document body, used
     * to bound the search for a sibling navigation menu.
     */
    private function menuToggleScope(DOMElement $toggle): DOMElement
    {
        for ( $node = $toggle->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $tagName = strtolower($node->tagName);
            if ( 'body' === $tagName ) {
                return $node;
            }

            if ( in_array($tagName, array( 'header', 'nav' ), true) || in_array(strtolower($this->attr($node, 'role')), array( 'banner', 'navigation' ), true) ) {
                return $node;
            }
        }

        return $toggle;
    }

    private function isNavigationMenuCandidate(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'nav' === $tagName || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        return in_array($tagName, array( 'ul', 'ol' ), true) && $this->hasSourceNavigationSignal($element);
    }

    private function convertsToCoreNavigation(DOMElement $element): bool
    {
        $navigation = $this->patternRecognizers->firstMatch($element, $this->probePatternContext());

        return null !== $navigation && 'core/navigation' === ($navigation['blockName'] ?? '');
    }

    private function elementWithId(DOMElement $context, string $id): ?DOMElement
    {
        $document = $context->ownerDocument;
        if ( ! $document instanceof DOMDocument ) {
            return null;
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $element->getAttribute('id') === $id ) {
                return $element;
            }
        }

        return null;
    }
}
