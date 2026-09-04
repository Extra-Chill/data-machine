<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Scenegraph;

/**
 * Builds deterministic page extraction plans from decoded Figma scenegraphs.
 */
final class ScenegraphPagePlanner
{
    /**
     * Frame-candidate ceiling for planning-time responsive detection.
     *
     * Responsive grouping is a frame-level question: it only needs the small
     * set of page-candidate FRAMEs the planner already holds in memory (name,
     * dimensions, device hint, ancestor ids) — NOT an index of every
     * descendant node. Detection therefore runs over that frame set without
     * rebuilding a second whole-scenegraph {@see ScenegraphIndex}, so TOTAL
     * node count no longer drives detection memory and grouping stays ON for
     * large designs (e.g. the 293MB "WP.Cloud 2.0" .fig) instead of degrading
     * to one-page-per-frame. The only remaining bound guards the genuinely
     * pathological case of an absurd number of frame candidates, where the
     * O(frames^2) sibling scan would dominate; above it the planner emits a
     * {@see ScenegraphPagePlanner::plan()} `responsive_detection_bounded`
     * diagnostic and degrades to one-page-per-frame. Overridable via the
     * `responsive_detection_frame_limit` plan option.
     */
    private const RESPONSIVE_DETECTION_FRAME_LIMIT = 5000;

    /**
     * Minimum absolute width delta (px) for a sibling-group to count as a real
     * responsive breakpoint spread rather than same-width duplicate drafts.
     */
    private const RESPONSIVE_WIDTH_MATERIAL_PX = 200.0;

    /**
     * Minimum relative width spread (max-min / max) for a responsive group when
     * device hints alone do not distinguish the members.
     */
    private const RESPONSIVE_WIDTH_MATERIAL_RATIO = 0.2;

    /**
     * Page-candidacy dimension band. A real page artboard is a tall, scrolling
     * frame whose width sits in the realistic device range (small mobile through
     * extra-wide desktop). Frames outside this band are top-level on the canvas but are
     * not pages: layout-grid guides / chips (narrower than {@see PAGE_MIN_WIDTH_PX}),
     * oversized presentation / overview / style-guide boards (wider than
     * {@see PAGE_MAX_WIDTH_PX}), and decorative dividers / cover thumbnails /
     * device mockups (shorter than {@see PAGE_MIN_HEIGHT_PX}). Explicit
     * `frame_ids` bypass this band so any requested frame stays selectable.
     */
    private const PAGE_MIN_WIDTH_PX  = 320.0;
    private const PAGE_MAX_WIDTH_PX  = 2560.0;
    private const PAGE_MIN_HEIGHT_PX = 700.0;

    public function __construct(
        private readonly ScenegraphIndex $index = new ScenegraphIndex(),
        private readonly ScenegraphFrameInspector $frameInspector = new ScenegraphFrameInspector(),
        private readonly ScenegraphFrameClassifier $frameClassifier = new ScenegraphFrameClassifier()
    ) {
    }

