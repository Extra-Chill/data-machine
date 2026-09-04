<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

use Closure;

/**
 * Classifies source nodes into semantic HTML elements and form-control intent.
 *
 * The emitter owns rendering, assets, and CSS. This classifier owns the semantic
 * policy so landmark/form decisions can evolve without growing emission code.
 */
final class StaticHtmlSemanticClassifier
{
    /** @var Closure(array<string, mixed>): array<int, mixed> */
    private Closure $nodeList;

    /** @var Closure(array<string, mixed>, array<string, mixed>|null=): string */
    private Closure $textContent;

    /** @var Closure(array<string, mixed>): int */
    private Closure $textDescendantCount;

    /** @var Closure(array<string, mixed>): string */
    private Closure $subtreePlainText;

    /** @var Closure(array<string, mixed>): string */
    private Closure $nodePlainText;

    /** @var Closure(array<string, mixed>, string): float|null */
    private Closure $boxValue;

    /** @var Closure(array<string, mixed>): string|null */
    private Closure $backgroundColor;

    /** @var Closure(array<string, mixed>): float */
    private Closure $cornerRadius;

    /** @var Closure(array<string, mixed>): bool */
    private Closure $hasStrokePaint;

    /** @var Closure(array<string, mixed>): string|null */
    private Closure $nodeAssetPath;

    /** @var Closure(array<string, mixed>): bool */
    private Closure $subtreeHasRenderableVector;

    /** @var Closure(array<string, mixed>): array<int, string> */
    private Closure $listItemIds;

    /** @var Closure(array<string, mixed>): bool */
    private Closure $listLooksOrdered;

    /** @var Closure(array<string, mixed>, string, int, array<string, mixed>|null): string|null */
    private Closure $headingLevel;

    /** @var Closure(string): string */
    private Closure $sanitizeAttribute;

    /**
     * @param array<string, Closure> $callbacks
     */
    public function __construct(
        private readonly LayoutIntentClassifier $layoutIntentClassifier,
        array $callbacks
    ) {
        $this->nodeList = $callbacks['nodeList'];
        $this->textContent = $callbacks['textContent'];
        $this->textDescendantCount = $callbacks['textDescendantCount'];
        $this->subtreePlainText = $callbacks['subtreePlainText'];
        $this->nodePlainText = $callbacks['nodePlainText'];
        $this->boxValue = $callbacks['boxValue'];
        $this->backgroundColor = $callbacks['backgroundColor'];
        $this->cornerRadius = $callbacks['cornerRadius'];
        $this->hasStrokePaint = $callbacks['hasStrokePaint'];
        $this->nodeAssetPath = $callbacks['nodeAssetPath'];
        $this->subtreeHasRenderableVector = $callbacks['subtreeHasRenderableVector'];
        $this->listItemIds = $callbacks['listItemIds'];
        $this->listLooksOrdered = $callbacks['listLooksOrdered'];
        $this->headingLevel = $callbacks['headingLevel'];
        $this->sanitizeAttribute = $callbacks['sanitizeAttribute'];
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     */
    public function semanticTag(array $node, string $type, string $name, int $depth, int $sectionDepth, ?array $parentNode, ?array $grandParentNode = null): string
    {
        $lowerName = strtolower($name);

        if ( 'TEXT' === $type ) {
            if ( null !== $parentNode && $this->isButtonLike($parentNode) ) {
                return 'span';
            }

            if ( null !== $parentNode && $this->isNavigationLabelText($node, $parentNode) ) {
                return 'span';
            }

            if ( null !== $parentNode && $this->isCompactControlTokenText($node, $parentNode) ) {
                return 'span';
            }

            if ( null !== $parentNode && $this->isListItemOf($node, $parentNode) ) {
                return 'li';
            }

            if (
                null !== $parentNode
                && ( $this->isSemanticListItemNode($parentNode) || ( null !== $grandParentNode && $this->isListItemOf($parentNode, $grandParentNode) ) )
            ) {
                return 'p';
            }

            if ( $this->isFooterTextContext($parentNode, $grandParentNode) && ! $this->hasExplicitHeadingIntent($lowerName) ) {
                return 'p';
            }

            if ( $this->hasExplicitBodyTextIntent($lowerName) ) {
                return 'p';
            }

            $heading = ($this->headingLevel)($node, $lowerName, $depth, $parentNode);
            if ( null !== $heading ) {
                return $heading;
            }

            return 'p';
        }

        $children = array_values(array_filter(($this->nodeList)($node), 'is_array'));

        if ( null !== $parentNode && $this->isListItemOf($node, $parentNode) && ! $this->isTopLevelSection($parentNode, $depth - 1, $sectionDepth, $grandParentNode, $this->children($parentNode)) ) {
            return 'li';
        }

        $isTopLevelSection = 'FRAME' === $type && $this->isTopLevelSection($node, $depth, $sectionDepth, $parentNode, $children);

        if ( $this->isTextareaLike($node, $parentNode) ) {
            return $this->hasFormControlAccessoryChildren($node) ? 'div' : 'textarea';
        }

        if ( $this->isInputLike($node, $parentNode) ) {
            return $this->hasFormControlAccessoryChildren($node) ? 'div' : 'input';
        }

        if ( $this->isFormLike($node, $parentNode) ) {
            return 'form';
        }

        if ( str_contains($lowerName, 'pagination') && ! str_contains($lowerName, 'number') ) {
            return 'nav';
        }

        if ( str_contains($lowerName, 'blockquote') || str_contains($lowerName, 'block quote') ) {
            return 'blockquote';
        }

        if ( null !== $parentNode && $this->isButtonLike($parentNode) && $this->isButtonLike($node) ) {
            return 'div';
        }

        if ( empty($node['figma_link']) && $this->isButtonLike($node) ) {
            return 'button';
        }

        if ( ! $isTopLevelSection && ! empty($this->listItemIds($node)) ) {
            return $this->listLooksOrdered($node) ? 'ol' : 'ul';
        }

        $landmark = $this->landmarkTag($node, $lowerName, $depth, $parentNode);
        if ( null !== $landmark ) {
            return $landmark;
        }

        if ( $this->isArticleLikeContainer($node, $lowerName) ) {
            return 'article';
        }

        if ( $isTopLevelSection ) {
            return 'section';
        }

        return 'div';
    }

    /** @param array<string, mixed> $node */
    public function isInputLike(array $node, ?array $parentNode = null): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        if ( $this->isTextareaLike($node) ) {
            return false;
        }

        if ( $this->isSpatiallyLabeledInputRectangle($node, $parentNode) ) {
            return true;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'button') || str_contains($name, 'btn') || str_contains($name, 'cta') ) {
            return false;
        }

