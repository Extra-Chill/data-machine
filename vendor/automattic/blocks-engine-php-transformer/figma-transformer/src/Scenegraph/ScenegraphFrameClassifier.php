<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Classifies top-level FRAME candidates into ROLES so the page planner can keep
 * style-guide / design-system frames out of page selection and tag every real
 * page with a WordPress-template-aligned page type.
 *
 * This is the foundational "abstract known-truths" layer: before any pixels are
 * emitted, the transformer decides WHAT each top-level frame IS. The classifier
 * is generic and signal-based — it reads the frame's NAME plus light
 * content-shape metrics (text/asset density, repeating sibling structure) the
 * planner already gathers, never a hardcoded list of file-specific frame names.
 *
 * Roles:
 *   - {@see ROLE_DESIGN_SYSTEM}: a style guide / design system / token /
 *     component-gallery frame. These INFORM the design system (another layer
 *     extracts their tokens) and must be EXCLUDED from page selection.
 *   - {@see ROLE_PAGE}: a real page frame. Every page carries a `page_type`
 *     aligned to the WordPress template hierarchy so the downstream
 *     php-transformer can map it to a WP template:
 *       - {@see PAGE_TYPE_FRONT_PAGE} (front-page.php / front page)
 *       - {@see PAGE_TYPE_SINGLE}     (single.php — a single post/article)
 *       - {@see PAGE_TYPE_ARCHIVE}    (archive.php / index.php — a list of cards)
 *       - {@see PAGE_TYPE_PAGE}       (page.php — a generic content page)
 *       - {@see PAGE_TYPE_UNKNOWN}    (fallback when no signal is decisive)
 *
 * Every classification carries the ordered list of signals that drove it, so the
 * diagnostic surface can explain WHY a frame landed in a role / page type.
 */
final class ScenegraphFrameClassifier
{
    public const ROLE_DESIGN_SYSTEM = 'design_system';
    public const ROLE_PAGE          = 'page';

    public const PAGE_TYPE_FRONT_PAGE = 'front_page';
    public const PAGE_TYPE_SINGLE     = 'single';
    public const PAGE_TYPE_ARCHIVE    = 'archive';
    public const PAGE_TYPE_404        = '404';
    public const PAGE_TYPE_PAGE       = 'page';
    public const PAGE_TYPE_UNKNOWN    = 'unknown';

    /**
     * Minimum count of near-uniform, small, leaf-like siblings under a frame for
     * its content shape to read as a swatch/specimen grid (a design-system tell)
     * rather than ordinary page content.
     */
    private const SWATCH_GRID_MIN_TILES = 6;

    /**
     * Minimum count of structurally-similar repeating sibling subtrees for a
     * frame's content shape to read as a list of post cards (an archive tell).
     */
    private const CARD_LIST_MIN_CARDS = 3;

    /**
     * Classify one top-level frame from frame-level signals.
     *
     * @param array{
     *     name?: string,
     *     width?: float|null,
     *     height?: float|null,
     *     text_count?: int,
     *     asset_count?: int,
     *     uniform_tile_count?: int,
     *     repeating_card_count?: int,
     *     section_name?: string|null,
     *     page_name?: string|null
     * } $signals
     * @return array{
     *     role: string,
     *     page_type: string|null,
     *     signals: array<int, string>,
     *     is_page: bool
     * }
     */
    public function classify(array $signals): array
    {
        $name = trim((string) ($signals['name'] ?? ''));
        $sectionName = trim((string) ($signals['section_name'] ?? ''));
        $pageName = trim((string) ($signals['page_name'] ?? ''));
        $scopeNames = trim($name . ' ' . $sectionName . ' ' . $pageName);

        $designSystem = $this->assessDesignSystem($name, $scopeNames, $signals);
        if ( $designSystem['is_design_system'] ) {
            return array(
                'role'      => self::ROLE_DESIGN_SYSTEM,
                'page_type' => null,
                'signals'   => $designSystem['signals'],
                'is_page'   => false,
            );
        }

        $pageType = $this->assessPageType($name, $signals);

        return array(
            'role'      => self::ROLE_PAGE,
            'page_type' => $pageType['page_type'],
            'signals'   => $pageType['signals'],
            'is_page'   => true,
        );
    }

