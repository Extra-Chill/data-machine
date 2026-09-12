<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class ButtonsPattern
{
    private const BLOCK_LEVEL_LABEL_TAGS = 'address|article|aside|blockquote|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul';

    private readonly ButtonStyleResolver $styleResolver;
    private readonly ButtonSignalClassifier $signalClassifier;

    public function __construct()
    {
        $this->styleResolver = new ButtonStyleResolver();
        $this->signalClassifier = new ButtonSignalClassifier();
    }

    /**
     * @param callable(DOMElement): array<string, mixed>|null $fileBlockFromAnchor
     * @param callable(DOMElement, array<int, string>): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchAnchor(DOMElement $anchor, callable $fileBlockFromAnchor, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $materializeSvgImages, callable $attr, callable $createBlock): ?array
    {
        $fileBlock = $fileBlockFromAnchor($anchor);
        if ( null !== $fileBlock ) {
            return $fileBlock;
        }

        if ( $this->isPositionedFragmentNavigation($anchor, (string) $resolvedStyle($anchor)) ) {
            return null;
        }

        $surface = $this->buttonSurfaceElement($anchor);
        if ( ! $this->hasButtonSignal($anchor, (string) $resolvedStyle($anchor), null !== $surface ? (string) $resolvedStyle($surface) : '') ) {
            return null;
        }

        return $createBlock('core/buttons', $this->buttonWrapperAttributes($anchor, $presentationAttributes, $resolvedStyle), array( $this->buttonBlockFromAnchor($anchor, $presentationAttributes, $resolvedStyle, $innerHtml, $materializeSvgImages, $attr, $createBlock) ), $anchor);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(DOMElement): bool $isGridItem
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    public function matchButton(DOMElement $button, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $materializeSvgImages, callable $isGridItem, callable $createBlock): array
    {
        $resolvedButtonStyle = trim((string) $resolvedStyle($button));
        $attrs = $this->buttonPresentationAttributes($button, $presentationAttributes, $resolvedStyle);
        if ( $isGridItem($button) ) {
            $attrs['width'] = 100;
        }
        if ( 100 === (int) ($attrs['width'] ?? 0) && $resolvedButtonStyle !== trim($button->getAttribute('style')) ) {
            $this->removeSourceControlClasses($attrs, $button);
        }
        $text = $this->buttonText($button, $innerHtml($button), $materializeSvgImages);

        return $createBlock('core/buttons', $this->buttonWrapperAttributes($button, $presentationAttributes, $resolvedStyle), array(
            $createBlock('core/button', array_filter(array_merge(
                $attrs,
                $this->buttonRuntimeAttributes($button),
                array(
                    'tagName' => 'button',
                    'text'    => $text,
                    'title'   => $this->buttonAccessibleTitle($button, $text),
                )
            ), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $button),
        ), $button);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>|null
     */
    public function matchContainer(DOMElement $element, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $materializeSvgImages, callable $attr, callable $createBlock): ?array
    {
        $wrappedAnchor = $this->singleSimpleAnchorChild($element);
        if ( null !== $wrappedAnchor && $this->hasWrapperButtonSignal($element, (string) $resolvedStyle($element)) ) {
            return $createBlock('core/buttons', $this->buttonWrapperAttributes($element, $presentationAttributes, $resolvedStyle), array( $this->buttonBlockFromAnchor($wrappedAnchor, $presentationAttributes, $resolvedStyle, $innerHtml, $materializeSvgImages, $attr, $createBlock, $element) ), $element);
        }

        $buttons = array();
        foreach ( $element->childNodes as $child ) {
            $surface = $child instanceof DOMElement ? $this->buttonSurfaceElement($child) : null;
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') && ! $this->isPositionedFragmentNavigation($child, (string) $resolvedStyle($child)) && $this->hasButtonSignal($child, (string) $resolvedStyle($child), null !== $surface ? (string) $resolvedStyle($surface) : '') ) {
                $buttons[] = $this->buttonBlockFromAnchor($child, $presentationAttributes, $resolvedStyle, $innerHtml, $materializeSvgImages, $attr, $createBlock);
            }
        }

        if ( count($buttons) <= 1 ) {
            return null;
        }

        return $createBlock('core/buttons', $presentationAttributes($element), $buttons, $element);
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @param callable(DOMElement): string $innerHtml
     * @param callable(DOMElement, string): ?string $materializeSvgImages
     * @param callable(DOMElement, string): string $attr
     * @param callable(string, array<string, mixed>, array<int, array<string, mixed>>, DOMElement|null, DOMElement|null): array<string, mixed> $createBlock
     * @return array<string, mixed>
     */
    private function buttonBlockFromAnchor(DOMElement $anchor, callable $presentationAttributes, callable $resolvedStyle, callable $innerHtml, callable $materializeSvgImages, callable $attr, callable $createBlock, ?DOMElement $presentationElement = null): array
    {
        $presentationElement ??= $this->buttonSurfaceElement($anchor) ?? $anchor;
        $resolvedPresentation = trim((string) $resolvedStyle($presentationElement));
        $hasAuthoredStyleRules = $resolvedPresentation !== trim($presentationElement->getAttribute('style'));
        $attrs = $this->buttonPresentationAttributes($presentationElement, $presentationAttributes, $resolvedStyle);
        if ( $presentationElement !== $anchor ) {
            $anchorStyle = (string) $resolvedStyle($anchor);
            if ( preg_match('/(?:^|;)\s*border\s*:\s*(?!0(?:;|$)|none(?:;|$))[^;]+/i', $anchorStyle)
                && ! preg_match('/(?:^|;)\s*border-radius\s*:/i', $anchorStyle)
            ) {
                $attrs['style']['border']['radius'] = '0';
            }
        }
        // core/button only saves className on its wrapper. Anchor-root selectors
        // are projected through the generated control marker onto the saved link.
        if ( $hasAuthoredStyleRules && ($presentationElement === $anchor || $presentationElement->parentNode === $anchor) ) {
            $this->removeSourceControlClasses($attrs, $presentationElement);
        }
        $text = $this->buttonText($anchor, $innerHtml($anchor), $materializeSvgImages);

        return $createBlock('core/button', array_filter(array_merge($attrs, array(
            'text'  => $text,
            'url'   => $attr($anchor, 'href'),
            'title' => $this->buttonAccessibleTitle($anchor, $text),
        )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value), array(), $presentationElement, $anchor);
    }

    /**
     * External spacing belongs to the core/buttons flex item, not its child link.
     *
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @return array<string, mixed>
     */
    private function buttonWrapperAttributes(DOMElement $element, callable $presentationAttributes, callable $resolvedStyle): array
    {
        $presentation = $presentationAttributes($element);
        $attrs = array();
        $margin = $presentation['style']['spacing']['margin'] ?? null;
        if ( is_array($margin) && array() !== $margin ) {
            $attrs['style']['spacing']['margin'] = $margin;
        }

        $alignment = $this->textAlignment((string) $resolvedStyle($element));
        if ( '' === $alignment && $element->parentNode instanceof DOMElement ) {
            $alignment = $this->textAlignment((string) $resolvedStyle($element->parentNode));
        }
        if ( in_array($alignment, array( 'left', 'center', 'right' ), true) ) {
            $attrs['layout'] = array(
                'type'           => 'flex',
                'justifyContent' => $alignment,
            );
        }

        return $attrs;
    }

    private function textAlignment(string $style): string
    {
        if ( preg_match_all('/(?:^|;)\s*text-align\s*:\s*(left|center|right)\b/i', $style, $matches) < 1 ) {
            return '';
        }

        return strtolower((string) end($matches[1]));
    }

    private function buttonSurfaceElement(DOMElement $anchor): ?DOMElement
    {
        $surface = null;
        foreach ( $anchor->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement || null !== $surface ) {
                return null;
            }
            $surface = $child;
        }

        $hasPresentationIdentity = $surface instanceof DOMElement
            && ( $surface->hasAttribute('class') || $surface->hasAttribute('id') || $surface->hasAttribute('style') );

        return $hasPresentationIdentity && in_array(strtolower($surface->tagName), array( 'div', 'span' ), true)
            ? $surface
            : null;
    }

    /** @param callable(DOMElement, string): ?string $materializeSvgImages */
    private function buttonText(DOMElement $element, string $html, callable $materializeSvgImages): string
    {
        $html = preg_replace('/<img\b[^>]*\balt\s*=\s*(["\'])(.*?)\1[^>]*>/is', '$2', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;
        $html = $materializeSvgImages($element, $html) ?? (preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html) ?? $html);
        $html = preg_replace('/<([a-z][a-z0-9]*)\b[^>]*\baria-hidden\s*=\s*(["\'])?true\2[^>]*>\s*<\/\1>/i', '', $html) ?? $html;
        $html = preg_replace('/<\/?(?:' . self::BLOCK_LEVEL_LABEL_TAGS . ')\b[^>]*>/i', '', $html) ?? $html;
        $html = $this->unwrapPresentationalSpan(trim($html));
        if ( '' !== trim($this->plainText($html)) || preg_match('/<img\b[^>]*>/i', $html) ) {
            return $html;
        }

        return '';
    }

    private function buttonAccessibleTitle(DOMElement $element, string $text): string
    {
        return html_entity_decode($this->accessibleFallbackLabel($element), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function accessibleFallbackLabel(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = trim($element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '');
            if ( '' !== $label ) {
                return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $image = $element->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            $alt = trim($image->hasAttribute('alt') ? $image->getAttribute('alt') : '');
            if ( '' !== $alt ) {
                return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $title = $element->getElementsByTagName('title')->item(0);
        if ( $title instanceof DOMElement && '' !== trim($title->textContent ?? '') ) {
            return htmlspecialchars(trim($title->textContent ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    /**
     * Strip a presentational <span> that wraps the entire button label.
     *
     * core/button stores its label as RichText, which only recognizes a fixed
     * set of inline formats (e.g. <strong>, <em>). A bare wrapping <span> is not
     * a format, so RichText drops it on parse: the saved markup no longer matches
     * the re-serialized block ("unexpected or invalid content") and the label is
     * captured as literal `<span>…</span>` markup instead of text. Removing the
     * non-format wrapper keeps the label as valid RichText that round-trips, while
     * genuine inline formats inside the span are preserved.
     */
    private function unwrapPresentationalSpan(string $html): string
    {
        while ( preg_match('/^<span\b[^>]*>(.*)<\/span>$/is', $html, $matches) === 1 && $this->spanWrapsEntireContent($matches[1]) ) {
            $html = trim($matches[1]);
        }

        return $html;
    }

    /**
     * True when the outer span's matching close is the final </span>, i.e. a
     * single span wraps the whole label rather than two sibling spans
     * (`<span>A</span> <span>B</span>`), which must not be unwrapped.
     */
    private function spanWrapsEntireContent(string $inner): bool
    {
        $depth = 0;
        if ( preg_match_all('/<(\/?)span\b[^>]*>/i', $inner, $matches) ) {
            foreach ( $matches[1] as $slash ) {
                $depth += '' === $slash ? 1 : -1;
                if ( $depth < 0 ) {
                    return false;
                }
            }
        }

        return 0 === $depth;
    }

    /**
     * @param callable(DOMElement): array<string, mixed> $presentationAttributes
     * @param callable(DOMElement): string $resolvedStyle
     * @return array<string, mixed>
     */
    private function buttonPresentationAttributes(DOMElement $element, callable $presentationAttributes, callable $resolvedStyle): array
    {
        $resolvedStyle = (string) $resolvedStyle($element);
        $width = $this->buttonWidth($resolvedStyle);
        // Resolve native paint before classifying an outline: a generic reset such
        // as `button { background: none }` can precede a filled button variant.
        // It is also resolved before the presentation attributes so the carrier
        // can be told which properties the native supports have already claimed.
        $native = $this->styleResolver->nativeAttributes($resolvedStyle);
        // Native core/button width owns only its canonical percentage values.
        // Other anchors and width values retain their generated geometry carrier.
        $excludedGeometry = null !== $width ? array( 'width' ) : array();
        // The source control is re-emitted as core/button's own chrome — a
        // `wp-block-button` wrapper around a `wp-block-button__link` — so the
        // source element's own formatting context no longer describes the
        // rendered markup. This is the same reasoning that drops the `layout`
        // attribute below; carrying the declarations onto the wrapper instead
        // would fight the button chrome rather than preserve the source.
        $excludedGeometry = array_merge($excludedGeometry, array(
            'display',
            'flex-direction',
            'flex-wrap',
            'align-items',
            'justify-content',
            'gap',
        ));
        // The `shadow` support is the editor-visible owner of this box-shadow.
        // A carrier rule for the same property on the same element would be a
        // second, competing source, and would desynchronise what the block editor
        // shows from what the front end renders.
        if ( '' !== trim((string) ($native['style']['shadow'] ?? '')) ) {
            $excludedGeometry[] = 'box-shadow';
        }
        $attrs = $presentationAttributes($element, $excludedGeometry);
        // Buttons resolve styling from the raw merged CSS string, not the canonical
        // block style object, so the (now object-shaped) presentation `style` is
        // dropped and re-derived via ButtonStyleResolver below.
        unset($attrs['style']);
        // core/button does not support a `layout` attribute (a flex/grid layout
        // belongs on the parent core/buttons, not each button). Emitting it here
        // produces an unsupported attribute and invalid block markup, so drop it.
        unset($attrs['layout']);
        $isOutline     = $this->hasOutlineSignal($element, $resolvedStyle, $native);
        if ( $isOutline ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-style-outline');
        }

        // Translate the resolved source CSS (inline style merged with the matched
        // <style>/linked CSS rules) into native core/button block attributes so the
        // button renders with its source colors/border instead of the theme default.
        // A button with no paintable styling resolves to no native attributes and
        // stays a default button.
        if ( array() !== $native ) {
            $attrs = array_merge($attrs, $native);
        }

		if ( null !== $width ) {
			$attrs['width'] = $width;
		}

        if ( $isOutline ) {
            if ( array() !== $native ) {
                $attrs['className'] = 'is-style-outline';
            }
            $attrs['style']['color']['background'] = 'transparent';
            if ( ! $this->hasBorderRadius($attrs) ) {
                $attrs['style']['border']['radius'] = '0';
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function hasBorderRadius(array $attrs): bool
    {
        return '' !== trim((string) ($attrs['style']['border']['radius'] ?? ''));
    }

    private function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

	private function buttonWidth(string $style): ?int
	{
		if ( ! preg_match('/(?:^|;)\s*width\s*:\s*(25|50|75|100)%\s*(?:;|$)/i', $style, $matches) ) {
			return null;
		}

		return (int) $matches[1];
	}

    /**
     * @return array<string, string>
     */
    private function buttonRuntimeAttributes(DOMElement $button): array
    {
        if ( ! $button->hasAttribute('type') ) {
            return array();
        }

        return array( 'type' => $button->getAttribute('type') );
    }

    private function hasRuntimeBehaviorSignal(DOMElement $element): bool
    {
        foreach ( array( 'aria-controls', 'aria-expanded', 'data-action', 'onclick', 'onchange', 'onsubmit' ) as $attribute ) {
            if ( $element->hasAttribute($attribute) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $attrs */
    private function removeSourceControlClasses(array &$attrs, DOMElement $element): void
    {
        $sourceClasses = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array();
        $classes = array_filter(
            preg_split('/\s+/', trim((string) ($attrs['className'] ?? ''))) ?: array(),
            static fn (string $class): bool => ! in_array($class, $sourceClasses, true)
        );
        if ( array() === $classes ) {
            unset($attrs['className']);
            return;
        }

        $attrs['className'] = implode(' ', $classes);
    }

    /** @param array<string, mixed> $native */
    private function hasOutlineSignal(DOMElement $element, string $style, array $native = array()): bool
    {
        if ( $this->hasAnyToken($element, array( 'outline', 'ghost', 'hollow', 'bordered' )) ) {
            return true;
        }

        $background = trim((string) ($native['style']['color']['background'] ?? ''));
        if ( '' !== $background && ! in_array(strtolower($background), array( 'transparent', 'none' ), true) ) {
            return false;
        }

        $normalized = strtolower($style);
        if ( ! preg_match('/(?:^|;)\s*border(?:-[a-z-]+)?\s*:\s*[^;]+/', $normalized) ) {
            return false;
        }

        if ( ! preg_match('/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $normalized, $matches) ) {
            return true;
        }

        return preg_match('/^(?:transparent|none|rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\))\s*$/i', trim((string) ($matches[1] ?? ''))) === 1;
    }

    private function hasButtonSignal(DOMElement $anchor, string $resolvedStyle = '', string $surfaceStyle = ''): bool
    {
        if ( $this->signalClassifier->hasTransformSignal($anchor, $resolvedStyle) ) {
            return true;
        }

        $surface = $this->buttonSurfaceElement($anchor);
        return null !== $surface && $this->signalClassifier->hasStyleSignal($surface, $surfaceStyle);
    }

    private function isPositionedFragmentNavigation(DOMElement $anchor, string $resolvedStyle): bool
    {
        $href = trim($anchor->getAttribute('href'));
        if ( ! str_starts_with($href, '#') || '#' === $href || 'button' === strtolower($anchor->getAttribute('role')) ) {
            return false;
        }

        return preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/i', $resolvedStyle) === 1;
    }

    private function hasWrapperButtonSignal(DOMElement $element, string $resolvedStyle): bool
    {
        if ( 'button' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return true;
        }

        return $this->signalClassifier->hasStyleSignal($element, $resolvedStyle);
	}

	private function singleSimpleAnchorChild(DOMElement $element): ?DOMElement
	{
		$anchor = null;
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( null !== $anchor || 'a' !== strtolower($child->tagName) || '' === trim($child->textContent ?? '') || ! $this->isSimpleAnchor($child) ) {
					return null;
				}
				$anchor = $child;
				continue;
			}

			if ( '' !== trim($child->textContent ?? '') ) {
				return null;
			}
		}

		return $anchor;
	}

	private function isSimpleAnchor(DOMElement $anchor): bool
	{
		foreach ( $anchor->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tagName = strtolower($child->tagName);
			if ( ! in_array($tagName, array( 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'svg', 'time' ), true) ) {
				return false;
			}

			if ( 'svg' === $tagName ) {
				continue;
			}

			if ( ! $this->isSimpleAnchor($child) ) {
				return false;
			}
		}

		return true;
	}

    /**
     * @param array<int, string> $tokens
     */
    private function hasAnyToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id' ) as $attribute ) {
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : '';
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($value)) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

}
