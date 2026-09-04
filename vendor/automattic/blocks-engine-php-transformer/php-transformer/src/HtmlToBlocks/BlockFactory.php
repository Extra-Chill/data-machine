<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;

/**
 * @internal Block construction is owned by HtmlTransformer.
 */
final class BlockFactory
{
    /**
     * Semantic container tags `core/group` may render as its wrapper element.
     * `div` is the canonical default; the rest mirror the semantic HTML5
     * landmarks core's group block exposes via its `tagName` attribute.
     *
     * @var array<int, string>
     */
    private const GROUP_TAG_NAMES = array( 'div', 'header', 'nav', 'section', 'article', 'aside', 'footer', 'main', 'ul', 'ol', 'li' );

    /**
     * Blocks whose supports.layout accepts an authored layout attribute, per
     * the vendored block-library block.json files. Blocks declaring
     * layout {allowEditing:false} (quote, details, accordion items) manage
     * their own layout and never accept one; blocks without layout support
     * (list, paragraph, image) reject the attribute entirely.
     *
     * @var array<string, true>
     */
    private const LAYOUT_SUPPORTING_BLOCKS = array(
        'core/accordion'           => true,
        'core/buttons'             => true,
        'core/column'              => true,
        'core/comments-pagination' => true,
        'core/cover'               => true,
        'core/group'               => true,
        'core/navigation'          => true,
        'core/post-content'        => true,
        'core/post-template'       => true,
        'core/query'               => true,
        'core/query-pagination'    => true,
        'core/social-links'        => true,
        'core/tab-list'            => true,
        'core/tab-panel'           => true,
        'core/term-template'       => true,
        'core/terms-query'         => true,
    );

    /**
     * The subset whose supports.layout permits switching to type grid
     * (layout true, or an object without an allowSwitching:false pin to a
     * fixed flex default).
     *
     * @var array<string, true>
     */
    private const GRID_LAYOUT_BLOCKS = array(
        'core/accordion'     => true,
        'core/column'        => true,
        'core/cover'         => true,
        'core/group'         => true,
        'core/post-content'  => true,
        'core/post-template' => true,
        'core/query'         => true,
        'core/tab-panel'     => true,
        'core/term-template' => true,
        'core/terms-query'   => true,
    );

    private ?StyleAttributeMapper $styleMapper = null;

