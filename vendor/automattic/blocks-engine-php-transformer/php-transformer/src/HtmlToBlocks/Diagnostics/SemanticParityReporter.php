<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\DomHelpersTrait;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TypographyParityAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Builds the semantic-parity report (the `html_semantic_parity_*` findings):
 * landmark count comparison, navigation-menu presence/item-count/label parity,
 * and the source-vs-block nav/landmark counting that backs those findings.
 *
 * This is a behavior-preserving extraction of the inline builders that
 * previously lived in HtmlTransformer. It performs the same DOM walks and
 * counting against the parsed source body, the generated blocks, and their
 * source provenance — all passed in as explicit parameters rather than read
 * from transformer state — so the report output is byte-identical to the
 * inline implementation.
 *
 * Shared DOM helpers come from DomHelpersTrait; the small `safeNavigationUrl`
 * helper is duplicated here (HtmlTransformer keeps its own copy for non-parity
 * callers) so this module needs no transformer reference.
 */
final class SemanticParityReporter
{
    use DomHelpersTrait;

    /**
     * The Runtime is required by DomHelpersTrait helpers (e.g. tag stripping in
     * normalizedNavigationLabel). It mirrors the transformer's own runtime so the
     * extracted builders behave identically to the inline implementation.
     */
    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>
     */
    public function report(DOMElement $body, array $blocks, array $sourceProvenance, string $html = '', string $staticCss = ''): array
    {
        $sourceLandmarks = $this->sourceLandmarkReport($body);
        $blockLandmarks = $this->blockLandmarkReport($blocks, $sourceProvenance, $sourceLandmarks);
        $sourceMenus = $this->sourceNavigationMenus($body);
        $blockMenus = $this->blockNavigationMenus($blocks);
        // Pair the two sides ONCE, before the carrier fold, and hand the same
        // pairing to both the fold and the comparison. Pairing after the fold
        // would be circular: the fold needs its paired source menu to decide
        // whether to run, so a pairing derived from folded items could hand the
        // fold a different menu than the comparison later reads.
        $menuPairing = $this->sourceToBlockMenuPairing($sourceMenus, $blockMenus);
        $blockMenus = $this->withCarriedItemsResolved($blockMenus, $sourceMenus, $menuPairing);
        $findings = $this->semanticParityFindings($sourceLandmarks, $blockLandmarks, $sourceMenus, $blockMenus, $menuPairing);
        // `excludes_outside_anchors` decides the carrier fold above; it is an
        // internal aid, not part of the published `semantic-parity/v1` payload,
        // so drop it the way the block side's `carried_sibling_items` is dropped.
        foreach ( $sourceMenus as $index => $_sourceMenu ) {
            unset($sourceMenus[$index]['excludes_outside_anchors']);
        }
        $findings = array_merge(
            $findings,
            ( new TypographyParityAnalyzer() )->findings($html, $staticCss, $this->inlineHeadingFontDeclarations($body))
        );

        $findings = array_map(
            fn (array $finding): array => $this->enrichSemanticParityFinding($finding, $body, $sourceProvenance),
            $findings
        );

        return array(
            'schema' => 'blocks-engine/php-transformer/semantic-parity/v1',
            'finding_schema' => ConversionFindingContract::SCHEMA,
            'status' => array() === $findings ? 'pass' : 'warning',
            'landmarks' => array(
                'source' => $sourceLandmarks['counts'],
                'blocks' => $blockLandmarks['counts'],
            ),
            'navigation_menus' => array(
                'source' => $sourceMenus,
                'blocks' => $blockMenus,
            ),
            'findings' => $findings,
        );
    }

