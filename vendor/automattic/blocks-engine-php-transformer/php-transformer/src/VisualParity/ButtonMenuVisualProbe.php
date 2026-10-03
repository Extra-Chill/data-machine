<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\VisualParity;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonSignalClassifier;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class ButtonMenuVisualProbe
{
    public const SCHEMA = 'blocks-engine/php-transformer/visual-parity-probes/v1';

    private readonly ButtonSignalClassifier $buttonSignalClassifier;

    private const STYLE_FIELDS = array(
        'background',
        'background-color',
        'border',
        'border-bottom',
        'border-bottom-color',
        'border-bottom-left-radius',
        'border-bottom-right-radius',
        'border-bottom-style',
        'border-bottom-width',
        'border-color',
        'border-left-color',
        'border-left-style',
        'border-left-width',
        'border-radius',
        'border-right-color',
        'border-right-style',
        'border-right-width',
        'border-style',
        'border-top-color',
        'border-top-left-radius',
        'border-top-right-radius',
        'border-top-style',
        'border-top-width',
        'border-width',
        'box-shadow',
        'color',
        'display',
        'font-size',
        'font-weight',
        'gap',
        'height',
        'justify-content',
        'line-height',
        'margin',
        'margin-bottom',
        'margin-left',
        'margin-right',
        'margin-top',
        'min-height',
        'min-width',
        'padding',
        'padding-bottom',
        'padding-left',
        'padding-right',
        'padding-top',
        'text-align',
        'text-decoration',
        'text-transform',
        'width',
    );

    public function __construct()
    {
        $this->buttonSignalClassifier = new ButtonSignalClassifier();
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $sourceHtml = preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ? $html : '<body>' . $this->bodyHtml($html) . '</body>';
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?>' . $sourceHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return array(
                'schema' => self::SCHEMA,
                'status' => 'failed',
                'probes' => array(),
                'summary' => array(
                    'total' => 0,
                    'by_kind' => array(),
                ),
                'diagnostics' => array(
                    array(
                        'code' => 'html_parse_failed',
                        'message' => 'Unable to parse HTML for visual parity probes.',
                    ),
                ),
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $this->result(array());
        }

        $rules = $this->styleRules($document);
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//a|//button|//*[@role="button"]');
        $probes = array();
        $seen = array();

        if ( false !== $nodes ) {
            foreach ( $nodes as $node ) {
                if ( ! $node instanceof DOMElement || $this->isInsideStyleOrScript($node) ) {
                    continue;
                }

                $kind = $this->probeKind($node);
                if ( 'plain_link' === $kind && ! $this->hasRegressionRiskSignal($node) ) {
                    $kind = 'plain_link';
                }

                $selector = $this->selector($node);
                if ( isset($seen[$selector]) ) {
                    continue;
                }
                $seen[$selector] = true;

                $probes[] = array_filter(array(
                    'id' => 'visual-probe-' . ( count($probes) + 1 ),
                    'kind' => $kind,
                    'selector' => $selector,
                    'tag' => strtolower($node->tagName),
                    'text' => $this->normalizedText($node),
                    'href' => $node->hasAttribute('href') ? trim($node->getAttribute('href')) : null,
                    'role' => $node->hasAttribute('role') ? strtolower(trim($node->getAttribute('role'))) : null,
                    'classes' => $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : ''),
                    'signals' => $this->signals($node),
                    'hierarchy' => $this->hierarchy($node),
                    'wrapper_chrome' => $this->wrapperChrome($node, $rules),
                    'style' => $this->computedStyle($node, $rules),
                    'geometry' => $this->geometry($node, $rules),
                ), static fn ($value): bool => null !== $value && array() !== $value && '' !== $value);
            }
        }

        return $this->result($probes);
    }

    /**
     * @param array<int, array<string, mixed>> $probes
     * @return array<string, mixed>
     */
    private function result(array $probes): array
    {
        $byKind = array();
        foreach ( $probes as $probe ) {
            $kind = (string) ($probe['kind'] ?? 'unknown');
            $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
        }
        ksort($byKind);

        return array(
            'schema' => self::SCHEMA,
            'status' => 'success',
            'probes' => $probes,
            'summary' => array(
                'total' => count($probes),
                'by_kind' => $byKind,
            ),
            'diagnostics' => array(),
        );
    }

    private function bodyHtml(string $html): string
    {
        if ( preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $match) ) {
            return (string) $match[1];
        }

        return $html;
    }

    private function probeKind(DOMElement $element): string
    {
        if ( 'button' === strtolower($element->tagName) || 'button' === strtolower($element->hasAttribute('role') ? $element->getAttribute('role') : '') ) {
            return $this->isMenuControl($element) ? 'menu_button' : ( $this->hasCtaSignal($element) ? 'cta' : 'button' );
        }

        if ( $this->isLinkedCard($element) ) {
            return 'linked_card';
        }

        if ( $this->isMenuItem($element) ) {
            return $this->menuDepth($element) > 0 ? 'submenu_item' : 'menu_item';
        }

        if ( $this->hasCtaSignal($element) ) {
            return 'cta';
        }

        if ( $this->hasButtonSignal($element) ) {
            return 'button';
        }

        return 'plain_link';
    }

    private function isMenuControl(DOMElement $element): bool
    {
        $aria = strtolower($element->hasAttribute('aria-haspopup') ? $element->getAttribute('aria-haspopup') : '');
        return in_array($aria, array( 'true', 'menu' ), true) || $element->hasAttribute('aria-expanded') || $this->hasAnyToken($element, array( 'menu-toggle', 'hamburger', 'submenu-toggle' ));
    }

    private function isMenuItem(DOMElement $element): bool
    {
        if ( 'a' !== strtolower($element->tagName) ) {
            return false;
        }

        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            $tag = strtolower($node->tagName);
            if ( 'nav' === $tag || 'navigation' === strtolower($node->hasAttribute('role') ? $node->getAttribute('role') : '') || $this->hasAnyToken($node, array( 'nav', 'navbar', 'navigation', 'menu', 'menu-item' )) ) {
                return true;
            }
        }

        return false;
    }

    private function isLinkedCard(DOMElement $element): bool
    {
        if ( 'a' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( ! $this->hasAnyToken($element, array( 'card', 'tile', 'article', 'product' )) ) {
            return false;
        }

        $blockChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'article', 'div', 'figure', 'h2', 'h3', 'h4', 'img', 'p', 'picture', 'section', 'svg' ), true) ) {
                ++$blockChildren;
            }
        }

        return $blockChildren > 0;
    }

    private function hasCtaSignal(DOMElement $element): bool
    {
        return $this->hasAnyToken($element, array( 'cta', 'primary', 'secondary', 'action' )) || in_array(strtolower($this->normalizedText($element)), array(
            'book now',
            'buy now',
            'checkout',
            'donate',
            'get started',
            'register',
            'shop now',
            'sign up',
            'subscribe',
        ), true);
    }

    private function hasButtonSignal(DOMElement $element): bool
    {
        return $this->buttonSignalClassifier->hasClassSignal($element);
    }

    private function hasRegressionRiskSignal(DOMElement $element): bool
    {
        return 'a' === strtolower($element->tagName) && ! $this->isMenuItem($element) && ! $this->hasButtonSignal($element) && ! $this->hasCtaSignal($element) && ! $this->isLinkedCard($element);
    }

    /**
     * @return array<int, string>
     */
    private function signals(DOMElement $element): array
    {
        $signals = array();
        if ( $this->hasButtonSignal($element) ) {
            $signals[] = 'button-class';
        }
        if ( $this->hasCtaSignal($element) ) {
            $signals[] = 'cta-signal';
        }
        if ( $this->isMenuItem($element) ) {
            $signals[] = 'menu-ancestor';
        }
        if ( $this->hasCurrentSignal($element) ) {
            $signals[] = 'current-active';
        }
        if ( $this->hasSeparatorSignal($element) ) {
            $signals[] = 'separator-rule';
        }
        if ( null !== $this->submenuPanel($element) ) {
            $signals[] = 'submenu-panel';
        }
        if ( $this->isLinkedCard($element) ) {
            $signals[] = 'linked-card-content';
        }
        if ( $this->hasRegressionRiskSignal($element) ) {
            $signals[] = 'plain-link-regression-watch';
        }
        if ( $this->hasDefaultButtonStyleRisk($element) ) {
            $signals[] = 'default-button-style-watch';
        }
        if ( $element->hasAttribute('style') ) {
            $signals[] = 'inline-style';
        }

        return array_values(array_unique($signals));
    }

    private function hasDefaultButtonStyleRisk(DOMElement $element): bool
    {
        if ( ! $this->hasButtonSignal($element) && ! $this->hasCtaSignal($element) && 'button' !== strtolower($element->tagName) ) {
            return false;
        }

        $style = $this->computedStyle($element, $this->styleRules($element->ownerDocument));
        $background = strtolower($style['background-color'] ?? '');
        $border = strtolower(trim(implode(' ', array(
            $style['border'] ?? '',
            $style['border-color'] ?? '',
            $style['border-top-color'] ?? '',
            $style['border-right-color'] ?? '',
            $style['border-bottom-color'] ?? '',
            $style['border-left-color'] ?? '',
        ))));
        $radius = strtolower($style['border-radius'] ?? '');

        $defaultGreyBackground = in_array($background, array( '#f7f7f7', '#eee', '#eeeeee', '#e5e5e5', '#ddd', '#dddddd', 'rgb(247, 247, 247)', 'rgb(238, 238, 238)', 'rgb(229, 229, 229)', 'rgb(221, 221, 221)' ), true);
        $defaultGreyBorder = '' !== $border && ( str_contains($border, '#ccc') || str_contains($border, '#cccccc') || str_contains($border, 'rgb(204, 204, 204)') );
        $unstyledButton = '' === $background && '' === $radius && '' === trim((string) ($style['padding'] ?? ''));

        return $unstyledButton || $defaultGreyBackground || $defaultGreyBorder;
    }

    private function hasCurrentSignal(DOMElement $element): bool
    {
        if ( '' !== trim($element->hasAttribute('aria-current') ? $element->getAttribute('aria-current') : '') ) {
            return true;
        }

        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->hasAnyToken($node, array( 'active', 'current', 'current-menu-item', 'current_page_item', 'is-active', 'selected' )) ) {
                return true;
            }
            if ( in_array(strtolower($node->tagName), array( 'nav', 'body' ), true) ) {
                break;
            }
        }

        return false;
    }

    private function hasSeparatorSignal(DOMElement $element): bool
    {
        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( $this->hasAnyToken($node, array( 'separator', 'divider', 'rule' )) ) {
                return true;
            }
            if ( in_array(strtolower($node->tagName), array( 'nav', 'body' ), true) ) {
                break;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function hierarchy(DOMElement $element): array
    {
        $listDepth = $this->menuDepth($element);
        $parentItem = null;
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'li' === strtolower($node->tagName) ) {
                $parentItem = $this->firstOwnAnchorText($node, $element);
                if ( null !== $parentItem ) {
                    break;
                }
            }
        }

        return array_filter(array(
            'menu_depth' => $listDepth,
            'parent_text' => $parentItem,
            'has_submenu' => $this->hasSubmenu($element),
            'submenu_panel' => $this->submenuPanelSnapshot($element),
        ), static fn ($value): bool => null !== $value && '' !== $value && false !== $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function submenuPanelSnapshot(DOMElement $element): ?array
    {
        $panel = $this->submenuPanel($element);
        if ( ! $panel instanceof DOMElement ) {
            return null;
        }

        return array_filter(array(
            'tag' => strtolower($panel->tagName),
            'classes' => $this->tokens($panel->hasAttribute('class') ? $panel->getAttribute('class') : ''),
            'style' => $this->computedStyle($panel, $this->styleRules($panel->ownerDocument)),
        ), static fn ($value): bool => array() !== $value && '' !== $value);
    }

    private function submenuPanel(DOMElement $element): ?DOMElement
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol', 'div' ), true) && $this->hasAnyToken($node, array( 'dropdown', 'submenu', 'subnav', 'flyout', 'menu-panel', 'dropdown-panel', 'wp-block-navigation__submenu-container' )) ) {
                return $node;
            }
            if ( 'nav' === strtolower($node->tagName) ) {
                break;
            }
        }

        return null;
    }

    private function menuDepth(DOMElement $element): int
    {
        $depth = -1;
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'ul', 'ol' ), true) ) {
                ++$depth;
                continue;
            }
            if ( 'nav' === strtolower($node->tagName) || ( 'li' !== strtolower($node->tagName) && $this->hasAnyToken($node, array( 'nav', 'navbar', 'navigation', 'menu' )) ) ) {
                break;
            }
        }

        return max(0, $depth);
    }

    private function hasSubmenu(DOMElement $element): bool
    {
        $parent = $element->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        foreach ( $parent->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'ul', 'ol' ), true) ) {
                return true;
            }
        }

        return false;
    }

    private function firstOwnAnchorText(DOMElement $item, DOMElement $current): ?string
    {
        foreach ( $item->childNodes as $child ) {
            if ( $child === $current ) {
                continue;
            }
            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) ) {
                return $this->normalizedText($child);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{selector: string, declarations: array<string, string>}> $rules
     * @return array<string, string>
     */
    private function computedStyle(DOMElement $element, array $rules): array
    {
        $style = array();
        foreach ( $rules as $rule ) {
            if ( $this->matchesSimpleSelector($element, $rule['selector']) ) {
                $style = array_merge($style, $rule['declarations']);
            }
        }

        if ( $element->hasAttribute('style') ) {
            $style = array_merge($style, $this->declarations($element->getAttribute('style')));
        }

        $style = $this->resolveCustomProperties($element, $style);
        $style = array_intersect_key($style, array_flip(self::STYLE_FIELDS));
        $style = $this->withCoreButtonWidthClass($element, $style);
        ksort($style);

        return $style;
    }

    /**
     * @param array<string, string> $style
     * @return array<string, string>
     */
    private function resolveCustomProperties(DOMElement $element, array $style): array
    {
        if ( array() === $style ) {
            return $style;
        }

        $customProperties = $this->customProperties($element->ownerDocument);
        if ( array() === $customProperties ) {
            return $style;
        }

        foreach ( $style as $property => $value ) {
            if ( ! str_contains($value, 'var(') ) {
                continue;
            }
            $style[$property] = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $matches) use ($customProperties): string {
                $name = (string) ($matches[1] ?? '');
                if ( isset($customProperties[$name]) ) {
                    return $customProperties[$name];
                }
                return trim((string) ($matches[2] ?? $matches[0]));
            }, $value) ?? $value;
        }

        return $style;
    }

    /**
     * @return array<string, string>
     */
    private function customProperties(?DOMDocument $document): array
    {
        if ( ! $document instanceof DOMDocument ) {
            return array();
        }

        $properties = array();
        foreach ( $document->getElementsByTagName('style') as $style ) {
            if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', (string) $style->textContent, $matches, PREG_SET_ORDER) ) {
                foreach ( $matches as $match ) {
                    $properties[(string) $match[1]] = trim((string) $match[2]);
                }
            }
        }

        return $properties;
    }

    /**
     * @param array<int, array{selector: string, declarations: array<string, string>}> $rules
     * @return array<string, mixed>
     */
    private function geometry(DOMElement $element, array $rules): array
    {
        $style = $this->computedStyle($element, $rules);
        $geometry = array(
            'text_length' => strlen($this->normalizedText($element)),
            'child_element_count' => $this->childElementCount($element),
        );

        foreach ( array( 'width', 'height', 'min-width', 'min-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'gap' ) as $field ) {
            if ( isset($style[$field]) ) {
                $geometry[$field] = $style[$field];
            }
        }

        return $geometry;
    }

    /**
     * @param array<int, array{selector: string, declarations: array<string, string>}> $rules
     * @return array<string, mixed>|null
     */
    private function wrapperChrome(DOMElement $element, array $rules): ?array
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( 'body' === strtolower($node->tagName) ) {
                break;
            }

            $style = $this->visualChromeStyle($this->computedStyle($node, $rules));
            if ( array() === $style ) {
                continue;
            }

            return array_filter(array(
                'tag' => strtolower($node->tagName),
                'selector' => $this->selector($node),
                'classes' => $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : ''),
                'style' => $style,
            ), static fn ($value): bool => array() !== $value && '' !== $value);
        }

        return null;
    }

    /**
     * @param array<string, string> $style
     * @return array<string, string>
     */
    private function visualChromeStyle(array $style): array
    {
        $fields = array(
            'background',
            'background-color',
            'border',
            'border-color',
            'border-radius',
            'box-shadow',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
        );

        $chrome = array_intersect_key($style, array_flip($fields));
        ksort($chrome);

        return array_filter($chrome, static fn (string $value): bool => '' !== trim($value));
    }

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    private function styleRules(DOMDocument $document): array
    {
        $rules = array();
        foreach ( $document->getElementsByTagName('style') as $style ) {
            $css = (string) $style->textContent;
            if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $matches as $match ) {
                foreach ( explode(',', (string) $match[1]) as $selector ) {
                    $selector = trim($selector);
                    if ( '' === $selector ) {
                        continue;
                    }
                    if ( $this->selectorCarriesPseudoState($selector) ) {
                        continue;
                    }
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $this->declarations((string) $match[2]),
                    );
                }
            }
        }

        return $rules;
    }

    /**
     * WordPress renders core/button custom width as a wrapper class. The visual
     * probe compares the inner link/button control, so synthesize the equivalent
     * width declaration from the nearest core/button wrapper.
     *
     * @param array<string, string> $style
     * @return array<string, string>
     */
    private function withCoreButtonWidthClass(DOMElement $element, array $style): array
    {
        if ( isset($style['width']) ) {
            return $style;
        }

        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
            $className = $node->hasAttribute('class') ? $node->getAttribute('class') : '';
            if ( preg_match('/(?:^|\s)wp-block-button__width-(25|50|75|100)(?:\s|$)/', $className, $match) ) {
                $style['width'] = $match[1] . '%';
                return $style;
            }
            if ( 'body' === strtolower($node->tagName) ) {
                break;
            }
        }

        return $style;
    }

    private function selectorCarriesPseudoState(string $selector): bool
    {
        return 1 === preg_match('/:{1,2}(?:hover|focus-visible|focus-within|focus|active|visited|before|after)\b/i', $selector);
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $style): array
    {
        $declarations = array();
        foreach ( explode(';', $style) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            if ( '' !== $name && '' !== $value ) {
                $declarations[$name] = preg_replace('/\s+/', ' ', $value) ?? $value;
            }
        }

        return $declarations;
    }

    private function matchesSimpleSelector(DOMElement $element, string $selector): bool
    {
        if ( $this->selectorCarriesPseudoState($selector) ) {
            return false;
        }

        $selector = trim($selector);
        if ( '' === $selector || str_contains($selector, '>') || str_contains($selector, '+') || str_contains($selector, '~') ) {
            return false;
        }

        if ( str_contains($selector, ' ') ) {
            return $this->matchesDescendantSelector($element, $selector);
        }

        if ( preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return $element->hasAttribute('id') && $element->getAttribute('id') === $match[1];
        }

        if ( preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $match) ) {
            return in_array($match[1], $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : ''), true);
        }

        if ( preg_match('/^([A-Za-z0-9_-]+)(\.[A-Za-z0-9_-]+)+$/', $selector) ) {
            $parts = explode('.', $selector);
            $tag = array_shift($parts);
            if ( strtolower((string) $tag) !== strtolower($element->tagName) ) {
                return false;
            }
            $classes = $this->tokens($element->hasAttribute('class') ? $element->getAttribute('class') : '');
            foreach ( $parts as $class ) {
                if ( ! in_array($class, $classes, true) ) {
                    return false;
                }
            }

            return true;
        }

        return strtolower($selector) === strtolower($element->tagName);
    }

    private function matchesDescendantSelector(DOMElement $element, string $selector): bool
    {
        $parts = preg_split('/\s+/', trim($selector)) ?: array();
        if ( array() === $parts || ! $this->matchesSimpleSelector($element, array_pop($parts)) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 1; $index >= 0; --$index ) {
            $matched = false;
            for ( $node = $current; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                if ( $this->matchesSimpleSelector($node, $parts[$index]) ) {
                    $matched = true;
                    $current = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }

        return true;
    }

    private function selector(DOMElement $element): string
    {
        $segments = array();
        for ( $node = $element; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            $segment = strtolower($node->tagName);
            if ( $node->hasAttribute('id') && '' !== trim($node->getAttribute('id')) ) {
                $segment .= '#' . trim($node->getAttribute('id'));
                array_unshift($segments, $segment);
                break;
            }
            $classes = $this->tokens($node->hasAttribute('class') ? $node->getAttribute('class') : '');
            if ( array() !== $classes ) {
                $segment .= '.' . implode('.', array_slice($classes, 0, 2));
            }
            $index = $this->elementIndex($node);
            if ( $index > 1 ) {
                $segment .= ':nth-of-type(' . $index . ')';
            }
            array_unshift($segments, $segment);
        }

        return implode(' > ', $segments);
    }

    private function elementIndex(DOMElement $element): int
    {
        $index = 1;
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && strtolower($node->tagName) === strtolower($element->tagName) ) {
                ++$index;
            }
        }

        return $index;
    }

    private function normalizedText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $element->textContent, ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: array(), static fn (string $token): bool => '' !== $token));
    }

    /**
     * @param array<int, string> $tokens
     */
    private function hasAnyToken(DOMElement $element, array $tokens): bool
    {
        $values = strtolower(($element->hasAttribute('class') ? $element->getAttribute('class') : '') . ' ' . ($element->hasAttribute('id') ? $element->getAttribute('id') : ''));
        foreach ( $tokens as $token ) {
            if ( preg_match('/(^|[^a-z0-9])' . preg_quote($token, '/') . '([^a-z0-9]|$)/', $values) ) {
                return true;
            }
        }

        return false;
    }

    private function isInsideStyleOrScript(DOMElement $element): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), array( 'script', 'style' ), true) ) {
                return true;
            }
        }

        return false;
    }
}