        $placeholder = strtolower(trim(($this->subtreePlainText)($node)));
        $haystack = $name . ' ' . $placeholder;
        $hasInputName = str_contains($name, 'input')
            || str_contains($name, 'text field')
            || str_contains($name, 'textfield')
            || str_contains($name, 'form field')
            || str_contains($haystack, 'search')
            || preg_match('/(^|[^a-z])field([^a-z]|$)/', $name);
        if ( ! $hasInputName ) {
            return false;
        }

        $textCount = ($this->textDescendantCount)($node);
        if ( $textCount < 1 || $textCount > 2 ) {
            return false;
        }

        $width = ($this->boxValue)($node, 'width');
        $height = ($this->boxValue)($node, 'height');
        if ( (null !== $width && $width > 640.0) || (null !== $height && $height > 120.0) ) {
            return false;
        }

        return null !== ($this->backgroundColor)($node) || ($this->cornerRadius)($node) > 0.0 || ($this->hasStrokePaint)($node) || $this->hasFormControlChromeChild($node);
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    public function isTextareaLike(array $node, ?array $parentNode = null): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'button') || str_contains($name, 'btn') || str_contains($name, 'cta') ) {
            return false;
        }

        $placeholder = strtolower(trim(($this->subtreePlainText)($node)));
        $label = strtolower($this->nearbyFormControlLabel($node, $parentNode));
        $haystack = $name . ' ' . $placeholder . ' ' . $label;
        $hasTextareaIntent = str_contains($haystack, 'textarea')
            || str_contains($haystack, 'text area')
            || str_contains($haystack, 'message')
            || str_contains($haystack, 'comment')
            || str_contains($haystack, 'reply');
        if ( ! $hasTextareaIntent ) {
            return false;
        }

        $textCount = ($this->textDescendantCount)($node);
        if ( $textCount < 1 || $textCount > 3 ) {
            return false;
        }

        $width = ($this->boxValue)($node, 'width');
        $height = ($this->boxValue)($node, 'height');
        if ( null !== $width && $width > 900.0 ) {
            return false;
        }
        if ( null !== $height && $height < 72.0 ) {
            return false;
        }

        return null !== ($this->backgroundColor)($node) || ($this->cornerRadius)($node) > 0.0 || ($this->hasStrokePaint)($node) || $this->hasFormControlChromeChild($node);
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    public function isFormLike(array $node, ?array $parentNode): bool
    {
        if ( null !== $parentNode && $this->isFormLike($parentNode, null) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $text = strtolower(($this->subtreePlainText)($node));
        $haystack = $name . ' ' . $text;
        $hasFormIntent = str_contains($haystack, 'search')
            || str_contains($haystack, 'newsletter')
            || str_contains($haystack, 'subscribe')
            || str_contains($haystack, 'sign up')
            || str_contains($haystack, 'comment')
            || str_contains($haystack, 'reply');
        $hasNamedFormIntent = str_contains($name, 'search')
            || str_contains($name, 'newsletter')
            || str_contains($name, 'subscribe')
            || str_contains($name, 'sign up')
            || str_contains($name, 'comment')
            || str_contains($name, 'reply')
            || str_contains($name, 'form');
        if ( ! $hasFormIntent || ! $hasNamedFormIntent ) {
            return false;
        }

        $height = ($this->boxValue)($node, 'height');
        if ( null !== $height && $height > 800.0 ) {
            return false;
        }

        $hasField = false;
        $hasSubmit = false;
        $children = array_values(array_filter(($this->nodeList)($node), 'is_array'));
        $relevantChildren = 0;
        foreach ( $children as $child ) {
            $childHasField = $this->isInputLike($child, $node) || $this->isTextareaLike($child, $node) || $this->subtreeHasInputLike($child) || $this->subtreeHasTextareaLike($child);
            $childHasSubmit = $this->subtreeHasSubmitButtonLike($child);
            if ( $childHasField || $childHasSubmit ) {
                $relevantChildren++;
            }
            $hasField = $hasField || $childHasField;
            $hasSubmit = $hasSubmit || $childHasSubmit;
        }

        if ( count($children) > 3 && $relevantChildren < 2 && $relevantChildren < count($children) - 1 ) {
            return false;
        }
        return $hasField && ($hasSubmit || str_contains($haystack, 'search'));
    }

    /** @param array<string, mixed> $node */
    public function hasFormControlAccessoryChildren(array $node): bool
    {
        if ( $this->hasFormControlChromeChild($node) ) {
            return true;
        }

        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) || $this->isFormControlPlaceholderChild($child) ) {
                continue;
            }

            if ( ($this->subtreeHasRenderableVector)($child) || null !== ($this->nodeAssetPath)($child) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function hasFormControlChromeChild(array $node): bool
    {
        $width = ($this->boxValue)($node, 'width');
        $height = ($this->boxValue)($node, 'height');
        if ( null === $width || null === $height || $width < 80.0 || $width > 640.0 || $height < 24.0 || $height > 160.0 ) {
            return false;
        }

        foreach ( ($this->nodeList)($node) as $child ) {
            if ( ! is_array($child) || 'TEXT' === strtoupper((string) ($child['type'] ?? '')) ) {
                continue;
            }

            $childWidth = ($this->boxValue)($child, 'width');
            $childHeight = ($this->boxValue)($child, 'height');
            if ( null === $childWidth || null === $childHeight ) {
                continue;
            }
            if ( abs($childWidth - $width) > 8.0 || abs($childHeight - $height) > 8.0 ) {
                continue;
            }
            if ( null !== ($this->backgroundColor)($child) || ($this->cornerRadius)($child) > 0.0 || ($this->hasStrokePaint)($child) || ($this->subtreeHasRenderableVector)($child) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    public function isFormControlPlaceholderChild(array $node): bool
    {
        if ( '' === trim(($this->subtreePlainText)($node)) ) {
            return false;
        }

        foreach ( ($this->nodeList)($node) as $child ) {
            if ( is_array($child) && ! $this->isFormControlPlaceholderChild($child) ) {
                return false;
            }
        }

        return ! ($this->subtreeHasRenderableVector)($node) && null === ($this->nodeAssetPath)($node);
    }

    /** @param array<string, mixed> $node */
    public function isButtonLike(array $node): bool
    {
        if ( $this->isTextareaLike($node) || $this->isInputLike($node) ) {
            return false;
        }
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }
        if ( 1 !== ($this->textDescendantCount)($node) ) {
            return false;
        }

        $width = ($this->boxValue)($node, 'width');
        if ( null !== $width && $width > 480.0 ) {
            return false;
        }
        $height = ($this->boxValue)($node, 'height');
        if ( null !== $height && $height > 160.0 ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $nameHint = str_contains($name, 'button') || str_contains($name, 'btn') || str_contains($name, 'cta');

        return $nameHint || null !== ($this->backgroundColor)($node) || ($this->cornerRadius)($node) > 0.0;
    }

    /** @param array<string, mixed> $node */
    public function formControlAttributes(array $node, string $tag, ?array $parentNode = null): string
    {
        $placeholder = trim(($this->subtreePlainText)($node));
        $label = $this->nearbyFormControlLabel($node, $parentNode);
        $name = (string) ($node['name'] ?? '');
        $haystack = strtolower($name . ' ' . $placeholder . ' ' . $label);
        $type = 'text';
        if ( str_contains($haystack, 'search') ) {
            $type = 'search';
        } elseif ( str_contains($haystack, 'email') || str_contains($haystack, 'e-mail') ) {
            $type = 'email';
        }

        $attributes = 'input' === $tag ? ' type="' . $type . '"' : '';
        if ( 'search' === $type ) {
            $attributes .= ' name="s"';
        } elseif ( 'email' === $type ) {
            $attributes .= ' name="email"';
        } elseif ( 'textarea' === $tag ) {
            $attributes .= ' name="' . ($this->sanitizeAttribute)($this->textareaControlName($node, $haystack)) . '"';
        }
        if ( '' !== $placeholder ) {
            $attributes .= ' placeholder="' . ($this->sanitizeAttribute)($placeholder) . '"';
            $attributes .= ' aria-label="' . ($this->sanitizeAttribute)('' !== $label ? $label : $placeholder) . '"';
        } elseif ( '' !== $label && $this->isSpatiallyLabeledInputRectangle($node, $parentNode) ) {
            $attributes .= ' placeholder="' . ($this->sanitizeAttribute)($label) . '"';
            $attributes .= ' aria-label="' . ($this->sanitizeAttribute)($label) . '"';
        } elseif ( '' !== $label ) {
            $attributes .= ' aria-label="' . ($this->sanitizeAttribute)($label) . '"';
        } elseif ( '' !== $name ) {
            $attributes .= ' aria-label="' . ($this->sanitizeAttribute)($name) . '"';
        }

        return $attributes;
    }

    /** @param array<string, mixed> $node */
    private function textareaControlName(array $node, string $haystack): string
    {
        $base = str_contains($haystack, 'comment') || str_contains($haystack, 'reply') ? 'comment' : 'message';
        $id = strtolower((string) ($node['id'] ?? ''));
        $suffix = trim((string) preg_replace('/[^a-z0-9]+/', '-', $id), '-');

        return '' === $suffix ? $base : $base . '-' . $suffix;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    public function nearbyFormControlLabel(array $node, ?array $parentNode): string
    {
        if ( null === $parentNode ) {
            return '';
        }

        $nodeId = (string) ($node['id'] ?? '');
        foreach ( ($this->nodeList)($parentNode) as $child ) {
            if ( ! is_array($child) || $nodeId === (string) ($child['id'] ?? '') ) {
                continue;
            }
            if ( 'TEXT' !== strtoupper((string) ($child['type'] ?? '')) ) {
                continue;
            }

            $name = strtolower((string) ($child['name'] ?? ''));
            if ( ! str_contains($name, 'label') && ! $this->isSpatiallyLabeledInputRectangle($node, $parentNode, $child) ) {
                continue;
            }

            $text = trim(($this->nodePlainText)($child));
            if ( '' !== $text ) {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    public function isSpatialFormControlLabel(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $text = trim(($this->nodePlainText)($node));
        if ( ! $this->isSimpleFormFieldLabel($text) ) {
            return false;
        }

        foreach ( ($this->nodeList)($parentNode) as $sibling ) {
            if ( is_array($sibling) && $this->isSpatiallyLabeledInputRectangle($sibling, $parentNode, $node) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasInputLike(array $node): bool
    {
        if ( $this->isInputLike($node) ) {
            return true;
        }
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasInputLike($child) ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasTextareaLike(array $node): bool
    {
        if ( $this->isTextareaLike($node) ) {
            return true;
        }
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasTextareaLike($child) ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasSubmitButtonLike(array $node): bool
    {
        if ( $this->isButtonLike($node) ) {
            $text = strtolower(($this->subtreePlainText)($node));
            return 1 === preg_match('/(^|[^a-z])(submit|send|post|search|sign up|subscribe)([^a-z]|$)/', $text);
        }
        foreach ( ($this->nodeList)($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasSubmitButtonLike($child) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $requiredLabel
     */
    private function isSpatiallyLabeledInputRectangle(array $node, ?array $parentNode, ?array $requiredLabel = null): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('RECTANGLE', 'ROUNDED_RECTANGLE', 'FRAME', 'INSTANCE'), true) ) {
            return false;
        }
        if ( ($this->textDescendantCount)($node) > 0 || null === ($this->backgroundColor)($node) && ! ($this->hasStrokePaint)($node) && 0.0 === ($this->cornerRadius)($node) ) {
            return false;
        }

        $width = ($this->boxValue)($node, 'width');
        $height = ($this->boxValue)($node, 'height');
        if ( null === $width || null === $height || $width < 80.0 || $width > 640.0 || $height < 24.0 || $height > 96.0 ) {
            return false;
        }

        $parentName = strtolower((string) ($parentNode['name'] ?? ''));
        $parentText = strtolower(($this->subtreePlainText)($parentNode));
        $parentHaystack = $parentName . ' ' . $parentText;
        if ( ! str_contains($parentHaystack, 'newsletter') && ! str_contains($parentHaystack, 'subscribe') && ! str_contains($parentHaystack, 'sign up') && ! str_contains($parentHaystack, 'contact') && ! str_contains($parentHaystack, 'comment') && ! str_contains($parentHaystack, 'search') && ! str_contains($parentName, 'form') ) {
            return false;
        }

        foreach ( ($this->nodeList)($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || 'TEXT' !== strtoupper((string) ($sibling['type'] ?? '')) ) {
                continue;
            }
            if ( null !== $requiredLabel && (string) ($requiredLabel['id'] ?? '') !== (string) ($sibling['id'] ?? '') ) {
                continue;
            }
            $label = trim(($this->nodePlainText)($sibling));
            if ( ! $this->isSimpleFormFieldLabel($label) ) {
                continue;
            }
            if ( $this->boxContainsCenter($node, $sibling) ) {
                return true;
            }
        }

        return false;
    }

    private function isSimpleFormFieldLabel(string $label): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? ''));
        return '' !== $normalized
            && strlen($normalized) <= 40
            && 1 === preg_match('/^(name|full name|first name|last name|email|e-mail|phone|telephone|company|organization|subject|message|comment|search|address|zip|postal code)$/', $normalized);
    }

    /**
     * @param array<string, mixed> $container
     * @param array<string, mixed> $child
     */
    private function boxContainsCenter(array $container, array $child): bool
    {
        $containerX = ($this->boxValue)($container, 'x');
        $containerY = ($this->boxValue)($container, 'y');
        $containerWidth = ($this->boxValue)($container, 'width');
        $containerHeight = ($this->boxValue)($container, 'height');
        $childX = ($this->boxValue)($child, 'x');
        $childY = ($this->boxValue)($child, 'y');
        $childWidth = ($this->boxValue)($child, 'width');
        $childHeight = ($this->boxValue)($child, 'height');
        if ( null === $containerX || null === $containerY || null === $containerWidth || null === $containerHeight || null === $childX || null === $childY || null === $childWidth || null === $childHeight ) {
            return false;
        }

        $centerX = $childX + ($childWidth / 2.0);
        $centerY = $childY + ($childHeight / 2.0);
        return $centerX >= $containerX - 1.0
            && $centerX <= $containerX + $containerWidth + 1.0
            && $centerY >= $containerY - 1.0
            && $centerY <= $containerY + $containerHeight + 1.0;
    }

    private function isFooterTextContext(?array $parentNode, ?array $grandParentNode): bool
    {
        foreach ( array($parentNode, $grandParentNode) as $ancestor ) {
            if ( ! is_array($ancestor) ) {
                continue;
            }
            $name = strtolower((string) ($ancestor['name'] ?? ''));
            if ( str_contains($name, 'footer') ) {
                return true;
            }
        }

        return false;
    }

    private function hasExplicitHeadingIntent(string $lowerName): bool
    {
        return str_contains($lowerName, 'title')
            || str_contains($lowerName, 'heading')
            || str_contains($lowerName, 'headline');
    }

    private function hasExplicitBodyTextIntent(string $lowerName): bool
    {
        if ( $this->hasExplicitHeadingIntent($lowerName) ) {
            return false;
        }

        return 1 === preg_match('/\b(body|supporting\s+text|copy|description|caption|excerpt|summary|intro|eyebrow|author|date|time|timing|chapter|role|name|number|privacy|terms|rights|reserved|copyright|listen|read\s+more)\b/', $lowerName);
    }

    /** @param array<string, mixed> $node */
    private function isArticleLikeContainer(array $node, string $lowerName): bool
    {
        if ( str_contains($lowerName, 'article') || str_contains($lowerName, 'comment') ) {
            return ($this->textDescendantCount)($node) >= 2;
        }

        if ( preg_match('/(^|\s)(post|preview|card)(\s|$)/', $lowerName) ) {
            $textCount = ($this->textDescendantCount)($node);
            if ( $textCount >= 3 ) {
                return true;
            }

            foreach ( $this->children($node) as $child ) {
                if ( null !== ($this->nodeAssetPath)($child) ) {
                    return $textCount >= 2;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>             $node
     * @param array<string, mixed>|null        $parentNode
     * @param array<int, array<string, mixed>> $children
     */
    private function isTopLevelSection(array $node, int $depth, int $sectionDepth, ?array $parentNode, array $children): bool
    {
        if ( $depth !== $sectionDepth ) {
            return false;
        }

        $textRuns = ($this->textDescendantCount)($node);
        if ( $textRuns < 2 && count($children) < 2 ) {
            return false;
        }

        if ( null !== $parentNode ) {
            $width = ($this->boxValue)($node, 'width');
            $parentWidth = ($this->boxValue)($parentNode, 'width');
            if ( null !== $width && null !== $parentWidth && $parentWidth > 0.0 ) {
                return ( $width / $parentWidth ) >= 0.6;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function landmarkTag(array $node, string $lowerName, int $depth, ?array $parentNode): ?string
    {
        $role = $this->layoutIntentClassifier->chromeGroupRole($node, $parentNode, $depth);
        if ( LayoutIntentClassifier::CHROME_GROUP_ROLE_HEADER === $role ) {
            return 'header';
        }
        if ( LayoutIntentClassifier::CHROME_GROUP_ROLE_FOOTER === $role ) {
            return 'footer';
        }
        if ( in_array($role, array(LayoutIntentClassifier::CHROME_GROUP_ROLE_NAVIGATION, LayoutIntentClassifier::CHROME_GROUP_ROLE_SOCIAL), true) ) {
            return 'nav';
        }

        if ( str_contains($lowerName, 'article') ) {
            return 'article';
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    private function isSemanticListItemNode(array $node): bool
    {
        $id = (string) ($node['id'] ?? '');
        return '' !== $id && in_array($id, $this->listItemIds($node), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isListItemOf(array $node, array $parentNode): bool
    {
        $id = (string) ($node['id'] ?? '');
        return '' !== $id && in_array($id, $this->listItemIds($parentNode), true);
    }

    /** @param array<string, mixed> $container */
    private function listItemIds(array $container): array
    {
        return ($this->listItemIds)($container);
    }

    /** @param array<string, mixed> $container */
    private function listLooksOrdered(array $container): bool
    {
        return ($this->listLooksOrdered)($container);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function children(array $node): array
    {
        return array_values(array_filter(($this->nodeList)($node), 'is_array'));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isNavigationLabelText(array $node, array $parentNode): bool
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $parentName = strtolower((string) ($parentNode['name'] ?? ''));
        if ( $this->isMenuItemName($parentName) ) {
            return true;
        }

        return (str_contains($parentName, 'nav') || str_contains($parentName, 'menu')) && ! $this->isMenuItemName($parentName);
    }

    private function isMenuItemName(string $lowerName): bool
    {
        return 1 === preg_match('/\b(menu|nav(?:igation)?)\s*item\b|\bitem\s*(menu|nav(?:igation)?)\b/', $lowerName);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isCompactControlTokenText(array $node, array $parentNode): bool
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $token = trim(($this->textContent)($node));
        if ( ! preg_match('/^(\d+|…|\.\.\.)$/', $token) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? '') . ' ' . (string) ($parentNode['name'] ?? ''));
        if ( str_contains($name, 'pagination') || str_contains($name, 'number') || str_contains($name, 'page') ) {
            return true;
        }

        $box = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        return isset($box['width'], $box['height'])
            && is_numeric($box['width'])
            && is_numeric($box['height'])
            && (float) $box['width'] <= 56.0
            && (float) $box['height'] <= 56.0;
    }
}