    /**
     * Fold each carrier's hoisted links into the menu they belong to, so the
     * published record shows the same item list the parity comparison uses.
     * Leaving the two out of step would publish a menu counted as 5 beside a
     * source menu of 6 while reporting parity as a pass.
     *
     * The fold is skipped when the paired source menu already leaves outside
     * anchors out of its own list — a landmark bearing mobile chrome, or one
     * holding both a brand and a CTA beside its list — because then neither side
     * counts them.
     *
     * @param array<int, array<string, mixed>> $blockMenus
     * @param array<int, array<string, mixed>> $sourceMenus
     * @param array<int, int|null> $menuPairing source menu index => block menu index
     * @return array<int, array<string, mixed>>
     */
    private function withCarriedItemsResolved(array $blockMenus, array $sourceMenus, array $menuPairing): array
    {
        $sourceIndexes = array();
        foreach ( $menuPairing as $sourceIndex => $blockIndex ) {
            if ( null !== $blockIndex ) {
                $sourceIndexes[$blockIndex] = $sourceIndex;
            }
        }

        foreach ( $blockMenus as $index => $blockMenu ) {
            $carried = is_array($blockMenu['carried_sibling_items'] ?? null) ? $blockMenu['carried_sibling_items'] : array();
            unset($blockMenus[$index]['carried_sibling_items']);

            // Read the flag from the source menu this block menu is actually
            // compared against, not from the one sharing its position.
            $pairedSource = isset($sourceIndexes[$index]) ? ( $sourceMenus[$sourceIndexes[$index]] ?? array() ) : array();
            if ( array() === $carried || true === ($pairedSource['excludes_outside_anchors'] ?? false) ) {
                continue;
            }

            $items = array_merge(
                is_array($carried['before'] ?? null) ? $carried['before'] : array(),
                is_array($blockMenu['items'] ?? null) ? array_values($blockMenu['items']) : array(),
                is_array($carried['after'] ?? null) ? $carried['after'] : array()
            );

            $blockMenus[$index]['item_count'] = count($items);
            $blockMenus[$index]['items'] = $items;
        }

        return array_values($blockMenus);
    }

    /**
     * @return array{counts: array<string, int>, selectors: array<string, array<int, string>>}
     */
    private function sourceLandmarkReport(DOMElement $body): array
    {
        $counts = array('header' => 0, 'nav' => 0, 'main' => 0, 'footer' => 0);
        $selectors = array('header' => array(), 'nav' => array(), 'main' => array(), 'footer' => array());
        $seenNavigation = array();
        $this->collectSourceLandmarks($body, $counts, $selectors, $seenNavigation);

        return array('counts' => $counts, 'selectors' => $selectors);
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, array<int, string>> $selectors
     * @param array<string, bool> $seenNavigation
     */
    private function collectSourceLandmarks(DOMElement $element, array &$counts, array &$selectors, array &$seenNavigation): void
    {
        $landmark = $this->landmarkKindForElement($element);
        if ( '' !== $landmark ) {
            if ( 'nav' === $landmark ) {
                $signature = $this->sourceNavigationMenuSignature($this->sourceNavigationMenuItems($element));
                if ( '' !== $signature && isset($seenNavigation[$signature]) && $this->isMobileDuplicateSourceNavigation($element) ) {
                    return;
                }

                if ( '' !== $signature ) {
                    $seenNavigation[$signature] = true;
                }
            }

            ++$counts[$landmark];
            $selectors[$landmark][] = $this->elementSelector($element);
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $this->collectSourceLandmarks($child, $counts, $selectors, $seenNavigation);
            }
        }
    }