    /**
     * Decide whether a frame is a design-system / style-guide frame.
     *
     * Name signals win first (the explicit "Style Guide" / "Design System" /
     * "Tokens" / "Components" / "Styles" family). When the name is silent, the
     * content shape can still betray a style guide: a grid of near-uniform color
     * swatches, or a dense type-specimen frame with many text nodes and almost no
     * imagery and no card structure.
     *
     * @param array<string, mixed> $signals
     * @return array{is_design_system: bool, signals: array<int, string>}
     */
    private function assessDesignSystem(string $name, string $scopeNames, array $signals): array
    {
        $reasons = array();

        if ( $this->matchesDesignSystemName($name) ) {
            $reasons[] = 'name:design_system';
        } elseif ( $this->matchesDesignSystemName($scopeNames) ) {
            // Scoped under a "Style Guide" section/page even when the frame's own
            // name is generic (e.g. a "Colors" frame inside a "Design System"
            // section).
            $reasons[] = 'scope:design_system';
        }

        $uniformTiles = (int) ($signals['uniform_tile_count'] ?? 0);
        $repeatingCards = (int) ($signals['repeating_card_count'] ?? 0);
        if ( $uniformTiles >= self::SWATCH_GRID_MIN_TILES && $repeatingCards < self::CARD_LIST_MIN_CARDS ) {
            // A grid of many near-uniform small tiles with no card structure reads
            // as a swatch/specimen palette, not page content.
            $reasons[] = 'content:swatch_grid';
        }

        // A name/scope match alone is decisive. A pure content-shape match is
        // only decisive when nothing about the frame looks like a real page (no
        // page-type name signal), so a "Colors" hero section on a marketing page
        // is not misread as a style guide.
        $nameMatched = in_array('name:design_system', $reasons, true)
            || in_array('scope:design_system', $reasons, true);
        $contentMatched = in_array('content:swatch_grid', $reasons, true);
        $looksLikePage = '' !== $this->pageTypeNameMatch($name);

        $isDesignSystem = $nameMatched || ( $contentMatched && ! $looksLikePage );

        // Suppress a name match if the frame ALSO carries a strong page-type name
        // ("Components Landing Page") — the explicit page intent wins.
        if ( in_array('name:design_system', $reasons, true) && '' !== $this->pageTypeNameMatch($name) && $this->hasPageIntentSuffix($name) ) {
            $isDesignSystem = false;
            $reasons[] = 'override:page_intent';
        }

        // A page-sized frame with its own explicit front-page name (for example
        // a top-level "Home" artboard) remains a page even if the surrounding
        // canvas/section has design-system language. Keep this narrow: generic
        // page/archive labels inside style-guide scopes are often component or
        // pattern specimens, not routes.
        if ( in_array('scope:design_system', $reasons, true) && self::PAGE_TYPE_FRONT_PAGE === $this->pageTypeNameMatch($name) && $this->isPageSized($signals) ) {
            $isDesignSystem = false;
            $reasons[] = 'override:page_intent';
        }

        return array(
            'is_design_system' => $isDesignSystem,
            'signals'          => $reasons,
        );
    }

    /**
     * Resolve the WordPress-template-aligned page type for a real page frame.
     *
     * Name signals are authoritative when present (front page, single, archive,
     * generic page); when the name is silent the content shape decides: a single
     * long article body (one dominant text-heavy column, little repetition) reads
     * as `single`; a repeating list of post cards reads as `archive`; otherwise
     * `page`, falling to `unknown` only when even that is unclear.
     *
     * @param array<string, mixed> $signals
     * @return array{page_type: string, signals: array<int, string>}
     */
    private function assessPageType(string $name, array $signals): array
    {
        $reasons = array();

        $nameType = $this->pageTypeNameMatch($name);
        if ( '' !== $nameType ) {
            $reasons[] = 'name:' . $nameType;

            return array('page_type' => $nameType, 'signals' => $reasons);
        }

        $textCount = (int) ($signals['text_count'] ?? 0);
        $repeatingCards = (int) ($signals['repeating_card_count'] ?? 0);

        if ( $repeatingCards >= self::CARD_LIST_MIN_CARDS ) {
            $reasons[] = 'content:card_list';

            return array('page_type' => self::PAGE_TYPE_ARCHIVE, 'signals' => $reasons);
        }

        if ( $textCount > 0 && $repeatingCards < self::CARD_LIST_MIN_CARDS && $this->isArticleShape($signals) ) {
            $reasons[] = 'content:article_body';

            return array('page_type' => self::PAGE_TYPE_SINGLE, 'signals' => $reasons);
        }

        if ( $textCount > 0 ) {
            $reasons[] = 'content:generic_content';

            return array('page_type' => self::PAGE_TYPE_PAGE, 'signals' => $reasons);
        }

        $reasons[] = 'fallback:unknown';

        return array('page_type' => self::PAGE_TYPE_UNKNOWN, 'signals' => $reasons);
    }

