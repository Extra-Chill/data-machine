<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    private const EXTERNAL_VECTOR_SVG_BYTES = 65536;
    private const INLINE_VECTOR_SVG_BUDGET_BYTES = 32768;

    private LayoutGapResolver $layoutGapResolver;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetsById = array();

    /**
     * @var array<string, string>
     */
    private array $assetUnavailableReasonsById = array();

    /**
     * @var callable|null
     */
    private mixed $archiveAssetContentResolver = null;

    /**
     * @var array<string, bool>
     */
    private array $usedAssetPaths = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $generatedAssetFiles = array();

    /**
     * @var array<string, string>
     */
    private array $generatedVectorSvgPathsByHash = array();

    private int $inlineVectorSvgBytes = 0;

    private bool $renderTextGlyphPaths = false;

    private ?FontResolver $fontResolver = null;

    private ?TypographyModel $typographyModel = null;

    /**
     * @var array<string, string> TypographyModel signature => font-size token name.
     */
    private array $typographyTokenVars = array();

    private function fontResolver(): FontResolver
    {
        return $this->fontResolver ??= new FontResolver();
    }

    private function typographyModel(): TypographyModel
    {
        return $this->typographyModel ??= new TypographyModel($this->fontResolver());
    }

    private ?DesignSystemExtractor $designSystemExtractor = null;

    private ?VectorSvgRenderer $vectorSvgRenderer = null;

    private ?StyleDeclarationBuilder $styleDeclarationBuilder = null;

    private ?TextStyleDeclarationResolver $textStyleDeclarationResolver = null;

    private ?PaintStackResolver $paintStackResolver = null;

    private ?TransformDiagnosticsBuilder $transformDiagnosticsBuilder = null;

    private ?StaticHtmlEmissionDiagnostics $staticHtmlEmissionDiagnostics = null;

    private ?EffectOverflowPolicy $effectOverflowPolicy = null;

    private ?ClipMaskStyleResolver $clipMaskStyleResolver = null;

    private ?CssPositioningResolver $cssPositioningResolver = null;

    private ?CanvasShellResolver $canvasShellResolver = null;

    private ?PositioningStyleResolver $positioningStyleResolver = null;

    private ?StickyLayoutCoordinator $stickyLayoutCoordinator = null;

    private ?HtmlArtifactAssembler $htmlArtifactAssembler = null;

    private ?BreakpointMediaDiffBuilder $breakpointMediaDiffBuilder = null;

    private ?BreakpointDimensionPolicy $breakpointDimensionPolicy = null;

    private ?ChildLayerCompositionResolver $childLayerCompositionResolver = null;

    private ?LocalBorderShellClusterResolver $localBorderShellClusterResolver = null;

    private ?StaticHtmlCssRuleSet $staticHtmlCssRuleSet = null;

    private ?SourceGeometryFlexGapResolver $sourceGeometryFlexGapResolver = null;

    public function __construct(?LayoutGapResolver $layoutGapResolver = null)
    {
        $this->layoutGapResolver = $layoutGapResolver ?? new LayoutGapResolver();
        $this->linkState = new StaticHtmlLinkState();
    }

    private ?LayoutFrameRoleClassifier $layoutFrameRoleClassifier = null;

    private ?StaticHtmlSemanticClassifier $staticHtmlSemanticClassifier = null;

    private function designSystemExtractor(): DesignSystemExtractor
    {
        return $this->designSystemExtractor ??= new DesignSystemExtractor($this->fontResolver());
    }

    private function vectorSvgRenderer(): VectorSvgRenderer
    {
        return $this->vectorSvgRenderer ??= new VectorSvgRenderer(
            fn (array $node): array => $this->nodeList($node),
            fn (float $value): string => $this->number($value),
            fn (string $value): string => $this->sanitizeAttribute($value),
            fn (array $paints): ?string => $this->firstSolidPaint($paints),
            fn (array $node): ?string => $this->backgroundColor($node),
            fn (array $node): array => $this->nodeImagePaints($node),
            fn (array $node): array => $this->explicitNodeAssetReferences($node),
        );
    }

    private function styleDeclarationBuilder(): StyleDeclarationBuilder
    {
        return $this->styleDeclarationBuilder ??= new StyleDeclarationBuilder(
            fn (float $value): string => $this->number($value),
            fn (array $paints): ?array => $this->firstCssPaint($paints),
            fn (mixed $value, mixed $opacity = null): ?string => $this->color($value, $opacity),
        );
    }

    private function staticHtmlCssRuleSet(): StaticHtmlCssRuleSet
    {
        return $this->staticHtmlCssRuleSet ??= new StaticHtmlCssRuleSet();
    }

    private function sourceGeometryFlexGapResolver(): SourceGeometryFlexGapResolver
    {
        return $this->sourceGeometryFlexGapResolver ??= new SourceGeometryFlexGapResolver(
            $this->layoutIntentClassifier(),
            fn (array $node, array $parentNode): bool => $this->normalFlexFlowChild($node, $parentNode),
        );
    }

    private function textStyleDeclarationResolver(): TextStyleDeclarationResolver
    {
        return $this->textStyleDeclarationResolver ??= new TextStyleDeclarationResolver(
            $this->typographyModel(),
            fn (float $value): string => $this->number($value),
            fn (mixed $value, mixed $opacity = null): ?string => $this->color($value, $opacity),
        );
    }

    private function paintStackResolver(): PaintStackResolver
    {
        return $this->paintStackResolver ??= new PaintStackResolver(
            fn (array $paint): ?string => $this->resolveAndMarkPaintAssetPath($paint),
            fn (float $value): string => $this->number($value),
            fn (mixed $value, mixed $opacity = null): ?string => $this->color($value, $opacity),
        );
    }

    private function transformDiagnosticsBuilder(): TransformDiagnosticsBuilder
    {
        return $this->transformDiagnosticsBuilder ??= new TransformDiagnosticsBuilder();
    }

    private function staticHtmlEmissionDiagnostics(): StaticHtmlEmissionDiagnostics
    {
        return $this->staticHtmlEmissionDiagnostics ??= new StaticHtmlEmissionDiagnostics();
    }

    private function visualGeometryResolver(): VisualGeometryResolver
    {
        return new VisualGeometryResolver($this->layoutIntentClassifier());
    }

    private function effectOverflowPolicy(): EffectOverflowPolicy
    {
        return $this->effectOverflowPolicy ??= new EffectOverflowPolicy();
    }

    private function clipMaskStyleResolver(): ClipMaskStyleResolver
    {
        return $this->clipMaskStyleResolver ??= new ClipMaskStyleResolver(
            $this->effectOverflowPolicy(),
            fn (array $node): bool => $this->stickyLayoutCoordinator()->containsStickyPrimary($node),
        );
    }

    private function cssPositioningResolver(): CssPositioningResolver
    {
        return $this->cssPositioningResolver ??= new CssPositioningResolver(
            $this->layoutIntentClassifier(),
            fn (float $value): string => $this->number($value),
        );
    }

    private function canvasShellResolver(): CanvasShellResolver
    {
        return $this->canvasShellResolver ??= new CanvasShellResolver(
            $this->layoutFrameRoleClassifier(),
            fn (array $node): bool => $this->isFreeformContainer($node),
            fn (array $node): bool => $this->freeformContainerShouldUseFlow($node),
            fn (array $node): bool => $this->hasAbsoluteChild($node),
            fn (array $node): bool => $this->hasDecorativeFlexUnderlayChild($node),
            $this->visualGeometryResolver(),
            $this->breakpointDimensionPolicy(),
        );
    }

    private function positioningStyleResolver(): PositioningStyleResolver
    {
        return $this->positioningStyleResolver ??= new PositioningStyleResolver(
            $this->layoutIntentClassifier(),
            $this->cssPositioningResolver(),
            $this->canvasShellResolver(),
            fn (array $node): bool => $this->isFreeformContainer($node),
            fn (array $node): bool => $this->freeformContainerShouldUseFlow($node),
            fn (array $node, array $parentNode): bool => $this->isDecorativeFlexUnderlay($node, $parentNode),
            fn (array $node): bool => $this->hasDecorativeFlexUnderlayChild($node),
        );
    }

    private function stickyLayoutCoordinator(): StickyLayoutCoordinator
    {
        return $this->stickyLayoutCoordinator ??= new StickyLayoutCoordinator(
            fn (array $node): array => $this->nodeList($node),
            fn (array $node): string => $this->textContent($node),
        );
    }

    private function htmlArtifactAssembler(): HtmlArtifactAssembler
    {
        return $this->htmlArtifactAssembler ??= new HtmlArtifactAssembler(
            fn (string $value): string => $this->sanitizeAttribute($value),
        );
    }

    private function breakpointMediaDiffBuilder(): BreakpointMediaDiffBuilder
    {
        return $this->breakpointMediaDiffBuilder ??= new BreakpointMediaDiffBuilder(
            $this->stickyLayoutCoordinator(),
            fn (array $node): array => $this->nodeList($node),
            fn (array $node, string $type, ?array $parentNode, ?array $grandParentNode): array => $this->styleDeclarations($node, $type, $parentNode, $grandParentNode),
            fn (array $node, string $type, ?array $parentNode): mixed => $this->supportedVectorSvg($node, $type, $parentNode),
            fn (array $child, array $parent): bool => $this->isFullyClippedDecorativeChild($child, $parent),
            fn (array $node): bool => $this->isPaginationContainer($node),
            fn (string $value): string => $this->sanitizeAttribute($value),
            fn (string $value): string => $this->slug($value),
            fn (float $value): string => $this->number($value),
            null,
            $this->breakpointDimensionPolicy(),
        );
    }

    private function breakpointDimensionPolicy(): BreakpointDimensionPolicy
    {
        return $this->breakpointDimensionPolicy ??= new BreakpointDimensionPolicy(fn (float $value): string => $this->number($value));
    }

    private function childLayerCompositionResolver(): ChildLayerCompositionResolver
    {
        return $this->childLayerCompositionResolver ??= new ChildLayerCompositionResolver(
            fn (array $node): ?string => $this->nodeAssetPath($node),
            fn (float $value): string => $this->number($value),
        );
    }

    private function localBorderShellClusterResolver(): LocalBorderShellClusterResolver
    {
        return $this->localBorderShellClusterResolver ??= new LocalBorderShellClusterResolver();
    }

    private function layoutIntentClassifier(): LayoutIntentClassifier
    {
        return new LayoutIntentClassifier($this->assetsById);
    }

    private function layoutFrameRoleClassifier(): LayoutFrameRoleClassifier
    {
        return $this->layoutFrameRoleClassifier ??= new LayoutFrameRoleClassifier();
    }

    private function staticHtmlSemanticClassifier(): StaticHtmlSemanticClassifier
    {
        return $this->staticHtmlSemanticClassifier ??= new StaticHtmlSemanticClassifier(
            $this->layoutIntentClassifier(),
            array(
                'nodeList' => fn (array $node): array => $this->nodeList($node),
                'textContent' => fn (array $node, ?array $parentNode = null): string => $this->textContent($node, $parentNode),
                'textDescendantCount' => fn (array $node): int => $this->textDescendantCount($node),
                'subtreePlainText' => fn (array $node): string => $this->subtreePlainText($node),
                'nodePlainText' => fn (array $node): string => $this->nodePlainText($node),
                'boxValue' => fn (array $node, string $key): ?float => $this->boxValue($node, $key),
                'backgroundColor' => fn (array $node): ?string => $this->backgroundColor($node),
                'cornerRadius' => fn (array $node): float => $this->cornerRadius($node),
                'hasStrokePaint' => fn (array $node): bool => $this->hasStrokePaint($node),
                'nodeAssetPath' => fn (array $node): ?string => $this->nodeAssetPath($node),
                'subtreeHasRenderableVector' => fn (array $node): bool => $this->subtreeHasRenderableVector($node),
                'listItemIds' => fn (array $container): array => $this->listItemIds($container),
                'listLooksOrdered' => fn (array $container): bool => $this->listLooksOrdered($container),
                'headingLevel' => fn (array $node, string $lowerName, int $depth, ?array $parentNode = null): ?string => $this->headingLevel($node, $lowerName, $depth, $parentNode),
                'sanitizeAttribute' => fn (string $value): string => $this->sanitizeAttribute($value),
            )
        );
    }

    private StaticHtmlLinkState $linkState;

    /**
     * Page-relative typographic hierarchy: rounded font-size key => heading tag
     * (h1-h6). Populated per emitted page so the largest/boldest text becomes the
     * top heading and smaller sizes descend.
     *
     * @var array<string, string>
     */
    private array $headingLevels = array();

    /**
     * Per-page heading node id => stable DOM id, derived from heading text.
     *
     * @var array<string, string>
     */
    private array $headingAnchorIds = array();

    /**
     * Per-page normalized heading text => page-local hash href for TOC entries.
     *
     * @var array<string, string>
     */
    private array $tocHrefByText = array();

    private string $currentPagePath = 'index.html';

    private string $currentTemplateType = '';

    private string $currentTemplateSlug = '';

    /**
     * Memoized list-item id sets keyed by container node id, so list-container
     * (<ul>) and list-item (<li>) decisions stay consistent within a page.
     *
     * @var array<string, array<int, string>>
     */
    private array $listItemIdCache = array();

    /**
     * Per-page emitted form control name counts, used to keep generated names
     * unique without losing the first canonical name such as `s` or `email`.
     *
     * @var array<string, int>
     */
    private array $formControlNameCounts = array();

    /**
     * Tree depth at which a frame can read as a top-level <section> for the page
     * being emitted. When the page is a single wrapping frame, its bands sit one
     * level down (depth 1); when bands are emitted as sibling root nodes, they
     * sit at the root (depth 0). Set per emitted page; everything deeper than
     * this is nested structure and stays a <div>.
     */
    private int $sectionDepth = 0;

    /**
     * Source node id => emitted DOM metadata used to connect result JSON back to
     * the emitted HTML/CSS artifact.
     *
     * @var array<string, array{class: string, tag: string, page_path: string}>
     */
    private array $emittedNodeMetadata = array();

    /**
     * @var array<string, string>
     */
    private array $suppressedVisualNodeIds = array();

    /**
     * Stable reason traces for behavior decisions that suppress, re-route, or
     * normalize source nodes without changing the emitted artifact contract.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $decisionTraces = array();

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emit(array $scenegraph, array $options = array()): array
    {
        $this->renderTextGlyphPaths = true === ($options['render_text_glyph_paths'] ?? false);
        $this->usedAssetPaths = array();
        $this->generatedAssetFiles = array();
        $this->generatedVectorSvgPathsByHash = array();
        $this->inlineVectorSvgBytes = 0;
        $this->staticHtmlCssRuleSet()->resetReadableNames();
        $this->emittedNodeMetadata = array();
        $this->suppressedVisualNodeIds = array();
        $this->decisionTraces = array();
        $this->archiveAssetContentResolver = is_callable($options['archive_asset_content_resolver'] ?? null) ? $options['archive_asset_content_resolver'] : null;
        $this->breakpointMediaDiffBuilder()->resetDecisionTraces();
        $this->stickyLayoutCoordinator()->reset();
        $this->linkState->resetForSinglePage($this->normalizeLinkTargetPaths($options));
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = $this->nodeList($scenegraph);
        $pagePath = (string) ($options['static_site_page_path'] ?? 'index.html');
        $this->currentTemplateType = is_scalar($options['static_site_template_type'] ?? null) ? (string) $options['static_site_template_type'] : '';
        $this->currentTemplateSlug = is_scalar($options['static_site_template_slug'] ?? null) ? (string) $options['static_site_template_slug'] : $this->templateSlugFromPath($pagePath);
        $this->stickyLayoutCoordinator()->detectStickyGhostCandidates($nodes);
        $this->listItemIdCache = array();
        $this->formControlNameCounts = array();
        $this->prepareHeadingRanking($nodes);
        $this->prepareHeadingAnchors($nodes, $pagePath);
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);
        $this->cssPositioningResolver = null;

        $this->sectionDepth = $this->sectionDepthFor($nodes);

        $body = '';
        $cssRules = $this->htmlArtifactAssembler()->baseCssRules($this->renderTextGlyphPaths);
        $operatorFontCss = $this->fontCss($options);
        $familyOverrides = $this->fontFamilyOverrides($options);
        $designSystem = $this->designSystemExtractor()->extract($scenegraph);
        $this->typographyTokenVars = is_array($designSystem['type_token_map'] ?? null) ? $designSystem['type_token_map'] : array();

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
        }

        $assetFiles = array_merge($this->referencedAssetFiles($assetFiles), array_values($this->generatedAssetFiles));

        $shared   = $this->staticHtmlCssRuleSet()->applySharedStyleClasses($cssRules);
        $cssRules = $shared['rules'];
        $body     = $this->staticHtmlCssRuleSet()->applySharedClassMapToHtml($body, $shared['class_map']);

        $mediaBlocks = $this->desktopOnlyFallbackMediaBlocks($scenegraph, $nodes);
        $cssWithoutFontCss = $this->htmlArtifactAssembler()->stylesheet('', (string) $designSystem['css'], $cssRules, $mediaBlocks);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics, $cssWithoutFontCss, $body);
        $fontFamilies = array_column($fontUsage, 'family');
        $fontResolution = $this->fontResolver()->resolve($fontUsage, $operatorFontCss, $familyOverrides);
        $fontCss = (string) $fontResolution['css'];

        foreach ( $this->designSystemDiagnostics($designSystem) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        $css = $this->htmlArtifactAssembler()->stylesheet($fontCss, (string) $designSystem['css'], $cssRules, $mediaBlocks);
        $files = array(
            array(
                'path'      => 'index.html',
                'role'      => 'entrypoint',
                'mime_type' => 'text/html',
                'content'   => $this->htmlArtifactAssembler()->htmlDocument($title, 'style.css', $body, $this->headMetadata($options, $pagePath, html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $this->currentTemplateType, $this->currentTemplateSlug)),
            ),
            array(
                'path'      => 'style.css',
                'role'      => 'stylesheet',
                'mime_type' => 'text/css',
                'content'   => $css,
            ),
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        if ( false !== ($options['inline_css'] ?? true) ) {
            $files = (new InlineCssFileInjector())->inject($files, $css);
        }

        $visualNodeMap = $this->visualNodeMap($nodes);
        $transformDiagnostics = $this->transformDiagnostics(
            $nodes,
            $visualNodeMap,
            $assetFiles,
            $fontFamilies,
            $fontUsage,
            $fontResolution,
            $css,
            array_merge(is_array($scenegraph['diagnostics'] ?? null) ? $scenegraph['diagnostics'] : array(), $diagnostics),
            $body,
            is_array($options['source_loss_evidence'] ?? null) ? $options['source_loss_evidence'] : array()
        );
        foreach ( $this->unresolvedSourceFontDiagnostics($fontResolution) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'                         => $title,
                'node_count'                   => $this->countNodes($nodes),
                'schema'                       => $scenegraph['schema'] ?? null,
                'node_style_diagnostic_count'  => count($nodeStyleDiagnostics),
                'node_style_mismatch_count'    => $this->countNodeStyleMismatches($nodeStyleDiagnostics),
                'node_style_diagnostics'       => $nodeStyleDiagnostics,
                'visual_node_count'            => count($visualNodeMap),
                'visual_node_map'              => $visualNodeMap,
                'font_families'                => $fontFamilies,
                'font_usage'                   => $fontUsage,
                'font_css_supplied'            => (bool) $fontResolution['operator_supplied'],
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
                'design_system'                => array(
                    'coverage'                  => $designSystem['coverage'],
                    'frame_names'               => $designSystem['frame_names'],
                    'type_token_map'            => $designSystem['type_token_map'] ?? array(),
                    'materialized_node_classes' => $designSystem['materialized_node_classes'] ?? array(),
                ),
                'transform_diagnostics'        => $transformDiagnostics,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($nodes),
                'asset_count' => count($assetFiles),
            ),
        );
    }

    /**
     * @param array<string, mixed> $scenegraph
     * @param array<int, mixed> $nodes
     * @return array<int, string>
     */
    private function desktopOnlyFallbackMediaBlocks(array $scenegraph, array $nodes): array
    {
        $blocks = array();
        $nodeMap = $this->nodeMap($scenegraph);
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $viewportWidth = $this->boxValue($node, 'width');
            $blocks = array_merge($blocks, $this->breakpointMediaDiffBuilder()->buildMediaBlocks(array(
                'variants' => array(
                    array(
                        'frame_id'       => is_scalar($node['id'] ?? null) ? (string) $node['id'] : '',
                        'viewport_width' => $viewportWidth,
                        'primary'        => true,
                    ),
                ),
            ), $node, $nodeMap));
        }

        return array_values(array_unique($blocks));
    }

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $pagePlan Planned pages with frame_id, name, path, and entrypoint.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emitSite(array $scenegraph, array $pagePlan, array $options = array()): array
    {
        $this->renderTextGlyphPaths = true === ($options['render_text_glyph_paths'] ?? false);
        $this->usedAssetPaths = array();
        $this->generatedAssetFiles = array();
        $this->generatedVectorSvgPathsByHash = array();
        $this->inlineVectorSvgBytes = 0;
        $this->staticHtmlCssRuleSet()->resetReadableNames();
        $this->emittedNodeMetadata = array();
        $this->suppressedVisualNodeIds = array();
        $this->decisionTraces = array();
        $this->archiveAssetContentResolver = is_callable($options['archive_asset_content_resolver'] ?? null) ? $options['archive_asset_content_resolver'] : null;
        $this->breakpointMediaDiffBuilder()->resetDecisionTraces();
        $this->stickyLayoutCoordinator()->reset();
        $implicitRoutePagePlan = is_array($options['implicit_route_page_plan'] ?? null) ? $options['implicit_route_page_plan'] : $pagePlan;
        $implicitRouteData = $this->implicitRouteDataFromPagePlan($implicitRoutePagePlan, $scenegraph);
        $this->linkState->resetForSite(
            $this->linkTargetPathsFromPagePlan($pagePlan, $options),
            $this->entrypointPathFromPagePlan($implicitRoutePagePlan),
            $implicitRouteData['paths'],
            $implicitRouteData['targets']
        );
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $diagnostics = array();
        $nodeStyleDiagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);
        $nodeMap = $this->nodeMap($scenegraph);

        $cssRules = $this->htmlArtifactAssembler()->baseCssRules($this->renderTextGlyphPaths);
        $operatorFontCss = $this->fontCss($options);
        $familyOverrides = $this->fontFamilyOverrides($options);
        $designSystem = $this->designSystemExtractor()->extract($scenegraph);
        $this->typographyTokenVars = is_array($designSystem['type_token_map'] ?? null) ? $designSystem['type_token_map'] : array();
        $files = array();
        $pages = array();
        $renderedNodes = array();
        $seenPaths = array();
        $mediaBlocks = array();
        $plannedPages = $this->plannedPages($pagePlan);

        $stickyDetectionRoots = array();
        foreach ( $plannedPages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameIds = array();
            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' !== $frameId ) {
                $frameIds[] = $frameId;
            }
            foreach ( is_array($page['variants'] ?? null) ? $page['variants'] : array() as $variant ) {
                if ( is_array($variant) && isset($variant['frame_id']) && is_scalar($variant['frame_id']) ) {
                    $frameIds[] = (string) $variant['frame_id'];
                }
            }

            foreach ( array_values(array_unique($frameIds)) as $candidateFrameId ) {
                if ( isset($nodeMap[$candidateFrameId]) && is_array($nodeMap[$candidateFrameId]) ) {
                    $stickyDetectionRoots[] = $nodeMap[$candidateFrameId];
                }
            }
        }
        $this->stickyLayoutCoordinator()->detectStickyGhostCandidates($stickyDetectionRoots);

        foreach ( $plannedPages as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = (string) ($page['frame_id'] ?? '');
            $frameNode = '' !== $frameId && isset($nodeMap[$frameId]) ? $nodeMap[$frameId] : null;
            if ( null === $frameNode ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'planned_page_frame_missing',
                    'message'  => 'Planned page frame was not found in the scenegraph.',
                    'frame_id' => $frameId,
                );
                continue;
            }

            $pageName = (string) ($page['name'] ?? $frameNode['name'] ?? 'Page');
            $path = $this->pagePath($page, $pageName, $index);
            if ( isset($seenPaths[$path]) ) {
                $diagnostics[] = array(
                    'severity' => 'warning',
                    'code'     => 'duplicate_page_path_omitted',
                    'message'  => 'Planned page path duplicates an earlier page and was omitted.',
                    'path'     => $path,
                    'frame_id' => $frameId,
                );
                continue;
            }
            $seenPaths[$path] = true;

            $this->listItemIdCache = array();
            $this->formControlNameCounts = array();
            $this->currentTemplateType = is_scalar($page['page_type'] ?? null) ? (string) $page['page_type'] : '';
            $this->currentTemplateSlug = is_scalar($page['slug'] ?? null) ? (string) $page['slug'] : $this->templateSlugFromPath($path);
            $this->prepareHeadingRanking(array($frameNode));
            $this->prepareHeadingAnchors(array($frameNode), $path);
            // A planned page is a single wrapping frame; its bands are its
            // direct children one level down.
            $this->sectionDepth = 1;
            $this->inlineVectorSvgBytes = 0;
            $body = $this->emitNode($frameNode, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
            $pageHtml = $this->htmlArtifactAssembler()->htmlDocument($this->sanitizeText($pageName), $this->stylesheetHref($path), $body, $this->headMetadata($options, $path, $pageName, $this->currentTemplateType, $this->currentTemplateSlug));
            $files[] = array(
                'path'      => $path,
                'role'      => true === ($page['entrypoint'] ?? false) ? 'entrypoint' : 'document',
                'mime_type' => 'text/html',
                'content'   => $pageHtml,
            );
            $canonicalTemplatePath = $this->canonicalTemplatePath($this->currentTemplateType);
            $templateAliases = array();
            if ( '' !== $canonicalTemplatePath && $canonicalTemplatePath !== $path && ! isset($seenPaths[$canonicalTemplatePath]) ) {
                $templateAliases[] = $canonicalTemplatePath;
                $seenPaths[$canonicalTemplatePath] = true;
                $files[] = array(
                    'path'      => $canonicalTemplatePath,
                    'role'      => 'template-alias',
                    'mime_type' => 'text/html',
                    'content'   => $this->htmlArtifactAssembler()->htmlDocument($this->sanitizeText($pageName), $this->stylesheetHref($canonicalTemplatePath), $body, $this->headMetadata($options, $canonicalTemplatePath, $pageName, $this->currentTemplateType, $this->templateSlugFromPath($canonicalTemplatePath))),
                );
            }
            $renderedNodes[] = $frameNode;

            foreach ( $this->breakpointMediaDiffBuilder()->buildMediaBlocks($page, $frameNode, $nodeMap) as $mediaBlock ) {
                $mediaBlocks[] = $mediaBlock;
            }

            $pages[] = array(
                'frame_id'   => $frameId,
                'name'       => $pageName,
                'path'       => $path,
                'entrypoint' => true === ($page['entrypoint'] ?? false),
                'page_type'  => $this->currentTemplateType,
                'slug'       => $this->currentTemplateSlug,
                'canonical_template_path' => '' !== $canonicalTemplatePath ? $canonicalTemplatePath : null,
                'template_aliases' => $templateAliases,
                'node_count' => $this->countNodes(array($frameNode)),
            );
        }

        if ( empty($files) ) {
            $this->currentPagePath = 'index.html';
            $this->currentTemplateType = '';
            $this->currentTemplateSlug = 'index';
            $this->formControlNameCounts = array();
            $fallbackNodes = $this->nodeList($scenegraph);
            $this->prepareHeadingRanking($fallbackNodes);
            $this->prepareHeadingAnchors($fallbackNodes, 'index.html');
            $this->sectionDepth = $this->sectionDepthFor($fallbackNodes);
            $this->inlineVectorSvgBytes = 0;
            foreach ( $fallbackNodes as $node ) {
                if ( ! is_array($node) ) {
                    continue;
                }
                $body = $this->emitNode($node, $cssRules, $diagnostics, $nodeStyleDiagnostics, 0, null);
                $files[] = array(
                    'path'      => 'index.html',
                    'role'      => 'entrypoint',
                    'mime_type' => 'text/html',
                    'content'   => $this->htmlArtifactAssembler()->htmlDocument($title, 'style.css', $body, $this->headMetadata($options, 'index.html', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $this->currentTemplateType, $this->currentTemplateSlug)),
                );
                $renderedNodes[] = $node;
            }
        }

        $assetFiles = array_merge($this->referencedAssetFiles($assetFiles), array_values($this->generatedAssetFiles));

        $shared   = $this->staticHtmlCssRuleSet()->applySharedStyleClasses($cssRules, true);
        $cssRules = $shared['rules'];
        if ( ! empty($shared['class_map']) ) {
            foreach ( $files as $fileIndex => $file ) {
                if ( 'text/html' === ($file['mime_type'] ?? '') && isset($file['content']) ) {
                    $files[$fileIndex]['content'] = $this->staticHtmlCssRuleSet()->applySharedClassMapToHtml((string) $file['content'], $shared['class_map']);
                }
            }
        }

        $htmlForFontUsage = $this->htmlArtifactAssembler()->htmlFilesContent($files);
        $cssWithoutFontCss = $this->htmlArtifactAssembler()->stylesheet('', (string) $designSystem['css'], $cssRules, $mediaBlocks, true);
        $fontUsage = $this->fontUsage($nodeStyleDiagnostics, $cssWithoutFontCss, $htmlForFontUsage);
        $fontFamilies = array_column($fontUsage, 'family');
        $fontResolution = $this->fontResolver()->resolve($fontUsage, $operatorFontCss, $familyOverrides);
        $fontCss = (string) $fontResolution['css'];
        foreach ( $this->designSystemDiagnostics($designSystem) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }
        $css = $this->htmlArtifactAssembler()->stylesheet($fontCss, (string) $designSystem['css'], $cssRules, $mediaBlocks, true);
        $files[] = array(
            'path'      => 'style.css',
            'role'      => 'stylesheet',
            'mime_type' => 'text/css',
            'content'   => $css,
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        if ( true === ($options['inline_css'] ?? false) ) {
            $files = (new InlineCssFileInjector())->inject($files, $css);
        }

        $visualNodeMap = $this->visualNodeMap($renderedNodes);
        $transformDiagnostics = $this->transformDiagnostics(
            $renderedNodes,
            $visualNodeMap,
            $assetFiles,
            $fontFamilies,
            $fontUsage,
            $fontResolution,
            $css,
            array_merge(is_array($scenegraph['diagnostics'] ?? null) ? $scenegraph['diagnostics'] : array(), $diagnostics),
            $this->htmlArtifactAssembler()->htmlFilesContent($files),
            is_array($options['source_loss_evidence'] ?? null) ? $options['source_loss_evidence'] : array()
        );
        foreach ( $this->unresolvedSourceFontDiagnostics($fontResolution) as $diagnostic ) {
            $diagnostics[] = $diagnostic;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'                         => $title,
                'node_count'                   => $this->countNodes($renderedNodes),
                'schema'                       => $scenegraph['schema'] ?? null,
                'pages'                        => $pages,
                'node_style_diagnostic_count'  => count($nodeStyleDiagnostics),
                'node_style_mismatch_count'    => $this->countNodeStyleMismatches($nodeStyleDiagnostics),
                'node_style_diagnostics'       => $nodeStyleDiagnostics,
                'visual_node_count'            => count($visualNodeMap),
                'visual_node_map'              => $visualNodeMap,
                'font_families'                => $fontFamilies,
                'font_usage'                   => $fontUsage,
                'font_css_supplied'            => (bool) $fontResolution['operator_supplied'],
                'render_text_glyph_paths'      => $this->renderTextGlyphPaths,
                'design_system'                => array(
                    'coverage'                  => $designSystem['coverage'],
                    'frame_names'               => $designSystem['frame_names'],
                    'type_token_map'            => $designSystem['type_token_map'] ?? array(),
                    'materialized_node_classes' => $designSystem['materialized_node_classes'] ?? array(),
                ),
                'transform_diagnostics'        => $transformDiagnostics,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($renderedNodes),
                'asset_count' => count($assetFiles),
                'page_count'  => count($pages),
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>                 $cssRules
     * @param array<int, array<string, mixed>>   $diagnostics
     */
    private function emitNode(array $node, array &$cssRules, array &$diagnostics, array &$nodeStyleDiagnostics, int $depth, ?array $parentNode, ?array $grandParentNode = null, bool $insideForm = false, bool $insideLink = false): string
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) ) {
            $this->recordDecisionTrace('layout_suppression', 'sticky_ghost_suppressed', $node, 'skip_node', $parentNode, array('depth' => $depth));
            return '';
        }

        // Designer-hidden layers carry an explicit `visible: false` from Figma.
        // Skip emitting them and their entire subtree. Absent/null `visible`
        // means visible, so only an explicit false is honored. A hidden node
        // emitted as a top-level render root (depth 0, e.g. an explicitly
        // selected frame) still renders; hidden descendants never do.
        if ( $depth > 0 && false === ($node['visible'] ?? null) ) {
            $this->recordDecisionTrace('layout_suppression', 'hidden_descendant_suppressed', $node, 'skip_node', $parentNode, array('depth' => $depth));
            return '';
        }

        if ( $depth > 0 && $this->isMaskOperatorNode($node) ) {
            $this->recordDecisionTrace('layout_suppression', 'mask_source_suppressed', $node, 'skip_node', $parentNode, array('depth' => $depth));
            return '';
        }

        if ( $depth > 0 && $this->isNonRenderingVectorLayer($node) ) {
            $this->recordDecisionTrace('vector_scaffold', 'non_rendering_vector_layer_suppressed', $node, 'skip_node', $parentNode, array('depth' => $depth));
            return '';
        }

        if ( $depth > 0 && $this->isInvisibleZeroAreaScaffold($node) ) {
            $scaffoldId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $scaffoldId ) {
                $this->suppressedVisualNodeIds[$scaffoldId] = 'invisible-zero-area-scaffold';
            }
            $this->recordDecisionTrace('layout_suppression', 'invisible_zero_area_scaffold_suppressed', $node, 'skip_node', $parentNode, array('depth' => $depth));
            return '';
        }

        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $attributeName = $this->sanitizeAttribute($name);
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        if ( 'TEXT' === $type ) {
            $text = $this->textGlyphSvg($node);
            if ( null === $text ) {
                // Multi-paragraph text splits into per-paragraph boxes so
                // `paragraphSpacing` lands as a margin; otherwise render the node
                // as a single element.
                $text = $this->multiParagraphTextContent($node) ?? $this->packedNavigationTextContent($node, $parentNode) ?? $this->textContent($node, $parentNode);
            }
        } else {
            $text = $this->textContent($node, $parentNode);
        }
        $tag = $this->semanticTag($node, $type, $name, $depth, $parentNode, $grandParentNode);
        $sourceTextList = 'TEXT' === $type ? $this->sourceTextListMarkup($node) : null;
        if ( null !== $sourceTextList ) {
            $tag = $sourceTextList['tag'];
            $text = $sourceTextList['content'];
        }
        if ( $insideForm && 'form' === $tag ) {
            $tag = 'div';
        }
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        if ( '' !== $id ) {
            $this->emittedNodeMetadata[$id] = array(
                'class'     => $className,
                'tag'       => $tag,
                'page_path' => $this->currentPagePath,
            );
        }
        $children = $this->childrenInEmissionOrder($node);
        $imageElement = $this->imageElementMetadata($node, $type, $children);
        if ( null !== $imageElement ) {
            $tag = 'img';
        }
        $content = $text;
        $nodeIntroducesLink = $this->nodeIntroducesLinkContext($node, $parentNode, $insideLink);
        $inputAccessoryControl = 'div' === $tag && $this->isInputLike($node) && $this->hasFormControlAccessoryChildren($node);
        $textareaAccessoryControl = 'div' === $tag && $this->isTextareaLike($node) && $this->hasFormControlAccessoryChildren($node);
        $formControlAccessoryControl = $inputAccessoryControl || $textareaAccessoryControl;
        $assetComposition = $this->nodeAssetComposition($node, $type, $parentNode);
        $vectorSvg = $assetComposition['vector_svg'];
        $hasVectorAssetFallback = $assetComposition['has_vector_asset_fallback'];
        $buttonLayerComposition = 'button' === $tag ? $this->buttonLayerComposition($node, $children) : array('styles' => array(), 'suppressed_child_ids' => array());

        if ( ! in_array($tag, array('input', 'textarea'), true) && ! ( 'BOOLEAN_OPERATION' === $type && null !== $vectorSvg ) && ! $this->vectorSvgComposesChildren($vectorSvg) ) {
            $insertedAccessoryInput = false;
            $childCompositionMaps = $this->childAssetCompositionMaps($children);
            if ( ! empty($buttonLayerComposition['suppressed_child_ids']) ) {
                $childCompositionMaps['suppressed_child_ids'] = array_merge($childCompositionMaps['suppressed_child_ids'], $buttonLayerComposition['suppressed_child_ids']);
            }
            $suppressRootOffCanvasChildren = 0 === $depth && $this->hasRootOffCanvasChildCluster($children, $node);
            $localClusters = $this->localBorderShellClusters($node, $children);
            $localClusterByFirstChildId = $localClusters['by_first_child_id'];
            $localClusterMemberIds = $localClusters['member_ids'];
            foreach ( $children as $child ) {
                if ( is_array($child) ) {
                    if ( $this->isMaskOperatorNode($child) ) {
                        $this->recordDecisionTrace('layout_suppression', 'mask_source_suppressed', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    $childId = isset($child['id']) && is_scalar($child['id']) ? (string) $child['id'] : '';
                    if ( '' !== $childId && isset($childCompositionMaps['suppressed_child_ids'][$childId]) ) {
                        $reason = $childCompositionMaps['suppressed_child_ids'][$childId];
                        $this->suppressedVisualNodeIds[$childId] = $reason;
                        $this->recordDecisionTrace('layout_suppression', $reason, $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    if ( '' !== $childId && isset($localClusterByFirstChildId[$childId]) ) {
                        $content .= $this->emitNode($localClusterByFirstChildId[$childId], $cssRules, $diagnostics, $nodeStyleDiagnostics, $depth + 1, $node, $parentNode, $insideForm || 'form' === $tag, $insideLink || $nodeIntroducesLink);
                        continue;
                    }
                    if ( '' !== $childId && isset($localClusterMemberIds[$childId]) ) {
                        continue;
                    }
                    $child = $this->applyChildAssetComposition($child, $childId, $childCompositionMaps);
                    if ( $this->isFullyClippedDecorativeChild($child, $node) ) {
                        $this->recordDecisionTrace('layout_suppression', 'fully_clipped_decorative_child_suppressed', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    if ( $suppressRootOffCanvasChildren && $this->isFullyOffCanvasRootChild($child, $node) ) {
                        if ( '' !== $childId ) {
                            $this->suppressedVisualNodeIds[$childId] = 'root_off_canvas_child_suppressed';
                        }
                        $this->recordDecisionTrace('layout_suppression', 'root_off_canvas_child_suppressed', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    if ( 'form' === $tag && $this->isSpatialFormControlLabel($child, $node) ) {
                        $this->recordDecisionTrace('source_loss_accounting', 'spatial_label_converted_to_form_control', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    if ( $formControlAccessoryControl && $this->isFormControlPlaceholderChild($child) ) {
                        $this->recordDecisionTrace('source_loss_accounting', 'placeholder_child_converted_to_form_control', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        if ( ! $insertedAccessoryInput ) {
                            $content .= $this->syntheticFormControlMarkup($node, $className, $textareaAccessoryControl ? 'textarea' : 'input', $parentNode);
                            $cssRules[] = $this->syntheticFormControlResetCss($className, $textareaAccessoryControl ? 'textarea' : 'input');
                            $insertedAccessoryInput = true;
                        }
                        continue;
                    }
                    if ( $formControlAccessoryControl && ( $this->isInputLike($child, $node) || $this->isTextareaLike($child, $node) ) ) {
                        $this->recordDecisionTrace('source_loss_accounting', 'nested_form_control_chrome_converted_to_parent_control', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    if ( 'li' === $tag && $this->isListMarkerTextChild($child) ) {
                        $this->recordDecisionTrace('source_loss_accounting', 'list_marker_text_suppressed', $child, 'skip_child', $node, array('depth' => $depth + 1));
                        continue;
                    }
                    $content .= $this->emitNode($child, $cssRules, $diagnostics, $nodeStyleDiagnostics, $depth + 1, $node, $parentNode, $insideForm || 'form' === $tag, $insideLink || $nodeIntroducesLink);
                }
            }
            if ( $formControlAccessoryControl && ! $insertedAccessoryInput ) {
                $content .= $this->syntheticFormControlMarkup($node, $className, $textareaAccessoryControl ? 'textarea' : 'input', $parentNode);
                $cssRules[] = $this->syntheticFormControlResetCss($className, $textareaAccessoryControl ? 'textarea' : 'input');
            }
        }

        $vectorSvgMarkup = null;
        if ( null !== $vectorSvg ) {
            $vectorSvgMarkup = $this->vectorSvgMarkup($vectorSvg, $node, $type, $parentNode);
            if ( '' !== trim($vectorSvgMarkup) ) {
                $content = $vectorSvgMarkup . $content;
            }
        }

        $hasRenderableVectorFallback = '' !== trim($content);
        if ( $this->shouldSuppressNonRenderableUnsupportedVectorPlaceholder($node, $type, $vectorSvg, $hasVectorAssetFallback, $hasRenderableVectorFallback) ) {
            if ( '' !== $id ) {
                $this->suppressedVisualNodeIds[$id] = 'non_renderable_unsupported_vector_suppressed';
            }
            $this->recordDecisionTrace('vector_scaffold', 'non_renderable_unsupported_vector_suppressed', $node, 'skip_node', $parentNode, array('reason' => 'zero_area_without_vector_source'));

            return '';
        }
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback && ! $hasRenderableVectorFallback ) {
            $diagnostics[] = array(
                'severity' => 'warning',
                'code'     => 'unsupported_vector_node_placeholder',
                'reason_code' => 'unsupported_vector_node_placeholder',
                'message'  => 'Unsupported vector-like Figma node emitted as a static placeholder.',
            ) + $this->vectorPlaceholderDiagnostic($node, $type, $parentNode);
            $this->recordDecisionTrace('vector_scaffold', 'unsupported_vector_node_placeholder', $node, 'emit_placeholder', $parentNode, $this->vectorPlaceholderDiagnostic($node, $type, $parentNode));

            $content = '';
        }

        $rendersInlineVectorSvg = null !== $vectorSvgMarkup && '' !== trim($vectorSvgMarkup);
        $styles = $this->styleDeclarations($node, $type, $parentNode, $grandParentNode, $rendersInlineVectorSvg);
        if ( ! empty($buttonLayerComposition['styles']) ) {
            array_push($styles, ...$buttonLayerComposition['styles']);
        }
        $styles = $this->stickyLayoutCoordinator()->stickyAwareStyleDeclarations($node, $styles);
        if ( 'p' === $tag && $this->hasBodyTextNameIntent(strtolower($name)) && ! $this->hasExplicitUppercaseTextCase($node) ) {
            $styles = array_values(array_filter($styles, static fn (string $style): bool => 'text-transform:uppercase' !== $style));
        }
        if ( ! empty($styles) ) {
            $cssRules[] = '.' . $className . '{' . implode(';', $styles) . '}';
            foreach ( $this->negativeAutoLayoutSpacingRules($className, $node) as $rule ) {
                $cssRules[] = $rule;
            }
            $this->staticHtmlCssRuleSet()->rememberNodeReadableName($className, $name, $type);
        }
        if ( $this->isSemanticListItemBodyText($node, $parentNode, $grandParentNode) && $this->textContainsLowercase($this->rawDecodedText($node)) && ! $this->hasExplicitUppercaseTextCase($node) ) {
            $parentClassName = 'figma-node-' . $this->slug((string) ($parentNode['id'] ?? '') . '-' . (string) ($parentNode['name'] ?? 'Node'));
            $cssRules[] = '.' . $parentClassName . '>.' . $className . '{text-transform:none}';
        } elseif ( 'p' === $tag && $this->hasBodyTextNameIntent(strtolower($name)) && ! $this->hasExplicitUppercaseTextCase($node) ) {
            $cssRules[] = 'ol .' . $className . ',ul .' . $className . '{text-transform:none}';
        }
        if ( in_array($tag, array('ol', 'ul'), true) && $this->listShouldRenderMarkers($node, null !== $sourceTextList) && ! $this->isChromeListContext($node, $parentNode, $grandParentNode) ) {
            $cssRules[] = '.' . $className . '{list-style:' . ( 'ol' === $tag ? 'decimal' : 'disc' ) . ';padding-left:1.5em' . ( 'ol' === $tag ? ';counter-reset:figma-list-item' : '' ) . '}';
        }
        if ( 'li' === $tag && null !== $parentNode && $this->isListItemOf($node, $parentNode) && $this->listShouldRenderMarkers($parentNode, false) && ! $this->isChromeListContext($node, $parentNode, $grandParentNode) ) {
            $marker = $this->listLooksOrdered($parentNode) ? 'counter(figma-list-item) ". "' : '"\2022"';
            $cssRules[] = '.' . $className . '{position:relative}';
            $cssRules[] = '.' . $className . '::before{content:' . $marker . ';counter-increment:figma-list-item;display:inline-block;min-width:1.5em;margin-left:-1.5em;flex-shrink:0}';
        }
        $nodeStyleDiagnostics[] = $this->nodeStyleDiagnostic($node, $type, $className, $tag, $styles, $parentNode, $rendersInlineVectorSvg);

        if ( 'TEXT' === $type ) {
            $paragraphSpacingDiagnostic = $this->paragraphSpacingDiagnostic($node);
            if ( null !== $paragraphSpacingDiagnostic ) {
                $diagnostics[] = $paragraphSpacingDiagnostic;
            }
        }

        $layoutIntent = $this->layoutIntentClassifier()->layoutIntent($node, $parentNode);
        $elementClassName = null === $imageElement ? $className : $className . ' figma-image-asset';
        $attributes = sprintf(' class="%1$s" data-figma-node-id="%2$s" data-figma-node-name="%3$s"', $elementClassName, $id, $attributeName);
        $attributes .= ' data-source-node-type="' . $this->sanitizeAttribute($type) . '"';
        $sourceVisualBox = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( ! isset($sourceVisualBox[$dimension]) && isset($node[$dimension]) && is_numeric($node[$dimension]) ) {
                $sourceVisualBox[$dimension] = $node[$dimension];
            }
        }
        if ( isset($sourceVisualBox['width'], $sourceVisualBox['height']) && is_numeric($sourceVisualBox['width']) && is_numeric($sourceVisualBox['height']) ) {
            $attributes .= ' data-source-visual-width="' . $this->sanitizeAttribute((string) $sourceVisualBox['width']) . '"';
            $attributes .= ' data-source-visual-height="' . $this->sanitizeAttribute((string) $sourceVisualBox['height']) . '"';
        }
        $semanticRole = $this->semanticRoleMetadata($node, $tag, $type, $name);
        if ( null !== $semanticRole ) {
            $attributes .= ' data-figma-semantic-role="' . $this->sanitizeAttribute($semanticRole) . '"';
        }
        if ( is_array($layoutIntent) && ($this->layoutIntentShouldEmitClass($layoutIntent) || ($this->freeformContainerShouldUseFlow($node) && ! $this->nodeWillPositionAbsolute($node, $parentNode))) ) {
            $attributes .= $this->layoutIntentAttributes($layoutIntent);
        }
        $anchorId = $this->headingAnchorId($node, $tag);
        if ( null !== $anchorId ) {
            $attributes .= ' id="' . $this->sanitizeAttribute($anchorId) . '"';
        }
        if ( null !== $imageElement ) {
            $attributes .= $this->imageElementAttributes($imageElement);
        }
        $semanticHintAttributes = $this->semanticHintAttributes($node, $tag, $parentNode, $grandParentNode);
        if ( '' !== $semanticHintAttributes ) {
            $attributes .= $semanticHintAttributes;
        }
        if ( in_array($tag, array('input', 'textarea'), true) ) {
            $attributes .= $this->formControlAttributes($node, $tag, $parentNode);
        } elseif ( 'ol' === $tag && null !== $sourceTextList && isset($sourceTextList['start']) ) {
            $attributes .= ' start="' . $this->sanitizeAttribute((string) $sourceTextList['start']) . '"';
        } elseif ( 'button' === $tag ) {
            $attributes .= $this->buttonControlAttributes($node, $insideForm);
        } elseif ( 'form' === $tag ) {
            $attributes .= $this->formAttributes($node);
        }
        if ( 'RECTANGLE' === $type && '' === $content && null === $imageElement ) {
            $attributes .= ' aria-hidden="true"';
        }
        $semanticArea = $this->semanticArea($tag, $node, $parentNode);
        if ( '' !== $semanticArea ) {
            $attributes .= ' data-template-area="' . $this->sanitizeAttribute($semanticArea) . '"';
        }
        if ( 0 === $depth && '' !== $this->currentTemplateType ) {
            $attributes .= ' data-template-type="' . $this->sanitizeAttribute($this->currentTemplateType) . '"';
        }
        if ( $this->isUnsupportedVectorType($type) && null === $vectorSvg && ! $hasVectorAssetFallback && ! $hasRenderableVectorFallback ) {
            $attributes .= ' data-figma-unsupported-vector="true" aria-hidden="true"';
        } elseif ( $hasVectorAssetFallback ) {
            $attributes .= ' role="img" aria-label="' . $this->sanitizeAttribute('' !== $name ? $name : $type) . '"';
        }

        if ( 'img' === $tag ) {
            $element = sprintf("<img%1\$s>\n", $attributes);
        } elseif ( 'input' === $tag ) {
            $element = sprintf("<input%1\$s>\n", $attributes);
        } else {
            $element = sprintf("<%1\$s%2\$s>%3\$s</%1\$s>\n", $tag, $attributes, $content);
        }

        return $this->wrapComposedElementWithLink($node, $element, $diagnostics, $parentNode, $insideLink);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{asset_path: string|null, vector_svg: string|null, has_vector_asset_fallback: bool}
     */
    private function nodeAssetComposition(array $node, string $type, ?array $parentNode): array
    {
        $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
        $assetPath = $this->nodeAssetPath($node);
        if ( null !== $assetPath || $this->nodeHasCssMaskImage($node) ) {
            $vectorSvg = null;
        }

        return array(
            'asset_path' => $assetPath,
            'vector_svg' => $vectorSvg,
            'has_vector_asset_fallback' => $this->isUnsupportedVectorType($type) && null !== $assetPath,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, mixed>    $children
     * @return array{src: string, alt: string, scale_mode: string, background_size: string|null, background_position: string|null, object_fit: string, object_position: string, crop_rect: array<string, mixed>|null}|null
     */
    private function imageElementMetadata(array $node, string $type, array $children): ?array
    {
        if ( ! in_array($type, array('RECTANGLE', 'ROUNDED_RECTANGLE', 'FRAME', 'GROUP', 'INSTANCE'), true) ) {
            return null;
        }
        if ( ! empty(array_filter($children, 'is_array')) || '' !== trim($this->textContent($node)) || $this->nodeHasCssMaskImage($node) ) {
            return null;
        }

        $layers = $this->nodeImagePaintLayers($node);
        $assetPath = null;
        $paint = array();
        if ( ! empty($layers) ) {
            $assetPath = (string) ($layers[0]['path'] ?? '');
            $paint = is_array($layers[0]['paint'] ?? null) ? $layers[0]['paint'] : array();
        } else {
            $assetPath = $this->nodeAssetPath($node);
        }
        if ( null === $assetPath || '' === $assetPath ) {
            return null;
        }

        $scaleMode = empty($paint) ? $this->nodeImageScaleMode($node) : $this->imagePaintScaleMode($paint);
        $backgroundStyles = empty($paint) ? array() : $this->imagePaintLayerBackgroundStyles($node, $paint, $scaleMode);

        return array(
            'src'                 => $assetPath,
            'alt'                 => $this->imageAltText($node, $paint),
            'scale_mode'          => $scaleMode,
            'background_size'     => isset($backgroundStyles['size']) ? (string) $backgroundStyles['size'] : null,
            'background_position' => isset($backgroundStyles['position']) ? (string) $backgroundStyles['position'] : null,
            'object_fit'          => $this->imageObjectFit($scaleMode),
            'object_position'     => $this->imageObjectPosition(isset($backgroundStyles['position']) ? (string) $backgroundStyles['position'] : null),
            'crop_rect'           => empty($paint) ? null : $this->imagePaintCropRect($paint),
        );
    }

    /** @param array{src: string, alt: string, scale_mode: string, background_size: string|null, background_position: string|null, object_fit: string, object_position: string, crop_rect: array<string, mixed>|null} $metadata */
    private function imageElementAttributes(array $metadata): string
    {
        $attributes = ' src="' . $this->sanitizeAttribute($metadata['src']) . '"';
        $attributes .= ' alt="' . $this->sanitizeAttribute($metadata['alt']) . '"';
        $attributes .= ' loading="lazy" decoding="async"';
        $attributes .= ' style="object-fit:' . $this->sanitizeAttribute($metadata['object_fit']) . ';object-position:' . $this->sanitizeAttribute($metadata['object_position']) . '"';
        $attributes .= ' data-figma-image-fill="true" data-figma-image-rendering="semantic-img" data-figma-image-scale-mode="' . $this->sanitizeAttribute($metadata['scale_mode']) . '"';
        $attributes .= ' data-figma-image-object-fit="' . $this->sanitizeAttribute($metadata['object_fit']) . '" data-figma-image-object-position="' . $this->sanitizeAttribute($metadata['object_position']) . '"';
        if ( null !== $metadata['background_size'] ) {
            $attributes .= ' data-figma-image-background-size="' . $this->sanitizeAttribute($metadata['background_size']) . '"';
        }
        if ( null !== $metadata['background_position'] ) {
            $attributes .= ' data-figma-image-background-position="' . $this->sanitizeAttribute($metadata['background_position']) . '"';
        }
        if ( is_array($metadata['crop_rect']) ) {
            $attributes .= ' data-figma-image-crop-rect="' . $this->sanitizeAttribute((string) json_encode($metadata['crop_rect'])) . '"';
        }

        return $attributes;
    }

    private function imageObjectFit(string $scaleMode): string
    {
        return match ( strtoupper($scaleMode) ) {
            'FIT' => 'contain',
            'STRETCH' => 'fill',
            'TILE' => 'none',
            default => 'cover',
        };
    }

    private function imageObjectPosition(?string $backgroundPosition): string
    {
        if ( null === $backgroundPosition || '' === trim($backgroundPosition) ) {
            return 'center';
        }

        return $backgroundPosition;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $paint
     */
    private function imageAltText(array $node, array $paint): string
    {
        foreach ( array('altText', 'alt', 'description', 'name', 'imageName') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== trim((string) $paint[$key]) ) {
                return $this->humanImageLabel((string) $paint[$key]);
            }
        }

        return $this->humanImageLabel((string) ($node['name'] ?? ''));
    }

    private function humanImageLabel(string $value): string
    {
        $label = preg_replace('/\.(jpe?g|png|gif|webp|svg)$/i', '', $value) ?? $value;
        $label = preg_replace('/[-_]+/', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        return trim($label);
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function semanticHintAttributes(array $node, string $tag, ?array $parentNode, ?array $grandParentNode): string
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        $attributes = '';
        if ( in_array($tag, array('section', 'div'), true) && $this->hasQueryContainerName($name) ) {
            $attributes .= ' data-figma-collection="posts"';
            $attributes .= ' data-figma-template-hint="archive"';
        }
        if ( 'article' === $tag && $this->hasPostCardName($name) ) {
            $inQueryContainer = false;
            foreach ( array($parentNode, $grandParentNode) as $ancestor ) {
                if ( ! is_array($ancestor) ) {
                    continue;
                }
                $ancestorName = strtolower((string) ($ancestor['name'] ?? ''));
                if ( $this->hasQueryContainerName($ancestorName) ) {
                    $inQueryContainer = true;
                    break;
                }
            }
            $attributes .= ' data-figma-content-kind="' . ( $inQueryContainer ? 'post-card' : 'post' ) . '"';
            if ( $inQueryContainer ) {
                $attributes .= ' data-figma-query-item="true"';
            }
        }
        if ( 'nav' === $tag && str_contains($name, 'pagination') ) {
            $attributes .= ' aria-label="Pagination"';
        }

        return $attributes;
    }

    private function hasQueryContainerName(string $name): bool
    {
        return 1 === preg_match('/(^|[^a-z])(query|archive|index|search results)([^a-z]|$)/', $name);
    }

    private function hasPostCardName(string $name): bool
    {
        if ( str_contains($name, 'comment') || str_contains($name, 'navigation') || str_contains($name, 'content') ) {
            return false;
        }

        return 1 === preg_match('/(^|[^a-z])(post|article|preview|card)([^a-z]|$)/', $name);
    }

    /**
     * @param array<int, mixed> $children
     * @return array{clip_paths: array<string, string>, image_mask_paths: array<string, string>, suppressed_child_ids: array<string, string>}
     */
    private function childAssetCompositionMaps(array $children): array
    {
        return $this->childLayerCompositionResolver()->resolveChildMaps($children);
    }

    /**
     * @param array<string, mixed> $child
     * @param array{clip_paths: array<string, string>, image_mask_paths: array<string, string>, suppressed_child_ids: array<string, string>} $compositionMaps
     * @return array<string, mixed>
     */
    private function applyChildAssetComposition(array $child, string $childId, array $compositionMaps): array
    {
        return $this->childLayerCompositionResolver()->applyToChild($child, $childId, $compositionMaps);
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<int, mixed> $children
     * @return array{by_first_child_id: array<string, array<string, mixed>>, member_ids: array<string, string>}
     */
    private function localBorderShellClusters(array $parent, array $children): array
    {
        return $this->localBorderShellClusterResolver()->resolve($parent, $children);
    }

    /**
     * @param array<string, mixed> $button
     * @param array<int, mixed> $children
     * @return array{styles: array<int, string>, suppressed_child_ids: array<string, string>}
     */
    private function buttonLayerComposition(array $button, array $children): array
    {
        $backgroundChild = $this->buttonBackgroundLayerChild($button, $children);
        if ( null === $backgroundChild ) {
            return array('styles' => array(), 'suppressed_child_ids' => array());
        }

        $childId = isset($backgroundChild['id']) && is_scalar($backgroundChild['id']) ? (string) $backgroundChild['id'] : '';
        if ( '' === $childId ) {
            return array('styles' => array(), 'suppressed_child_ids' => array());
        }

        $styles = $this->buttonBackgroundLayerStyles($backgroundChild);
        if ( empty($styles) ) {
            return array('styles' => array(), 'suppressed_child_ids' => array());
        }

        return array(
            'styles' => $styles,
            'suppressed_child_ids' => array($childId => 'button_background_layer_composed_into_control'),
        );
    }

    /**
     * @param array<string, mixed> $button
     * @param array<int, mixed> $children
     * @return array<string, mixed>|null
     */
    private function buttonBackgroundLayerChild(array $button, array $children): ?array
    {
        $matches = array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) || ! $this->isSimpleButtonBackgroundLayer($child, $button) ) {
                continue;
            }

            $matches[] = $child;
        }

        return 1 === count($matches) ? $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, mixed> $button
     */
    private function isSimpleButtonBackgroundLayer(array $child, array $button): bool
    {
        if ( false === ($child['visible'] ?? true) || $this->isMaskOperatorNode($child) || '' !== trim($this->subtreePlainText($child)) ) {
            return false;
        }
        if ( null !== $this->nodeAssetPath($child) || ! empty($this->nodeImagePaints($child)) ) {
            return false;
        }

        $type = strtoupper((string) ($child['type'] ?? ''));
        if ( ! in_array($type, array('RECTANGLE', 'ROUNDED_RECTANGLE', 'VECTOR', 'BOOLEAN_OPERATION'), true) ) {
            return false;
        }
        if ( null === $this->backgroundColor($child) && empty($this->strokeStyles($child)) ) {
            return false;
        }
        if ( 'BOOLEAN_OPERATION' === $type && count(array_filter($this->nodeList($child), 'is_array')) > 1 ) {
            return false;
        }

        return $this->buttonBackgroundLayerCoversButton($child, $button);
    }

    /**
     * @param array<string, mixed> $child
     * @param array<string, mixed> $button
     */
    private function buttonBackgroundLayerCoversButton(array $child, array $button): bool
    {
        $buttonWidth = $this->boxValue($button, 'width');
        $buttonHeight = $this->boxValue($button, 'height');
        $childWidth = $this->boxValue($child, 'width');
        $childHeight = $this->boxValue($child, 'height');
        if ( null === $buttonWidth || null === $buttonHeight || null === $childWidth || null === $childHeight ) {
            return false;
        }
        if ( abs($buttonWidth - $childWidth) > 1.5 || abs($buttonHeight - $childHeight) > 1.5 ) {
            return false;
        }

        $buttonBox = is_array($button['box'] ?? null) ? $button['box'] : array();
        $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
        $x = $this->positionOffset($childBox, $buttonBox, 'x', $button);
        $y = $this->positionOffset($childBox, $buttonBox, 'y', $button);
        if ( null === $x && $this->isFiniteNumeric($child['x'] ?? null) ) {
            $x = (float) $child['x'];
        }
        if ( null === $y && $this->isFiniteNumeric($child['y'] ?? null) ) {
            $y = (float) $child['y'];
        }

        return abs((float) ($x ?? 0.0)) <= 1.5 && abs((float) ($y ?? 0.0)) <= 1.5;
    }

    /**
     * @param array<string, mixed> $child
     * @return array<int, string>
     */
    private function buttonBackgroundLayerStyles(array $child): array
    {
        $styles = array();
        $background = $this->backgroundColor($child);
        if ( null !== $background ) {
            $styles[] = 'background:' . $background;
        }

        $box = is_array($child['figma_box'] ?? null) ? $child['figma_box'] : (is_array($child['box'] ?? null) ? $child['box'] : array());
        foreach ( $this->radiusStyles($box) as $style ) {
            $styles[] = $style;
        }
        foreach ( $this->strokeStyles($child) as $style ) {
            $styles[] = $style;
        }

        return $styles;
    }

    /** @param array<string, mixed> $node */
    private function nodeHasCssMaskImage(array $node): bool
    {
        return isset($node['_figma_css_mask_image_path'])
            && is_scalar($node['_figma_css_mask_image_path'])
            && '' !== (string) $node['_figma_css_mask_image_path'];
    }

    /** @param array<string, mixed> $node */
    private function nodeIntroducesLinkContext(array $node, ?array $parentNode, bool $insideLink): bool
    {
        return ! $insideLink && $this->nodeWouldWrapWithLink($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function wrapComposedElementWithLink(array $node, string $element, array &$diagnostics, ?array $parentNode, bool $insideLink): string
    {
        return $this->wrapWithLink($node, $element, $diagnostics, $this->isButtonLike($node), $parentNode, $insideLink);
    }

    /**
     * Selects a semantic HTML element for a node from its type, name, position,
     * and content. Landmarks (header/nav/section/footer/article) come from
     * structure and position; content tags (h1-h6/p/ul/li/button/span) come from
     * the page-relative typographic hierarchy and node shape. Falls back to the
     * historical name-based mapping and a generic section/div when no stronger
     * signal exists.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function semanticTag(array $node, string $type, string $name, int $depth, ?array $parentNode, ?array $grandParentNode = null): string
    {
        return $this->staticHtmlSemanticClassifier()->semanticTag($node, $type, $name, $depth, $this->sectionDepth, $parentNode, $grandParentNode);
    }

    /** @param array<string, mixed> $node */
    private function semanticRoleMetadata(array $node, string $tag, string $type, string $name): ?string
    {
        if ( 'TEXT' === $type ) {
            return null;
        }

        if ( in_array($tag, array('header', 'nav', 'footer'), true) ) {
            return $tag;
        }

        $nameHaystack = strtolower(trim($name));
        if ( '' === $nameHaystack ) {
            return null;
        }

        if ( 'article' === $tag ) {
            if ( $this->containsSemanticRoleToken($nameHaystack, array('comment', 'reply')) ) {
                return 'comment';
            }
            if ( $this->containsSemanticRoleToken($nameHaystack, array('card', 'preview')) ) {
                return 'post-card';
            }
            if ( $this->containsSemanticRoleToken($nameHaystack, array('query', 'loop')) ) {
                return 'query-item';
            }

            return 'article';
        }

        if ( 'section' === $tag && $this->hasQueryContainerName($nameHaystack) ) {
            return 'query';
        }

        if ( $this->containsSemanticRoleToken($nameHaystack, array('nav', 'navigation', 'menu')) ) {
            return 'nav';
        }
        if ( $this->containsSemanticRoleToken($nameHaystack, array('service', 'services', 'treatment', 'treatments', 'tratamiento', 'tratamientos')) ) {
            return 'services';
        }
        if ( $this->containsSemanticRoleToken($nameHaystack, array('pricing', 'price', 'prices', 'plans', 'precios', 'precio')) ) {
            return 'pricing';
        }
        if ( $this->containsSemanticRoleToken($nameHaystack, array('map', 'location', 'visit us', 'visitanos')) ) {
            return 'map';
        }
        if ( $this->containsSemanticRoleToken($nameHaystack, array('contact', 'contacto', 'phone', 'telephone', 'address', 'whatsapp')) ) {
            return 'contact';
        }
        if ( $this->containsSemanticRoleToken($nameHaystack, array('cta', 'call to action', 'book now', 'reserve', 'reservar', 'appointment', 'cita')) ) {
            return 'cta';
        }

        $subtreeHaystack = strtolower(trim(strip_tags($this->subtreePlainText($node))));
        if ( '' === $subtreeHaystack ) {
            return null;
        }

        if ( in_array($tag, array('button', 'a'), true) ) {
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('contact', 'contacto', 'phone', 'telephone', 'address', 'whatsapp')) ) {
                return 'contact';
            }
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('cta', 'call to action', 'book now', 'reserve', 'reservar', 'appointment', 'cita')) ) {
                return 'cta';
            }
        }

        if ( 'section' === $tag ) {
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('service', 'services', 'treatment', 'treatments', 'tratamiento', 'tratamientos')) ) {
                return 'services';
            }
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('pricing', 'price', 'prices', 'plans', 'precios', 'precio')) ) {
                return 'pricing';
            }
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('map', 'location', 'visit us', 'visitanos')) ) {
                return 'map';
            }
            if ( $this->containsSemanticRoleToken($subtreeHaystack, array('contact', 'contacto', 'phone', 'telephone', 'address', 'whatsapp')) ) {
                return 'contact';
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function containsSemanticRoleToken(string $haystack, array $tokens): bool
    {
        foreach ( $tokens as $token ) {
            if ( str_contains($haystack, $token) ) {
                return true;
            }
        }

        return false;
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

    private function isChromeListContext(array $node, ?array $parentNode, ?array $grandParentNode): bool
    {
        return $this->layoutIntentClassifier()->isChromeListContext($node, $parentNode, $grandParentNode);
    }

    private function hasExplicitHeadingIntent(string $lowerName): bool
    {
        return str_contains($lowerName, 'title')
            || str_contains($lowerName, 'heading')
            || str_contains($lowerName, 'headline');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isArticleLikeContainer(array $node, string $lowerName): bool
    {
        if ( str_contains($lowerName, 'article') || str_contains($lowerName, 'comment') ) {
            return $this->textDescendantCount($node) >= 2;
        }

        if ( preg_match('/(^|\s)(post|preview|card)(\s|$)/', $lowerName) ) {
            return $this->textDescendantCount($node) >= 3;
        }

        return false;
    }

    /**
     * Decides whether a frame is a genuine top-level content region worthy of a
     * <section>, rather than a nested structural container. The signals are
     * generic and position/size/content based — no file-specific names:
     *
     *  - Position: a top-level page band — exactly one level below the page
     *    root ({@see $sectionDepth}), so only the bands a hand-author wraps in
     *    <section> qualify, never the structure nested inside them.
     *  - Size: spans most of the page width, like a full-width content band.
     *  - Significance: holds meaningful mixed content (multiple text runs, or
     *    sub-regions), not a thin wrapper around a single element.
     *
     * Deeper frames — rows, columns, cards, wrappers, and decorative groups —
     * are never sections; they stay <div>. That is what keeps a page to a
     * handful of sections instead of hundreds.
     *
     * @param array<string, mixed>             $node
     * @param array<string, mixed>|null        $parentNode
     * @param array<int, array<string, mixed>> $children
     */
    private function isTopLevelSection(array $node, int $depth, ?array $parentNode, array $children): bool
    {
        // Only the page's top-level bands qualify: the single level directly
        // below the page root. Anything deeper is nested structure (a <div>).
        if ( $depth !== $this->sectionDepth ) {
            return false;
        }

        // A band needs real content, not an empty or single-element wrapper:
        // either several text runs, or more than one structural sub-region.
        $textRuns = $this->textDescendantCount($node);
        if ( $textRuns < 2 && count($children) < 2 ) {
            return false;
        }

        // A band spans most of the page: its width is a large fraction of the
        // wrapping page frame's width. Narrow columns and side rails stay <div>.
        // Root-level bands (depth 0) have no parent frame to measure against;
        // their content significance alone settles it.
        if ( null !== $parentNode ) {
            $width = $this->boxValue($node, 'width');
            $parentWidth = $this->boxValue($parentNode, 'width');
            if ( null !== $width && null !== $parentWidth && $parentWidth > 0.0 ) {
                return ( $width / $parentWidth ) >= 0.6;
            }
        }

        // Without reliable geometry, fall back to the content signal alone: a
        // top-level band carrying meaningful mixed content reads as a section.
        return true;
    }

    /**
     * Determines the tree depth at which top-level page bands live for a set of
     * root nodes. When the page is a single frame that wraps several band
     * frames, the bands are its direct children (depth 1). When the bands are
     * emitted as sibling root nodes — or when the single root frame is itself a
     * content region rather than a wrapper — the bands sit at the root (depth 0).
     *
     * @param array<int, mixed> $rootNodes
     */
    private function sectionDepthFor(array $rootNodes): int
    {
        $frames = array_values(array_filter(
            $rootNodes,
            static fn ($node): bool => is_array($node) && 'FRAME' === strtoupper((string) ($node['type'] ?? ''))
        ));

        // A single root frame is a page wrapper only when it groups several band
        // frames of its own. Otherwise it is itself a content region.
        if ( 1 === count($frames) ) {
            $childFrames = array_filter(
                $this->nodeList($frames[0]),
                static fn ($child): bool => is_array($child) && 'FRAME' === strtoupper((string) ($child['type'] ?? ''))
            );

            return count($childFrames) >= 2 ? 1 : 0;
        }

        return 0;
    }

    /**
     * Maps a container to a landmark element from explicit name signals first,
     * then position + content heuristics for generically-named regions.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed>|null $parentNode
     */
    private function landmarkTag(array $node, string $lowerName, array $children, int $depth, ?array $parentNode): ?string
    {
        $role = $this->layoutIntentClassifier()->chromeGroupRole($node, $parentNode, $depth);
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

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $children
     * @param array<string, mixed>|null $parentNode
     */
    private function isHeaderLandmarkCandidate(array $node, array $children, int $depth, ?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $region = $this->verticalRegion($node, $parentNode);
        return 'top' === $region && ($this->hasLogoChild($children) || $this->linkChildCount($children) >= 1 || $depth <= 1);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFooterLandmarkCandidate(array $node, int $depth, ?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $region = $this->verticalRegion($node, $parentNode);
        return 'bottom' === $region || $this->hasLegalText($node) || $depth <= 1;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function isNavigationContainer(array $children): bool
    {
        if ( empty($children) ) {
            return false;
        }

        $linkCount = $this->linkChildCount($children);
        if ( $linkCount >= 2 && $linkCount === count($children) ) {
            return true;
        }

        $textCount = 0;
        foreach ( $children as $child ) {
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) || $this->isMenuItemName(strtolower((string) ($child['name'] ?? ''))) ) {
                $textCount++;
            }
        }

        return $textCount >= 2 && $textCount === count($children);
    }

    /**
     * Builds the page-relative heading ranking. The most common text size is
     * treated as body copy; distinct larger sizes are ranked descending into
     * h1..h6 (largest/boldest first).
     *
     * @param array<int, mixed> $nodes
     */
    private function prepareHeadingRanking(array $nodes): void
    {
        $this->headingLevels = array();
        $sizes = array();
        $this->collectTextSizes($nodes, $sizes);
        if ( empty($sizes) ) {
            return;
        }

        $bodySize = $this->modeFontSize($sizes);
        $headingSizes = array();
        foreach ( $sizes as $size ) {
            if ( $size > $bodySize ) {
                $headingSizes[$this->sizeKey($size)] = $size;
            }
        }
        if ( empty($headingSizes) ) {
            return;
        }

        $values = array_values($headingSizes);
        rsort($values);
        $level = 1;
        foreach ( $values as $size ) {
            $this->headingLevels[$this->sizeKey($size)] = 'h' . min($level, 6);
            $level++;
        }
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, float> $sizes
     */
    private function collectTextSizes(array $nodes, array &$sizes): void
    {
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
                $size = $this->textFontSize($node);
                if ( null !== $size ) {
                    $sizes[] = $size;
                }
            }
            $this->collectTextSizes($this->nodeList($node), $sizes);
        }
    }

    /**
     * @param array<int, float> $sizes
     */
    private function modeFontSize(array $sizes): float
    {
        $counts = array();
        foreach ( $sizes as $size ) {
            $key = $this->sizeKey($size);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $bestCount = -1;
        $bestSize = 0.0;
        foreach ( $sizes as $size ) {
            $count = $counts[$this->sizeKey($size)];
            if ( $count > $bestCount || ( $count === $bestCount && $size < $bestSize ) ) {
                $bestCount = $count;
                $bestSize = $size;
            }
        }

        return $bestSize;
    }

    private function sizeKey(float $size): string
    {
        return number_format($size, 1, '.', '');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function headingLevel(array $node, string $lowerName, int $depth, ?array $parentNode = null): ?string
    {
        if ( $this->isTocEntryText($node, $parentNode) ) {
            return null;
        }

        if ( null !== $parentNode && $this->isNavigationLabelText($node, $parentNode) ) {
            return null;
        }

        $size = $this->textFontSize($node);
        if ( null !== $size ) {
            $key = $this->sizeKey($size);
            // Long running text at a heading size still reads as a paragraph.
            if ( isset($this->headingLevels[$key]) && $this->textWordCount($node) <= 24 ) {
                return $this->headingLevels[$key];
            }
        }

        // Name-based fallback preserves explicit title/heading/headline intent.
        if ( str_contains($lowerName, 'title') || str_contains($lowerName, 'heading') || str_contains($lowerName, 'headline') ) {
            return 0 === $depth ? 'h1' : 'h2';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textFontSize(array $node): ?float
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( isset($style['font_size']) && is_numeric($style['font_size']) ) {
            return (float) $style['font_size'];
        }
        if ( isset($node['fontSize']) && is_numeric($node['fontSize']) ) {
            return (float) $node['fontSize'];
        }

        return null;
    }

    /**
     * Identifies a button-like control: a small container with a single text
     * label that is filled, rounded, or named like a button.
     *
     * @param array<string, mixed> $node
     */
    private function isButtonLike(array $node): bool
    {
        return $this->staticHtmlSemanticClassifier()->isButtonLike($node);
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

        $token = trim($this->textContent($node));
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

    /**
     * Identifies input-like control chrome before generic button heuristics see a
     * rounded, filled single-text frame and emit it as a button.
     *
     * @param array<string, mixed> $node
     */
    private function isInputLike(array $node, ?array $parentNode = null): bool
    {
        return $this->staticHtmlSemanticClassifier()->isInputLike($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFormLike(array $node, ?array $parentNode): bool
    {
        return $this->staticHtmlSemanticClassifier()->isFormLike($node, $parentNode);
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasInputLike(array $node): bool
    {
        if ( $this->isInputLike($node) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
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
        foreach ( $this->nodeList($node) as $child ) {
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
            $text = strtolower($this->subtreePlainText($node));
            return 1 === preg_match('/(^|[^a-z])(submit|send|post|search|sign up|subscribe)([^a-z]|$)/', $text);
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasSubmitButtonLike($child) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isTextareaLike(array $node, ?array $parentNode = null): bool
    {
        return $this->staticHtmlSemanticClassifier()->isTextareaLike($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasFormControlAccessoryChildren(array $node): bool
    {
        return $this->staticHtmlSemanticClassifier()->hasFormControlAccessoryChildren($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFormControlPlaceholderChild(array $node): bool
    {
        return $this->staticHtmlSemanticClassifier()->isFormControlPlaceholderChild($node);
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isSpatialFormControlLabel(array $node, ?array $parentNode): bool
    {
        return $this->staticHtmlSemanticClassifier()->isSpatialFormControlLabel($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasRenderableVector(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasRenderableVector($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function syntheticFormControlMarkup(array $node, string $className, string $tag = 'input', ?array $parentNode = null): string
    {
        $tag = 'textarea' === $tag ? 'textarea' : 'input';
        $attributes = $this->formControlAttributes($node, $tag, $parentNode);
        if ( 'textarea' === $tag ) {
            return sprintf(
                '<textarea class="%1$s__control" data-figma-synthetic-control="textarea"%2$s></textarea>' . "\n",
                $this->sanitizeAttribute($className),
                $attributes
            );
        }

        return sprintf(
            '<input class="%1$s__control" data-figma-synthetic-control="input"%2$s>' . "\n",
            $this->sanitizeAttribute($className),
            $attributes
        );
    }

    private function syntheticFormControlResetCss(string $className, string $tag): string
    {
        $css = '.' . $className . '__control{border:0;background:transparent;padding:0;margin:0;min-width:0;flex:1;font:inherit;color:inherit;outline:none';
        if ( 'textarea' === $tag ) {
            $css .= ';resize:none';
        }

        return $css . '}';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasStrokePaint(array $node): bool
    {
        foreach ( array('figma_paints', 'strokes', 'strokePaints') as $key ) {
            $paints = $node[$key] ?? null;
            if ( ! is_array($paints) ) {
                continue;
            }

            if ( 'figma_paints' === $key ) {
                $paints = $paints['strokes'] ?? array();
            }

            if ( ! empty($paints) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isHeadingSeparatorChild(array $node, array $parentNode): bool
    {
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( 'flex' !== ($parentLayout['display'] ?? null) || 'row' !== ($parentLayout['flex_direction'] ?? null) ) {
            return false;
        }

        if ( ! $this->subtreeIsDecorativeSeparator($node) ) {
            return false;
        }

        $textChildren = 0;
        $separatorChildren = 0;
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) && '' !== trim($this->textContent($child)) ) {
                ++$textChildren;
            } elseif ( $this->subtreeIsDecorativeSeparator($child) ) {
                ++$separatorChildren;
            }
        }

        return 1 === $textChildren && $separatorChildren >= 1;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isPaginationContainer(array $node): bool
    {
        $text = strtolower(' ' . preg_replace('/\s+/', ' ', trim($this->subtreePlainText($node))) . ' ');
        if ( ! str_contains($text, ' previous ') || ! str_contains($text, ' next ') ) {
            return false;
        }

        $numberTokens = 0;
        foreach ( preg_split('/\s+/', trim($text)) ?: array() as $token ) {
            if ( preg_match('/^(\d+|…|\.\.\.)$/', $token) ) {
                ++$numberTokens;
            }
        }

        if ( $numberTokens < 3 ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return 'flex' === ($layout['display'] ?? null) && 'row' === ($layout['flex_direction'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isFlexiblePaginationControl(array $node, array $parentNode): bool
    {
        if ( ! $this->isPaginationContainer($parentNode) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( ! isset($layout['grow']) || ! is_numeric($layout['grow']) || (float) $layout['grow'] <= 0.0 ) {
            return false;
        }

        $text = strtolower(' ' . preg_replace('/\s+/', ' ', trim($this->subtreePlainText($node))) . ' ');
        return str_contains($text, ' previous ') || str_contains($text, ' next ');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function freeformContainerShouldUseFlow(array $node): bool
    {
        $layoutIntent = $this->layoutIntentClassifier()->layoutIntent($node);
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( empty($layout['display'] ?? null) && null !== $this->freeformContainerFlowIntent($node) ) {
            return true;
        }
        if ( empty($layout['display'] ?? null) && $this->layoutIntentCanUseFlow($layoutIntent) && $this->isContentOnlyFlowScaffold($node) ) {
            return true;
        }

        if ( ! empty($this->listItemIds($node)) ) {
            return false;
        }

        if ( ! $this->isFreeformContainer($node) ) {
            return false;
        }

        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        if ( count($children) < 2 ) {
            return false;
        }

        $contentChildren = array();
        $originY = null;
        foreach ( $children as $child ) {
            if ( $this->subtreeIsDecorativeSeparator($child) || $this->isFullyClippedDecorativeChild($child, $node) ) {
                continue;
            }
            if ( ! $this->subtreeHasText($child) ) {
                continue;
            }
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($layout['positioning'] ?? null) ) {
                return false;
            }
            $box = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( ! isset($box['y']) || ! is_numeric($box['y']) ) {
                return false;
            }
            $y = (float) $box['y'];
            if ( null === $originY ) {
                $originY = $y;
            } elseif ( abs($originY - $y) > 0.5 ) {
                return false;
            }
            $contentChildren[] = $child;
        }

        return count($contentChildren) >= 2;
    }

    /**
     * Convert simple media/content bands to intrinsic flow while preserving
     * layered freeform compositions as positioned geometry.
     *
     * @param array<string, mixed> $node
     * @return array{intent: string, display: string, direction: string, collection: null, item_count: int, column_count: int, gap: float|null, confidence: string}|null
     */
    private function freeformContainerFlowIntent(array $node): ?array
    {
        if ( ! $this->isFreeformContainer($node) ) {
            return null;
        }

        $parentBox = is_array($node['box'] ?? null) ? $node['box'] : array();
        $bands = array();
        $hasText = false;
        $hasMedia = false;
        foreach ( array_values(array_filter($this->nodeList($node), 'is_array')) as $child ) {
            if ( $this->subtreeIsDecorativeSeparator($child) || $this->isFullyClippedDecorativeChild($child, $node) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }
            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($childLayout['positioning'] ?? null) ) {
                return null;
            }
            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            $mainStart = $this->positionOffset($childBox, $parentBox, 'y');
            $crossStart = $this->positionOffset($childBox, $parentBox, 'x');
            $mainSize = $childBox['height'] ?? null;
            if ( null === $mainStart || null === $crossStart || ! is_numeric($mainSize) || (float) $mainSize <= 0.0 || $mainStart < -0.5 ) {
                return null;
            }

            $childHasText = $this->subtreeHasText($child) || $this->subtreeHasLink($child);
            $childHasMedia = $this->subtreeHasImageEvidence($child);
            if ( ! $childHasText && ! $childHasMedia ) {
                return null;
            }
            $hasText = $hasText || $childHasText;
            $hasMedia = $hasMedia || $childHasMedia;
            $bands[] = array('start' => $mainStart, 'end' => $mainStart + (float) $mainSize, 'cross' => $crossStart);
        }

        if ( count($bands) < 2 || ! $hasText || ! $hasMedia ) {
            return null;
        }

        usort($bands, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $crossOrigin = $bands[0]['cross'];
        $previousEnd = null;
        $gaps = array();
        foreach ( $bands as $band ) {
            if ( abs($band['cross'] - $crossOrigin) > 0.5 || (null !== $previousEnd && $band['start'] < $previousEnd - 0.5) ) {
                return null;
            }
            if ( null !== $previousEnd ) {
                $gaps[] = max(0.0, $band['start'] - $previousEnd);
            }
            $previousEnd = $band['end'];
        }

        return array(
            'intent'       => LayoutIntentClassifier::LAYOUT_INTENT_STACK,
            'display'      => 'flex',
            'direction'    => 'column',
            'collection'   => null,
            'item_count'   => count($bands),
            'column_count' => 1,
            'gap'          => empty($gaps) ? null : array_sum($gaps) / count($gaps),
            'confidence'   => 'high',
        );
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasImageEvidence(array $node): bool
    {
        if ( null !== $this->nodeAssetPath($node) || $this->hasImagePaintEvidence($node) ) {
            return true;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasImageEvidence($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeIsDecorativeSeparator(array $node): bool
    {
        if ( $this->subtreeHasText($node) || null !== $this->nodeAssetPath($node) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        if ( null !== $width && null !== $height && $width >= 24.0 && $height <= 12.0 ) {
            return true;
        }

        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        if ( empty($children) ) {
            return in_array(strtoupper((string) ($node['type'] ?? '')), array('VECTOR', 'LINE', 'RECTANGLE'), true)
                && null !== $width
                && null !== $height
                && $width >= 24.0
                && $height <= 12.0;
        }

        foreach ( $children as $child ) {
            if ( ! $this->subtreeIsDecorativeSeparator($child) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $parentNode
     */
    private function headingSeparatorBaselineOffset(array $parentNode): ?float
    {
        foreach ( $this->nodeList($parentNode) as $child ) {
            if ( ! is_array($child) || 'TEXT' !== strtoupper((string) ($child['type'] ?? '')) ) {
                continue;
            }

            $text = is_array($child['figma_text'] ?? null) ? $child['figma_text'] : array();
            $style = is_array($text['style'] ?? null) ? $text['style'] : array();
            $fontSize = isset($style['font_size']) && is_numeric($style['font_size']) ? (float) $style['font_size'] : null;
            $lineHeight = null;
            foreach ( array('line_height_px', 'line_height') as $key ) {
                if ( isset($style[$key]) && is_numeric($style[$key]) ) {
                    $lineHeight = (float) $style[$key];
                    break;
                }
            }
            if ( null === $fontSize || null === $lineHeight || $lineHeight <= $fontSize ) {
                return null;
            }

            return -($lineHeight - $fontSize) / 2.0;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function formControlAttributes(array $node, string $tag, ?array $parentNode = null): string
    {
        $attributes = $this->staticHtmlSemanticClassifier()->formControlAttributes($node, $tag, $parentNode);
        if ( 1 !== preg_match('/\bname="([^"]+)"/', $attributes, $matches) ) {
            return $attributes;
        }

        $name = $matches[1];
        $count = $this->formControlNameCounts[$name] ?? 0;
        $this->formControlNameCounts[$name] = $count + 1;
        if ( 0 === $count ) {
            return $attributes;
        }

        $suffix = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($node['id'] ?? ''))), '-');
        if ( '' === $suffix ) {
            $suffix = (string) ($count + 1);
        }

        return preg_replace('/\bname="[^"]+"/', 'name="' . $this->sanitizeAttribute($name . '-' . $suffix) . '"', $attributes, 1) ?? $attributes;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function nearbyFormControlLabel(array $node, ?array $parentNode): string
    {
        return $this->staticHtmlSemanticClassifier()->nearbyFormControlLabel($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function formAttributes(array $node): string
    {
        $text = strtolower($this->subtreePlainText($node));
        $name = strtolower((string) ($node['name'] ?? ''));
        $haystack = $name . ' ' . $text;

        if ( str_contains($haystack, 'search') ) {
            return ' method="get" action="' . $this->sanitizeAttribute($this->linkState->entrypointPath()) . '" role="search"';
        }

        $action = $this->currentPagePath;
        if ( str_contains($haystack, 'comment') || str_contains($haystack, 'reply') ) {
            return ' method="post" action="' . $this->sanitizeAttribute($action) . '"';
        }

        return ' method="post" action="' . $this->sanitizeAttribute($action) . '"';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function buttonControlAttributes(array $node, bool $insideForm): string
    {
        $label = trim($this->subtreePlainText($node));
        $name = (string) ($node['name'] ?? '');
        $haystack = strtolower($name . ' ' . $label);
        $submitIntent = 1 === preg_match('/(^|[^a-z])(submit|send|post|search|sign up|subscribe)([^a-z]|$)/', $haystack);
        $type = $insideForm && $submitIntent ? 'submit' : 'button';

        $attributes = ' type="' . $type . '"';
        if ( ! $insideForm && $submitIntent ) {
            $attributes .= ' data-figma-action-intent="submit"';
        }
        if ( '' === $label && '' !== $name ) {
            $attributes .= ' aria-label="' . $this->sanitizeAttribute($name) . '"';
        }

        return $attributes;
    }

    /**
     * Returns the ids of a container's children when they form a list: at least
     * three structurally-similar, text-bearing siblings of one type that are not
     * a navigation/landmark cluster. Empty otherwise.
     *
     * @param array<string, mixed> $container
     * @return array<int, string>
     */
    private function listItemIds(array $container): array
    {
        $id = (string) ($container['id'] ?? '');
        if ( '' !== $id && array_key_exists($id, $this->listItemIdCache) ) {
            return $this->listItemIdCache[$id];
        }

        $result = $this->computeListItemIds($container);
        if ( '' !== $id ) {
            $this->listItemIdCache[$id] = $result;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, string>
     */
    private function computeListItemIds(array $container): array
    {
        return $this->layoutIntentClassifier()->semanticListItemIds($container);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isListItemOf(array $node, array $parentNode): bool
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' === $id ) {
            return false;
        }

        return in_array($id, $this->listItemIds($parentNode), true);
    }

    /**
     * @param array<string, mixed> $container
     */
    private function listShouldRenderMarkers(array $container, bool $sourceTextList): bool
    {
        if ( $sourceTextList ) {
            return true;
        }

        foreach ( $this->listItemIds($container) as $index => $itemId ) {
            foreach ( $this->nodeList($container) as $child ) {
                if ( ! is_array($child) || (string) ($child['id'] ?? '') !== $itemId ) {
                    continue;
                }
                if ( $this->isSemanticListItemNode($child) ) {
                    continue 2;
                }
                foreach ( $this->nodeList($child) as $itemChild ) {
                    if ( is_array($itemChild) && $this->isListMarkerTextChild($itemChild, $index + 1) ) {
                        continue 3;
                    }
                }

                return false;
            }
        }

        return ! empty($this->listItemIds($container));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isSemanticListItemNode(array $node): bool
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        if ( empty($children) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'list item') ) {
            return true;
        }

        foreach ( $children as $child ) {
            if ( $this->isListMarkerTextChild($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $container
     */
    private function listLooksOrdered(array $container): bool
    {
        return $this->layoutIntentClassifier()->semanticListLooksOrdered($container);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isListMarkerTextChild(array $node, ?int $expectedNumber = null): bool
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $text = trim($this->subtreePlainText($node));
        if ( '' === $text ) {
            return false;
        }

        if ( null !== $expectedNumber ) {
            return 1 === preg_match('/^' . preg_quote((string) $expectedNumber, '/') . '[.)]?$/', $text);
        }

        return 1 === preg_match('/^\d+[.)]?$/', $text);
    }

    /**
     * Classifies a node's vertical position among its siblings as top, bottom,
     * or middle, using box coordinates and falling back to source order.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function verticalRegion(array $node, array $parentNode): ?string
    {
        $siblings = array_values(array_filter($this->nodeList($parentNode), 'is_array'));
        if ( 2 > count($siblings) ) {
            return 'middle';
        }

        $thisId = (string) ($node['id'] ?? '');
        $positions = array();
        $haveAll = true;
        foreach ( $siblings as $sibling ) {
            $y = $this->boxValue($sibling, 'y');
            if ( null === $y ) {
                $haveAll = false;
                break;
            }
            $positions[(string) ($sibling['id'] ?? '')] = $y;
        }

        if ( $haveAll && isset($positions[$thisId]) ) {
            $y = $positions[$thisId];
            if ( $y <= min($positions) ) {
                return 'top';
            }
            if ( $y >= max($positions) ) {
                return 'bottom';
            }

            return 'middle';
        }

        $firstId = (string) ($siblings[0]['id'] ?? '');
        $lastId = (string) ($siblings[count($siblings) - 1]['id'] ?? '');
        if ( $thisId === $firstId ) {
            return 'top';
        }
        if ( $thisId === $lastId ) {
            return 'bottom';
        }

        return 'middle';
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function linkChildCount(array $children): int
    {
        $count = 0;
        foreach ( $children as $child ) {
            if ( is_array($child) && $this->subtreeHasLink($child) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasLink(array $node): bool
    {
        if ( ! empty($node['figma_link']) ) {
            return true;
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) && $this->subtreeHasLink($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasLogoChild(array $children): bool
    {
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $name = strtolower((string) ($child['name'] ?? ''));
            if ( str_contains($name, 'logo') || str_contains($name, 'brand') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasLegalText(array $node): bool
    {
        $text = strtolower($this->subtreePlainText($node));

        return str_contains($text, '©') || str_contains($text, 'copyright') || str_contains($text, 'rights reserved');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textDescendantCount(array $node): int
    {
        $count = 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            if ( 'TEXT' === strtoupper((string) ($child['type'] ?? '')) ) {
                $count++;
            }
            $count += $this->textDescendantCount($child);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreeHasText(array $node): bool
    {
        return '' !== trim($this->subtreePlainText($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function subtreePlainText(array $node): string
    {
        $parts = array();
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $own = $this->nodePlainText($node);
            if ( '' !== $own ) {
                $parts[] = $own;
            }
        }
        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childText = $this->subtreePlainText($child);
            if ( '' !== $childText ) {
                $parts[] = $childText;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodePlainText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $out = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $out .= (string) $segment['characters'];
                }
            }
            if ( '' !== $out ) {
                return $out;
            }
        }

        foreach ( array('characters', 'text') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                return (string) $node[$key];
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function prepareHeadingAnchors(array $nodes, string $pagePath): void
    {
        $this->headingAnchorIds = array();
        $this->tocHrefByText = array();
        $this->currentPagePath = $pagePath;

        $used = array();
        $this->collectHeadingAnchors($nodes, 0, null, false, $used);
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<string, int> $used
     */
    private function collectHeadingAnchors(array $nodes, int $depth, ?array $parentNode, bool $insideToc, array &$used): void
    {
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $isToc = $insideToc || $this->isTocContainer($node);
            if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) && ! $isToc ) {
                $text = $this->normalizedAnchorText($this->nodePlainText($node));
                $heading = $this->headingLevel($node, strtolower((string) ($node['name'] ?? '')), $depth, $parentNode);
                $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
                if ( null !== $heading && '' !== $nodeId && '' !== $text ) {
                    $base = $this->slug($text);
                    $count = ($used[$base] ?? 0) + 1;
                    $used[$base] = $count;
                    $anchorId = 1 === $count ? $base : $base . '-' . $count;
                    $this->headingAnchorIds[$nodeId] = $anchorId;
                    $this->tocHrefByText[$text] ??= '#' . $anchorId;
                }
            }

            $this->collectHeadingAnchors($this->nodeList($node), $depth + 1, $node, $isToc, $used);
        }
    }

    private function headingAnchorId(array $node, string $tag): ?string
    {
        if ( ! preg_match('/^h[1-6]$/', $tag) ) {
            return null;
        }

        $nodeId = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
        return '' !== $nodeId && isset($this->headingAnchorIds[$nodeId]) ? $this->headingAnchorIds[$nodeId] : null;
    }

    private function implicitTocHref(array $node): ?string
    {
        $text = $this->normalizedAnchorText($this->nodePlainText($node));
        return '' !== $text && isset($this->tocHrefByText[$text]) ? $this->tocHrefByText[$text] : null;
    }

    private function normalizedAnchorText(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
    }

    private function isTocContainer(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'table of contents') || preg_match('/\btoc\b/', $name) ) {
            return true;
        }

        return in_array($this->normalizedAnchorText($this->nodePlainText($node)), array('contents', 'table of contents'), true)
            && 2 <= $this->textDescendantCount($node);
    }

    private function isTocEntryText(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || ! $this->isTocContainer($parentNode) ) {
            return false;
        }

        return ! in_array($this->normalizedAnchorText($this->nodePlainText($node)), array('contents', 'table of contents'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textWordCount(array $node): int
    {
        $words = preg_split('/\s+/', trim($this->nodePlainText($node)));
        if ( ! is_array($words) ) {
            return 0;
        }

        return count(array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function cornerRadius(array $node): float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box['corner_radius']) && is_numeric($box['corner_radius']) ) {
            return (float) $box['corner_radius'];
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function boxValue(array $node, string $key): ?float
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( isset($box[$key]) && is_numeric($box[$key]) ) {
            return (float) $box[$key];
        }
        if ( isset($node[$key]) && is_numeric($node[$key]) ) {
            return (float) $node[$key];
        }

        return null;
    }

    /**
     * Build the design-system coverage diagnostic: an informational record of
     * how many color/type/spacing tokens were extracted from how many detected
     * style-guide frames. Returns an empty list when no design-system frame was
     * detected, so files without one stay silent.
     *
     * @param array{css: string, coverage: array<string, int>, frame_names: array<int, string>, materialized_node_classes?: array<int, string>} $designSystem
     * @return array<int, array<string, mixed>>
     */
    private function designSystemDiagnostics(array $designSystem): array
    {
        $coverage = is_array($designSystem['coverage'] ?? null) ? $designSystem['coverage'] : array();
        if ( (int) ($coverage['frame_count'] ?? 0) < 1 ) {
            return array();
        }

        return array(
            array(
                'severity'       => 'info',
                'code'           => 'design_system_extracted',
                'message'        => 'Extracted a global design system from detected style-guide frames.',
                'frame_count'    => (int) ($coverage['frame_count'] ?? 0),
                'color_tokens'   => (int) ($coverage['color_tokens'] ?? 0),
                'type_tokens'    => (int) ($coverage['type_tokens'] ?? 0),
                'spacing_tokens' => (int) ($coverage['spacing_tokens'] ?? 0),
                'materialized_type_nodes' => (int) ($coverage['materialized_type_nodes'] ?? 0),
                'frame_names'    => is_array($designSystem['frame_names'] ?? null) ? $designSystem['frame_names'] : array(),
                'materialized_node_classes' => is_array($designSystem['materialized_node_classes'] ?? null) ? $designSystem['materialized_node_classes'] : array(),
            ),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function fontCss(array $options): string
    {
        if ( isset($options['font_css']) && is_scalar($options['font_css']) ) {
            return trim((string) $options['font_css']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function fontFamilyOverrides(array $options): array
    {
        $overrides = $options['font_family_overrides'] ?? array();
        if ( ! is_array($overrides) ) {
            return array();
        }
        $result = array();
        foreach ( $overrides as $family => $css ) {
            if ( is_string($family) && '' !== $family && is_string($css) ) {
                $result[strtolower($family)] = $css;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $styles
     * @return array<string, mixed>
     */
    private function nodeStyleDiagnostic(array $node, string $type, string $className, string $tag, array $styles, ?array $parentNode, bool $rendersInlineVectorSvg = false): array
    {
        $expected = $this->expectedNodeStyleData($node, $type, $parentNode, $rendersInlineVectorSvg);
        $emitted = $this->emittedNodeStyleData($styles);
        $matches = array();
        $mismatches = array();

        foreach ( array_keys($expected + $emitted) as $key ) {
            $left = $expected[$key] ?? null;
            $right = $emitted[$key] ?? null;
            $matches[$key] = $left === $right;
            if ( ! $matches[$key] ) {
                $mismatches[] = $key;
            }
        }

        return array(
            'node'       => array(
                'id'    => (string) ($node['id'] ?? ''),
                'name'  => (string) ($node['name'] ?? ''),
                'type'  => $type,
                'tag'   => $tag,
                'class' => $className,
            ),
            'expected'   => $expected,
            'emitted'    => $emitted,
            'matches'    => $matches,
            'mismatches' => $mismatches,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string|null>
     */
    private function expectedNodeStyleData(array $node, string $type, ?array $parentNode, bool $rendersInlineVectorSvg = false): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $data = array(
            'background'  => $this->nodeShouldEmitCssBackground($type, null, $rendersInlineVectorSvg) ? $this->backgroundColor($node) : null,
            'width'       => $this->expectedCssLength($box['width'] ?? null),
            'height'      => $this->expectedCssLength($box['height'] ?? null),
            'x'           => null,
            'y'           => null,
            'text_color'  => null,
            'font_family' => null,
            'font_size'   => null,
            'font_weight' => null,
            'line_height' => null,
        );

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( null !== $parentNode && $this->isFreeformContainer($parentNode) ) {
            $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
            $data['x'] = $this->expectedCssLength($this->positionOffset($box, $parentBox, 'x'));
            $data['y'] = $this->expectedCssLength($this->positionOffset($box, $parentBox, 'y'));
        } elseif ( null !== $parentNode && 'absolute' === ($layout['positioning'] ?? null) ) {
            $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
            $data['x'] = $this->expectedCssLength($this->relativeOffset($box, $parentBox, 'x'));
            $data['y'] = $this->expectedCssLength($this->relativeOffset($box, $parentBox, 'y'));
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->expectedTextStyleData($node) as $key => $value ) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string|null>
     */
    private function expectedTextStyleData(array $node): array
    {
        $declarations = $this->styleDeclarationMap($this->textStyles($node));
        return array(
            'text_color'  => $declarations['color'] ?? null,
            'font_family' => $declarations['font-family'] ?? null,
            'font_size'   => $declarations['font-size'] ?? null,
            'font_weight' => $declarations['font-weight'] ?? null,
            'line_height' => $declarations['line-height'] ?? null,
        );
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string|null>
     */
    private function emittedNodeStyleData(array $styles): array
    {
        $map = $this->styleDeclarationMap($styles);
        return array(
            'background'  => $map['background'] ?? null,
            'width'       => $map['width'] ?? null,
            'height'      => $map['height'] ?? null,
            'x'           => $map['left'] ?? null,
            'y'           => $map['top'] ?? null,
            'text_color'  => $map['color'] ?? null,
            'font_family' => $map['font-family'] ?? null,
            'font_size'   => $map['font-size'] ?? null,
            'font_weight' => $map['font-weight'] ?? null,
            'line_height' => $map['line-height'] ?? null,
        );
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, string>
     */
    private function styleDeclarationMap(array $styles): array
    {
        $map = array();
        foreach ( $styles as $style ) {
            $parts = explode(':', $style, 2);
            if ( 2 === count($parts) ) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $map;
    }

    private function expectedCssLength(mixed $value): ?string
    {
        return is_numeric($value) ? $this->number((float) $value) . 'px' : null;
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     */
    private function countNodeStyleMismatches(array $nodeStyleDiagnostics): int
    {
        $count = 0;
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $count += count(is_array($diagnostic['mismatches'] ?? null) ? $diagnostic['mismatches'] : array());
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     * @return array<int, string>
     */
    private function fontFamilies(array $nodeStyleDiagnostics): array
    {
        $families = array();
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $family = $diagnostic['expected']['font_family'] ?? null;
            if ( is_scalar($family) && '' !== $this->primaryFontFamily((string) $family) ) {
                $families[] = $this->primaryFontFamily((string) $family);
            }
        }

        sort($families);
        return array_values(array_unique($families));
    }

    /**
     * @param array<int, array<string, mixed>> $nodeStyleDiagnostics
     * @return array<int, array<string, mixed>>
     */
    private function fontUsage(array $nodeStyleDiagnostics, string $css = '', string $html = ''): array
    {
        $usageByFamily = array();
        foreach ( $nodeStyleDiagnostics as $diagnostic ) {
            $expected = is_array($diagnostic['expected'] ?? null) ? $diagnostic['expected'] : array();
            if ( ! isset($expected['font_family']) || ! is_scalar($expected['font_family']) ) {
                continue;
            }

            $family = $this->primaryFontFamily((string) $expected['font_family']);
            if ( '' === $family ) {
                continue;
            }

            $node = is_array($diagnostic['node'] ?? null) ? $diagnostic['node'] : array();
            $weight = isset($expected['font_weight']) && is_numeric($expected['font_weight']) ? (int) $expected['font_weight'] : 400;
            $usageByFamily[$family] ??= array('weights' => array(), 'weight_counts' => array(), 'text_node_count' => 0, 'visible_text_area_px' => 0.0, 'sample_nodes' => array());
            $usageByFamily[$family]['weights'][] = $weight;
            $usageByFamily[$family]['weight_counts'][(string) $weight] = ($usageByFamily[$family]['weight_counts'][(string) $weight] ?? 0) + 1;
            $usageByFamily[$family]['text_node_count']++;
            $usageByFamily[$family]['visible_text_area_px'] += $this->diagnosticTextArea($expected);
            if ( count($usageByFamily[$family]['sample_nodes']) < 10 ) {
                $usageByFamily[$family]['sample_nodes'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name' => (string) ($node['name'] ?? ''),
                    'weight' => $weight,
                );
            }
        }

        foreach ( $this->fontUsageEntriesFromMaterializedCss($css) as $entry ) {
            $this->addFontUsageEntry($usageByFamily, $entry);
        }

        foreach ( $this->fontUsageEntriesFromInlineHtml($html) as $entry ) {
            $this->addFontUsageEntry($usageByFamily, $entry);
        }

        ksort($usageByFamily);
        $usage = array();
        foreach ( $usageByFamily as $family => $data ) {
            $weights = array_values(array_unique($data['weights']));
            sort($weights);
            ksort($data['weight_counts']);
            $usage[] = array(
                'family' => $family,
                'weights' => $weights,
                'weight_counts' => $data['weight_counts'],
                'text_node_count' => (int) $data['text_node_count'],
                'visible_text_area_px' => (int) round((float) $data['visible_text_area_px']),
                'sample_nodes' => $data['sample_nodes'],
            );
        }

        return $usage;
    }

    /**
     * @param array<string, array<string, mixed>> $usageByFamily
     * @param array<string, mixed> $entry
     */
    private function addFontUsageEntry(array &$usageByFamily, array $entry): void
    {
        $family = $this->primaryFontFamily((string) ($entry['family'] ?? ''));
        if ( '' === $family ) {
            return;
        }

        $weight = isset($entry['weight']) && is_numeric($entry['weight']) ? (int) $entry['weight'] : 400;
        $usageByFamily[$family] ??= array('weights' => array(), 'weight_counts' => array(), 'text_node_count' => 0, 'visible_text_area_px' => 0.0, 'sample_nodes' => array());
        $usageByFamily[$family]['weights'][] = $weight;
        if ( false !== ($entry['counts_as_text_node'] ?? true) ) {
            $usageByFamily[$family]['weight_counts'][(string) $weight] = ($usageByFamily[$family]['weight_counts'][(string) $weight] ?? 0) + 1;
            $usageByFamily[$family]['text_node_count']++;
        }
        if ( count($usageByFamily[$family]['sample_nodes']) < 10 ) {
            $usageByFamily[$family]['sample_nodes'][] = array_filter(array(
                'node_id' => (string) ($entry['node_id'] ?? ''),
                'name'    => (string) ($entry['name'] ?? ''),
                'weight'  => $weight,
                'source'  => (string) ($entry['source'] ?? ''),
            ), static fn (mixed $value): bool => '' !== $value);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageEntriesFromMaterializedCss(string $css): array
    {
        $entries = array();
        if ( '' === trim($css) || false === preg_match_all('/font-family\s*:\s*([^;}]+)/i', $css, $matches, PREG_OFFSET_CAPTURE) ) {
            return $entries;
        }

        foreach ( $matches[1] as $match ) {
            $family = $this->primaryFontFamily((string) $match[0]);
            if ( '' === $family || $this->isCssGenericFontFamily($family) ) {
                continue;
            }
            $entries[] = array(
                'family' => $family,
                'weight' => $this->fontWeightNearCssOffset($css, (int) $match[1]),
                'source' => 'materialized_css',
                'counts_as_text_node' => false,
            );
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fontUsageEntriesFromInlineHtml(string $html): array
    {
        $entries = array();
        if ( '' === trim($html) || false === preg_match_all('/style=(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches) ) {
            return $entries;
        }

        foreach ( $matches[1] as $index => $doubleQuotedStyle ) {
            $style = '' !== $doubleQuotedStyle ? $doubleQuotedStyle : (string) ($matches[2][$index] ?? '');
            $map = $this->styleDeclarationMap(array_filter(array_map('trim', explode(';', html_entity_decode($style, ENT_QUOTES | ENT_HTML5, 'UTF-8')))));
            if ( ! isset($map['font-family']) || ! is_scalar($map['font-family']) ) {
                continue;
            }
            $family = $this->primaryFontFamily((string) $map['font-family']);
            if ( '' === $family || $this->isCssGenericFontFamily($family) ) {
                continue;
            }
            $entries[] = array(
                'family' => $family,
                'weight' => isset($map['font-weight']) && is_numeric($map['font-weight']) ? (int) $map['font-weight'] : 400,
                'source' => 'inline_html',
                'counts_as_text_node' => false,
            );
        }

        return $entries;
    }

    private function fontWeightNearCssOffset(string $css, int $offset): int
    {
        $blockStart = strrpos(substr($css, 0, $offset), '{');
        $blockEnd = strpos($css, '}', $offset);
        if ( false === $blockStart || false === $blockEnd ) {
            return 400;
        }

        $block = substr($css, $blockStart + 1, $blockEnd - $blockStart - 1);
        if ( 1 === preg_match('/font-weight\s*:\s*([0-9.]+)/i', $block, $matches) && is_numeric($matches[1]) ) {
            return (int) $matches[1];
        }

        return 400;
    }

    private function isCssGenericFontFamily(string $family): bool
    {
        return in_array(strtolower($family), array('serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong'), true);
    }

    /**
     * @param array<string, string|null> $expected
     */
    private function diagnosticTextArea(array $expected): float
    {
        $width = $this->cssPxValue($expected['width'] ?? null);
        $height = $this->cssPxValue($expected['height'] ?? null);
        return max(0.0, $width) * max(0.0, $height);
    }

    private function cssPxValue(mixed $value): float
    {
        if ( ! is_scalar($value) ) {
            return 0.0;
        }

        $value = trim((string) $value);
        return preg_match('/^-?\d+(?:\.\d+)?px$/', $value) ? (float) substr($value, 0, -2) : 0.0;
    }

    /**
     * Extract the primary family from a (possibly multi-value) font-family
     * declaration, dropping the generic fallback so font detection keys on the
     * source family.
     */
    private function primaryFontFamily(string $value): string
    {
        $first = explode(',', $value, 2)[0];

        return trim($first, " \t\n\r\0\x0B\"'");
    }

    /**
     * Build one info diagnostic per unresolved source font family so operators
     * know exactly which families still need a supplied font.
     *
     * @param array<string, mixed> $fontResolution
     * @return array<int, array<string, mixed>>
     */
    private function unresolvedSourceFontDiagnostics(array $fontResolution): array
    {
        $diagnostics = array();
        foreach ( array_values($fontResolution['unresolved_families'] ?? array()) as $fontFamily ) {
            $diagnostics[] = array(
                'severity' => 'info',
                'code' => 'font_css_missing_for_source_font',
                'message' => 'Source font family could not be resolved to embedded or CDN font CSS; supply font_css to restore visual parity.',
                'context' => array('font_family' => (string) $fontFamily),
            );
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function normalizeLinkTargetPaths(array $options): array
    {
        $map = array();
        $raw = is_array($options['link_target_paths'] ?? null) ? $options['link_target_paths'] : array();
        foreach ( $raw as $nodeId => $path ) {
            if ( is_scalar($nodeId) && is_scalar($path) && '' !== (string) $nodeId && '' !== (string) $path ) {
                $map[(string) $nodeId] = (string) $path;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function linkTargetPathsFromPagePlan(array $pagePlan, array $options): array
    {
        $map = $this->normalizeLinkTargetPaths($options);
        foreach ( $this->plannedPages($pagePlan) as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            if ( '' === $frameId || isset($map[$frameId]) ) {
                continue;
            }

            $name = (string) ($page['name'] ?? $frameId);
            $map[$frameId] = $this->pagePath($page, $name, is_int($index) ? $index : 0);
        }

        return $map;
    }

    /** @param array<string, mixed> $pagePlan */
    private function entrypointPathFromPagePlan(array $pagePlan): string
    {
        foreach ( $this->plannedPages($pagePlan) as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }
            $name = (string) ($page['name'] ?? 'Page');
            $path = $this->pagePath($page, $name, is_int($index) ? $index : 0);
            if ( true === ($page['entrypoint'] ?? false) ) {
                return $path;
            }
        }

        return 'index.html';
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @param array<string, mixed> $scenegraph
     * @return array{paths: array<string, string>, targets: array<string, array{label:string,path:string,confidence:string,evidence:string}>}
     */
    private function implicitRouteDataFromPagePlan(array $pagePlan, array $scenegraph): array
    {
        $routes = array();
        $targets = array();
        $nodeMap = $this->nodeMap($scenegraph);

        foreach ( $this->plannedPages($pagePlan) as $index => $page ) {
            if ( ! is_array($page) ) {
                continue;
            }

            $frameId = isset($page['frame_id']) && is_scalar($page['frame_id']) ? (string) $page['frame_id'] : '';
            $frameNode = '' !== $frameId && isset($nodeMap[$frameId]) && is_array($nodeMap[$frameId]) ? $nodeMap[$frameId] : array();
            $name = (string) ($page['name'] ?? ($frameNode['name'] ?? 'Page'));
            $path = $this->pagePath($page, $name, is_int($index) ? $index : 0);
            $pageType = (string) ($page['page_type'] ?? '');

            foreach ( array($name, $this->stripDeviceSuffix($name), (string) ($page['slug'] ?? '')) as $label ) {
                $this->addImplicitRoute($routes, $targets, $label, $path, 'high', 'planned_page_identity');
            }

            if ( true === ($page['entrypoint'] ?? false) || 'front_page' === $pageType ) {
                foreach ( array('home', 'homepage', 'front page') as $label ) {
                    $this->addImplicitRoute($routes, $targets, $label, $path, 'high', 'front_page_alias');
                }
            }
            if ( 'archive' === $pageType ) {
                foreach ( array('archive', 'archives', 'blog', 'posts', 'news') as $label ) {
                    $this->addImplicitRoute($routes, $targets, $label, $path, 'high', 'archive_page_type_alias');
                }
            }

            if ( 'front_page' !== $pageType ) {
                foreach ( $this->pageHeadingLabels($frameNode) as $label ) {
                    $this->addImplicitRoute($routes, $targets, $label, $path, 'medium', 'page_heading');
                    if ( 'page' === $pageType && str_ends_with($this->routeKey($label), '-us') ) {
                        $this->addImplicitRoute($routes, $targets, preg_replace('/\s+us$/i', '', $label) ?? $label, $path, 'medium', 'page_heading_alias');
                    }
                }
            }
        }

        return array('paths' => $routes, 'targets' => $targets);
    }

    /**
     * @param array<string, string> $routes
     * @param array<string, array{label:string,path:string,confidence:string,evidence:string}> $targets
     */
    private function addImplicitRoute(array &$routes, array &$targets, string $label, string $path, string $confidence, string $evidence): void
    {
        $label = trim($label);
        if ( '' === $label ) {
            return;
        }

        $key = $this->routeKey($label);
        if ( '' === $key ) {
            return;
        }

        $existing = is_array($targets[$key] ?? null) ? $targets[$key] : null;
        if ( null !== $existing && $this->implicitRouteEvidencePriority((string) ($existing['evidence'] ?? '')) >= $this->implicitRouteEvidencePriority($evidence) ) {
            return;
        }

        if ( null === $existing || $this->implicitRouteEvidencePriority((string) ($existing['evidence'] ?? '')) < $this->implicitRouteEvidencePriority($evidence) ) {
            $routes[$key] = $path;
            $targets[$key] = array(
                'label'      => $label,
                'path'       => $path,
                'confidence' => $confidence,
                'evidence'   => $evidence,
            );
        }
    }

    private function implicitRouteEvidencePriority(string $evidence): int
    {
        return match ( $evidence ) {
            'planned_page_identity' => 40,
            'front_page_alias' => 30,
            'page_heading', 'page_heading_alias' => 20,
            'archive_page_type_alias' => 10,
            default => 0,
        };
    }

    private function routeKey(string $label): string
    {
        return $this->slug($this->stripDeviceSuffix($label));
    }

    private function stripDeviceSuffix(string $label): string
    {
        return trim((string) preg_replace('/\s+[–-]\s*(desktop|mobile|tablet)\s*$/i', '', $label));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function pageHeadingLabels(array $node): array
    {
        $candidates = array();
        $this->collectPageHeadingLabels($node, $candidates);
        if ( empty($candidates) ) {
            return array();
        }

        $maxSize = max(array_map(static fn (array $candidate): float => (float) ($candidate['font_size'] ?? 0), $candidates));
        $labels = array();
        foreach ( $candidates as $candidate ) {
            if ( abs((float) ($candidate['font_size'] ?? 0) - $maxSize) < 0.5 ) {
                $labels[] = (string) ($candidate['label'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($labels, static fn (string $label): bool => '' !== trim($label))));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{label:string,font_size:float}> $labels
     */
    private function collectPageHeadingLabels(array $node, array &$labels): void
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $name = strtolower((string) ($node['name'] ?? ''));
            $text = trim($this->nodePlainText($node));
            $fontSize = $this->textFontSize($node) ?? 0.0;
            if ( '' !== $text && (str_contains($name, 'heading') || $fontSize >= 24.0) ) {
                $labels[] = array('label' => $text, 'font_size' => $fontSize);
            }
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectPageHeadingLabels($child, $labels);
            }
        }
    }

    /**
     * Wrap an emitted element in a real anchor when the node carries Figma link data.
     *
     * @param array<string, mixed>             $node
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function wrapWithLink(array $node, string $element, array &$diagnostics, bool $buttonLike = false, ?array $parentNode = null, bool $insideLink = false): string
    {
        if ( $insideLink ) {
            return $element;
        }

        $link = is_array($node['figma_link'] ?? null) ? $node['figma_link'] : array();
        if ( empty($link) ) {
            $tocHref = $this->isTocEntryText($node, $parentNode) ? $this->implicitTocHref($node) : null;
            if ( null !== $tocHref ) {
                $this->linkState->increment('toc_links');
                $this->linkState->increment('anchors_emitted');

                return $this->linkedElementMarkup($element, $tocHref, 'toc', 'figma-link figma-toc-link', $buttonLike);
            }

            $implicitHref = $this->implicitRouteHref($node, $parentNode, true);
            if ( null !== $implicitHref ) {
                $this->linkState->increment('implicit_route_links');
                $this->linkState->increment('anchors_emitted');

                return $this->linkedElementMarkup($element, $implicitHref, 'implicit-route', $buttonLike ? 'figma-link button' : 'figma-link', $buttonLike);
            }

            return $element;
        }

        $this->linkState->increment('sources_found');
        $type = (string) ($link['type'] ?? '');
        $nodeId = (string) ($node['id'] ?? '');
        $targetNodeId = (string) ($link['target_node_id'] ?? '');
        $href = null;
        $resolved = false;

        if ( 'url' === $type ) {
            $this->linkState->increment('url_links');
            $href = $this->sanitizeLinkUrl((string) ($link['url'] ?? ''));
            $resolved = '#' !== $href;
        } elseif ( 'node' === $type ) {
            $this->linkState->increment('node_links');
            $targetPath = '' !== $targetNodeId ? $this->linkState->linkTargetPath($targetNodeId) : null;
            if ( null !== $targetPath ) {
                $href = $targetPath;
                if ( isset($this->headingAnchorIds[$targetNodeId]) ) {
                    $href = $this->linkHrefWithHash($href, $this->headingAnchorIds[$targetNodeId]);
                }
                $resolved = true;
            } elseif ( '' !== $targetNodeId && isset($this->headingAnchorIds[$targetNodeId]) ) {
                $href = '#' . $this->headingAnchorIds[$targetNodeId];
                $resolved = true;
            } else {
                $href = '#';
            }
        }

        if ( null === $href ) {
            return $element;
        }

        if ( ! $resolved ) {
            $this->linkState->increment('unresolved');
            $this->linkState->appendTarget('unresolved_targets', array(
                'node_id'        => $nodeId,
                'link_type'      => $type,
                'target_node_id' => $targetNodeId,
                'source'         => (string) ($link['source'] ?? ''),
            ));
            $diagnostics[] = array(
                'severity' => 'info',
                'code'     => 'link_target_unresolved',
                'message'  => 'Figma link target could not be resolved to a generated page, so no anchor was emitted.',
                'context'  => array(
                    'node_id'        => $nodeId,
                    'link_type'      => $type,
                    'target_node_id' => $targetNodeId,
                    'source'         => (string) ($link['source'] ?? ''),
                ),
            );

            return $element;
        }

        $this->linkState->increment('anchors_emitted');

        return $this->linkedElementMarkup($element, $href, $type, $buttonLike ? 'figma-link button' : 'figma-link', $buttonLike);
    }

    /**
     * @param array<string, mixed>|null $parentNode
     */
    private function nodeWouldWrapWithLink(array $node, ?array $parentNode): bool
    {
        $link = is_array($node['figma_link'] ?? null) ? $node['figma_link'] : array();
        if ( ! empty($link) ) {
            $type = (string) ($link['type'] ?? '');
            return 'url' === $type || 'node' === $type;
        }

        if ( $this->isTocEntryText($node, $parentNode) && null !== $this->implicitTocHref($node) ) {
            return true;
        }

        return null !== $this->implicitRouteHref($node, $parentNode, false);
    }

    private function linkedElementMarkup(string $element, string $href, string $type, string $className, bool $buttonLike): string
    {
        $anchor = sprintf(
            '<a class="%1$s" href="%2$s" data-figma-link-type="%3$s">',
            $this->sanitizeAttribute($className),
            $this->sanitizeAttribute($href),
            $this->sanitizeAttribute($type)
        );

        if ( 1 === preg_match('/^<li([^>]*)>(.*)<\/li>\n?$/s', $element, $matches) ) {
            return '<li' . $matches[1] . '>' . $anchor . $matches[2] . "</a></li>\n";
        }

        if ( $buttonLike && 1 === preg_match('/^<button([^>]*)>(.*)<\/button>\n?$/s', $element, $matches) ) {
            $attributes = preg_replace('/\s+type="[^"]*"/', '', $matches[1]) ?? $matches[1];
            $element = '<div' . $attributes . '>' . $matches[2] . "</div>\n";
        }

        return $anchor . $element . "</a>\n";
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function implicitRouteHref(array $node, ?array $parentNode, bool $recordUnresolved = false): ?string
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        if ( str_contains($name, 'logo') && ! $this->isSocialIconNode($node) ) {
            return $this->linkState->entrypointPath();
        }

        if ( null !== $parentNode && $this->isNavigationLabelText($node, $parentNode) ) {
            $label = $this->subtreePlainText($node);
            return $this->routePathForLabel($label, $node, $parentNode, $recordUnresolved)
                ?? $this->currentPageAnchorHrefForLabel($label)
                ?? $this->recordImplicitRouteUnresolved($label, $node, 'navigation_label_unresolved', $recordUnresolved && ! $this->hasImplicitRouteForLabel($label));
        }

        if ( $this->isMenuItemName($name) || str_contains($name, 'nav item') ) {
            $label = $this->subtreePlainText($node);
            return $this->routePathForLabel($label, $node, $parentNode, $recordUnresolved)
                ?? $this->currentPageAnchorHrefForLabel($label)
                ?? $this->recordImplicitRouteUnresolved($label, $node, 'menu_item_unresolved', $recordUnresolved && ! $this->hasImplicitRouteForLabel($label));
        }

        if ( null !== $parentNode && $this->isPaginationContainer($parentNode) ) {
            $label = strtolower($this->subtreePlainText($node));
            if ( str_contains($label, 'next') && $this->linkState->hasImplicitRoute('news') ) {
                return $this->routePathForLabel('news');
            }
        }

        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $text = trim($this->nodePlainText($node));
            if ( '' !== $text ) {
                return $this->routePathForLabel($text, $node, $parentNode, $recordUnresolved);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isSocialIconNode(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        foreach ( array('facebook', 'instagram', 'twitter', 'x-twitter', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'mastodon', 'bluesky') as $network ) {
            if ( str_contains($name, $network) ) {
                return true;
            }
        }

        return false;
    }

    private function routePathForLabel(string $label, ?array $node = null, ?array $parentNode = null, bool $recordUnresolved = false): ?string
    {
        $key = $this->routeKey($label);
        $path = $this->linkState->implicitRoutePath($key);
        if ( '' === $key || null === $path ) {
            return null;
        }

        if ( $path === $this->linkState->entrypointPath() && ! $this->isHomeRouteKey($key) && ! $this->isLogoNode($node) ) {
            $this->recordImplicitRouteUnresolved($label, $node ?? array(), 'entrypoint_label_not_home', $recordUnresolved, $this->linkState->implicitRouteTarget($key));
            return null;
        }

        if ( $path === $this->currentPagePath ) {
            $routeTarget = $this->linkState->implicitRouteTarget($key);
            $this->linkState->increment('implicit_route_self_suppressed');
            if ( $recordUnresolved ) {
                $this->linkState->appendTarget('implicit_route_self_suppressed_targets', array_filter(array(
                    'node_id' => is_array($node) ? (string) ($node['id'] ?? '') : '',
                    'label'   => trim($label),
                    'path'    => $path,
                    'route_confidence' => (string) ($routeTarget['confidence'] ?? ''),
                    'route_evidence'   => (string) ($routeTarget['evidence'] ?? ''),
                ), static fn (mixed $value): bool => '' !== $value));
            }
            return null;
        }

        return $path;
    }

    private function hasImplicitRouteForLabel(string $label): bool
    {
        $key = $this->routeKey($label);
        return $this->linkState->hasImplicitRoute($key);
    }

    private function isHomeRouteKey(string $key): bool
    {
        return in_array($key, array('home', 'homepage', 'front-page', 'home-page', 'frontpage'), true);
    }

    /** @param array<string, mixed>|null $node */
    private function isLogoNode(?array $node): bool
    {
        return null !== $node && str_contains(strtolower((string) ($node['name'] ?? '')), 'logo');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $routeTarget
     */
    private function recordImplicitRouteUnresolved(string $label, array $node, string $reason, bool $record, array $routeTarget = array()): ?string
    {
        if ( ! $record || '' === trim($label) ) {
            return null;
        }

        $this->linkState->increment('implicit_route_unresolved');
        $this->linkState->appendTarget('implicit_route_unresolved_targets', array_filter(array(
                'node_id' => (string) ($node['id'] ?? ''),
                'label'   => trim($label),
                'reason'  => $reason,
                'route_path' => (string) ($routeTarget['path'] ?? ''),
                'route_confidence' => (string) ($routeTarget['confidence'] ?? ''),
                'route_evidence' => (string) ($routeTarget['evidence'] ?? ''),
            ), static fn (mixed $value): bool => '' !== $value));

        return null;
    }

    private function currentPageAnchorHrefForLabel(string $label): ?string
    {
        $text = $this->normalizedAnchorText($label);
        if ( '' === $text || ! isset($this->tocHrefByText[$text]) ) {
            return null;
        }

        return $this->tocHrefByText[$text];
    }

    private function sanitizeLinkUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url ) {
            return '#';
        }

        if ( str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, '?') ) {
            return $url;
        }

        if ( 1 === preg_match('/^(https?:|mailto:|tel:)/i', $url) ) {
            return $url;
        }

        // Reject unsafe or unsupported schemes (javascript:, data:, etc.).
        if ( 1 === preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) ) {
            return '#';
        }

        // Schemeless relative reference (e.g. about.html, ../contact/).
        return $url;
    }

    private function linkHrefWithHash(string $href, string $anchorId): string
    {
        $base = preg_replace('/#.*$/', '', $href) ?? $href;
        if ( '' === $base || $base === $this->currentPagePath ) {
            return '#' . $anchorId;
        }

        return $base . '#' . $anchorId;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function visualNodeMap(array $nodes): array
    {
        return (new VisualNodeMapBuilder($this->assetsById, $this->renderTextGlyphPaths, $this->emittedNodeMetadata))->build($this->withoutSuppressedVisualNodes($nodes));
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function withoutSuppressedVisualNodes(array $nodes): array
    {
        if ( empty($this->suppressedVisualNodeIds) ) {
            return $nodes;
        }

        $filtered = array();
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
            if ( '' !== $id && isset($this->suppressedVisualNodeIds[$id]) ) {
                continue;
            }
            if ( is_array($node['children'] ?? null) ) {
                $node['children'] = $this->withoutSuppressedVisualNodes($node['children']);
            }
            $filtered[] = $node;
        }

        return $filtered;
    }

    /**
     * Build production-transform diagnostics for Figma import development.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array<int, array<string, mixed>> $assetFiles
     * @param array<int, string> $fontFamilies
     * @param array<int, array<string, mixed>> $fontUsage
     * @param array<string, mixed> $fontResolution
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, mixed> $sourceLossEvidence
     * @return array<string, mixed>
     */
    private function transformDiagnostics(array $nodes, array $visualNodeMap, array $assetFiles, array $fontFamilies, array $fontUsage, array $fontResolution, string $css, array $diagnostics, string $html = '', array $sourceLossEvidence = array()): array
    {
        $image = array(
            'paint_refs'      => 0,
            'node_refs'       => 0,
            'resolved_assets' => 0,
            'image_block_count' => 0,
            'total_node_count' => 0,
            'image_block_nodes' => array(),
            'missing_assets'  => array(),
            'asset_nodes'     => array(),
            'asset_node_reason_categories' => array(),
        );
        $vectors = array(
            'nodes'                       => 0,
            'rendered_paths'              => 0,
            'rendered_asset_fallbacks'    => 0,
            'vector_network_decoded'      => 0,
            'boolean_operations_composed' => 0,
            'placeholders'                => 0,
            'placeholder_reasons'         => array(),
            'placeholder_nodes'           => array(),
            'child_composition'           => array(
                'vector_parent_node_count' => 0,
                'vector_child_node_count' => 0,
                'composed_parent_node_count' => 0,
                'uncomposed_parent_node_count' => 0,
                'uncomposed_vector_child_node_count' => 0,
                'sample_nodes' => array(),
            ),
        );
        $geometryDiagnostics = $this->diagnosticGeometryIndexes($nodes, $visualNodeMap, $css);
        $nodeDiagnosticIndex = $geometryDiagnostics['nodes'];
        $cssGeometryIndex = $geometryDiagnostics['emitted_css'];
        $visualNodeMapById = $geometryDiagnostics['visual_by_id'];
        $cssOffsetDiagnostics = $this->cssAbsoluteOffsetDiagnostics($css, $nodeDiagnosticIndex, $geometryDiagnostics['visual_by_class'], $visualNodeMapById);
        $invalidCssDiagnostics = $this->invalidCssNumericTokenDiagnostics($css);
        $visualOffsetDiagnostics = $this->visualOffCanvasDiagnostics($visualNodeMap, $visualNodeMapById, $nodeDiagnosticIndex, $cssGeometryIndex);
        $visualClipDiagnostics = $this->visualClipDiagnostics($visualNodeMap, $nodeDiagnosticIndex);
        $layout = array(
            'large_negative_left_count' => preg_match_all('/left:-[0-9]{3,}/', $css),
            'large_css_offset_count' => count($cssOffsetDiagnostics),
            'large_css_offset_nodes' => $cssOffsetDiagnostics,
            'invalid_css_count' => count($invalidCssDiagnostics),
            'invalid_css_tokens' => $invalidCssDiagnostics,
            'off_canvas_visual_node_count' => count($visualOffsetDiagnostics),
            'off_canvas_visual_nodes' => $visualOffsetDiagnostics,
            'clipped_visual_node_count' => (int) ($visualClipDiagnostics['clipped_visual_node_count'] ?? 0),
            'clipped_visual_area_ratio' => (float) ($visualClipDiagnostics['clipped_visual_area_ratio'] ?? 0.0),
            'clipped_visual_area_px' => (int) ($visualClipDiagnostics['clipped_visual_area_px'] ?? 0),
            'clipped_visual_nodes' => is_array($visualClipDiagnostics['clipped_visual_nodes'] ?? null) ? $visualClipDiagnostics['clipped_visual_nodes'] : array(),
            'large_absolute_offset_count' => 0,
            'large_absolute_offset_nodes' => array(),
            'suppressed_large_absolute_offset_count' => 0,
            'suppressed_large_absolute_offset_reason_counts' => array(),
            'suppressed_large_absolute_offset_nodes' => array(),
            'empty_visible_container_count' => 0,
            'empty_visible_container_blocker_count' => 0,
            'empty_visible_container_categories' => array(),
            'empty_visible_containers' => array(),
            'decorative_underlays'      => array(
                'count' => 0,
                'nodes' => array(),
            ),
            'image_heavy_landmark_candidates' => array(),
            'layout_mismatch_count' => 0,
            'layout_mismatch_status' => 'not_evaluated',
            'stacking_order' => array(
                'mixed_positioning_parent_count' => 0,
                'absolute_child_count' => 0,
                'flow_child_count' => 0,
                'sample_nodes' => array(),
            ),
            'sticky_ghosts' => array(
                'count' => count($this->stickyLayoutCoordinator()->stickyGhostCandidates()),
                'candidates' => $this->stickyLayoutCoordinator()->stickyGhostCandidates(),
            ),
        );
        $components = array(
            'schema' => 'blocks-engine/figma-transformer/component-coverage/v1',
            'clone_source_node_count' => 0,
            'emitted_clone_node_count' => 0,
            'override_applied_node_count' => 0,
            'override_candidate_node_count' => 0,
            'missing_emitted_clone_node_count' => 0,
            'intentionally_suppressed_clone_node_count' => 0,
            'omission_reason_counts' => array(),
            'intentional_suppression_reason_counts' => array(),
            'clone_nodes' => array(),
            'override_nodes' => array(),
            'missing_emitted_clone_nodes' => array(),
            'intentionally_suppressed_clone_nodes' => array(),
        );
        $effects = array(
            'schema' => 'blocks-engine/figma-transformer/effect-coverage/v1',
            'source_effect_node_count' => 0,
            'emitted_effect_node_count' => 0,
            'missing_emitted_effect_node_count' => 0,
            'intentionally_suppressed_effect_node_count' => 0,
            'by_type' => array(),
            'field_coverage' => array(),
            'omission_reason_counts' => array(),
            'intentional_suppression_reason_counts' => array(),
            'effect_nodes' => array(),
            'missing_emitted_effect_nodes' => array(),
            'intentionally_suppressed_effect_nodes' => array(),
        );
        $maskEffectClipping = array(
            'schema' => 'blocks-engine/figma-transformer/mask-effect-clipping/v1',
            'mask_node_count' => 0,
            'mask_metadata_node_count' => 0,
            'emitted_mask_source_node_count' => 0,
            'suppressed_mask_source_node_count' => 0,
            'clips_content_node_count' => 0,
            'effect_node_count' => 0,
            'clipped_effect_node_count' => 0,
            'by_mask_type' => array(),
            'sample_nodes' => array(),
            'emitted_mask_source_nodes' => array(),
            'suppressed_mask_source_nodes' => array(),
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->collectTransformDiagnostics($node, $image, $vectors, $layout, $components, $effects, $maskEffectClipping, $html, $css);
            }
        }

        $this->collectComponentCloneEmissionDiagnostics($nodes, $components, $html);

        $image['missing_assets'] = array_values($image['missing_assets']);
        $image['image_block_nodes'] = array_values($image['image_block_nodes']);
        $image['asset_nodes'] = array_slice(array_values($image['asset_nodes']), 0, 50);
        ksort($image['asset_node_reason_categories']);
        $vectors['placeholder_nodes'] = array_values($vectors['placeholder_nodes']);
        $vectors['decode_coverage'] = $this->transformDiagnosticsBuilder()->vectorDecodeCoverage($vectors);
        $layout['decorative_underlays']['nodes'] = array_values($layout['decorative_underlays']['nodes']);
        $layout['decorative_underlays']['count'] = count($layout['decorative_underlays']['nodes']);
        $layout['large_absolute_offset_nodes'] = array_values($layout['large_absolute_offset_nodes']);
        $layout['suppressed_large_absolute_offset_nodes'] = array_values($layout['suppressed_large_absolute_offset_nodes']);
        ksort($layout['suppressed_large_absolute_offset_reason_counts']);
        $layout['empty_visible_containers'] = array_values($layout['empty_visible_containers']);
        $layout['empty_visible_container_count'] = count($layout['empty_visible_containers']);
        $layout['empty_visible_container_blocker_count'] = count(array_filter(
            $layout['empty_visible_containers'],
            static fn (array $container): bool => true === ($container['blocks_parity'] ?? true)
        ));
        ksort($layout['empty_visible_container_categories']);
        $layout['image_heavy_landmark_candidates'] = array_values($layout['image_heavy_landmark_candidates']);
        $layout['stacking_order']['sample_nodes'] = array_slice($layout['stacking_order']['sample_nodes'], 0, 25);
        $components['clone_nodes'] = array_slice($components['clone_nodes'], 0, 25);
        $components['override_nodes'] = array_slice($components['override_nodes'], 0, 25);
        $components['missing_emitted_clone_nodes'] = array_slice($components['missing_emitted_clone_nodes'], 0, 25);
        $components['intentionally_suppressed_clone_nodes'] = array_slice($components['intentionally_suppressed_clone_nodes'], 0, 25);
        ksort($components['omission_reason_counts']);
        ksort($components['intentional_suppression_reason_counts']);
        ksort($effects['field_coverage']);
        $effects['effect_nodes'] = array_slice($effects['effect_nodes'], 0, 25);
        $effects['missing_emitted_effect_nodes'] = array_slice($effects['missing_emitted_effect_nodes'], 0, 25);
        $effects['intentionally_suppressed_effect_nodes'] = array_slice($effects['intentionally_suppressed_effect_nodes'], 0, 25);
        ksort($effects['by_type']);
        ksort($effects['omission_reason_counts']);
        ksort($effects['intentional_suppression_reason_counts']);
        ksort($maskEffectClipping['by_mask_type']);
        $maskEffectClipping['sample_nodes'] = array_slice($maskEffectClipping['sample_nodes'], 0, 25);
        $maskEffectClipping['emitted_mask_source_nodes'] = array_slice($maskEffectClipping['emitted_mask_source_nodes'], 0, 25);
        $maskEffectClipping['suppressed_mask_source_nodes'] = array_slice($maskEffectClipping['suppressed_mask_source_nodes'], 0, 25);
        $generatedSvgAssets = $this->generatedSvgAssetDiagnostics($assetFiles);
        $assets = array(
            'emitted_files' => count($assetFiles),
            'paths'         => array_values(array_map(static fn (array $file): string => (string) ($file['path'] ?? ''), $assetFiles)),
        );
        $fontCss = (string) ($fontResolution['css'] ?? '');
        $fonts = array(
            'families'                => $fontFamilies,
            'usage'                   => $fontUsage,
            'count'                   => count($fontFamilies),
            'css_supplied'            => (bool) ($fontResolution['operator_supplied'] ?? false),
            'materialized'            => '' !== $fontCss,
            'missing_css'             => array_values($fontResolution['unresolved_families'] ?? array()),
            'resolved_css'            => array_values($fontResolution['resolved_families'] ?? array()),
            'cdn_families'            => array_values($fontResolution['cdn_families'] ?? array()),
            'family_overrides_applied' => array_values($fontResolution['family_overrides_applied'] ?? array()),
            'coverage'                => array_values($fontResolution['coverage'] ?? array()),
        );

        $text = $this->textCoverageDiagnostics($nodes, $html);
        $links = $this->linkState->diagnostics();
        $cssDiagnostics = $this->staticHtmlEmissionDiagnostics()->cssDiagnostics($css);
        $htmlArtifactDiagnostics = $this->staticHtmlEmissionDiagnostics()->htmlArtifactDiagnostics($html, $css);
        $responsiveDecisionTraces = $this->breakpointMediaDiffBuilder()->decisionTraces();
        $decisionTraces = $this->decisionTraceDiagnostics($responsiveDecisionTraces);
        $layout['positional_parity'] = $this->staticHtmlEmissionDiagnostics()->positionalParityDiagnostics($layout, $css, $decisionTraces);

        return array(
            'schema' => 'blocks-engine/figma-transformer/transform-diagnostics/v1',
            'selection' => $this->selectionDiagnostics($nodes),
            'visual_node_map_summary' => $this->staticHtmlEmissionDiagnostics()->visualNodeMapSummary($visualNodeMap),
            'images' => $image,
            'vectors' => $vectors,
            'fonts' => $fonts,
            'text' => $text,
            'components' => $components,
            'effects' => $effects,
            'mask_effect_clipping' => $maskEffectClipping,
            'assets' => $assets,
            'generated_svg_assets' => $generatedSvgAssets,
            'layout' => $layout,
            'decision_traces' => $decisionTraces,
            'links' => $links,
            'css' => $cssDiagnostics,
            'html_artifact' => $htmlArtifactDiagnostics,
            'artifact_quality' => $this->transformDiagnosticsBuilder()->artifactQualityDiagnostics($image, $vectors, $fonts, $assets, $generatedSvgAssets, $layout, $links, $text, $components, $effects, $maskEffectClipping, $cssDiagnostics, $htmlArtifactDiagnostics, $responsiveDecisionTraces, $diagnostics, $sourceLossEvidence),
            'diagnostic_codes' => $this->diagnosticCodeCounts($diagnostics),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $responsiveTraces
     * @return array<string, mixed>
     */
    private function decisionTraceDiagnostics(array $responsiveTraces = array()): array
    {
        foreach ( $responsiveTraces as $trace ) {
            if ( is_array($trace) ) {
                $this->recordDecisionTrace(
                    (string) ($trace['domain'] ?? 'responsive_decision'),
                    (string) ($trace['reason_code'] ?? 'responsive_decision'),
                    array(
                        'id' => (string) ($trace['node_id'] ?? ''),
                        'name' => (string) ($trace['name'] ?? ''),
                        'type' => (string) ($trace['type'] ?? ''),
                    ),
                    (string) ($trace['decision'] ?? 'responsive_override'),
                    null,
                    $trace
                );
            }
        }

        return DecisionTraceBuilder::summary($this->decisionTraces);
    }

    /**
     * @return array<string, mixed>
     */
    private function htmlArtifactDiagnostics(string $html, string $css): array
    {
        return $this->staticHtmlEmissionDiagnostics()->htmlArtifactDiagnostics($html, $css);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $evidence
     */
    private function recordDecisionTrace(string $domain, string $reasonCode, array $node, string $decision, ?array $parentNode = null, array $evidence = array()): void
    {
        DecisionTraceBuilder::recordEmitterTrace(
            $this->decisionTraces,
            $domain,
            $reasonCode,
            $node,
            $decision,
            $parentNode,
            $evidence,
            $this->currentPagePath,
            fn (array $traceNode): string => $this->nodeDiagnosticClass($traceNode)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function selectionDiagnostics(array $nodes): array
    {
        $frames = array();
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $frames[] = $this->selectedFrameDiagnostic($node, 'index.html', true);
            }
        }

        return array(
            'schema' => 'blocks-engine/figma-transformer/selection/v1',
            'mode' => count($frames) > 1 ? 'root_nodes' : 'single_root',
            'page_count' => count($frames),
            'selected_frames' => $frames,
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function selectedFrameDiagnostic(array $node, string $path, bool $entrypoint): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $assetReferences = $this->countAssetReferences($node);

        return array_filter(array(
            'frame_id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '',
            'name' => isset($node['name']) && is_scalar($node['name']) ? (string) $node['name'] : '',
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'path' => $path,
            'entrypoint' => $entrypoint,
            'width' => $this->reportNumericValue($box['width'] ?? null),
            'height' => $this->reportNumericValue($box['height'] ?? null),
            'node_count' => $this->countNodes(array($node)),
            'asset_reference_count' => $assetReferences,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function countAssetReferences(array $node): int
    {
        $count = (! empty($this->explicitNodeAssetReferences($node)) || ! empty($this->nodeImagePaints($node))) ? 1 : 0;
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $count += $this->countAssetReferences($child);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<string, mixed>
     */
    private function generatedSvgAssetDiagnostics(array $assetFiles): array
    {
        $assets = array();
        foreach ( $assetFiles as $file ) {
            $sourceId = (string) ($file['source_id'] ?? '');
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? null) || ! str_starts_with($sourceId, 'generated-vector-') ) {
                continue;
            }

            $content = (string) ($file['content'] ?? '');
            $assets[] = array_merge(array(
                'id'        => $sourceId,
                'path'      => (string) ($file['path'] ?? ''),
                'mime_type' => 'image/svg+xml',
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            ), $this->svgAssetMetrics($content));
        }

        usort($assets, static fn (array $a, array $b): int => ((int) $b['bytes'] <=> (int) $a['bytes']) ?: strcmp((string) $a['path'], (string) $b['path']));

        return array(
            'schema' => 'blocks-engine/figma-transformer/generated-svg-assets/v1',
            'threshold_bytes' => self::EXTERNAL_VECTOR_SVG_BYTES,
            'count' => count($assets),
            'bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['bytes'] ?? 0), $assets)),
            'gzip_bytes' => $this->sumNullableAssetMetric($assets, 'gzip_bytes'),
            'path_element_count' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_element_count'] ?? 0), $assets)),
            'path_data_bytes' => array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_bytes'] ?? 0), $assets)),
            'largest_path_data_bytes' => empty($assets) ? 0 : max(array_map(static fn (array $asset): int => (int) ($asset['largest_path_data_bytes'] ?? 0), $assets)),
            'unique_path_data_count' => $this->uniqueAssetPathDataCount($assets),
            'duplicate_path_data_count' => $this->duplicateAssetPathDataCount($assets),
            'paths' => array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)),
            'largest_assets' => array_slice($assets, 0, 10),
            'assets' => $assets,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function sumNullableAssetMetric(array $assets, string $key): ?int
    {
        $sum = 0;
        foreach ( $assets as $asset ) {
            if ( ! array_key_exists($key, $asset) || null === $asset[$key] ) {
                return null;
            }
            $sum += (int) $asset[$key];
        }

        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function uniqueAssetPathDataCount(array $assets): int
    {
        $hashes = array();
        foreach ( $assets as $asset ) {
            foreach ( is_array($asset['path_data_hashes'] ?? null) ? $asset['path_data_hashes'] : array() as $hash ) {
                if ( is_scalar($hash) && '' !== (string) $hash ) {
                    $hashes[(string) $hash] = true;
                }
            }
        }

        return count($hashes);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     */
    private function duplicateAssetPathDataCount(array $assets): int
    {
        $pathDataCount = array_sum(array_map(static fn (array $asset): int => (int) ($asset['path_data_count'] ?? 0), $assets));

        return max(0, $pathDataCount - $this->uniqueAssetPathDataCount($assets));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $image
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $components
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectTransformDiagnostics(array $node, array &$image, array &$vectors, array &$layout, array &$components, array &$effects, array &$maskEffectClipping, string $html, string $css, ?array $parentNode = null, ?string $parentSuppressionReason = null): void
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) ) {
            return;
        }

        ++$image['total_node_count'];

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();

        $suppressionReason = false === ($node['visible'] ?? null)
            ? 'hidden_descendant_suppressed'
            : $this->suppressedLayoutDiagnosticReason($node, $parentSuppressionReason);

        if ( null !== $parentNode ) {
            $offset = $this->largeAbsoluteOffsetDiagnostic($node, $parentNode);
            if ( null !== $offset ) {
                if ( null !== $suppressionReason ) {
                    $offset['suppression_reason'] = $suppressionReason;
                    ++$layout['suppressed_large_absolute_offset_count'];
                    $layout['suppressed_large_absolute_offset_reason_counts'][$suppressionReason] = (int) ($layout['suppressed_large_absolute_offset_reason_counts'][$suppressionReason] ?? 0) + 1;
                    $layout['suppressed_large_absolute_offset_nodes'][] = $offset;
                } else {
                    ++$layout['large_absolute_offset_count'];
                    $layout['large_absolute_offset_nodes'][] = $offset;
                }
            }
        }

        if ( null !== $parentNode && $this->isDecorativeFlexUnderlay($node, $parentNode) ) {
            $layout['decorative_underlays']['nodes'][] = $this->decorativeUnderlayDiagnostic($node, $parentNode);
        }

        $stackingOrder = $this->stackingOrderDiagnostic($node);
        if ( null !== $stackingOrder ) {
            ++$layout['stacking_order']['mixed_positioning_parent_count'];
            $layout['stacking_order']['absolute_child_count'] += (int) ($stackingOrder['absolute_child_count'] ?? 0);
            $layout['stacking_order']['flow_child_count'] += (int) ($stackingOrder['flow_child_count'] ?? 0);
            $layout['stacking_order']['sample_nodes'][] = $stackingOrder;
        }

        $this->collectComponentCoverageDiagnostics($node, $components, $html);
        $this->collectEffectCoverageDiagnostics($node, $effects, $maskEffectClipping, $html, $css);
        $this->collectMaskEffectClippingDiagnostics($node, $maskEffectClipping, $html);

        if ( null !== $parentNode && $this->isMaskOperatorNode($node) ) {
            return;
        }

        $emptyContainer = $this->emptyVisibleContainerDiagnostic($node, $parentNode);
        if ( null !== $emptyContainer ) {
            $layout['empty_visible_containers'][] = $emptyContainer;
            $category = (string) ($emptyContainer['category'] ?? 'empty_visible_container');
            $layout['empty_visible_container_categories'][$category] = (int) ($layout['empty_visible_container_categories'][$category] ?? 0) + 1;
        }

        $landmarkCandidate = $this->imageHeavyLandmarkCandidate($node);
        if ( null !== $landmarkCandidate ) {
            $layout['image_heavy_landmark_candidates'][] = $landmarkCandidate;
        }

        $imagePaints = $this->nodeImagePaints($node);
        if ( ! empty($imagePaints) ) {
            $image['paint_refs'] += count($imagePaints);
        }

        $assetReferences = $this->explicitNodeAssetReferences($node);
        $hasAssetExpectation = ! empty($assetReferences) || ! empty($imagePaints);
        if ( $hasAssetExpectation ) {
            ++$image['node_refs'];
            $assetPath = $this->nodeAssetPath($node);
            $emitted = $this->htmlContainsNodeId($html, (string) ($node['id'] ?? ''));
            $reason = $this->assetNodeEmissionReason($node, $assetPath, $emitted, $parentNode);
            $sourceLossReason = $emitted ? null : $suppressionReason;
            $assetNode = $this->assetCoverageNodeSample($node, $assetReferences, $this->imagePaintReferences($node), $assetPath, $emitted, $reason, $sourceLossReason);
            $image['asset_nodes'][] = $assetNode;
            $image['asset_node_reason_categories'][$reason] = (int) ($image['asset_node_reason_categories'][$reason] ?? 0) + 1;

            if ( null !== $assetPath ) {
                ++$image['resolved_assets'];
                ++$image['image_block_count'];
                $image['image_block_nodes'][] = array(
                    'node_id' => (string) ($node['id'] ?? ''),
                    'name'    => (string) ($node['name'] ?? ''),
                    'type'    => strtoupper((string) ($node['type'] ?? '')),
                    'path'    => $assetPath,
                    'reason'  => $reason,
                );
            } else {
                $image['missing_assets'][] = $assetNode;
            }
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        $booleanComposedChildren = false;
        if ( $this->isUnsupportedVectorType($type) ) {
            if ( 'non_renderable_unsupported_vector_suppressed' === $suppressionReason ) {
                return;
            }
            if ( $this->isNonRenderingVectorLayer($node) ) {
                return;
            }

            ++$vectors['nodes'];
            $this->collectVectorChildCompositionDiagnostics($node, $vectors, $parentNode);
            $vectorSvg = $this->supportedVectorSvg($node, $type, $parentNode);
            if ( null !== $vectorSvg ) {
                ++$vectors['rendered_paths'];
                if ( $this->vectorPathsIncludeNetworkSource($node) ) {
                    ++$vectors['vector_network_decoded'];
                }
                if ( 'BOOLEAN_OPERATION' === $type && ! empty($this->nodeList($node)) ) {
                    ++$vectors['boolean_operations_composed'];
                    $booleanComposedChildren = true;
                }
            } elseif ( null !== $this->nodeAssetPath($node) ) {
                ++$vectors['rendered_asset_fallbacks'];
            } else {
                ++$vectors['placeholders'];
                $placeholder = $this->vectorPlaceholderDiagnostic($node, $type, $parentNode);
                $reason = (string) ($placeholder['reason'] ?? 'unknown');
                $vectors['placeholder_reasons'][$reason] = (int) ($vectors['placeholder_reasons'][$reason] ?? 0) + 1;
                $vectors['placeholder_nodes'][] = $placeholder;
            }
        }

        // A composed boolean operation folds its child geometry into one SVG, so
        // the children are not emitted separately; mirror that here to keep the
        // vector counts aligned with what is actually rendered.
        if ( $booleanComposedChildren ) {
            return;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                if ( $this->isFullyClippedDecorativeChild($child, $node) ) {
                    $this->collectClippedChildOmissionDiagnostics($child, $image, $html, $node);
                    continue;
                }
                $this->collectTransformDiagnostics($child, $image, $vectors, $layout, $components, $effects, $maskEffectClipping, $html, $css, $node, $suppressionReason);
            }
        }
    }

    private function suppressedLayoutDiagnosticReason(array $node, ?string $parentSuppressionReason): ?string
    {
        if ( null !== $parentSuppressionReason ) {
            return $parentSuppressionReason;
        }

        $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';
        if ( '' !== $id && isset($this->suppressedVisualNodeIds[$id]) ) {
            return $this->suppressedVisualNodeIds[$id];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $components
     */
    private function collectComponentCoverageDiagnostics(array $node, array &$components, string $html): void
    {
        $hasOverride = true === ($node['_figma_instance_override_applied'] ?? false);

        if ( $hasOverride || is_array($node['overrides'] ?? null) ) {
            ++$components['override_candidate_node_count'];
        }
        if ( $hasOverride ) {
            ++$components['override_applied_node_count'];
            $components['override_nodes'][] = $this->nodeCoverageSample($node);
        }
    }

    /**
     * Account for every component-source clone node, including subtrees that the
     * emitter intentionally skips before recursive diagnostics can reach them.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, mixed> $components
     */
    private function collectComponentCloneEmissionDiagnostics(array $nodes, array &$components, string $html): void
    {
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->collectComponentCloneEmissionNode($node, $components, $html, null, null);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $components
     */
    private function collectComponentCloneEmissionNode(array $node, array &$components, string $html, ?array $parentNode, ?string $parentOmissionReason): void
    {
        $ownOmissionReason = $this->componentCloneOmissionReason($node, $parentNode, $parentOmissionReason);

        if ( $this->isComponentCloneSourceNode($node) ) {
            ++$components['clone_source_node_count'];
            $sample = $this->componentCloneCoverageSample($node);
            $components['clone_nodes'][] = $sample;

            if ( $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
                ++$components['emitted_clone_node_count'];
            } else {
                $reason = $ownOmissionReason ?? 'unsupported';
                $sample['omission_reason'] = $reason;
                if ( $this->isIntentionalComponentCloneSuppression($reason) ) {
                    ++$components['intentionally_suppressed_clone_node_count'];
                    $components['intentional_suppression_reason_counts'][$reason] = (int) ($components['intentional_suppression_reason_counts'][$reason] ?? 0) + 1;
                    $components['intentionally_suppressed_clone_nodes'][] = $sample;
                } else {
                    ++$components['missing_emitted_clone_node_count'];
                    $components['omission_reason_counts'][$reason] = (int) ($components['omission_reason_counts'][$reason] ?? 0) + 1;
                    $components['missing_emitted_clone_nodes'][] = $sample;
                }
            }
        }

        $childParentOmissionReason = null !== $ownOmissionReason
            ? ($this->isIntentionalComponentCloneSuppression($ownOmissionReason) ? $ownOmissionReason : 'parent-omitted')
            : null;
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->collectComponentCloneEmissionNode($child, $components, $html, $node, $childParentOmissionReason);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isComponentCloneSourceNode(array $node): bool
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        return '' !== $sourceId || $this->hasComponentCloneGeometry($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isComponentSourceDuplicateNode(array $node): bool
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        return '' !== $sourceId && ! $this->hasComponentCloneGeometry($node);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function componentCloneCoverageSample(array $node): array
    {
        $sourceId = isset($node['figma_component_source_id']) && is_scalar($node['figma_component_source_id']) ? (string) $node['figma_component_source_id'] : '';
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? max(0.0, (float) $box['width']) : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? max(0.0, (float) $box['height']) : null;
        return array_filter($this->nodeCoverageSample($node) + array(
            'source_node_id' => $sourceId,
            'component_clone_geometry' => $this->hasComponentCloneGeometry($node),
            'width' => null === $width ? null : $this->reportNumericValue($width),
            'height' => null === $height ? null : $this->reportNumericValue($height),
            'visible_area_px' => null === $width || null === $height ? null : $this->reportNumericValue($width * $height),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function componentCloneOmissionReason(array $node, ?array $parentNode, ?string $parentOmissionReason): ?string
    {
        if ( null !== $parentOmissionReason ) {
            return $parentOmissionReason;
        }
        if ( false === ($node['visible'] ?? null) ) {
            return 'hidden';
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        if ( null !== $width && null !== $height && ($width <= 0.0 || $height <= 0.0) ) {
            return 'zero-area';
        }
        if ( null !== $parentNode && $this->isFullyClippedDecorativeChild($node, $parentNode) ) {
            return 'masked/clipped';
        }
        if ( $this->isInvisibleZeroAreaScaffold($node) ) {
            return 'invisible-zero-area-scaffold';
        }
        if ( $this->isMaskOperatorNode($node) ) {
            return 'mask-source';
        }
        if ( $this->isNonRenderingVectorLayer($node) ) {
            return 'non-rendering-vector-layer';
        }
        if ( null !== $parentNode && $this->isComposedVectorChild($node, $parentNode) ) {
            return 'composed-into-parent';
        }
        if ( null !== $parentNode && $this->formControlComposesChild($parentNode, $node) ) {
            return 'form-control-composed-into-parent';
        }
        if ( $this->isComponentSourceDuplicateNode($node) ) {
            return 'component_source_duplicate';
        }

        $emptyContainer = $this->emptyVisibleContainerDiagnostic($node, $parentNode);
        if ( null !== $emptyContainer && false === ($emptyContainer['blocks_parity'] ?? true) ) {
            return 'decorative-collapsed';
        }

        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( 'COMPONENT' === $type ) {
            return 'component-root-suppressed';
        }

        return null;
    }

    private function isIntentionalComponentCloneSuppression(string $reason): bool
    {
        return in_array($reason, array('hidden', 'zero-area', 'mask-source', 'composed-into-parent', 'form-control-composed-into-parent', 'non-rendering-vector-layer', 'invisible-zero-area-scaffold', 'component_source_duplicate'), true);
    }

    private function formControlComposesChild(array $parent, array $child): bool
    {
        $parentIsControl = ($this->isInputLike($parent) || $this->isTextareaLike($parent)) && $this->hasFormControlAccessoryChildren($parent);
        return $parentIsControl && ($this->isFormControlPlaceholderChild($child) || $this->isInputLike($child, $parent) || $this->isTextareaLike($child, $parent));
    }

    /**
     * Figma exports can include opacity-zero, zero-area helper frames inside image
     * crops/masks. They add DOM/CSS noise but cannot contribute visible pixels.
     *
     * @param array<string, mixed> $node
     */
    private function isInvisibleZeroAreaScaffold(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( in_array($type, array('TEXT', 'VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        if ( null === $width || null === $height || ($width > 0.5 && $height > 0.5) ) {
            return false;
        }

        $figmaBox = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        $opacity = $figmaBox['opacity'] ?? $node['opacity'] ?? null;
        if ( ! is_numeric($opacity) || (float) $opacity > 0.001 ) {
            return false;
        }

        return '' === trim($this->subtreePlainText($node));
    }

    /**
     * Figma can include structural vector layers whose own paints are explicitly
     * invisible because the visible output is supplied by a sibling mask/fill layer.
     * Treat those layers like source scaffolding, not failed vector output.
     *
     * @param array<string, mixed> $node
     */
    private function isNonRenderingVectorLayer(array $node): bool
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! $this->isUnsupportedVectorType($type) || $this->isMaskOperatorNode($node) ) {
            return false;
        }

        return $this->hasExplicitInvisiblePaintCollections($node) && ! $this->hasVisiblePaintCollection($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasExplicitInvisiblePaintCollections(array $node): bool
    {
        $foundPaint = false;
        foreach ( array('fillPaints', 'fills', 'strokePaints', 'strokes') as $key ) {
            if ( ! is_array($node[$key] ?? null) ) {
                continue;
            }

            foreach ( $node[$key] as $paint ) {
                if ( ! is_array($paint) ) {
                    continue;
                }
                $foundPaint = true;
                if ( false !== ($paint['visible'] ?? true) && ((isset($paint['opacity']) && is_numeric($paint['opacity'])) ? (float) $paint['opacity'] > 0.0 : true) ) {
                    return false;
                }
            }
        }

        return $foundPaint;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasVisiblePaintCollection(array $node): bool
    {
        foreach ( array('fills', 'strokes', 'background') as $key ) {
            $paints = is_array($node['figma_paints'][$key] ?? null) ? $node['figma_paints'][$key] : array();
            foreach ( $paints as $paint ) {
                if ( is_array($paint) && false !== ($paint['visible'] ?? true) && ((isset($paint['opacity']) && is_numeric($paint['opacity'])) ? (float) $paint['opacity'] > 0.0 : true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $effects
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectEffectCoverageDiagnostics(array $node, array &$effects, array &$maskEffectClipping, string $html, string $css): void
    {
        $nodeEffects = is_array($node['figma_effects'] ?? null) ? $node['figma_effects'] : array();
        if ( empty($nodeEffects) ) {
            return;
        }

        ++$effects['source_effect_node_count'];
        ++$maskEffectClipping['effect_node_count'];
        foreach ( $nodeEffects as $effect ) {
            if ( ! is_array($effect) ) {
                continue;
            }
            $type = (string) ($effect['type'] ?? 'unknown');
            $effects['by_type'][$type] = (int) ($effects['by_type'][$type] ?? 0) + 1;
            foreach ( array('source_type', 'offset_x', 'offset_y', 'radius', 'spread', 'color', 'opacity', 'visible', 'blend_mode', 'show_shadow_behind_node') as $field ) {
                if ( array_key_exists($field, $effect) ) {
                    $effects['field_coverage'][$field] = (int) ($effects['field_coverage'][$field] ?? 0) + 1;
                }
            }
        }

        $sample = $this->nodeCoverageSample($node);
        $sample['effect_types'] = array_values(array_map(
            static fn (array $effect): string => (string) ($effect['type'] ?? 'unknown'),
            array_filter($nodeEffects, 'is_array')
        ));
        $effects['effect_nodes'][] = $sample;

        $class = $this->nodeDiagnosticClass($node);
        $hasEffectCss = str_contains($css, '.' . $class . '{') && preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*(?:box-shadow|text-shadow|filter|backdrop-filter):/s', $css);
        if ( $hasEffectCss ) {
            ++$effects['emitted_effect_node_count'];
            return;
        }

        $reason = $this->effectOmissionReason($node);
        $sample['reason'] = $reason;
        if ( $this->isIntentionalEffectSuppression($reason) ) {
            ++$effects['intentionally_suppressed_effect_node_count'];
            $effects['intentional_suppression_reason_counts'][$reason] = (int) ($effects['intentional_suppression_reason_counts'][$reason] ?? 0) + 1;
            $effects['intentionally_suppressed_effect_nodes'][] = $sample;
            return;
        }

        ++$effects['missing_emitted_effect_node_count'];
        $effects['omission_reason_counts'][$reason] = (int) ($effects['omission_reason_counts'][$reason] ?? 0) + 1;
        $effects['missing_emitted_effect_nodes'][] = $sample;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function effectOmissionReason(array $node): string
    {
        if ( false === ($node['visible'] ?? null) ) {
            return 'hidden';
        }
        if ( $this->nodeHasZeroArea($node) ) {
            return 'zero_area';
        }
        if ( $this->isMaskOperatorNode($node) ) {
            return 'mask-source';
        }
        if ( $this->isComponentSourceDuplicateNode($node) ) {
            return 'component_source_duplicate';
        }

        return 'not_emitted';
    }

    private function isIntentionalEffectSuppression(string $reason): bool
    {
        return in_array($reason, array('hidden', 'zero_area', 'mask-source', 'component_source_duplicate'), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $maskEffectClipping
     */
    private function collectMaskEffectClippingDiagnostics(array $node, array &$maskEffectClipping, string $html): void
    {
        $mask = is_array($node['figma_mask'] ?? null) ? $node['figma_mask'] : array();
        if ( ! empty($mask) ) {
            ++$maskEffectClipping['mask_metadata_node_count'];
            $maskSample = $this->nodeCoverageSample($node);
            foreach ( array('is_mask', 'type', 'frame_mask_disabled', 'is_clip') as $field ) {
                if ( array_key_exists($field, $mask) ) {
                    $maskSample[$field] = $mask[$field];
                }
            }
            if ( isset($mask['type']) && is_scalar($mask['type']) ) {
                $maskType = (string) $mask['type'];
                $maskEffectClipping['by_mask_type'][$maskType] = (int) ($maskEffectClipping['by_mask_type'][$maskType] ?? 0) + 1;
            }
            $maskEffectClipping['sample_nodes'][] = $maskSample;
        }
        if ( $this->isMaskOperatorNode($node) ) {
            ++$maskEffectClipping['mask_node_count'];
            if ( $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
                ++$maskEffectClipping['emitted_mask_source_node_count'];
                $maskEffectClipping['emitted_mask_source_nodes'][] = $maskSample ?? $this->nodeCoverageSample($node);
            } else {
                ++$maskEffectClipping['suppressed_mask_source_node_count'];
                $maskEffectClipping['suppressed_mask_source_nodes'][] = $maskSample ?? $this->nodeCoverageSample($node);
            }
        }
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( true === ($layout['clips_content'] ?? false) ) {
            ++$maskEffectClipping['clips_content_node_count'];
        }
        if ( ! empty($node['figma_effects']) && true === ($layout['clips_content'] ?? false) ) {
            ++$maskEffectClipping['clipped_effect_node_count'];
            $maskEffectClipping['sample_nodes'][] = $this->nodeCoverageSample($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $vectors
     */
    private function collectVectorChildCompositionDiagnostics(array $node, array &$vectors, ?array $parentNode): void
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        $vectorChildren = array_filter($children, fn (array $child): bool => $this->isUnsupportedVectorType(strtoupper((string) ($child['type'] ?? ''))));
        if ( empty($vectorChildren) ) {
            return;
        }

        ++$vectors['child_composition']['vector_parent_node_count'];
        $vectors['child_composition']['vector_child_node_count'] += count($vectorChildren);
        if ( null !== $this->supportedVectorSvg($node, strtoupper((string) ($node['type'] ?? '')), $parentNode) ) {
            ++$vectors['child_composition']['composed_parent_node_count'];
            return;
        }

        ++$vectors['child_composition']['uncomposed_parent_node_count'];
        $vectors['child_composition']['uncomposed_vector_child_node_count'] += count($vectorChildren);
        $sample = $this->nodeCoverageSample($node);
        $sample['vector_child_count'] = count($vectorChildren);
        $vectors['child_composition']['sample_nodes'][] = $sample;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isMaskOperatorNode(array $node): bool
    {
        return $this->childLayerCompositionResolver()->isMaskOperatorNode($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isComposedVectorChild(array $node, array $parentNode): bool
    {
        if ( ! $this->isUnsupportedVectorType(strtoupper((string) ($node['type'] ?? ''))) ) {
            return false;
        }

        $parentType = strtoupper((string) ($parentNode['type'] ?? ''));
        return $this->isUnsupportedVectorType($parentType) && null !== $this->supportedVectorSvg($parentNode, $parentType, null);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function stackingOrderDiagnostic(array $node): ?array
    {
        $children = array_values(array_filter($this->nodeList($node), 'is_array'));
        if ( count($children) < 2 ) {
            return null;
        }
        $absolute = 0;
        $flow = 0;
        $childLayerRoles = array();
        $childZIndexReasons = array();
        foreach ( $children as $child ) {
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($layout['positioning'] ?? null) ) {
                ++$absolute;
            } else {
                ++$flow;
            }

            $stackingContextPlan = $this->layoutIntentClassifier()->stackingContextPlan($child, $node);
            $role = is_string($stackingContextPlan['sibling_role'] ?? null) ? $stackingContextPlan['sibling_role'] : $this->layoutIntentClassifier()->siblingLayerRole($child, $node);
            $childLayerRoles[$role] = (int) ($childLayerRoles[$role] ?? 0) + 1;
            $zIndexReason = is_string($stackingContextPlan['z_index_reason'] ?? null) ? $stackingContextPlan['z_index_reason'] : null;
            if ( null !== $zIndexReason ) {
                $childZIndexReasons[$zIndexReason] = (int) ($childZIndexReasons[$zIndexReason] ?? 0) + 1;
            }
        }
        if ( 0 === $absolute || 0 === $flow ) {
            return null;
        }
        ksort($childLayerRoles);
        ksort($childZIndexReasons);
        $stackingContextPlan = $this->layoutIntentClassifier()->stackingContextPlan($node);

        return array_merge($this->nodeCoverageSample($node), array(
            'absolute_child_count' => $absolute,
            'flow_child_count' => $flow,
            'child_layer_roles' => $childLayerRoles,
            'child_z_index_reasons' => $childZIndexReasons,
            'local_stacking_reasons' => is_array($stackingContextPlan['local_reasons'] ?? null) ? $stackingContextPlan['local_reasons'] : array(),
        ));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function nodeCoverageSample(array $node): array
    {
        return $this->diagnosticNodeSample($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $assetReferences
     * @param array<int, string> $paintReferences
     * @return array<string, mixed>
     */
    private function assetCoverageNodeSample(array $node, array $assetReferences, array $paintReferences, ?string $assetPath, bool $emitted, string $reason, ?string $sourceLossReason = null): array
    {
        return array_filter(array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
            'emitted' => $emitted,
            'reason' => $reason,
            'source_loss_reason' => $sourceLossReason,
            'path' => $assetPath,
            'refs' => array_values(array_unique(array_merge($assetReferences, $paintReferences))),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function assetNodeEmissionReason(array $node, ?string $assetPath, bool $emitted, ?array $parentNode = null): string
    {
        if ( false === ($node['visible'] ?? true) ) {
            return 'hidden';
        }
        if ( null !== $parentNode && $this->isFullyClippedDecorativeChild($node, $parentNode) ) {
            return 'clipped_masked';
        }
        if ( null !== $parentNode && false === ($parentNode['visible'] ?? true) ) {
            return 'hidden_parent';
        }
        if ( $this->nodeHasZeroArea($node) ) {
            return 'zero_area';
        }
        if ( null === $assetPath ) {
            $references = array_merge($this->explicitNodeAssetReferences($node), $this->imagePaintReferences($node));
            if ( empty($references) ) {
                return 'no_archive_asset_hash';
            }

            return $this->assetUnavailableReasonForReferences($references) ?? 'no_archive_asset';
        }
        if ( $emitted ) {
            return 'converted_to_background';
        }

        return null !== $parentNode ? 'parent_omitted' : 'not_emitted';
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $image
     * @param array<string, mixed> $parentNode
     */
    private function collectClippedChildOmissionDiagnostics(array $node, array &$image, string $html, array $parentNode): void
    {
        $imagePaints = $this->nodeImagePaints($node);
        $assetReferences = $this->explicitNodeAssetReferences($node);
        if ( ! empty($assetReferences) || ! empty($imagePaints) ) {
            ++$image['node_refs'];
            $assetPath = $this->nodeAssetPath($node);
            $reason = $this->assetNodeEmissionReason($node, $assetPath, $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')), $parentNode);
            $assetNode = $this->assetCoverageNodeSample($node, $assetReferences, $this->imagePaintReferences($node), $assetPath, false, $reason, 'clipped_masked');
            $image['asset_nodes'][] = $assetNode;
            $image['asset_node_reason_categories'][$reason] = (int) ($image['asset_node_reason_categories'][$reason] ?? 0) + 1;
            if ( null === $assetPath ) {
                $image['missing_assets'][] = $assetNode;
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasZeroArea(array $node): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;

        return null !== $width && null !== $height && ($width <= 0.0 || $height <= 0.0);
    }

    /**
     * Whether a node's decoded vector geometry originates from a raw Figma
     * vectorNetwork blob, used to credit network-decode coverage distinctly
     * from ready-made path/command-blob geometry.
     *
     * @param array<string, mixed> $node
     */
    private function vectorPathsIncludeNetworkSource(array $node): bool
    {
        if ( ! is_array($node['figma_vector_paths'] ?? null) ) {
            return false;
        }

        foreach ( $node['figma_vector_paths'] as $path ) {
            if ( is_array($path) && str_starts_with((string) ($path['source'] ?? ''), 'vectorData.vectorNetworkBlob') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<string, mixed>|null
     */
    private function largeAbsoluteOffsetDiagnostic(array $node, array $parentNode): ?array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( $this->isDecorativeFlexUnderlay($node, $parentNode) || ('absolute' !== ($layout['positioning'] ?? null) && ! $this->isFreeformContainer($parentNode)) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $offsets = $this->cssPositioningResolver()->effectiveOffsets($box, $parentNode, $node);
        $left = $offsets['x'];
        $top = $offsets['y'];
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;
        $parentWidth = isset($parentBox['width']) && is_numeric($parentBox['width']) ? (float) $parentBox['width'] : null;
        $parentHeight = isset($parentBox['height']) && is_numeric($parentBox['height']) ? (float) $parentBox['height'] : null;
        $offCanvas = (null !== $left && ($left < -100.0 || (null !== $parentWidth && $left > $parentWidth + 100.0) || $left + $width < -100.0))
            || (null !== $top && ($top < -100.0 || (null !== $parentHeight && $top > $parentHeight + 100.0) || $top + $height < -100.0));

        if ( ! $offCanvas ) {
            return null;
        }

        $sample = $this->diagnosticGeometrySample(
            $this->diagnosticNodeSample($node) + array('parent_id' => (string) ($parentNode['id'] ?? '')),
            array('x' => $left, 'y' => $top, 'width' => $width, 'height' => $height),
            array('x' => 0.0, 'y' => 0.0, 'width' => $parentWidth, 'height' => $parentHeight)
        );
        $this->applyDiagnosticReason($sample, $this->largeGeometryIntentClassification($node, array(), $parentNode), 'large_absolute_offset');

        return $sample;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array{nodes: array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>}, emitted_css: array<string, array<string, float>>, visual_by_id: array<string, array<string, mixed>>, visual_by_class: array<string, array<string, mixed>>}
     */
    private function diagnosticGeometryIndexes(array $nodes, array $visualNodeMap, string $css): array
    {
        return array(
            'nodes' => $this->nodeDiagnosticIndex($nodes),
            'emitted_css' => $this->cssBaseGeometryIndex($css),
            'visual_by_id' => $this->visualNodeMapIndex($visualNodeMap),
            'visual_by_class' => $this->visualNodeMapClassIndex($visualNodeMap),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>}
     */
    private function nodeDiagnosticIndex(array $nodes): array
    {
        $index = array('by_id' => array(), 'by_class' => array());
        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $this->appendNodeDiagnosticIndex($node, $index);
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $index
     */
    private function appendNodeDiagnosticIndex(array $node, array &$index): void
    {
        $entry = $this->diagnosticNodeSample($node) + array(
            'empty_visible_container' => null !== $this->emptyVisibleContainerDiagnostic($node),
            'component_clone_geometry' => $this->hasComponentCloneGeometry($node),
        );
        if ( '' !== $entry['node_id'] ) {
            $index['by_id'][$entry['node_id']] = $entry;
        }
        if ( '' !== $entry['class'] ) {
            $index['by_class'][$entry['class']] = $entry;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendNodeDiagnosticIndex($child, $index);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeDiagnosticClass(array $node): string
    {
        return 'figma-node-' . $this->slug((string) ($node['id'] ?? '') . '-' . (string) ($node['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function diagnosticNodeSample(array $node): array
    {
        return array_filter(array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, array<string, mixed>>
     */
    private function visualNodeMapIndex(array $visualNodeMap): array
    {
        $byId = array();
        foreach ( $visualNodeMap as $entry ) {
            if ( is_array($entry) && isset($entry['id']) && is_scalar($entry['id']) ) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        return $byId;
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @return array<string, array<string, mixed>>
     */
    private function visualNodeMapClassIndex(array $visualNodeMap): array
    {
        $byClass = array();
        foreach ( $visualNodeMap as $entry ) {
            if ( is_array($entry) && isset($entry['emitted_class']) && is_scalar($entry['emitted_class']) ) {
                $byClass[(string) $entry['emitted_class']] = $entry;
            }
        }

        return $byClass;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $rect
     * @param array<string, mixed> $parentRect
     * @param array<string, mixed>|null $sourceRect
     * @return array<string, mixed>
     */
    private function diagnosticGeometrySample(array $base, array $rect, array $parentRect, ?array $sourceRect = null, string $geometrySource = ''): array
    {
        $sample = array_filter($base + array(
            'left' => $this->diagnosticRectDelta($rect, $parentRect, 'x'),
            'top' => $this->diagnosticRectDelta($rect, $parentRect, 'y'),
            'x' => $this->diagnosticRectValue($rect, 'x'),
            'y' => $this->diagnosticRectValue($rect, 'y'),
            'width' => $this->diagnosticRectValue($rect, 'width'),
            'height' => $this->diagnosticRectValue($rect, 'height'),
            'parent_width' => $this->diagnosticRectValue($parentRect, 'width'),
            'parent_height' => $this->diagnosticRectValue($parentRect, 'height'),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);

        if ( '' !== $geometrySource ) {
            $sample['geometry_source'] = $geometrySource;
        }
        if ( null !== $sourceRect ) {
            $sample['source_left'] = $this->diagnosticRectDelta($sourceRect, $parentRect, 'x');
            $sample['source_top'] = $this->diagnosticRectDelta($sourceRect, $parentRect, 'y');
        }

        return array_filter($sample, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $rect
     */
    private function diagnosticRectValue(array $rect, string $key): mixed
    {
        return isset($rect[$key]) && is_numeric($rect[$key]) ? $this->reportNumericValue((float) $rect[$key]) : null;
    }

    /**
     * @param array<string, mixed> $rect
     * @param array<string, mixed> $parentRect
     */
    private function diagnosticRectDelta(array $rect, array $parentRect, string $key): mixed
    {
        if ( ! isset($rect[$key], $parentRect[$key]) || ! is_numeric($rect[$key]) || ! is_numeric($parentRect[$key]) ) {
            return null;
        }

        return $this->reportNumericValue((float) $rect[$key] - (float) $parentRect[$key]);
    }

    /**
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @return array<int, array<string, mixed>>
     */
    private function cssAbsoluteOffsetDiagnostics(string $css, array $nodeIndex, array $visualNodeMapByClass = array(), array $visualNodeMapById = array()): array
    {
        $samples = array();
        if ( ! preg_match_all('/\.(figma-node-[A-Za-z0-9_-]+)\{([^}]*)\}/s', $css, $rules, PREG_SET_ORDER) ) {
            return $samples;
        }

        foreach ( $rules as $rule ) {
            $className = (string) ($rule[1] ?? '');
            $body = (string) ($rule[2] ?? '');
            if ( $this->isDecorativeHairlineOffsetRule($body) ) {
                continue;
            }
            $left = $this->cssPixelDeclarationValue($body, 'left');
            $top = $this->cssPixelDeclarationValue($body, 'top');
            if ( (null === $left || abs($left) < 1000.0) && (null === $top || abs($top) < 1000.0) ) {
                continue;
            }

            $node = is_array($nodeIndex['by_class'][$className] ?? null) ? $nodeIndex['by_class'][$className] : array();
            $visualEntry = is_array($visualNodeMapByClass[$className] ?? null) ? $visualNodeMapByClass[$className] : array();
            if ( $this->isContainedCssOffset($className, $visualNodeMapByClass, $visualNodeMapById) ) {
                continue;
            }
            if ( $this->isPlausibleInCanvasCssOffset($left, $top, $body) ) {
                continue;
            }
            $sample = array_filter(array(
                'node_id' => (string) ($node['node_id'] ?? ''),
                'name' => (string) ($node['name'] ?? ''),
                'type' => (string) ($node['type'] ?? ''),
                'class' => $className,
                'left' => null === $left ? null : $this->reportNumericValue($left),
                'top' => null === $top ? null : $this->reportNumericValue($top),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
            $classification = $this->largeCssOffsetClassification($node, $visualEntry);
            if ( '' === $classification && $this->hasCssBackgroundImageEvidence($body) ) {
                $classification = 'intended_image_crop_bleed';
            }
            $this->applyDiagnosticReason($sample, $classification, 'large_css_offset');
            $samples[] = $sample;
        }

        return array_values($samples);
    }

    private function isPlausibleInCanvasCssOffset(?float $left, ?float $top, string $body): bool
    {
        if ( null !== $left && $left < 0.0 ) {
            return false;
        }
        if ( null !== $top && $top < 0.0 ) {
            return false;
        }

        $width = $this->cssPixelDeclarationValue($body, 'width');
        if ( null !== $left && $left > 1440.0 ) {
            return false;
        }
        if ( null !== $left && null !== $width && ($left + $width) > 2560.0 ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $visualNodeMapByClass
     * @param array<string, array<string, mixed>> $visualNodeMapById
     */
    private function isContainedCssOffset(string $className, array $visualNodeMapByClass, array $visualNodeMapById): bool
    {
        $entry = is_array($visualNodeMapByClass[$className] ?? null) ? $visualNodeMapByClass[$className] : array();
        $parentId = isset($entry['parent_id']) && is_scalar($entry['parent_id']) ? (string) $entry['parent_id'] : '';
        $parent = '' !== $parentId && is_array($visualNodeMapById[$parentId] ?? null) ? $visualNodeMapById[$parentId] : array();
        $rect = is_array($entry['rect'] ?? null) ? $entry['rect'] : array();
        $parentRect = is_array($parent['rect'] ?? null) ? $parent['rect'] : array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            if ( ! is_numeric($rect[$key] ?? null) || ! is_numeric($parentRect[$key] ?? null) ) {
                return false;
            }
        }

        $epsilon = 0.5;
        $rectLeft = (float) $rect['x'];
        $rectRight = $rectLeft + (float) $rect['width'];
        $parentLeft = (float) $parentRect['x'];
        $parentRight = $parentLeft + (float) $parentRect['width'];
        $rectTop = (float) $rect['y'];
        $rectBottom = $rectTop + (float) $rect['height'];
        $parentTop = (float) $parentRect['y'];
        $parentBottom = $parentTop + (float) $parentRect['height'];

        return $rectLeft >= $parentLeft - $epsilon
            && $rectRight <= $parentRight + $epsilon
            && $rectTop >= $parentTop - $epsilon
            && $rectBottom <= $parentBottom + $epsilon;
    }

    private function isDecorativeHairlineOffsetRule(string $body): bool
    {
        $height = $this->cssPixelDeclarationValue($body, 'height');
        $left = $this->cssPixelDeclarationValue($body, 'left');
        if ( null === $height || abs($height) > 1.0 || null === $left || abs($left) > 0.5 ) {
            return false;
        }

        return $this->cssDeclarationValue($body, 'position') === 'absolute';
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function cssBaseGeometryIndex(string $css): array
    {
        $baseCss = preg_split('/@media\\b/', $css, 2)[0] ?? $css;
        if ( ! preg_match_all('/\\.(figma-node-[A-Za-z0-9_-]+)\\{([^}]*)\\}/s', $baseCss, $rules, PREG_SET_ORDER) ) {
            return array();
        }

        $index = array();
        foreach ( $rules as $rule ) {
            $className = (string) ($rule[1] ?? '');
            $body = (string) ($rule[2] ?? '');
            $geometry = array();
            foreach ( array('left', 'top', 'width', 'height') as $property ) {
                $value = $this->cssPixelDeclarationValue($body, $property);
                if ( null !== $value ) {
                    $geometry[$property] = $value;
                }
            }
            if ( array() !== $geometry ) {
                $index[$className] = $geometry;
            }
        }

        return $index;
    }

    private function cssPixelDeclarationValue(string $body, string $property): ?float
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . ':\s*(-?\d+(?:\.\d+)?)px(?:;|$)/', $body, $match)
            ? (float) $match[1]
            : null;
    }

    private function cssDeclarationValue(string $body, string $property): ?string
    {
        return preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . ':\s*([^;]+)(?:;|$)/', $body, $match)
            ? trim((string) $match[1])
            : null;
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @param array<string, array<string, float>> $cssGeometryIndex
     * @return array<int, array<string, mixed>>
     */
    private function visualOffCanvasDiagnostics(array $visualNodeMap, array $visualNodeMapById, array $nodeIndex, array $cssGeometryIndex): array
    {
        $samples = array();
        foreach ( $visualNodeMap as $entry ) {
            if ( ! is_array($entry) || ! isset($entry['parent_id']) || '' === (string) $entry['parent_id'] || ! is_array($entry['rect'] ?? null) ) {
                continue;
            }
            $parent = $visualNodeMapById[(string) $entry['parent_id']] ?? null;
            if ( ! is_array($parent) || ! is_array($parent['rect'] ?? null) ) {
                continue;
            }
            $rect = $entry['rect'];
            $parentRect = $parent['rect'];
            foreach ( array('x', 'y', 'width', 'height') as $key ) {
                if ( ! is_numeric($rect[$key] ?? null) || ! is_numeric($parentRect[$key] ?? null) ) {
                    continue 2;
                }
            }

            $node = is_array($nodeIndex['by_id'][(string) ($entry['id'] ?? '')] ?? null) ? $nodeIndex['by_id'][(string) $entry['id']] : array();
            $className = isset($entry['emitted_class']) && is_scalar($entry['emitted_class']) ? (string) $entry['emitted_class'] : (string) ($node['class'] ?? '');
            $cssGeometry = '' !== $className && is_array($cssGeometryIndex[$className] ?? null) ? $cssGeometryIndex[$className] : array();
            $sourceRect = $rect;
            if ( array() !== $cssGeometry ) {
                if ( isset($cssGeometry['left']) ) {
                    $rect['x'] = (float) $parentRect['x'] + (float) $cssGeometry['left'];
                }
                if ( isset($cssGeometry['top']) ) {
                    $rect['y'] = (float) $parentRect['y'] + (float) $cssGeometry['top'];
                }
                if ( isset($cssGeometry['width']) ) {
                    $rect['width'] = (float) $cssGeometry['width'];
                }
                if ( isset($cssGeometry['height']) ) {
                    $rect['height'] = (float) $cssGeometry['height'];
                }
            }

            $offCanvas = (float) $rect['x'] < (float) $parentRect['x'] - 100.0
                || (float) $rect['x'] > (float) $parentRect['x'] + (float) $parentRect['width'] + 100.0
                || (float) $rect['x'] + (float) $rect['width'] < (float) $parentRect['x'] - 100.0
                || (float) $rect['y'] < (float) $parentRect['y'] - 100.0
                || (float) $rect['y'] > (float) $parentRect['y'] + (float) $parentRect['height'] + 100.0
                || (float) $rect['y'] + (float) $rect['height'] < (float) $parentRect['y'] - 100.0;
            if ( ! $offCanvas ) {
                continue;
            }

            $sample = $this->diagnosticGeometrySample(
                array(
                    'node_id' => (string) ($entry['id'] ?? ''),
                    'name' => (string) ($entry['name'] ?? ($node['name'] ?? '')),
                    'type' => (string) ($entry['type'] ?? ($node['type'] ?? '')),
                    'class' => $className,
                    'parent_id' => (string) ($entry['parent_id'] ?? ''),
                ),
                $rect,
                $parentRect,
                array() !== $cssGeometry ? $sourceRect : null,
                array() !== $cssGeometry ? 'emitted_css' : ''
            );
            $this->applyDiagnosticReason($sample, $this->visualOffCanvasClassification($entry, $parent), 'off_canvas_visual_node');
            $samples[] = $sample;
        }

        return array_values($samples);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function largeCssOffsetClassification(array $node, array $visualEntry = array()): string
    {
        if ( true === ($node['component_clone_geometry'] ?? false) ) {
            return 'component_clone_geometry_leak';
        }

        if ( true === ($node['empty_visible_container'] ?? false) ) {
            return 'empty_visible_container';
        }

        $intent = $this->largeGeometryIntentClassification($node, $visualEntry);
        if ( '' !== $intent ) {
            return $intent;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $sample
     */
    private function applyDiagnosticReason(array &$sample, string $classification, string $fallbackReason): void
    {
        if ( '' !== $classification ) {
            $sample['classification'] = $classification;
        }
        $sample['reason_code'] = '' !== $classification ? $classification : $fallbackReason;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $parent
     */
    private function visualOffCanvasClassification(array $entry, array $parent): string
    {
        $parentLayout = is_array($parent['layout'] ?? null) ? $parent['layout'] : array();
        $entryLayout = is_array($entry['layout'] ?? null) ? $entry['layout'] : array();
        if ( in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true) && 'absolute' !== ($entryLayout['positioning'] ?? null) ) {
            return 'flex_flow_overflow';
        }

        if ( true === ($entry['component_clone_geometry'] ?? false) ) {
            return 'component_clone_geometry_leak';
        }

        $intent = $this->largeGeometryIntentClassification($entry, $entry, $parent);
        if ( '' !== $intent ) {
            return $intent;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $visualEntry
     * @param array<string, mixed>|null $parent
     */
    private function largeGeometryIntentClassification(array $node, array $visualEntry = array(), ?array $parent = null): string
    {
        if ( $this->hasBackgroundArtNameHint($node) || $this->hasBackgroundArtNameHint($visualEntry) ) {
            return 'intended_background_bleed';
        }

        if ( $this->hasImagePaintEvidence($node) || $this->hasImagePaintEvidence($visualEntry) ) {
            return 'intended_image_crop_bleed';
        }

        if ( $this->isClippedDecorativeGeometry($node, $visualEntry, $parent) ) {
            return 'intended_clipped_decorative_art';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasBackgroundArtNameHint(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        if ( '' === $name ) {
            return false;
        }

        foreach ( array('background', 'bg ', 'bg-', 'bg_', 'gradient', 'underlay', 'artwork', 'illustration', 'blob') as $hint ) {
            if ( str_contains($name, $hint) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasImagePaintEvidence(array $node): bool
    {
        if ( is_array($node['image'] ?? null) ) {
            return true;
        }

        $paints = is_array($node['paints'] ?? null) ? $node['paints'] : array();
        foreach ( array('fills', 'background') as $paintKey ) {
            foreach ( is_array($paints[$paintKey] ?? null) ? $paints[$paintKey] : array() as $paint ) {
                if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                    return true;
                }
            }
        }

        foreach ( array('fills', 'background') as $paintKey ) {
            foreach ( is_array($node[$paintKey] ?? null) ? $node[$paintKey] : array() as $paint ) {
                if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasCssBackgroundImageEvidence(string $cssBody): bool
    {
        $backgroundImage = $this->cssDeclarationValue($cssBody, 'background-image');

        return null !== $backgroundImage && str_contains(strtolower($backgroundImage), 'url(');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $visualEntry
     * @param array<string, mixed>|null $parent
     */
    private function isClippedDecorativeGeometry(array $node, array $visualEntry, ?array $parent): bool
    {
        $clip = is_array($visualEntry['clip'] ?? null) ? $visualEntry['clip'] : array();
        $parentLayout = is_array($parent['layout'] ?? null) ? $parent['layout'] : array();
        $isClipped = 'parent_clips_content' === ($clip['source'] ?? null) || true === ($parentLayout['clips_content'] ?? false);
        if ( ! $isClipped ) {
            return false;
        }

        if ( $this->hasBackgroundArtNameHint($node) || $this->hasBackgroundArtNameHint($visualEntry) || $this->hasImagePaintEvidence($node) || $this->hasImagePaintEvidence($visualEntry) ) {
            return true;
        }

        $type = strtoupper((string) ($visualEntry['type'] ?? ($node['type'] ?? '')));
        return in_array($type, array('VECTOR', 'RECTANGLE', 'ROUNDED_RECTANGLE', 'ELLIPSE', 'BOOLEAN_OPERATION'), true)
            && '' === trim((string) ($visualEntry['text']['characters'] ?? $node['characters'] ?? ''));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasComponentCloneGeometry(array $node): bool
    {
        if ( true === ($node['_component_source_clone_geometry'] ?? false) ) {
            return true;
        }

        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( 'component_source_clone' === ($box['geometry_semantics'] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $visualNodeMap
     * @param array{by_id: array<string, array<string, mixed>>, by_class: array<string, array<string, mixed>>} $nodeIndex
     * @return array<string, mixed>
     */
    private function visualClipDiagnostics(array $visualNodeMap, array $nodeIndex): array
    {
        $samples = array();
        $sourceArea = 0.0;
        $visibleArea = 0.0;
        $visualNodeMapById = $this->visualNodeMapIndex($visualNodeMap);

        foreach ( $visualNodeMap as $entry ) {
            if ( ! is_array($entry) || ! is_array($entry['rect'] ?? null) || ! is_array($entry['visible_rect'] ?? null) ) {
                continue;
            }

            $rect = $entry['rect'];
            $visibleRect = $entry['visible_rect'];
            foreach ( array('width', 'height') as $key ) {
                if ( ! is_numeric($rect[$key] ?? null) || ! is_numeric($visibleRect[$key] ?? null) ) {
                    continue 2;
                }
            }

            $entryArea = max(0.0, (float) $rect['width']) * max(0.0, (float) $rect['height']);
            $entryVisibleArea = max(0.0, (float) $visibleRect['width']) * max(0.0, (float) $visibleRect['height']);
            if ( $entryArea <= 0.0 || $entryVisibleArea >= $entryArea ) {
                continue;
            }

            $sourceArea += $entryArea;
            $visibleArea += $entryVisibleArea;
            $node = is_array($nodeIndex['by_id'][(string) ($entry['id'] ?? '')] ?? null) ? $nodeIndex['by_id'][(string) $entry['id']] : array();
            $parentId = isset($entry['parent_id']) && is_scalar($entry['parent_id']) ? (string) $entry['parent_id'] : '';
            $parent = '' !== $parentId && is_array($visualNodeMapById[$parentId] ?? null) ? $visualNodeMapById[$parentId] : null;
            $sample = array_filter(array(
                'node_id' => (string) ($entry['id'] ?? ''),
                'name' => (string) ($entry['name'] ?? ($node['name'] ?? '')),
                'type' => (string) ($entry['type'] ?? ($node['type'] ?? '')),
                'class' => (string) ($node['class'] ?? ''),
                'parent_id' => $parentId,
                'source_area_px' => $this->reportNumericValue($entryArea),
                'visible_area_px' => $this->reportNumericValue($entryVisibleArea),
                'clipped_area_px' => $this->reportNumericValue($entryArea - $entryVisibleArea),
                'clipped_area_ratio' => round(($entryArea - $entryVisibleArea) / $entryArea, 3),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
            $this->applyDiagnosticReason($sample, $this->largeGeometryIntentClassification($node, $entry, $parent), 'clipped_visual_area');
            $samples[] = $sample;
        }

        usort($samples, static fn (array $a, array $b): int => ((float) ($b['clipped_area_px'] ?? 0.0) <=> (float) ($a['clipped_area_px'] ?? 0.0)) ?: strcmp((string) ($a['node_id'] ?? ''), (string) ($b['node_id'] ?? '')));
        $clippedArea = max(0.0, $sourceArea - $visibleArea);

        return array(
            'clipped_visual_node_count' => count($samples),
            'clipped_visual_area_px' => $this->reportNumericValue($clippedArea),
            'visible_visual_area_px' => $this->reportNumericValue($visibleArea),
            'source_visual_area_px' => $this->reportNumericValue($sourceArea),
            'clipped_visual_area_ratio' => $sourceArea > 0.0 ? round($clippedArea / $sourceArea, 3) : 0.0,
            'clipped_visual_nodes' => array_slice($samples, 0, 25),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function textCoverageDiagnostics(array $nodes, string $html): array
    {
        $coverage = array(
            'schema' => 'blocks-engine/figma-transformer/text-coverage/v1',
            'decoded_text_node_count' => 0,
            'emitted_text_node_count' => 0,
            'intentionally_suppressed_text_node_count' => 0,
            'empty_decoded_text_node_count' => 0,
            'missing_emitted_text_node_count' => 0,
            'missing_emitted_text_reason_categories' => array(),
            'intentional_suppression_reason_counts' => array(),
            'empty_decoded_text_nodes' => array(),
            'missing_emitted_text_nodes' => array(),
            'intentionally_suppressed_text_nodes' => array(),
        );

        foreach ( $nodes as $node ) {
            if ( is_array($node) ) {
                $page = array(
                    'page_id' => (string) ($node['id'] ?? ''),
                    'page_name' => (string) ($node['name'] ?? ''),
                );
                $this->appendTextCoverageDiagnostics($node, $html, $coverage, $page, true, null, null);
            }
        }

        $coverage['empty_decoded_text_nodes'] = array_slice($coverage['empty_decoded_text_nodes'], 0, 25);
        $coverage['missing_emitted_text_nodes'] = array_slice($coverage['missing_emitted_text_nodes'], 0, 25);
        $coverage['intentionally_suppressed_text_nodes'] = array_slice($coverage['intentionally_suppressed_text_nodes'], 0, 25);
        ksort($coverage['missing_emitted_text_reason_categories']);
        ksort($coverage['intentional_suppression_reason_counts']);

        return $coverage;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $coverage
     * @param array{page_id: string, page_name: string} $page
     */
    private function appendTextCoverageDiagnostics(array $node, string $html, array &$coverage, array $page, bool $isRoot, ?array $parentNode, ?string $ancestorOmissionReason): void
    {
        if ( $this->isInputLike($node) && $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
            return;
        }

        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            $rawText = $this->rawDecodedText($node);
            if ( '' === trim($rawText) ) {
                ++$coverage['empty_decoded_text_node_count'];
                $coverage['empty_decoded_text_nodes'][] = $this->textCoverageNodeSample($node, $page, 0);
            } else {
                ++$coverage['decoded_text_node_count'];
                if ( $this->htmlContainsNodeId($html, (string) ($node['id'] ?? '')) ) {
                    ++$coverage['emitted_text_node_count'];
                } else {
                    $reason = $this->textOmissionReason($node, $isRoot, $parentNode, $ancestorOmissionReason);
                    $sample = $this->textCoverageNodeSample($node, $page, mb_strlen($rawText));
                    $sample['reason'] = $reason;
                    if ( $this->isIntentionalTextOmissionReason($reason) ) {
                        ++$coverage['intentionally_suppressed_text_node_count'];
                        $coverage['intentional_suppression_reason_counts'][$reason] = (int) ($coverage['intentional_suppression_reason_counts'][$reason] ?? 0) + 1;
                        $coverage['intentionally_suppressed_text_nodes'][] = $sample;
                    } else {
                        ++$coverage['missing_emitted_text_node_count'];
                        $coverage['missing_emitted_text_reason_categories'][$reason] = (int) ($coverage['missing_emitted_text_reason_categories'][$reason] ?? 0) + 1;
                        $coverage['missing_emitted_text_nodes'][] = $sample;
                    }
                }
            }
        }

        $childAncestorOmissionReason = $this->textSubtreeOmissionReason($node, $isRoot, $parentNode, $ancestorOmissionReason);
        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendTextCoverageDiagnostics($child, $html, $coverage, $page, false, $node, $childAncestorOmissionReason);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textSubtreeOmissionReason(array $node, bool $isRoot, ?array $parentNode, ?string $ancestorOmissionReason): ?string
    {
        if ( null !== $ancestorOmissionReason ) {
            return $ancestorOmissionReason;
        }
        $suppressionReason = $this->suppressedLayoutDiagnosticReason($node, null);
        if ( null !== $suppressionReason ) {
            return $suppressionReason;
        }
        if ( ! $isRoot && false === ($node['visible'] ?? null) ) {
            return 'hidden';
        }
        if ( null !== $parentNode && $this->isFullyClippedDecorativeChild($node, $parentNode) ) {
            return 'clipped_masked';
        }
        if ( $this->isDecorativeTextContainer($node) ) {
            return 'decorative';
        }
        if ( $this->isComponentSourceDuplicateNode($node) ) {
            return 'component_source_duplicate';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textOmissionReason(array $node, bool $isRoot, ?array $parentNode, ?string $ancestorOmissionReason): string
    {
        if ( null !== $ancestorOmissionReason ) {
            return $ancestorOmissionReason;
        }
        $suppressionReason = $this->suppressedLayoutDiagnosticReason($node, null);
        if ( null !== $suppressionReason ) {
            return $suppressionReason;
        }
        if ( ! $isRoot && false === ($node['visible'] ?? null) ) {
            return 'hidden';
        }
        if ( null !== $parentNode && $this->isFullyClippedDecorativeChild($node, $parentNode) ) {
            return 'clipped_masked';
        }
        if ( $this->nodeHasZeroArea($node) ) {
            return 'zero_area';
        }
        if ( null !== $parentNode && ($this->isInputLike($parentNode) || $this->isTextareaLike($parentNode)) && $this->isFormControlPlaceholderChild($node) ) {
            return 'converted_to_form_control';
        }
        if ( null !== $parentNode && $this->isSpatialFormControlLabel($node, $parentNode) ) {
            return 'converted_to_form_control';
        }
        if ( null !== $parentNode && $this->isListMarkerTextChild($node) ) {
            return 'list_marker';
        }
        if ( $this->isComponentSourceDuplicateNode($node) ) {
            return 'component_source_duplicate';
        }
        if ( $this->isUnresolvedComponentPlaceholderText($node, $this->rawDecodedText($node)) ) {
            return 'decorative';
        }

        return null !== $parentNode ? 'parent_omitted' : 'not_emitted';
    }

    private function isIntentionalTextOmissionReason(string $reason): bool
    {
        return in_array($reason, array('hidden', 'clipped_masked', 'zero_area', 'converted_to_form_control', 'decorative', 'list_marker', 'component_source_duplicate', 'root_off_canvas_child_suppressed'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isDecorativeTextContainer(array $node): bool
    {
        return 'TEXT' === strtoupper((string) ($node['type'] ?? '')) && $this->isUnresolvedComponentPlaceholderText($node, $this->rawDecodedText($node));
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rawDecodedText(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) ) {
                    $content .= (string) $segment['characters'];
                }
            }
            if ( '' !== $content ) {
                return $content;
            }
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        return (string) ($node['characters'] ?? $node['text'] ?? '');
    }

    private function htmlContainsNodeId(string $html, string $nodeId): bool
    {
        return '' !== $nodeId && str_contains($html, 'data-figma-node-id="' . $this->sanitizeAttribute($nodeId) . '"');
    }

    /**
     * @param array<string, mixed> $node
     * @param array{page_id: string, page_name: string} $page
     * @return array<string, mixed>
     */
    private function textCoverageNodeSample(array $node, array $page, int $characterCount): array
    {
        return array_filter(array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => strtoupper((string) ($node['type'] ?? '')),
            'class' => $this->nodeDiagnosticClass($node),
            'page_id' => $page['page_id'],
            'page_name' => $page['page_name'],
            'character_count' => $characterCount,
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function emptyVisibleContainerDiagnostic(array $node, ?array $parentNode = null): ?array
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE', 'SECTION'), true) || false === ($node['visible'] ?? true) ) {
            return null;
        }
        if ( ! empty($this->nodeList($node)) || '' !== trim($this->textContent($node)) || ! empty($this->nodeImagePaints($node)) || ! empty($this->explicitNodeAssetReferences($node)) ) {
            return null;
        }
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;
        if ( $width <= 0.0 || $height <= 0.0 ) {
            return null;
        }

        $category = $this->emptyVisibleContainerCategory($node, $type, $width, $height, $parentNode);

        return array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'type' => $type,
            'class' => $this->nodeDiagnosticClass($node),
            'width' => $this->reportNumericValue($width),
            'height' => $this->reportNumericValue($height),
            'category' => $category,
            'blocks_parity' => ! $this->isNonBlockingEmptyVisibleContainerCategory($category),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emptyVisibleContainerCategory(array $node, string $type, float $width, float $height, ?array $parentNode = null): string
    {
        $name = trim((string) ($node['name'] ?? ''));
        if ( $height <= 1.0 && preg_match('/^[\x{2013}\x{2014}-]+$/u', $name) ) {
            return 'decorative_zero_height_separator';
        }

        if ( $height <= 80.0 && 1 === preg_match('/\b(spacer|gap)\b/i', $name) ) {
            return 'layout_spacer';
        }

        if ( $height <= 12.0 && $width >= 600.0 ) {
            return 'layout_spacer';
        }

        if ( $this->isFormControlChrome($node, $parentNode, $width, $height) ) {
            return 'form_control_chrome';
        }

        if ( 'INSTANCE' === $type ) {
            return 'missing_instance_descendants';
        }

        return 'empty_visible_container';
    }

    private function isNonBlockingEmptyVisibleContainerCategory(string $category): bool
    {
        return in_array($category, array('decorative_zero_height_separator', 'form_control_chrome', 'layout_spacer'), true);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFormControlChrome(array $node, ?array $parentNode, float $width, float $height): bool
    {
        if ( null === $parentNode || $width < 10.0 || $height < 10.0 || $width > 40.0 || $height > 40.0 || abs($width - $height) > 2.0 ) {
            return false;
        }

        if ( empty($this->strokeStyles($node)) ) {
            return false;
        }

        $layout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( ! in_array((string) ($layout['flex_direction'] ?? ''), array('row', 'row-reverse'), true) && 'HORIZONTAL' !== ($layout['mode'] ?? null) ) {
            return false;
        }

        $nodeId = (string) ($node['id'] ?? '');
        foreach ( $this->nodeList($parentNode) as $sibling ) {
            if ( ! is_array($sibling) || $nodeId === (string) ($sibling['id'] ?? '') ) {
                continue;
            }

            if ( '' !== trim($this->subtreePlainText($sibling)) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function imageHeavyLandmarkCandidate(array $node): ?array
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        $role = str_contains($name, 'header') ? 'header' : (str_contains($name, 'footer') ? 'footer' : null);
        if ( null === $role ) {
            return null;
        }

        $summary = $this->subtreeVisualSummary($node);
        if ( $summary['image_nodes'] < 3 || $summary['image_nodes'] < max(1, $summary['text_nodes'] * 2) ) {
            return null;
        }

        return array(
            'node_id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'role' => $role,
            'image_nodes' => $summary['image_nodes'],
            'text_nodes' => $summary['text_nodes'],
            'total_nodes' => $summary['total_nodes'],
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array{image_nodes: int, text_nodes: int, total_nodes: int}
     */
    private function subtreeVisualSummary(array $node): array
    {
        $type = strtoupper((string) ($node['type'] ?? ''));
        $summary = array(
            'image_nodes' => null !== $this->nodeAssetPath($node) || ! empty($this->nodeImagePaints($node)) ? 1 : 0,
            'text_nodes' => 'TEXT' === $type ? 1 : 0,
            'total_nodes' => 1,
        );

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childSummary = $this->subtreeVisualSummary($child);
            $summary['image_nodes'] += $childSummary['image_nodes'];
            $summary['text_nodes'] += $childSummary['text_nodes'];
            $summary['total_nodes'] += $childSummary['total_nodes'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     * @return array<string, mixed>
     */
    private function decorativeUnderlayDiagnostic(array $node, array $parentNode): array
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();

        return array(
            'node_id'       => (string) ($node['id'] ?? ''),
            'name'          => (string) ($node['name'] ?? ''),
            'parent_id'     => (string) ($parentNode['id'] ?? ''),
            'parent_name'   => (string) ($parentNode['name'] ?? ''),
            'width'         => $this->reportNumericValue($box['width'] ?? null),
            'height'        => $this->reportNumericValue($box['height'] ?? null),
            'parent_width'  => $this->reportNumericValue($parentBox['width'] ?? null),
            'parent_height' => $this->reportNumericValue($parentBox['height'] ?? null),
        );
    }

    private function reportNumericValue(mixed $value): mixed
    {
        if ( ! $this->isFiniteNumeric($value) ) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function explicitNodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) && '' !== (string) $node[$key] ) {
                $references[] = (string) $node[$key];
            }
        }
        if ( is_array($node['image'] ?? null) ) {
            $references = array_merge($references, $this->imageAssetReferences($node['image']));
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imagePaintReferences(array $node): array
    {
        $references = array();
        foreach ( $this->nodeImagePaints($node) as $paint ) {
            $references = array_merge($references, $this->paintAssetReferences($paint));
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int>
     */
    private function diagnosticCodeCounts(array $diagnostics): array
    {
        $counts = array();
        foreach ( $diagnostics as $diagnostic ) {
            $code = is_array($diagnostic) ? (string) ($diagnostic['code'] ?? '') : '';
            if ( '' === $code ) {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isClippableDecorativeVisualNode(array $node): bool
    {
        return $this->layoutIntentClassifier()->isClippableDecorativeVisualNode($node);
    }

    private function nodeShouldEmitCssBackground(string $type, ?float $zeroHeightVectorFallbackHeight, bool $rendersInlineVectorSvg): bool
    {
        if ( 'TEXT' === $type ) {
            return false;
        }

        if ( 'LINE' === $type && $rendersInlineVectorSvg ) {
            return false;
        }

        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE'), true) ) {
            return true;
        }

        return ! $rendersInlineVectorSvg || null !== $zeroHeightVectorFallbackHeight;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isFullyClippedDecorativeChild(array $node, array $parentNode): bool
    {
        return $this->visualGeometryResolver()->isFullyClippedDecorativeChild($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isFullyOffCanvasRootChild(array $node, array $parentNode): bool
    {
        return $this->visualGeometryResolver()->isFullyOffCanvasChild($node, $parentNode);
    }

    /**
     * @param array<int, mixed> $children
     * @param array<string, mixed> $parentNode
     */
    private function hasRootOffCanvasChildCluster(array $children, array $parentNode): bool
    {
        return $this->visualGeometryResolver()->hasOffCanvasChildCluster($children, $parentNode);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $rect
     * @param array{x: float, y: float, width: float, height: float} $clipRect
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function rectIntersection(array $rect, array $clipRect): ?array
    {
        return $this->visualGeometryResolver()->rectIntersection($rect, $clipRect);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function styleDeclarations(array $node, string $type, ?array $parentNode, ?array $grandParentNode, bool $rendersInlineVectorSvg = false): array
    {
        $styles = array();

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layoutBox = $box;
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $canvasShell = $this->canvasShellResolver()->resolve($node, $parentNode, $grandParentNode);
        $canvasWidthDecision = null;
        $zeroHeightVectorFallbackHeight = $this->zeroHeightVectorFallbackHeight($node, $type);
        foreach ( array('width', 'height') as $dimension ) {
            $sizingKey = 'width' === $dimension ? 'sizing_horizontal' : 'sizing_vertical';
            $sizing = strtoupper((string) ($layout[$sizingKey] ?? ''));
            if ( 'height' === $dimension && isset($box['height']) && is_numeric($box['height']) && $this->canvasShellResolver()->nodeShouldUseContentDrivenHeight($type, $canvasShell, (float) $box['height']) ) {
                $this->recordDecisionTrace('layout_geometry', 'content_driven_canvas_shell_height', $node, 'omit_source_canvas_height', $parentNode, array(
                    'source_box' => $this->visualGeometryResolver()->nodeSourceBoxEvidence($node),
                    'canvas_shell' => array(
                        'frame_width_role' => $canvasShell->frameWidthRole,
                        'canvas_child_role' => $canvasShell->canvasChildRole,
                    ),
                    'omitted_css_box' => array('height' => (float) $box['height']),
                ));
                continue;
            }
            if ( 'height' === $dimension && isset($box['height']) && is_numeric($box['height']) && ($this->canvasShellResolver()->nodeShouldUseFlowHeight($type, $layout, $canvasShell) || $this->freeformContainerShouldUseFlow($node) || (empty($layout['display'] ?? null) && $this->layoutIntentShouldEmitClass($this->layoutIntentClassifier()->layoutIntent($node, $parentNode)))) ) {
                $styles[] = 'min-height:' . $this->number((float) $box['height']) . 'px';
                continue;
            }
            if ( 'width' === $dimension && null !== $parentNode && $this->isFlexiblePaginationControl($node, $parentNode) ) {
                $styles[] = 'width:auto';
                continue;
            }
            if ( 'TEXT' === $type && $this->textShouldUseFluidFlowBox($node, $parentNode) ) {
                if ( 'width' === $dimension && isset($box['width']) && is_numeric($box['width']) ) {
                    $styles[] = 'width:100%';
                    $styles[] = 'max-width:' . $this->textFlowMaxWidth($node, (float) $box['width']);
                }
                continue;
            }
            if ( 'height' === $dimension && isset($box['height']) && is_numeric($box['height']) && 'TEXT' === $type && $this->textShouldUseIntrinsicFlowHeight($node, $parentNode) ) {
                $styles[] = 'min-height:' . $this->number((float) $box['height']) . 'px';
                continue;
            }
            if ( 'height' === $dimension && isset($box['height']) && is_numeric($box['height']) && $this->flexContainerShouldUseIntrinsicFlowHeight($node) ) {
                $styles[] = 'min-height:' . $this->number((float) $box['height']) . 'px';
                continue;
            }
            if ( 'width' === $dimension ) {
                $canvasWidthDecision = $this->breakpointDimensionPolicy()->canvasWidthDecision(
                    $canvasShell,
                    $this->isFluidPageWidth($box, $layout, $parentNode),
                    isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : null,
                );
                if ( ! empty($canvasWidthDecision['declarations']) ) {
                    array_push($styles, ...$canvasWidthDecision['declarations']);
                    continue;
                }
            }
            if ( 'HUG' === $sizing ) {
                $derivedTextSizeDecision = 'TEXT' === $type ? $this->derivedTextLayoutSizeDecision($node, $dimension) : null;
                if ( null !== $derivedTextSizeDecision ) {
                    $derivedTextSize = $derivedTextSizeDecision['size'];
                    if ( 'source_box' === $derivedTextSizeDecision['authority'] ) {
                        $this->recordDecisionTrace('layout_geometry', 'derived_hug_text_size_conflicts_with_source_box', $node, 'emit_source_box_size', $parentNode, array(
                            'dimension' => $dimension,
                            'source_box' => $this->visualGeometryResolver()->nodeSourceBoxEvidence($node),
                            'derived_text_layout_size' => $derivedTextSizeDecision['derived_size'],
                            'agreement_tolerance' => $derivedTextSizeDecision['agreement_tolerance'],
                            'emitted_css_box' => array($dimension => $derivedTextSize),
                        ));
                    }
                    if ( 'height' === $dimension && $this->textShouldAvoidTinyFixedHeight($node, $derivedTextSize) && ! $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                        continue;
                    }
                    $styles[] = $dimension . ':' . $this->number($derivedTextSize) . 'px';
                } elseif ( 'flex' === ($layout['display'] ?? null) && $this->isFiniteNumeric($box[$dimension] ?? null) ) {
                    $intrinsicMainAxisSize = $this->flexHugMainAxisIntrinsicSizeStyle($node, $dimension);
                    $styles[] = $dimension . ':' . (null === $intrinsicMainAxisSize ? $this->number((float) $box[$dimension]) . 'px' : $intrinsicMainAxisSize);
                } else {
                    $styles[] = $dimension . ':fit-content';
                }
            } elseif ( 'FILL' === $sizing ) {
                $styles[] = $dimension . ':100%';
            } elseif ( $this->isFiniteNumeric($box[$dimension] ?? null) ) {
                $property = $dimension;
                $value = 'height' === $dimension && null !== $zeroHeightVectorFallbackHeight ? $zeroHeightVectorFallbackHeight : (float) $box[$dimension];
                if ( 'height' === $dimension && 'TEXT' === $type && $this->textShouldAvoidTinyFixedHeight($node, $value) && ! $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                    continue;
                }
                $styles[] = $property . ':' . $this->number($value) . 'px';
            }
        }

        $absoluteChildReserveHeightDecision = $this->absoluteChildReserveHeightDecision($node);
        $absoluteChildReserveHeight = is_array($absoluteChildReserveHeightDecision) && isset($absoluteChildReserveHeightDecision['height']) && is_numeric($absoluteChildReserveHeightDecision['height']) ? (float) $absoluteChildReserveHeightDecision['height'] : null;
        if ( null !== $absoluteChildReserveHeight && ! $this->stylesDeclareProperty($styles, 'min-height') ) {
            $layoutMinHeight = isset($layout['min_height']) && is_numeric($layout['min_height']) ? (float) $layout['min_height'] : null;
            $emittedMinHeight = null === $layoutMinHeight ? $absoluteChildReserveHeight : max($layoutMinHeight, $absoluteChildReserveHeight);
            $styles[] = 'min-height:' . $this->number($emittedMinHeight) . 'px';
            $this->recordDecisionTrace('layout_geometry', 'absolute_child_reserve_height_from_visual_bounds', $node, 'emit_min_height', $parentNode, array(
                'source_box' => $this->visualGeometryResolver()->nodeSourceBoxEvidence($node),
                'emitted_css_box' => array('min_height' => $emittedMinHeight),
                'child_bounds' => $absoluteChildReserveHeightDecision['children'] ?? array(),
            ));
        }

        // Auto Layout min/max constraints (Kiwi minSize/maxSize). Skip a property
        // the width/height pass already emitted (e.g. the fluid root max-width).
        foreach ( array(
            'min_width'  => 'min-width',
            'max_width'  => 'max-width',
            'min_height' => 'min-height',
            'max_height' => 'max-height',
        ) as $layoutKey => $property ) {
            if ( $this->isFiniteNumeric($layout[$layoutKey] ?? null) && ! $this->stylesDeclareProperty($styles, $property) ) {
                $styles[] = $property . ':' . $this->number((float) $layout[$layoutKey]) . 'px';
            }
        }

        foreach ( $this->clipMaskStyleResolver()->resolve($node) as $style ) {
            $styles[] = $style;
        }

        $positioningStyleDecision = $this->positioningStyleResolver()->resolve($node, $type, $parentNode, $box, $layout, $canvasShell, $styles);
        foreach ( $positioningStyleDecision->styles as $style ) {
            $styles[] = $style;
        }
        $fullBleedBreakoutDecision = $this->canvasShellResolver()->fullBleedViewportBreakoutDecision($canvasShell);

        if ( $this->nodeShouldEmitCssBackground($type, $zeroHeightVectorFallbackHeight, $rendersInlineVectorSvg) ) {
            $background = $this->backgroundColor($node);
            if ( null !== $background ) {
                $styles[] = 'background:' . $background;
            }
        }

        $box = is_array($node['figma_box'] ?? null) ? $node['figma_box'] : array();
        if ( $this->isFiniteNumeric($box['opacity'] ?? null) ) {
            $styles[] = 'opacity:' . $this->number((float) $box['opacity']);
        }

        if ( isset($box['blend_mode']) && is_scalar($box['blend_mode']) ) {
            $blendMode = $this->blendModeCss((string) $box['blend_mode']);
            if ( null !== $blendMode ) {
                $styles[] = 'mix-blend-mode:' . $blendMode;
            }
        }

        $transform = $this->isNearZeroHeightContainer($node, $type) || $this->hasAbsoluteVisualBounds($node) ? null : $this->transformStyle($box);
        if ( null !== $transform ) {
            $styles[] = 'transform:' . $transform;
            $transformOrigin = $this->transformOriginStyle($box);
            if ( null !== $transformOrigin ) {
                $styles[] = 'transform-origin:' . $transformOrigin;
            } elseif ( $canvasShell->fullBleedCanvasChildReflected ) {
                $styles[] = 'transform-origin:50% 50%';
            } elseif ( $this->hasExplicitTransformMatrix($box) ) {
                $styles[] = 'transform-origin:0 0';
            }
        }

        foreach ( $this->radiusStyles($box) as $style ) {
            $styles[] = $style;
        }

        if ( ! $this->rendersStrokeInsideInlineSvg($node, $type, $parentNode) ) {
            foreach ( $this->strokeStyles($node) as $style ) {
                $styles[] = $style;
            }
        }

        foreach ( $this->composedImageBackgroundStyles($node) as $style ) {
            $styles[] = $style;
        }
        if ( $canvasShell->fullBleedCanvasChild ) {
            $styles = $this->scaleFullBleedImageCropStyles($styles, $layoutBox);
        }

        if ( 'TEXT' === $type ) {
            foreach ( $this->textStyles($node, $parentNode, $grandParentNode) as $style ) {
                if ( ($this->textShouldUseFluidFlowBox($node, $parentNode) || $this->textShouldUseIntrinsicFlowHeight($node, $parentNode)) && str_starts_with($style, 'white-space:') ) {
                    continue;
                }
                $styles[] = $style;
            }
            if ( $this->textShouldUseFluidFlowBox($node, $parentNode) || $this->textShouldUseIntrinsicFlowHeight($node, $parentNode) ) {
                foreach ( $this->textWrappingStyles($node, $parentNode, $grandParentNode) as $style ) {
                    $styles[] = $style;
                }
            }
            if ( $this->textShouldUseMeasuredFlexHeight($node, $parentNode) ) {
                $styles[] = 'overflow:visible';
            }
        }

        foreach ( $this->effectStyles($node, $type) as $style ) {
            $styles[] = $style;
        }

        $layoutIntent = $this->layoutIntentClassifier()->layoutIntent($node, $parentNode);
        $freeformFlowIntent = empty($layout['display'] ?? null) ? $this->freeformContainerFlowIntent($node) : null;
        if ( null !== $freeformFlowIntent ) {
            $layoutIntent = $freeformFlowIntent;
        }
        if ( empty($layout['display'] ?? null) && is_array($layoutIntent) && ($this->layoutIntentShouldEmitClass($layoutIntent) || ($this->freeformContainerShouldUseFlow($node) && ! $positioningStyleDecision->willPositionAbsolute)) ) {
            foreach ( $this->layoutIntentStyles($layoutIntent) as $style ) {
                $styles[] = $style;
            }
        }

        foreach ( array(
            'display'         => 'display',
            'flex_direction'  => 'flex-direction',
            'justify_content' => 'justify-content',
            'align_items'     => 'align-items',
            'flex_wrap'       => 'flex-wrap',
        ) as $source => $property ) {
            if ( isset($layout[$source]) && is_scalar($layout[$source]) && '' !== (string) $layout[$source] ) {
                $styles[] = $property . ':' . (string) $layout[$source];
            }
        }
        if ( 'wrap' === ($layout['flex_wrap'] ?? null) ) {
            $styles[] = 'align-content:flex-start';
        }

        if ( isset($layout['padding']) && is_array($layout['padding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                if ( $this->isFiniteNumeric($layout['padding'][$edge] ?? null) ) {
                    $paddingValue = $this->cssPaddingValue($node, $edge, $parentNode);
                    $styles[] = 'padding-' . $edge . ':' . (is_string($paddingValue) ? $paddingValue : $this->number($paddingValue) . 'px');
                }
            }
        }

        if ( null !== $parentNode && $this->isHeadingSeparatorChild($node, $parentNode) ) {
            $styles[] = 'align-self:center';
            $offset = $this->headingSeparatorBaselineOffset($parentNode);
            if ( null !== $offset ) {
                $styles[] = 'margin-top:' . $this->number($offset) . 'px';
            }
        }

        $justifyContent = (string) ($layout['justify_content'] ?? '');
        $usesDistributedMainAxis = in_array($justifyContent, array('space-between', 'space-around', 'space-evenly'), true);
        $gap = $this->layoutGapResolver->resolve($layout);
        if ( ! $usesDistributedMainAxis && null !== $gap ) {
            if ( 'wrap' === ($layout['flex_wrap'] ?? null) && $gap['row'] !== $gap['column'] ) {
                // CSS `gap` shorthand is `row-gap column-gap`.
                $styles[] = 'gap:' . $this->number($gap['row']) . 'px ' . $this->number($gap['column']) . 'px';
            } else {
                $styles[] = 'gap:' . $this->number($gap['column']) . 'px';
            }
        }

        if ( ! $positioningStyleDecision->isDecorativeFlexUnderlay ) {
            foreach ( $this->flexItemStyles($node, $layout, $parentNode) as $style ) {
                $styles[] = $style;
            }
            if ( ! $positioningStyleDecision->willPositionAbsolute ) {
                foreach ( $this->inferredGridItemStyles($node, $parentNode) as $style ) {
                    $styles[] = $style;
                }
            }
        }

        $styles = $this->mergeBoxShadowDeclarations(array_values(array_unique($styles)));
        $this->recordGeometryDecisionDiagnostics($node, $type, $parentNode, $layoutBox, $box, $layout, $canvasShell, $canvasWidthDecision, $fullBleedBreakoutDecision, $positioningStyleDecision, $styles, $transform);

        return $styles;
    }

    /**
     * @param array<int, string> $styles
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function scaleFullBleedImageCropStyles(array $styles, array $box): array
    {
        if ( ! isset($box['width']) || ! is_numeric($box['width']) || (float) $box['width'] <= 0.0 ) {
            return $styles;
        }

        $sourceWidth = (float) $box['width'];
        $scaled = array();
        foreach ( $styles as $style ) {
            if ( str_starts_with($style, 'background-size:') ) {
                $scaled[] = $this->scaleFullBleedImageCropDeclaration($style, $sourceWidth, 'size');
                continue;
            }
            if ( str_starts_with($style, 'background-position:') ) {
                $scaled[] = $this->scaleFullBleedImageCropDeclaration($style, $sourceWidth, 'position');
                continue;
            }

            $scaled[] = $style;
        }

        return $scaled;
    }

    /**
     * @param array{intent: string, display: string, direction: string, collection: string|null, item_count: int, column_count: int|null, gap: float|null, confidence: string} $layoutIntent
     * @return array<int, string>
     */
    private function layoutIntentStyles(array $layoutIntent): array
    {
        $styles = array();
        if ( 'grid' === ($layoutIntent['display'] ?? null) ) {
            $columns = isset($layoutIntent['column_count']) && is_int($layoutIntent['column_count']) && $layoutIntent['column_count'] > 1 ? $layoutIntent['column_count'] : 2;
            $styles[] = 'display:grid';
            $styles[] = 'grid-template-columns:repeat(' . (string) $columns . ',minmax(0,1fr))';
        } else {
            $styles[] = 'display:flex';
            $styles[] = 'flex-direction:' . ('row' === ($layoutIntent['direction'] ?? null) ? 'row' : 'column');
            if ( in_array($layoutIntent['intent'] ?? '', array(LayoutIntentClassifier::LAYOUT_INTENT_CARD_ROW, LayoutIntentClassifier::LAYOUT_INTENT_NAV_ROW), true) ) {
                $styles[] = 'align-items:center';
            }
        }

        if ( isset($layoutIntent['gap']) && is_numeric($layoutIntent['gap']) && (float) $layoutIntent['gap'] > 0.0 ) {
            $styles[] = 'gap:' . $this->number((float) $layoutIntent['gap']) . 'px';
        }

        return $styles;
    }

    /** @param array<string, mixed>|null $layoutIntent */
    private function layoutIntentShouldEmitClass(?array $layoutIntent): bool
    {
        return is_array($layoutIntent) && null !== ($layoutIntent['collection'] ?? null);
    }

    /** @param array<string, mixed>|null $layoutIntent */
    private function layoutIntentCanUseFlow(?array $layoutIntent): bool
    {
        return is_array($layoutIntent) && in_array($layoutIntent['intent'] ?? '', array(
            LayoutIntentClassifier::LAYOUT_INTENT_FLOW_SECTION,
            LayoutIntentClassifier::LAYOUT_INTENT_STACK,
            LayoutIntentClassifier::LAYOUT_INTENT_NAV_ROW,
            LayoutIntentClassifier::LAYOUT_INTENT_CARD_ROW,
            LayoutIntentClassifier::LAYOUT_INTENT_CARD_GRID,
            LayoutIntentClassifier::LAYOUT_INTENT_PRICING_GRID,
            LayoutIntentClassifier::LAYOUT_INTENT_SERVICE_GRID,
            LayoutIntentClassifier::LAYOUT_INTENT_ARTICLE_GRID,
            LayoutIntentClassifier::LAYOUT_INTENT_CTA,
        ), true);
    }

    /**
     * Inferred flow layouts use source geometry to reconstruct their visual
     * placement. Keep declared auto-layout and layered children source-ordered.
     *
     * @param array<string, mixed> $node
     * @return array<int, mixed>
     */
    private function childrenInEmissionOrder(array $node): array
    {
        $children = $this->nodeList($node);
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $layoutIntent = $this->layoutIntentClassifier()->layoutIntent($node);
        if (
            ! empty($layout['display'] ?? null)
            || ! is_array($layoutIntent)
            || ! in_array($layoutIntent['display'] ?? null, array('grid', 'flex'), true)
            || ('flex' === ($layoutIntent['display'] ?? null) && 'row' !== ($layoutIntent['direction'] ?? null))
        ) {
            return $children;
        }

        $participating = array();
        foreach ( $children as $index => $child ) {
            if ( ! is_array($child) || ! $this->normalFlexFlowChild($child, $node) ) {
                continue;
            }
            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($childLayout['positioning'] ?? null) ) {
                continue;
            }

            $stackingPlan = $this->layoutIntentClassifier()->stackingContextPlan($child, $node);
            if ( null !== ($stackingPlan['z_index'] ?? null) ) {
                continue;
            }

            $box = is_array($child['box'] ?? null) ? $child['box'] : $child;
            $x = $box['x'] ?? null;
            $y = $box['y'] ?? null;
            if ( ! is_numeric($x) || ('grid' === ($layoutIntent['display'] ?? null) && ! is_numeric($y)) ) {
                return $children;
            }
            $participating[] = array('index' => $index, 'child' => $child, 'x' => (float) $x, 'y' => is_numeric($y) ? (float) $y : 0.0);
        }

        if ( count($participating) < 2 ) {
            return $children;
        }

        $isGrid = 'grid' === ($layoutIntent['display'] ?? null);
        usort($participating, static function (array $left, array $right) use ($isGrid): int {
            if ( $isGrid && $left['y'] !== $right['y'] ) {
                return $left['y'] <=> $right['y'];
            }
            if ( $left['x'] !== $right['x'] ) {
                return $left['x'] <=> $right['x'];
            }

            return $left['index'] <=> $right['index'];
        });

        $slots = array_column($participating, 'index');
        sort($slots, SORT_NUMERIC);
        foreach ( $slots as $position => $index ) {
            $children[$index] = $participating[$position]['child'];
        }

        return $children;
    }

    /** @param array<string, mixed> $node */
    private function isContentOnlyFlowScaffold(array $node): bool
    {
        if ( in_array(strtoupper((string) ($node['type'] ?? '')), array('COMPONENT', 'INSTANCE'), true) ) {
            return false;
        }

        if ( $this->subtreeHasComponentCloneGeometry($node) || $this->hasDecorativeFlexUnderlayChild($node) || null !== $this->layoutIntentClassifier()->chromeGroupRole($node, null, 1) ) {
            return false;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        if ( ! preg_match('/\b(section|content|main|hero|intro|cards?|grid|columns?|services?|features?|articles?|posts?|pricing|plans?|cta|call to action)\b/', $name) ) {
            return false;
        }

        $contentChildren = 0;
        foreach ( array_values(array_filter($this->nodeList($node), 'is_array')) as $child ) {
            if ( $this->subtreeIsDecorativeSeparator($child) || $this->isFullyClippedDecorativeChild($child, $node) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }
            if ( $this->subtreeHasComponentCloneGeometry($child) ) {
                return false;
            }
            if ( ! $this->subtreeHasText($child) && ! $this->subtreeHasLink($child) ) {
                return false;
            }
            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' !== ($layout['positioning'] ?? null) ) {
                return false;
            }
            ++$contentChildren;
        }

        return $contentChildren >= 2;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function nodeWillPositionAbsolute(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        return ($this->isFreeformContainer($parentNode) && ! $this->freeformContainerShouldUseFlow($parentNode))
            || ('absolute' === ($layout['positioning'] ?? null) && ! $this->freeformContainerShouldUseFlow($parentNode))
            || $this->isDecorativeFlexUnderlay($node, $parentNode);
    }

    /** @param array<string, mixed> $node */
    private function subtreeHasComponentCloneGeometry(array $node): bool
    {
        if ( true === ($node['_component_source_clone_geometry'] ?? false) || isset($node['figma_component_source_id']) ) {
            return true;
        }
        foreach ( array('box', 'figma_box') as $boxKey ) {
            $box = is_array($node[$boxKey] ?? null) ? $node[$boxKey] : array();
            if ( 'component_source_clone' === ($box['geometry_semantics'] ?? null) ) {
                return true;
            }
        }
        foreach ( array_values(array_filter($this->nodeList($node), 'is_array')) as $child ) {
            if ( $this->subtreeHasComponentCloneGeometry($child) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{intent: string, display: string, direction: string, collection: string|null, item_count: int, column_count: int|null, gap: float|null, confidence: string} $layoutIntent
     */
    private function layoutIntentAttributes(array $layoutIntent): string
    {
        $attributes = ' data-figma-layout-intent="' . $this->sanitizeAttribute((string) ($layoutIntent['intent'] ?? '')) . '"';
        $attributes .= ' data-figma-layout-display="' . $this->sanitizeAttribute((string) ($layoutIntent['display'] ?? '')) . '"';
        $attributes .= ' data-figma-layout-direction="' . $this->sanitizeAttribute((string) ($layoutIntent['direction'] ?? '')) . '"';
        if ( null !== ($layoutIntent['collection'] ?? null) ) {
            $attributes .= ' data-figma-collection="' . $this->sanitizeAttribute((string) $layoutIntent['collection']) . '"';
        }
        if ( isset($layoutIntent['column_count']) && is_int($layoutIntent['column_count']) && $layoutIntent['column_count'] > 1 ) {
            $attributes .= ' data-figma-layout-columns="' . $this->sanitizeAttribute((string) $layoutIntent['column_count']) . '"';
        }

        return $attributes;
    }

    private function scaleFullBleedImageCropDeclaration(string $style, float $sourceWidth, string $kind): string
    {
        $parts = explode(':', $style, 2);
        if ( 2 !== count($parts) ) {
            return $style;
        }

        $layers = explode(',', $parts[1]);
        $scaledLayers = array();
        foreach ( $layers as $layer ) {
            $tokens = preg_split('/\s+/', trim($layer));
            if ( ! is_array($tokens) || 2 !== count($tokens) ) {
                return $style;
            }

            $scaledTokens = array();
            foreach ( $tokens as $token ) {
                if ( 1 !== preg_match('/^-?\d+(?:\.\d+)?px$/', $token) ) {
                    return $style;
                }

                $value = (float) substr($token, 0, -2);
                if ( 'size' === $kind && $value <= 0.0 ) {
                    return $style;
                }
                $scaledTokens[] = 'calc(100vw * ' . $this->number($value / $sourceWidth) . ')';
            }

            $scaledLayers[] = implode(' ', $scaledTokens);
        }

        return $parts[0] . ':' . implode(',', $scaledLayers);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed> $sourceBox
     * @param array<string, mixed> $figmaBox
     * @param array<string, mixed> $layout
     * @param array{reason_code: string, declarations: array<int, string>}|null $canvasWidthDecision
     * @param array{reason_code: string, declarations: array<int, string>, evidence?: array<string, mixed>} $fullBleedBreakoutDecision
     * @param array<int, string> $styles
     */
    private function recordGeometryDecisionDiagnostics(array $node, string $type, ?array $parentNode, array $sourceBox, array $figmaBox, array $layout, CanvasShellDecision $canvasShell, ?array $canvasWidthDecision, array $fullBleedBreakoutDecision, PositioningStyleDecision $positioningStyleDecision, array $styles, ?string $transform): void
    {
        $sourceRect = $this->sourceGeometryDiagnostic($sourceBox);
        $effectiveGeometry = $this->effectiveCssGeometryDiagnostic($styles);
        $baseEvidence = array(
            'source_frame' => array_filter(array(
                'page_path' => $this->currentPagePath,
                'node_id' => isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '',
                'parent_id' => null === $parentNode ? '' : (string) ($parentNode['id'] ?? ''),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value),
            'source_geometry' => $sourceRect,
            'effective_css_geometry' => $effectiveGeometry,
        );

        $canvasReason = (string) ($canvasWidthDecision['reason_code'] ?? '');
        $fullBleedReason = (string) ($fullBleedBreakoutDecision['reason_code'] ?? '');
        if ( '' !== $canvasReason || '' !== $fullBleedReason || $canvasShell->fullBleedCanvasChild || $canvasShell->responsiveCenteredFlowShell || $canvasShell->fluidStretchCanvasChild ) {
            $this->recordDecisionTrace(
                'effective_geometry',
                '' !== $fullBleedReason ? $fullBleedReason : ('' !== $canvasReason ? $canvasReason : 'canvas_shell_role'),
                $node,
                'resolve_canvas_geometry',
                $parentNode,
                array_merge($baseEvidence, array(
                    'canvas_shell' => array(
                        'frame_width_role' => $canvasShell->frameWidthRole,
                        'canvas_child_role' => $canvasShell->canvasChildRole,
                        'parent_renders_fluid_canvas' => $canvasShell->parentRendersFluidCanvas,
                        'parent_uses_fluid_canvas_coordinates' => $canvasShell->parentUsesFluidCanvasCoordinates,
                        'full_bleed_canvas_child' => $canvasShell->fullBleedCanvasChild,
                        'centered_within_parent_fluid_canvas' => $canvasShell->centeredWithinParentFluidCanvas,
                        'responsive_centered_flow_shell' => $canvasShell->responsiveCenteredFlowShell,
                        'fluid_stretch_canvas_child' => $canvasShell->fluidStretchCanvasChild,
                        'responsive_centered_flow_width' => $canvasShell->responsiveCenteredFlowWidth,
                        'full_bleed_canvas_child_reflected' => $canvasShell->fullBleedCanvasChildReflected,
                    ),
                    'canvas_width_reason_code' => $canvasReason,
                    'canvas_width_declarations' => $canvasWidthDecision['declarations'] ?? array(),
                    'full_bleed_reason_code' => $fullBleedReason,
                    'full_bleed_declarations' => $fullBleedBreakoutDecision['declarations'],
                    'full_bleed_evidence' => is_array($fullBleedBreakoutDecision['evidence'] ?? null) ? $fullBleedBreakoutDecision['evidence'] : array(),
                ))
            );
        }

        if ( null !== $positioningStyleDecision->absolutePositioningDecision ) {
            $absoluteDecision = $positioningStyleDecision->absolutePositioningDecision;
            $this->recordDecisionTrace(
                'positioning_context',
                $absoluteDecision->reasonCode,
                $node,
                'resolve_absolute_positioning',
                $parentNode,
                array_merge($baseEvidence, array(
                    'positioning_declarations' => $absoluteDecision->declarations,
                    'suppressed_full_bleed_horizontal_offsets' => $absoluteDecision->suppressedFullBleedHorizontalOffsets,
                    'centered_within_parent_fluid_canvas' => $canvasShell->centeredWithinParentFluidCanvas,
                    'full_bleed_canvas_child' => $canvasShell->fullBleedCanvasChild,
                ))
            );
        }

        $syntheticCluster = is_array($node['_figma_synthetic_local_cluster'] ?? null) ? $node['_figma_synthetic_local_cluster'] : array();
        if ( ! empty($syntheticCluster) ) {
            $this->recordDecisionTrace(
                'local_coordinate_grouping',
                (string) ($syntheticCluster['reason_code'] ?? 'local_border_shell_cluster'),
                $node,
                'emit_synthetic_local_cluster',
                $parentNode,
                array_merge($baseEvidence, array(
                    'shell_id' => $syntheticCluster['shell_id'] ?? null,
                    'member_ids' => is_array($syntheticCluster['member_ids'] ?? null) ? $syntheticCluster['member_ids'] : array(),
                ))
            );
        }

        $stackingContextPlan = $this->layoutIntentClassifier()->stackingContextPlan($node, $parentNode);
        if ( true === ($stackingContextPlan['manages_local_stacking'] ?? false) || true === ($stackingContextPlan['needs_isolation'] ?? false) || null !== ($stackingContextPlan['z_index'] ?? null) || null !== $positioningStyleDecision->zIndexReasonCode ) {
            $this->recordDecisionTrace(
                'stacking_context',
                $positioningStyleDecision->zIndexReasonCode ?? (string) ($stackingContextPlan['z_index_reason'] ?? 'stacking_context_policy'),
                $node,
                'resolve_stacking_context',
                $parentNode,
                array_merge($baseEvidence, array(
                    'manages_local_stacking' => true === ($stackingContextPlan['manages_local_stacking'] ?? false),
                    'needs_isolation' => true === ($stackingContextPlan['needs_isolation'] ?? false),
                    'local_reasons' => is_array($stackingContextPlan['local_reasons'] ?? null) ? $stackingContextPlan['local_reasons'] : array(),
                    'sibling_role' => $stackingContextPlan['sibling_role'] ?? null,
                    'overlaps_sibling' => true === ($stackingContextPlan['overlaps_sibling'] ?? false),
                    'z_index' => $stackingContextPlan['z_index'] ?? null,
                    'z_index_reason' => $positioningStyleDecision->zIndexReasonCode ?? ($stackingContextPlan['z_index_reason'] ?? null),
                    'will_position_absolute' => $positioningStyleDecision->willPositionAbsolute,
                ))
            );
        }

        if ( null !== $transform ) {
            $matrix = $this->visualGeometryResolver()->cssTransformMatrixValues(is_array($figmaBox['relative_transform'] ?? null) ? $figmaBox['relative_transform'] : null);
            $viewport = null;
            if ( null !== $matrix && isset($sourceBox['width'], $sourceBox['height']) && is_numeric($sourceBox['width']) && is_numeric($sourceBox['height']) ) {
                $viewport = $this->reportRect($this->visualGeometryResolver()->transformedRect((float) $sourceBox['width'], (float) $sourceBox['height'], $matrix));
            }
            $this->recordDecisionTrace(
                'transform_viewport',
                'css_transform_matrix',
                $node,
                'resolve_transform_viewport',
                $parentNode,
                array_merge($baseEvidence, array(
                    'transform' => $transform,
                    'matrix' => null === $matrix ? array() : array_map(fn (float $value): mixed => $this->reportNumericValue($value), $matrix),
                    'transformed_rect' => $viewport,
                ))
            );
        }
    }

    /**
     * @param array<string, mixed> $box
     * @return array<string, mixed>
     */
    private function sourceGeometryDiagnostic(array $box): array
    {
        $rect = array();
        foreach ( array('x', 'y', 'width', 'height') as $key ) {
            if ( isset($box[$key]) && is_numeric($box[$key]) ) {
                $rect[$key] = $this->reportNumericValue((float) $box[$key]);
            }
        }

        return $rect;
    }

    /**
     * @param array<int, string> $styles
     * @return array<string, mixed>
     */
    private function effectiveCssGeometryDiagnostic(array $styles): array
    {
        $geometry = array();
        foreach ( array('position', 'left', 'right', 'top', 'width', 'max-width', 'height', 'min-height', 'margin-left', 'margin-right', 'z-index') as $property ) {
            $value = $this->styleDeclarationValue($styles, $property);
            if ( null !== $value ) {
                $geometry[$property] = $value;
            }
        }

        return $geometry;
    }

    /**
     * @param array<int, string> $styles
     */
    private function styleDeclarationValue(array $styles, string $property): ?string
    {
        $prefix = $property . ':';
        foreach ( $styles as $style ) {
            if ( str_starts_with($style, $prefix) ) {
                return substr($style, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $rect
     * @return array<string, mixed>
     */
    private function reportRect(array $rect): array
    {
        return array(
            'x' => $this->reportNumericValue($rect['x']),
            'y' => $this->reportNumericValue($rect['y']),
            'width' => $this->reportNumericValue($rect['width']),
            'height' => $this->reportNumericValue($rect['height']),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function cssMaskImageStyles(array $node): array
    {
        if ( ! $this->nodeHasCssMaskImage($node) ) {
            return array();
        }

        $maskPath = (string) $node['_figma_css_mask_image_path'];
        return array(
            '-webkit-mask-image:url("' . $maskPath . '")',
            'mask-image:url("' . $maskPath . '")',
            '-webkit-mask-size:100% 100%',
            'mask-size:100% 100%',
            '-webkit-mask-repeat:no-repeat',
            'mask-repeat:no-repeat',
        );
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function composedImageBackgroundStyles(array $node): array
    {
        return $this->paintStackResolver()->composedImageBackgroundStyles($node, $this->nodeAssetPaths($node));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{path: string, paint: array<string, mixed>}> $fallbackImageLayers
     * @return array<int, array{type: string, css: string, paint: array<string, mixed>}>
     */
    private function nodeComposedBackgroundLayers(array $node, array $fallbackImageLayers): array
    {
        return $this->paintStackResolver()->nodeComposedBackgroundLayers($node, $fallbackImageLayers);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{type: string, css: string, paint: array<string, mixed>}> $layers
     * @return array<int, string>
     */
    private function composedBackgroundLayerStyles(array $node, array $layers): array
    {
        return $this->paintStackResolver()->composedBackgroundLayerStyles($node, $layers);
    }

    /**
     * @param array<int, array{type: string, css: string, paint: array<string, mixed>}> $layers
     * @return array<int, string>
     */
    private function composedBackgroundBlendModes(array $layers): array
    {
        return $this->paintStackResolver()->composedBackgroundBlendModes($layers);
    }

    /**
     * @param array<int, string> $paths
     */
    private function cssUrlList(array $paths): string
    {
        return implode(',', array_map(static fn (string $path): string => 'url("' . $path . '")', $paths));
    }

    /**
     * @param array<int, string> $styles
     * @return array<int, string>
     */
    private function mergeBoxShadowDeclarations(array $styles): array
    {
        $merged = array();
        $boxShadows = array();
        $boxShadowIndex = null;

        foreach ( $styles as $style ) {
            if ( str_starts_with($style, 'box-shadow:') ) {
                $boxShadows[] = substr($style, strlen('box-shadow:'));
                if ( null === $boxShadowIndex ) {
                    $boxShadowIndex = count($merged);
                    $merged[] = $style;
                }
                continue;
            }

            $merged[] = $style;
        }

        if ( null !== $boxShadowIndex && count($boxShadows) > 1 ) {
            $merged[$boxShadowIndex] = 'box-shadow:' . implode(',', $boxShadows);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function derivedTextLayoutSize(array $node, string $dimension): ?float
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $size = is_array($derivedLayout['size'] ?? null) ? $derivedLayout['size'] : array();
        if ( isset($size[$dimension]) && is_numeric($size[$dimension]) && 0.0 <= (float) $size[$dimension] ) {
            return (float) $size[$dimension];
        }

        return null;
    }

    /**
     * Chooses the authority for a HUG text dimension while preserving the
     * derived layout measurement as diagnostic evidence.
     *
     * @param array<string, mixed> $node
     * @return array{size: float, authority: string, derived_size: float, agreement_tolerance: float}|null
     */
    private function derivedTextLayoutSizeDecision(array $node, string $dimension): ?array
    {
        $derivedSize = $this->derivedTextLayoutSize($node, $dimension);
        if ( null === $derivedSize ) {
            return null;
        }

        $agreementTolerance = 0.5;
        $sourceBox = $this->visualGeometryResolver()->nodeSourceBoxEvidence($node);
        $sourceSize = isset($sourceBox[$dimension]) && is_numeric($sourceBox[$dimension]) && is_finite((float) $sourceBox[$dimension])
            ? (float) $sourceBox[$dimension]
            : null;
        $sourceIsAuthoritative = 'height' === $dimension
            && null !== $sourceSize
            && abs($derivedSize - $sourceSize) > $agreementTolerance;

        return array(
            'size' => $sourceIsAuthoritative ? $sourceSize : $derivedSize,
            'authority' => $sourceIsAuthoritative ? 'source_box' : 'derived_layout',
            'derived_size' => $derivedSize,
            'agreement_tolerance' => $agreementTolerance,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function cssPaddingValue(array $node, string $edge, ?array $parentNode): float|string
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $value = isset($padding[$edge]) && is_numeric($padding[$edge]) ? (float) $padding[$edge] : 0.0;
        $axis = in_array($edge, array('left', 'right'), true) ? 'horizontal' : 'vertical';
        if ( 'horizontal' === $axis ) {
            $responsiveGutter = $this->responsiveFluidCanvasGutter($node, $edge, $parentNode);
            if ( null !== $responsiveGutter ) {
                return $responsiveGutter;
            }
        }

        $dimension = 'horizontal' === $axis ? 'width' : 'height';
        $sizingKey = 'horizontal' === $axis ? 'sizing_horizontal' : 'sizing_vertical';
        if ( in_array(strtoupper((string) ($layout[$sizingKey] ?? '')), array('HUG', 'FILL'), true) ) {
            return $value;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return $value;
        }

        $start = 'horizontal' === $axis ? 'left' : 'top';
        $end = 'horizontal' === $axis ? 'right' : 'bottom';
        $startValue = isset($padding[$start]) && is_numeric($padding[$start]) ? (float) $padding[$start] : 0.0;
        $endValue = isset($padding[$end]) && is_numeric($padding[$end]) ? (float) $padding[$end] : 0.0;
        $sum = $startValue + $endValue;
        $available = max(0.0, (float) $box[$dimension]);
        if ( $sum <= 0.0 || $sum <= $available ) {
            return $value;
        }

        return $value * ($available / $sum);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function responsiveFluidCanvasGutter(array $node, string $edge, ?array $parentNode): ?string
    {
        if ( ! in_array($edge, array('left', 'right'), true) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( ! $this->isFluidPageWidth($box, $layout, $parentNode) ) {
            return null;
        }

        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        if ( ! isset($padding['left'], $padding['right'], $box['width']) || ! is_numeric($padding['left']) || ! is_numeric($padding['right']) || ! is_numeric($box['width']) ) {
            return null;
        }

        $left = (float) $padding['left'];
        $right = (float) $padding['right'];
        $width = (float) $box['width'];
        if ( $left < 64.0 || $right < 64.0 || abs($left - $right) > 1.0 || $left + $right >= $width ) {
            return null;
        }

        $contentWidth = $width - $left - $right;
        $sourceGutter = 'left' === $edge ? $left : $right;
        return 'clamp(24px,calc((100% - ' . $this->number($contentWidth) . 'px) / 2),' . $this->number($sourceGutter) . 'px)';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function flexHugMainAxisIntrinsicSizeStyle(array $node, string $dimension): ?string
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $isRow = 'row' === ($layout['flex_direction'] ?? null);
        $mainAxis = $isRow ? 'width' : 'height';
        if ( $dimension !== $mainAxis || 'wrap' === ($layout['flex_wrap'] ?? null) || ! isset($box[$dimension]) || ! is_numeric($box[$dimension]) ) {
            return null;
        }

        $children = $this->nodeList($node);
        if ( empty($children) ) {
            return null;
        }

        $childCount = 0;
        $childMainSpan = 0.0;
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($childLayout['positioning'] ?? null) || $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( ! isset($childBox[$mainAxis]) || ! is_numeric($childBox[$mainAxis]) ) {
                return null;
            }

            $childMainSpan += (float) $childBox[$mainAxis];
            $childCount++;
        }

        if ( 0 === $childCount ) {
            return null;
        }

        $padding = is_array($layout['padding'] ?? null) ? $layout['padding'] : array();
        $paddingStart = $isRow ? 'left' : 'top';
        $paddingEnd = $isRow ? 'right' : 'bottom';
        $paddingSpan = 0.0;
        foreach ( array($paddingStart, $paddingEnd) as $edge ) {
            if ( isset($padding[$edge]) && is_numeric($padding[$edge]) ) {
                $paddingSpan += (float) $padding[$edge];
            }
        }

        $gap = isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ? (float) $layout['item_spacing'] : 0.0;
        $intrinsicMainSpan = $childMainSpan + $paddingSpan + max(0, $childCount - 1) * $gap;

        return $intrinsicMainSpan > (float) $box[$dimension] + 1.0 ? 'max-content' : null;
    }

    /**
     * @param array<string, mixed> $box
     */
    private function isFluidPageWidth(array $box, array $layout, ?array $parentNode): bool
    {
        return $this->layoutFrameRoleClassifier()->isFluidPageWidth($box, $layout, $parentNode);
    }

    /**
     * @param array<int, string> $styles
     */
    private function stylesDeclareProperty(array $styles, string $property): bool
    {
        foreach ( $styles as $style ) {
            if ( is_string($style) && str_starts_with($style, $property . ':') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function localPositionStyles(array $box): array
    {
        $styles = array();
        if ( $this->isFiniteNumeric($box['x'] ?? null) ) {
            $styles[] = 'left:' . $this->number((float) $box['x']) . 'px';
        }
        if ( $this->isFiniteNumeric($box['y'] ?? null) ) {
            $styles[] = 'top:' . $this->number((float) $box['y']) . 'px';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isFreeformContainer(array $node): bool
    {
        return $this->layoutIntentClassifier()->isFreeformContainer($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isFooterChromeNode(array $node, ?array $parentNode, int $depth): bool
    {
        return LayoutIntentClassifier::CHROME_GROUP_ROLE_FOOTER === $this->layoutIntentClassifier()->chromeGroupRole($node, $parentNode, $depth);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function absoluteChildReserveHeightDecision(array $node): ?array
    {
        $children = $this->nodeList($node);
        if ( empty($children) || (! $this->isFreeformContainer($node) && ! $this->hasAbsoluteChild($node) && ! $this->hasDecorativeFlexUnderlayChild($node)) ) {
            return null;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $parentHeight = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        $maxBottom = null;
        $contributingChildren = 0;
        $childEvidence = array();
        foreach ( $children as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }

            $layout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( ! $this->isFreeformContainer($node) && 'absolute' !== ($layout['positioning'] ?? null) && ! $this->isDecorativeFlexUnderlay($child, $node) ) {
                continue;
            }

            $childBox = is_array($child['box'] ?? null) ? $child['box'] : array();
            if ( ! isset($childBox['height']) || ! is_numeric($childBox['height']) ) {
                continue;
            }

            $top = $this->positionOffset($childBox, $box, 'y');
            if ( null === $top ) {
                continue;
            }
            if ( $top < -0.5 ) {
                return null;
            }

            $visualBoundsEvidence = $this->visualGeometryResolver()->childVisualBoundsEvidenceInParent($child, $node);
            $visualBounds = is_array($visualBoundsEvidence['transformed_visual_box'] ?? null) ? $visualBoundsEvidence['transformed_visual_box'] : array();
            if ( isset($visualBounds['y'], $visualBounds['height']) && is_numeric($visualBounds['y']) && is_numeric($visualBounds['height']) ) {
                $top = (float) $visualBounds['y'];
                $bottom = $top + (float) $visualBounds['height'];
            } else {
                $bottom = $top + (float) $childBox['height'];
            }
            if ( $top < -0.5 ) {
                return null;
            }
            if ( null !== $parentHeight && $bottom > $parentHeight + 0.5 && ! $this->isFooterChromeNode($node, null, 1) ) {
                return null;
            }
            $maxBottom = null === $maxBottom ? $bottom : max($maxBottom, $bottom);
            $contributingChildren++;
            $visualBoundsEvidence['reserve_top'] = $top;
            $visualBoundsEvidence['reserve_bottom'] = $bottom;
            $childEvidence[] = $visualBoundsEvidence;
        }

        if ( $contributingChildren <= 1 || null === $maxBottom || $maxBottom <= 0.0 ) {
            return null;
        }
        if ( null !== $parentHeight && abs($parentHeight - $maxBottom) > 0.5 && ! $this->isFooterChromeNode($node, null, 1) ) {
            return null;
        }

        return array(
            'height' => $maxBottom,
            'children' => $childEvidence,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isNearZeroHeightContainer(array $node, string $type): bool
    {
        if ( ! in_array($type, array('FRAME', 'GROUP', 'COMPONENT', 'INSTANCE'), true) || empty($this->nodeList($node)) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['height']) && is_numeric($box['height']) && 0.5 >= abs((float) $box['height']);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function relativeOffset(array $box, array $parentBox, string $dimension): ?float
    {
        return $this->layoutIntentClassifier()->relativeOffset($box, $parentBox, $dimension);
    }

    /**
     * @param array<string, mixed> $box
     * @param array<string, mixed> $parentBox
     */
    private function positionOffset(array $box, array $parentBox, string $dimension, ?array $parentNode = null): ?float
    {
        return $this->layoutIntentClassifier()->positionOffset($box, $parentBox, $dimension, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasAbsoluteChild(array $node): bool
    {
        return $this->layoutIntentClassifier()->hasAbsoluteChild($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasDecorativeFlexUnderlayChild(array $node): bool
    {
        return $this->layoutIntentClassifier()->hasDecorativeFlexUnderlayChild($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function isDecorativeFlexUnderlay(array $node, array $parentNode): bool
    {
        return $this->layoutIntentClassifier()->isDecorativeFlexUnderlay($node, $parentNode);
    }

    /**
     * @param array<string, mixed> $box
     */
    private function transformStyle(array $box): ?string
    {
        if ( isset($box['transform']) && is_array($box['transform']) ) {
            $matrix = $this->cssMatrix($box['transform']);
            if ( null !== $matrix ) {
                return $matrix;
            }
        }

        if ( isset($box['rotation']) && is_numeric($box['rotation']) ) {
            return 'rotate(' . $this->number((float) $box['rotation']) . 'deg)';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $box
     */
    private function transformOriginStyle(array $box): ?string
    {
        $origin = is_array($box['transform_origin'] ?? null) ? $box['transform_origin'] : array();
        if ( ! isset($origin['x'], $origin['y']) || ! is_numeric($origin['x']) || ! is_numeric($origin['y']) ) {
            return null;
        }

        return $this->number((float) $origin['x']) . 'px ' . $this->number((float) $origin['y']) . 'px';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasAbsoluteVisualBounds(array $node): bool
    {
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return 'absolute' === ($box['coordinate_space'] ?? null);
    }

    /**
     * @param array<string, mixed> $box
     */
    private function hasExplicitTransformMatrix(array $box): bool
    {
        return isset($box['transform']) && is_array($box['transform']);
    }

    /**
     * @param array<int, mixed> $transform
     */
    private function cssMatrix(array $transform): ?string
    {
        $values = $this->cssTransformMatrixValues($transform);
        if ( null === $values ) {
            return null;
        }

        return 'matrix(' . implode(',', array_map(fn (mixed $value): string => $this->number((float) $value), $values)) . ')';
    }

    /**
     * @param array<int|string, mixed>|null $transform
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null
     */
    private function cssTransformMatrixValues(?array $transform): ?array
    {
        return $this->visualGeometryResolver()->cssTransformMatrixValues($transform);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<int, string>
     */
    private function flexItemStyles(array $node, array $layout, ?array $parentNode): array
    {
        $styles = array();
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $isFlexChild = in_array((string) ($parentLayout['display'] ?? ''), array('flex', 'inline-flex'), true);

        if ( $this->layoutIntentClassifier()->fillsParentFlexMainAxis($layout, $parentNode) ) {
            $styles[] = 'flex-grow:1';
            $styles[] = 'flex-shrink:1';
        } elseif ( $this->textShouldUseFluidFlowBox($node, $parentNode) ) {
            $styles[] = 'flex-shrink:1';
            $styles[] = 'min-width:0';
        } elseif ( $this->isFiniteNumeric($layout['grow'] ?? null) ) {
            $styles[] = 'flex-grow:' . $this->number((float) $layout['grow']);
        } elseif ( $isFlexChild ) {
            $styles[] = 'flex-shrink:0';
        }

        if ( isset($layout['align']) && 'STRETCH' === $layout['align'] ) {
            $styles[] = 'align-self:stretch';
        }
        if ( $isFlexChild && isset($layout['order']) && is_numeric($layout['order']) ) {
            $styles[] = 'order:' . (string) (int) $layout['order'];
        }

        if ( $isFlexChild ) {
            $sourceGapMargin = $this->sourceGeometryFlexGapResolver()->resolve($node, $parentNode);
            if ( null !== $sourceGapMargin ) {
                $styles[] = $sourceGapMargin['property'] . ':' . $this->number($sourceGapMargin['value']) . 'px';
            }
        }

        return $styles;
    }

    /**
     * Preserve source-relative placement that equal inferred grid tracks do not
     * represent, including asymmetric container insets and item widths.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     * @return array<int, string>
     */
    private function inferredGridItemStyles(array $node, ?array $parentNode): array
    {
        if ( null === $parentNode || ! $this->normalFlexFlowChild($node, $parentNode) ) {
            return array();
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $layoutIntent = $this->layoutIntentClassifier()->layoutIntent($parentNode);
        if (
            ! empty($parentLayout['display'] ?? null)
            || true !== ($parentLayout['freeform'] ?? null)
            || 'absolute' === ($layout['positioning'] ?? null)
            || ! is_array($layoutIntent)
            || 'grid' !== ($layoutIntent['display'] ?? null)
        ) {
            return array();
        }

        $columns = $layoutIntent['column_count'] ?? null;
        $parentBox = is_array($parentNode['box'] ?? null) ? $parentNode['box'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        if ( ! is_int($columns) || $columns < 2 || ! is_numeric($parentBox['width'] ?? null) ) {
            return array();
        }

        $flowChildren = array_values(array_filter(
            $this->childrenInEmissionOrder($parentNode),
            function (mixed $child) use ($parentNode): bool {
                if (
                    ! is_array($child)
                    || ! $this->normalFlexFlowChild($child, $parentNode)
                    || 'absolute' === ($child['layout']['positioning'] ?? null)
                ) {
                    return false;
                }

                $childBox = is_array($child['box'] ?? null) ? $child['box'] : $child;
                $stackingPlan = $this->layoutIntentClassifier()->stackingContextPlan($child, $parentNode);
                return null === ($stackingPlan['z_index'] ?? null)
                    && is_numeric($childBox['x'] ?? null)
                    && is_numeric($childBox['y'] ?? null);
            }
        ));
        $index = null;
        foreach ( $flowChildren as $position => $child ) {
            if ( (string) ($child['id'] ?? '') === (string) ($node['id'] ?? '') ) {
                $index = $position;
                break;
            }
        }
        if ( null === $index ) {
            return array();
        }

        $sourceOffset = $this->layoutIntentClassifier()->positionOffset($box, $parentBox, 'x', $parentNode);
        $parentWidth = (float) $parentBox['width'];
        $gap = is_numeric($layoutIntent['gap'] ?? null) ? (float) $layoutIntent['gap'] : 0.0;
        $trackWidth = ($parentWidth - ($gap * ($columns - 1))) / $columns;
        if ( null === $sourceOffset || $trackWidth <= 0.0 ) {
            return array();
        }

        $gridOffset = ($index % $columns) * ($trackWidth + $gap);
        $residual = $sourceOffset - $gridOffset;
        if ( abs($residual) <= 0.5 ) {
            return array();
        }

        return array('margin-left:' . $this->number($residual) . 'px');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textShouldUseFluidFlowBox(array $node, ?array $parentNode): bool
    {
        if ( 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) || (null !== $parentNode && $this->isFreeformContainer($parentNode) && ! $this->freeformContainerShouldUseFlow($parentNode)) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $hasResponsiveWidth = isset($box['width']) && is_numeric($box['width']) && (float) $box['width'] >= 280.0;
        if ( null === $parentNode ) {
            $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
            $textAutoResize = strtoupper((string) ($text['auto_resize'] ?? $node['text_auto_resize'] ?? ''));
            $fontSize = $this->textFontSize($node);
            return $hasResponsiveWidth
                && $this->textHasDerivedLineBreaks($node)
                && ! $this->textHasLineBreaks($node)
                && ! in_array($textAutoResize, array('HEIGHT', 'WIDTH_AND_HEIGHT'), true)
                && (null === $fontSize || $fontSize <= 96.0);
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $textIntent = str_contains($name, 'paragraph')
            || str_contains($name, 'body')
            || str_contains($name, 'copy')
            || str_contains($name, 'lede')
            || str_contains($name, 'supporting text');
        if ( ! $textIntent ) {
            return false;
        }

        return $hasResponsiveWidth;
    }

    /**
     * Let ordinary wrapping text expand beyond its measured Figma box when a
     * browser substitutes fonts with different metrics.
     *
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textShouldUseIntrinsicFlowHeight(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) || '' === trim($this->nodePlainText($node)) ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) || 'flex' !== ($parentLayout['display'] ?? null) || 'column' !== ($parentLayout['flex_direction'] ?? null) || true === ($parentLayout['clips_content'] ?? false) ) {
            return false;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        return ($this->textIsLongFallbackWrappingHeading($node) || ! $this->textIsAtomicSingleLineLabel($node, $text))
            && $this->textSourceBoxAllowsMultipleLines($node, $text);
    }

    /** @param array<string, mixed> $node */
    private function textIsLongFallbackWrappingHeading(array $node): bool
    {
        $name = strtolower((string) ($node['name'] ?? ''));
        return strlen(trim($this->nodePlainText($node))) > 32 && (bool) preg_match('/\b(?:heading|headline|title)\b/', $name);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $text
     */
    private function textSourceBoxAllowsMultipleLines(array $node, array $text): bool
    {
        if ( $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) {
            return true;
        }

        if ( $this->textIsLongFallbackWrappingHeading($node) ) {
            return true;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        $lineHeight = null;
        foreach ( array('line_height_px', 'line_height') as $key ) {
            if ( isset($style[$key]) && is_numeric($style[$key]) && (float) $style[$key] > 0.0 ) {
                $lineHeight = (float) $style[$key];
                break;
            }
        }
        if ( null === $lineHeight ) {
            $fontSize = $this->textFontSize($node);
            $lineHeight = null === $fontSize ? null : $fontSize * 1.2;
        }

        return null !== $height && null !== $lineHeight && $height > $lineHeight * 1.25;
    }

    /** @param array<string, mixed> $node */
    private function flexContainerShouldUseIntrinsicFlowHeight(array $node): bool
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $usesFlow = 'flex' === ($layout['display'] ?? null) || $this->freeformContainerShouldUseFlow($node);
        if ( ! $usesFlow || true === ($layout['clips_content'] ?? false) ) {
            return false;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( ! is_array($child) ) {
                continue;
            }
            $childLayout = is_array($child['layout'] ?? null) ? $child['layout'] : array();
            if ( 'absolute' === ($childLayout['positioning'] ?? null) ) {
                continue;
            }
            if ( $this->textShouldUseIntrinsicFlowHeight($child, $node) || $this->flexContainerShouldUseIntrinsicFlowHeight($child) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function textFlowMaxWidth(array $node, float $pixelWidth): string
    {
        $px = $this->number($pixelWidth) . 'px';
        $characters = trim(strip_tags($this->textContent($node)));
        if ( '' === $characters ) {
            return $px;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $wordCount = $this->textWordCount($node);
        if ( $wordCount >= 12 || $this->hasBodyTextNameIntent($name) ) {
            return 'min(' . $px . ',72ch)';
        }
        if ( str_contains($name, 'title') || str_contains($name, 'heading') || str_contains($name, 'headline') ) {
            return 'min(' . $px . ',18ch)';
        }

        return $px;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     * @return array<int, string>
     */
    private function textWrappingStyles(array $node, ?array $parentNode, ?array $grandParentNode): array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( ($this->textIsAtomicSingleLineLabel($node, $text) && ! $this->textIsLongFallbackWrappingHeading($node)) || $this->textShouldPreserveChromeSpacing($node, $parentNode, $grandParentNode) ) {
            return array();
        }

        $styles = array('overflow-wrap:break-word');
        $tag = $this->semanticTag($node, strtoupper((string) ($node['type'] ?? '')), strtolower((string) ($node['name'] ?? '')), 1, $parentNode, $grandParentNode);
        if ( in_array($tag, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true) ) {
            $styles[] = 'text-wrap:balance';
        } elseif ( 'p' === $tag || $this->hasBodyTextNameIntent(strtolower((string) ($node['name'] ?? ''))) ) {
            $styles[] = 'hyphens:auto';
            $styles[] = 'text-wrap:pretty';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $parentNode
     */
    private function normalFlexFlowChild(array $node, array $parentNode): bool
    {
        if ( $this->stickyLayoutCoordinator()->isSuppressedStickyGhost($node) || false === ($node['visible'] ?? null) ) {
            return false;
        }
        if ( $this->isFullyClippedDecorativeChild($node, $parentNode) || $this->isDecorativeFlexUnderlay($node, $parentNode) ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textContent(array $node, ?array $parentNode = null): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        if ( ! empty($segments) ) {
            $content = '';
            foreach ( $segments as $segment ) {
                if ( ! is_array($segment) ) {
                    continue;
                }

                $segmentText = (string) ($segment['characters'] ?? '');
                if ( '' === $segmentText ) {
                    continue;
                }

                $content .= $this->segmentRunHtml($segmentText, is_array($segment['style'] ?? null) ? $segment['style'] : null);
            }

            if ( '' !== $content ) {
                return $content;
            }
        }

        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            $characters = (string) $text['characters'];
            if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
                return '';
            }

            return $this->sanitizeText($this->normalizeTextContentWhitespace($characters));
        }

        $characters = (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return '';
        }

        return $this->sanitizeText($this->normalizeTextContentWhitespace($characters));
    }

    private function normalizeTextContentWhitespace(string $characters): string
    {
        $characters = str_replace(array("\r\n", "\r"), "\n", trim($characters));

        return preg_replace('/[^\S\n]+/u', ' ', $characters) ?? $characters;
    }

    /**
     * Packed navigation text is a single visual text layer containing labels
     * separated by designer-authored spacing. Preserve the layer and spacing,
     * but restore links for labels that resolve to planned routes or anchors.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function packedNavigationTextContent(array $node, ?array $parentNode): ?string
    {
        $characters = $this->rawTextCharacters($node);
        if ( ! $this->isPackedNavigationRouteText($node, $parentNode, $characters) ) {
            return null;
        }

        $parts = preg_split('/(\s{2,})/', $characters, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ( ! is_array($parts) ) {
            return null;
        }

        $content = '';
        foreach ( $parts as $part ) {
            if ( '' === $part ) {
                continue;
            }

            if ( 1 === preg_match('/^\s+$/', $part) ) {
                $content .= $this->sanitizeText($part);
                continue;
            }

            $label = trim($part);
            $href = $this->routePathForLabel($label, $node, $parentNode, true)
                ?? $this->currentPageAnchorHrefForLabel($label);
            if ( null === $href ) {
                $content .= $this->sanitizeText($part);
                continue;
            }

            $this->linkState->increment('implicit_route_links');
            $this->linkState->increment('anchors_emitted');
            $content .= sprintf(
                '<a class="figma-link" href="%1$s" data-figma-link-type="implicit-route">%2$s</a>',
                $this->sanitizeAttribute($href),
                $this->sanitizeText($part)
            );
        }

        return '' !== $content ? $content : null;
    }

    /** @param array<string, mixed> $node */
    private function rawTextCharacters(array $node): string
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( isset($text['characters']) && is_scalar($text['characters']) ) {
            return (string) $text['characters'];
        }

        return (string) ($node['characters'] ?? $node['text'] ?? '');
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     */
    private function isPackedNavigationRouteText(array $node, ?array $parentNode, string $characters): bool
    {
        $trimmed = trim($characters);
        if ( '' === $trimmed || ! preg_match('/\s{2,}/', $characters) ) {
            return false;
        }

        if ( str_word_count($trimmed) > 24 || strlen($trimmed) > 240 ) {
            return false;
        }

        $context = strtolower((string) ($node['name'] ?? ''));
        if ( null !== $parentNode ) {
            $context .= ' ' . strtolower((string) ($parentNode['name'] ?? ''));
        }
        if ( ! preg_match('/\b(nav|navigation|menu|header|footer|link|links)\b/', $context) ) {
            return false;
        }

        $labels = preg_split('/\s{2,}/', $trimmed);
        if ( ! is_array($labels) || count($labels) < 2 ) {
            return false;
        }

        $linkable = 0;
        foreach ( $labels as $label ) {
            $label = trim((string) $label);
            if ( '' === $label ) {
                continue;
            }
            if ( $this->hasImplicitRouteForLabel($label) || null !== $this->currentPageAnchorHrefForLabel($label) ) {
                $linkable++;
            }
        }

        return $linkable >= 2 && $linkable >= (int) ceil(count($labels) / 2);
    }

    /**
     * Render source-backed Figma text lists as semantic HTML lists.
     *
     * Figma can encode an ordered/bulleted list as one TEXT node whose visible
     * characters are only the item bodies; marker data lives in TextLineData.
     * Plain text rendering drops those generated markers, so use the source line
     * metadata when every rendered line maps to a list item.
     *
     * @param array<string, mixed> $node
     * @return array{tag: string, content: string, start?: int}|null
     */
    private function sourceTextListMarkup(array $node): ?array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        if ( $this->renderTextGlyphPaths && ! empty($derivedLayout['glyph_paths']) && $this->textAllowsGlyphRendering((string) ($text['characters'] ?? ''), $text) ) {
            return null;
        }

        $lines = is_array($derivedLayout['lines'] ?? null) ? array_values(array_filter($derivedLayout['lines'], 'is_array')) : array();
        if ( empty($lines) ) {
            return null;
        }

        $listKinds = array();
        foreach ( $lines as $line ) {
            $kind = $this->sourceTextLineListKind($line);
            if ( null === $kind ) {
                return null;
            }
            $listKinds[] = $kind;
        }

        $characters = isset($text['characters']) && is_scalar($text['characters'])
            ? (string) $text['characters']
            : (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( '' === trim($characters) || $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
            return null;
        }

        $lineItems = $this->sourceTextListLineItems($node, $text, $characters, $lines);
        if ( count($lineItems) !== count($lines) ) {
            return null;
        }

        $rootKind = $listKinds[0];
        $rootIndent = $this->sourceTextListIndent($lines[0]);
        $stack = array();
        $content = '';
        foreach ( $lineItems as $index => $lineItem ) {
            $line = $lineItem['line'];
            $kind = $listKinds[$index];
            $indent = $this->sourceTextListIndent($line);
            $item = $this->sourceTextListItemHtml($lineItem['html']);
            if ( '' === $item || $indent < $rootIndent ) {
                return null;
            }

            if ( $lineItem['continues_previous'] && ! empty($stack) ) {
                $current = $stack[count($stack) - 1];
                if ( $indent === $current['indent'] && $kind === $this->sourceTextListKindForTag($current['tag']) ) {
                    $content .= '<br>' . $item;
                    continue;
                }
            }

            while ( ! empty($stack) && $indent < $stack[count($stack) - 1]['indent'] ) {
                $content .= '</li></' . $stack[count($stack) - 1]['tag'] . '>';
                array_pop($stack);
            }

            if ( empty($stack) ) {
                if ( $indent !== $rootIndent || $kind !== $rootKind ) {
                    return null;
                }
            } elseif ( $indent > $stack[count($stack) - 1]['indent'] ) {
                $tag = 'ordered' === $kind ? 'ol' : 'ul';
                $content .= '<' . $tag . $this->sourceNestedListAttributes($tag, $line) . '>';
                $stack[] = array('indent' => $indent, 'tag' => $tag);
            } elseif ( $kind !== $this->sourceTextListKindForTag($stack[count($stack) - 1]['tag']) ) {
                $content .= '</li></' . $stack[count($stack) - 1]['tag'] . '>';
                array_pop($stack);
                if ( empty($stack) || $indent !== $stack[count($stack) - 1]['indent'] ) {
                    return null;
                }
                $tag = 'ordered' === $kind ? 'ol' : 'ul';
                $content .= '<' . $tag . $this->sourceNestedListAttributes($tag, $line) . '>';
                $stack[] = array('indent' => $indent, 'tag' => $tag);
            } else {
                $content .= '</li>';
            }

            if ( empty($stack) ) {
                $tag = 'ordered' === $kind ? 'ol' : 'ul';
                $content .= '<' . $tag . $this->sourceNestedListAttributes($tag, $line) . '>';
                $stack[] = array('indent' => $indent, 'tag' => $tag);
            }
            $content .= '<li>' . $item;
        }

        while ( ! empty($stack) ) {
            $content .= '</li></' . $stack[count($stack) - 1]['tag'] . '>';
            array_pop($stack);
        }

        $tag = 'ordered' === $rootKind ? 'ol' : 'ul';
        $open = '<' . $tag . $this->sourceNestedListAttributes($tag, $lines[0]) . '>';
        $close = '</' . $tag . '>';
        if ( str_starts_with($content, $open) && str_ends_with($content, $close) ) {
            $content = substr($content, strlen($open), -strlen($close));
        }

        $result = array(
            'tag'     => $tag,
            'content' => $content,
        );

        if ( 'ol' === $tag ) {
            $start = $this->sourceTextListStart($lines[0]);
            if ( null !== $start && 1 !== $start ) {
                $result['start'] = $start;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function sourceTextListIndent(array $line): int
    {
        return isset($line['indentation_level']) && is_numeric($line['indentation_level']) ? max(0, (int) $line['indentation_level']) : 0;
    }

    private function sourceTextListKindForTag(string $tag): string
    {
        return 'ol' === $tag ? 'ordered' : 'unordered';
    }

    /**
     * @param array<string, mixed> $line
     */
    private function sourceNestedListAttributes(string $tag, array $line): string
    {
        $attributes = ' style="list-style:' . ( 'ol' === $tag ? 'decimal' : 'disc' ) . ';padding-left:1.5em"';
        if ( 'ol' === $tag ) {
            $start = $this->sourceTextListStart($line);
            if ( null !== $start && 1 !== $start ) {
                $attributes .= ' start="' . $this->sanitizeAttribute((string) $start) . '"';
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $text
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array{line: array<string, mixed>, html: string, continues_previous: bool}>
     */
    private function sourceTextListLineItems(array $node, array $text, string $characters, array $lines): array
    {
        $lineHtml = $this->sourceTextListLineHtml($node, $text, $characters, count($lines));
        if ( count($lineHtml) !== count($lines) ) {
            return array();
        }

        $hardBreakBefore = $this->sourceTextListHardBreakBeforeMap($text, $characters, count($lines));
        $items = array();
        foreach ( $lines as $index => $line ) {
            $continuesPrevious = $index > 0
                && false === ($line['is_first_line_of_list'] ?? null)
                && false === ($hardBreakBefore[$index] ?? true);
            $items[] = array(
                'line'               => $line,
                'html'               => $lineHtml[$index],
                'continues_previous' => $continuesPrevious,
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $text
     * @return array<int, bool>
     */
    private function sourceTextListHardBreakBeforeMap(array $text, string $characters, int $lineCount): array
    {
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( count($baselines) !== $lineCount ) {
            return array_fill(0, $lineCount, true);
        }

        $hardBreakBefore = array_fill(0, $lineCount, true);
        for ( $index = 1; $index < $lineCount; $index++ ) {
            $previous = is_array($baselines[$index - 1] ?? null) ? $baselines[$index - 1] : array();
            if ( ! isset($previous['endCharacter']) || ! is_numeric($previous['endCharacter']) ) {
                continue;
            }
            $offset = (int) $previous['endCharacter'];
            $hardBreakBefore[$index] = "\n" === mb_substr($characters, $offset, 1);
        }

        return $hardBreakBefore;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $text
     * @return array<int, string>
     */
    private function sourceTextListLineHtml(array $node, array $text, string $characters, int $lineCount): array
    {
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( ! empty($segments) && count($baselines) === $lineCount ) {
            $lines = array();
            foreach ( $baselines as $baseline ) {
                if ( ! is_array($baseline) || ! isset($baseline['firstCharacter'], $baseline['endCharacter']) || ! is_numeric($baseline['firstCharacter']) || ! is_numeric($baseline['endCharacter']) ) {
                    return array();
                }
                $lines[] = $this->sourceTextListSegmentRangeHtml($segments, (int) $baseline['firstCharacter'], (int) $baseline['endCharacter']);
            }
            return $lines;
        }

        $lineTexts = preg_split('/\R/u', $this->derivedLineBreakText($characters, $text));
        if ( ! is_array($lineTexts) || count($lineTexts) !== $lineCount ) {
            return array();
        }

        return array_map(fn (string $lineText): string => $this->sanitizeText($lineText), array_values($lineTexts));
    }

    /**
     * @param array<int, mixed> $segments
     */
    private function sourceTextListSegmentRangeHtml(array $segments, int $start, int $end): string
    {
        $html = '';
        $cursor = 0;
        foreach ( $segments as $segment ) {
            if ( ! is_array($segment) || ! isset($segment['characters']) || ! is_scalar($segment['characters']) ) {
                continue;
            }
            $segmentText = (string) $segment['characters'];
            $length = mb_strlen($segmentText);
            $segmentStart = $cursor;
            $segmentEnd = $cursor + $length;
            $cursor = $segmentEnd;
            if ( $segmentEnd <= $start || $segmentStart >= $end ) {
                continue;
            }
            $sliceStart = max(0, $start - $segmentStart);
            $sliceLength = min($segmentEnd, $end) - max($segmentStart, $start);
            if ( $sliceLength <= 0 ) {
                continue;
            }
            $html .= $this->segmentRunHtml(mb_substr($segmentText, $sliceStart, $sliceLength), is_array($segment['style'] ?? null) ? $segment['style'] : null);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function sourceTextLineListKind(array $line): ?string
    {
        $lineType = strtoupper((string) ($line['line_type'] ?? ''));
        if ( '' === $lineType ) {
            return null;
        }

        if ( str_contains($lineType, 'BULLET') || str_contains($lineType, 'UNORDERED') ) {
            return 'unordered';
        }

        if ( str_contains($lineType, 'ORDER') || str_contains($lineType, 'NUMBER') ) {
            return 'ordered';
        }

        return null;
    }

    private function sourceTextListItemHtml(string $lineHtml): string
    {
        $lineHtml = trim($lineHtml);
        $lineHtml = preg_replace('/^\s*(?:[\x{2022}\x{2023}\x{25E6}\x{2043}\x{2219}\-*+]|\d+[.)])\s+/u', '', $lineHtml);

        return null === $lineHtml ? '' : trim($lineHtml);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function sourceTextListStart(array $line): ?int
    {
        if ( ! isset($line['list_start_offset']) || ! is_numeric($line['list_start_offset']) ) {
            return null;
        }

        return max(1, (int) $line['list_start_offset']);
    }

    /**
     * Renders a single styled text run, wrapping it in a minimal `<span style>`
     * only when the run carries overriding style declarations. Mirrors the inline
     * segment rendering in {@see textContent} so per-character override spans
     * (color/weight/etc.) emit identically whether the text node is a single
     * element or split into per-paragraph boxes.
     *
     * @param array<string, mixed>|null $style
     */
    private function segmentRunHtml(string $characters, ?array $style): string
    {
        if ( '' === $characters ) {
            return '';
        }

        $segmentStyles = is_array($style) ? $this->textStyleDeclarations($style) : array();
        if ( empty($segmentStyles) ) {
            return $this->sanitizeText($characters);
        }

        return '<span style="' . $this->sanitizeAttribute(implode(';', $segmentStyles)) . '">' . $this->sanitizeText($characters) . '</span>';
    }

    /**
     * Splits a text node into per-paragraph buckets of styled runs.
     *
     * Figma encodes a hard paragraph break (the Enter key, the boundary
     * `paragraphSpacing` applies between) as a `\n` in the node's characters.
     * Soft line wraps are not present in the source characters — they are
     * recovered separately as derived baselines — so this split keys only on the
     * real `\n` separators and never treats a wrapped line as a paragraph.
     *
     * Each bucket is an ordered list of `['characters' => string, 'style' =>
     * ?array]` runs. When a styled run straddles a `\n` it is divided at the
     * break and the same style is carried into both paragraphs, so per-character
     * override spans land in the correct paragraph. Leading/trailing empty
     * paragraphs (from a stray boundary `\n`) are dropped; interior blank
     * paragraphs are preserved.
     *
     * @param array<string, mixed> $node
     * @return array<int, array<int, array{characters: string, style: array<string, mixed>|null}>>
     */
    private function paragraphBuckets(array $node): array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();

        $runs = array();
        foreach ( $segments as $segment ) {
            if ( ! is_array($segment) ) {
                continue;
            }
            $segmentText = (string) ($segment['characters'] ?? '');
            if ( '' === $segmentText ) {
                continue;
            }
            $runs[] = array(
                'characters' => $segmentText,
                'style'      => is_array($segment['style'] ?? null) ? $segment['style'] : null,
            );
        }

        if ( empty($runs) ) {
            $characters = isset($text['characters']) && is_scalar($text['characters'])
                ? (string) $text['characters']
                : (string) ($node['characters'] ?? $node['text'] ?? '');
            if ( '' === $characters || $this->isUnresolvedComponentPlaceholderText($node, $characters) ) {
                return array();
            }
            $runs[] = array('characters' => $characters, 'style' => null);
        }

        $paragraphs = array(array());
        foreach ( $runs as $run ) {
            $parts = explode("\n", (string) $run['characters']);
            foreach ( $parts as $index => $part ) {
                if ( $index > 0 ) {
                    $paragraphs[] = array();
                }
                if ( '' !== $part ) {
                    $paragraphs[count($paragraphs) - 1][] = array(
                        'characters' => $part,
                        'style'      => $run['style'],
                    );
                }
            }
        }

        // Drop empty paragraphs at the head and tail (a stray boundary newline),
        // while keeping interior blank paragraphs that carry a real blank line.
        while ( ! empty($paragraphs) && empty($paragraphs[0]) ) {
            array_shift($paragraphs);
        }
        while ( ! empty($paragraphs) && empty($paragraphs[count($paragraphs) - 1]) ) {
            array_pop($paragraphs);
        }

        return array_values($paragraphs);
    }

    /**
     * Whether a text node carries real paragraph spacing that can be rendered by
     * splitting it into separate per-paragraph boxes.
     *
     * Requires a positive `paragraphSpacing` and at least two real paragraphs
     * (`\n`-separated). Glyph-rendered text has no paragraph boxes to carry a
     * margin, so it is excluded and reported via {@see paragraphSpacingDiagnostic}.
     *
     * @param array<string, mixed> $node
     */
    private function shouldSplitParagraphs(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['paragraph_spacing']) || ! is_numeric($style['paragraph_spacing']) || 0.0 >= (float) $style['paragraph_spacing'] ) {
            return false;
        }

        if ( null !== $this->textGlyphSvg($node) ) {
            return false;
        }

        return count($this->paragraphBuckets($node)) >= 2;
    }

    /**
     * Renders a multi-paragraph text node as one block element per paragraph so
     * Figma `paragraphSpacing` lands as a real `margin-bottom` between paragraphs.
     *
     * Each paragraph is a block-level `<span>` (valid inside the node's `<p>` /
     * heading container) and carries the spacing as `margin-bottom` on every
     * paragraph except the last. Inline override spans are preserved within each
     * paragraph via {@see segmentRunHtml}. Returns null when the node is not a
     * splittable multi-paragraph node, so the caller falls back to {@see
     * textContent}.
     *
     * @param array<string, mixed> $node
     */
    private function multiParagraphTextContent(array $node): ?string
    {
        if ( ! $this->shouldSplitParagraphs($node) ) {
            return null;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        $spacing = (float) $style['paragraph_spacing'];

        $paragraphs = $this->paragraphBuckets($node);
        $last = count($paragraphs) - 1;

        $html = '';
        foreach ( $paragraphs as $index => $runs ) {
            $inner = '';
            foreach ( $runs as $run ) {
                $inner .= $this->segmentRunHtml((string) $run['characters'], $run['style']);
            }

            $styles = array('display:block');
            if ( $index < $last ) {
                $styles[] = 'margin-bottom:' . $this->number($spacing) . 'px';
            }

            $html .= '<span style="' . $this->sanitizeAttribute(implode(';', $styles)) . '">' . $inner . '</span>';
        }

        return '' === $html ? null : $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isUnresolvedComponentPlaceholderText(array $node, string $characters): bool
    {
        $placeholder = strtolower(trim($characters));
        if ( ! in_array($placeholder, array('button label', 'label'), true) ) {
            return false;
        }

        $id = (string) ($node['id'] ?? '');
        return str_contains($id, '/') || isset($node['figma_component_source_id']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textGlyphSvg(array $node): ?string
    {
        if ( ! $this->renderTextGlyphPaths ) {
            return null;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $glyphPaths = is_array($derivedLayout['glyph_paths'] ?? null) ? $derivedLayout['glyph_paths'] : array();
        if ( empty($glyphPaths) ) {
            return null;
        }

        $label = isset($text['characters']) && is_scalar($text['characters']) ? (string) $text['characters'] : (string) ($node['characters'] ?? $node['text'] ?? '');
        if ( ! $this->textAllowsGlyphRendering($label, $text) ) {
            return null;
        }

        $size = is_array($derivedLayout['size'] ?? null) ? $derivedLayout['size'] : array();
        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($size['width']) && is_numeric($size['width']) ? (float) $size['width'] : ( isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0 );
        $height = isset($size['height']) && is_numeric($size['height']) ? (float) $size['height'] : ( isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0 );
        if ( 0.0 >= $width || 0.0 >= $height ) {
            return null;
        }

        $paths = '';
        $cursors = array();
        foreach ( $glyphPaths as $glyphPath ) {
            if ( ! is_array($glyphPath) ) {
                continue;
            }

            $fontSize = isset($glyphPath['fontSize']) && is_numeric($glyphPath['fontSize']) ? (float) $glyphPath['fontSize'] : $this->textGlyphFallbackFontSize($text);
            $baseline = $this->textGlyphBaseline($glyphPath, $derivedLayout);
            $baselineKey = (string) $baseline['index'];
            if ( ! isset($cursors[$baselineKey]) ) {
                $cursors[$baselineKey] = $baseline['x'];
            }
            $x = isset($glyphPath['position_x']) && is_numeric($glyphPath['position_x']) ? (float) $glyphPath['position_x'] : ( isset($glyphPath['x']) && is_numeric($glyphPath['x']) ? (float) $glyphPath['x'] : (float) $cursors[$baselineKey] );
            $y = isset($glyphPath['position_y']) && is_numeric($glyphPath['position_y']) ? (float) $glyphPath['position_y'] : ( isset($glyphPath['y']) && is_numeric($glyphPath['y']) ? (float) $glyphPath['y'] : $baseline['y'] );
            $transform = 'translate(' . $this->number($x) . ' ' . $this->number($y) . ')';
            if ( 0.0 < $fontSize ) {
                $transform .= ' scale(' . $this->number($fontSize) . ' -' . $this->number($fontSize) . ')';
            }
            if ( isset($glyphPath['advance']) && is_numeric($glyphPath['advance']) ) {
                $cursors[$baselineKey] += (float) $glyphPath['advance'] * ( 0.0 < $fontSize ? $fontSize : 1.0 );
            }
            if ( ! isset($glyphPath['data']) || ! is_scalar($glyphPath['data']) ) {
                continue;
            }
            if ( isset($glyphPath['character']) && is_scalar($glyphPath['character']) && '' !== (string) $glyphPath['character'] && ctype_space((string) $glyphPath['character']) ) {
                continue;
            }

            $attributes = ' d="' . $this->sanitizeAttribute((string) $glyphPath['data']) . '" fill="currentColor" transform="' . $transform . '"';
            $paths .= '<path' . $attributes . '></path>';
        }

        if ( '' === $paths ) {
            return null;
        }

        return '<svg class="figma-text-glyphs" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $this->number($width) . ' ' . $this->number($height) . '" width="100%" height="100%" role="img" aria-label="' . $this->sanitizeAttribute($label) . '" data-figma-text-glyphs="true">' . $paths . '</svg>';
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textAllowsGlyphRendering(string $characters, array $text): bool
    {
        if ( $this->textNeedsDomSymbolFallback($characters) ) {
            return false;
        }

        if ( mb_strlen($characters) > 80 ) {
            return false;
        }

        if ( mb_strlen($characters) > 45 && 1 === preg_match('/[.!?。！？]$/u', trim($characters)) ) {
            return false;
        }

        if ( str_contains($characters, "\n") && ! $this->textLooksLikeDisplayText($text) ) {
            return false;
        }

        if ( ! empty($text['segments'] ?? array()) ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textLooksLikeDisplayText(array $text): bool
    {
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( isset($style['font_weight']) && is_numeric($style['font_weight']) && 700 <= (float) $style['font_weight'] ) {
            return true;
        }

        if ( isset($style['font_size']) && is_numeric($style['font_size']) && 30 <= (float) $style['font_size'] ) {
            return true;
        }

        $derivedLineHeight = $this->textDerivedBaselineLineHeight($text);
        return null !== $derivedLineHeight && 36 <= $derivedLineHeight;
    }

    private function textNeedsDomSymbolFallback(string $characters): bool
    {
        return 1 === preg_match('/[✔✖✕✓✗•▪■□☑]/u', $characters);
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textGlyphFallbackFontSize(array $text): float
    {
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        return isset($style['font_size']) && is_numeric($style['font_size']) ? (float) $style['font_size'] : 0.0;
    }

    /**
     * @param array<string, mixed> $glyphPath
     * @param array<string, mixed> $derivedLayout
     * @return array{index: int, x: float, y: float}
     */
    private function textGlyphBaseline(array $glyphPath, array $derivedLayout): array
    {
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        $character = isset($glyphPath['firstCharacter']) && is_numeric($glyphPath['firstCharacter']) ? (float) $glyphPath['firstCharacter'] : null;
        foreach ( $baselines as $index => $baseline ) {
            if ( ! is_array($baseline) ) {
                continue;
            }
            $x = isset($baseline['position_x']) && is_numeric($baseline['position_x']) ? (float) $baseline['position_x'] : 0.0;
            $y = isset($baseline['position_y']) && is_numeric($baseline['position_y']) ? (float) $baseline['position_y'] : ( isset($baseline['lineAscent']) && is_numeric($baseline['lineAscent']) ? (float) $baseline['lineAscent'] : 0.0 );
            if ( null === $character || ! isset($baseline['firstCharacter'], $baseline['endCharacter']) || ! is_numeric($baseline['firstCharacter']) || ! is_numeric($baseline['endCharacter']) ) {
                return array('index' => (int) $index, 'x' => $x, 'y' => $y);
            }
            if ( $character >= (float) $baseline['firstCharacter'] && $character < (float) $baseline['endCharacter'] ) {
                return array('index' => (int) $index, 'x' => $x, 'y' => $y);
            }
        }

        return array('index' => 0, 'x' => 0.0, 'y' => 0.0);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function textStyles(array $node, ?array $parentNode = null, ?array $grandParentNode = null): array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( $this->isSemanticListItemBodyText($node, $parentNode, $grandParentNode) && $this->textStyleHasUnprovenUppercaseTransform($node, $style) ) {
            unset($style['text_transform']);
        }
        if ( ! isset($style['color']) ) {
            $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
            $color = $this->firstSolidPaint($paints);
            if ( null !== $color ) {
                $style['css_color'] = $color;
            }
        }

        $styles = $this->textStyleDeclarations($style);
        $derivedLineHeight = $this->textDerivedBaselineLineHeight($text);
        if ( null !== $derivedLineHeight && 0.0 < $derivedLineHeight ) {
            $styles = array_values(array_filter(
                $styles,
                static fn (string $style): bool => ! str_starts_with($style, 'line-height:')
            ));
            $styles[] = 'line-height:' . $this->number($derivedLineHeight) . 'px';
        }
        if ( $this->textShouldPreserveChromeSpacing($node, $parentNode, $grandParentNode) ) {
            $styles[] = 'white-space:pre-wrap';
        } elseif ( $this->textHasLineBreaks($node) && ! $this->shouldSplitParagraphs($node) ) {
            $styles[] = 'white-space:pre-line';
        } elseif ( $this->textIsAtomicSingleLineLabel($node, $text) ) {
            $styles[] = 'white-space:nowrap';
        }

        return $styles;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     */
    private function isSemanticListItemBodyText(array $node, ?array $parentNode, ?array $grandParentNode): bool
    {
        if ( null === $parentNode || null === $grandParentNode || ! $this->nodeHasTextContent($node) ) {
            return false;
        }

        return $this->isListItemOf($parentNode, $grandParentNode) && ! $this->isListMarkerTextChild($node);
    }

    /** @param array<string, mixed> $node */
    private function nodeHasTextContent(array $node): bool
    {
        if ( 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ) {
            return true;
        }

        if ( is_array($node['figma_text'] ?? null) && '' !== trim($this->rawDecodedText($node)) ) {
            return true;
        }

        return isset($node['characters']) && is_scalar($node['characters']) && '' !== trim((string) $node['characters']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $style
     */
    private function textStyleHasUnprovenUppercaseTransform(array $node, array $style): bool
    {
        if ( 'uppercase' !== strtolower((string) ($style['text_transform'] ?? '')) ) {
            return false;
        }

        if ( ! $this->textContainsLowercase($this->rawDecodedText($node)) ) {
            return false;
        }

        return ! $this->hasExplicitUppercaseTextCase($node);
    }

    private function hasBodyTextNameIntent(string $lowerName): bool
    {
        foreach ( array('paragraph', 'body', 'supporting text', 'caption', 'description', 'excerpt', 'copy') as $needle ) {
            if ( str_contains($lowerName, $needle) ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source */
    private function hasExplicitUppercaseTextCase(array $source): bool
    {
        foreach ( array('textCase', 'text_case') as $key ) {
            if ( isset($source[$key]) && is_scalar($source[$key]) && 'UPPER' === strtoupper((string) $source[$key]) ) {
                return true;
            }
        }

        foreach ( array('style', 'textData', 'derivedTextData') as $key ) {
            if ( is_array($source[$key] ?? null) && $this->hasExplicitUppercaseTextCase($source[$key]) ) {
                return true;
            }
        }

        return false;
    }

    private function textContainsLowercase(string $text): bool
    {
        return 1 === preg_match('/\p{Ll}/u', $text);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parentNode
     * @param array<string, mixed>|null $grandParentNode
     */
    private function textShouldPreserveChromeSpacing(array $node, ?array $parentNode, ?array $grandParentNode): bool
    {
        $characters = $this->rawDecodedText($node);
        if ( ! preg_match('/ {2,}/', $characters) ) {
            return false;
        }

        foreach ( array($parentNode, $grandParentNode) as $candidate ) {
            if ( is_array($candidate) && $this->isFooterChromeNode($candidate, null, 1) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports a Figma `paragraphSpacing` value that genuinely cannot be applied.
     *
     * Multi-paragraph text is normally split into per-paragraph boxes that carry
     * the spacing as `margin-bottom` ({@see multiParagraphTextContent}), so no
     * diagnostic is emitted in that case. The value is only surfaced as an `info`
     * diagnostic when the node has multiple real paragraphs but cannot be split —
     * for example glyph-rendered text, which has no paragraph boxes to carry a
     * margin. Single-paragraph nodes (including soft-wrap-only text) are ignored
     * because paragraph spacing has no paragraph boundary to apply between.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function paragraphSpacingDiagnostic(array $node): ?array
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $style = is_array($text['style'] ?? null) ? $text['style'] : array();
        if ( ! isset($style['paragraph_spacing']) || ! is_numeric($style['paragraph_spacing']) || 0.0 >= (float) $style['paragraph_spacing'] ) {
            return null;
        }

        // Spacing is actually applied as per-paragraph margins — nothing to report.
        if ( $this->shouldSplitParagraphs($node) ) {
            return null;
        }

        // Only a node with multiple real paragraphs that could not be split is a
        // genuine "not applied" case. Single-paragraph text has no boundary.
        if ( count($this->paragraphBuckets($node)) < 2 ) {
            return null;
        }

        return array(
            'severity' => 'info',
            'code'     => 'paragraph_spacing_not_applied',
            'message'  => 'Figma paragraphSpacing could not be applied: this multi-paragraph text node cannot be split into per-paragraph boxes (for example glyph-rendered text); the value is reported but not emitted as CSS.',
            'context'  => array(
                'node_id'           => (string) ($node['id'] ?? ''),
                'paragraph_spacing' => (float) $style['paragraph_spacing'],
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textHasLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $segments = is_array($text['segments'] ?? null) ? $text['segments'] : array();
        foreach ( $segments as $segment ) {
            if ( is_array($segment) && isset($segment['characters']) && is_scalar($segment['characters']) && str_contains((string) $segment['characters'], "\n") ) {
                return true;
            }
        }

        foreach ( array($text['characters'] ?? null, $node['characters'] ?? null, $node['text'] ?? null) as $value ) {
            if ( is_scalar($value) && str_contains((string) $value, "\n") ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $text
     */
    private function derivedLineBreakText(string $characters, array $text): string
    {
        if ( str_contains($characters, "\n") ) {
            return $characters;
        }

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( 2 > count($baselines) ) {
            return $characters;
        }

        $chars = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($chars) || empty($chars) ) {
            return $characters;
        }

        $lines = array();
        foreach ( $baselines as $baseline ) {
            if ( ! is_array($baseline) || ! isset($baseline['firstCharacter'], $baseline['endCharacter']) || ! is_numeric($baseline['firstCharacter']) || ! is_numeric($baseline['endCharacter']) ) {
                return $characters;
            }
            $start = max(0, (int) $baseline['firstCharacter']);
            $end = min(count($chars), (int) $baseline['endCharacter']);
            if ( $end <= $start ) {
                continue;
            }
            $lines[] = implode('', array_slice($chars, $start, $end - $start));
        }

        return empty($lines) ? $characters : implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textHasDerivedLineBreaks(array $node): bool
    {
        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        return isset($derivedLayout['baseline_count']) && is_numeric($derivedLayout['baseline_count']) && 1 < (int) $derivedLayout['baseline_count'];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $text
     */
    private function textIsAtomicSingleLineLabel(array $node, array $text): bool
    {
        if ( $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) {
            return false;
        }

        $characters = trim(strip_tags($this->textContent($node)));
        if ( '' === $characters || ! str_contains($characters, ' ') || strlen($characters) > 48 ) {
            return false;
        }

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        if ( isset($derivedLayout['baseline_count']) && is_numeric($derivedLayout['baseline_count']) && 1 !== (int) $derivedLayout['baseline_count'] ) {
            return false;
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        $sizing = strtoupper((string) ($layout['sizing_horizontal'] ?? ''));
        $textAutoResize = strtoupper((string) ($text['auto_resize'] ?? $node['text_auto_resize'] ?? ''));
        if ( ! in_array($sizing, array('HUG', ''), true) && ! in_array($textAutoResize, array('WIDTH_AND_HEIGHT', 'HEIGHT'), true) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : null;
        $lineHeight = $this->textDerivedBaselineLineHeight($text);
        if ( null === $height || null === $lineHeight || $lineHeight <= 0.0 ) {
            return false;
        }

        return $height <= ( $lineHeight * 1.25 );
    }

    /**
     * @param array<string, mixed> $text
     */
    private function textDerivedBaselineLineHeight(array $text): ?float
    {
        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? $derivedLayout['baselines'] : array();
        if ( empty($baselines) ) {
            return null;
        }

        $baselineDeltaLineHeight = $this->textMedianPositiveBaselinePositionDelta($baselines);
        if ( null !== $baselineDeltaLineHeight ) {
            return $baselineDeltaLineHeight;
        }

        $lineHeights = array();
        foreach ( $baselines as $baseline ) {
            if ( is_array($baseline) && isset($baseline['lineHeight']) && is_numeric($baseline['lineHeight']) && 0.0 < (float) $baseline['lineHeight'] ) {
                $lineHeights[] = (float) $baseline['lineHeight'];
            }
        }
        if ( ! empty($lineHeights) ) {
            sort($lineHeights);
            return $lineHeights[(int) floor(( count($lineHeights) - 1 ) / 2)];
        }

        return null;
    }

    /**
     * @param array<int, mixed> $baselines
     */
    private function textMedianPositiveBaselinePositionDelta(array $baselines): ?float
    {
        $positions = array();
        foreach ( $baselines as $baseline ) {
            if ( is_array($baseline) && isset($baseline['position_y']) && is_numeric($baseline['position_y']) ) {
                $positions[] = (float) $baseline['position_y'];
            }
        }
        if ( 2 > count($positions) ) {
            return null;
        }
        sort($positions);

        $deltas = array();
        for ( $i = 1; $i < count($positions); $i++ ) {
            $delta = $positions[$i] - $positions[$i - 1];
            if ( 0.001 < $delta && 10000.0 > $delta ) {
                $deltas[] = $delta;
            }
        }
        if ( empty($deltas) ) {
            return null;
        }

        sort($deltas);
        return $deltas[(int) floor(( count($deltas) - 1 ) / 2)];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function textShouldAvoidTinyFixedHeight(array $node, float $height): bool
    {
        if ( 0.0 >= $height ) {
            return false;
        }

        $text = is_array($node['figma_text'] ?? null) ? $node['figma_text'] : array();
        if ( '' === trim($this->nodePlainText($node)) || $this->textHasLineBreaks($node) || $this->textHasDerivedLineBreaks($node) ) {
            return false;
        }

        $derivedLayout = is_array($text['derived_layout'] ?? null) ? $text['derived_layout'] : array();
        $baselines = is_array($derivedLayout['baselines'] ?? null) ? array_values(array_filter($derivedLayout['baselines'], 'is_array')) : array();
        if ( 1 !== count($baselines) ) {
            return false;
        }

        $baseline = $baselines[0];
        if ( ! isset($baseline['lineHeight'], $baseline['lineY']) || ! is_numeric($baseline['lineHeight']) || ! is_numeric($baseline['lineY']) ) {
            return false;
        }

        $lineHeight = (float) $baseline['lineHeight'];
        $lineY = (float) $baseline['lineY'];

        return 0.0 > $lineY && $lineHeight > $height + 0.5;
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function textShouldUseMeasuredFlexHeight(array $node, ?array $parentNode): bool
    {
        if ( null === $parentNode || 'TEXT' !== strtoupper((string) ($node['type'] ?? '')) ) {
            return false;
        }

        $parentLayout = is_array($parentNode['layout'] ?? null) ? $parentNode['layout'] : array();
        if ( 'flex' !== ($parentLayout['display'] ?? null) ) {
            return false;
        }

        if ( $this->flexTextShouldUseCenteredLineBox($parentLayout) ) {
            return false;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        return isset($box['height']) && is_numeric($box['height']) && $this->textShouldAvoidTinyFixedHeight($node, (float) $box['height']);
    }

    /**
     * A centered flex parent should center the text line box itself. Preserving
     * Figma's smaller measured glyph box height makes the line box overflow and
     * defeats the parent's cross-axis centering.
     *
     * @param array<string, mixed> $parentLayout
     */
    private function flexTextShouldUseCenteredLineBox(array $parentLayout): bool
    {
        return 'center' === ($parentLayout['align_items'] ?? null);
    }

    /**
     * @param array<string, mixed> $style
     * @return array<int, string>
     */
    private function textStyleDeclarations(array $style): array
    {
        return $this->textStyleDeclarationResolver()->declarations($style, $this->typographyTokenVars);
    }

    /**
     * @param array<string, mixed> $box
     * @return array<int, string>
     */
    private function radiusStyles(array $box): array
    {
        return $this->styleDeclarationBuilder()->radiusStyles($box);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function strokeStyles(array $node): array
    {
        return $this->styleDeclarationBuilder()->strokeStyles($node);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function rendersStrokeInsideInlineSvg(array $node, string $type, ?array $parentNode): bool
    {
        if ( ! in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true) ) {
            return false;
        }

        $strokeStyles = $this->strokeStyles($node);
        if ( empty($strokeStyles) ) {
            return false;
        }

        foreach ( $strokeStyles as $style ) {
            if ( str_starts_with($style, 'border-image:') ) {
                return false;
            }
        }

        return null !== $this->supportedVectorSvg($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function effectStyles(array $node, string $type): array
    {
        return $this->styleDeclarationBuilder()->effectStyles($node, $type);
    }

    /**
     * @param mixed $assets
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssets(mixed $assets, array &$diagnostics): array
    {
        $this->assetsById = array();
        $this->assetUnavailableReasonsById = array();
        $this->staticHtmlSemanticClassifier = null;
        if ( ! is_array($assets) ) {
            return array();
        }

        $files = array();
        foreach ( $assets as $key => $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }

            $id = (string) ($asset['id'] ?? $key);
            $content = $asset['content'] ?? $asset['data'] ?? null;
            $source = (string) ($asset['url'] ?? $asset['src'] ?? '');
            $mimeType = (string) ($asset['mime_type'] ?? $asset['mimeType'] ?? 'application/octet-stream');
            $decodedAsset = $this->decodeInlineAssetContent($asset, $content, $mimeType);
            $content = $decodedAsset['content'];
            $mimeType = $decodedAsset['mime_type'];

            if ( null === $content && null !== $this->archiveAssetContentResolver ) {
                $hydratedAsset = ($this->archiveAssetContentResolver)($asset);
                if ( is_array($hydratedAsset) ) {
                    $asset = array_merge($asset, $hydratedAsset);
                    $content = $asset['content'] ?? $asset['data'] ?? null;
                    $mimeType = (string) ($asset['mime_type'] ?? $asset['mimeType'] ?? $mimeType);
                    $decodedAsset = $this->decodeInlineAssetContent($asset, $content, $mimeType);
                    $content = $decodedAsset['content'];
                    $mimeType = $decodedAsset['mime_type'];
                }
            }

            if ( null === $content ) {
                if ( preg_match('/^https?:\/\//', $source) ) {
                    $diagnostics[] = array(
                        'severity' => 'warning',
                        'code'     => 'external_asset_omitted',
                        'message'  => 'External asset URL omitted from static output.',
                        'asset_id' => $id,
                    );
                }

                $reason = true === ($asset['content_omitted'] ?? false) ? 'archive_asset_content_omitted' : 'asset_content_unavailable';
                foreach ( $this->assetAliases($asset, $id) as $alias ) {
                    $this->assetUnavailableReasonsById[$alias] = $reason;
                }
                continue;
            }

            $path = 'assets/' . $this->slug((string) ($asset['name'] ?? $id)) . '.' . $this->extensionForMimeType($mimeType);
            $file = array(
                'path'      => $path,
                'role'      => 'asset',
                'mime_type' => $mimeType,
                'content'   => (string) $content,
                'source_id' => $id,
            );

            $files[] = $file;
            foreach ( $this->assetAliases($asset, $id) as $alias ) {
                $this->assetsById[$alias] = $file;
            }
        }

        usort(
            $files,
            static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path'])
        );

        return $files;
    }

    /**
     * @param array<string, mixed> $asset
     * @return array{content: mixed, mime_type: string}
     */
    private function decodeInlineAssetContent(array $asset, mixed $content, string $mimeType): array
    {
        if ( null !== $content ) {
            return array('content' => $content, 'mime_type' => $mimeType);
        }

        foreach ( array('dataUrl', 'dataURL', 'data_url') as $key ) {
            if ( ! isset($asset[$key]) || ! is_scalar($asset[$key]) ) {
                continue;
            }

            $dataUrl = (string) $asset[$key];
            if ( 1 !== preg_match('/^data:([^;,]+)?(;base64)?,(.*)$/s', $dataUrl, $matches) ) {
                continue;
            }

            $data = rawurldecode($matches[3]);
            if ( ';base64' === ($matches[2] ?? '') ) {
                $decoded = base64_decode($data, true);
                if ( false === $decoded ) {
                    continue;
                }
                $data = $decoded;
            }

            $dataUrlMimeType = (string) ($matches[1] ?? '');
            return array(
                'content'   => $data,
                'mime_type' => '' !== $dataUrlMimeType ? $dataUrlMimeType : $mimeType,
            );
        }

        foreach ( array('content_base64', 'contentBase64', 'base64') as $key ) {
            if ( ! isset($asset[$key]) || ! is_scalar($asset[$key]) ) {
                continue;
            }

            $decoded = base64_decode((string) $asset[$key], true);
            if ( false !== $decoded ) {
                return array('content' => $decoded, 'mime_type' => $mimeType);
            }
        }

        return array('content' => null, 'mime_type' => $mimeType);
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    private function assetReport(array $assetFiles): array
    {
        $assets = array();
        foreach ( $assetFiles as $file ) {
            $content = (string) ($file['content'] ?? '');
            $asset = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) $file['path'],
                'mime_type' => (string) $file['mime_type'],
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
            if ( 'image/svg+xml' === ($file['mime_type'] ?? null) && str_starts_with((string) ($file['source_id'] ?? ''), 'generated-vector-') ) {
                $asset += $this->svgAssetMetrics($content);
            }
            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function svgAssetMetrics(string $content): array
    {
        $pathElementCount = preg_match_all('/<path\b[^>]*>/i', $content, $pathMatches);
        $pathDataValues = array();
        foreach ( $pathMatches[0] ?? array() as $pathElement ) {
            if ( preg_match('/\bd\s*=\s*(["\'])(.*?)\1/is', (string) $pathElement, $pathDataMatch) ) {
                $pathDataValues[] = html_entity_decode((string) $pathDataMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pathDataBytes = array_map(static fn (string $pathData): int => strlen($pathData), $pathDataValues);
        $pathDataHashes = array_map(static fn (string $pathData): string => hash('sha256', $pathData), $pathDataValues);
        $uniquePathDataHashes = array_values(array_unique($pathDataHashes));

        return array(
            'gzip_bytes' => function_exists('gzencode') ? strlen((string) gzencode($content, 9)) : null,
            'path_element_count' => false === $pathElementCount ? 0 : $pathElementCount,
            'path_data_count' => count($pathDataValues),
            'path_data_bytes' => array_sum($pathDataBytes),
            'largest_path_data_bytes' => empty($pathDataBytes) ? 0 : max($pathDataBytes),
            'unique_path_data_count' => count($uniquePathDataHashes),
            'duplicate_path_data_count' => max(0, count($pathDataValues) - count($uniquePathDataHashes)),
            'path_data_hashes' => $uniquePathDataHashes,
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeAssetPath(array $node): ?string
    {
        foreach ( $this->nodeAssetReferences($node) as $assetId ) {
            $path = $this->resolveAssetPath($assetId);
            if ( null !== $path ) {
                $this->usedAssetPaths[$path] = true;
                return $path;
            }
        }

        return null;
    }

    /**
     * Return all image-fill asset paths for a node ordered top→bottom (Figma's
     * topmost paint first), matching CSS background-image layer stacking order.
     * Figma stores fills bottom→top in the array, so fills are reversed before
     * resolution. Paints with `visible === false` are skipped. Every resolved
     * path is marked used so its blob is emitted.
     *
     * When a node carries no fill-based image paints the method falls back to
     * the legacy node-level reference (same as {@see nodeAssetPath()}) so that
     * simple `asset_id` nodes continue to work unchanged.
     *
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetPaths(array $node): array
    {
        $layers = $this->nodeImagePaintLayers($node);
        if ( ! empty($layers) ) {
            return array_map(static fn (array $layer): string => (string) $layer['path'], $layers);
        }

        // Fallback: node-level asset reference (e.g. explicit `asset_id` key
        // not expressed as a fill paint).
        $fallbackPath = $this->nodeAssetPath($node);
        return null !== $fallbackPath ? array($fallbackPath) : array();
    }

    /**
     * Return resolved image paint layers ordered top→bottom. Duplicate asset
     * paths are intentionally preserved because Figma can stack the same image
     * with different crops, opacity, or blend modes.
     *
     * @param array<string, mixed> $node
     * @return array<int, array{path: string, paint: array<string, mixed>}>
     */
    private function nodeImagePaintLayers(array $node): array
    {
        return $this->paintStackResolver()->nodeImagePaintLayers($node);
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    private function referencedAssetFiles(array $assetFiles): array
    {
        if ( empty($this->usedAssetPaths) ) {
            return array();
        }

        return array_values(array_filter(
            $assetFiles,
            fn (array $file): bool => isset($this->usedAssetPaths[(string) ($file['path'] ?? '')])
        ));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imageBackgroundStyles(array $node, array $imageLayers): array
    {
        return $this->composedBackgroundLayerStyles($node, array_map(static fn (array $layer): array => array(
            'type'  => 'image',
            'css'   => 'url("' . (string) ($layer['path'] ?? '') . '")',
            'paint' => is_array($layer['paint'] ?? null) ? $layer['paint'] : array(),
        ), $imageLayers));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function imageBackgroundBlendModes(array $imageLayers): array
    {
        return $this->composedBackgroundBlendModes(array_map(static fn (array $layer): array => array(
            'type'  => 'image',
            'css'   => 'url("' . (string) ($layer['path'] ?? '') . '")',
            'paint' => is_array($layer['paint'] ?? null) ? $layer['paint'] : array(),
        ), $imageLayers));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $paint
     * @return array{size: string, repeat: string, position: string}
     */
    private function imagePaintLayerBackgroundStyles(array $node, array $paint, string $scaleMode): array
    {
        $layers = array(array('type' => 'image', 'css' => '', 'paint' => $paint));
        $styles = $this->paintStackResolver()->composedBackgroundLayerStyles($node, $layers);

        return array(
            'size'     => substr($styles[0] ?? 'background-size:cover', strlen('background-size:')),
            'repeat'   => substr($styles[1] ?? 'background-repeat:no-repeat', strlen('background-repeat:')),
            'position' => substr($styles[2] ?? 'background-position:center', strlen('background-position:')),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $paint
     * @return array{size: string, repeat: string, position: string}|array{}
     */
    private function imagePaintTransformStyles(array $node, array $paint): array
    {
        return $this->paintStackResolver()->imagePaintTransformStyles($node, $paint);
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function imagePaintCropRect(array $paint): ?array
    {
        $cropRect = $paint['cropRect'] ?? null;
        if ( ! is_array($cropRect) ) {
            return null;
        }

        $width = $cropRect['width'] ?? $cropRect['w'] ?? null;
        $height = $cropRect['height'] ?? $cropRect['h'] ?? null;
        $x = $cropRect['x'] ?? 0;
        $y = $cropRect['y'] ?? 0;
        if ( ! is_numeric($x) || ! is_numeric($y) || ! is_numeric($width) || ! is_numeric($height) || 0 >= (float) $width || 0 >= (float) $height ) {
            return null;
        }

        return array(
            'x'      => (float) $x,
            'y'      => (float) $y,
            'width'  => (float) $width,
            'height' => (float) $height,
        );
    }

    /**
     * @param array<string, mixed> $paint
     * @return array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float}|null
     */
    private function imagePaintTransformMatrix(array $paint): ?array
    {
        $transform = $paint['transform'] ?? null;
        if ( ! is_array($transform) ) {
            return null;
        }

        if ( isset($transform['m00'], $transform['m01'], $transform['m02'], $transform['m10'], $transform['m11'], $transform['m12']) ) {
            $values = array(
                'm00' => $transform['m00'],
                'm01' => $transform['m01'],
                'm02' => $transform['m02'],
                'm10' => $transform['m10'],
                'm11' => $transform['m11'],
                'm12' => $transform['m12'],
            );
        } elseif ( is_array($transform[0] ?? null) && is_array($transform[1] ?? null) ) {
            $values = array(
                'm00' => $transform[0][0] ?? null,
                'm01' => $transform[0][1] ?? null,
                'm02' => $transform[0][2] ?? null,
                'm10' => $transform[1][0] ?? null,
                'm11' => $transform[1][1] ?? null,
                'm12' => $transform[1][2] ?? null,
            );
        } else {
            return null;
        }

        foreach ( $values as $value ) {
            if ( ! is_numeric($value) ) {
                return null;
            }
        }

        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * @param array{m00: float, m01: float, m02: float, m10: float, m11: float, m12: float} $matrix
     */
    private function isIdentityImageTransform(array $matrix): bool
    {
        return 0.00001 > abs($matrix['m00'] - 1.0)
            && 0.00001 > abs($matrix['m01'])
            && 0.00001 > abs($matrix['m02'])
            && 0.00001 > abs($matrix['m10'])
            && 0.00001 > abs($matrix['m11'] - 1.0)
            && 0.00001 > abs($matrix['m12']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeImageScaleMode(array $node): string
    {
        foreach ( $this->nodeImagePaints($node) as $paint ) {
            return $this->imagePaintScaleMode($paint);
        }

        return 'FILL';
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function imagePaintScaleMode(array $paint): string
    {
        foreach ( array('imageScaleMode', 'scaleMode') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                return strtoupper((string) $paint[$key]);
            }
        }

        return 'FILL';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function nodeImagePaints(array $node): array
    {
        return VisualLayerEvidence::imagePaints($node);
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<int, string>
     */
    private function assetAliases(array $asset, string $id): array
    {
        $aliases = array($id);
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename', 'key', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($asset[$key]) && is_scalar($asset[$key]) ) {
                $aliases[] = (string) $asset[$key];
            }
        }

        foreach ( $aliases as $alias ) {
            $aliases[] = $this->slug($alias);
        }

        if ( isset($asset['path']) && is_scalar($asset['path']) ) {
            $path = (string) $asset['path'];
            $aliases[] = $path;
            $aliases[] = basename($path);
            $aliases[] = pathinfo($path, PATHINFO_FILENAME);
        }

        return array_values(array_unique(array_filter($aliases, static fn (string $alias): bool => '' !== $alias)));
    }

    /**
     * @param array<int, string> $references
     */
    private function assetUnavailableReasonForReferences(array $references): ?string
    {
        foreach ( $references as $reference ) {
            if ( isset($this->assetUnavailableReasonsById[$reference]) ) {
                return $this->assetUnavailableReasonsById[$reference];
            }

            $slugged = $this->slug($reference);
            if ( isset($this->assetUnavailableReasonsById[$slugged]) ) {
                return $this->assetUnavailableReasonsById[$slugged];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function nodeAssetReferences(array $node): array
    {
        $references = array();
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash', 'ref', 'id', 'name') as $key ) {
            if ( isset($node[$key]) && is_scalar($node[$key]) ) {
                $references[] = (string) $node[$key];
            }
        }

        foreach ( array('fills', 'strokes', 'background') as $paintKey ) {
            $paintCollections = array();
            if ( is_array($node[$paintKey] ?? null) ) {
                $paintCollections[] = $node[$paintKey];
            }
            if ( is_array($node['figma_paints'][$paintKey] ?? null) ) {
                $paintCollections[] = $node['figma_paints'][$paintKey];
            }

            foreach ( $paintCollections as $paints ) {
                foreach ( $paints as $paint ) {
                    if ( ! is_array($paint) || 'IMAGE' !== strtoupper((string) ($paint['type'] ?? '')) ) {
                        continue;
                    }

                    $references = array_merge($references, $this->paintAssetReferences($paint));
                }
            }
        }

        foreach ( $references as $reference ) {
            $references[] = $this->slug($reference);
        }

        return array_values(array_unique($references));
    }

    private function resolveAssetPath(string $assetId): ?string
    {
        if ( isset($this->assetsById[$assetId]) ) {
            return (string) $this->assetsById[$assetId]['path'];
        }

        $slugged = $this->slug($assetId);
        return isset($this->assetsById[$slugged]) ? (string) $this->assetsById[$slugged]['path'] : null;
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function resolveAndMarkPaintAssetPath(array $paint): ?string
    {
        foreach ( $this->paintAssetReferences($paint) as $assetId ) {
            $path = $this->resolveAssetPath($assetId);
            if ( null === $path ) {
                continue;
            }

            $this->usedAssetPaths[$path] = true;
            return $path;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $paint
     * @return array<int, string>
     */
    private function paintAssetReferences(array $paint): array
    {
        $references = array();
        foreach ( array('ref', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref') as $key ) {
            if ( isset($paint[$key]) && is_scalar($paint[$key]) && '' !== (string) $paint[$key] ) {
                $references[] = (string) $paint[$key];
            }
        }

        if ( is_array($paint['assetRef'] ?? null) ) {
            $references = array_merge($references, $this->assetRefReferences($paint['assetRef']));
        }

        foreach ( array('image', 'thumbnail', 'imageThumbnail', 'sourceImage') as $imageKey ) {
            if ( is_array($paint[$imageKey] ?? null) ) {
                $references = array_merge($references, $this->imageAssetReferences($paint[$imageKey]));
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $image
     * @return array<int, string>
     */
    private function imageAssetReferences(array $image): array
    {
        $references = array();
        foreach ( array('hash', 'imageRef', 'imageHash', 'asset_id', 'assetId', 'image_ref', 'ref', 'source_id', 'node_id', 'nodeId', 'name', 'fileName', 'filename') as $key ) {
            if ( isset($image[$key]) && is_scalar($image[$key]) && '' !== (string) $image[$key] ) {
                $references[] = (string) $image[$key];
            }
        }

        if ( is_array($image['assetRef'] ?? null) ) {
            $references = array_merge($references, $this->assetRefReferences($image['assetRef']));
        }
        if ( is_array($image['sourceImage'] ?? null) ) {
            $references = array_merge($references, $this->imageAssetReferences($image['sourceImage']));
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $assetRef
     * @return array<int, string>
     */
    private function assetRefReferences(array $assetRef): array
    {
        $references = array();
        foreach ( array('id', 'key', 'nodeID', 'fileKey', 'libraryKey', 'publishID', 'sourceLibraryKey') as $key ) {
            if ( isset($assetRef[$key]) && is_scalar($assetRef[$key]) && '' !== (string) $assetRef[$key] ) {
                $references[] = (string) $assetRef[$key];
            }
        }
        if ( is_array($assetRef['guid'] ?? null) && isset($assetRef['guid']['sessionID'], $assetRef['guid']['localID']) ) {
            $references[] = (string) $assetRef['guid']['sessionID'] . ':' . (string) $assetRef['guid']['localID'];
        } elseif ( isset($assetRef['guid']) && is_scalar($assetRef['guid']) && '' !== (string) $assetRef['guid'] ) {
            $references[] = (string) $assetRef['guid'];
        }

        return array_values(array_unique($references));
    }

    private function isUnsupportedVectorType(string $type): bool
    {
        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function supportedVectorSvg(array $node, string $type, ?array $parentNode = null): ?string
    {
        return $this->vectorSvgRenderer()->supportedVectorSvg($node, $type, $parentNode);
    }

    private function vectorSvgComposesChildren(?string $vectorSvg): bool
    {
        return null !== $vectorSvg && (str_contains($vectorSvg, 'data-figma-vector-composition="group"') || str_contains($vectorSvg, 'data-figma-boolean-operation='));
    }

    /** @param array<string, mixed> $node */
    private function shouldSuppressNonRenderableUnsupportedVectorPlaceholder(array $node, string $type, ?string $vectorSvg, bool $hasVectorAssetFallback, bool $hasRenderableVectorFallback): bool
    {
        if ( ! $this->isUnsupportedVectorType($type) || null !== $vectorSvg || $hasVectorAssetFallback || $hasRenderableVectorFallback ) {
            return false;
        }
        $diagnostic = $this->vectorPlaceholderDiagnostic($node, $type);
        if ( ! empty($diagnostic['source_fields'] ?? array()) ) {
            return false;
        }
        if ( 'missing_dimensions' === ($diagnostic['reason'] ?? null) ) {
            return true;
        }

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        $width = isset($box['width']) && is_numeric($box['width']) ? (float) $box['width'] : 0.0;
        $height = isset($box['height']) && is_numeric($box['height']) ? (float) $box['height'] : 0.0;

        return $width <= 0.0 || $height <= 0.0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function vectorSvgMarkup(string $svg, array $node, string $type, ?array $parentNode = null): string
    {
        $decorative = $this->vectorIsDecorativeForAccessibility($node, $type, $parentNode);
        if ( $decorative ) {
            $svg = $this->decorativeVectorSvgMarkup($svg);
        } else {
            $svg = $this->labeledVectorSvgMarkup($svg, $this->vectorAccessibleLabel($node, $type, $parentNode));
        }

        $hash = hash('sha256', $svg);
        $svgBytes = strlen($svg);
        if (
            $svgBytes <= self::EXTERNAL_VECTOR_SVG_BYTES
            && ! isset($this->generatedVectorSvgPathsByHash[$hash])
            && $this->inlineVectorSvgBytes + $svgBytes <= self::INLINE_VECTOR_SVG_BUDGET_BYTES
        ) {
            $this->inlineVectorSvgBytes += $svgBytes;
            return $svg;
        }

        $path = $this->generatedVectorSvgPathsByHash[$hash] ?? null;
        if ( null === $path ) {
            $path = 'assets/vector-' . substr($hash, 0, 16) . '.svg';
            $this->generatedVectorSvgPathsByHash[$hash] = $path;
            $this->generatedAssetFiles[$path] = array(
                'path'      => $path,
                'role'      => 'asset',
                'mime_type' => 'image/svg+xml',
                'content'   => $svg,
                'source_id' => 'generated-vector-' . substr($hash, 0, 16),
            );
        }

        $label = $decorative ? '' : $this->vectorAccessibleLabel($node, $type, $parentNode);
        $decorativeAttributes = $decorative ? ' aria-hidden="true"' : '';
        $width = $this->nodeDimension($node, 'width');
        $height = $this->nodeDimension($node, 'height');
        $dimensionAttributes = '';
        if ( null !== $width && null !== $height ) {
            $dimensionAttributes = ' width="' . $this->sanitizeAttribute($this->number($width)) . '" height="' . $this->sanitizeAttribute($this->number($height)) . '"';
        }

        return '<img class="figma-vector-asset" src="' . $this->sanitizeAttribute($path) . '" alt="' . $this->sanitizeAttribute($label) . '"' . $dimensionAttributes . ' decoding="async" data-figma-vector="true"' . $decorativeAttributes . '>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function vectorIsDecorativeForAccessibility(array $node, string $type, ?array $parentNode = null): bool
    {
        $name = trim((string) ($node['name'] ?? ''));
        $parentName = null !== $parentNode ? trim((string) ($parentNode['name'] ?? '')) : '';
        if ( $this->isBrandLikeNodeName($name) || $this->isBrandLikeNodeName($parentName) ) {
            return false;
        }

        if ( ! $this->isGenericVectorName($name) ) {
            return false;
        }

        if ( $this->subtreeIsDecorativeSeparator($node) ) {
            return true;
        }

        return in_array($type, array('VECTOR', 'BOOLEAN_OPERATION', 'LINE', 'ELLIPSE', 'RECTANGLE', 'ROUNDED_RECTANGLE', 'STAR', 'POLYGON', 'REGULAR_POLYGON'), true);
    }

    private function decorativeVectorSvgMarkup(string $svg): string
    {
        $svg = preg_replace('/\srole="img"\saria-label="[^"]*"/', ' aria-hidden="true" focusable="false"', $svg, 1);
        return is_string($svg) ? $svg : '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function vectorAccessibleLabel(array $node, string $type, ?array $parentNode = null): string
    {
        $name = trim((string) ($node['name'] ?? ''));
        $parentName = null !== $parentNode ? trim((string) ($parentNode['name'] ?? '')) : '';
        if ( $this->isBrandLikeNodeName($parentName) && $this->isGenericVectorName($name) ) {
            return $parentName;
        }

        return '' !== $name ? $name : $type;
    }

    private function labeledVectorSvgMarkup(string $svg, string $label): string
    {
        $svg = preg_replace('/\saria-label="[^"]*"/', ' aria-label="' . $this->sanitizeAttribute($label) . '"', $svg, 1);
        return is_string($svg) ? $svg : '';
    }

    private function isBrandLikeNodeName(string $name): bool
    {
        $lower = strtolower($name);
        return str_contains($lower, 'logo') || str_contains($lower, 'brand');
    }

    private function isGenericVectorName(string $name): bool
    {
        return '' === $name || 1 === preg_match('/^(vector|union|subtract|intersect|exclude|ellipse|rectangle|line|polygon|star|regular polygon|group|shape|icon)(\s+\d+)?$/i', $name);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function zeroHeightVectorFallbackHeight(array $node, string $type): ?float
    {
        return $this->vectorSvgRenderer()->zeroHeightVectorFallbackHeight($node, $type);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function vectorPlaceholderDiagnostic(array $node, string $type, ?array $parentNode = null): array
    {
        return $this->vectorSvgRenderer()->vectorPlaceholderDiagnostic($node, $type, $parentNode);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function backgroundColor(array $node): ?string
    {
        $paints = is_array($node['figma_paints']['fills'] ?? null) ? $node['figma_paints']['fills'] : array();
        $paint = $this->firstBackgroundPaint($paints);
        if ( null !== $paint ) {
            return $paint;
        }

        $paints = is_array($node['figma_paints']['background'] ?? null) ? $node['figma_paints']['background'] : array();
        $paint = $this->firstBackgroundPaint($paints);
        if ( null !== $paint ) {
            return $paint;
        }

        return $this->color($node['background'] ?? $node['backgroundColor'] ?? $node['fill'] ?? $node['fills'][0]['color'] ?? $node['fillPaints'][0]['color'] ?? $node['paints']['fills'][0]['color'] ?? $node['paints'][0]['color'] ?? $node['paints'][0][0]['color'] ?? null);
    }

    /**
     * @param array<int, mixed> $paints
     */
    private function firstBackgroundPaint(array $paints): ?string
    {
        $paint = $this->firstCssPaint($paints);
        return is_array($paint) ? $paint['css'] : null;
    }

    /**
     * @param array<int, mixed> $paints
     * @return array{css: string, gradient: bool}|null
     */
    private function firstCssPaint(array $paints): ?array
    {
        return $this->paintStackResolver()->firstCssPaint($paints);
    }

    /**
     * @param array<int, mixed> $paints
     */
    private function firstSolidPaint(array $paints): ?string
    {
        foreach ( $paints as $paint ) {
            if ( ! is_array($paint) || 'SOLID' !== ($paint['type'] ?? null) ) {
                continue;
            }

            $color = $this->color($paint['color'] ?? null, $paint['opacity'] ?? null);
            if ( null !== $color ) {
                return $color;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $paint
     */
    private function gradientPaint(array $paint): ?string
    {
        return $this->paintStackResolver()->gradientPaint($paint);
    }

    /**
     * Computes the CSS conic-gradient geometry (start angle + center) for a
     * Figma angular paint from its gradientTransform matrix.
     *
     * Figma evaluates an angular (conic) gradient in the same canonical space
     * the linear/radial paths use: the 2x3 gradientTransform maps the shape's
     * normalized bounding-box space (0..1, y-down) into the gradient's canonical
     * space, and the angular parameter is t = atan2(v - 0.5, u - 0.5) / 2pi
     * around the canonical center (0.5, 0.5). So t = 0 (the gradient's first
     * stop / seam) points along the canonical +u axis -- the very same handle
     * direction the linear path treats as start->end. Mapping +u back through
     * the INVERSE matrix yields (d/det, -c/det), the t=0 radial direction in the
     * shape's own y-down space.
     *
     * CSS conic-gradient `from` angles share the linear-gradient clock: 0deg
     * points up, 90deg right, 180deg down, 270deg left, sweeping clockwise. For
     * a y-down direction (dx, dy) the matching angle is atan2(dx, -dy), so the
     * seam direction reuses the exact linearGradientAngle convention. The center
     * is the canonical point (0.5, 0.5) mapped back through the inverse affine,
     * expressed as percentages of the shape box.
     *
     * Returns `from 0deg at 50% 50%` (seam at top, centered) when no usable
     * transform is present, so geometry-less angular paints stay deterministic.
     *
     * @param array<string, mixed> $paint
     * @return array{from: float, cx: float, cy: float}
     */
    private function angularGradientGeometry(array $paint): array
    {
        $default = array('from' => 0.0, 'cx' => 50.0, 'cy' => 50.0);

        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return $default;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $tx = $this->numericOrNull($matrix[0][2] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        $ty = $this->numericOrNull($matrix[1][2] ?? null);
        if ( null === $a || null === $b || null === $tx || null === $c || null === $d || null === $ty ) {
            return $default;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return $default;
        }

        // Canonical +u axis mapped to the shape's y-down space: the t=0 seam
        // direction. Identical first column of the inverse linear part the
        // linear path uses, so the seam angle matches linearGradientAngle.
        $dx = $d / $det;
        $dy = -$c / $det;
        $from = 0.0;
        if ( abs($dx) >= 1e-9 || abs($dy) >= 1e-9 ) {
            $from = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
            if ( $from < 0.0 ) {
                $from += 360.0;
            }
        }

        // Canonical center (0.5, 0.5) mapped back through the inverse affine
        // gives the conic center in the shape's normalized space.
        $cx = ($d * (0.5 - $tx) - $b * (0.5 - $ty)) / $det;
        $cy = ($a * (0.5 - $ty) - $c * (0.5 - $tx)) / $det;

        return array(
            'from' => $from,
            'cx'   => $cx * 100.0,
            'cy'   => $cy * 100.0,
        );
    }

    /**
     * Computes the CSS linear-gradient angle (degrees) for a Figma linear paint
     * from its gradientTransform matrix.
     *
     * Figma encodes gradient direction with a 2x3 affine matrix that maps the
     * shape's normalized bounding-box space (0..1, y-down) into the gradient's
     * canonical parameter space, where the gradient runs along x from 0 (start)
     * to 1 (end) at any y. To recover the start->end direction in the shape's own
     * space we map the canonical points (0, 0.5) and (1, 0.5) back through the
     * INVERSE matrix. Their difference equals the first column of the inverse
     * linear part, (d/det, -c/det), where the linear part is [[a, b], [c, d]] and
     * det = a*d - b*c.
     *
     * CSS linear-gradient angles run clockwise from "to top": 0deg points up,
     * 90deg right, 180deg down, 270deg left. For a direction vector (dx, dy) in
     * y-down space the matching angle is atan2(dx, -dy), normalized to [0, 360).
     * A left-to-right vector (1, 0) yields 90deg; a top-to-bottom vector (0, 1)
     * yields 180deg.
     *
     * Returns 180.0 (top-to-bottom) when no usable transform is present, so paints
     * without geometry keep the historical default.
     *
     * @param array<string, mixed> $paint
     */
    private function linearGradientAngle(array $paint): float
    {
        $matrix = $paint['gradientTransform'] ?? null;
        if ( ! is_array($matrix) || ! is_array($matrix[0] ?? null) || ! is_array($matrix[1] ?? null) ) {
            return 180.0;
        }

        $a = $this->numericOrNull($matrix[0][0] ?? null);
        $b = $this->numericOrNull($matrix[0][1] ?? null);
        $c = $this->numericOrNull($matrix[1][0] ?? null);
        $d = $this->numericOrNull($matrix[1][1] ?? null);
        if ( null === $a || null === $b || null === $c || null === $d ) {
            return 180.0;
        }

        $det = $a * $d - $b * $c;
        if ( abs($det) < 1e-9 ) {
            return 180.0;
        }

        // First column of the inverse linear part: the start->end direction in
        // the shape's normalized (y-down) coordinate space.
        $dx = $d / $det;
        $dy = -$c / $det;
        if ( abs($dx) < 1e-9 && abs($dy) < 1e-9 ) {
            return 180.0;
        }

        $angle = fmod(rad2deg(atan2($dx, -$dy)), 360.0);
        if ( $angle < 0.0 ) {
            $angle += 360.0;
        }

        return $angle;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Map a Figma node-level blendMode enum to the equivalent CSS
     * `mix-blend-mode` keyword. Returns null for the default compositing
     * modes (NORMAL / PASS_THROUGH) and any unrecognized value so no CSS
     * is emitted in those cases.
     */
    private function blendModeCss(string $blendMode): ?string
    {
        return match ( strtoupper($blendMode) ) {
            'MULTIPLY' => 'multiply',
            'SCREEN' => 'screen',
            'OVERLAY' => 'overlay',
            'DARKEN' => 'darken',
            'LIGHTEN' => 'lighten',
            'COLOR_DODGE' => 'color-dodge',
            'COLOR_BURN' => 'color-burn',
            'HARD_LIGHT' => 'hard-light',
            'SOFT_LIGHT' => 'soft-light',
            'DIFFERENCE' => 'difference',
            'EXCLUSION' => 'exclusion',
            'HUE' => 'hue',
            'SATURATION' => 'saturation',
            'COLOR' => 'color',
            'LUMINOSITY' => 'luminosity',
            default => null,
        };
    }

    private function color(mixed $value, mixed $opacity = null): ?string
    {
        if ( is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ) {
            return strtolower($value);
        }

        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->colorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->colorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->colorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        $alpha = $opacity;
        if ( null === $alpha && isset($value['a']) ) {
            $alpha = $value['a'];
        }

        if ( is_numeric($alpha) && (float) $alpha < 1 ) {
            return sprintf('rgba(%d,%d,%d,%s)', $red, $green, $blue, $this->number(max(0, (float) $alpha)));
        }

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    private function colorChannel(mixed $value): ?int
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel <= 1 ) {
            $channel *= 255;
        }

        return max(0, min(255, (int) round($channel)));
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ( $mimeType ) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }

        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }

    /**
     * @param array<string, mixed> $scenegraph
     * @return array<string, array<string, mixed>>
     */
    private function nodeMap(array $scenegraph): array
    {
        $map = array();
        if ( is_array($scenegraph['node_map'] ?? null) ) {
            foreach ( $scenegraph['node_map'] as $id => $node ) {
                if ( is_array($node) ) {
                    $nodeId = (string) ($node['id'] ?? $id);
                    if ( '' !== $nodeId ) {
                        $map[$nodeId] = $node;
                    }
                }
            }
        }

        foreach ( $this->nodeList($scenegraph) as $node ) {
            if ( is_array($node) ) {
                $this->appendNodeMap($node, $map);
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $map
     */
    private function appendNodeMap(array $node, array &$map): void
    {
        $id = (string) ($node['id'] ?? '');
        if ( '' !== $id ) {
            $map[$id] = $node;
        }

        foreach ( $this->nodeList($node) as $child ) {
            if ( is_array($child) ) {
                $this->appendNodeMap($child, $map);
            }
        }
    }

    /**
     * @param array<string, mixed> $pagePlan
     * @return array<int, mixed>
     */
    private function plannedPages(array $pagePlan): array
    {
        if ( is_array($pagePlan['pages'] ?? null) ) {
            return array_values($pagePlan['pages']);
        }

        return array_values($pagePlan);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function pagePath(array $page, string $name, int $index): string
    {
        if ( isset($page['path']) && is_scalar($page['path']) && '' !== trim((string) $page['path']) ) {
            $path = trim(str_replace('\\', '/', (string) $page['path']));
            $path = ltrim($path, '/');
            $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => '' !== $part && '.' !== $part && '..' !== $part));
            $path = implode('/', $parts);
            if ( '' !== $path && str_ends_with($path, '/') ) {
                $path .= 'index.html';
            }
            if ( '' !== $path ) {
                return str_contains(basename($path), '.') ? $path : rtrim($path, '/') . '/index.html';
            }
        }

        if ( true === ($page['entrypoint'] ?? false) || 0 === $index ) {
            return 'index.html';
        }

        return $this->slug($name) . '.html';
    }

    private function stylesheetHref(string $pagePath): string
    {
        $directory = trim(dirname($pagePath), '.');
        if ( '' === $directory || '/' === $directory ) {
            return 'style.css';
        }

        $depth = count(array_filter(explode('/', trim($directory, '/')), static fn (string $part): bool => '' !== $part));
        return str_repeat('../', $depth) . 'style.css';
    }

    private function templateSlugFromPath(string $pagePath): string
    {
        $base = basename(str_replace('\\', '/', $pagePath));
        $slug = preg_replace('/\.html?$/i', '', $base) ?? $base;
        $slug = trim($slug);

        return '' === $slug ? 'index' : $slug;
    }

    private function canonicalTemplatePath(string $templateType): string
    {
        return match ( $templateType ) {
            'single' => 'single.html',
            'archive' => 'archive.html',
            '404' => '404.html',
            default => '',
        };
    }

    /**
     * @param array<string, mixed>      $node
     * @param array<string, mixed>|null $parentNode
     */
    private function semanticArea(string $tag, array $node, ?array $parentNode): string
    {
        if ( in_array($tag, array('header', 'footer', 'nav', 'article', 'form'), true) ) {
            return 'nav' === $tag ? 'navigation' : $tag;
        }

        $name = strtolower((string) ($node['name'] ?? ''));
        $isStructuralContainer = ! in_array($tag, array('p', 'span', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'input', 'textarea', 'button'), true);
        if ( $isStructuralContainer ) {
            if ( str_contains($name, 'header') || str_contains($name, 'site head') ) {
                return 'header';
            }
            if ( str_contains($name, 'footer') || str_contains($name, 'site foot') ) {
                return 'footer';
            }
            if ( str_contains($name, 'navigation') || str_contains($name, 'nav menu') || str_contains($name, 'main nav') ) {
                return 'navigation';
            }
        }
        if ( str_contains($name, 'entry content') || str_contains($name, 'post content') || str_contains($name, 'page content') || str_contains($name, 'content area') ) {
            return 'content';
        }

        if ( null !== $parentNode ) {
            $parentName = strtolower((string) ($parentNode['name'] ?? ''));
            if ( str_contains($parentName, 'comments') || str_contains($name, 'comment') ) {
                return 'comments';
            }
        }

        return '';
    }

    /**
     * Build deterministic production head metadata from explicit transform inputs.
     * No descriptions or social text are inferred from visual copy.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function headMetadata(array $options, string $pagePath, string $title, string $templateType = '', string $templateSlug = ''): array
    {
        $global = is_array($options['site_metadata'] ?? null) ? $options['site_metadata'] : array();
        $pages = is_array($options['page_metadata'] ?? null) ? $options['page_metadata'] : array();
        $page = is_array($pages[$pagePath] ?? null) ? $pages[$pagePath] : array();
        $metadata = array_merge($global, $page);

        $canonicalUrl = $this->metadataString($metadata, 'canonical_url');
        if ( null === $canonicalUrl ) {
            $siteUrl = $this->metadataString($options, 'site_url');
            if ( null !== $siteUrl ) {
                $canonicalUrl = $this->canonicalUrlForPage($siteUrl, $pagePath);
            }
        }

        $description = $this->metadataString($metadata, 'description');
        $ogTitle = $this->metadataString($metadata, 'og_title');
        $twitterTitle = $this->metadataString($metadata, 'twitter_title');
        $ogDescription = $this->metadataString($metadata, 'og_description');
        $twitterDescription = $this->metadataString($metadata, 'twitter_description');

        return array_filter(array(
            'page_path' => $pagePath,
            'template_type' => $templateType,
            'template_slug' => $templateSlug,
            'description' => $description,
            'canonical_url' => $canonicalUrl,
            'favicon_href' => $this->metadataString($metadata, 'favicon_href'),
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $this->metadataString($metadata, 'og_image'),
            'twitter_card' => $this->metadataString($metadata, 'twitter_card'),
            'twitter_title' => $twitterTitle,
            'twitter_description' => $twitterDescription,
            'twitter_image' => $this->metadataString($metadata, 'twitter_image'),
        ), static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    private function canonicalUrlForPage(string $siteUrl, string $pagePath): string
    {
        $base = rtrim($siteUrl, '/');
        $path = trim(str_replace('\\', '/', $pagePath), '/');
        if ( '' === $path || 'index.html' === $path ) {
            return $base . '/';
        }

        return $base . '/' . $path;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function metadataString(array $values, string $key): ?string
    {
        if ( ! isset($values[$key]) || ! is_scalar($values[$key]) ) {
            return null;
        }

        $value = trim((string) $values[$key]);
        return '' === $value ? null : $value;
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            ++$count;
            $count += $this->countNodes($this->nodeList($node));
        }

        return $count;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function negativeAutoLayoutSpacingRules(string $className, array $node): array
    {
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'flex' !== ($layout['display'] ?? null) || ! $this->isFiniteNumeric($layout['item_spacing'] ?? null) || (float) $layout['item_spacing'] >= 0.0 ) {
            return array();
        }

        $property = 'column' === ($layout['flex_direction'] ?? null) ? 'margin-top' : 'margin-left';
        return array('.' . $className . '>*+*{' . $property . ':' . $this->number((float) $layout['item_spacing']) . 'px}');
    }

    /**
     * @return array<int, array{token: string, declaration: string}>
     */
    private function invalidCssNumericTokenDiagnostics(string $css): array
    {
        $diagnostics = array();
        $css = $this->cssWithoutQuotedStringsAndUrls($css);
        if ( preg_match_all('/(?<declaration>[\w-]+:[^;{}]*(?<token>NaN|Infinity|INF)[^;{}]*)/i', $css, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $diagnostics[] = array(
                    'token' => trim((string) $match['token']),
                    'declaration' => trim((string) $match['declaration']),
                );
            }
        }
        if ( preg_match_all('/(?<declaration>gap:\s*-[0-9.]+px(?:\s+-?[0-9.]+px)?)/i', $css, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $diagnostics[] = array(
                    'token' => 'gap:-',
                    'declaration' => trim((string) $match['declaration']),
                );
            }
        }

        return $diagnostics;
    }

    private function cssWithoutQuotedStringsAndUrls(string $css): string
    {
        $css = preg_replace('/url\((?:[^()"\']+|"(?:\\.|[^"])*"|\'(?:\\.|[^\'])*\')*\)/i', 'url()', $css);
        $css = preg_replace('/"(?:\\.|[^"])*"|\'(?:\\.|[^\'])*\'/s', '""', (string) $css);

        return (string) $css;
    }

    private function isFiniteNumeric(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function number(float $value): string
    {
        if ( ! is_finite($value) ) {
            return '0';
        }

        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeDimension(array $node, string $key): ?float
    {
        if ( isset($node[$key]) && is_numeric($node[$key]) && (float) $node[$key] > 0 ) {
            return (float) $node[$key];
        }

        if ( is_array($node['absoluteRenderBounds'] ?? null) && isset($node['absoluteRenderBounds'][$key]) && is_numeric($node['absoluteRenderBounds'][$key]) && (float) $node['absoluteRenderBounds'][$key] > 0 ) {
            return (float) $node['absoluteRenderBounds'][$key];
        }

        return null;
    }

    private function cssString(string $value): string
    {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
    }

    private function sanitizeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeAttribute(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