    /**
     * Build deterministic page plans, collapsing responsive sibling-groups.
     *
     * When {@see ScenegraphFrameInspector} reports that several FRAME nodes
     * represent the same page at different widths (a responsive sibling-group),
     * those frames collapse into a SINGLE page plan that carries an ordered list
     * of breakpoint variants instead of emitting one page per frame. Frames with
     * no detected siblings still produce one single-variant page each, so the
     * top-level page fields stay backward compatible. If the inspector cannot
     * determine a group, the planner falls back to one-page-per-frame.
     *
     * Each entry in the returned `pages` list has this shape (the contract the
     * downstream `@media`-aware emitter consumes):
     *
     *     array(
     *         // Primary (desktop/page-depth) variant drives these page-level fields.
     *         'frame_id'              => string,   // primary variant frame id
     *         'name'                  => string,
     *         'slug'                  => string,   // deduped, derived from primary
     *         'path'                  => string,   // index.html for the entrypoint
     *         'entrypoint'            => bool,
     *         // Frame role classification (#247). Only ROLE_PAGE frames are
     *         // selected as pages; design_system frames are excluded upstream.
     *         'role'                  => string,   // ScenegraphFrameClassifier::ROLE_PAGE
     *         'page_type'             => string,   // front_page|single|archive|page|unknown
     *                                              //   (maps to the WP template hierarchy:
     *                                              //    front_page→front-page.php,
     *                                              //    single→single.php,
     *                                              //    archive→archive.php/index.php,
     *                                              //    page→page.php, unknown→fallback)
     *         'classification_signals' => array<int, string>, // why this page_type was chosen
     *         'figma_page_id'         => string|null,
     *         'figma_page_name'       => string|null,
     *         'section_id'            => string|null,
     *         'section_name'          => string|null,
     *         'width'                 => float|null, // primary variant width
     *         'height'                => float|null, // primary variant height
     *         'node_count'            => int,
     *         'text_count'            => int,
     *         'asset_reference_count' => int,
     *         // Responsive grouping contract.
     *         'responsive'            => bool, // true when more than one variant
     *         'breakpoint_count'      => int,  // count($variants)
     *         'variants'              => array<int, array{
     *             frame_id: string,
     *             name: string,
     *             slug: string,           // identity for the variant frame
     *             responsive_identity: string, // qualifier-stripped page identity
     *             sibling_group_key: string|null, // detection scope + identity evidence
     *             device_hint: string,    // desktop|tablet|mobile|unknown
     *             viewport_width: float|null,
     *             viewport_height: float|null,
     *             primary: bool,          // true for the selected desktop/page-depth variant
     *             order: int,             // 0-based, primary first
     *         }>,
     *         'diagnostics'           => array<int, array<string, mixed>>,
     *     )
     *
     * Variants are ordered primary-first (desktop/page-depth, then breakpoint width), so
     * `variants[0]` is always the primary that drives the page slug/identity.
     *
     * @param array<string, mixed> $source Decoded Figma scenegraph source array.
     * @param array<string, mixed> $options Page planning options.
     * @return array<string, mixed>
     */
    public function plan(array $source, array $options = array()): array
    {
        $index = $this->index->build($source);
        $nodes = is_array($index['nodes'] ?? null) ? $index['nodes'] : array();
        $childrenIndex = is_array($index['children_index'] ?? null) ? $index['children_index'] : array();
        $parentIndex = is_array($index['parent_index'] ?? null) ? $index['parent_index'] : array();
        $diagnostics = is_array($index['diagnostics'] ?? null) ? $index['diagnostics'] : array();
        $statsMemo = array();
        $explicitFrameIds = $this->explicitFrameIds($options);
        $includeAllPages = true === ($options['include_all_pages'] ?? false)
            || true === ($options['multi_page'] ?? false)
            || ! empty($options['frame_ids']);
        $maxPages = isset($options['max_pages']) && is_numeric($options['max_pages']) ? max(1, (int) $options['max_pages']) : null;
        $entryFrameId = isset($options['entry_frame_id']) && is_scalar($options['entry_frame_id']) ? (string) $options['entry_frame_id'] : null;
        $slugMap = is_array($options['frame_slug_map'] ?? null) ? $options['frame_slug_map'] : array();
        $candidates = array();

        // Page candidates are TOP-LEVEL frames: FRAME nodes that sit directly on
        // a CANVAS (or the document root), with only transparent SECTION grouping
        // allowed in between. The deeply nested frames (annotation cards like
        // "Buttons"/"Legend", layout-grid guides, component internals) are NOT
        // pages and must not drown the real pages or misdirect dev-status
        // selection. Restricting candidacy to the top level collapses the
        // candidate set from "every FRAME at any depth" to the handful of real
        // page frames. Explicit `frame_ids` bypass this scoping (built on demand
        // in the selection branch below) so a requested frame at any depth stays
        // selectable.
        foreach ( $nodes as $id => $node ) {
            if ( ! is_string($id) || ! is_array($node) || 'FRAME' !== strtoupper((string) ($node['type'] ?? '')) ) {
                continue;
            }
            if ( ! $this->isTopLevelFrame($id, $nodes, $parentIndex) ) {
                continue;
            }

            $candidates[$id] = $this->buildCandidate($id, $node, $nodes, $childrenIndex, $parentIndex, $statsMemo);
        }

        // Frame role classification (#247): decide WHAT each top-level frame IS
        // before any pixels are emitted. Design-system / style-guide frames are
        // excluded from page selection; real pages carry a WP-template-aligned
        // page_type. Classification runs over the frame-level signals already in
        // memory, so it adds no extra scenegraph traversal.
        $classifications = array();
        foreach ( $candidates as $candidateId => $candidate ) {
            $classifications[(string) $candidateId] = $this->classifyCandidate(
                (string) $candidateId,
                $candidate,
                $nodes,
                $childrenIndex,
                $parentIndex
            );
        }
        $designSystemIds = array();
        foreach ( $classifications as $candidateId => $classification ) {
            if ( ScenegraphFrameClassifier::ROLE_DESIGN_SYSTEM === ($classification['role'] ?? '') ) {
                $designSystemIds[(string) $candidateId] = true;
            }
        }

        // The SELECTABLE page pool: top-level candidates that are neither
        // design-system frames nor off-page-sized noise (grid guides, decorative
        // dividers, cover/device boards, oversized overview artboards). Selection,
        // dev-status ranking, responsive detection and grouping all operate over
        // THIS pool so noise frames never become pages and never get pulled into a
        // real page's responsive group. The full `$candidates` set is retained for
        // candidate_count and classification coverage. If dimension filtering
        // empties the pool (unusual designs), fall back to all non-design-system,
        // non-internal candidates so ordinary single-page transforms still produce
        // output without reviving hidden/internal scaffolds.
        $pageCandidates = array();
        $filteredCandidateEvidence = array();
        $internalOnlyIds = array();
        foreach ( $candidates as $candidateId => $candidate ) {
            $internalScope = $this->internalOnlyCandidateScope((string) $candidateId, $nodes, $parentIndex);
            if ( null !== $internalScope ) {
                $internalOnlyIds[(string) $candidateId] = true;
                $filteredCandidateEvidence[] = $this->candidateEvidenceRecord((string) $candidateId, $candidate, 'internal_only_scope', $classifications[$candidateId] ?? null, $internalScope);
                continue;
            }
            if ( isset($designSystemIds[$candidateId]) ) {
                $filteredCandidateEvidence[] = $this->candidateEvidenceRecord((string) $candidateId, $candidate, 'design_system_role', $classifications[$candidateId] ?? null);
                continue;
            }
            if ( ! $this->isPageSizedCandidate(
                (float) ($candidate['dimensions']['width'] ?? 0),
                (float) ($candidate['dimensions']['height'] ?? 0)
            ) ) {
                $filteredCandidateEvidence[] = $this->candidateEvidenceRecord((string) $candidateId, $candidate, 'page_size_gate', $classifications[$candidateId] ?? null);
                continue;
            }
            $pageCandidates[$candidateId] = $candidate;
        }
        if ( array() === $pageCandidates ) {
            $pageCandidates = array_diff_key($candidates, $designSystemIds, $internalOnlyIds);
            $filteredCandidateEvidence = array_values(array_filter(
                $filteredCandidateEvidence,
                static fn (array $evidence): bool => in_array(($evidence['reason'] ?? null), array('design_system_role', 'internal_only_scope'), true)
            ));
        }

        // Figma Dev Mode status (#280): when ANY node in the file carries a
        // normalized dev status, it becomes the PRIMARY frame-selection signal.
        $nodeDevStatus = $this->resolveNodeDevStatus($nodes);
        $frameDevStatus = $this->resolveFrameDevStatus($candidates, $nodeDevStatus, $parentIndex);
        $fileHasDevStatus = array() !== $nodeDevStatus;

        $selectedIds = array();
        $explicitSelected = false;
        $selectionSource = 'heuristic';
        // Dev-status is the PRIMARY selector ONLY when a real top-level page
        // candidate carries a mark. Because page candidacy is top-level scoped,
        // dev marks that live solely on nested/annotation frames never enter
        // this set, so they can no longer suppress unmarked real pages —
        // selection then falls back to the heuristic ranking over top-level
        // page candidates. Design-system frames never become pages even when
        // dev-marked.
        $markedPageCandidates = array_intersect_key($pageCandidates, $frameDevStatus);
        if ( ! empty($explicitFrameIds) ) {
            $explicitSelected = true;
            foreach ( $explicitFrameIds as $id ) {
                if ( ! isset($candidates[$id]) ) {
                    $node = is_array($nodes[$id] ?? null) ? $nodes[$id] : null;
                    if ( null === $node || 'FRAME' !== strtoupper((string) ($node['type'] ?? '')) ) {
                        $diagnostics[] = array(
                            'severity' => 'warning',
                            'code'     => 'figma_page_plan_frame_missing',
                            'message'  => 'Skipped a requested frame because it was not found as a FRAME node.',
                            'frame_id' => $id,
                        );
                        continue;
                    }

                    // Explicit selection bypasses top-level + page-size scoping:
                    // build the requested frame as a candidate on demand so a
                    // frame at any depth stays selectable and emits a page, and
                    // add it to the page pool so it still participates in
                    // responsive grouping with its explicitly-selected siblings.
                    $candidates[$id] = $this->buildCandidate($id, $node, $nodes, $childrenIndex, $parentIndex, $statsMemo);
                    $classifications[$id] = $this->classifyCandidate($id, $candidates[$id], $nodes, $childrenIndex, $parentIndex);
                }
                $pageCandidates[$id] = $candidates[$id];

                $selectedIds[] = $id;
            }
        } elseif ( $fileHasDevStatus && array() !== $markedPageCandidates ) {
            // Prefer ready_for_dev/completed frames (and frames under a marked
            // section); skip WIP/unmarked frames. Heuristics stay as the order
            // within the marked set and as the fallback when no frame qualifies.
            $selectionSource = 'dev_status';
            $selectedIds = $this->rankedCandidateIdsByDevStatus($markedPageCandidates, $frameDevStatus);
            if ( ! $includeAllPages ) {
                $selectedIds = array_slice($selectedIds, 0, 1);
            }
        } else {
            $selectedIds = $this->rankedCandidateIds($pageCandidates);
            if ( ! $includeAllPages ) {
                $selectedIds = array_slice($selectedIds, 0, 1);
            }
        }

        if ( null !== $maxPages ) {
            $selectedIds = array_slice($selectedIds, 0, $maxPages);
        }

        if ( null !== $entryFrameId && ! in_array($entryFrameId, $selectedIds, true) && isset($candidates[$entryFrameId]) ) {
            array_unshift($selectedIds, $entryFrameId);
            $selectedIds = array_values(array_unique($selectedIds));
            if ( null !== $maxPages ) {
                $selectedIds = array_slice($selectedIds, 0, $maxPages);
            }
        }

        $detectionResult = $this->detectResponsive($pageCandidates, $nodes, $parentIndex, $options);
        $detectionById = $detectionResult['detection'];
        if ( $detectionResult['bounded'] ) {
            $diagnostics[] = array(
                'severity'              => 'info',
                'code'                  => 'responsive_detection_bounded',
                'message'               => 'Responsive sibling detection was skipped because the design has an unusually large number of frame candidates; emitting one page per frame.',
                'frame_candidate_count' => $detectionResult['frame_candidate_count'],
                'frame_candidate_limit' => $detectionResult['frame_candidate_limit'],
            );
        }

        $grouping = $this->responsiveGroups($pageCandidates, $detectionById);
        $responsiveGroups = $grouping['groups'];
        foreach ( $grouping['diagnostics'] as $groupDiagnostic ) {
            $diagnostics[] = $groupDiagnostic;
        }

        if ( ! $explicitSelected ) {
            $duplicateDraftRejectIds = $this->duplicateDraftRejectIds($grouping['diagnostics']);
            if ( array() !== $duplicateDraftRejectIds ) {
                foreach ( array_keys($duplicateDraftRejectIds) as $rejectId ) {
                    if ( isset($pageCandidates[$rejectId]) ) {
                        $filteredCandidateEvidence[] = $this->candidateEvidenceRecord((string) $rejectId, $pageCandidates[$rejectId], 'duplicate_draft_frame', $classifications[$rejectId] ?? null, array(
                            'source' => 'responsive_grouping',
                        ));
                    }
                }
                $selectedIds = array_values(array_filter(
                    $selectedIds,
                    static fn (string $id): bool => ! isset($duplicateDraftRejectIds[$id])
                ));
            }

            $duplicateRouteResult = $this->filterDuplicateRouteDraftFrames($selectedIds, $pageCandidates);
            $selectedIds = $duplicateRouteResult['ids'];
            foreach ( $duplicateRouteResult['diagnostics'] as $diagnostic ) {
                $diagnostics[] = $diagnostic;
                foreach ( is_array($diagnostic['draft_frame_ids'] ?? null) ? $diagnostic['draft_frame_ids'] : array() as $draftId ) {
                    if ( is_string($draftId) && isset($pageCandidates[$draftId]) ) {
                        $filteredCandidateEvidence[] = $this->candidateEvidenceRecord($draftId, $pageCandidates[$draftId], 'duplicate_route_draft_frame', $classifications[$draftId] ?? null, array(
                            'canonical_frame_id' => $diagnostic['canonical_frame_id'] ?? null,
                            'route_identity'     => $diagnostic['route_identity'] ?? null,
                        ));
                    }
                }
            }

            $explorationResult = $this->filterCrossCanvasExplorationFrames($selectedIds, $pageCandidates, $nodes, $parentIndex, $detectionById, $responsiveGroups);
            $selectedIds = $explorationResult['ids'];
            foreach ( $explorationResult['diagnostics'] as $diagnostic ) {
                $diagnostics[] = $diagnostic;
                foreach ( is_array($diagnostic['draft_frame_ids'] ?? null) ? $diagnostic['draft_frame_ids'] : array() as $draftId ) {
                    if ( is_string($draftId) && isset($pageCandidates[$draftId]) ) {
                        $filteredCandidateEvidence[] = $this->candidateEvidenceRecord($draftId, $pageCandidates[$draftId], 'cross_canvas_exploration_frame', $classifications[$draftId] ?? null, array(
                            'canonical_frame_id'      => $diagnostic['canonical_frame_id'] ?? null,
                            'normalized_page_identity' => $diagnostic['normalized_page_identity'] ?? null,
                            'device_hint'             => $diagnostic['device_hint'] ?? null,
                            'canvas_name'             => $diagnostic['canvas_names'][$draftId] ?? null,
                            'canonical_canvas_name'   => is_string($diagnostic['canonical_frame_id'] ?? null) ? ($diagnostic['canvas_names'][(string) $diagnostic['canonical_frame_id']] ?? null) : null,
                        ));
                    }
                }
            }

            if ( ! empty($responsiveGroups) ) {
                $utilityResult = $this->filterLowConfidenceUtilityRouteFrames($selectedIds, $pageCandidates, $classifications);
                $selectedIds = $utilityResult['ids'];
                foreach ( $utilityResult['diagnostics'] as $diagnostic ) {
                    $diagnostics[] = $diagnostic;
                    $filteredId = isset($diagnostic['frame_id']) && is_scalar($diagnostic['frame_id']) ? (string) $diagnostic['frame_id'] : '';
                    if ( '' !== $filteredId && isset($pageCandidates[$filteredId]) ) {
                        $filteredCandidateEvidence[] = $this->candidateEvidenceRecord($filteredId, $pageCandidates[$filteredId], 'low_confidence_route_frame', $classifications[$filteredId] ?? null, array(
                            'route_identity' => $diagnostic['route_identity'] ?? null,
                        ));
                    }
                }
            }
        }

        // Orphan mobile menu/component-demo exclusion: when the file has at
        // least one real responsive desktop+mobile pair, lone mobile-width
        // frames that never grouped with a desktop sibling AND carry a
        // menu/nav/component-demo name AND have no recognizable page-type
        // signal are component demos of the open-menu state — NOT pages.
        // Explicit frame selection bypasses this filter so a requested frame
        // is always emitted. The filter never empties the selected set (safety
        // guard for pathological inputs where every candidate is an orphan).
        if ( ! $explicitSelected && ! empty($responsiveGroups) ) {
            $beforeOrphanFilterIds = $selectedIds;
            $filtered = $this->filterOrphanMenuDemoFrames(
                $selectedIds,
                $pageCandidates,
                $responsiveGroups,
                $classifications,
                $detectionById
            );
            if ( ! empty($filtered) ) {
                $selectedIds = $filtered;
                $selectedAfterOrphan = array_fill_keys($selectedIds, true);
                foreach ( $beforeOrphanFilterIds as $beforeId ) {
                    if ( ! isset($selectedAfterOrphan[$beforeId]) && isset($pageCandidates[$beforeId]) ) {
                        $filteredCandidateEvidence[] = $this->candidateEvidenceRecord((string) $beforeId, $pageCandidates[$beforeId], 'orphan_menu_demo_frame', $classifications[$beforeId] ?? null);
                    }
                }
            }
        }

        // Entrypoint (index.html) assignment. The downstream php-transformer and
        // WordPress treat index.html as the FRONT PAGE, so the page classified
        // `front_page` must own it rather than whichever page merely ranked first.
        // An explicit `entry_frame_id` always wins; otherwise the highest-ranked
        // `front_page` page is the entrypoint, falling back to rank order when no
        // page classifies as a front page.
        $entrypointPrimaryId = null;
        if ( null === $entryFrameId ) {
            $seenPrimary = array();
            $rankFirstPrimaryId = null;
            foreach ( $selectedIds as $id ) {
                $members = $responsiveGroups[$id] ?? array($id);
                $primaryId = $members[0];
                if ( isset($seenPrimary[$primaryId]) ) {
                    continue;
                }
                $seenPrimary[$primaryId] = true;
                if ( null === $rankFirstPrimaryId ) {
                    $rankFirstPrimaryId = $primaryId;
                }
                $primaryClassification = $classifications[$primaryId]
                    ?? $this->classifyCandidate($primaryId, $candidates[$primaryId], $nodes, $childrenIndex, $parentIndex);
                if ( ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE === ($primaryClassification['page_type'] ?? '') ) {
                    $entrypointPrimaryId = $primaryId;
                    break;
                }
            }
            if ( null === $entrypointPrimaryId ) {
                $entrypointPrimaryId = $rankFirstPrimaryId;
            }
        }

        $slugs = array();
        // Template pages emit these aliases after planning, so ordinary routes
        // must not claim their final artifact paths.
        $paths = array_fill_keys(array('index.html', 'single.html', 'archive.html', '404.html'), true);
        $pages = array();
        $consumed = array();
        $pageIdentityEvidence = array();
        foreach ( $selectedIds as $id ) {
            if ( isset($consumed[$id]) ) {
                continue;
            }

            $members = $responsiveGroups[$id] ?? array($id);
            foreach ( $members as $memberId ) {
                $consumed[$memberId] = true;
            }

            $primaryId = $members[0];
            $candidate = $candidates[$primaryId];
            $node = $candidate['node'];
            $page = $this->nearestAncestor($primaryId, array('CANVAS'), $nodes, $parentIndex);
            $section = $this->nearestAncestor($primaryId, array('SECTION'), $nodes, $parentIndex);
            $name = (string) ($node['name'] ?? $primaryId);
            $slugEvidence = $this->dedupeSlugEvidence($this->pageSlug($primaryId, $name, $members, $slugMap), $slugs);
            $slug = $slugEvidence['slug'];
            $entrypoint = null !== $entryFrameId ? in_array($entryFrameId, $members, true) : $primaryId === $entrypointPrimaryId;
            $path = $entrypoint ? 'index.html' : $this->dedupeOutputPath($slug . '.html', $paths);
            $variants = $this->breakpointVariants($members, $primaryId, $candidates, $detectionById);
            $classification = $classifications[$primaryId] ?? $this->classifyCandidate($primaryId, $candidate, $nodes, $childrenIndex, $parentIndex);
            $identity = $this->sourceFrameIdentityEvidence(
                $id,
                $primaryId,
                $members,
                $candidate,
                $classification,
                $detectionById,
                $slug,
                $entrypoint
            );
            $identity['path'] = $path;
            $identity['score'] = (int) ($candidate['score'] ?? 0);
            $identity['base_slug'] = $slugEvidence['base_slug'];
            $identity['slug_collision_index'] = $slugEvidence['collision_index'];
            $pageIdentityEvidence[] = $identity;

            $pages[] = array(
                'frame_id'              => $primaryId,
                'source_frame_identity' => $identity,
                'name'                  => $name,
                'slug'                  => $slug,
                'base_slug'             => $slugEvidence['base_slug'],
                'slug_collision_index'  => $slugEvidence['collision_index'],
                'path'                  => $path,
                'entrypoint'            => $entrypoint,
                'role'                  => $classification['role'],
                'page_type'             => $classification['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN,
                'classification_signals' => $classification['signals'],
                'score'                 => (int) ($candidate['score'] ?? 0),
                'figma_page_id'         => $page['id'] ?? null,
                'figma_page_name'       => $page['name'] ?? null,
                'section_id'            => $section['id'] ?? null,
                'section_name'          => $section['name'] ?? null,
                'width'                 => $candidate['dimensions']['width'],
                'height'                => $candidate['dimensions']['height'],
                'node_count'            => $candidate['stats']['nodes'],
                'text_count'            => $candidate['stats']['texts'],
                'asset_reference_count' => $candidate['stats']['assets'],
                'responsive'            => count($members) > 1,
                'breakpoint_count'      => count($members),
                'variants'              => $variants,
                'diagnostics'           => $this->pageDiagnostics($primaryId, $node, $candidate['dimensions'], $explicitSelected),
            );
        }

        $classificationCoverage = $this->classificationCoverage($candidates, $classifications, $pages);
        // The full coverage report is always available via the
        // `classification_coverage` plan key. Emit it as a diagnostic only when
        // it carries actionable signal — a design-system frame was excluded from
        // pages, or a selected page fell to an `unknown` page type — so files
        // whose every frame classifies cleanly keep an empty diagnostics list
        // (and a clean `success` transform status).
        $excludedDesignSystem = (int) ($classificationCoverage['excluded_design_system_count'] ?? 0);
        $unknownPageTypes = (int) ($classificationCoverage['page_types'][ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN] ?? 0);
        if ( $excludedDesignSystem > 0 || $unknownPageTypes > 0 ) {
            $diagnostics[] = array(
                'severity' => $unknownPageTypes > 0 ? 'warning' : 'info',
                'code'     => 'figma_frame_classification_coverage',
                'message'  => $excludedDesignSystem > 0
                    ? 'Excluded design-system frames from page selection; see coverage for role/page-type breakdown.'
                    : 'Some selected pages fell to an unknown page type; see coverage for the breakdown.',
                'coverage' => $classificationCoverage,
            );
        }

        $devStatusCoverage = $this->devStatusCoverage($nodes, $frameDevStatus, $selectionSource, $fileHasDevStatus);
        // Emit the coverage as a diagnostic only when a dev-status signal is
        // actually present, so files without dev-status keep a clean (empty)
        // diagnostics list. The coverage report is always available via the
        // `dev_status_coverage` plan key regardless.
        if ( $fileHasDevStatus ) {
            $diagnostics[] = array(
                'severity' => 'info',
                'code'     => 'figma_dev_status_coverage',
                'message'  => 'dev_status' === $selectionSource
                    ? 'Frame selection was driven by Figma dev-status.'
                    : 'Dev-status present but no FRAME qualified; heuristics drove selection.',
                'coverage' => $devStatusCoverage,
            );
        }

        return array(
            'schema'                  => 'blocks-engine/figma-transformer/page-plan/v1',
            'page_count'              => count($pages),
            'candidate_count'         => count($candidates),
            'pages'                   => $pages,
            'selection_source'        => $selectionSource,
            'source_frame_evidence'   => array(
                'schema'                      => 'blocks-engine/figma-transformer/source-frame-evidence/v1',
                'selection_source'            => $selectionSource,
                'selected_frame_ids'          => array_values($selectedIds),
                'emitted_primary_frame_ids'   => array_values(array_map(static fn (array $page): string => (string) ($page['frame_id'] ?? ''), $pages)),
                'page_candidate_frame_ids'    => array_values(array_map('strval', array_keys($pageCandidates))),
                'top_level_candidate_frame_ids' => array_values(array_map('strval', array_keys($candidates))),
                'pages'                       => $pageIdentityEvidence,
                'filtered_candidates'         => $filteredCandidateEvidence,
                'candidate_decisions'         => $this->candidateDecisionEvidence(
                    $candidates,
                    $pageCandidates,
                    $classifications,
                    $filteredCandidateEvidence,
                    $pageIdentityEvidence,
                    $responsiveGroups,
                    $detectionById
                ),
            ),
            'dev_status_coverage'     => $devStatusCoverage,
            'classification_coverage' => $classificationCoverage,
            'diagnostics'             => $diagnostics,
        );
    }

    /**
     * Whether a FRAME node is a TOP-LEVEL page candidate: walking up its ancestor
     * chain reaches a CANVAS or the document root WITHOUT first passing through
     * another container FRAME/GROUP/INSTANCE/COMPONENT. SECTION nodes are
     * transparent because they are Figma's on-canvas organizational grouping (a
     * dev-handoff "folder"), so a page frame placed inside a section is still
     * top-level; likewise a frame at the document root (a CANVAS-less synthetic
     * source) is top-level. This is the generic structural test that separates
     * real page frames from the nested frames (annotation cards, layout-grid
     * guides, component internals) that must never compete for page selection.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     */
    private function isTopLevelFrame(string $id, array $nodes, array $parentIndex): bool
    {
        $cursor = $parentIndex[$id] ?? null;
        $guard = 0;
        while ( is_string($cursor) && isset($nodes[$cursor]) && is_array($nodes[$cursor]) && $guard < 4096 ) {
            ++$guard;
            $type = strtoupper((string) ($nodes[$cursor]['type'] ?? ''));
            if ( 'CANVAS' === $type ) {
                return true;
            }
            if ( 'SECTION' !== $type ) {
                // Nested inside another frame/group/component → content, not a page.
                return false;
            }
            $cursor = $parentIndex[$cursor] ?? null;
        }

        // Reached the document root (no parent, or only SECTIONs above) without
        // passing through a container frame: a depth-1 frame, i.e. a page.
        return true;
    }

    /**
     * Whether a candidate frame has PAGE-SIZED dimensions: a width inside the
     * realistic page-width band (mobile through wide desktop) and a height tall
     * enough to be a scrolling page. This is the dimension half of page
     * candidacy — it drops top-level frames that are structurally on the canvas
     * but are plainly not pages: layout-grid guides and chips (too narrow),
     * decorative "Title Card" dividers / cover thumbnails / device mockups (too
     * short), and oversized presentation/overview artboards (too wide). Explicit
     * `frame_ids` bypass this gate entirely.
     */
    private function isPageSizedCandidate(float $width, float $height): bool
    {
        return $width >= self::PAGE_MIN_WIDTH_PX
            && $width <= self::PAGE_MAX_WIDTH_PX
            && $height >= self::PAGE_MIN_HEIGHT_PX;
    }

    /**
     * Build a single page-candidate record (subtree stats, dimensions, score).
     * Shared by the top-level candidacy scan and the explicit-`frame_ids`
     * on-demand path so a requested frame at any depth is scored identically.
     *
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $childrenIndex
     * @param array<string, string|null>          $parentIndex
     * @param array<string, array<string, int>>   $statsMemo
     * @return array{id:string,node:array<string, mixed>,stats:array{nodes:int,texts:int,assets:int},dimensions:array{width:float|null,height:float|null},score:int}
     */
    private function buildCandidate(string $id, array $node, array $nodes, array $childrenIndex, array $parentIndex, array &$statsMemo): array
    {
        $stats = $this->subtreeStats($id, $nodes, $childrenIndex, $statsMemo);
        $dimensions = $this->dimensions($node);

        return array(
            'id'         => $id,
            'node'       => $node,
            'stats'      => $stats,
            'dimensions' => $dimensions,
            'score'      => $this->scoreCandidate($id, $node, $dimensions, $stats, $nodes, $parentIndex),
        );
    }

    /**
     * Classify a single FRAME candidate into a role + page type via
     * {@see ScenegraphFrameClassifier}, gathering the content-shape signals
     * (swatch/specimen tiles, repeating post cards) from the already-built
     * node/children indexes so no extra scenegraph traversal is required.
     *
     * @param array<string, mixed>                $candidate
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $childrenIndex
     * @param array<string, string|null>          $parentIndex
     * @return array{role: string, page_type: string|null, signals: array<int, string>, is_page: bool}
     */
    private function classifyCandidate(string $id, array $candidate, array $nodes, array $childrenIndex, array $parentIndex): array
    {
        $node = is_array($candidate['node'] ?? null) ? $candidate['node'] : array();
        $stats = is_array($candidate['stats'] ?? null) ? $candidate['stats'] : array();
        $dimensions = is_array($candidate['dimensions'] ?? null) ? $candidate['dimensions'] : array();
        $section = $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex);
        $page = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);
        $contentShape = $this->contentShapeSignals($id, $nodes, $childrenIndex);