    private function matchesDesignSystemName(string $value): bool
    {
        return 1 === preg_match(
            '/\b(style\s*guide|styleguide|design\s*system|design\s*tokens?|tokens?|components?|component\s*library|ui\s*kit|pattern\s*library|styles?|typography|color\s*palette|swatches?)\b/i',
            $value
        );
    }

    /**
     * Map a frame name to a page type, or '' when the name carries no page
     * signal. The ordering matters: front page first (most specific), then the
     * single/archive families, then the generic page fallback.
     */
    private function pageTypeNameMatch(string $name): string
    {
        if ( 1 === preg_match('/\b(home\s*page|homepage|home|landing(\s*page)?|front\s*page)\b/i', $name) ) {
            return self::PAGE_TYPE_FRONT_PAGE;
        }

        // Archive / list views before single, so "Blog" (an index) is not read as
        // a single article. "Blog Post" / "Article" / "Post" detail read single.
        if ( 1 === preg_match('/\b(blog\s*post|article|post\s*detail|single\s*post)\b/i', $name) ) {
            return self::PAGE_TYPE_SINGLE;
        }

        if ( 1 === preg_match('/\b(archive|blog|index|news|articles|posts|category|categories|tag|search\s*results|listing)\b/i', $name) ) {
            return self::PAGE_TYPE_ARCHIVE;
        }

        if ( 1 === preg_match('/\b(404|not\s+found|error\s+page)\b/i', $name) ) {
            return self::PAGE_TYPE_404;
        }

        if ( 1 === preg_match('/\b(about|contact|pricing|services?|team|faq|privacy|terms|page|portfolio|gallery|product|shop)\b/i', $name) ) {
            return self::PAGE_TYPE_PAGE;
        }

        return '';
    }

    /**
     * Whether a name carries an explicit page-intent suffix ("... Page",
     * "... Landing"), used to let page intent override a design-system name token
     * in mixed names like "Components Landing Page".
     */
    private function hasPageIntentSuffix(string $name): bool
    {
        return 1 === preg_match('/\b(page|landing|home\s*page|homepage)\b/i', $name);
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function isPageSized(array $signals): bool
    {
        $width = isset($signals['width']) && is_numeric($signals['width']) ? (float) $signals['width'] : 0.0;
        $height = isset($signals['height']) && is_numeric($signals['height']) ? (float) $signals['height'] : 0.0;

        return $width >= 320.0 && $width <= 2048.0 && $height >= 700.0;
    }

    /**
     * Content-shape heuristic for a single article body: a text-dominant frame
     * with no card repetition and a tall, narrow-ish reading column. The planner
     * supplies the raw metrics; we keep the rule conservative so generic content
     * pages fall to `page`, not `single`.
     *
     * @param array<string, mixed> $signals
     */
    private function isArticleShape(array $signals): bool
    {
        $textCount = (int) ($signals['text_count'] ?? 0);
        $assetCount = (int) ($signals['asset_count'] ?? 0);
        $width = is_numeric($signals['width'] ?? null) ? (float) $signals['width'] : 0.0;
        $height = is_numeric($signals['height'] ?? null) ? (float) $signals['height'] : 0.0;

        $textDominant = $textCount >= 6 && $textCount >= ($assetCount * 2 + 1);
        $tallReadingColumn = $height > 0 && $width > 0 && $height >= $width;

        return $textDominant && $tallReadingColumn;
    }
}
