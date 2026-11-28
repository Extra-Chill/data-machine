<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use DOMElement;

final class NavigationPattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    private const BLOCK_LEVEL_LABEL_TAGS = 'address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul';

    private const LINK_COLOR_CLASS_PREFIX = 'blocks-engine-navigation-link-color-';

    private const CURRENT_COLOR_CLASS_PREFIX = 'blocks-engine-navigation-current-color-';

    private const LINK_COLOR_STATE_CLASS_PREFIX = 'blocks-engine-navigation-link-color-states-';

    private const DIRECT_NAVIGATION_CLASS = 'blocks-engine-direct-navigation';

    private const DIRECT_NAVIGATION_CARRIER_CLASS = 'blocks-engine-brand-navigation-carrier';

    private const DIRECT_NAVIGATION_LINK_COLOR_PREFIX = 'blocks-engine-direct-navigation-link-color-';

    /**
     * @return array<string, mixed>|null
     */
    public function match(DOMElement $element, PatternContext $context): ?array
    {
        $presentationAttributes = $context->presentationAttributesCallback();
        $innerHtml = $context->innerHtmlCallback();
        $createBlock = $context->createBlockCallback();
        $isRuntimeDomTarget = $context->isRuntimeDomTargetCallback();
        $navigationUnderlineColor = $context->navigationUnderlineColorCallback();
        $resolvedStyle = $context->resolvedStyleCallback();
        $navigationColorInteractionStates = $context->navigationColorInteractionStatesCallback();

        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) && ! $this->hasDirectListNavigationSignal($element) ) {
            return null;
        }

        if ( $this->hasNavigationChrome($element) ) {
            return null;
        }

        // A row of button-styled links (e.g. `<div class="stream-links"><a
        // class="stream-btn">…</a>…</div>`) is a call-to-action button group, not
        // site navigation. It matched here only because a container token like
        // `links` looks navigational, but its anchors carry button signals and
        // belong to the buttons pattern, which preserves their pill geometry and
        // styling. Defer so navigation does not flatten them into menu items.
        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasDirectListNavigationSignal($element) && $this->hasButtonStyledLinkChildren($element, $resolvedStyle) ) {
            return null;
        }

        // The carrier is offered the container BEFORE the deferral guard. Both
        // exist to stop a branding anchor being absorbed as a menu item, but the
        // carrier does it by emitting the brand as its own block beside a real
        // core/navigation, where the guard does it by giving up on the container
        // entirely. Consulting the guard first inverted the two: an anchor whose
        // class sits outside the brand vocabulary reached the carrier, while the
        // same markup naming itself `brand` deferred to a generic group and a
        // wp:list of raw anchors — no core/navigation for WordPress to mark the
        // current page in, and destinations left as inner-HTML hrefs rather than
        // a navigation-link `url`. The guard still catches every container the
        // carrier declines, so nothing it protected loses that protection.
        $hoisted = $this->brandAnchorCarrier($element, $presentationAttributes, $innerHtml, $createBlock, $context->convertElementCallback(), $isRuntimeDomTarget, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
        if ( null !== $hoisted ) {
            return $hoisted;
        }

        if ( $this->hasDirectBrandingAnchorBesideListNavigation($element, $innerHtml) ) {
            return null;
        }

        $links = $this->navigationBlocks($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, false, $navigationUnderlineColor, false, $resolvedStyle, $navigationColorInteractionStates);

        if ( array() === $links ) {
            return null;
        }

        $label = $this->directSectionLabel($element);
        $navigationAttrs = $label instanceof DOMElement
            ? $this->nestedLabeledNavigationAttributes($element, $presentationAttributes)
            : $this->navigationContainerAttributes($element, $presentationAttributes);
        $navigationAttrs['overlayMenu'] = 'mobile';
        if ( $label instanceof DOMElement ) {
            $navigationAttrs['layout'] = array( 'type' => 'flex', 'orientation' => 'vertical' );
        }

        // Declare responsive-overlay intent explicitly so the saved block carries
        // its interactive behavior in the content itself rather than relying on
        // WordPress applying the block.json `overlayMenu` default at render time.
        // `mobile` matches the core default: WP renders the responsive overlay
        // container and enqueues the `navigation/view` Interactivity module so the
        // hamburger menu functions on the rendered site (#native-interactivity).
        $commonTextAttrs = $this->commonNavigationLinkTextAttributes($links);
        if ( $this->isListNavigationSource($element) ) {
            unset($commonTextAttrs['style']['typography']);
        }
        $navigationAttrs = array_replace_recursive(
			$navigationAttrs,
            $commonTextAttrs
        );
        $currentTextColorClass = $this->currentNavigationTextColorClass($links);
        if ( '' !== $currentTextColorClass ) {
            $navigationAttrs['className'] = trim((string) ($navigationAttrs['className'] ?? '') . ' ' . $currentTextColorClass);
        }

        $navigation = $createBlock('core/navigation', $navigationAttrs, $links, $element);

        if ( ! $label instanceof DOMElement ) {
            return $navigation;
        }

        $labelTag = strtolower($label->tagName);
        $labelBlock = preg_match('/^h([1-6])$/', $labelTag, $matches)
            ? $createBlock('core/heading', array_merge($presentationAttributes($label), array(
                'content' => $innerHtml($label),
                'level' => (int) $matches[1],
            )), array(), $label)
            : $createBlock('core/paragraph', array_merge($presentationAttributes($label), array(
                'content' => $innerHtml($label),
            )), array(), $label);

        return $createBlock('core/group', array_merge($presentationAttributes($element), array( 'tagName' => 'div' )), array( $labelBlock, $navigation ), $element);
    }

    /**
     * A nav container that holds a branding anchor beside its link cluster
     * authors THREE elements — the landmark, the brand, and the menu — each with
     * its own CSS rule. Folding all three into one core/navigation makes the
     * brand a menu item: the landmark's own box rules then compete with the
     * menu list's rules on a single element, and the brand emits
     * `anchorClassName`, which core/navigation-link does not register.
     *
     * Emit the landmark as a carrier group instead, holding the brand block and
     * a core/navigation built from the link cluster alone. Structural position
     * does the work a class allowlist used to do — a direct-child anchor outside
     * the cluster — but position alone cannot tell a brand from an ordinary menu
     * item that happens to sit outside the list, so the anchor must also read as
     * a brand: a lockup (element children) or a brand/logo cue. A bare
     * `<a>Home</a>` beside the list stays a menu item.
     *
     * Not covered: an anchor holding only an image with no accessible name is
     * classified as navigation chrome before it reaches the brand test, so an
     * image-only logo is still dropped — as it is without this carrier.
     *
     * The carrier is restricted to a real `<nav>` landmark. That is load-bearing,
     * not cosmetic: a consumer's raw-anchor link resolution scopes by lexical
     * `<nav>` ancestry, so a brand hoisted out of the link set keeps its resolved
     * URL only while it renders inside a `<nav>`. A `div` carrier would put the
     * brand outside both that pass and the block pass that rewrites
     * `wp:navigation-link`, losing coverage the folded shape had.
     *
     * `hasDirectBrandingAnchorBesideListNavigation()` runs first and keeps
     * deferring the shapes it already recognises, so this covers exactly the
     * containers that would otherwise absorb the brand.
     *
     * @return array<string, mixed>|null
     */
    private function brandAnchorCarrier(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $convertElement, ?callable $isRuntimeDomTarget, ?callable $navigationUnderlineColor, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): ?array
    {
        if ( null === $convertElement ) {
            return null;
        }

        if ( 'nav' !== strtolower($element->tagName) ) {
            return null;
        }

        $anchor = null;
        $cluster = null;
        $brandLeads = true;
        $extras = array();
        $order = array();
        $buttonSignals = new ButtonSignalClassifier();
        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            if ( $this->isNavigationChromeElement($child) ) {
                // Chrome that scripts drive at runtime is not decoration: a
                // carrier group would drop it, so keep the source shape.
                if ( null !== $isRuntimeDomTarget && $isRuntimeDomTarget($child) ) {
                    return null;
                }
                continue;
            }

            // Block-level content inside the anchor is no obstacle here: the
            // carrier converts the anchor rather than flattening it into a menu
            // item label, so a lockup built from a heading survives whole.
            if ( 'a' === strtolower($child->tagName) ) {
                if ( '' === $this->anchorLabel($child, $innerHtml) ) {
                    return null;
                }

                if ( ! $anchor instanceof DOMElement ) {
                    if ( ! $this->readsAsBrandAnchor($child) ) {
                        return null;
                    }

                    $anchor = $child;
                    $brandLeads = ! $cluster instanceof DOMElement;
                    $order[] = array( 'kind' => 'brand' );
                    continue;
                }

                // A second anchor beside the brand and the menu is only carried
                // when it reads as a CALL TO ACTION — calm-lantern authors
                // `<a class="nav-cta">Book a Call</a>` after its list, and
                // declining the whole container over it cost that project its
                // entire navigation. Anything else stays ambiguous: position
                // alone cannot tell a CTA from an ordinary menu item sitting
                // outside the list, so the container still defers and the anchor
                // is absorbed into the menu exactly as it is today.
                if ( ! $buttonSignals->hasTransformSignal($child, null !== $resolvedStyle ? $resolvedStyle($child) : '') ) {
                    return null;
                }

                $extras[] = $child;
                $order[] = array( 'kind' => 'extra', 'index' => count($extras) - 1 );
                continue;
            }

            if ( $cluster instanceof DOMElement ) {
                return null;
            }

            $cluster = $child;
            $order[] = array( 'kind' => 'cluster' );
        }

        if ( ! $anchor instanceof DOMElement || ! $cluster instanceof DOMElement ) {
            return null;
        }

        // Settle every cheap structural question before converting anything.
        // Both conversions below run against the real block factory and record
        // provenance and runtime islands, so a bail after them leaves recorded
        // side effects behind for output that was never emitted.
        if ( 2 > $cluster->getElementsByTagName('a')->length ) {
            return null;
        }

        $links = $this->navigationBlocks($cluster, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, false, $navigationUnderlineColor, true, $resolvedStyle, $navigationColorInteractionStates);
        if ( 2 > count($links) ) {
            return null;
        }

        // An anchor that only converts to an HTML fallback would trade a menu
        // item for raw markup; keep today's shape rather than lose the block.
        $brand = $convertElement($anchor);
        $brandName = is_array($brand) ? (string) ($brand['blockName'] ?? '') : '';
        if ( '' === $brandName || 'core/html' === $brandName ) {
            return null;
        }

        // The link cluster owns the navigation block's presentation: it is the
        // element core/navigation stands in for, so the menu list's className
        // stays with the menu instead of being copied onto the landmark.
        $navigationAttrs = $this->navigationContainerAttributes($cluster, $presentationAttributes);
        if ( null !== $resolvedStyle && $this->isListNavigationSource($cluster) ) {
            $clusterSpacing = $this->resolvedNavigationSpacing((string) $resolvedStyle($cluster));
            $blockGap = trim((string) ($clusterSpacing['blockGap'] ?? ''));
            if ( '' !== $blockGap ) {
                // A two-axis authored gap is valid CSS but cannot be inferred
                // from core/navigation's one-axis default. Keep the exact
                // cascade winner from the source list.
                $navigationAttrs['style']['spacing']['blockGap'] = $blockGap;
            }

            if ( $this->resolvedStyleDeclaresFamily((string) $resolvedStyle($element), 'padding') ) {
                $padding = is_array($clusterSpacing['padding'] ?? null) ? $clusterSpacing['padding'] : array();
                if ( array() !== $padding ) {
                    // The carrier adds an inner nav that the authored outer-nav
                    // selector also reaches. Inline source-list padding restores
                    // the generated host to the element it replaces.
                    $navigationAttrs['style']['spacing']['padding'] = $padding;
                }
            }
        }
        $navigationAttrs['overlayMenu'] = 'mobile';
        $isDirectDivCluster = 'div' === strtolower($cluster->tagName);
        $isDirectDivCascadeCollision = $isDirectDivCluster
            && null !== $resolvedStyle
            && $this->hasDirectNavigationBoxCollision(
                (string) $resolvedStyle($element),
                (string) $resolvedStyle($cluster)
            );
        if ( $isDirectDivCascadeCollision ) {
            $navigationAttrs = $this->withClassName($navigationAttrs, self::DIRECT_NAVIGATION_CLASS);
            $links = $this->markDirectNavigationLinkColors($links);
            foreach ( array( 'margin', 'padding', 'max-width' ) as $family ) {
                if ( null !== $resolvedStyle
                    && $this->resolvedStyleDeclaresFamily((string) $resolvedStyle($element), $family)
                    && ! $this->resolvedStyleDeclaresFamily((string) $resolvedStyle($cluster), $family)
                ) {
                    $navigationAttrs = $this->withClassName($navigationAttrs, self::DIRECT_NAVIGATION_CLASS . '-reset-' . $family);
                }
            }
        }
        $commonTextAttrs = $this->commonNavigationLinkTextAttributes($links);
        if ( $this->isListNavigationSource($cluster) ) {
            unset($commonTextAttrs['style']['typography']);
        }
        $navigationAttrs = array_replace_recursive($navigationAttrs, $commonTextAttrs);
        $currentTextColorClass = $this->currentNavigationTextColorClass($links);
        if ( '' !== $currentTextColorClass ) {
            $navigationAttrs['className'] = trim((string) ($navigationAttrs['className'] ?? '') . ' ' . $currentTextColorClass);
        }

        $navigation = $createBlock('core/navigation', $navigationAttrs, $links, $cluster);

        // The carrier is the authored `<nav>`, so it keeps that tag (see above).
        // The authored `aria-label` does not come with it: core/group registers no
        // attribute that carries an accessible name, and inventing one would emit
        // exactly the unregistered comment attribute this carrier exists to stop.
        $carrierAttrs = array_merge($presentationAttributes($element), array( 'tagName' => 'nav' ));
        if ( $isDirectDivCascadeCollision ) {
            $carrierAttrs = $this->withClassName($carrierAttrs, self::DIRECT_NAVIGATION_CARRIER_CLASS);
        }

        $extraBlocks = array();
        foreach ( $extras as $extra ) {
            $extraBlock = $convertElement($extra);
            $extraName = is_array($extraBlock) ? (string) ($extraBlock['blockName'] ?? '') : '';
            if ( '' === $extraName || 'core/html' === $extraName ) {
                return null;
            }
            $extraBlocks[] = $extraBlock;
        }

        if ( array() === $extraBlocks ) {
            return $createBlock('core/group', $carrierAttrs, $brandLeads ? array( $brand, $navigation ) : array( $navigation, $brand ), $element);
        }

        // Authored order is the only order that reproduces the design: the CTA
        // sits where the source put it, not wherever the carrier finds room.
        $children = array();
        foreach ( $order as $slot ) {
            if ( 'brand' === $slot['kind'] ) {
                $children[] = $brand;
                continue;
            }
            if ( 'cluster' === $slot['kind'] ) {
                $children[] = $navigation;
                continue;
            }
            $children[] = $extraBlocks[$slot['index']];
        }

        return $createBlock('core/group', $carrierAttrs, $children, $element);
    }

    /**
     * Per-link colour support is retained in block attributes for diagnostics,
     * but core/navigation-link does not render it. Add a deterministic class to
     * the runtime item so engine-support CSS can address the generated anchor.
     *
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array<string, mixed>>
     */
    private function markDirectNavigationLinkColors(array $links): array
    {
        foreach ( $links as &$link ) {
            $attrs = is_array($link['attrs'] ?? null) ? $link['attrs'] : array();
            $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
            if ( '' !== $color ) {
                $link['attrs'] = $this->withClassName(
                    $attrs,
                    self::DIRECT_NAVIGATION_LINK_COLOR_PREFIX . substr(hash('sha256', $color), 0, 12)
                );
            }
            if ( is_array($link['innerBlocks'] ?? null) ) {
                $link['innerBlocks'] = $this->markDirectNavigationLinkColors($link['innerBlocks']);
            }
        }
        unset($link);

        return $links;
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private function withClassName(array $attrs, string $className): array
    {
        $classes = preg_split('/\s+/', trim((string) ($attrs['className'] ?? '') . ' ' . $className)) ?: array();
        $attrs['className'] = implode(' ', array_values(array_unique(array_filter($classes))));
        return $attrs;
    }

    private function resolvedStyleDeclaresFamily(string $style, string $family): bool
    {
        $property = match ( $family ) {
            'margin' => 'margin(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?',
            'padding' => 'padding(?:-(?:top|right|bottom|left|inline|inline-start|inline-end|block|block-start|block-end))?',
            default => preg_quote($family, '/'),
        };

        return 1 === preg_match('/(?:^|;)\s*' . $property . '\s*:/i', $style);
    }

    /** @return array<string, mixed> */
    private function resolvedNavigationSpacing(string $style): array
    {
        $declarations = array();
        foreach ( CssValueSplitter::splitTopLevel($style, array( ';' )) as $declaration ) {
            $separator = strpos($declaration, ':');
            if ( false === $separator ) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $separator)));
            $value = trim(substr($declaration, $separator + 1));
            if ( '' === $property || '' === $value ) {
                continue;
            }
            $declarations[$property] = $value;
        }

        $mapped = ( new StyleAttributeMapper() )->map($declarations);
        $styleObject = is_array($mapped['style'] ?? null) ? $mapped['style'] : array();
        return is_array($styleObject['spacing'] ?? null) ? $styleObject['spacing'] : array();
    }

    private function hasDirectNavigationBoxCollision(string $landmarkStyle, string $clusterStyle): bool
    {
        // Enter this compatibility path only when replacing a plain div with a
        // nested nav duplicates the landmark's complete centered box. Partial
        // box ownership is not enough evidence that resetting the generated
        // host preserves the source element's authored layout.
        foreach ( array( 'margin', 'padding', 'max-width' ) as $family ) {
            if ( ! $this->resolvedStyleDeclaresFamily($landmarkStyle, $family)
                || $this->resolvedStyleDeclaresFamily($clusterStyle, $family)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a direct-child anchor reads as branding rather than as a menu item
     * that happens to sit outside the list. A lockup — an anchor built from
     * element children such as a name plus a location, or a heading — is the
     * structural signal; an explicit brand/logo cue is accepted as well so a
     * single-line wordmark still qualifies. Bare anchor text does not.
     */
    private function readsAsBrandAnchor(DOMElement $anchor): bool
    {
        foreach ( $anchor->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return true;
            }
        }

        return $this->hasBrandAnchorSignal($anchor);
    }

    private function directSectionLabel(DOMElement $element): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isSectionLabelElement($child) ) {
                return $child;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function nestedLabeledNavigationAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        $attrs = $this->navigationContainerAttributes($element, $presentationAttributes);
        $blockGap = (string) ($attrs['style']['spacing']['blockGap'] ?? '');
        return '' === $blockGap ? array() : array(
            'style' => array( 'spacing' => array( 'blockGap' => $blockGap ) ),
        );
    }

    /**
     * Core navigation links render text styles from their parent block context.
     * Promote a strict-majority colour so current/CTA exceptions do not erase
     * the menu default. Typography still requires unanimity; genuinely mixed
     * menus retain their companion CSS rather than receiving a false uniform
     * native style.
     *
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    private function commonNavigationLinkTextAttributes(array $links): array
    {
        $first = $links[0]['attrs'] ?? array();
        if ( ! is_array($first) ) {
            return array();
        }

        $attrs = array();
        foreach ( array( 'textColor' ) as $name ) {
            $value = $this->strictMajorityNavigationLinkString($links, static fn (array $linkAttrs): mixed => $linkAttrs[ $name ] ?? null);
            if ( null !== $value ) {
                $attrs[ $name ] = $value;
            }
        }

        $customTextColor = $this->strictMajorityNavigationLinkString($links, static fn (array $linkAttrs): mixed => $linkAttrs['style']['color']['text'] ?? null);
        if ( null !== $customTextColor ) {
            $attrs['customTextColor'] = $customTextColor;
        }

        $typography = is_array($first['style']['typography'] ?? null) ? $first['style']['typography'] : array();
        foreach ( $typography as $name => $value ) {
            if ( $this->allNavigationLinksShare($links, static fn (array $linkAttrs): mixed => $linkAttrs['style']['typography'][ $name ] ?? null, $value) ) {
                $attrs['style']['typography'][ $name ] = $value;
            }
        }

        return $attrs;
    }

    /**
     * A menu can author one default colour plus exceptional current/CTA items.
     * Core renders link colour from the parent navigation context, so requiring
     * unanimity drops that default whenever an exception exists. Carry a value
     * only when it is an unambiguous strict majority across every link; ties and
     * genuinely mixed menus retain their per-link companion CSS without an
     * invented parent colour.
     *
     * @param array<int, array<string, mixed>> $links
     */
    private function strictMajorityNavigationLinkString(array $links, callable $value): ?string
    {
        $counts = array();
        $values = array();
        foreach ( $links as $link ) {
            $linkAttrs = is_array($link['attrs'] ?? null) ? $link['attrs'] : array();
            $candidate = $value($linkAttrs);
            if ( ! is_string($candidate) || '' === trim($candidate) ) {
                continue;
            }

            // Prefix with length so a numeric-looking string remains a string
            // key instead of PHP coercing it to an integer array key.
            $key = strlen($candidate) . ':' . $candidate;
            $counts[ $key ] = ($counts[ $key ] ?? 0) + 1;
            $values[ $key ] = $candidate;
        }

        $threshold = count($links) / 2;
        foreach ( $counts as $key => $count ) {
            if ( $count > $threshold ) {
                return $values[ $key ];
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $links */
    private function allNavigationLinksShare(array $links, callable $value, mixed $expected): bool
    {
        foreach ( $links as $link ) {
            $linkAttrs = is_array($link['attrs'] ?? null) ? $link['attrs'] : array();
            if ( $value($linkAttrs) !== $expected ) {
                return false;
            }
        }

        return true;
    }

    private function safeNavigationUrl(string $url): string
    {
        return LinkUrlSanitizer::sanitize($url);
    }

    private function hasDirectBrandingAnchorBesideListNavigation(DOMElement $element, callable $innerHtml): bool
    {
        if ( 'nav' !== strtolower($element->tagName) && ! $this->hasNavigationSignal($element) ) {
            return false;
        }

        $hasDirectAnchor = false;
        $hasListNavigation = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'a' === $tagName && $this->hasBrandAnchorSignal($child) && '' !== $this->anchorLabel($child, $innerHtml) && ! preg_match('/<(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b/i', $innerHtml($child)) ) {
                $hasDirectAnchor = true;
                continue;
            }

            if ( in_array($tagName, array( 'ul', 'ol' ), true) && array() !== $this->navigationBlocksFromList($child, static fn (): array => array(), $innerHtml, static fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => array(
                'blockName'   => $name,
                'attrs'       => $attrs,
                'innerBlocks' => $innerBlocks,
            )) ) {
                $hasListNavigation = true;
            }
        }

        return $hasDirectAnchor && $hasListNavigation;
    }

    /**
     * A brand cue is a TOKEN an author chose to name the element with — a class,
     * an id, a link relation. It is deliberately not read from `aria-label` or
     * `title`, whose values are human prose written for a screen reader or a
     * tooltip: "Brand new products" and "Download our logo" are sentences that
     * happen to contain the vocabulary, not claims that the anchor is branding.
     * Substring-searching prose for `brand` and `logo` classified both as
     * branding and hoisted a real menu item out of its menu.
     *
     * `rel` is read as a token attribute for consistency, but note that no
     * standard link relation matches this vocabulary: `rel="home"` does NOT
     * qualify, because the vocabulary carries `home-link` and `home-logo` rather
     * than a bare `home`. Only an authored `rel` such as `logo` or `home-link`
     * reaches it, so today the `rel` read is very nearly inert.
     */
    private function hasBrandAnchorSignal(DOMElement $anchor): bool
    {
        $haystack = strtolower(trim($this->attr($anchor, 'class') . ' ' . $this->attr($anchor, 'id') . ' ' . $this->attr($anchor, 'rel')));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:brand|branding|logo|site-title|site-name|home-link|home-logo)(?:[^a-z0-9]|$)/', $haystack);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationBlocks(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, bool $allowsDescriptiveChrome = false, ?callable $navigationUnderlineColor = null, bool $itemsAreVouched = false, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): array
    {
        $blocks = array();
        $allowsDescriptiveChrome = $allowsDescriptiveChrome || $this->hasSubmenuSignal($element);
        // A caller that has already established structurally that this element IS
        // the nav's link cluster vouches for its direct anchors. The class
        // vocabulary cannot do it: it splits on non-alphanumerics, so `navlinks`
        // is a single token matching neither `nav` nor `links`, and amber-ember's
        // whole menu became paragraphs on that spelling alone.
        $allowsDirectItems = $itemsAreVouched || $allowsDescriptiveChrome || 'nav' === strtolower($element->tagName) || $this->hasNavigationSignal($element) || $this->hasSubmenuSignal($element) || in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true);
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            return $this->navigationBlocksFromList($element, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
        }

        foreach ( $element->childNodes as $child ) {
            if ( XML_COMMENT_NODE === $child->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isSectionLabelElement($child) ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isNavigationChromeElement($child) ) {
                if ( null !== $isRuntimeDomTarget && $isRuntimeDomTarget($child) ) {
                    return array();
                }
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== $this->anchorLabel($child, $innerHtml) ) {
                if ( ! $allowsDirectItems ) {
                    return array();
                }
                $blocks[] = $this->navigationLinkBlock($child, $presentationAttributes, $innerHtml, $createBlock, $child, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
                continue;
            }

            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                $listBlocks = $this->navigationBlocksFromList($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
                if ( array() === $listBlocks ) {
                    return array();
                }
                $blocks = array_merge($blocks, $listBlocks);
                continue;
            }

            if ( $child instanceof DOMElement ) {
                if ( ! $allowsDirectItems ) {
                    return array();
                }

                $block = $this->navigationBlockFromItem($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
                if ( null !== $block ) {
                    $blocks[] = $block;
                    continue;
                }

                if ( $this->isNavigationWrapperElement($child) ) {
                    $wrappedBlocks = $this->navigationBlocks($child, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $allowsDescriptiveChrome, $navigationUnderlineColor, false, $resolvedStyle, $navigationColorInteractionStates);
                    if ( array() !== $wrappedBlocks ) {
                        $blocks = array_merge($blocks, $wrappedBlocks);
                        continue;
                    }
                }

                if ( $allowsDescriptiveChrome && ! $this->containsNavigationAnchor($child, $innerHtml) ) {
                    continue;
                }
            }

            return array();
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationBlocksFromList(DOMElement $list, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, ?callable $navigationUnderlineColor = null, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): array
    {
        $blocks = array();
        foreach ( $list->childNodes as $item ) {
            if ( XML_COMMENT_NODE === $item->nodeType ) {
                continue;
            }

            if ( XML_TEXT_NODE === $item->nodeType && '' === trim($item->textContent ?? '') ) {
                continue;
            }

            if ( $item instanceof DOMElement && $this->isNavigationChromeElement($item) ) {
                continue;
            }

            if ( ! $item instanceof DOMElement || 'li' !== strtolower($item->tagName) ) {
                return array();
            }

            $block = $this->navigationBlockFromItem($item, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
            if ( null === $block ) {
                return array();
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    private function navigationBlockFromItem(DOMElement $element, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?callable $isRuntimeDomTarget = null, ?callable $navigationUnderlineColor = null, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): ?array
    {
        $anchor = $this->primaryNavigationAnchor($element);
        if ( ! $anchor instanceof DOMElement || '' === $this->anchorLabel($anchor, $innerHtml) ) {
            return null;
        }

        $submenuBlocks = array();
        foreach ( $this->submenuContainers($element, $anchor) as $submenuContainer ) {
            foreach ( $this->navigationBlocks($submenuContainer, $presentationAttributes, $innerHtml, $createBlock, $isRuntimeDomTarget, true, null, false, $resolvedStyle, $navigationColorInteractionStates) as $submenuBlock ) {
                $submenuBlocks[] = $submenuBlock;
            }
        }

        if ( array() !== $submenuBlocks ) {
            if ( 1 !== count($this->anchorsExcludingSubmenus($element, $anchor)) ) {
                return null;
            }

            $submenuAttrs = array(
                'label' => $this->anchorLabel($anchor, $innerHtml),
                'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
                'kind'  => 'custom',
            );
            $submenuContainer = $this->submenuContainers($element, $anchor)[0] ?? null;
            return $createBlock('core/navigation-submenu', $this->navigationItemAttributes($element, $anchor, $submenuContainer, $submenuAttrs, $presentationAttributes, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates), $submenuBlocks, $element);
        }

        if ( 1 !== count($this->anchorsExcludingSubmenus($element, $anchor)) ) {
            return null;
        }

        return $this->navigationLinkBlock($anchor, $presentationAttributes, $innerHtml, $createBlock, $element, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates);
    }

    private function navigationLinkBlock(DOMElement $anchor, callable $presentationAttributes, callable $innerHtml, callable $createBlock, ?DOMElement $item = null, ?callable $navigationUnderlineColor = null, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): array
    {
        return $createBlock('core/navigation-link', $this->navigationItemAttributes($item ?? $anchor, $anchor, null, array(
            'label' => $this->anchorLabel($anchor, $innerHtml),
            'url'   => $this->safeNavigationUrl($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''),
            'kind'  => 'custom',
        ), $presentationAttributes, $navigationUnderlineColor, $resolvedStyle, $navigationColorInteractionStates), array(), $anchor);
    }

    private function anchorLabel(DOMElement $anchor, callable $innerHtml): string
    {
        $label = $this->navigationLabel($innerHtml($anchor));
        if ( '' !== $label ) {
            return $label;
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $fallback = trim($this->attr($anchor, $attribute));
            if ( '' !== $fallback ) {
                return htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        // The first image that carries a name, not the first image: a decorative
        // `alt=""` ahead of the real one must not decide the label, or this
        // disagrees with anchorCarriesAccessibleName() and the anchor is kept as
        // named content while being labelled empty.
        foreach ( $anchor->getElementsByTagName('img') as $image ) {
            $alt = trim($this->attr($image, 'alt'));
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return '';
    }

    private function navigationLabel(string $html): string
    {
        $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html;
        $html = preg_replace('/<span\b[^>]*>\s*<\/span>/i', '', $html) ?? $html;
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b[^>]*>/i', '', $html) ?? $html;
        $html = trim($html);

        // Markup carrying no text of its own is not a label. An anchor built from
        // an image alone would otherwise hand back the `<img>` tag as its label
        // and emit a navigation link labelled with raw markup, instead of letting
        // `anchorLabel()` fall through to the image's own alt text.
        if ( '' === trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ) {
            return '';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $baseAttrs
     * @return array<string, mixed>
     */
    private function navigationItemAttributes(DOMElement $item, DOMElement $anchor, ?DOMElement $submenuContainer, array $baseAttrs, callable $presentationAttributes, ?callable $navigationUnderlineColor = null, ?callable $resolvedStyle = null, ?callable $navigationColorInteractionStates = null): array
    {
        $isCurrentNavigationItem = $this->hasCurrentNavigationSignal($item) || $this->hasCurrentNavigationSignal($anchor);
        $itemAttrs = $item->isSameNode($anchor) ? array() : $this->withoutCoreNavigationClasses($presentationAttributes($item));
        $anchorAttrs = $this->withoutCoreNavigationClasses($presentationAttributes($anchor));
        $submenuAttrs = $submenuContainer instanceof DOMElement ? $this->withoutCoreNavigationClasses($presentationAttributes($submenuContainer)) : array();
        if ( $isCurrentNavigationItem ) {
            // Current-page identity belongs to WordPress/runtime URL state. A
            // source snapshot's active/current/selected hooks must not remain
            // on one permanently selected item in the shared header.
            $itemAttrs = $this->withoutAuthoredCurrentNavigationSignals($itemAttrs);
            $anchorAttrs = $this->withoutAuthoredCurrentNavigationSignals($anchorAttrs);
            $submenuAttrs = $this->withoutAuthoredCurrentNavigationSignals($submenuAttrs);
        }
        if ( '' === (string) ($itemAttrs['className'] ?? '') && '' !== (string) ($anchorAttrs['className'] ?? '') ) {
            $itemAttrs['className'] = $anchorAttrs['className'];
        }

        $itemAttrs = array_replace_recursive($itemAttrs, $this->navigationAnchorTextAttributes($anchorAttrs, 'a' === strtolower($item?->tagName ?? 'a')));
        if ( null !== $resolvedStyle ) {
            $resolvedTextColor = $this->navigationTextColorFromStyle($resolvedStyle($anchor));
            if ( '' !== $resolvedTextColor ) {
                $itemAttrs['style']['color']['text'] = $resolvedTextColor;
            }
        }

        $textColor = trim((string) ($itemAttrs['style']['color']['text'] ?? ''));
        if ( '' !== $textColor ) {
            $stateMask = $this->navigationColorStateMask($anchor, $navigationColorInteractionStates);
            $itemAttrs['className'] = trim((string) ($itemAttrs['className'] ?? '') . ' '
                . self::LINK_COLOR_STATE_CLASS_PREFIX . $stateMask);
            if ( ! $isCurrentNavigationItem ) {
                $itemAttrs['className'] .= ' ' . self::LINK_COLOR_CLASS_PREFIX
                    . hash('sha256', $textColor . "\0" . $stateMask);
            }
        }

        if ( $isCurrentNavigationItem ) {
            $itemAttrs['className'] = trim((string) ($itemAttrs['className'] ?? '') . ' blocks-engine-current-navigation-item');
            $decorationColor = null !== $navigationUnderlineColor ? trim((string) $navigationUnderlineColor($item, $anchor)) : '';
            $sourceDecoration = strtolower(trim((string) ($anchorAttrs['style']['typography']['textDecoration'] ?? $itemAttrs['style']['typography']['textDecoration'] ?? '')));
            if ( 'underline' === $sourceDecoration || '' !== $decorationColor ) {
                $itemAttrs['className'] .= ' blocks-engine-current-navigation-underline';
                $baseAttrs['style']['typography']['textDecoration'] = 'underline';
            }
            if ( '' === $decorationColor && 'underline' === $sourceDecoration ) {
                $decorationColor = $this->activeNavigationUnderlineColor($anchorAttrs, $itemAttrs);
            }
            if ( '' !== $decorationColor ) {
                $baseAttrs['style']['typography']['textDecorationColor'] = $decorationColor;
            }
        }

        // The anchor/submenu CSS rides on the preserved classNames + companion CSS;
        // a raw inline `style` string on the navigation-link/submenu inner markup
        // would diverge from the block save() output, so it is not emitted (#261).
        return array_filter(array_replace_recursive($itemAttrs, $baseAttrs, array(
            'anchorClassName'  => $anchorAttrs['className'] ?? '',
            'submenuClassName' => $submenuAttrs['className'] ?? '',
        )), static fn ($value): bool => '' !== $value);
    }

    private function navigationTextColorFromStyle(string $style): string
    {
        if ( 1 !== preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $match) ) {
            return '';
        }

        return trim(preg_replace('/\s*!\s*important\s*$/i', '', $match[1]) ?? $match[1]);
    }

    private function navigationColorStateMask(DOMElement $anchor, ?callable $navigationColorInteractionStates): int
    {
        if ( null === $navigationColorInteractionStates ) {
            return 0;
        }

        $mask = 0;
        $bits = array( 'hover' => 1, 'focus' => 2, 'focus-visible' => 4, 'active' => 8 );
        foreach ( $navigationColorInteractionStates($anchor) as $state ) {
            $mask |= $bits[$state] ?? 0;
        }

        return $mask;
    }

    /** @param array<int, array<string, mixed>> $links */
    private function currentNavigationTextColorClass(array $links): string
    {
        $colors = array();
        $collect = static function (array $items) use (&$collect, &$colors): void {
            foreach ( $items as $link ) {
                $attrs = is_array($link['attrs'] ?? null) ? $link['attrs'] : array();
                $className = (string) ($attrs['className'] ?? '');
                $color = trim((string) ($attrs['style']['color']['text'] ?? ''));
                if ( '' !== $color && str_contains($className, 'blocks-engine-current-navigation-item') ) {
                    $stateMask = 0;
                    if ( preg_match('/(?:^|\s)' . preg_quote(self::LINK_COLOR_STATE_CLASS_PREFIX, '/') . '(\d+)(?:\s|$)/', $className, $match) ) {
                        $stateMask = (int) $match[1];
                    }
                    $colors[$color . "\0" . $stateMask] = array( 'color' => $color, 'state_mask' => $stateMask );
                }

                $collect(is_array($link['innerBlocks'] ?? null) ? $link['innerBlocks'] : array());
            }
        };
        $collect($links);

        if ( 1 !== count($colors) ) {
            return '';
        }

        $current = reset($colors);
        return self::CURRENT_COLOR_CLASS_PREFIX . hash('sha256', $current['color'] . "\0" . $current['state_mask']);
    }


    /**
     * Carry inheritable anchor paint and typography through core's dynamic link.
     * Box styles remain owned by the source classes and companion stylesheet.
     *
     * @param array<string, mixed> $anchorAttrs
     * @return array<string, mixed>
     */
    private function navigationAnchorTextAttributes(array $anchorAttrs, bool $includeTypography = true): array
    {
        $attrs = array();
        if ( isset($anchorAttrs['textColor']) ) {
            $attrs['textColor'] = $anchorAttrs['textColor'];
        }

        $style = is_array($anchorAttrs['style'] ?? null) ? $anchorAttrs['style'] : array();
        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
        $textColor = $style['color']['text'] ?? null;
        if ( $includeTypography && array() !== $typography ) {
            $attrs['style']['typography'] = $typography;
        }
        if ( is_string($textColor) && '' !== trim($textColor) ) {
            $attrs['style']['color']['text'] = trim($textColor);
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $anchorAttrs
     * @param array<string, mixed> $itemAttrs
     */
    private function activeNavigationUnderlineColor(array $anchorAttrs, array $itemAttrs): string
    {
        foreach ( array( $anchorAttrs, $itemAttrs ) as $attrs ) {
            $textColor = $attrs['style']['color']['text'] ?? null;
            if ( is_string($textColor) && '' !== trim($textColor) ) {
                return trim($textColor);
            }
        }

        foreach ( array( $anchorAttrs, $itemAttrs ) as $attrs ) {
            $style = $attrs['style'] ?? null;
            if ( ! is_array($style) ) {
                continue;
            }
            $serialized = $this->serializedStyleColor($style);
            if ( '' !== $serialized ) {
                return $serialized;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $style
     */
    private function serializedStyleColor(array $style): string
    {
        $serialized = (string) json_encode($style);
        if ( preg_match('/"(?:textDecorationColor|borderColor|color)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/', $serialized, $match) ) {
            $decoded = json_decode('"' . $match[1] . '"');
            return is_string($decoded) ? trim($decoded) : '';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationContainerAttributes(DOMElement $element, callable $presentationAttributes): array
    {
        $attrs = $this->withoutCoreNavigationClasses($presentationAttributes($element));
        if ( $this->isListNavigationSource($element) ) {
            $attrs['className'] = trim((string) ($attrs['className'] ?? '') . ' blocks-engine-list-navigation');
        }
        if ( '' !== (string) ($attrs['style']['spacing']['blockGap'] ?? '') ) {
            return $attrs;
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                continue;
            }

            $listAttrs = $this->withoutCoreNavigationClasses($presentationAttributes($child));
            $listClasses = trim((string) ($listAttrs['className'] ?? ''));
            if ( '' !== $listClasses ) {
                $attrs['className'] = implode(' ', array_values(array_unique(array_filter(preg_split('/\s+/', trim((string) ($attrs['className'] ?? '') . ' ' . $listClasses)) ?: array()))));
            }

            $listGap = (string) ($listAttrs['style']['spacing']['blockGap'] ?? '');
            if ( '' !== $listGap ) {
                $attrs['style']['spacing']['blockGap'] = $listGap;
            }
            break;
        }

        if ( $this->isListNavigationSource($element) && '' === (string) ($attrs['style']['spacing']['blockGap'] ?? '') ) {
            // Core navigation adds its own default gap; source lists do not.
            $attrs['style']['spacing']['blockGap'] = '0px';
        }

        return $attrs;
    }

    private function isListNavigationSource(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array( 'ul', 'ol' ), true) ) {
            return true;
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function withoutCoreNavigationClasses(array $attrs): array
    {
        if ( empty($attrs['className']) || ! is_string($attrs['className']) ) {
            return $attrs;
        }

        $classNames = array_values(array_filter(preg_split('/\s+/', trim($attrs['className'])) ?: array(), static function (string $className): bool {
            return ! in_array($className, array(
                'wp-block-navigation',
                'wp-block-navigation-item',
                'wp-block-navigation-link',
                'wp-block-navigation-submenu',
                'wp-block-navigation__container',
                'wp-block-navigation__submenu-container',
            ), true);
        }));

        if ( array() === $classNames ) {
            unset($attrs['className']);
            return $attrs;
        }

        $attrs['className'] = implode(' ', $classNames);
        return $attrs;
    }

    /** @param array<string, mixed> $attrs @return array<string, mixed> */
    private function withoutAuthoredCurrentNavigationSignals(array $attrs): array
    {
        if ( is_string($attrs['className'] ?? null) ) {
            $classNames = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['className'])) ?: array(),
                fn (string $className): bool => ! $this->isCurrentNavigationSignalName($className)
            ));
            if ( array() === $classNames ) {
                unset($attrs['className']);
            } else {
                $attrs['className'] = implode(' ', $classNames);
            }
        }

        if ( is_string($attrs['anchor'] ?? null) && $this->isCurrentNavigationSignalName($attrs['anchor']) ) {
            unset($attrs['anchor']);
        }

        return $attrs;
    }

    private function hasCurrentNavigationSignal(DOMElement $element): bool
    {
        if ( '' !== trim($this->attr($element, 'aria-current')) ) {
            return true;
        }

        return $this->isCurrentNavigationSignalName($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
    }

    private function isCurrentNavigationSignalName(string $value): bool
    {
        foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
            if ( in_array($token, array( 'active', 'current', 'selected' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function primaryNavigationAnchor(DOMElement $element): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( 'a' === strtolower($child->tagName) ) {
                return $child;
            }

            if ( in_array(strtolower($child->tagName), array( 'span', 'div', 'p' ), true) ) {
                $anchor = $this->primaryNavigationAnchor($child);
                if ( $anchor instanceof DOMElement ) {
                    return $anchor;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function submenuContainers(DOMElement $element, DOMElement $primaryAnchor): array
    {
        $containers = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($primaryAnchor) ) {
                continue;
            }

            if ( $this->isNavigationChromeElement($child) ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'nav', 'ul', 'ol' ), true)
                || $this->hasSubmenuSignal($child)
                || ( $this->isNavigationWrapperElement($child)
                    && ( 0 < $child->getElementsByTagName('ul')->length || 0 < $child->getElementsByTagName('ol')->length ) )
            ) {
                $containers[] = $child;
            }
        }

        return $containers;
    }

    private function hasSubmenuSignal(DOMElement $element): bool
    {
        if ( 'menu' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        foreach ( array( 'class', 'id', 'role' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'dropdown', 'mega', 'megamenu', 'submenu', 'subnav', 'flyout' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isMenuToggleControl(DOMElement $element): bool
    {
        if ( 'button' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-label'))) ?: array() as $token ) {
            if ( in_array($token, array( 'hamburger', 'menu', 'toggle' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function isNavigationChromeElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( $this->isMenuToggleControl($element) ) {
            return true;
        }

        if ( in_array(strtolower($this->attr($element, 'role')), array( 'separator', 'presentation', 'none' ), true) ) {
            return true;
        }

        if ( in_array($tagName, array( 'hr', 'svg' ), true) ) {
            return true;
        }

        if ( 'a' === $tagName && ! $this->anchorCarriesAccessibleName($element) ) {
            return true;
        }

        $tokens = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));

        // A separator or a divider is decoration by authored intent, whatever it
        // happens to link to, so the destination escape below must not rescue
        // it. `isSourceNavigationChromeAnchor()` in the parity reporter reads
        // exactly these two tokens as chrome on the source side; rescuing them
        // here would leave the two sides counting different menus.
        if ( preg_match('/(?:^|[^a-z0-9])(?:separator|divider)(?:[^a-z0-9]|$)/', $tokens) ) {
            return true;
        }

        // An anchor that names itself AND points somewhere is content, whatever
        // its class happens to be called. The vocabulary below matches on the
        // bare word `toggle`, which an authored `lang-toggle` or `theme-toggle`
        // satisfies while being an ordinary link.
        if ( 'a' === $tagName && $this->anchorNavigatesToDestination($element) ) {
            return false;
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:toggle|hamburger|menu-button|menu-toggle)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * Whether an anchor carries an accessible name: visible text, an explicit
     * `aria-label`/`title`, or an image whose `alt` names it.
     *
     * `anchorLabel()` already falls back to a descendant image's `alt`, so the
     * chrome test has to agree with it. While it did not, an image brand the
     * author had named — `<a class="mark"><img alt="Harbor"></a>` — was read as
     * decoration and dropped from the output entirely, and because such an
     * anchor contributes no text the source menu never counted it as an item,
     * so semantic parity reported `pass` with no findings while the brand
     * disappeared.
     */
    private function anchorCarriesAccessibleName(DOMElement $anchor): bool
    {
        if ( '' !== trim($anchor->textContent ?? '') ) {
            return true;
        }

        if ( '' !== trim($this->attr($anchor, 'aria-label') . $this->attr($anchor, 'title')) ) {
            return true;
        }

        foreach ( $anchor->getElementsByTagName('img') as $image ) {
            if ( '' !== trim($this->attr($image, 'alt')) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an anchor points at a destination rather than driving an in-page
     * control. A menu toggle authored as an anchor targets a fragment, a
     * `javascript:` URL or nothing at all, and usually declares `aria-controls`
     * or `aria-expanded`; a language or theme switcher targets a real URL.
     */
    private function anchorNavigatesToDestination(DOMElement $anchor): bool
    {
        if ( $anchor->hasAttribute('aria-controls') || $anchor->hasAttribute('aria-expanded') ) {
            return false;
        }

        $href = trim($this->attr($anchor, 'href'));
        if ( '' === $href || str_starts_with($href, '#') ) {
            return false;
        }

        return ! str_starts_with(strtolower($href), 'javascript:');
    }

    private function isNavigationWrapperElement(DOMElement $element): bool
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'span', 'section' ), true) ) {
            return false;
        }

        $hasNavigationChild = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( $this->isSectionLabelElement($child) || $this->isDescriptiveNavigationChromeElement($child) ) {
                continue;
            }

            if ( 'a' !== $tagName && 0 === $child->getElementsByTagName('a')->length ) {
                continue;
            }

            if ( in_array($tagName, array( 'a', 'ul', 'ol' ), true) || $this->hasNavigationSignal($child) || $this->isNavigationChromeElement($child) ) {
                $hasNavigationChild = true;
                continue;
            }

            if ( ! $this->isNavigationWrapperElement($child) ) {
                return false;
            }

            $hasNavigationChild = true;
        }

        return $hasNavigationChild;
    }

    private function containsNavigationAnchor(DOMElement $element, callable $innerHtml): bool
    {
        if ( 'a' === strtolower($element->tagName) && '' !== $this->anchorLabel($element, $innerHtml) ) {
            return true;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && '' !== $this->anchorLabel($anchor, $innerHtml) ) {
                return true;
            }
        }

        return false;
    }

    private function isDescriptiveNavigationChromeElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'p', 'small' ), true);
    }

    private function isSectionLabelElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( preg_match('/^h[1-6]$/', $tagName) ) {
            return true;
        }

        if ( ! in_array($tagName, array( 'span', 'p', 'strong', 'b' ), true) ) {
            return false;
        }

        $tokens = strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:label|heading|title)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function hasNavigationChrome(DOMElement $element): bool
    {
        $hasToggle = false;
        foreach ( $element->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement && $this->isMenuToggleControl($button) ) {
                $hasToggle = true;
                break;
            }
        }

        if ( ! $hasToggle ) {
            return false;
        }

        $hasList = false;
        foreach ( $element->getElementsByTagName('ul') as $list ) {
            if ( $list instanceof DOMElement && $this->hasNavigationSignal($list) ) {
                $hasList = true;
                break;
            }
        }

        if ( ! $hasList ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ! $this->hasListAncestor($anchor, $element) ) {
                return true;
            }
        }

        return false;
    }

    private function hasListAncestor(DOMElement $element, DOMElement $boundary): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && ! $node->isSameNode($boundary); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function anchorsExcludingSubmenus(DOMElement $element, DOMElement $primaryAnchor): array
    {
        $anchors = array();
        $submenuContainers = $this->submenuContainers($element, $primaryAnchor);
        $this->collectAnchorsExcluding($element, $anchors, $submenuContainers);
        return $anchors;
    }

    /**
     * @param array<int, DOMElement> $anchors
     * @param array<int, DOMElement> $excluded
     */
    private function collectAnchorsExcluding(DOMElement $element, array &$anchors, array $excluded): void
    {
        foreach ( $excluded as $excludedElement ) {
            if ( $element->isSameNode($excludedElement) ) {
                return;
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( 'a' === strtolower($child->tagName) ) {
                $anchors[] = $child;
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'span', 'div', 'p' ), true) || $this->hasSubmenuSignal($child) ) {
                $this->collectAnchorsExcluding($child, $anchors, $excluded);
            }
        }
    }

    /**
     * Whether the container's direct link children are button-styled call-to-
     * action anchors rather than navigation links. Requires every direct anchor
     * to carry a button signal so a genuine nav menu with one incidental
     * button-classed link is not misclassified.
     */
    /** @param callable(DOMElement): string|null $resolvedStyle */
    private function hasButtonStyledLinkChildren(DOMElement $element, ?callable $resolvedStyle): bool
    {
        $classifier = new ButtonSignalClassifier();
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) ) {
                $anchors[] = $child;
            }
        }
        if ( 2 > count($anchors) ) {
            return false;
        }

        foreach ( $anchors as $anchor ) {
            if ( ! $classifier->hasTransformSignal($anchor, null !== $resolvedStyle ? $resolvedStyle($anchor) : '') ) {
                return false;
            }
        }

        return true;
    }

    private function hasNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, array( 'nav', 'navbar', 'navigation', 'menu' ), true) ) {
                    return true;
                }
                if ( 'links' === $token && ! $this->isContactLinkCluster($element) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isContactLinkCluster(DOMElement $element): bool
    {
        $anchors = array();
        $this->collectAnchorsExcluding($element, $anchors, array());
        if ( array() === $anchors ) {
            return false;
        }

        foreach ( $anchors as $anchor ) {
            $href = strtolower(trim($anchor->hasAttribute('href') ? $anchor->getAttribute('href') : ''));
            if ( ! preg_match('/^(?:tel|mailto|sms):/', $href) ) {
                return false;
            }
        }

        return true;
    }

    private function hasDirectListNavigationSignal(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) && $this->hasNavigationSignal($child) ) {
                return true;
            }
        }

        return false;
    }
}