    private function landmarkKindForElement(DOMElement $element): string
    {
        return ShellLandmarkPolicy::landmarkKind(
            $element->tagName,
            $this->attr($element, 'role'),
            $this->hasAncestorTag($element, array( 'blockquote', 'figure' ))
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $sourceLandmarks
     * @return array{counts: array<string, int>, selectors: array<string, array<int, string>>}
     */
    private function blockLandmarkReport(array $blocks, array $sourceProvenance, array $sourceLandmarks): array
    {
        $counts = array('header' => 0, 'nav' => 0, 'main' => 0, 'footer' => 0);
        $selectors = array('header' => array(), 'nav' => array(), 'main' => array(), 'footer' => array());
        $this->collectBlockNavigationLandmarks($blocks, $counts);

        foreach ( array( 'header', 'main', 'footer' ) as $kind ) {
            foreach ( $sourceLandmarks['selectors'][$kind] ?? array() as $sourceSelector ) {
                if ( $this->sourceSelectorHasBlockRepresentation((string) $sourceSelector, $sourceProvenance) ) {
                    $selectors[$kind][] = (string) $sourceSelector;
                }
            }
            $counts[$kind] = count($selectors[$kind]);
        }

        return array('counts' => $counts, 'selectors' => $selectors);
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     */
    private function sourceSelectorHasBlockRepresentation(string $sourceSelector, array $sourceProvenance): bool
    {
        if ( '' === $sourceSelector ) {
            return false;
        }

        foreach ( $sourceProvenance as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }

            $blockSelector = (string) ($entry['selector'] ?? '');
            if ( $sourceSelector === $blockSelector || str_starts_with($blockSelector, $sourceSelector . ' > ') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, int> $counts
     */
    private function collectBlockNavigationLandmarks(array $blocks, array &$counts): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                ++$counts['nav'];
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockNavigationLandmarks($block['innerBlocks'], $counts);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceNavigationMenus(DOMElement $body): array
    {
        $menus = array();
        $seen = array();
        $this->collectSourceNavigationMenus($body, $menus, $seen);
        return $menus;
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param array<string, bool> $seen
     */
    private function collectSourceNavigationMenus(DOMElement $element, array &$menus, array &$seen): void
    {
        if ( 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            $items = $this->sourceNavigationMenuItems($element);

            $signature = $this->sourceNavigationMenuSignature($items);
            if ( '' !== $signature && isset($seen[$signature]) && $this->isMobileDuplicateSourceNavigation($element) ) {
                return;
            }

            if ( '' !== $signature ) {
                $seen[$signature] = true;
            }

            $menus[] = array(
                'selector' => $this->elementSelector($element),
                'item_count' => count($items),
                'items' => $items,
                'excludes_outside_anchors' => $this->sourceMenuExcludesOutsideAnchors($element),
            );
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $this->collectSourceNavigationMenus($child, $menus, $seen);
            }
        }
    }

    /**
     * Whether this menu's source item list already leaves out anchors that sit
     * outside the link cluster. When it does, the block side must not add them
     * back from a carrier group's siblings or the two sides double-disagree.
     */
    private function sourceMenuExcludesOutsideAnchors(DOMElement $element): bool
    {
        // A chrome-bearing landmark takes its items from the signaled containers
        // alone, which leaves out every direct-child anchor by construction.
        if ( $this->hasSourceNavigationChrome($element) ) {
            return true;
        }

        if ( ! $this->hasDirectNavigationBrandOrAction($element) ) {
            return false;
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement
                && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true)
                && array() !== $this->sourceNavigationMenuItems($child)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sourceNavigationMenuItems(DOMElement $element): array
    {
        if ( $this->hasSourceNavigationChrome($element) ) {
            return $this->sourceNavigationMenuItemsFromSignaledContainers($element);
        }

        // A list directly inside a navigation landmark is the menu when the
        // landmark also contains branding or a CTA. Core/navigation represents
        // that list, not its sibling controls.
        if ( $this->hasDirectNavigationBrandOrAction($element) ) {
            $listItems = array();
            foreach ( $element->childNodes as $child ) {
                if ( ! $child instanceof DOMElement || ! in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                    continue;
                }
                $items = $this->sourceNavigationMenuItems($child);
                if ( array() !== $items ) {
                    $listItems = array_merge($listItems, $items);
                }
            }
            if ( array() !== $listItems ) {
                return $listItems;
            }
        }

        $items = array();
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( ! $anchor instanceof DOMElement || $this->isSourceNavigationChromeAnchor($anchor) ) {
                continue;
            }

            $label = $this->sourceNavigationAnchorLabel($anchor);
            if ( '' !== $label ) {
                $items[] = array(
                    'label' => $label,
                    'url' => $this->safeNavigationUrl($this->attr($anchor, 'href')),
                );
            }
        }

        return $items;
    }

    private function hasDirectNavigationBrandOrAction(DOMElement $element): bool
    {
        $hasBrand = false;
        $hasAction = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'a' !== strtolower($child->tagName) ) {
                continue;
            }

            $tokens = strtolower($this->attr($child, 'class') . ' ' . $this->attr($child, 'id') . ' ' . $this->attr($child, 'aria-label'));
            $hasBrand = $hasBrand || (bool) preg_match('/(?:^|[^a-z0-9])(?:brand|logo|site-title|site-name|home-link|home-logo)(?:[^a-z0-9]|$)/', $tokens);
            $hasAction = $hasAction || (bool) preg_match('/(?:^|[^a-z0-9])(?:btn|button|cta|action)(?:[^a-z0-9]|$)/', $tokens);
        }

        return $hasBrand && $hasAction;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sourceNavigationMenuItemsFromSignaledContainers(DOMElement $element): array
    {
        $items = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'ul', 'ol' ), true) && $this->hasSourceNavigationSignal($child) ) {
                foreach ( $this->sourceNavigationMenuItems($child) as $item ) {
                    $items[] = $item;
                }
                continue;
            }

            if ( in_array($tagName, array( 'div', 'span', 'section' ), true) ) {
                foreach ( $this->sourceNavigationMenuItemsFromSignaledContainers($child) as $item ) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function hasSourceNavigationChrome(DOMElement $element): bool
    {
        $hasToggle = false;
        foreach ( $element->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement && $this->isSourceMenuToggleControl($button) ) {
                $hasToggle = true;
                break;
            }
        }

        if ( ! $hasToggle ) {
            return false;
        }

        $hasSignaledList = false;
        foreach ( $element->getElementsByTagName('ul') as $list ) {
            if ( $list instanceof DOMElement && $this->hasSourceNavigationSignal($list) ) {
                $hasSignaledList = true;
                break;
            }
        }

        if ( ! $hasSignaledList ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ! $this->hasAncestorTagWithin($anchor, array( 'ul', 'ol' ), $element) ) {
                return true;
            }
        }

        return false;
    }

    private function isSourceMenuToggleControl(DOMElement $element): bool
    {
        if ( 'button' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:hamburger|menu|toggle)(?:[^a-z0-9]|$)/', strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-label')));
    }

    /**
     * @param array<int, string> $tagNames
     */
    private function hasAncestorTagWithin(DOMElement $element, array $tagNames, DOMElement $boundary): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && ! $node->isSameNode($boundary); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), $tagNames, true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function sourceNavigationMenuSignature(array $items): string
    {
        $links = array();
        foreach ( $items as $item ) {
            $links[] = trim((string) ($item['label'] ?? '')) . '>' . trim((string) ($item['url'] ?? ''));
        }

        return implode('|', $links);
    }

    private function isMobileDuplicateSourceNavigation(DOMElement $element): bool
    {
        $tokens = array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
        );

        for ( $parent = $element->parentNode; $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName); $parent = $parent->parentNode ) {
            $tokens[] = $this->attr($parent, 'class');
            $tokens[] = $this->attr($parent, 'id');
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|hamburger|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', strtolower(implode(' ', $tokens)));
    }

    private function isSourceNavigationChromeAnchor(DOMElement $anchor): bool
    {
        if ( in_array(strtolower($this->attr($anchor, 'role')), array( 'separator', 'presentation', 'none' ), true) ) {
            return true;
        }

        // An anchor named only by an image's alt text is content, not decoration.
        // The label builder below already falls back to that alt, so excluding
        // such an anchor here left the source side counting one item fewer than
        // the block side represents.
        if ( '' === trim($anchor->textContent ?? '')
            && '' === trim($this->attr($anchor, 'aria-label') . $this->attr($anchor, 'title'))
            && '' === $this->anchorImageAltText($anchor)
        ) {
            return true;
        }

        $tokens = strtolower($this->attr($anchor, 'class') . ' ' . $this->attr($anchor, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:separator|divider)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function blockNavigationMenus(array $blocks): array
    {
        $menus = array();
        $this->collectBlockNavigationMenus($blocks, 'blocks', $menus, array());
        return $menus;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $menus
     * @param array<int, array<string, mixed>> $siblings
     */
    private function collectBlockNavigationMenus(array $blocks, string $path, array &$menus, array $siblings): void
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $blockPath = $path . '.' . $index;
            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                $items = array();
                $this->collectBlockNavigationItems(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $items);
                $menus[] = array(
                    'block_path' => $blockPath,
                    'represented_as_core_navigation' => true,
                    'item_count' => count($items),
                    'items' => $items,
                    'carried_sibling_items' => $this->carriedNavigationSiblingItems($siblings, $index),
                );
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $childSiblings = 'core/group' === ($block['blockName'] ?? '') && 'nav' === strtolower((string) ($block['attrs']['tagName'] ?? ''))
                    ? $block['innerBlocks']
                    : array();
                $this->collectBlockNavigationMenus($block['innerBlocks'], $blockPath . '.innerBlocks', $menus, $childSiblings);
            }
        }
    }

    /**
     * A navigation whose landmark is a core/group{tagName:"nav"} carrier shares
     * that landmark with blocks hoisted out of the menu — a branding anchor, for
     * instance. The source side counts every anchor under the landmark, so those
     * hoisted links belong to this menu's item list too; otherwise a faithful
     * hoist reads as content loss. Counting them here rather than excluding them
     * on the source side keeps a brand that goes missing entirely detectable.
     *
     * @param array<int, array<string, mixed>> $siblings
     * @return array{before: array<int, array<string, string>>, after: array<int, array<string, string>>}
     */
    private function carriedNavigationSiblingItems(array $siblings, int $navigationIndex): array
    {
        if ( array() === $siblings ) {
            return array( 'before' => array(), 'after' => array() );
        }

        $before = array();
        $after = array();
        foreach ( $siblings as $siblingIndex => $sibling ) {
            if ( ! is_array($sibling) || $siblingIndex === $navigationIndex || 'core/navigation' === ($sibling['blockName'] ?? '') ) {
                continue;
            }

            // One anchor can appear in more than one level of a block's saved
            // markup — a core/buttons wrapper and its core/button child both
            // carry it — so de-duplicate across each sibling's whole subtree.
            // Scope is per sibling: two siblings that genuinely link to the same
            // place are two anchors on the source side as well.
            $siblingItems = array();
            $seen = array();
            $this->collectBlockAnchorItems(array( $sibling ), $siblingItems, $seen);
            if ( array() === $siblingItems ) {
                continue;
            }

            if ( $siblingIndex < $navigationIndex ) {
                $before = array_merge($before, $siblingItems);
                continue;
            }

            $after = array_merge($after, $siblingItems);
        }

        return array( 'before' => $before, 'after' => $after );
    }

    /**
     * Anchor label/url pairs carried inside a block's rich text or link
     * attributes, in document order, shaped to match the source item records.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, string>> $items
     * @param array<string, true> $seen
     */
    private function collectBlockAnchorItems(array $blocks, array &$items, array &$seen): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();

            // Saved markup carries the anchor for blocks whose rich text is not
            // mirrored into an attribute — a synthetic paragraph wrapping a link,
            // for instance — so read both and de-duplicate by label+url below.
            $candidates = array();
            foreach ( array( 'content', 'text', 'value', 'caption' ) as $attribute ) {
                if ( isset($attrs[$attribute]) && is_string($attrs[$attribute]) ) {
                    $candidates[] = $attrs[$attribute];
                }
            }
            if ( isset($block['innerHTML']) && is_string($block['innerHTML']) ) {
                $candidates[] = $block['innerHTML'];
            }

            foreach ( $candidates as $markup ) {
                if ( ! str_contains($markup, '<a') ) {
                    continue;
                }

                if ( preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $markup, $matches, PREG_SET_ORDER) ) {
                    foreach ( $matches as $match ) {
                        $label = $this->normalizedNavigationLabel($match[3]);
                        if ( '' === $label ) {
                            // An anchor built from an image carries its name in
                            // the image's alt, which is what the source side
                            // counts it by. Without this the hoisted brand is
                            // invisible here and the two sides disagree.
                            $label = $this->markupImageAltText($match[3]);
                        }

                        if ( '' === $label ) {
                            continue;
                        }

                        $item = array(
                            'label' => $label,
                            'url' => $this->safeNavigationUrl(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                        );
                        $key = $item['label'] . "\0" . $item['url'];
                        if ( isset($seen[$key]) ) {
                            continue;
                        }

                        $seen[$key] = true;
                        $items[] = $item;
                    }
                }
            }

            // core/button keeps its destination in `url` and its label in `text`
            // rather than in anchor markup.
            if ( 'core/button' === ($block['blockName'] ?? '') && isset($attrs['url']) ) {
                $label = $this->normalizedNavigationLabel((string) ($attrs['text'] ?? ''));
                $url = $this->safeNavigationUrl((string) $attrs['url']);
                if ( '' !== $label && ! isset($seen[$label . "\0" . $url]) ) {
                    $seen[$label . "\0" . $url] = true;
                    $items[] = array( 'label' => $label, 'url' => $url );
                }
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockAnchorItems($block['innerBlocks'], $items, $seen);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, string>> $items
     */
    private function collectBlockNavigationItems(array $blocks, array &$items): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( in_array($block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
                $items[] = array(
                    'label' => $this->normalizedNavigationLabel((string) ($attrs['label'] ?? '')),
                    'url' => (string) ($attrs['url'] ?? ''),
                );
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockNavigationItems($block['innerBlocks'], $items);
            }
        }
    }

    /**
     * The alt text of the first image in a fragment of saved markup, normalised
     * the same way a label is. The block-side twin of anchorImageAltText().
     */
    private function markupImageAltText(string $markup): string
    {
        if ( ! preg_match_all('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1/is', $markup, $matches, PREG_SET_ORDER) ) {
            return '';
        }

        // The FIRST image that actually carries a name, not the first image. A
        // decorative `alt=""` ahead of the real one must not decide the answer,
        // or this disagrees with the transformer's own accessible-name test.
        foreach ( $matches as $match ) {
            $alt = $this->normalizedNavigationLabel(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ( '' !== $alt ) {
                return $alt;
            }
        }

        return '';
    }

    /**
     * The alt text of the first image inside an anchor, normalised the same way
     * a label is. This is what names an anchor built from an image alone.
     */
    private function anchorImageAltText(DOMElement $anchor): string
    {
        foreach ( $anchor->getElementsByTagName('img') as $image ) {
            $alt = $this->normalizedNavigationLabel($this->attr($image, 'alt'));
            if ( '' !== $alt ) {
                return $alt;
            }
        }

        return '';
    }

    private function sourceNavigationAnchorLabel(DOMElement $anchor): string
    {
        $label = $this->normalizedNavigationLabel($anchor->textContent ?? '');
        if ( '' !== $label ) {
            return $label;
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = $this->normalizedNavigationLabel($this->attr($anchor, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        return $this->anchorImageAltText($anchor);
    }

    /**
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $sourceLandmarks
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $blockLandmarks
     * @param array<int, array<string, mixed>> $sourceMenus
     * @param array<int, array<string, mixed>> $blockMenus
     * @param array<int, int|null> $menuPairing source menu index => block menu index
     * @return array<int, array<string, mixed>>
     */
    private function semanticParityFindings(array $sourceLandmarks, array $blockLandmarks, array $sourceMenus, array $blockMenus, array $menuPairing): array
    {
        $findings = array();
        foreach ( array( 'header', 'nav', 'main', 'footer' ) as $kind ) {
            $sourceCount = (int) ($sourceLandmarks['counts'][$kind] ?? 0);
            $blockCount = (int) ($blockLandmarks['counts'][$kind] ?? 0);
            if ( $sourceCount > $blockCount ) {
                $findings[] = array_filter(array(
                    'code' => 'landmark_count_mismatch',
                    'severity' => 'warning',
                    'kind' => $kind,
                    'source_count' => $sourceCount,
                    'block_count' => $blockCount,
                    'selector' => $sourceLandmarks['selectors'][$kind][0] ?? '',
                    'summary' => 'Source ' . $kind . ' landmarks exceed generated core block representation.',
                ), static fn (mixed $value): bool => '' !== $value);
            }
        }

        foreach ( $sourceMenus as $index => $sourceMenu ) {
            $blockMenuIndex = $menuPairing[$index] ?? null;
            $blockMenu = null === $blockMenuIndex ? null : ( $blockMenus[$blockMenuIndex] ?? null );
            if ( ! is_array($blockMenu) ) {
                $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
                $findings[] = array(
                    'code' => 'navigation_menu_missing',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'source_item_count' => $sourceMenu['item_count'] ?? 0,
                    'block_item_count' => 0,
                    'source_items' => $sourceItems,
                    'block_items' => array(),
                    'summary' => 'Source navigation menu was not represented as a core/navigation block.',
                );
                continue;
            }

            if ( true !== ($blockMenu['represented_as_core_navigation'] ?? false) ) {
                $findings[] = array(
                    'code' => 'navigation_core_block_missing',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'summary' => 'Generated navigation menu is not represented by core/navigation.',
                );
            }

            $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
            // Carrier siblings were already folded into `items` upstream, so both
            // sides are directly comparable here.
            $blockItems = is_array($blockMenu['items'] ?? null) ? array_values($blockMenu['items']) : array();

            if ( count($sourceItems) !== count($blockItems) ) {
                $findings[] = array(
                    'code' => 'navigation_item_count_mismatch',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'source_item_count' => count($sourceItems),
                    'block_item_count' => count($blockItems),
                    'source_items' => $sourceItems,
                    'block_items' => $blockItems,
                    'summary' => 'Source navigation item count differs from generated core navigation items.',
                );
                continue;
            }

            foreach ( $sourceItems as $itemIndex => $sourceItem ) {
                $blockItem = $blockItems[$itemIndex] ?? array();
                if ( ($sourceItem['label'] ?? '') !== ($blockItem['label'] ?? '') || ($sourceItem['url'] ?? '') !== ($blockItem['url'] ?? '') ) {
                    $findings[] = array(
                        'code' => 'navigation_item_mismatch',
                        'severity' => 'warning',
                        'selector' => $sourceMenu['selector'] ?? '',
                        'item_index' => $itemIndex,
                        'source_item' => $sourceItem,
                        'block_item' => $blockItem,
                        'summary' => 'Source navigation item label or URL differs from generated core navigation item.',
                    );
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Make a semantic-parity finding fix-ready by attaching additive context:
     * a stable `reason_code`, the bounded `source_snippet` for its selector, and
     * the `observed_block` output produced for that region (or an explicit
     * "none"). Strictly additive — never alters parity pass/fail counts.
     *
     * @param array<string, mixed> $finding
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>
     */
    private function enrichSemanticParityFinding(array $finding, DOMElement $body, array $sourceProvenance): array
    {
        if ( ! isset($finding['reason_code']) && isset($finding['code']) ) {
            $finding['reason_code'] = (string) $finding['code'];
        }

        if ( ! isset($finding['source_snippet']) ) {
            $finding['source_snippet'] = $this->semanticParitySourceSnippet((string) ($finding['selector'] ?? ''), $body);
        }

        if ( ! isset($finding['observed_block']) ) {
            $finding['observed_block'] = $this->observedBlockForFinding($finding, $sourceProvenance);
        }

        // Fill the remaining canonical classification fields (pattern_family /
        // repair_bucket) so each parity finding routes to a concrete repair lane;
        // the reason_code set above is honored and not overwritten.
        return ConversionFindingContract::withClassification($finding);
    }

    /**
     * Bounded source HTML for a finding selector, reusing the same bounded-HTML
     * helper used for runtime-island findings. Returns "none" when the selector
     * cannot be resolved back to a source element.
     */
    private function semanticParitySourceSnippet(string $selector, DOMElement $body): string
    {
        $element = $this->resolveSelectorElement($body, $selector);
        if ( ! $element instanceof DOMElement ) {
            return 'none';
        }

        $bounded = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        return '' === $bounded['html'] ? 'none' : $bounded['html'];
    }

    /**
     * Resolve a `tag:nth-of-type(n) > …` selector produced by elementSelector()
     * back to the source element, so findings can carry the offending markup.
     */
    private function resolveSelectorElement(DOMElement $body, string $selector): ?DOMElement
    {
        $selector = trim($selector);
        if ( '' === $selector ) {
            return null;
        }

        $current = $body;
        foreach ( explode(' > ', $selector) as $part ) {
            if ( ! preg_match('/^([a-z0-9]+):nth-of-type\((\d+)\)$/i', trim($part), $match) ) {
                return null;
            }

            $tag = strtolower($match[1]);
            $target = (int) $match[2];
            $index = 0;
            $found = null;
            foreach ( $current->childNodes as $child ) {
                if ( $child instanceof DOMElement && strtolower($child->tagName) === $tag ) {
                    ++$index;
                    if ( $index === $target ) {
                        $found = $child;
                        break;
                    }
                }
            }

            if ( ! $found instanceof DOMElement ) {
                return null;
            }

            $current = $found;
        }

        return $current === $body ? null : $current;
    }

    /**
     * Observed block output produced for a finding's region. Prefers block data
     * already carried by the finding, then provenance lookup by selector, and
     * falls back to an explicit "none" when nothing was generated.
     *
     * @param array<string, mixed> $finding
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>|string
     */
    private function observedBlockForFinding(array $finding, array $sourceProvenance): array|string
    {
        if ( isset($finding['block_items']) && is_array($finding['block_items']) && array() !== $finding['block_items'] ) {
            return array( 'block_items' => $finding['block_items'] );
        }

        if ( isset($finding['block_item']) && is_array($finding['block_item']) && array() !== $finding['block_item'] ) {
            return array( 'block_item' => $finding['block_item'] );
        }

        $blockNames = $this->blockNamesForSelector((string) ($finding['selector'] ?? ''), $sourceProvenance);

        return array() === $blockNames ? 'none' : array( 'block_names' => $blockNames );
    }

    /**
     * Block names whose source provenance selector matches or descends from the
     * given selector.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<int, string>
     */
    private function blockNamesForSelector(string $selector, array $sourceProvenance): array
    {
        if ( '' === $selector ) {
            return array();
        }

        $names = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }

            $blockSelector = (string) ($entry['selector'] ?? '');
            if ( $selector === $blockSelector || str_starts_with($blockSelector, $selector . ' > ') ) {
                $name = (string) ($entry['block_name'] ?? '');
                if ( '' !== $name ) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Heading-element inline `style="font-family:…"` declarations resolved from
     * the source DOM, for the typography parity diagnostic.
     *
     * @return array<int, array{family: string, selector: string, source_snippet: string}>
     */
    private function inlineHeadingFontDeclarations(DOMElement $body): array
    {
        $declarations = array();
        foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
            foreach ( $body->getElementsByTagName($tag) as $heading ) {
                if ( ! $heading instanceof DOMElement ) {
                    continue;
                }

                $style = $this->attr($heading, 'style');
                if ( '' === $style || ! preg_match('/font-family\s*:\s*([^;{}]+)/i', $style, $match) ) {
                    continue;
                }

                $bounded = $this->boundedFallbackHtml($this->safeFallbackHtml($heading));
                $declarations[] = array(
                    'family'         => trim((string) $match[1]),
                    'selector'       => $this->elementSelector($heading),
                    'source_snippet' => $bounded['html'],
                );
            }
        }

        return $declarations;
    }

    /**
     * Claim one block menu per source menu, in source order. Computed once and
     * shared by every consumer so a menu is never folded against one source
     * record and then compared against another.
     *
     * @param array<int, array<string, mixed>> $sourceMenus
     * @param array<int, array<string, mixed>> $blockMenus
     * @return array<int, int|null> source menu index => block menu index
     */
    private function sourceToBlockMenuPairing(array $sourceMenus, array $blockMenus): array
    {
        $pairing = array();
        $matchedBlockMenuIndexes = array();
        foreach ( $sourceMenus as $index => $sourceMenu ) {
            $blockMenuIndex = $this->matchingBlockNavigationMenuIndex($sourceMenu, $blockMenus, $matchedBlockMenuIndexes, $index);
            if ( null !== $blockMenuIndex ) {
                $matchedBlockMenuIndexes[$blockMenuIndex] = true;
            }

            $pairing[$index] = $blockMenuIndex;
        }

        return $pairing;
    }

    /**
     * @param array<string, mixed> $sourceMenu
     * @param array<int, array<string, mixed>> $blockMenus
     * @param array<int, true> $matchedBlockMenuIndexes
     */
    private function matchingBlockNavigationMenuIndex(array $sourceMenu, array $blockMenus, array $matchedBlockMenuIndexes, int $fallbackIndex): ?int
    {
        $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
        $sourceSignature = $this->navigationItemsSignature($sourceItems);
        if ( '' !== $sourceSignature ) {
            foreach ( $blockMenus as $index => $blockMenu ) {
                if ( isset($matchedBlockMenuIndexes[$index]) ) {
                    continue;
                }

                $blockItems = is_array($blockMenu['items'] ?? null) ? array_values($blockMenu['items']) : array();
                if ( $sourceSignature === $this->navigationItemsSignature($blockItems) ) {
                    return $index;
                }
            }
        }

        if ( isset($blockMenus[$fallbackIndex]) && ! isset($matchedBlockMenuIndexes[$fallbackIndex]) ) {
            return $fallbackIndex;
        }

        foreach ( $blockMenus as $index => $_blockMenu ) {
            if ( ! isset($matchedBlockMenuIndexes[$index]) ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function navigationItemsSignature(array $items): string
    {
        $parts = array();
        foreach ( $items as $item ) {
            $parts[] = trim((string) ($item['label'] ?? '')) . '>' . trim((string) ($item['url'] ?? ''));
        }

        return implode('|', $parts);
    }

}