        return $this->frameClassifier->classify(array(
            'name'                 => (string) ($node['name'] ?? ''),
            'width'                => $dimensions['width'] ?? null,
            'height'               => $dimensions['height'] ?? null,
            'text_count'           => (int) ($stats['texts'] ?? 0),
            'asset_count'          => (int) ($stats['assets'] ?? 0),
            'uniform_tile_count'   => $contentShape['uniform_tile_count'],
            'repeating_card_count' => $contentShape['repeating_card_count'],
            'section_name'         => $section['name'] ?? null,
            'page_name'            => $page['name'] ?? null,
        ));
    }

    /**
     * Derive content-shape signals from a frame's descendant structure:
     *
     *   - uniform_tile_count: the largest group of near-uniform, small sibling
     *     containers sharing one parent (a swatch/type-specimen grid tell).
     *   - repeating_card_count: the largest group of structurally-similar sibling
     *     subtrees sharing one parent (a list-of-post-cards / archive tell).
     *
     * Both are computed by scanning every node in the frame's subtree once and,
     * for each parent, bucketing its direct children by a coarse shape signature
     * (child count + presence of a TEXT/IMAGE descendant). The largest matching
     * bucket per category wins. This stays frame-local and deterministic.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $childrenIndex
     * @return array{uniform_tile_count: int, repeating_card_count: int}
     */
    private function contentShapeSignals(string $rootId, array $nodes, array $childrenIndex): array
    {
        $uniformTiles = 0;
        $repeatingCards = 0;

        $stack = array($rootId);
        $guard = 0;
        while ( array() !== $stack && $guard < 200000 ) {
            ++$guard;
            $parentId = (string) array_pop($stack);
            $childIds = is_array($childrenIndex[$parentId] ?? null) ? $childrenIndex[$parentId] : array();

            $tileBuckets = array();
            $cardBuckets = array();
            foreach ( $childIds as $childId ) {
                if ( ! is_string($childId) ) {
                    continue;
                }
                $stack[] = $childId;

                $childNode = is_array($nodes[$childId] ?? null) ? $nodes[$childId] : array();
                $type = strtoupper((string) ($childNode['type'] ?? ''));
                if ( ! in_array($type, array('FRAME', 'GROUP', 'INSTANCE', 'COMPONENT', 'RECTANGLE', 'ELLIPSE'), true) ) {
                    continue;
                }

                $grandChildren = is_array($childrenIndex[$childId] ?? null) ? $childrenIndex[$childId] : array();
                $grandCount = count($grandChildren);
                $dimensions = $this->dimensions($childNode);
                $width = (float) ($dimensions['width'] ?? 0);
                $height = (float) ($dimensions['height'] ?? 0);
                $area = $width * $height;

                if ( $grandCount <= 2 && $area > 0 && $area <= 90000 ) {
                    // Small, leaf-like tile (swatch / specimen chip).
                    $tileKey = $this->dimensionBucketKey($width, $height);
                    $tileBuckets[$tileKey] = ($tileBuckets[$tileKey] ?? 0) + 1;
                }

                if ( $grandCount >= 2 ) {
                    // Composite container (a card with media + text). Signature
                    // groups by child count so a repeating card layout clusters.
                    $cardKey = $type . ':' . min(12, $grandCount);
                    $cardBuckets[$cardKey] = ($cardBuckets[$cardKey] ?? 0) + 1;
                }
            }

            $uniformTiles = max($uniformTiles, array() === $tileBuckets ? 0 : max($tileBuckets));
            $repeatingCards = max($repeatingCards, array() === $cardBuckets ? 0 : max($cardBuckets));
        }

        return array(
            'uniform_tile_count'   => $uniformTiles,
            'repeating_card_count' => $repeatingCards,
        );
    }

    private function dimensionBucketKey(float $width, float $height): string
    {
        // Bucket to the nearest 16px so near-uniform swatches cluster together
        // while genuinely different shapes stay apart.
        $bucket = static fn (float $value): int => (int) round($value / 16.0);

        return $bucket($width) . 'x' . $bucket($height);
    }

    /**
     * Build the classification coverage report: per-role and per-page-type
     * counts plus, for every selected page, the signals that drove its
     * classification — the diagnostic that explains WHY each frame landed where
     * it did.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $classifications
     * @param array<int, array<string, mixed>>    $pages
     * @return array<string, mixed>
     */
    private function classificationCoverage(array $candidates, array $classifications, array $pages): array
    {
        $roles = array(
            ScenegraphFrameClassifier::ROLE_DESIGN_SYSTEM => 0,
            ScenegraphFrameClassifier::ROLE_PAGE          => 0,
        );
        $pageTypes = array(
            ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE => 0,
            ScenegraphFrameClassifier::PAGE_TYPE_SINGLE     => 0,
            ScenegraphFrameClassifier::PAGE_TYPE_ARCHIVE    => 0,
            ScenegraphFrameClassifier::PAGE_TYPE_404        => 0,
            ScenegraphFrameClassifier::PAGE_TYPE_PAGE       => 0,
            ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN    => 0,
        );
        $excludedDesignSystemFrameIds = array();

        foreach ( $classifications as $candidateId => $classification ) {
            $role = (string) ($classification['role'] ?? ScenegraphFrameClassifier::ROLE_PAGE);
            $roles[$role] = ($roles[$role] ?? 0) + 1;
            if ( ScenegraphFrameClassifier::ROLE_DESIGN_SYSTEM === $role ) {
                $excludedDesignSystemFrameIds[] = (string) $candidateId;
            }
        }

        $selectedSignals = array();
        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }
            $pageType = (string) ($page['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN);
            $pageTypes[$pageType] = ($pageTypes[$pageType] ?? 0) + 1;
            $selectedSignals[] = array(
                'frame_id'  => (string) ($page['frame_id'] ?? ''),
                'name'      => (string) ($page['name'] ?? ''),
                'role'      => (string) ($page['role'] ?? ScenegraphFrameClassifier::ROLE_PAGE),
                'page_type' => $pageType,
                'signals'   => is_array($page['classification_signals'] ?? null) ? $page['classification_signals'] : array(),
            );
        }

        sort($excludedDesignSystemFrameIds);

        return array(
            'schema'                          => 'blocks-engine/figma-transformer/frame-classification/v1',
            'candidate_count'                 => count($candidates),
            'classified_count'                => count($classifications),
            'roles'                           => $roles,
            'page_types'                      => $pageTypes,
            'excluded_design_system_count'    => count($excludedDesignSystemFrameIds),
            'excluded_design_system_frame_ids' => $excludedDesignSystemFrameIds,
            'selected_page_classifications'   => $selectedSignals,
        );
    }

    /**
     * Central source-frame identity contract for miners and diagnostics.
     *
     * `selected_frame_id` is the frame that survived selection/filtering. For a
     * responsive group it can be any sibling; `primary_frame_id` is the emitted
     * page identity (`frame_id`) after variant ordering picks the widest/desktop
     * source frame.
     *
     * @param array<int, string>                  $members
     * @param array<string, mixed>                $candidate
     * @param array<string, mixed>                $classification
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<string, mixed>
     */
    private function sourceFrameIdentityEvidence(
        string $selectedId,
        string $primaryId,
        array $members,
        array $candidate,
        array $classification,
        array $detectionById,
        string $slug,
        bool $entrypoint
    ): array {
        $variantIds = array_values($members);
        return array(
            'selected_frame_id'        => $selectedId,
            'primary_frame_id'         => $primaryId,
            'emitted_frame_id'         => $primaryId,
            'variant_frame_ids'        => $variantIds,
            'variant_sibling_frame_ids' => array_values(array_filter($variantIds, static fn (string $id): bool => $id !== $primaryId)),
            'selected_is_primary'      => $selectedId === $primaryId,
            'name'                     => (string) ($candidate['node']['name'] ?? $primaryId),
            'slug'                     => $slug,
            'entrypoint'               => $entrypoint,
            'device_hint'              => (string) ($detectionById[$primaryId]['device_hint'] ?? 'unknown'),
            'width'                    => $candidate['dimensions']['width'] ?? null,
            'height'                   => $candidate['dimensions']['height'] ?? null,
            'role'                     => (string) ($classification['role'] ?? ScenegraphFrameClassifier::ROLE_PAGE),
            'page_type'                => (string) ($classification['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN),
        );
    }

    /**
     * @param array<string, mixed>      $candidate
     * @param array<string, mixed>|null $classification
     * @param array<string, mixed>      $context
     * @return array<string, mixed>
     */
    private function candidateEvidenceRecord(string $id, array $candidate, string $reason, ?array $classification = null, array $context = array()): array
    {
        $node = is_array($candidate['node'] ?? null) ? $candidate['node'] : array();
        $dimensions = is_array($candidate['dimensions'] ?? null) ? $candidate['dimensions'] : array();
        $record = array(
            'frame_id'       => $id,
            'name'           => (string) ($node['name'] ?? $id),
            'reason'         => $reason,
            'width'          => $dimensions['width'] ?? null,
            'height'         => $dimensions['height'] ?? null,
            'score'          => (int) ($candidate['score'] ?? 0),
            'route_identity' => $this->routeIdentity((string) ($node['name'] ?? $id)),
        );

        if ( is_array($classification) ) {
            $record['role'] = (string) ($classification['role'] ?? ScenegraphFrameClassifier::ROLE_PAGE);
            $record['page_type'] = (string) ($classification['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN);
            $record['classification_signals'] = is_array($classification['signals'] ?? null) ? $classification['signals'] : array();
        }

        foreach ( $context as $key => $value ) {
            if ( is_string($key) && '' !== $key && null !== $value ) {
                $record[$key] = $value;
            }
        }

        return $record;
    }

    /**
     * Build one bounded decision row per top-level candidate frame so complex
     * files can be audited without reverse-engineering planner internals.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $pageCandidates
     * @param array<string, array<string, mixed>> $classifications
     * @param array<int, array<string, mixed>>    $filteredCandidateEvidence
     * @param array<int, array<string, mixed>>    $pageIdentityEvidence
     * @param array<string, array<int, string>>   $responsiveGroups
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<int, array<string, mixed>>
     */
    private function candidateDecisionEvidence(
        array $candidates,
        array $pageCandidates,
        array $classifications,
        array $filteredCandidateEvidence,
        array $pageIdentityEvidence,
        array $responsiveGroups,
        array $detectionById
    ): array {
        $filteredById = array();
        foreach ( $filteredCandidateEvidence as $evidence ) {
            $frameId = isset($evidence['frame_id']) && is_scalar($evidence['frame_id']) ? (string) $evidence['frame_id'] : '';
            if ( '' !== $frameId ) {
                $filteredById[$frameId] = $evidence;
            }
        }

        $emittedById = array();
        foreach ( $pageIdentityEvidence as $page ) {
            $primaryId = isset($page['primary_frame_id']) && is_scalar($page['primary_frame_id']) ? (string) $page['primary_frame_id'] : '';
            $variantIds = is_array($page['variant_frame_ids'] ?? null) ? $page['variant_frame_ids'] : array();
            foreach ( $variantIds as $variantId ) {
                if ( ! is_scalar($variantId) ) {
                    continue;
                }
                $variantId = (string) $variantId;
                $emittedById[$variantId] = array(
                    'decision'         => $variantId === $primaryId ? 'emitted_primary' : 'emitted_responsive_variant',
                    'primary_frame_id' => $primaryId,
                    'path'             => $page['path'] ?? null,
                    'slug'             => $page['slug'] ?? null,
                    'base_slug'        => $page['base_slug'] ?? null,
                    'slug_collision_index' => $page['slug_collision_index'] ?? null,
                );
            }
        }

        $rows = array();
        foreach ( $candidates as $id => $candidate ) {
            $id = (string) $id;
            $node = is_array($candidate['node'] ?? null) ? $candidate['node'] : array();
            $dimensions = is_array($candidate['dimensions'] ?? null) ? $candidate['dimensions'] : array();
            $classification = is_array($classifications[$id] ?? null) ? $classifications[$id] : array();
            $detection = is_array($detectionById[$id] ?? null) ? $detectionById[$id] : array();
            $group = is_array($responsiveGroups[$id] ?? null) ? array_values($responsiveGroups[$id]) : array();

            $decision = array(
                'frame_id' => $id,
                'name'     => (string) ($node['name'] ?? $id),
                'decision' => isset($pageCandidates[$id]) ? 'omitted_unselected' : 'omitted_candidate_filter',
                'reason'   => isset($pageCandidates[$id]) ? 'not_selected_after_ranking_or_route_filters' : 'not_in_selectable_page_pool',
                'score'    => (int) ($candidate['score'] ?? 0),
                'width'    => $dimensions['width'] ?? null,
                'height'   => $dimensions['height'] ?? null,
                'route_identity' => $this->routeIdentity((string) ($node['name'] ?? $id)),
                'device_hint' => (string) ($detection['device_hint'] ?? 'unknown'),
                'sibling_group_key' => $detection['sibling_group_key'] ?? null,
                'responsive_group_frame_ids' => $group,
                'role'      => (string) ($classification['role'] ?? ScenegraphFrameClassifier::ROLE_PAGE),
                'page_type' => (string) ($classification['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN),
                'classification_signals' => is_array($classification['signals'] ?? null) ? $classification['signals'] : array(),
            );

            if ( isset($filteredById[$id]) ) {
                $decision['decision'] = 'omitted_filtered';
                $decision['reason'] = $filteredById[$id]['reason'] ?? 'filtered';
                foreach ( array('canonical_frame_id', 'route_identity', 'source') as $key ) {
                    if ( array_key_exists($key, $filteredById[$id]) ) {
                        $decision[$key] = $filteredById[$id][$key];
                    }
                }
            }

            if ( isset($emittedById[$id]) ) {
                $decision = array_merge($decision, $emittedById[$id]);
                $decision['reason'] = 'selected_for_emission';
                unset($decision['canonical_frame_id'], $decision['source']);
            }

            $rows[] = array_filter($decision, static fn (mixed $value): bool => null !== $value);
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
                ?: strcmp((string) ($left['frame_id'] ?? ''), (string) ($right['frame_id'] ?? ''))
        );

        return $rows;
    }

    /**
     * Resolve normalized dev status (ready_for_dev|completed) for every node
     * that carries one. Nodes without a status, or with an unmapped raw token,
     * are omitted so they never count as a selection signal.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, string> node id => normalized status
     */
    private function resolveNodeDevStatus(array $nodes): array
    {
        $statusById = array();
        foreach ( $nodes as $id => $node ) {
            if ( ! is_string($id) || ! is_array($node) ) {
                continue;
            }

            $resolved = ScenegraphDevStatus::resolve($node);
            if ( null !== $resolved && null !== $resolved['normalized'] ) {
                $statusById[$id] = $resolved['normalized'];
            }
        }

        return $statusById;
    }

    /**
     * Map each FRAME candidate to its effective dev status: the frame's own
     * normalized status, or the nearest ancestor (e.g. its SECTION) that carries
     * one. Frames with no marked status anywhere in their ancestry are omitted.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, string>               $nodeDevStatus
     * @param array<string, string|null>          $parentIndex
     * @return array<string, string> frame id => normalized status
     */
    private function resolveFrameDevStatus(array $candidates, array $nodeDevStatus, array $parentIndex): array
    {
        if ( array() === $nodeDevStatus ) {
            return array();
        }

        $frameStatus = array();
        foreach ( array_keys($candidates) as $frameId ) {
            $frameId = (string) $frameId;
            $cursor = $frameId;
            $guard = 0;
            while ( is_string($cursor) && '' !== $cursor && $guard < 4096 ) {
                if ( isset($nodeDevStatus[$cursor]) ) {
                    $frameStatus[$frameId] = $nodeDevStatus[$cursor];
                    break;
                }
                $cursor = $parentIndex[$cursor] ?? null;
                ++$guard;
            }
        }

        return $frameStatus;
    }

    /**
     * Rank dev-status-marked FRAME candidates: completed first, then
     * ready_for_dev, with the existing heuristic score as the tiebreak so order
     * within a status band stays deterministic and backward compatible.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, string>               $statusById
     * @return array<int, string>
     */
    private function rankedCandidateIdsByDevStatus(array $candidates, array $statusById): array
    {
        uasort(
            $candidates,
            function (array $left, array $right) use ($statusById): int {
                $leftRank = $this->devStatusRank((string) ($statusById[(string) ($left['id'] ?? '')] ?? ''));
                $rightRank = $this->devStatusRank((string) ($statusById[(string) ($right['id'] ?? '')] ?? ''));
                if ( $leftRank !== $rightRank ) {
                    return $leftRank <=> $rightRank;
                }

                return $right['score'] <=> $left['score']
                    ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            }
        );

        $ids = array_keys($candidates);
        $nonWrapperIds = array_values(array_filter(
            $ids,
            fn (string $id): bool => ! $this->isWrapperName((string) ($candidates[$id]['node']['name'] ?? ''))
        ));

        return empty($nonWrapperIds) ? $ids : $nonWrapperIds;
    }

    private function devStatusRank(string $status): int
    {
        return match ( $status ) {
            ScenegraphDevStatus::COMPLETED     => 0,
            ScenegraphDevStatus::READY_FOR_DEV => 1,
            default                            => 2,
        };
    }

    /**
     * Build the dev-status coverage diagnostic: per-status counts for SECTION
     * and FRAME nodes, raw-token tallies, and the signal that drove selection.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string>               $frameDevStatus
     * @return array<string, mixed>
     */
    private function devStatusCoverage(array $nodes, array $frameDevStatus, string $selectionSource, bool $fileHasDevStatus): array
    {
        $sections = array('ready_for_dev' => 0, 'completed' => 0, 'unmapped' => 0);
        $frames = array('ready_for_dev' => 0, 'completed' => 0, 'unmapped' => 0);
        $rawTokens = array();
        $nodesWithRawStatus = 0;

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            $resolved = ScenegraphDevStatus::resolve($node);
            if ( null === $resolved ) {
                continue;
            }

            ++$nodesWithRawStatus;
            $rawKey = $resolved['raw'];
            $rawTokens[$rawKey] = ($rawTokens[$rawKey] ?? 0) + 1;

            $bucket = match ( $resolved['normalized'] ) {
                ScenegraphDevStatus::READY_FOR_DEV => 'ready_for_dev',
                ScenegraphDevStatus::COMPLETED     => 'completed',
                default                            => 'unmapped',
            };

            $type = strtoupper((string) ($node['type'] ?? ''));
            if ( 'SECTION' === $type ) {
                ++$sections[$bucket];
            } elseif ( 'FRAME' === $type ) {
                ++$frames[$bucket];
            }
        }

        $frameEffective = array('ready_for_dev' => 0, 'completed' => 0);
        foreach ( $frameDevStatus as $status ) {
            if ( isset($frameEffective[$status]) ) {
                ++$frameEffective[$status];
            }
        }

        return array(
            'selection_source'        => $selectionSource,
            'file_has_dev_status'     => $fileHasDevStatus,
            'nodes_with_raw_status'   => $nodesWithRawStatus,
            'sections'                => $sections,
            'frames'                  => $frames,
            'frames_effective'        => $frameEffective,
            'raw_tokens'              => $rawTokens,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function explicitFrameIds(array $options): array
    {
        $ids = array();
        if ( isset($options['frame_id']) && is_scalar($options['frame_id']) ) {
            $ids[] = (string) $options['frame_id'];
        }

        foreach ( is_array($options['frame_ids'] ?? null) ? $options['frame_ids'] : array() as $id ) {
            if ( is_scalar($id) ) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== $id)));
    }

    /**
     * Resolve responsive detection, bounding it against the NUMBER OF FRAME
     * CANDIDATES — not total node count.
     *
     * The prior implementation re-inspected the source, forcing a SECOND full
     * {@see ScenegraphIndex} build over every node; on the 293MB "WP.Cloud 2.0"
     * .fig that second index OOMed, so #265 skipped detection above a
     * 25k-node ceiling and responsive grouping silently switched off at scale.
     * Detection only needs frame-level data (name, width, height, device hint,
     * ancestor ids), all of which the planner already holds in memory after its
     * single index build, so it now runs regardless of total node count. The
     * only remaining bound guards the genuinely pathological case of an absurd
     * number of frame candidates; the `bounded` flag lets
     * {@see ScenegraphPagePlanner::plan()} surface a
     * `responsive_detection_bounded` diagnostic.
     *
     * @param array<string, array<string, mixed>> $candidates   FRAME candidates the planner tracks.
     * @param array<string, array<string, mixed>> $nodes        Already-built node map (for ancestor lookup).
     * @param array<string, string|null>          $parentIndex  Already-built parent index.
     * @param array<string, mixed>                $options
     * @return array{detection: array<string, array<string, mixed>>, bounded: bool, frame_candidate_count: int, frame_candidate_limit: int}
     */
    private function detectResponsive(array $candidates, array $nodes, array $parentIndex, array $options): array
    {
        $limit = isset($options['responsive_detection_frame_limit']) && is_numeric($options['responsive_detection_frame_limit'])
            ? max(1, (int) $options['responsive_detection_frame_limit'])
            : self::RESPONSIVE_DETECTION_FRAME_LIMIT;
        $frameCount = count($candidates);

        if ( $frameCount > $limit ) {
            return array(
                'detection'             => array(),
                'bounded'               => true,
                'frame_candidate_count' => $frameCount,
                'frame_candidate_limit' => $limit,
            );
        }

        return array(
            'detection'             => $this->detectionById($candidates, $nodes, $parentIndex),
            'bounded'               => false,
            'frame_candidate_count' => $frameCount,
            'frame_candidate_limit' => $limit,
        );
    }

    /**
     * Build the frame-level detection report (device_hint / sibling_group_key /
     * responsive_siblings) WITHOUT building a second scenegraph index.
     *
     * The planner already holds every FRAME candidate's dimensions plus the
     * node/parent indexes in memory, so it extracts only the minimal
     * frame-level records the detection heuristics need (name, dimensions,
     * page/section/parent ids) and hands them to
     * {@see ScenegraphFrameInspector::detectResponsiveFrames()}. No descendant
     * node index is materialized for detection — the question is answered from
     * frame-level data alone, which is what keeps grouping memory-safe at
     * scale.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     * @return array<string, array<string, mixed>>
     */
    private function detectionById(array $candidates, array $nodes, array $parentIndex): array
    {
        $frames = array();
        foreach ( $candidates as $id => $candidate ) {
            $id = (string) $id;
            $node = is_array($candidate['node'] ?? null) ? $candidate['node'] : array();
            $page = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);
            $section = $this->nearestAncestor($id, array('SECTION'), $nodes, $parentIndex);
            $parentId = $parentIndex[$id] ?? null;
            $frames[] = array(
                'id'           => $id,
                'name'         => (string) ($node['name'] ?? ''),
                'width'        => $candidate['dimensions']['width'] ?? null,
                'height'       => $candidate['dimensions']['height'] ?? null,
                'page_id'      => $page['id'] ?? null,
                'section_id'   => $section['id'] ?? null,
                'parent_id'    => is_string($parentId) ? $parentId : null,
                'ancestor_ids' => $this->ancestorIds($id, $nodes, $parentIndex),
            );
        }

        return $this->frameInspector->detectResponsiveFrames($frames);
    }

    /**
     * Cluster FRAME candidates into responsive sibling-groups.
     *
     * Builds connected components over the inspector's `responsive_siblings`
     * edges (restricted to FRAME ids the planner tracks), then GUARDS each
     * multi-member component: a real responsive group must have distinct device
     * hints OR a material width spread. Same-name + same-device-hint + ~same
     * width siblings are duplicate/iteration drafts (e.g. the "For Hosts" frame
     * that grouped 4 desktop-1440 drafts differing only in height) — they stay
     * as separate pages and surface a `duplicate_draft_frames` diagnostic rather
     * than collapsing into bogus breakpoint variants.
     *
     * Returns a map from every grouped member frame id to its full, ordered
     * variant list (so the page loop can look up the group from whichever member
     * was selected first) plus the grouping-rationale / rejection diagnostics.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array{groups: array<string, array<int, string>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function responsiveGroups(array $candidates, array $detectionById): array
    {
        $groups = array();
        $diagnostics = array();
        foreach ( $this->responsiveComponents($candidates, $detectionById) as $members ) {
            if ( count($members) < 2 ) {
                continue;
            }

            $ordered = $this->orderVariantIds($members, $candidates, $detectionById);
            $assessment = $this->assessResponsiveGroup($ordered, $candidates, $detectionById);

            if ( $assessment['is_responsive'] ) {
                foreach ( $ordered as $memberId ) {
                    $groups[$memberId] = $ordered;
                }
                $diagnostics[] = $this->responsiveGroupFormedDiagnostic($ordered, $assessment);
                continue;
            }

            // Duplicate/iteration drafts: keep them as separate pages (omitted
            // from the group map so the page loop falls back to one-per-frame).
            $diagnostics[] = $this->duplicateDraftFramesDiagnostic($ordered, $candidates, $assessment);
        }

        return array('groups' => $groups, 'diagnostics' => $diagnostics);
    }

    /**
     * Build connected components from responsive sibling edges reported by the
     * frame inspector. Edges to frames outside the planner's candidate set are
     * ignored so downstream grouping cannot pull filtered/noisy frames back in.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<string, array<int, string>>
     */
    private function responsiveComponents(array $candidates, array $detectionById): array
    {
        $parent = array();
        foreach ( array_keys($candidates) as $id ) {
            $parent[(string) $id] = (string) $id;
        }

        $find = static function (string $node) use (&$parent): string {
            while ( $parent[$node] !== $node ) {
                $parent[$node] = $parent[$parent[$node]];
                $node = $parent[$node];
            }

            return $node;
        };

        foreach ( array_keys($candidates) as $id ) {
            $id = (string) $id;
            $siblings = is_array($detectionById[$id]['responsive_siblings'] ?? null) ? $detectionById[$id]['responsive_siblings'] : array();
            foreach ( $siblings as $sibling ) {
                $siblingId = is_array($sibling) && isset($sibling['id']) && is_scalar($sibling['id']) ? (string) $sibling['id'] : '';
                if ( '' !== $siblingId && isset($parent[$siblingId]) ) {
                    $parent[$find($id)] = $find($siblingId);
                }
            }
        }

        $components = array();
        foreach ( array_keys($candidates) as $id ) {
            $components[$find((string) $id)][] = (string) $id;
        }

        return $components;
    }

    /**
     * @param array<int, string> $ordered
     * @param array{reasons: array<int, string>, device_hints: array<int, string>, distinct_hint_count: int, width_spread_px: float} $assessment
     * @return array<string, mixed>
     */
    private function responsiveGroupFormedDiagnostic(array $ordered, array $assessment): array
    {
        return array(
            'severity'              => 'info',
            'code'                  => 'responsive_group_formed',
            'message'               => 'Collapsed frames into one responsive page.',
            'primary_frame_id'      => $ordered[0],
            'frame_ids'             => $ordered,
            'reasons'               => $assessment['reasons'],
            'device_hints'          => $assessment['device_hints'],
            'distinct_device_hints' => $assessment['distinct_hint_count'],
            'width_spread_px'       => $assessment['width_spread_px'],
        );
    }

    /**
     * @param array<int, string>                  $ordered
     * @param array<string, array<string, mixed>> $candidates
     * @param array{device_hints: array<int, string>} $assessment
     * @return array<string, mixed>
     */
    private function duplicateDraftFramesDiagnostic(array $ordered, array $candidates, array $assessment): array
    {
        $canonicalId = $this->canonicalDraftId($ordered, $candidates);

        return array(
            'severity'           => 'warning',
            'code'               => 'duplicate_draft_frames',
            'message'            => 'Frames share a name, device hint, and width; treated as duplicate drafts rather than responsive breakpoints.',
            'canonical_frame_id' => $canonicalId,
            'draft_frame_ids'    => array_values(array_filter($ordered, static fn (string $id): bool => $id !== $canonicalId)),
            'frame_ids'          => $ordered,
            'device_hint'        => $assessment['device_hints'][0] ?? 'unknown',
            'width'              => (float) ($candidates[$canonicalId]['dimensions']['width'] ?? 0),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, true>
     */
    private function duplicateDraftRejectIds(array $diagnostics): array
    {
        $rejectIds = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( ! is_array($diagnostic) || 'duplicate_draft_frames' !== ($diagnostic['code'] ?? null) ) {
                continue;
            }

            foreach ( is_array($diagnostic['draft_frame_ids'] ?? null) ? $diagnostic['draft_frame_ids'] : array() as $draftId ) {
                if ( is_string($draftId) && '' !== $draftId ) {
                    $rejectIds[$draftId] = true;
                }
            }
        }

        return $rejectIds;
    }

    /**
     * @param array<int, string>                  $selectedIds
     * @param array<string, array<string, mixed>> $pageCandidates
     * @return array{ids: array<int, string>, diagnostics: array<int, array<string, mixed>>}
     */
    private function filterDuplicateRouteDraftFrames(array $selectedIds, array $pageCandidates): array
    {
        $buckets = array();
        foreach ( $selectedIds as $id ) {
            if ( ! isset($pageCandidates[$id]) ) {
                continue;
            }
            $buckets[$this->routeDraftBucketKey($pageCandidates[$id])][] = $id;
        }

        $rejectIds = array();
        $diagnostics = array();
        foreach ( $buckets as $members ) {
            if ( count($members) < 2 ) {
                continue;
            }

            $canonicalId = $this->canonicalDraftId($members, $pageCandidates);
            $draftIds = array_values(array_filter($members, static fn (string $id): bool => $id !== $canonicalId));
            foreach ( $draftIds as $draftId ) {
                $rejectIds[$draftId] = true;
            }

            $diagnostics[] = array(
                'severity'           => 'warning',
                'code'               => 'duplicate_route_draft_frames',
                'message'            => 'Frames resolve to the same route identity and dimensions; only the canonical draft is emitted as a page.',
                'canonical_frame_id' => $canonicalId,
                'draft_frame_ids'    => $draftIds,
                'frame_ids'          => $members,
                'route_identity'     => $this->candidateRouteIdentity($pageCandidates[$canonicalId]),
                'width'              => (float) ($pageCandidates[$canonicalId]['dimensions']['width'] ?? 0),
                'height'             => (float) ($pageCandidates[$canonicalId]['dimensions']['height'] ?? 0),
            );
        }

        if ( array() === $rejectIds ) {
            return array('ids' => array_values($selectedIds), 'diagnostics' => $diagnostics);
        }

        return array(
            'ids' => array_values(array_filter(
                $selectedIds,
                static fn (string $id): bool => ! isset($rejectIds[$id])
            )),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function routeDraftBucketKey(array $candidate): string
    {
        $width = (int) round((float) ($candidate['dimensions']['width'] ?? 0));
        $height = (int) round((float) ($candidate['dimensions']['height'] ?? 0));

        return $this->candidateRouteIdentity($candidate) . ':' . $width . 'x' . $height;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateRouteIdentity(array $candidate): string
    {
        return $this->routeIdentity((string) ($candidate['node']['name'] ?? ''));
    }

    /**
     * @param array<int, string>                  $selectedIds
     * @param array<string, array<string, mixed>> $pageCandidates
     * @param array<string, array<string, mixed>> $classifications
     * @return array{ids: array<int, string>, diagnostics: array<int, array<string, mixed>>}
     */
    private function filterLowConfidenceUtilityRouteFrames(array $selectedIds, array $pageCandidates, array $classifications): array
    {
        $kept = array();
        $diagnostics = array();
        foreach ( $selectedIds as $id ) {
            $candidate = $pageCandidates[$id] ?? null;
            if ( ! is_array($candidate) ) {
                $kept[] = $id;
                continue;
            }

            $name = (string) ($candidate['node']['name'] ?? '');
            $width = (float) ($candidate['dimensions']['width'] ?? 0);
            $height = (float) ($candidate['dimensions']['height'] ?? 0);
            if ( ScenegraphFrameClassifier::PAGE_TYPE_FRONT_PAGE === ($classifications[$id]['page_type'] ?? null) ) {
                $kept[] = $id;
                continue;
            }
            if ( ! $this->isLowConfidenceUtilityRouteName($name, $width, $height) ) {
                $kept[] = $id;
                continue;
            }

            $diagnostics[] = array(
                'severity'               => 'info',
                'code'                   => 'low_confidence_route_frame_filtered',
                'message'                => 'Filtered a device mockup or presentation/template frame from page route emission.',
                'frame_id'               => $id,
                'name'                   => $name,
                'width'                  => $width,
                'height'                 => $height,
                'route_identity'         => $this->routeIdentity($name),
                'page_type'              => $classifications[$id]['page_type'] ?? ScenegraphFrameClassifier::PAGE_TYPE_UNKNOWN,
                'classification_signals' => $classifications[$id]['signals'] ?? array(),
            );
        }

        return array('ids' => array() === $kept ? array_values($selectedIds) : array_values($kept), 'diagnostics' => $diagnostics);
    }

    private function isLowConfidenceUtilityRouteName(string $name, float $width, float $height): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
        if ( 1 === preg_match('/\b(iphone|ipad|android|phone|mobile|desktop|tablet)\b.*\b(\d{3,4}px|black|white|mockup|frame)\b/i', $name) ) {
            return true;
        }
        if ( 1 === preg_match('/^(presentation cover|section intro|intro|summary|page)$/i', $normalized) ) {
            return 1440.0 === round($width) && 1024.0 === round($height);
        }

        return false;
    }

    private function routeIdentity(string $name): string
    {
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($name)) ?: array()));
        $semanticTokens = array('home', 'about', 'services', 'reviews', 'faq', 'contact', 'news', 'handouts', 'blog', 'pricing', 'shop', 'products', 'product', 'portfolio', 'work', 'team', 'careers');
        foreach ( array_reverse($tokens) as $token ) {
            if ( in_array($token, $semanticTokens, true) ) {
                return $token;
            }
        }

        return $this->slugify($name);
    }

    /**
     * Filter repeated exploration/reference frames across sibling canvases.
     *
     * Designers often keep full-page drafts in successive canvases named
     * "Design", "Design v2", "Design v3", plus reference canvases like
     * "Wireframes" or "Backgrounds - for dev". When several top-level frames
     * have the same normalized page identity, device hint, and width on different
     * canvases, they represent alternate drafts of the same page route rather
     * than independent pages. Keep the highest-confidence design canvas version;
     * preserve unique wireframe/reference pages that have no design duplicate.
     *
     * @param array<int, string>                  $selectedIds
     * @param array<string, array<string, mixed>> $pageCandidates
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     * @param array<string, array<string, mixed>> $detectionById
     * @return array{ids: array<int, string>, diagnostics: array<int, array<string, mixed>>}
     */
    private function filterCrossCanvasExplorationFrames(array $selectedIds, array $pageCandidates, array $nodes, array $parentIndex, array $detectionById): array
    {
        $buckets = array();
        $canvasById = array();
        foreach ( $selectedIds as $id ) {
            if ( ! isset($pageCandidates[$id]) ) {
                continue;
            }

            $candidate = $pageCandidates[$id];
            $canvas = $this->nearestAncestor($id, array('CANVAS'), $nodes, $parentIndex);
            if ( null === $canvas ) {
                continue;
            }

            $width = (int) round((float) ($candidate['dimensions']['width'] ?? 0));
            $deviceHint = (string) ($detectionById[$id]['device_hint'] ?? 'unknown');
            $identity = $this->candidateRouteIdentity($candidate);
            $bucket = $identity . ':' . $deviceHint . ':' . $width;
            $buckets[$bucket][] = $id;
            $canvasById[$id] = $canvas;
        }

        $rejectIds = array();
        $diagnostics = array();
        foreach ( $buckets as $bucket => $members ) {
            if ( count($members) < 2 ) {
                continue;
            }

            $canvasIds = array();
            foreach ( $members as $memberId ) {
                $canvasIds[(string) ($canvasById[$memberId]['id'] ?? '')] = true;
            }
            if ( count(array_filter(array_keys($canvasIds), static fn (string $id): bool => '' !== $id)) < 2 ) {
                continue;
            }

            $canvasRanks = array();
            foreach ( $members as $memberId ) {
                $canvasRanks[] = $this->canvasExplorationRank((string) ($canvasById[$memberId]['name'] ?? ''));
            }
            if ( count(array_unique($canvasRanks)) < 2 ) {
                continue;
            }

            $canonicalId = $this->canonicalExplorationFrameId($members, $pageCandidates, $canvasById);
            $draftIds = array_values(array_filter($members, static fn (string $id): bool => $id !== $canonicalId));
            foreach ( $draftIds as $draftId ) {
                $rejectIds[$draftId] = true;
            }

            $canvasNames = array();
            foreach ( $members as $memberId ) {
                $canvasNames[$memberId] = (string) ($canvasById[$memberId]['name'] ?? '');
            }

            $parts = explode(':', $bucket);
            $diagnostics[] = array(
                'severity'                 => 'info',
                'code'                     => 'cross_canvas_exploration_frames',
                'message'                  => 'Frames share a normalized page identity, device hint, and width across different canvases; only the canonical design draft is emitted as a page.',
                'canonical_frame_id'       => $canonicalId,
                'draft_frame_ids'          => $draftIds,
                'frame_ids'                => $members,
                'normalized_page_identity' => $parts[0] ?? '',
                'device_hint'              => $parts[1] ?? 'unknown',
                'width'                    => (int) ($parts[2] ?? 0),
                'canvas_names'             => $canvasNames,
            );
        }

        if ( array() === $rejectIds ) {
            return array('ids' => array_values($selectedIds), 'diagnostics' => $diagnostics);
        }

        return array(
            'ids' => array_values(array_filter(
                $selectedIds,
                static fn (string $id): bool => ! isset($rejectIds[$id])
            )),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * @param array<int, string>                  $members
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array{id:string,name:string,type:string}> $canvasById
     */
    private function canonicalExplorationFrameId(array $members, array $candidates, array $canvasById): string
    {
        $canonical = $members[0];
        foreach ( $members as $id ) {
            $rank = $this->canvasExplorationRank((string) ($canvasById[$id]['name'] ?? ''));
            $bestRank = $this->canvasExplorationRank((string) ($canvasById[$canonical]['name'] ?? ''));
            $score = (int) ($candidates[$id]['score'] ?? 0);
            $bestScore = (int) ($candidates[$canonical]['score'] ?? 0);

            if ( $rank > $bestRank || ( $rank === $bestRank && ( $score > $bestScore || ( $score === $bestScore && strcmp($id, $canonical) < 0 ) ) ) ) {
                $canonical = $id;
            }
        }

        return $canonical;
    }

    private function canvasExplorationRank(string $canvasName): int
    {
        $name = strtolower(trim($canvasName));
        $rank = 100;

        if ( 1 === preg_match('/\bdesign\s*v?(\d+)\b/i', $canvasName, $matches) ) {
            $rank = 1000 + (int) $matches[1];
        } elseif ( 1 === preg_match('/\bdesign\b/i', $canvasName) ) {
            $rank = 1000;
        } elseif ( 1 === preg_match('/\b(wireframes?|lo\s*fi|sketch|draft)\b/i', $canvasName) ) {
            $rank = 200;
        }

        if ( 1 === preg_match('/\b(shared|backgrounds?|for\s+dev|internal|reference|archive|components?|style\s*tiles?|presentation|template|search\s*bar)\b/i', $canvasName) ) {
            $rank -= 100;
        }
        if ( str_contains($name, '💀') ) {
            $rank -= 150;
        }

        return $rank;
    }

    /**
     * Decide whether an ordered sibling cluster is a genuine responsive group.
     *
     * A cluster qualifies when it has at least two distinct (non-unknown) device
     * hints OR a material width spread (both an absolute and a relative
     * threshold), which separates real breakpoints from same-width duplicate
     * drafts. The rationale is returned so the caller can emit it as a
     * diagnostic.
     *
     * @param array<int, string>                  $members Ordered frame ids (widest first).
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array{is_responsive: bool, reasons: array<int, string>, device_hints: array<int, string>, distinct_hint_count: int, width_spread_px: float, width_spread_ratio: float}
     */
    private function assessResponsiveGroup(array $members, array $candidates, array $detectionById): array
    {
        $hints = array();
        $widths = array();
        foreach ( $members as $id ) {
            $hints[] = (string) ($detectionById[$id]['device_hint'] ?? 'unknown');
            $width = $candidates[$id]['dimensions']['width'] ?? null;
            if ( is_numeric($width) ) {
                $widths[] = (float) $width;
            }
        }

        $distinctKnownHints = array_values(array_unique(array_filter(
            $hints,
            static fn (string $hint): bool => 'unknown' !== $hint
        )));
        $distinctHintCount = count($distinctKnownHints);

        $minWidth = array() === $widths ? 0.0 : min($widths);
        $maxWidth = array() === $widths ? 0.0 : max($widths);
        $spread = $maxWidth - $minWidth;
        $ratio = $maxWidth > 0.0 ? $spread / $maxWidth : 0.0;

        $reasons = array();
        if ( $distinctHintCount >= 2 ) {
            $reasons[] = 'device_hint_diversity';
        }
        if ( $spread >= self::RESPONSIVE_WIDTH_MATERIAL_PX && $ratio >= self::RESPONSIVE_WIDTH_MATERIAL_RATIO ) {
            $reasons[] = 'width_spread';
        }

        return array(
            'is_responsive'       => array() !== $reasons,
            'reasons'             => $reasons,
            'device_hints'        => $hints,
            'distinct_hint_count' => $distinctHintCount,
            'width_spread_px'     => $spread,
            'width_spread_ratio'  => $ratio,
        );
    }

    /**
     * Pick the canonical frame from a duplicate-draft cluster (highest score,
     * then deterministic id tiebreak) for the diagnostic record.
     *
     * @param array<int, string>                  $members
     * @param array<string, array<string, mixed>> $candidates
     */
    private function canonicalDraftId(array $members, array $candidates): string
    {
        $canonical = $members[0];
        foreach ( $members as $id ) {
            $score = (int) ($candidates[$id]['score'] ?? 0);
            $bestScore = (int) ($candidates[$canonical]['score'] ?? 0);
            if ( $score > $bestScore || ( $score === $bestScore && strcmp($id, $canonical) < 0 ) ) {
                $canonical = $id;
            }
        }

        return $canonical;
    }

    /**
     * Order group members primary-first so the emitted page identity sorts first.
     *
     * @param array<int, string>                  $ids
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<int, string>
     */
    private function orderVariantIds(array $ids, array $candidates, array $detectionById): array
    {
        usort(
            $ids,
            function (string $left, string $right) use ($candidates, $detectionById): int {
                $leftPrimaryScore = $this->variantPrimaryScore($left, $candidates, $detectionById);
                $rightPrimaryScore = $this->variantPrimaryScore($right, $candidates, $detectionById);
                if ( $leftPrimaryScore !== $rightPrimaryScore ) {
                    return $rightPrimaryScore <=> $leftPrimaryScore;
                }

                $leftWidth = (float) ($candidates[$left]['dimensions']['width'] ?? 0);
                $rightWidth = (float) ($candidates[$right]['dimensions']['width'] ?? 0);
                if ( $leftWidth !== $rightWidth ) {
                    return $rightWidth <=> $leftWidth;
                }

                return strcmp($left, $right);
            }
        );

        return array_values($ids);
    }

    /**
     * Score responsive siblings for primary selection. Device class is the first
     * signal, then route/page content depth. This lets a real long desktop page
     * beat a shallow ultra-wide exploration/mockup board without dropping that
     * wide board or mobile frame from the responsive variants.
     *
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     */
    private function variantPrimaryScore(string $id, array $candidates, array $detectionById): int
    {
        $deviceScore = match ( (string) ($detectionById[$id]['device_hint'] ?? 'unknown') ) {
            'desktop' => 6000,
            'tablet'  => 4000,
            'unknown' => 3000,
            'mobile'  => 1000,
            default   => 0,
        };

        $candidate = $candidates[$id] ?? array();
        $dimensions = is_array($candidate['dimensions'] ?? null) ? $candidate['dimensions'] : array();
        $stats = is_array($candidate['stats'] ?? null) ? $candidate['stats'] : array();
        $width = (float) ($dimensions['width'] ?? 0);
        $height = (float) ($dimensions['height'] ?? 0);
        $textCount = (int) ($stats['texts'] ?? 0);
        $nodeCount = (int) ($stats['nodes'] ?? 0);

        $depthScore = min(900, (int) round($height / 8.0))
            + min(400, $textCount * 8)
            + min(240, intdiv($nodeCount, 4));
        $shallowWidePenalty = $width >= 1600.0 && $height > 0.0 && $height < max(1200.0, $width * 0.65) ? 500 : 0;

        return $deviceScore
            + (int) ($candidate['score'] ?? 0)
            + $depthScore
            - $shallowWidePenalty;
    }

    /**
     * Build the ordered breakpoint-variant list for one page plan.
     *
     * @param array<int, string>                  $members Ordered frame ids (primary first).
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, array<string, mixed>> $detectionById
     * @return array<int, array<string, mixed>>
     */
    private function breakpointVariants(array $members, string $primaryId, array $candidates, array $detectionById): array
    {
        $variants = array();
        foreach ( $members as $order => $memberId ) {
            $candidate = $candidates[$memberId];
            $name = (string) ($candidate['node']['name'] ?? $memberId);
            $variants[] = array(
                'frame_id'        => $memberId,
                'name'            => $name,
                'slug'            => $this->slugify($name),
                'responsive_identity' => $this->frameInspector->normalizedPageName($name),
                'sibling_group_key' => isset($detectionById[$memberId]['sibling_group_key']) && is_scalar($detectionById[$memberId]['sibling_group_key']) ? (string) $detectionById[$memberId]['sibling_group_key'] : null,
                'device_hint'     => (string) ($detectionById[$memberId]['device_hint'] ?? 'unknown'),
                'viewport_width'  => $candidate['dimensions']['width'],
                'viewport_height' => $candidate['dimensions']['height'],
                'primary'         => $memberId === $primaryId,
                'order'           => $order,
            );
        }

        return $variants;
    }

    /**
     * Remove orphan mobile-width frames that are nav/menu component demos from
     * the selected ID list when the file has real responsive paired pages.
     *
     * A frame is filtered when ALL three structural signals fire:
     *   1. It is NOT part of any responsive group (orphan — no desktop partner).
     *   2. Its width sits in the mobile range (≤ {@see ORPHAN_MOBILE_MAX_WIDTH_PX}).
     *   3. Its name matches the menu/nav/component-demo pattern.
     *
     * The file-has-responsive-pairs guard (non-empty $responsiveGroups) is
     * checked by the caller so a mobile-only design (no desktop variants at all)
     * never triggers the filter — there are no responsive groups to compare
     * against, so every frame is treated as a potential page.
     *
     * Page_type is intentionally NOT a signal here: a nav-menu component demo
     * with text nodes (nav links) classifies as `page` rather than `unknown`,
     * so gating on `unknown` would silently fail to exclude the common case.
     * The name pattern is specific enough (mobile menu / hamburger / nav drawer
     * vocabulary) that false positives are near-impossible once the orphan +
     * mobile-width guards have already narrowed the candidate set.
     *
     * @param array<int, string>                  $selectedIds
     * @param array<string, array<string, mixed>> $pageCandidates
     * @param array<string, array<int, string>>   $responsiveGroups  member id → ordered group
     * @param array<string, array<string, mixed>> $classifications    unused; reserved for future expansion
     * @param array<string, array<string, mixed>> $detectionById      unused; reserved for future expansion
     * @return array<int, string>
     */
    private function filterOrphanMenuDemoFrames(
        array $selectedIds,
        array $pageCandidates,
        array $responsiveGroups,
        array $classifications,
        array $detectionById
    ): array {
        return array_values(array_filter(
            $selectedIds,
            function (string $id) use ($pageCandidates, $responsiveGroups): bool {
                // Signal 1: part of a responsive group → keep (it has a partner).
                if ( isset($responsiveGroups[$id]) ) {
                    return true;
                }

                // Signal 2: width must be mobile-range to be an orphan mobile frame.
                $width = (float) ($pageCandidates[$id]['dimensions']['width'] ?? 0);
                if ( $width > self::ORPHAN_MOBILE_MAX_WIDTH_PX ) {
                    return true;
                }

                // Signal 3: name must match a menu/nav/component-demo pattern.
                $name = (string) ($pageCandidates[$id]['node']['name'] ?? '');
                if ( ! $this->isMenuOrComponentDemoName($name) ) {
                    return true;
                }

                // All three signals fired: exclude as orphan menu/component demo.
                return false;
            }
        ));
    }

    /**
     * Maximum width (px) for a frame to be considered a mobile-width orphan
     * in {@see filterOrphanMenuDemoFrames}. Covers common device widths (320,
     * 375, 390, 414, 428, 430) with a comfortable margin above the widest
     * production mobile width while staying well below the tablet range.
     */
    private const ORPHAN_MOBILE_MAX_WIDTH_PX = 500.0;

    /**
     * Whether a frame name matches a navigation menu or component-demo pattern.
     *
     * Matches names that describe a menu/nav UI component state (e.g. "Mobile
     * Menu", "Mobile Nav", "Hamburger Menu", "Nav Drawer") rather than a real
     * page. The pattern is deliberately focused on navigation-component
     * vocabulary to avoid false positives on content pages like "Our Menu"
     * or "Products". The other three structural signals in
     * {@see filterOrphanMenuDemoFrames} serve as a multi-layer guard so the
     * pattern does not need to be exhaustive.
     */
    private function isMenuOrComponentDemoName(string $name): bool
    {
        return 1 === preg_match(
            '/\b(mobile\s+menu|mobile\s+nav(igation)?|nav(igation)?\s+menu|nav(igation)?\s+(drawer|overlay|open)|hamburger(\s+menu)?|menu\s+(open|expanded|overlay|demo|state)|mobile\s+header|mobile\s+drawer|flyout(\s+menu)?|side\s*bar\s+menu)\b/i',
            $name
        );
    }

    /**
     * @param array<string, array{id:string,node:array<string, mixed>,stats:array{nodes:int,texts:int,assets:int},dimensions:array{width:float|null,height:float|null},score:int}> $candidates
     * @return array<int, string>
     */
    private function rankedCandidateIds(array $candidates): array
    {
        uasort(
            $candidates,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
        );

        $ids = array_keys($candidates);
        $nonWrapperIds = array_values(array_filter(
            $ids,
            fn (string $id): bool => ! $this->isWrapperName((string) ($candidates[$id]['node']['name'] ?? ''))
        ));

        return empty($nonWrapperIds) ? $ids : $nonWrapperIds;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<int, string>>   $childrenIndex
     * @param array<string, array<string, int>>   $memo
     * @return array{nodes:int,texts:int,assets:int}
     */
    private function subtreeStats(string $id, array $nodes, array $childrenIndex, array &$memo): array
    {
        if ( isset($memo[$id]) ) {
            return $memo[$id];
        }

        $node = is_array($nodes[$id] ?? null) ? $nodes[$id] : array();
        $stats = array(
            'nodes'  => 1,
            'texts'  => 'TEXT' === strtoupper((string) ($node['type'] ?? '')) ? 1 : 0,
            'assets' => $this->nodeHasAssetReference($node) ? 1 : 0,
        );

        foreach ( $childrenIndex[$id] ?? array() as $childId ) {
            if ( is_string($childId) ) {
                $childStats = $this->subtreeStats($childId, $nodes, $childrenIndex, $memo);
                $stats['nodes'] += $childStats['nodes'];
                $stats['texts'] += $childStats['texts'];
                $stats['assets'] += $childStats['assets'];
            }
        }

        $memo[$id] = $stats;
        return $stats;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{width:float|null,height:float|null}
     */
    private function dimensions(array $node): array
    {
        $width = null;
        $height = null;
        if ( is_numeric($node['width'] ?? null) ) {
            $width = (float) $node['width'];
        }
        if ( is_numeric($node['height'] ?? null) ) {
            $height = (float) $node['height'];
        }
        if ( is_array($node['size'] ?? null) ) {
            $width = is_numeric($node['size']['x'] ?? null) ? (float) $node['size']['x'] : $width;
            $height = is_numeric($node['size']['y'] ?? null) ? (float) $node['size']['y'] : $height;
        }

        foreach ( array('absoluteBoundingBox', 'absoluteRenderBounds') as $key ) {
            if ( is_array($node[$key] ?? null) ) {
                $width = is_numeric($node[$key]['width'] ?? null) ? (float) $node[$key]['width'] : $width;
                $height = is_numeric($node[$key]['height'] ?? null) ? (float) $node[$key]['height'] : $height;
            }
        }

        return array('width' => $width, 'height' => $height);
    }

    /**
     * @param array<string, mixed>                $node
     * @param array{width:float|null,height:float|null} $dimensions
     * @param array{nodes:int,texts:int,assets:int} $stats
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>          $parentIndex
     */
    private function scoreCandidate(string $id, array $node, array $dimensions, array $stats, array $nodes, array $parentIndex): int
    {
        $name = (string) ($node['name'] ?? '');
        $width = (float) ($dimensions['width'] ?? 0);
        $height = (float) ($dimensions['height'] ?? 0);
        $area = $width * $height;
        $canvasDistance = $this->ancestorDistance($id, array('CANVAS'), $nodes, $parentIndex);

        return 100
            + min(300, $stats['texts'] * 5)
            + min(140, $stats['assets'] * 10)
            + min(180, intdiv($stats['nodes'], 8))
            + (null === $canvasDistance ? 0 : max(0, 180 - ($canvasDistance * 45)))
            + ($this->isSemanticName($name) ? 140 : 0)
            + ($this->isWebLikeDimensions($width, $height) ? 160 : 0)
            + ($area > 300000 ? 70 : 0)
            - ($this->isWrapperName($name) ? 260 : 0)
            - ($height > 0 && $height < 700 ? 100 : 0)
            - ($area > 0 && $area < 10000 ? 100 : 0);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeHasAssetReference(array $node): bool
    {
        foreach ( array('asset_id', 'assetId', 'image_ref', 'imageRef', 'imageHash') as $key ) {
            if ( isset($node[$key]) ) {
                return true;
            }
        }

        foreach ( array('fills', 'fillPaints', 'backgroundPaints') as $key ) {
            foreach ( is_array($node[$key] ?? null) ? $node[$key] : array() as $paint ) {
                if ( is_array($paint) && 'IMAGE' === strtoupper((string) ($paint['type'] ?? '')) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string>                 $types
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     * @return array{id:string,name:string,type:string}|null
     */
    private function nearestAncestor(string $id, array $types, array $nodes, array $parentIndex): ?array
    {
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            $type = strtoupper((string) ($nodes[$parent]['type'] ?? ''));
            if ( in_array($type, $types, true) ) {
                return array(
                    'id'   => $parent,
                    'name' => (string) ($nodes[$parent]['name'] ?? ''),
                    'type' => $type,
                );
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     * @return array<int, string>
     */
    private function ancestorIds(string $id, array $nodes, array $parentIndex): array
    {
        $ancestors = array();
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            $ancestors[] = $parent;
            $parent = $parentIndex[$parent] ?? null;
        }

        return $ancestors;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     * @return array<string, string>|null
     */
    private function internalOnlyCandidateScope(string $id, array $nodes, array $parentIndex): ?array
    {
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            $ancestor = $nodes[$parent];
            $type = strtoupper((string) ($ancestor['type'] ?? ''));
            if ( 'CANVAS' === $type || 'SECTION' === $type ) {
                $name = trim((string) ($ancestor['name'] ?? ''));
                if ( true === ($ancestor['internalOnly'] ?? false) || 1 === preg_match('/\binternal\s+only\b/i', $name) ) {
                    return array(
                        'scope_id'   => $parent,
                        'scope_name' => $name,
                        'scope_type' => $type,
                    );
                }
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    /**
     * @param array<int, string>                 $types
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, string|null>         $parentIndex
     */
    private function ancestorDistance(string $id, array $types, array $nodes, array $parentIndex): ?int
    {
        $distance = 0;
        $parent = $parentIndex[$id] ?? null;
        while ( is_string($parent) && isset($nodes[$parent]) && is_array($nodes[$parent]) ) {
            ++$distance;
            if ( in_array(strtoupper((string) ($nodes[$parent]['type'] ?? '')), $types, true) ) {
                return $distance;
            }
            $parent = $parentIndex[$parent] ?? null;
        }

        return null;
    }

    private function isSemanticName(string $name): bool
    {
        return 1 === preg_match('/\b(home|homepage|landing|pricing|about|contact|blog|article|shop|product|checkout|cart|account|login|page)\b/i', $name);
    }

    private function isWrapperName(string $name): bool
    {
        return 1 === preg_match('/^(outer|mockup|device|browser|screen|content)?\s*wrapper\b|^frame\s+\d+$/i', trim($name));
    }

    private function isWebLikeDimensions(float $width, float $height): bool
    {
        return $width >= 320 && $width <= 2400 && $height >= 700 && $height <= 20000;
    }

    /**
     * Resolve a page's slug source. A configured `frame_slug_map` entry always
     * wins. For a RESPONSIVE page (more than one breakpoint variant) the slug is
     * derived from the normalized page name so it reflects the page rather than
     * its widest variant — "Home Page – Desktop" + "Home Page – Mobile" collapse
     * to the slug "home-page", not "home-page-desktop". A single-variant page
     * keeps its own name (e.g. "Mobile Menu" stays "mobile-menu").
     *
     * @param array<int, string>   $members
     * @param array<string, mixed> $slugMap
     */
    private function pageSlug(string $primaryId, string $name, array $members, array $slugMap): string
    {
        $configured = $this->configuredSlug($primaryId, $slugMap);
        if ( null !== $configured ) {
            return $configured;
        }

        if ( count($members) > 1 ) {
            return $this->slugify($this->frameInspector->normalizedPageName($name));
        }

        return $this->slugify($name);
    }

    /**
     * @param array<string, mixed> $slugMap
     */
    private function configuredSlug(string $id, array $slugMap): ?string
    {
        if ( isset($slugMap[$id]) && is_scalar($slugMap[$id]) ) {
            $slug = $this->slugify((string) $slugMap[$id]);
            return '' === $slug ? null : $slug;
        }

        return null;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return '' === $slug ? 'page' : $slug;
    }

    /**
     * @param array<string, int> $seen
     */
    private function dedupeSlug(string $slug, array &$seen): string
    {
        return $this->dedupeSlugEvidence($slug, $seen)['slug'];
    }

    /**
     * @param array<string, int> $seen
     * @return array{slug:string,base_slug:string,collision_index:int}
     */
    private function dedupeSlugEvidence(string $slug, array &$seen): array
    {
        $base = '' === $slug ? 'page' : $slug;
        $seen[$base] = ($seen[$base] ?? 0) + 1;

        return array(
            'slug'            => 1 === $seen[$base] ? $base : $base . '-' . $seen[$base],
            'base_slug'       => $base,
            'collision_index' => $seen[$base],
        );
    }

    /**
     * @param array<string, bool> $seen
     */
    private function dedupeOutputPath(string $path, array &$seen): string
    {
        if ( ! isset($seen[$path]) ) {
            $seen[$path] = true;
            return $path;
        }

        $extensionOffset = strrpos($path, '.');
        $base = false === $extensionOffset ? $path : substr($path, 0, $extensionOffset);
        $extension = false === $extensionOffset ? '' : substr($path, $extensionOffset);
        $collisionIndex = 2;
        do {
            $candidate = $base . '-' . $collisionIndex . $extension;
            ++$collisionIndex;
        } while ( isset($seen[$candidate]) );

        $seen[$candidate] = true;
        return $candidate;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{width:float|null,height:float|null} $dimensions
     * @return array<int, array<string, mixed>>
     */
    private function pageDiagnostics(string $id, array $node, array $dimensions, bool $explicitSelected): array
    {
        $diagnostics = array();
        $name = (string) ($node['name'] ?? '');
        if ( $this->isWrapperName($name) ) {
            $diagnostics[] = array(
                'severity' => $explicitSelected ? 'info' : 'warning',
                'code'     => 'figma_page_plan_wrapper_name',
                'message'  => 'Selected frame has a wrapper-like name.',
                'frame_id' => $id,
            );
        }

        if ( ! $this->isWebLikeDimensions((float) ($dimensions['width'] ?? 0), (float) ($dimensions['height'] ?? 0)) ) {
            $diagnostics[] = array(
                'severity' => 'info',
                'code'     => 'figma_page_plan_unusual_dimensions',
                'message'  => 'Selected frame dimensions are outside the common web-page range.',
                'frame_id' => $id,
            );
        }

        return $diagnostics;
    }
}