    private function styleMapper(): StyleAttributeMapper
    {
        return $this->styleMapper ??= new StyleAttributeMapper();
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function create(string $name, array $attrs = array(), array $innerBlocks = array()): array
    {
        $attrs = $this->normalizeAttrsForBlock($name, $attrs);
        $innerHtml = $this->blockHtml($name, $attrs, $innerBlocks);
        if ( is_array($innerHtml) ) {
            $innerContent = array( $innerHtml['opening'] );
            foreach ( $innerBlocks as $_ ) {
                $innerContent[] = null;
            }
            $innerContent[] = $innerHtml['closing'];
            $innerHtml      = $innerHtml['opening'] . $innerHtml['closing'];
        } else {
            $innerContent = array( $innerHtml );
        }

        return array(
            'blockName'    => $name,
            'attrs'        => $this->commentAttrs($name, $attrs),
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHtml,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function normalizeAttrsForBlock(string $name, array $attrs): array
    {
        $attrs = $this->normalizeClassNameAttr($attrs);

        // Layout is a block-supports opt-in. Stamping it on a block whose
        // supports do not accept an authored layout bakes is-layout-* classes
        // into save markup the block's canonical save never emits, so
        // downstream re-serialization rejects the block and reverts it.
        if ( isset($attrs['layout']) ) {
            $layoutType = is_array($attrs['layout']) ? strtolower((string) ($attrs['layout']['type'] ?? '')) : '';
            if ( ! isset(self::LAYOUT_SUPPORTING_BLOCKS[$name])
                || ( 'grid' === $layoutType && ! isset(self::GRID_LAYOUT_BLOCKS[$name]) )
            ) {
                unset($attrs['layout']);
            }
        }

        // These core save functions do not reproduce dimensions.maxWidth. Inline
        // max-width is retained by the generated geometry carrier stylesheet.
        if ( in_array($name, array( 'core/group', 'core/column', 'core/columns', 'core/image', 'core/list-item', 'core/media-text', 'core/paragraph', 'core/separator' ), true) ) {
            unset($attrs['style']['dimensions']['maxWidth']);
            if ( empty($attrs['style']['dimensions']) ) {
                unset($attrs['style']['dimensions']);
            }
            if ( empty($attrs['style']) ) {
                unset($attrs['style']);
            }
        }

        if ( 'core/media-text' === $name ) {
            $supportedStyleGroups = array( 'border', 'color', 'elements', 'spacing', 'typography' );
            if ( is_array($attrs['style'] ?? null) ) {
                foreach ( array_keys($attrs['style']) as $styleGroup ) {
                    if ( ! in_array($styleGroup, $supportedStyleGroups, true) ) {
                        unset($attrs['style'][ $styleGroup ]);
                    }
                }
                if ( empty($attrs['style']) ) {
                    unset($attrs['style']);
                }
            } else {
                unset($attrs['style']);
            }
            unset($attrs['inlineGeometryStyle']);
        }

        // Group save() only reproduces registered block-support styles. Arbitrary
        // source geometry already rides on the generated carrier class, so
        // duplicating it in saved markup makes the block invalid in Gutenberg.
        if ( 'core/group' === $name && preg_match('/(?:^|\s)be-inline-geometry-[^\s]+(?:\s|$)/', (string) ($attrs['className'] ?? '')) ) {
            unset($attrs['inlineGeometryStyle']);
        }

        if ( 'core/separator' === $name ) {
            unset($attrs['style']['spacing']['margin']['left'], $attrs['style']['spacing']['margin']['right']);
            if ( empty($attrs['style']['spacing']['margin']) ) {
                unset($attrs['style']['spacing']['margin']);
            }
            if ( empty($attrs['style']['spacing']) ) {
                unset($attrs['style']['spacing']);
            }
            if ( empty($attrs['style']) ) {
                unset($attrs['style']);
            }
        }

        if ( in_array($name, array( 'core/buttons', 'core/column', 'core/columns', 'core/group', 'core/heading', 'core/list', 'core/list-item', 'core/media-text', 'core/paragraph' ), true) ) {
            unset($attrs['style']['spacing']['blockGap']);
            if ( empty($attrs['style']['spacing']) ) {
                unset($attrs['style']['spacing']);
            }
            if ( empty($attrs['style']) ) {
                unset($attrs['style']);
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function normalizeClassNameAttr(array $attrs): array
    {
        if ( ! is_string($attrs['className'] ?? null) ) {
            return $attrs;
        }

        $classes = array();
        foreach ( preg_split('/\s+/', trim($attrs['className'])) ?: array() as $class ) {
            if ( '' === $class || GeneratedGutenbergClassPolicy::isGeneratedClassName($class) || in_array($class, $classes, true) ) {
                continue;
            }
            $classes[] = $class;
        }

        if ( array() === $classes ) {
            unset($attrs['className']);
            return $attrs;
        }

        $attrs['className'] = implode(' ', $classes);
        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function commentAttrs(string $name, array $attrs): array
    {
        unset($attrs['inlineGeometryStyle']);
        if ( 'core/paragraph' === $name && preg_match('/^\s*<a\b/i', (string) ($attrs['content'] ?? '')) ) {
            unset($attrs['content']);
        }
        if ( 'core/cover' === $name && 'px' === ($attrs['minHeightUnit'] ?? null) ) {
            unset($attrs['minHeightUnit']);
        }
        if ( 'core/media-text' === $name ) {
            if ( '' === ($attrs['mediaAlt'] ?? null) ) {
                unset($attrs['mediaAlt']);
            }
            if ( 'left' === ($attrs['mediaPosition'] ?? null) ) {
                unset($attrs['mediaPosition']);
            }
            if ( is_numeric($attrs['mediaWidth'] ?? null) && 50.0 === (float) $attrs['mediaWidth'] ) {
                unset($attrs['mediaWidth']);
            }
            if ( true === ($attrs['isStackedOnMobile'] ?? null) ) {
                unset($attrs['isStackedOnMobile']);
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return string|array{opening: string, closing: string}
     */
    private function blockHtml(string $name, array $attrs, array $innerBlocks): string|array
    {
        if ( 'core/heading' === $name ) {
            $level = (int) ($attrs['level'] ?? 2);
            $level = max(1, min(6, $level));
            return '<h' . $level . $this->blockSupportAttrs($attrs, 'wp-block-heading') . '>' . $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? '')) . '</h' . $level . '>';
        }

        if ( 'core/paragraph' === $name ) {
            return '<p' . $this->blockSupportAttrs($attrs) . '>' . $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? '')) . '</p>';
        }

        if ( 'core/list-item' === $name ) {
            $content = $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? ''));
            if ( array() !== $innerBlocks ) {
                return array( 'opening' => '<li' . $this->blockSupportAttrs($attrs) . '>' . $content, 'closing' => '</li>' );
            }

            return '<li' . $this->blockSupportAttrs($attrs) . '>' . $content . '</li>';
        }

        if ( 'core/list' === $name ) {
            $tagName = ! empty($attrs['ordered']) ? 'ol' : 'ul';
            return array( 'opening' => '<' . $tagName . $this->blockSupportAttrs($attrs, 'wp-block-list') . '>', 'closing' => '</' . $tagName . '>' );
        }

        if ( 'core/quote' === $name ) {
            $citation = $this->preserveRichTextPunctuation((string) ($attrs['citation'] ?? ''));
            $closing = '' !== $citation ? '<cite>' . $citation . '</cite></blockquote>' : '</blockquote>';
            return array( 'opening' => '<blockquote' . $this->blockSupportAttrs($attrs, 'wp-block-quote') . '>', 'closing' => $closing );
        }

        if ( 'core/pullquote' === $name ) {
            $citation = $this->preserveRichTextPunctuation((string) ($attrs['citation'] ?? ''));
            $citation = '' !== $citation ? '<cite>' . $citation . '</cite>' : '';
            return '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-pullquote') . '><blockquote>' . $this->preserveRichTextPunctuation((string) ($attrs['value'] ?? '')) . $citation . '</blockquote></figure>';
        }

        if ( 'core/code' === $name ) {
            $content = (string) ($attrs['content'] ?? '');
            if ( ! preg_match('/<(?:span|mark|b|strong|i|em)\b/i', $content) ) {
                $content = htmlspecialchars($content, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            return '<pre class="wp-block-code"><code>' . $content . '</code></pre>';
        }

        if ( 'core/math' === $name ) {
            return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-math') . '>' . ($attrs['content'] ?? '') . '</div>';
        }

        if ( 'core/icon' === $name ) {
            return '';
        }

        if ( 'core/preformatted' === $name ) {
            return '<pre' . $this->blockSupportAttrs($attrs, 'wp-block-preformatted') . '>' . ($attrs['content'] ?? '') . '</pre>';
        }

        if ( 'core/table' === $name ) {
            return $this->tableHtml($attrs);
        }

        if ( 'core/separator' === $name ) {
            return '<hr' . $this->blockSupportAttrs($attrs, implode(' ', GeneratedGutenbergClassPolicy::classesForBlock('core/separator'))) . ' />';
        }

        if ( 'core/spacer' === $name ) {
            $height = (string) ($attrs['height'] ?? '');
            $width = (string) ($attrs['width'] ?? '');
            $style = trim(implode(';', array_filter(array(
                '' !== $height ? 'height:' . $height : (is_string($attrs['style'] ?? null) ? $attrs['style'] : ''),
                '' !== $width ? 'width:' . $width : '',
                (string) ($attrs['inlineGeometryStyle'] ?? ''),
            ))), ';');
            if ( is_array($attrs['style'] ?? null) ) {
                $attrs['inlineGeometryStyle'] = $style;
            } else {
                $attrs['style'] = $style;
            }
            return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-spacer') . ' aria-hidden="true"></div>';
        }

        if ( 'core/columns' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-columns') . '>', 'closing' => '</div>' );
        }

        if ( 'core/column' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-column') . '>', 'closing' => '</div>' );
        }

        if ( 'core/details' === $name ) {
            return array(
                'opening' => '<details' . $this->blockSupportAttrs($attrs, 'wp-block-details') . ( ! empty($attrs['showContent']) ? ' open' : '' ) . '><summary>' . ($attrs['summary'] ?? '') . '</summary>',
                'closing' => '</details>',
            );
        }

        if ( 'core/accordion' === $name ) {
            return $this->roleWrapperHtml('group', $attrs, 'wp-block-accordion');
        }

        if ( 'core/accordion-item' === $name ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), ! empty($attrs['openByDefault']) ? 'is-open' : '');
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-accordion-item') . '>', 'closing' => '</div>' );
        }

        if ( 'core/accordion-heading' === $name ) {
            $level = (int) ($attrs['level'] ?? 3);
            $level = max(1, min(6, $level));
            $showIcon = ! array_key_exists('showIcon', $attrs) || false !== $attrs['showIcon'];
            $icon = $showIcon ? '<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>' : '';
            $title = '<span class="wp-block-accordion-heading__toggle-title">' . ($attrs['title'] ?? '') . '</span>';
            $children = 'left' === ($attrs['iconPosition'] ?? 'right') ? $icon . $title : $title . $icon;
            return '<h' . $level . $this->blockSupportAttrs($attrs, 'wp-block-accordion-heading') . '><button type="button" class="wp-block-accordion-heading__toggle">' . $children . '</button></h' . $level . '>';
        }

        if ( 'core/accordion-panel' === $name ) {
            return $this->roleWrapperHtml('region', $attrs, 'wp-block-accordion-panel');
        }

        if ( 'core/image' === $name ) {
            return $this->imageHtml($attrs);
        }

        if ( 'core/gallery' === $name ) {
            $caption = ! empty($attrs['caption']) ? '<figcaption class="blocks-gallery-caption wp-element-caption">' . $attrs['caption'] . '</figcaption>' : '';
            return array( 'opening' => '<figure' . $this->blockSupportAttrs($attrs, $this->galleryClasses($attrs)) . '>', 'closing' => $caption . '</figure>' );
        }

        if ( 'core/embed' === $name ) {
            return $this->embedHtml($attrs);
        }

        if ( 'core/file' === $name ) {
            return $this->fileHtml($attrs);
        }

        if ( 'core/video' === $name ) {
            return $this->mediaHtml('video', $attrs);
        }

        if ( 'core/audio' === $name ) {
            return $this->mediaHtml('audio', $attrs);
        }

        if ( 'core/search' === $name ) {
            return $this->searchHtml($attrs);
        }

        if ( 'core/html' === $name ) {
            return (string) ($attrs['content'] ?? '');
        }

        if ( 'core/buttons' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-buttons') . '>', 'closing' => '</div>' );
        }

        if ( 'core/button' === $name ) {
            return $this->buttonHtml($attrs);
        }

        // The navigation family (`core/navigation`, `core/navigation-link`,
        // `core/navigation-submenu`) are dynamic, server-rendered blocks:
        // `supports.html` is false and each registers a `render_callback`, so
        // their `save()` returns null. WordPress stores only the block comment
        // delimiters (plus serialized inner blocks); the `<nav>`/`<ul>`/`<li>`
        // chrome is produced at render time. Emitting that static markup into the
        // stored block makes `wp.blocks.validateBlock` re-run `save()` (empty),
        // see the leftover tags, and flag every navigation block invalid in the
        // editor. The label/url/className ride in the comment attributes, so the
        // canonical save()-matching shape carries no inner HTML at all.
        if ( 'core/navigation' === $name || 'core/navigation-submenu' === $name ) {
            return array( 'opening' => '', 'closing' => '' );
        }

        if ( 'core/navigation-link' === $name ) {
            return '';
        }

        if ( 'core/shortcode' === $name ) {
            return '<div class="wp-block-shortcode">' . ($attrs['text'] ?? '') . '</div>';
        }

        if ( 'core/cover' === $name ) {
            return $this->coverHtml($attrs, $innerBlocks);
        }

        if ( 'core/media-text' === $name ) {
            return $this->mediaTextHtml($attrs, $innerBlocks);
        }

        if ( 'core/group' === $name ) {
            $tag = $this->groupTagName($attrs['tagName'] ?? null);
            return array( 'opening' => '<' . $tag . $this->blockSupportAttrs($attrs, 'wp-block-group') . '>', 'closing' => '</' . $tag . '>' );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array{opening: string, closing: string}
     */
    private function coverHtml(array $attrs, array $innerBlocks): array
    {
        unset($innerBlocks);

        $wrapperAttrs = $attrs;
        unset($wrapperAttrs['layout']);
        if ( ! empty($attrs['minHeight']) ) {
            $unit = '' !== (string) ($attrs['minHeightUnit'] ?? '') ? (string) $attrs['minHeightUnit'] : 'px';
            $wrapperAttrs['inlineGeometryStyle'] = trim(
                (string) ($wrapperAttrs['inlineGeometryStyle'] ?? '') . ';min-height:' . (string) $attrs['minHeight'] . $unit,
                ';'
            );
        }

        $imageHtml = '';
        $url = (string) ($attrs['url'] ?? '');
        if ( '' !== $url ) {
            $imageAttrs = array(
                'class'                => 'wp-block-cover__image-background',
                'alt'                  => (string) ($attrs['alt'] ?? ''),
                'src'                  => $url,
                'style'                => '',
                'data-object-fit'      => 'cover',
                'data-object-position' => '',
            );
            if ( is_array($attrs['focalPoint'] ?? null) ) {
                $objectPosition = (string) (int) round((float) ($attrs['focalPoint']['x'] ?? 0.5) * 100)
                    . '% '
                    . (string) (int) round((float) ($attrs['focalPoint']['y'] ?? 0.5) * 100)
                    . '%';
                $imageAttrs['style'] = 'object-position:' . $objectPosition;
                $imageAttrs['data-object-position'] = $objectPosition;
            }
            $imageHtml = '<img' . $this->htmlAttrs($imageAttrs, array( 'alt' )) . '/>';
        }

        $overlayClasses = array( 'wp-block-cover__background' );
        $dimRatio = array_key_exists('dimRatio', $attrs) ? (int) $attrs['dimRatio'] : null;
        if ( null !== $dimRatio ) {
            if ( 50 !== $dimRatio ) {
                $overlayClasses[] = 'has-background-dim-' . (string) (10 * round($dimRatio / 10));
            }
            $overlayClasses[] = 'has-background-dim';
        }
        $customGradient = (string) ($attrs['customGradient'] ?? '');
        if ( '' !== $url && '' !== $customGradient && 0 !== $dimRatio ) {
            $overlayClasses[] = 'wp-block-cover__gradient-background';
        }
        if ( '' !== $customGradient ) {
            $overlayClasses[] = 'has-background-gradient';
        }
        $overlayStyles = array();
        if ( '' !== (string) ($attrs['customOverlayColor'] ?? '') ) {
            $overlayStyles[] = 'background-color:' . (string) $attrs['customOverlayColor'];
        }
        if ( '' !== $customGradient ) {
            $overlayStyles[] = 'background:' . $customGradient;
        }
        $overlayHtml = '<span' . $this->htmlAttrs(array(
            'aria-hidden' => 'true',
            'class'       => implode(' ', $overlayClasses),
            'style'       => implode(';', $overlayStyles),
        )) . '></span>';

        return array(
            'opening' => '<div' . $this->blockSupportAttrs($wrapperAttrs, 'wp-block-cover') . '>'
                . $imageHtml
                . $overlayHtml
                . '<div class="wp-block-cover__inner-container">',
            'closing' => '</div></div>',
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array{opening: string, closing: string}
     */
    private function mediaTextHtml(array $attrs, array $innerBlocks): array
    {
        unset($innerBlocks);

        $mediaOnRight = 'right' === ($attrs['mediaPosition'] ?? 'left');
        $verticalAlignment = (string) ($attrs['verticalAlignment'] ?? '');
        if ( ! in_array($verticalAlignment, array( 'top', 'center', 'bottom' ), true) ) {
            $verticalAlignment = '';
        }

        $wrapperClasses = array( 'wp-block-media-text' );
        if ( $mediaOnRight ) {
            $wrapperClasses[] = 'has-media-on-the-right';
        }
        if ( ! array_key_exists('isStackedOnMobile', $attrs) || false !== $attrs['isStackedOnMobile'] ) {
            $wrapperClasses[] = 'is-stacked-on-mobile';
        }
        if ( '' !== $verticalAlignment ) {
            $wrapperClasses[] = 'is-vertically-aligned-' . $verticalAlignment;
        }
        if ( ! empty($attrs['style']['elements']['link']['color']) ) {
            $wrapperClasses[] = 'has-link-color';
        }

        $wrapperAttrs = $attrs;
        $wrapperStyle = '';
        if ( is_numeric($attrs['mediaWidth'] ?? null) ) {
            $mediaWidth = (int) round((float) $attrs['mediaWidth']);
            if ( 50 !== $mediaWidth ) {
                $gridTemplateColumns = $mediaOnRight
                    ? 'auto ' . (string) $mediaWidth . '%'
                    : (string) $mediaWidth . '% auto';
                $wrapperStyle = 'grid-template-columns:' . $gridTemplateColumns;
            }
        }

        $wrapperOpening = '<div' . $this->blockSupportAttrs($wrapperAttrs, implode(' ', $wrapperClasses), $wrapperStyle) . '>';
        $contentOpening = '<div class="wp-block-media-text__content">';
        $figure = '<figure class="wp-block-media-text__media">' . $this->mediaTextMediaHtml($attrs) . '</figure>';

        if ( $mediaOnRight ) {
            return array(
                'opening' => $wrapperOpening . $contentOpening,
                'closing' => '</div>' . $figure . '</div>',
            );
        }

        return array(
            'opening' => $wrapperOpening . $figure . $contentOpening,
            'closing' => '</div></div>',
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function mediaTextMediaHtml(array $attrs): string
    {
        $mediaUrl = (string) ($attrs['mediaUrl'] ?? '');
        if ( 'video' === ($attrs['mediaType'] ?? '') ) {
            return '<video controls' . $this->htmlAttrs(array( 'src' => $mediaUrl )) . '></video>';
        }

        if ( 'image' !== ($attrs['mediaType'] ?? '') ) {
            return '';
        }

        $image = '';
        if ( '' !== $mediaUrl ) {
            $image = '<img' . $this->htmlAttrs(array(
                'src' => $mediaUrl,
                'alt' => (string) ($attrs['mediaAlt'] ?? ''),
            ), array( 'alt' )) . '/>';
        }

        $href = (string) ($attrs['href'] ?? '');
        if ( '' === $href ) {
            return $image;
        }

        return '<a' . $this->htmlAttrs(array(
            'class'  => (string) ($attrs['linkClass'] ?? ''),
            'href'   => $href,
            'target' => (string) ($attrs['linkTarget'] ?? ''),
            'rel'    => (string) ($attrs['rel'] ?? ''),
        )) . '>' . $image . '</a>';
    }

    /**
     * Resolve the wrapper tag for a `core/group`. Core's group `save()` renders
     * `<TagName>` from the `tagName` attribute, defaulting to `div`. Only the
     * semantic container and list tags used by the transformer are honored;
     * any other value falls back to `div` so output never diverges from save().
     */
    private function groupTagName(mixed $tagName): string
    {
        return is_string($tagName) && in_array($tagName, self::GROUP_TAG_NAMES, true) ? $tagName : 'div';
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array{opening: string, closing: string}
     */
    private function roleWrapperHtml(string $role, array $attrs, string $baseClass): array
    {
        return array( 'opening' => '<div role="' . $role . '"' . $this->blockSupportAttrs($attrs, $baseClass) . '>', 'closing' => '</div>' );
    }

    /**
     * Match core/button save(): useBlockProps.save() lives on the OUTER wrapper
     * <div>, so the block className and anchor id belong on the wrapper. The
     * inner <a>/<button> carries only structural classes plus color/border
     * support classes/styles.
     *
     * @param array<string, mixed> $attrs
     */
    private function buttonHtml(array $attrs): string
    {
        $support = $this->buttonStyleSupport($attrs);

        $wrapperAttrs = array(
            'id'    => (string) ($attrs['anchor'] ?? ''),
            'class' => $this->mergeClassNames('wp-block-button', $this->buttonWidthClasses($attrs), (string) ($attrs['className'] ?? '')),
            'style' => (string) ($attrs['inlineGeometryStyle'] ?? ''),
        );

        $controlAttrs = array(
            'class' => $this->mergeClassNames('wp-block-button__link', $support['classes'], 'wp-element-button'),
            'style' => $support['style'],
            'title' => (string) ($attrs['title'] ?? ''),
        );

        if ( 'button' === ($attrs['tagName'] ?? '') ) {
            $controlAttrs = array( 'type' => (string) ($attrs['type'] ?? 'button') ) + $controlAttrs;

            return '<div' . $this->htmlAttrs($wrapperAttrs) . '><button' . $this->htmlAttrs($controlAttrs) . '>' . $this->preserveRichTextPunctuation((string) ($attrs['text'] ?? '')) . '</button></div>';
        }

        $href = '' !== ($attrs['url'] ?? '') ? ' href="' . htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
        return '<div' . $this->htmlAttrs($wrapperAttrs) . '><a' . $this->htmlAttrs($controlAttrs) . $href . '>' . $this->preserveRichTextPunctuation((string) ($attrs['text'] ?? '')) . '</a></div>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function buttonWidthClasses(array $attrs): string
    {
        $width = (int) ($attrs['width'] ?? 0);
        if ( ! in_array($width, array( 25, 50, 75, 100 ), true) ) {
            return '';
        }

        return 'has-custom-width wp-block-button__width-' . $width;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function tableHtml(array $attrs): string
    {
        $tableAttrs = array(
            'class' => empty($attrs['hasFixedLayout']) && array_key_exists('hasFixedLayout', $attrs) ? '' : 'has-fixed-layout',
        );
        $html = '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-table') . '><table' . $this->htmlAttrs($tableAttrs) . '>';
        foreach ( array( 'head' => 'thead', 'body' => 'tbody', 'foot' => 'tfoot' ) as $attrName => $tagName ) {
            if ( empty($attrs[$attrName]) || ! is_array($attrs[$attrName]) ) {
                continue;
            }
            $html .= '<' . $tagName . '>';
            foreach ( $attrs[$attrName] as $row ) {
                $html .= '<tr>';
                foreach ( $row['cells'] ?? array() as $cell ) {
                    $cellTag = 'th' === ($cell['tag'] ?? '') ? 'th' : 'td';
                    $html .= '<' . $cellTag . '>' . $this->preserveRichTextPunctuation((string) ($cell['content'] ?? '')) . '</' . $cellTag . '>';
                }
                $html .= '</tr>';
            }
            $html .= '</' . $tagName . '>';
        }
        $html .= '</table>';
        if ( ! empty($attrs['caption']) ) {
            $html .= '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>';
        }
        return $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function imageHtml(array $attrs): string
    {
        $figureAttrs = $attrs;
        $baseClass = 'wp-block-image';
        $border = is_array($attrs['style']['border'] ?? null) ? $attrs['style']['border'] : array();
        $borderSupport = $this->styleSupport(array( 'border' => $border ));
        if ( array() !== $border ) {
            $baseClass = $this->mergeClassNames('wp-block-image has-custom-border', $borderSupport['classes']);
            unset($figureAttrs['style']['border']);
            if ( empty($figureAttrs['style']) ) {
                unset($figureAttrs['style']);
            }
        }
        if ( ! empty($attrs['sizeSlug']) ) {
            $figureAttrs['className'] = $this->mergeClassNames((string) ($figureAttrs['className'] ?? ''), 'size-' . (string) $attrs['sizeSlug']);
        }
        if ( '' !== (string) ($attrs['width'] ?? '') || '' !== (string) ($attrs['height'] ?? '') ) {
            $figureAttrs['className'] = $this->mergeClassNames('is-resized', (string) ($figureAttrs['className'] ?? ''));
        }

        $imageAttrs = array(
            'src'   => $attrs['url'] ?? '',
            'alt'   => $attrs['alt'] ?? '',
            'title' => $attrs['title'] ?? '',
            'class' => $this->mergeClassNames(! empty($attrs['id']) ? 'wp-image-' . (string) $attrs['id'] : '', $borderSupport['classes']),
            'style' => trim($this->imageDimensionStyle($attrs) . ';' . $borderSupport['style'], ';'),
        );

        $img = '<img' . $this->htmlAttrs($imageAttrs, array( 'alt' )) . '/>';
        if ( ! empty($attrs['href']) ) {
            $linkAttrs = array(
                'href'        => (string) $attrs['href'],
                'id'          => (string) ($attrs['linkAnchor'] ?? ''),
                'target'      => (string) ($attrs['linkTarget'] ?? ''),
                'rel'         => (string) ($attrs['rel'] ?? ''),
                'class'       => (string) ($attrs['linkClass'] ?? ''),
                'aria-label'  => (string) ($attrs['linkAriaLabel'] ?? ''),
                'aria-hidden' => (string) ($attrs['linkAriaHidden'] ?? ''),
                'tabindex'    => (string) ($attrs['linkTabIndex'] ?? ''),
            );
            $img = '<a' . $this->htmlAttrs($linkAttrs) . '>' . $img . '</a>';
        }
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>' : '';
        return '<figure' . $this->blockSupportAttrs($figureAttrs, $baseClass) . '>' . $img . $caption . '</figure>';
    }

    /**
     * Match the structural classes emitted by core/gallery save().
     *
     * @param array<string, mixed> $attrs
     */
    private function galleryClasses(array $attrs): string
    {
        $columns = (int) ($attrs['columns'] ?? 0);
        $classes = array('wp-block-gallery', 'has-nested-images', $columns > 0 ? 'columns-' . $columns : 'columns-default');
        if ( ! array_key_exists('imageCrop', $attrs) || false !== $attrs['imageCrop'] ) {
            $classes[] = 'is-cropped';
        }

        return implode(' ', $classes);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function imageDimensionStyle(array $attrs): string
    {
        if ( ! array_key_exists('width', $attrs) && ! array_key_exists('height', $attrs) && ! array_key_exists('scale', $attrs) && ! array_key_exists('aspectRatio', $attrs) ) {
            return '';
        }

        $style = array();
        // WordPress' image save writes aspect-ratio ahead of object-fit when an
        // aspectRatio attribute is present (typically alongside scale).
        if ( array_key_exists('aspectRatio', $attrs) && null !== $attrs['aspectRatio'] && '' !== (string) $attrs['aspectRatio'] ) {
            $style[] = 'aspect-ratio:' . (string) $attrs['aspectRatio'];
        }

        if ( array_key_exists('scale', $attrs) && null !== $attrs['scale'] ) {
            $style[] = 'object-fit:' . (string) $attrs['scale'];
        }

        // WordPress' image save applies dimension styles (including the forced
        // height:auto) only when a width or height attribute is provided; an
        // aspectRatio/scale-only image carries no width/height styles at all.
        if ( array_key_exists('width', $attrs) || array_key_exists('height', $attrs) ) {
            if ( array_key_exists('width', $attrs) && null !== $attrs['width'] ) {
                $style[] = 'width:' . (string) $attrs['width'];
            }

            if ( ! array_key_exists('height', $attrs) || null === $attrs['height'] ) {
                // Gutenberg's image save shape keeps percentage widths as width-only
                // styles. The image's intrinsic dimensions (including an SVG viewBox)
                // provide the automatic aspect ratio without serializing height:auto.
                if ( ! $this->isPercentageWidth((string) ($attrs['width'] ?? '')) ) {
                    $style[] = 'height:auto';
                }
            } else {
                $style[] = 'height:' . (string) $attrs['height'];
            }
        }

        return implode(';', $style);
    }

    private function isPercentageWidth(string $width): bool
    {
        return 1 === preg_match('/%\s*$/', trim($width));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function embedHtml(array $attrs): string
    {
        $url = htmlspecialchars((string) ($attrs['url'] ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $classes = array('wp-block-embed');
        if ( ! empty($attrs['type']) ) {
            $classes[] = 'is-type-' . (string) $attrs['type'];
        }
        if ( ! empty($attrs['providerNameSlug']) ) {
            $classes[] = 'is-provider-' . (string) $attrs['providerNameSlug'];
            $classes[] = 'wp-block-embed-' . (string) $attrs['providerNameSlug'];
        }

        $figureAttrs = $attrs;
        $figureAttrs['className'] = $this->mergeClassNames(implode(' ', $classes), (string) ($attrs['className'] ?? ''));

        return '<figure' . $this->blockSupportAttrs($figureAttrs) . '><div class="wp-block-embed__wrapper">' . $url . '</div></figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function fileHtml(array $attrs): string
    {
        $href = (string) ($attrs['href'] ?? $attrs['url'] ?? '');
        $text = (string) ($attrs['text'] ?? ($href !== '' ? basename(parse_url($href, PHP_URL_PATH) ?: $href) : ''));
        $linkAttrs = array(
            'href' => $href,
        );

        $downloadButton = '';
        if ( ! empty($attrs['showDownloadButton']) ) {
            $downloadButton = '<a class="wp-block-file__button wp-element-button" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" download>Download</a>';
        }

        return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-file') . '><a' . $this->htmlAttrs($linkAttrs) . '>' . $this->preserveRichTextPunctuation($text) . '</a>' . $downloadButton . '</div>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function mediaHtml(string $tagName, array $attrs): string
    {
        $mediaAttrs = array(
            'src'      => (string) ($attrs['src'] ?? ''),
            'poster'   => (string) ($attrs['poster'] ?? ''),
            'preload'  => (string) ($attrs['preload'] ?? ''),
            'width'    => (string) ($attrs['width'] ?? ''),
            'height'   => (string) ($attrs['height'] ?? ''),
            'controls' => ! empty($attrs['controls']) ? 'controls' : '',
        );
        if ( 'video' === $tagName ) {
            $mediaAttrs['autoplay']   = ! empty($attrs['autoplay']) ? 'autoplay' : '';
            $mediaAttrs['loop']       = ! empty($attrs['loop']) ? 'loop' : '';
            $mediaAttrs['muted']      = ! empty($attrs['muted']) ? 'muted' : '';
            $mediaAttrs['playsinline'] = ! empty($attrs['playsInline']) ? 'playsinline' : '';
        }
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>' : '';

        return '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-' . $tagName) . '><' . $tagName . $this->htmlAttrs($mediaAttrs) . '></' . $tagName . '>' . $caption . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function searchHtml(array $attrs): string
    {
        // core/search is dynamic in the supported runtime: save() returns null,
        // so stored blocks must carry no static form markup.
        return '';
    }

    /**
     * Translate a core/button block's native style support into the rendered
     * has-* support classes and the inline style string WordPress emits for
     * custom colors and borders. Accepts the canonical `style` object; falls back
     * to a legacy raw `style` string when present for backward compatibility.
     *
     * @param array<string, mixed> $attrs
     * @return array{classes: string, style: string}
     */
    private function buttonStyleSupport(array $attrs): array
    {
        $style = $attrs['style'] ?? null;
        if ( ! is_array($style) ) {
            return array(
                'classes' => '',
                'style'   => is_string($style) ? $style : '',
            );
        }

        $classes = array();
        $declarations = array();

        $background = (string) ($style['color']['background'] ?? '');
        $gradient = (string) ($style['color']['gradient'] ?? '');
        $text = (string) ($style['color']['text'] ?? '');
        if ( '' !== $text ) {
            $classes[] = 'has-text-color';
        }
        if ( '' !== $background || '' !== $gradient ) {
            $classes[] = 'has-background';
        }

        $border = is_array($style['border'] ?? null) ? $style['border'] : array();
        if ( isset($border['color']) && '' !== (string) $border['color'] ) {
            $classes[] = 'has-border-color';
            $declarations[] = 'border-color:' . (string) $border['color'];
        }
        if ( isset($border['style']) && '' !== (string) $border['style'] ) {
            $declarations[] = 'border-style:' . (string) $border['style'];
        }
        if ( isset($border['width']) && '' !== (string) $border['width'] ) {
            $declarations[] = 'border-width:' . (string) $border['width'];
        }
        if ( isset($border['radius']) && '' !== (string) $border['radius'] ) {
            $declarations[] = 'border-radius:' . (string) $border['radius'];
        }

        if ( '' !== $text ) {
            $declarations[] = 'color:' . $text;
        }
        if ( '' !== $background ) {
            $declarations[] = 'background-color:' . $background;
        }
        if ( '' !== $gradient ) {
            $declarations[] = 'background:' . $gradient;
        }

        $shadow = trim((string) ($style['shadow'] ?? ''));
        if ( '' !== $shadow ) {
            $declarations[] = 'box-shadow:' . $shadow;
        }

        $padding = is_array($style['spacing']['padding'] ?? null) ? $style['spacing']['padding'] : array();
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            if ( isset($padding[$side]) && '' !== (string) $padding[$side] ) {
                $declarations[] = 'padding-' . $side . ':' . (string) $padding[$side];
            }
        }

        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
        if ( isset($typography['fontSize']) && '' !== (string) $typography['fontSize'] ) {
            $classes[] = 'has-custom-font-size';
        }
        $typographyMap = array(
            // A raw authored family is a custom value, so core's style engine
            // serializes it inline rather than as a has-*-font-family class.
            'fontFamily'    => 'font-family',
            'fontSize'      => 'font-size',
            'fontWeight'    => 'font-weight',
            'letterSpacing' => 'letter-spacing',
            'lineHeight'    => 'line-height',
            'textTransform' => 'text-transform',
        );
        foreach ( $typographyMap as $attrName => $cssName ) {
            if ( isset($typography[$attrName]) && '' !== (string) $typography[$attrName] ) {
                $declarations[] = $cssName . ':' . (string) $typography[$attrName];
            }
        }

        return array(
            'classes' => implode(' ', $classes),
            'style'   => implode(';', $declarations),
        );
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

    /**
     * @param array<string, mixed> $attrs
     */
    private function blockSupportAttrs(array $attrs, string $baseClass = '', ?string $styleOverride = null): string
    {
        $support = $this->styleSupport($attrs['style'] ?? null);
        $presetClasses = $this->presetColorClasses($attrs);
        $layoutClasses = $this->layoutClasses($attrs['layout'] ?? null, $baseClass);
        $alignmentClasses = $this->textAlignmentClasses($attrs);
        $classes = $this->mergeClassNames($baseClass, $presetClasses, $support['classes'], $layoutClasses, $alignmentClasses, (string) ($attrs['className'] ?? ''));
        $style = trim(
            (string) $support['style'] . ';' . (null === $styleOverride
                ? (string) ($attrs['inlineGeometryStyle'] ?? '')
                : $styleOverride),
            ';'
        );
        return $this->htmlAttrs(array(
            'id'    => (string) ($attrs['anchor'] ?? ''),
            'class' => $classes,
            'style' => $style,
        ));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function textAlignmentClasses(array $attrs): string
    {
        $align = strtolower(trim((string) ($attrs['align'] ?? '')));
        return in_array($align, array( 'left', 'center', 'right' ), true) ? 'has-text-align-' . $align : '';
    }

    /**
     * Serialize the canonical block `style` OBJECT into the has-* support classes
     * and inline CSS string WordPress emits in `save()`. A legacy raw `style`
     * string is passed through unchanged for backward compatibility.
     *
     * @param mixed $style
     * @return array{classes: string, style: string}
     */
    private function styleSupport(mixed $style): array
    {
        if ( is_array($style) ) {
            return $this->styleMapper()->serialize($style);
        }

        return array(
            'classes' => '',
            'style'   => is_string($style) ? $style : '',
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function presetColorClasses(array $attrs): string
    {
        $classes = array();
        $textColor = $this->safeSlug((string) ($attrs['textColor'] ?? ''));
        if ( '' !== $textColor ) {
            $classes[] = 'has-' . $textColor . '-color';
            $classes[] = 'has-text-color';
        }

        $backgroundColor = $this->safeSlug((string) ($attrs['backgroundColor'] ?? ''));
        if ( '' !== $backgroundColor ) {
            $classes[] = 'has-' . $backgroundColor . '-background-color';
            $classes[] = 'has-background';
        }

        return implode(' ', $classes);
    }

    private function layoutClasses(mixed $layout, string $baseClass): string
    {
        if ( ! is_array($layout) ) {
            return '';
        }

        $type = $this->safeSlug((string) ($layout['type'] ?? ''));
        if ( ! in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
            return '';
        }

        return $this->mergeClassNames(
            'is-layout-' . $type,
            '' !== $baseClass ? $baseClass . '-is-layout-' . $type : ''
        );
    }

    private function safeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_-]+$/', $value) ? $value : '';
    }

    /**
     * Keep source-authored straight punctuation stable through wptexturize.
     */
    private function preserveRichTextPunctuation(string $html): string
    {
        $output = '';
        $inTag = false;
        $attributeQuote = '';
        $length = strlen($html);

        for ( $index = 0; $index < $length; ++$index ) {
            $character = $html[$index];
            if ( ! $inTag && '<' === $character ) {
                $inTag = true;
                $output .= $character;
                continue;
            }
            if ( $inTag ) {
                if ( '' !== $attributeQuote ) {
                    if ( $attributeQuote === $character ) {
                        $attributeQuote = '';
                    }
                } elseif ( '"' === $character || "'" === $character ) {
                    $attributeQuote = $character;
                } elseif ( '>' === $character ) {
                    $inTag = false;
                }
                $output .= $character;
                continue;
            }

            $output .= match ( $character ) {
                '"' => '&quot;',
                "'" => '&#039;',
                default => $character,
            };
        }

        return $output;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<int, string> $includeEmpty
     */
    private function htmlAttrs(array $attrs, array $includeEmpty = array()): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            if ( '' === $value && ! in_array($name, $includeEmpty, true) ) {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }
}
