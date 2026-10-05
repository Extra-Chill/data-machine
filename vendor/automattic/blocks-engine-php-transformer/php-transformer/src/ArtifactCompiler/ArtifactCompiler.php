<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\CoreHtmlFallbackEvidence;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\MaterializationPlanBuilder;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ValidationException;
use DOMDocument;
use DOMElement;

final class ArtifactCompiler
{
    public const INPUT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';
    public const SHARED_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-shared-plan/v1';
    public const PAGE_PLAN_SCHEMA = 'blocks-engine/php-transformer/staged-page-plan/v1';

    /**
     * Tag-only script selectors whose native DOM shape can be behavior-bearing.
     *
     * @var array<int, string>
     */
    private const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li' );

	/** @var array<string, string> */
	private array $themeStaticCssCache = array();

	/** @var array<string, string> */
	private array $wordpressCompatCssCache = array();

    private ?HtmlTransformerAnalysisCache $htmlTransformerAnalysisCache = null;

    /** @var array<string, array<string, mixed>> */
    private array $filesByPath = array();

    /** @var array<int, array<string, mixed>> */
    private array $imageFiles = array();

    /** @var array<int, string> */
    private array $scriptContents = array();

    /** @var array<string, array<int, string>> */
    private array $scriptDomSelectorCache = array();

    /** @var array<string, array<string, bool>> */
    private array $scriptControlSelectorCache = array();

    /**
     * Resolve the runtime selector context used when a caller converts one
     * source document or landmark separately from full artifact compilation.
     *
     * @param array<int|string, mixed> $files
     * @return array<string, mixed>
     */
    public function runtimeContextForSource(string $html, string $sourcePath, array $files): array
    {
        $normalized = ( new ArtifactNormalizer() )->normalize(array(
            'entrypoint' => $sourcePath,
            'files'      => $files,
        ));
        $this->indexFiles($normalized['files']);

        return array(
            'runtime_script_metadata'  => $this->runtimeScriptMetadataForSource($html, $sourcePath, $normalized['files']),
            'runtime_dom_selectors'    => $this->runtimeDomSelectors($html, $sourcePath, $normalized['files']),
            'runtime_canvas_selectors' => $this->runtimeCanvasSelectors($html, $sourcePath, $normalized['files']),
        );
    }

    /**
     * Prepare the immutable, serializable shared portion of an artifact.
     *
     * File ownership is declared with `metadata.compilation`: `{scope:
     * "shared"}` or `{scope: "page", id: "..."}`. Unannotated HTML files
     * are page-owned by their normalized path; all other files are shared.
     *
     * @param array<string,mixed> $artifact
     * @return array<string,mixed>
     */
    public function prepareShared(array $artifact, ?PayloadReader $payloadReader = null): array
    {
        if (null !== $payloadReader && $this->containsPayloadReferences($artifact)) {
            return $this->prepareReferencedStage($artifact, 'shared', '', $payloadReader, null);
        }
        $partition = $this->partitionArtifact($artifact);
        $sharedArtifact = $this->artifactEnvelope($partition, $partition['shared']);
        $normalized = (new ArtifactNormalizer())->normalize($sharedArtifact);

        return array(
            'schema' => self::SHARED_PLAN_SCHEMA,
            'digest' => $this->planDigest(array('artifact' => $sharedArtifact, 'files' => $normalized['files'])),
            'artifact' => $sharedArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
        );
    }

    /**
     * Prepare one page-owned artifact portion against an immutable shared plan.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $sharedPlan
     * @return array<string,mixed>
     */
    public function preparePage(array $artifact, array $sharedPlan, string $pageId, ?PayloadReader $payloadReader = null): array
    {
        $this->assertSharedPlan($sharedPlan);
        if (null !== $payloadReader && $this->containsPayloadReferences($artifact)) {
            return $this->prepareReferencedStage($artifact, 'page', $pageId, $payloadReader, (string) $sharedPlan['digest']);
        }
        $partition = $this->partitionArtifact($artifact);
        if (!isset($partition['pages'][$pageId])) {
            throw new \InvalidArgumentException('The requested page ownership id is not present in the artifact.');
        }
        $pageArtifact = $this->artifactEnvelope($partition, $partition['pages'][$pageId]);
        $normalized = (new ArtifactNormalizer())->normalize($pageArtifact);

        return array(
            'schema' => self::PAGE_PLAN_SCHEMA,
            'digest' => $this->planDigest(array('shared_digest' => $sharedPlan['digest'], 'page_id' => $pageId, 'artifact' => $pageArtifact, 'files' => $normalized['files'])),
            'shared_digest' => $sharedPlan['digest'],
            'page_id' => $pageId,
            'artifact' => $pageArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
        );
    }

    /**
     * Compose independently prepared plans in canonical page-id and path order.
     *
     * @param array<string,mixed> $sharedPlan
     * @param array<int,array<string,mixed>> $pagePlans
     */
    public function compose(array $sharedPlan, array $pagePlans, ?PayloadReader $payloadReader = null): TransformerResult
    {
        $this->assertSharedPlan($sharedPlan);
        $sharedArtifact = $this->materializePlanArtifact($sharedPlan['artifact'], $payloadReader);
        $files = $sharedArtifact['files'];
        $seen = array();
        usort($pagePlans, static fn(array $left, array $right): int => strcmp((string) ($left['page_id'] ?? ''), (string) ($right['page_id'] ?? '')));
        foreach ($pagePlans as $pagePlan) {
            $this->assertPagePlan($pagePlan, $sharedPlan);
            if (isset($seen[$pagePlan['page_id']])) {
                throw new \InvalidArgumentException(sprintf('Composition received more than one staged page plan for page id "%s".', $pagePlan['page_id']));
            }
            $seen[$pagePlan['page_id']] = true;
            $pageArtifact = $this->materializePlanArtifact($pagePlan['artifact'], $payloadReader);
            $files = array_merge($files, $pageArtifact['files']);
        }
        $this->assertUniqueComposedPaths($files);
        $artifact = $sharedArtifact;
        $artifact['files'] = self::sortedByPath($files);

        return $this->compile($artifact);
    }

    /**
     * @param array<string, mixed> $artifact
     */
    public function compile(array $artifact): TransformerResult
    {
        $startedAt = hrtime(true);
		$this->themeStaticCssCache = array();
		$this->wordpressCompatCssCache = array();
        $this->htmlTransformerAnalysisCache = new HtmlTransformerAnalysisCache();
        $normalized = ( new ArtifactNormalizer() )->normalize($artifact);
        $entry = $this->entryFile($normalized['files'], $normalized['entrypoints']);
        $documents = $this->compileSourceDocuments($normalized);
        $diagnostics = array_merge($normalized['diagnostics'], $documents['diagnostics'], $this->svgAssetDiagnostics($normalized['files']));

        if ( null === $entry && array() === $documents['documents'] ) {
            $diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
        }

        $entryPath = is_array($entry) ? (string) $entry['path'] : '';
        $html = is_array($entry) ? (string) $entry['content'] : '';
        $components = $this->detectComponents($normalized['files'], $entryPath, $documents['components']);
        $blockTypes = $this->detectBlockTypes($normalized['files'], $diagnostics);
        $companionPluginPayloadBuilder = new CompanionPluginPayload();
        $normalized['files'] = $this->withStylesheetOccurrenceAssets($html, $entryPath, $normalized['files']);
        $this->indexFiles($normalized['files']);
        $entryBlocks = $this->compileEntryBlocks($html, $entryPath, $normalized['files'], $companionPluginPayloadBuilder->blockNamespace($artifact));
        $compiledHtmlDocuments = $this->compileHtmlSourceDocuments($normalized['files'], $entryPath, $companionPluginPayloadBuilder->blockNamespace($artifact));
        $authorStylesheetProjections = $entryBlocks['author_stylesheet_projections'];
        $allDiagnostics = $this->entryTransformDiagnostics($entryBlocks['diagnostics'], $entryPath);
        $allFallbacks = $entryBlocks['fallbacks'];
        $allGeneratedBlocks = $entryBlocks['generated_blocks'];
        $allGutenbergGaps = $entryBlocks['gutenberg_gaps'];
        $coreHtmlFallbackEvidence = array($entryBlocks['core_html_fallback_evidence']);
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $authorStylesheetProjections = array_merge($authorStylesheetProjections, $compiledHtmlDocument['author_stylesheet_projections'] ?? array());
            $allDiagnostics = array_merge($allDiagnostics, $this->entryTransformDiagnostics($compiledHtmlDocument['diagnostics'] ?? array(), (string) $sourcePath));
            $allFallbacks = array_merge($allFallbacks, $compiledHtmlDocument['fallbacks'] ?? array());
            $allGeneratedBlocks = array_merge($allGeneratedBlocks, $compiledHtmlDocument['generated_blocks'] ?? array());
            $allGutenbergGaps = array_merge($allGutenbergGaps, $compiledHtmlDocument['gutenberg_gaps'] ?? array());
            $coreHtmlFallbackEvidence[] = $compiledHtmlDocument['core_html_fallback_evidence'] ?? array();
        }
        $allGutenbergGaps = $this->dedupeRows($allGutenbergGaps);
        $normalized['runtime_declarations'] = $this->runtimeDeclarationsFromFallbacks($normalized['runtime_declarations'], $allFallbacks, $entryPath, $normalized['files']);
        $runtimeIslandPackage = ( new RuntimeIslandPackageBuilder() )->fromRuntimeIslands($entryBlocks['runtime_islands'], $normalized['files'], $entryPath);
        $normalized['files'] = $this->applyAuthorStylesheetProjections($normalized['files'], $authorStylesheetProjections, $entryBlocks['author_stylesheet_projections']);
        $wordpressCompatAsset = $this->wordpressCompatAsset($normalized['files']);
        $referenceReports = $this->referenceReports($normalized['files']);
        $manifestAssets = $this->assetManifest($normalized['files'], $entryPath, $referenceReports['asset_references'], $html);
        $entryOwnership = is_array($entry) ? $this->fileOwnership($entry) : array('scope' => 'page', 'id' => $entryPath);
        $generatedAssets = $this->generatedAssetsForDocuments($entryBlocks['assets'], $entryOwnership, $compiledHtmlDocuments, $normalized['files']);
        $beforeAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'before-author' === ($asset['stylesheet_placement'] ?? '')));
        $afterAuthorAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => 'after-author' === ($asset['stylesheet_placement'] ?? '')));
        $otherGeneratedAssets = array_values(array_filter($generatedAssets, static fn (array $asset): bool => ! in_array($asset, $beforeAuthorAssets, true) && ! in_array($asset, $afterAuthorAssets, true)));
        // Runtime loads the manifest in array order. Placement metadata keeps
        // engine support on its intended side of the authored stylesheets.
        $assets = array_merge($beforeAuthorAssets, $manifestAssets, $otherGeneratedAssets, $afterAuthorAssets);
        if ( null !== $wordpressCompatAsset ) {
            $assets[] = $wordpressCompatAsset;
        }
        $diagnostics = array_merge($diagnostics, $allDiagnostics);
        $serializedBlocks = $entryBlocks['serialized_blocks'];
        if ( '' === $serializedBlocks && ! empty($documents['documents'][0]['block_markup']) ) {
            $serializedBlocks = (string) $documents['documents'][0]['block_markup'];
        }
        $sourceReports = array(
            'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::merge($coreHtmlFallbackEvidence),
            'artifact' => array(
                'schema'          => self::INPUT_SCHEMA,
                'original_schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
                'entry_path'      => $entryPath,
                'entrypoints'     => $normalized['entrypoints'],
                'file_count'      => count($normalized['files']),
                'accepted_count'  => count($normalized['files']),
                'rejected_count'  => $normalized['rejected_count'],
                'bytes'           => $normalized['bytes'],
                'files_by_kind'   => $this->countBy($normalized['files'], 'kind'),
                'files_by_role'   => $this->countBy($normalized['files'], 'role'),
                'files_by_mime'   => $this->countBy($normalized['files'], 'mime_type'),
                'files_by_source' => $this->countBy($normalized['files'], 'source'),
                'files_by_intent' => $this->countBy($normalized['files'], 'intent'),
                'truncation_impact' => $normalized['truncation_impact'],
                'limits'          => array(
                    'max_files'       => $normalized['limits']['max_files'],
                    'max_file_bytes'  => $normalized['limits']['max_file_bytes'],
                    'max_total_bytes' => $normalized['limits']['max_total_bytes'],
                ),
                'source_hash'     => $normalized['source_hash'],
                'html'            => array(
                    'bytes'         => strlen($html),
                    'element_count' => preg_match_all('/<\s*[a-z][a-z0-9:-]*(?:\s|>|\/)/i', $html),
                ),
                'internal_links'    => $referenceReports['internal_links'],
                'asset_references'  => $referenceReports['asset_references'],
                'image_references'  => $referenceReports['image_references'],
                'runtime_declarations' => $normalized['runtime_declarations'],
            ),
        );
        $sourceReports['compiled_site'] = $this->compiledSiteReport($normalized, $entryPath, $documents['documents'], $assets, $blockTypes, $serializedBlocks, $entryBlocks['shell_artifacts'], $compiledHtmlDocuments);
        $editabilityDocuments = array($entryPath => array('blocks' => $entryBlocks['blocks'], 'serialized_blocks' => $entryBlocks['serialized_blocks']));
        foreach ($compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument) {
            $editabilityDocuments[(string) $sourcePath] = array(
                'blocks' => is_array($compiledHtmlDocument['blocks'] ?? null) ? $compiledHtmlDocument['blocks'] : array(),
                'serialized_blocks' => is_string($compiledHtmlDocument['serialized_blocks'] ?? null) ? $compiledHtmlDocument['serialized_blocks'] : '',
            );
        }
        $sourceReports['editability_report'] = (new EditabilityReport())->fromDocuments($editabilityDocuments);
        if ( array() !== $allGutenbergGaps ) {
            $sourceReports['gutenberg_gaps'] = $allGutenbergGaps;
        }
        $sourceReports['materialization_plan'] = ( new MaterializationPlanBuilder() )->fromCompiledSite($sourceReports['compiled_site']);
        $companionPluginPayload = $companionPluginPayloadBuilder->fromBlockTypes($blockTypes, $normalized['files'], $artifact, $allGeneratedBlocks, $runtimeIslandPackage);
        if ( array() !== $companionPluginPayload ) {
            $sourceReports['companion_plugin_payload'] = $companionPluginPayload;
        }
        if ( array() !== $entryBlocks['superseded_selectors'] ) {
            $sourceReports['superseded_selectors'] = $entryBlocks['superseded_selectors'];
        }
        $sourceReports['runtime_dependency_parity'] = ( new RuntimeDependencyParityReport() )->fromArtifact($normalized['files'], $html, $serializedBlocks, $entryPath, $entryBlocks['runtime_islands'], $referenceReports['asset_references'], $entryBlocks['interaction_candidates'], $entryBlocks['superseded_selectors']);
        if ( array() !== $entryBlocks['runtime_islands'] ) {
            $sourceReports['runtime_islands'] = $entryBlocks['runtime_islands'];
            if ( array() !== $runtimeIslandPackage ) {
                $sourceReports['runtime_island_package'] = $runtimeIslandPackage;
            }
        }
        $provenance = array(
            array(
                'source_format' => 'artifact',
                'input_keys'    => $this->sourceOperationInputKeys($artifact),
                'source_hash'   => $normalized['source_hash'],
            ),
        );
        // WordPressSitePlan consumes a canonical result envelope, so give it a
        // provisional report before final diagnostics and metrics are projected.
        $metrics = array(
            'input_bytes'           => $normalized['bytes'],
            'block_count'           => $this->countBlocks($entryBlocks['blocks']),
            'fallback_count'        => count($allFallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($serializedBlocks),
        );
        // Failed compilations have no materializable source identity and no site plan.
        if ( 'failed' !== $this->statusFromDiagnostics($diagnostics) ) {
            $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('artifact', $entryBlocks['blocks'], $allFallbacks, $sourceReports, $assets, $provenance, $metrics);
            try {
                $sourceReports['wordpress_site_plan'] = ( new WordPressSitePlan() )->fromResult(array(
                    'schema' => TransformerResult::SCHEMA,
                    'status' => $this->statusFromDiagnostics($diagnostics),
                    'components' => $components,
                    'block_types' => $blockTypes,
                    'source_reports' => $sourceReports,
                    'blocks' => $entryBlocks['blocks'],
                    'serialized_blocks' => $serializedBlocks,
                    'documents' => $documents['documents'],
                    'assets' => $assets,
                    'diagnostics' => $diagnostics,
                    'fallbacks' => $allFallbacks,
                    'provenance' => $provenance,
                    'coverage' => array(),
                    'context' => array(),
                    'metrics' => $metrics,
                ));
            } catch (\InvalidArgumentException $exception) {
                $diagnostics[] = $exception instanceof ValidationException
                    ? array_merge($exception->diagnostic(), array('severity' => 'error', 'source' => self::class))
                    : $this->diagnostic('wordpress_site_plan_not_self_contained', 'error', $exception->getMessage());
            }
        }

        $metrics['diagnostic_count'] = count($diagnostics);
        $metrics['transform_duration_ms'] = (hrtime(true) - $startedAt) / 1000000;
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('artifact', $entryBlocks['blocks'], $allFallbacks, $sourceReports, $assets, $provenance, $metrics);
        $sourceReports['wordpress_site_plan_diagnostics'] = array_values(array_filter($diagnostics, static fn (array $diagnostic): bool => str_starts_with((string) ($diagnostic['code'] ?? ''), 'wordpress_site_plan_')));
        if ( array() === $sourceReports['wordpress_site_plan_diagnostics'] ) {
            unset($sourceReports['wordpress_site_plan_diagnostics']);
        }

        return new TransformerResult(
            status: $this->statusFromDiagnostics($diagnostics),
            components: $components,
            blockTypes: $blockTypes,
            sourceReports: $sourceReports,
            blocks: $entryBlocks['blocks'],
            serializedBlocks: $serializedBlocks,
            documents: $documents['documents'],
            assets: $assets,
            diagnostics: $diagnostics,
            fallbacks: $allFallbacks,
            provenance: $provenance,
            metrics: $metrics
        );
    }

    /**
     * @param array<string,mixed> $artifact
     * @return array{shared:array<int,array<string,mixed>>,pages:array<string,array<int,array<string,mixed>>>,entrypoints:array<int,string>,limits:array<string,int>,runtime_declarations:array<int,array<string,mixed>>,schema:string,input_keys:array<int,string>}
     */
    private function partitionArtifact(array $artifact): array
    {
        $normalized = (new ArtifactNormalizer())->normalize($artifact);
        $shared = array();
        $pages = array();
        foreach ($normalized['files'] as $file) {
            $ownership = $this->fileOwnership($file);
            if ('shared' === $ownership['scope']) {
                $shared[] = $file;
                continue;
            }
            $pages[$ownership['id']][] = $file;
        }
        ksort($pages, SORT_STRING);
        foreach ($pages as $pageId => $files) {
            $pages[$pageId] = self::sortedByPath($files);
        }
        $shared = self::sortedByPath($shared);

        return array(
            'shared' => $shared,
            'pages' => $pages,
            'entrypoints' => $normalized['entrypoints'],
            'limits' => $normalized['limits'],
            'runtime_declarations' => $normalized['runtime_declarations'],
            'schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
            'input_keys' => array_values(array_filter(array_keys($artifact), 'is_string')),
        );
    }

    /** @param array<string,mixed> $file @return array{scope:string,id:string} */
    private function fileOwnership(array $file): array
    {
        $ownership = $file['metadata']['compilation'] ?? null;
        if (null === $ownership) {
            if ('html' === ($file['kind'] ?? null)) {
                return array('scope' => 'page', 'id' => (string) $file['path']);
            }
            // Inline styles/scripts expanded out of an unannotated page must
            // follow that page: parking page-varying content in the immutable
            // shared plan would invalidate every page plan on a page edit.
            $inlineSource = ArtifactNormalizer::inlineExpansionSourcePath($file);
            if ('' !== $inlineSource) {
                return array('scope' => 'page', 'id' => $inlineSource);
            }
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_array($ownership) || !is_string($ownership['scope'] ?? null) || !in_array($ownership['scope'], array('shared', 'page'), true)) {
            throw new \InvalidArgumentException('File compilation ownership requires a shared or page scope.');
        }
        if ('shared' === $ownership['scope']) {
            if (isset($ownership['id'])) {
                throw new \InvalidArgumentException('Shared file compilation ownership cannot declare a page id.');
            }
            return array('scope' => 'shared', 'id' => '');
        }
        if (!is_string($ownership['id'] ?? null) || '' === trim($ownership['id']) || strlen($ownership['id']) > 255) {
            throw new \InvalidArgumentException('Page file compilation ownership requires a bounded nonblank page id.');
        }
        return array('scope' => 'page', 'id' => $ownership['id']);
    }

    /**
     * @param array<int,array<string,mixed>> $entryAssets
     * @param array{scope:string,id:string} $entryOwnership
     * @param array<string,array<string,mixed>> $compiledHtmlDocuments
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>>
     */
    private function generatedAssetsForDocuments(array $entryAssets, array $entryOwnership, array $compiledHtmlDocuments, array $files): array
    {
        $assets = array();
        $assetIndexes = array();
        $append = static function (array $documentAssets, array $ownership) use (&$assets, &$assetIndexes): void {
            foreach ( $documentAssets as $asset ) {
                if ( ! is_array($asset) ) {
                    continue;
                }
                if ( 'css' === ($asset['kind'] ?? null) ) {
                    $asset['compilation'] ??= $ownership;
                }
                $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? '');
                $identity = hash('sha256', (string) ($asset['path'] ?? '') . "\0" . $payload);
                if ( ! isset($assetIndexes[$identity]) ) {
                    $assetIndexes[$identity] = count($assets);
                    $assets[] = $asset;
                    continue;
                }
                $index = $assetIndexes[$identity];
                if ( 'css' !== ($asset['kind'] ?? null) ) {
                    continue;
                }
                $existingOwnership = $assets[$index]['compilation'] ?? null;
                $assetOwnership = $asset['compilation'] ?? null;
                if ( $existingOwnership !== $assetOwnership ) {
                    $assets[$index]['compilation'] = array('scope' => 'shared');
                }
            }
        };

        $append($entryAssets, $entryOwnership);
        $filesByPath = array_column($files, null, 'path');
        foreach ( $compiledHtmlDocuments as $sourcePath => $compiledHtmlDocument ) {
            $file = $filesByPath[$sourcePath] ?? array('path' => $sourcePath, 'kind' => 'html');
            $append(
                array_values(array_filter(
                    is_array($compiledHtmlDocument['assets'] ?? null) ? $compiledHtmlDocument['assets'] : array(),
                    static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)
                )),
                $this->fileOwnership($file)
            );
        }

        return $assets;
    }

    /**
     * @param array{entrypoints:array<int,string>,limits:array<string,int>,runtime_declarations:array<int,array<string,mixed>>,schema:string,input_keys:array<int,string>} $partition
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>
     */
    private function artifactEnvelope(array $partition, array $files): array
    {
        $artifact = array(
            'files' => $files,
            'entrypoints' => $partition['entrypoints'],
            'compiler_limits' => $partition['limits'],
            'runtime_declarations' => $partition['runtime_declarations'],
            // Preserve the original generic source-operation identity across
            // serialized staged transport without exposing a consumer identity.
            'source_operation' => array('schema' => 'blocks-engine/php-transformer/source-operation/v1', 'input_keys' => $partition['input_keys']),
        );
        if ('' !== $partition['schema']) {
            $artifact['schema'] = $partition['schema'];
        }
        return $artifact;
    }

    /** @param array<string,mixed> $artifact */
    private function containsPayloadReferences(array $artifact): bool
    {
        foreach (is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $file) {
            if (is_array($file) && isset($file['payload_reference'])) return true;
        }
        return false;
    }

    /**
     * Resolve only one ownership partition, then replace the prepared payloads
     * with their portable references before returning the serializable plan.
     *
     * @return array<string,mixed>
     */
    private function prepareReferencedStage(array $artifact, string $scope, string $pageId, PayloadReader $payloadReader, ?string $sharedDigest): array
    {
        $stageArtifact = $artifact;
        $stageArtifact['files'] = array();
        $references = array();
        foreach (is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $key => $file) {
            if (!is_array($file)) continue;
            $path = is_string($file['path'] ?? null) ? $file['path'] : (is_string($key) ? $key : '');
            $ownership = $file['metadata']['compilation'] ?? null;
            $fileScope = is_array($ownership) && isset($ownership['scope']) ? $ownership['scope'] : (str_ends_with(strtolower($path), '.html') ? 'page' : 'shared');
            $filePageId = is_array($ownership) && is_string($ownership['id'] ?? null) ? $ownership['id'] : $path;
            if ($scope !== $fileScope || ('page' === $scope && $pageId !== $filePageId)) continue;
            if (isset($file['payload_reference'])) {
                $reference = $this->payloadReference($file['payload_reference']);
                if ($this->isReferenceBackedBinary($file)) {
                    $references[$path] = $reference;
                } else {
                    $content = $this->readPayload($reference, $payloadReader);
                    unset($file['payload_reference']);
                    $file['content'] = $content;
                    $references[$path] = $reference;
                }
            }
            $stageArtifact['files'][] = $file;
        }
        $partition = $this->partitionArtifact($stageArtifact);
        $stageFiles = 'shared' === $scope ? $partition['shared'] : ($partition['pages'][$pageId] ?? array());
        $planArtifact = $this->artifactEnvelope($partition, $stageFiles);
        $normalized = (new ArtifactNormalizer())->normalize($planArtifact);
        $plan = array(
            'schema' => 'shared' === $scope ? self::SHARED_PLAN_SCHEMA : self::PAGE_PLAN_SCHEMA,
            'artifact' => $planArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
        );
        if ('page' === $scope) {
            $plan['shared_digest'] = $sharedDigest;
            $plan['page_id'] = $pageId;
        }
        foreach ($plan['artifact']['files'] as &$file) {
            if (!isset($references[$file['path']])) continue;
            unset($file['content'], $file['content_base64']);
            $file['payload_reference'] = $references[$file['path']];
        }
        unset($file);
        $hasReferences = $this->containsPayloadReferences($plan['artifact']);
        $hashInput = 'shared' === $scope
            ? ($hasReferences ? array('artifact' => $plan['artifact']) : array('artifact' => $plan['artifact'], 'files' => $normalized['files']))
            : ($hasReferences ? array('shared_digest' => $sharedDigest, 'page_id' => $pageId, 'artifact' => $plan['artifact']) : array('shared_digest' => $sharedDigest, 'page_id' => $pageId, 'artifact' => $plan['artifact'], 'files' => $normalized['files']));
        $plan['digest'] = $this->planDigest($hashInput);
        return $plan;
    }

    /** @param mixed $reference @return array{schema:string,id:string,bytes:int,sha256:string} */
    private function payloadReference(mixed $reference): array
    {
        if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !is_string($reference['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $reference['sha256'])) {
            throw new \InvalidArgumentException('A payload reference requires a schema, id, byte count, and sha256 hex digest.');
        }
        return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']);
    }

    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    private function readPayload(array $reference, PayloadReader $payloadReader): string
    {
        $content = $payloadReader->read($reference);
        if (strlen($content) !== $reference['bytes'] || !hash_equals($reference['sha256'], hash('sha256', $content))) {
            throw new \InvalidArgumentException('The payload reader returned bytes that do not match the payload reference.');
        }
        return $content;
    }

    /** @param array<string,mixed> $artifact @return array<string,mixed> */
    private function materializePlanArtifact(array $artifact, ?PayloadReader $payloadReader): array
    {
        foreach ($artifact['files'] as &$file) {
            if (!isset($file['payload_reference'])) continue;
            if (null === $payloadReader) throw new \InvalidArgumentException('Composition requires a payload reader for referenced staged payloads.');
            $reference = $this->payloadReference($file['payload_reference']);
            if ($this->isReferenceBackedBinary($file)) continue;
            $content = $this->readPayload($reference, $payloadReader);
            unset($file['payload_reference']);
            if (!empty($file['binary'])) {
                $file['content'] = '';
                $file['content_base64'] = base64_encode($content);
            } else {
                $file['content'] = $content;
            }
        }
        unset($file);
        return $artifact;
    }

    /** @param array<string,mixed> $file */
    private function isReferenceBackedBinary(array $file): bool
    {
        if (!isset($file['payload_reference'])) return false;
        $mime = strtolower((string) ($file['mime_type'] ?? $file['type'] ?? ''));
        if ('image/svg+xml' === $mime || str_ends_with(strtolower((string) ($file['path'] ?? '')), '.svg')) return false;
        $extension = strtolower(pathinfo((string) ($file['path'] ?? ''), PATHINFO_EXTENSION));
        return !str_starts_with($mime, 'text/') && !in_array($mime, array('application/javascript', 'application/json', 'application/ecmascript'), true) && !in_array($extension, array('css', 'html', 'htm', 'js', 'mjs', 'json', 'md', 'markdown', 'mdx', 'svg'), true);
    }

    /** @param array<string,mixed> $hashInput */
    private function planDigest(array $hashInput): string
    {
        return RuntimeDeclarations::hash($hashInput);
    }

    /** @param array<string,mixed> $sharedPlan */
    private function assertSharedPlan(array $sharedPlan): void
    {
        if (($sharedPlan['schema'] ?? null) !== self::SHARED_PLAN_SCHEMA) {
            throw new \InvalidArgumentException('A staged shared plan must declare the staged shared plan schema.');
        }
        if (!is_array($sharedPlan['artifact'] ?? null) || !is_array($sharedPlan['artifact']['files'] ?? null)) {
            throw new \InvalidArgumentException('A staged shared plan requires its serialized artifact payload.');
        }
        $this->assertPlanDigest(
            $this->containsPayloadReferences($sharedPlan['artifact']) ? array('artifact' => $sharedPlan['artifact']) : array('artifact' => $sharedPlan['artifact'], 'files' => (new ArtifactNormalizer())->normalize($sharedPlan['artifact'])['files']),
            $sharedPlan['digest'] ?? null,
            'shared'
        );
    }

    /**
     * Per-plan validity only; cross-plan invariants (page-id uniqueness,
     * path collisions) are enforced by compose().
     *
     * @param array<string,mixed> $pagePlan
     * @param array<string,mixed> $sharedPlan
     */
    private function assertPagePlan(array $pagePlan, array $sharedPlan): void
    {
        if (($pagePlan['schema'] ?? null) !== self::PAGE_PLAN_SCHEMA) {
            throw new \InvalidArgumentException('A staged page plan must declare the staged page plan schema.');
        }
        if (!is_string($pagePlan['page_id'] ?? null) || '' === $pagePlan['page_id']) {
            throw new \InvalidArgumentException('A staged page plan requires a nonblank page id.');
        }
        if (($pagePlan['shared_digest'] ?? null) !== $sharedPlan['digest']) {
            throw new \InvalidArgumentException('A staged page plan must be bound to the supplied shared plan digest.');
        }
        if (!is_array($pagePlan['artifact']['files'] ?? null)) {
            throw new \InvalidArgumentException('A staged page plan requires its serialized artifact payload.');
        }
        $this->assertPlanDigest(
            $this->containsPayloadReferences($pagePlan['artifact']) ? array('shared_digest' => $sharedPlan['digest'], 'page_id' => $pagePlan['page_id'], 'artifact' => $pagePlan['artifact']) : array('shared_digest' => $sharedPlan['digest'], 'page_id' => $pagePlan['page_id'], 'artifact' => $pagePlan['artifact'], 'files' => (new ArtifactNormalizer())->normalize($pagePlan['artifact'])['files']),
            $pagePlan['digest'] ?? null,
            'page'
        );
    }

    /** @param array<string,mixed> $hashInput */
    private function assertPlanDigest(array $hashInput, mixed $digest, string $label): void
    {
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest)) {
            throw new \InvalidArgumentException(sprintf('A staged %s plan requires a sha256 hex digest.', $label));
        }
        if (!hash_equals($this->planDigest($hashInput), $digest)) {
            throw new \InvalidArgumentException(sprintf('The staged %s plan digest does not match its serialized artifact payload.', $label));
        }
    }

    /**
     * Composed plans must not collide on artifact paths: a silent
     * dedupe-rename during the final compile would ship files under an
     * identity no plan's digest ever covered. Uniqueness is checked on the
     * canonical path identity the final compile will use.
     *
     * @param array<int,array<string,mixed>> $files
     */
    private function assertUniqueComposedPaths(array $files): void
    {
        $seenPaths = array();
        foreach ($files as $file) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ('' !== $path && isset($seenPaths[$path])) {
                throw new \InvalidArgumentException(sprintf('Composed staged plans collide on artifact path "%s".', $path));
            }
            $seenPaths[$path] = true;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>>
     */
    private static function sortedByPath(array $files): array
    {
        usort($files, static fn(array $left, array $right): int => strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')));
        return $files;
    }

    /** @param array<string,mixed> $artifact @return array<int,string> */
    private function sourceOperationInputKeys(array $artifact): array
    {
        $sourceOperation = $artifact['source_operation'] ?? null;
        $inputKeys = is_array($sourceOperation) ? ($sourceOperation['input_keys'] ?? null) : null;
        if (is_array($sourceOperation) && 'blocks-engine/php-transformer/source-operation/v1' === ($sourceOperation['schema'] ?? null) && is_array($inputKeys) && array_is_list($inputKeys)) {
            foreach ($inputKeys as $key) {
                if (!is_string($key) || '' === $key) {
                    return array_values(array_filter(array_keys($artifact), 'is_string'));
                }
            }
            return $inputKeys;
        }
        return array_values(array_filter(array_keys($artifact), 'is_string'));
    }

    /**
     * Promote provider-materializable findings into the canonical runtime contract.
     *
     * Explicit caller declarations remain authoritative. Detected entities fill
     * only missing product/form collections and their matching dependencies.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $fallbacks
     * @return array<int,array<string,mixed>>
     */
    private function runtimeDeclarationsFromFallbacks(array $declarations, array $fallbacks, string $entryPath, array $files): array
    {
        if ( '' === $entryPath ) return $declarations;
        foreach ( $declarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) if ( is_array($entity) && array_key_exists('superseded_scripts', $entity) ) throw new \InvalidArgumentException('Caller runtime declarations cannot provide compiler-reserved script supersession proofs.');

        $keys = array();
        foreach ( $declarations as $declaration ) {
            if ( ! is_array($declaration) ) continue;
            $name = $declaration['type'] ?? $declaration['capability'] ?? null;
            if ( is_string($declaration['kind'] ?? null) && is_string($name) ) $keys[$declaration['kind'] . ':' . $name] = true;
        }

        $slug = static function (string $value): string {
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
            return trim($value, '-');
        };
        $price = static function (mixed $value): string {
            if ( ! is_scalar($value) ) return '';
            $clean = preg_replace('/[^0-9.,]/', '', trim((string) $value)) ?? '';
            if ( '' === $clean ) return '';
            $commaCount = substr_count($clean, ',');
            $dotCount = substr_count($clean, '.');
            $decimal = '';
            if ( 0 < $commaCount && 0 < $dotCount ) $decimal = strrpos($clean, ',') > strrpos($clean, '.') ? ',' : '.';
            elseif ( 1 === $commaCount ) { $tail = strlen(substr($clean, (int) strrpos($clean, ',') + 1)); if ( 1 <= $tail && 2 >= $tail ) $decimal = ','; }
            elseif ( 1 === $dotCount ) { $tail = strlen(substr($clean, (int) strrpos($clean, '.') + 1)); if ( 1 <= $tail && 2 >= $tail ) $decimal = '.'; }
            if ( '' === $decimal ) return ltrim(preg_replace('/[^0-9]/', '', $clean) ?? '', '0') ?: '0';
            $parts = explode($decimal, $clean); $fraction = preg_replace('/[^0-9]/', '', (string) array_pop($parts)) ?? ''; $integer = ltrim(preg_replace('/[^0-9]/', '', implode('', $parts)) ?? '', '0') ?: '0';
            return strlen($fraction) > 2 ? number_format((float) ($integer . '.' . $fraction), 2, '.', '') : $integer . '.' . str_pad($fraction, 2, '0');
        };

        $products = array();
        $forms = array();
        foreach ( $fallbacks as $fallback ) {
            if ( ! is_array($fallback) ) continue;
            $code = (string) ($fallback['diagnostic_code'] ?? $fallback['kind'] ?? '');
            $sourcePath = is_string($fallback['source'] ?? null) ? $fallback['source'] : $entryPath;
            if ( 'html_product_grid_fallback' === $code ) {
                $container = is_string($fallback['container_selector'] ?? null) ? $fallback['container_selector'] : (is_string($fallback['selector'] ?? null) ? $fallback['selector'] : '');
                foreach ( is_array($fallback['products'] ?? null) ? $fallback['products'] : array() as $product ) {
                    if ( ! is_array($product) ) continue;
                    $name = is_scalar($product['name'] ?? null) ? trim((string) $product['name']) : '';
                    $productSlug = $slug(is_scalar($product['slug'] ?? null) ? (string) $product['slug'] : $name);
                    $regularPrice = $price($product['price'] ?? null);
                    if ( '' === $name || '' === $productSlug || '' === $regularPrice ) continue;
                    $row = array('name' => $name, 'slug' => $productSlug, 'regular_price' => $regularPrice);
                    $salePrice = $price($product['sale_price'] ?? null); if ( '' !== $salePrice ) $row['sale_price'] = $salePrice;
                    if ( is_scalar($product['description'] ?? null) && '' !== trim((string) $product['description']) ) $row['description'] = (string) $product['description'];
                    $image = is_string($product['image'] ?? null) ? $product['image'] : (is_array($product['image'] ?? null) && is_string($product['image']['src'] ?? null) ? $product['image']['src'] : '');
                    if ( '' !== trim($image) ) $row['image'] = $image;
                    $selectors = array_values(array_unique(array_filter(array($product['source_selector'] ?? '', $container), static fn(mixed $selector): bool => is_string($selector) && '' !== trim($selector))));
                    if ( array() !== $selectors ) $row['source_selectors'] = $selectors;
                    if ( is_array($product['binding'] ?? null) && 'generic/block-binding/v1' === ($product['binding']['schema'] ?? null) && is_string($product['binding']['search_block_markup'] ?? null) && '' !== trim($product['binding']['search_block_markup']) ) {
                        $row['bindings'] = array(array_merge($product['binding'], array('source_path' => $sourcePath)));
                    }
                    if ( ! isset($row['bindings']) ) continue;
                    if ( isset($products[$productSlug]) ) {
                        $binding = $row['bindings'][0];
                        $claim = $binding['source_path'] . "\n" . hash('sha256', $binding['search_block_markup']) . "\n" . $binding['occurrence'];
                        $existingClaims = array_map(static fn(array $existing): string => $existing['source_path'] . "\n" . hash('sha256', $existing['search_block_markup']) . "\n" . $existing['occurrence'], $products[$productSlug]['bindings']);
                        if (!in_array($claim, $existingClaims, true)) $products[$productSlug]['bindings'][] = $binding;
                        continue;
                    }
                    $products[$productSlug] = $row;
                }
            } elseif ( 'html_form_fallback' === $code && is_array($fallback['controls'] ?? null) ) {
                if ( true === ($fallback['control_topology']['truncated'] ?? false) ) continue;
                $selector = is_string($fallback['selector'] ?? null) ? $fallback['selector'] : '';
                $form = array('selector' => $selector, 'source_path' => $sourcePath, 'form' => is_array($fallback['form'] ?? null) ? $fallback['form'] : array(), 'controls' => array_values(array_filter($fallback['controls'], 'is_array')));
                if ( is_array($fallback['control_topology'] ?? null) ) $form['control_topology'] = $fallback['control_topology'];
                if ( is_array($fallback['layout_graph'] ?? null) && true !== ($fallback['layout_graph']['truncated'] ?? false) ) { FormLayoutGraphBuilder::assertValid($fallback['layout_graph']); $form['layout_graph'] = $fallback['layout_graph']; }
                if ( is_array($fallback['binding'] ?? null) && 'generic/block-binding/v1' === ($fallback['binding']['schema'] ?? null) && is_string($fallback['binding']['search_block_markup'] ?? null) && '' !== trim($fallback['binding']['search_block_markup']) ) {
                    $form['bindings'] = array(array_merge($fallback['binding'], array('source_path' => $sourcePath)));
                }
                if ( ! isset($form['bindings']) ) continue;
                $supersededScripts = $this->supersededFormScripts($fallback, $files, $sourcePath);
                if ( array() !== $supersededScripts ) $form['superseded_scripts'] = $supersededScripts;
                $forms[$sourcePath . "\n" . $selector] = $form;
            }
        }
        ksort($products, SORT_STRING); ksort($forms, SORT_STRING);
        $collections = array(
            'shop' => array('type' => 'products', 'aliases' => array('product', 'products'), 'entities' => array_values($products), 'schema' => 'generic/products/v1'),
            'form' => array('type' => 'forms', 'aliases' => array('form', 'forms'), 'entities' => array_values($forms), 'schema' => 'generic/forms/v1'),
        );
        foreach ( $collections as $capability => $collection ) {
            $entityKey = 'entity_collection:' . $collection['type'];
            foreach ( $collection['aliases'] as $alias ) if ( isset($keys['entity_collection:' . $alias]) ) { $entityKey = 'entity_collection:' . $alias; break; }
            if ( array() !== $collection['entities'] && ! isset($keys[$entityKey]) ) {
                $declarations[] = array('kind' => 'entity_collection', 'type' => $collection['type'], 'source_path' => $entryPath, 'payload' => array('schema' => $collection['schema'], 'entities' => $collection['entities']));
                $keys[$entityKey] = true;
            }
            $dependencyKey = 'dependency:' . $capability;
            if ( isset($keys[$entityKey]) && ! isset($keys[$dependencyKey]) ) {
                $declarations[] = array('kind' => 'dependency', 'capability' => $capability, 'source_path' => $entryPath, 'required_for' => array($entityKey));
                $keys[$dependencyKey] = true;
            }
        }
        return RuntimeDeclarations::normalizeList($declarations);
    }

    /** @param array<string,mixed> $fallback @param array<int,array<string,mixed>> $files @return array<int,array<string,string>> */
    private function supersededFormScripts(array $fallback, array $files, string $sourcePath): array
    {
        $ownedIds = array();
        foreach ( array_merge(array($fallback['form'] ?? array()), is_array($fallback['controls'] ?? null) ? $fallback['controls'] : array()) as $row ) {
            if ( is_array($row) && is_string($row['id'] ?? null) && '' !== trim($row['id']) ) $ownedIds[$row['id']] = true;
        }
        if ( is_string($fallback['html'] ?? null) && preg_match_all('/\bid\s*=\s*["\']([^"\']+)["\']/i', $fallback['html'], $matches) ) foreach ( $matches[1] as $id ) $ownedIds[(string) $id] = true;
        if ( array() === $ownedIds ) return array();
        $formId = is_array($fallback['form'] ?? null) && is_string($fallback['form']['id'] ?? null) ? trim($fallback['form']['id']) : '';
        if ( '' === $formId ) return array();
        $targetSelector = '#' . $formId;

        $superseded = array();
        foreach ( $files as $file ) {
            if ( !is_array($file) || 'inline-script' !== ($file['source'] ?? null) || $sourcePath !== ($file['source_path'] ?? null) || $targetSelector !== ($file['superseded_by'] ?? null) || !is_string($file['selector'] ?? null) || !is_string($file['content'] ?? null) ) continue;
            $script = trim($file['content']);
            if ( '' === $script || preg_match('/\b(?:window|globalThis|fetch|XMLHttpRequest|WebSocket|EventSource|navigator|localStorage|sessionStorage|indexedDB|eval|import|createElement|appendChild|insertBefore)\b|document\s*\.\s*(?:cookie|location)/i', $script) ) continue;
            if ( !preg_match_all('/document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $lookups) || array() !== array_diff(array_unique($lookups[1]), array_keys($ownedIds)) ) continue;
            $ownedVariables = array();
            if ( preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*["\']([^"\']+)["\']\s*\)/i', $script, $assignments, PREG_SET_ORDER) ) foreach ( $assignments as $assignment ) if ( isset($ownedIds[$assignment[2]]) ) $ownedVariables[$assignment[1]] = true;
            $withoutOwnedLookups = preg_replace('/document\s*\.\s*getElementById\s*\(\s*["\'][^"\']+["\']\s*\)/i', '', $script) ?? $script;
            if ( preg_match('/\[\s*["\'][A-Za-z_$][A-Za-z0-9_$]*["\']\s*\]/', $withoutOwnedLookups) ) continue;
            if ( preg_match('/\bdocument\s*\./i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/(?<![.\w$])([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $calls) && array_diff(array_unique($calls[1]), array('if', 'for', 'while', 'switch', 'catch', 'function')) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', $withoutOwnedLookups, $memberCalls) && array_diff(array_unique($memberCalls[1]), array('addEventListener', 'preventDefault', 'querySelector', 'querySelectorAll', 'trim', 'forEach')) ) continue;
            if ( preg_match('/\.\s*(?:parentElement|parentNode|ownerDocument|children|firstElementChild|lastElementChild|nextElementSibling|previousElementSibling)\b/i', $withoutOwnedLookups) ) continue;
            if ( preg_match_all('/([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*)\s*\.\s*querySelector(?:All)?\s*\(/', $withoutOwnedLookups, $queries) && array_diff(array_map(static fn(string $receiver): string => preg_replace('/\s+/', '', $receiver) ?? $receiver, array_unique($queries[1])), array_keys($ownedVariables)) ) continue;
            if ( preg_match_all('/\.\s*([A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)?)\s*=/', $withoutOwnedLookups, $writes) && array_diff(array_map(static fn(string $property): string => preg_replace('/\s+/', '', $property) ?? $property, array_unique($writes[1])), array('textContent', 'style.background', 'style.borderColor', 'style.display', 'value')) ) continue;
            $superseded[] = array('schema' => 'blocks-engine/provider-script-supersession/v1', 'source_path' => $sourcePath, 'selector' => $file['selector'], 'asset_source_path' => $file['path'], 'body_hash' => hash('sha256', $script), 'target_selector' => $targetSelector, 'reason' => 'provider_binding_replaces_form_behavior');
        }
        usort($superseded, static fn(array $left, array $right): int => strcmp($left['body_hash'], $right['body_hash']));
        return $superseded;
    }

    /**
     * Compile a standalone source fragment through the canonical format bridge.
     *
     * @param array<string,mixed> $options Transformer context/provenance options.
     */
    public function compileFragment(string $content, string $source = 'fragment', string $format = 'html', array $options = array()): TransformerResult
    {
        $bridge = new FormatBridge();
        return $bridge->convertResult($content, $format, 'blocks', array_merge(array(
            'source'       => $source,
            'source_scope' => 'artifact-fragment',
        ), $options));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>, runtime_islands: array<int, array<string, mixed>>, generated_blocks: array<int, array<string, mixed>>, gutenberg_gaps: array<int, array<string, mixed>>, interaction_candidates: array<int, array<string, mixed>>, superseded_selectors: array<int, string>, author_stylesheet_projections: array<int, array<string, mixed>>, shell_artifacts: array<int, array<string, mixed>>, core_html_fallback_evidence: array<string, mixed>}
     */
    private function compileEntryBlocks(string $html, string $entryPath, array $files, string $generatedBlockNamespace = ''): array
    {
        $result = $this->compileHtmlDocumentBlocks($html, $entryPath, $files, 'artifact-entry', $generatedBlockNamespace, true);

        return array(
            'blocks'            => $result['blocks'],
            'serialized_blocks' => $result['serialized_blocks'],
            'diagnostics'       => $result['diagnostics'],
            'fallbacks'         => $result['fallbacks'],
            'assets'            => $result['assets'],
            'runtime_islands'   => $result['runtime_islands'],
            'generated_blocks'  => $result['generated_blocks'],
            'gutenberg_gaps'    => $result['gutenberg_gaps'],
            'interaction_candidates' => $result['interaction_candidates'],
            'superseded_selectors' => $result['superseded_selectors'],
            'author_stylesheet_projections' => $result['author_stylesheet_projections'],
            'shell_artifacts' => $result['shell_artifacts'],
            'core_html_fallback_evidence' => $result['core_html_fallback_evidence'],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{blocks: array<int, array<string, mixed>>, serialized_blocks: string, diagnostics: array<int, array<string, mixed>>, fallbacks: array<int, array<string, mixed>>, assets: array<int, array<string, mixed>>, runtime_islands: array<int, array<string, mixed>>, generated_blocks: array<int, array<string, mixed>>, gutenberg_gaps: array<int, array<string, mixed>>, interaction_candidates: array<int, array<string, mixed>>, superseded_selectors: array<int, string>, author_stylesheet_projections: array<int, array<string, mixed>>, shell_artifacts: array<int, array<string, mixed>>, core_html_fallback_evidence: array<string, mixed>}
     */
    private function compileHtmlDocumentBlocks(string $html, string $sourcePath, array $files, string $sourceScope, string $generatedBlockNamespace = '', bool $extractGlobalShell = false): array
    {
        if ( $this->containsBlockMarkup($html) ) {
            return array(
                'blocks'            => array(),
                'serialized_blocks' => $html,
                'diagnostics'       => array(),
                'fallbacks'         => array(),
                'assets'            => array(),
                'runtime_islands'   => array(),
                'generated_blocks'  => array(),
                'gutenberg_gaps'    => array(),
                'interaction_candidates' => array(),
                'superseded_selectors' => array(),
                'author_stylesheet_projections' => array(),
                'shell_artifacts' => array(),
                'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::fromBlocks(array(), array(), array()),
            );
        }

        if ( '' === trim($html) ) {
            return array(
                'blocks'            => array(),
                'serialized_blocks' => '',
                'diagnostics'       => array(),
                'fallbacks'         => array(),
                'assets'            => array(),
                'runtime_islands'   => array(),
                'generated_blocks'  => array(),
                'gutenberg_gaps'    => array(),
                'interaction_candidates' => array(),
                'superseded_selectors' => array(),
                'author_stylesheet_projections' => array(),
                'shell_artifacts' => array(),
                'core_html_fallback_evidence' => CoreHtmlFallbackEvidence::fromBlocks(array(), array(), array()),
            );
        }

        $result = (new HtmlTransformer(analysisCache: $this->htmlTransformerAnalysisCache ??= new HtmlTransformerAnalysisCache()))->transform($this->safeHtmlDocumentHtml($html, $sourcePath, $files), array(
            'source'                    => $sourcePath,
            'source_scope'              => $sourceScope,
            'declarative_state_html'    => $html,
            'static_css'                => $this->linkedStylesheetCss($html, $sourcePath, $files),
            'author_stylesheet_assets'  => $this->stylesheetAssetsForSource($html, $sourcePath, $files),
            'skip_author_stylesheet_materialization' => true,
            'asset_metadata'            => $this->assetMetadataForSource($sourcePath, $files),
            'runtime_script_metadata'   => $this->runtimeScriptMetadataForSource($html, $sourcePath, $files),
            'runtime_dom_selectors'     => $this->runtimeDomSelectors($html, $sourcePath, $files),
            'runtime_canvas_selectors'  => $this->runtimeCanvasSelectors($html, $sourcePath, $files),
            'generated_block_namespace' => $generatedBlockNamespace,
            'extract_global_shell'       => $extractGlobalShell,
        ))->toArray();

        return array(
            'blocks'            => is_array($result['blocks'] ?? null) ? $result['blocks'] : array(),
            'serialized_blocks' => (string) ($result['serialized_blocks'] ?? ''),
            'diagnostics'       => is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
            'fallbacks'         => is_array($result['fallbacks'] ?? null) ? $result['fallbacks'] : array(),
            'core_html_fallback_evidence' => is_array($result['source_reports']['html']['core_html_fallback_evidence'] ?? null) ? $result['source_reports']['html']['core_html_fallback_evidence'] : CoreHtmlFallbackEvidence::fromBlocks(array(), array(), array()),
            'assets'            => is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            'runtime_islands'   => $this->runtimeIslandsWithMaterializedInlineScripts(
                is_array($result['source_reports']['runtime_islands'] ?? null) ? $result['source_reports']['runtime_islands'] : array(),
                $sourcePath,
                $files
            ),
            'generated_blocks'  => is_array($result['source_reports']['generated_blocks'] ?? null) ? $result['source_reports']['generated_blocks'] : array(),
            'gutenberg_gaps'    => is_array($result['source_reports']['gutenberg_gaps'] ?? null) ? $result['source_reports']['gutenberg_gaps'] : array(),
            'interaction_candidates' => is_array($result['source_reports']['interaction_candidates'] ?? null) ? $result['source_reports']['interaction_candidates'] : array(),
            'superseded_selectors' => array_values(array_filter(
                is_array($result['source_reports']['superseded_selectors'] ?? null) ? $result['source_reports']['superseded_selectors'] : array(),
                static fn (mixed $selector): bool => is_string($selector) && '' !== $selector
            )),
            'author_stylesheet_projections' => is_array($result['source_reports']['author_stylesheet_projections'] ?? null) ? $result['source_reports']['author_stylesheet_projections'] : array(),
            'shell_artifacts' => is_array($result['source_reports']['shell_artifacts'] ?? null) ? $result['source_reports']['shell_artifacts'] : array(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function runtimeIslandsWithMaterializedInlineScripts(array $runtimeIslands, string $sourcePath, array $files): array
    {
        $inlineScripts = array_values(array_filter($files, fn (mixed $file): bool => is_array($file) && 'inline-script' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') && $this->isMaterializedScriptAsset($file)));
        foreach ( $runtimeIslands as &$runtimeIsland ) {
            if ( ! is_array($runtimeIsland) || 'script' === ($runtimeIsland['kind'] ?? '') ) {
                continue;
            }
            $requiredScripts = is_array($runtimeIsland['required_scripts'] ?? null) ? $runtimeIsland['required_scripts'] : array();
            foreach ( $inlineScripts as $file ) {
                $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
                if ( '' === $content || ! $this->inlineScriptReferencesRuntimeIsland($content, $runtimeIsland) ) {
                    continue;
                }
                $requiredScripts[] = array_filter(array(
                    'script_source_kind' => 'inline',
                    'script_role'        => 'first_party',
                    'selector'           => is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '',
                    'script_body'        => $content,
                    'body_bytes'         => strlen($content),
                    'body_truncated'     => false,
                    'attributes'         => $this->inlineScriptAttributes($file),
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
            }
            $runtimeIsland['required_scripts'] = $this->dedupeArrayRows($requiredScripts);
        }
        unset($runtimeIsland);

        foreach ( $files as $file ) {
            if ( ! is_array($file) || 'inline-script' !== ($file['source'] ?? '') || $sourcePath !== ($file['source_path'] ?? '') || ! $this->isMaterializedScriptAsset($file) ) {
                continue;
            }

            $selector = is_scalar($file['selector'] ?? null) ? (string) $file['selector'] : '';
            $content = is_scalar($file['content'] ?? null) ? trim((string) $file['content']) : '';
            if ( '' === $selector || '' === $content || $this->hasRuntimeIsland($runtimeIslands, 'script', $selector) ) {
                continue;
            }

            $attributes = $this->inlineScriptAttributes($file);

            $attributeHtml = '';
            foreach ( $attributes as $name => $value ) {
                if ( $name === $value ) {
                    $attributeHtml .= ' ' . $name;
                    continue;
                }
                $attributeHtml .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            $runtimeIslands[] = array_filter(array(
                'kind'                => 'script',
                'selector'            => $selector,
                'tag'                 => 'script',
                'diagnostic_code'     => 'preserved_runtime_island',
                'preservation_reason' => 'script_requires_runtime',
                'runtime_requirement' => 'client_script_execution',
                'disposition'         => 'preserve',
                'preservation_status' => 'accepted_runtime_preservation',
                'js_handling'         => 'preserve_verbatim',
                'source_snippet'      => '<script' . $attributeHtml . '></script>',
                'source_bytes'        => strlen($content),
                'source_truncated'    => false,
                'attributes'          => $attributes,
                'script_role'         => 'first_party',
                'script_source_kind'  => 'inline',
                'script_body'         => $content,
                'body_bytes'          => strlen($content),
                'body_truncated'      => false,
                'required_assets'     => array(),
                'required_scripts'    => array(),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
        }

        return $runtimeIslands;
    }

    /**
     * @param array<int, array<string, mixed>> $runtimeIslands
     */
    private function hasRuntimeIsland(array $runtimeIslands, string $kind, string $selector): bool
    {
        foreach ( $runtimeIslands as $island ) {
            if ( is_array($island) && $kind === ($island['kind'] ?? '') && $selector === ($island['selector'] ?? '') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $runtimeIsland
     */
    private function inlineScriptReferencesRuntimeIsland(string $content, array $runtimeIsland): bool
    {
        $attributes = is_array($runtimeIsland['attributes'] ?? null) ? $runtimeIsland['attributes'] : array();
        $id = is_scalar($attributes['id'] ?? null) ? trim((string) $attributes['id']) : '';
        if ( '' !== $id && str_contains($content, $id) ) {
            return true;
        }

        $classes = preg_split('/\s+/', is_scalar($attributes['class'] ?? null) ? trim((string) $attributes['class']) : '') ?: array();
        foreach ( $classes as $class ) {
            if ( '' !== $class && str_contains($content, $class) ) {
                return true;
            }
        }

        $selector = is_scalar($runtimeIsland['selector'] ?? null) ? trim((string) $runtimeIsland['selector']) : '';
        return '' !== $selector && str_contains($content, $selector);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, string>
     */
    private function inlineScriptAttributes(array $file): array
    {
        $attributes = array();
        if ( isset($file['type']) && is_scalar($file['type']) && '' !== trim((string) $file['type']) ) {
            $attributes['type'] = (string) $file['type'];
        }
        foreach ( array('defer', 'async') as $field ) {
            if ( ! empty($file[$field]) ) {
                $attributes[$field] = $field;
            }
        }

        return $attributes;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    private function dedupeArrayRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function safeHtmlDocumentHtml(string $html, string $entryPath, array $files): string
    {
        $html = $this->withoutMaterializedScriptTags($html, $entryPath, $files);
        $html = $this->withoutMaterializedStyleTags($html, $entryPath, $files);
        $html = $this->withoutGlobalTemplatePartShell($html, $files);
        $html = preg_replace_callback('/<img\s+[^>]*src\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', function (array $matches) use ($entryPath, $files): string {
            $asset = $this->findAssetByHtmlReference((string) $matches[2], $entryPath, $files);
            if ( is_array($asset) && 'image/svg+xml' === ($asset['mime_type'] ?? '') && ! $this->isSafeImageAsset($asset) ) {
                return '';
            }

            return (string) $matches[0];
        }, $html) ?? $html;

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutGlobalTemplatePartShell(string $html, array $files): string
    {
        $areas = $this->templatePartAreas($files);
        if ( ! in_array('footer', $areas, true) ) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return $html;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        $removed = false;
        $candidates = array();
        foreach ( $body->getElementsByTagName('*') as $element ) {
            if ( $element instanceof DOMElement && $this->isGlobalFooterShellElement($element) ) {
                $candidates[] = $element;
            }
        }

        foreach ( $candidates as $element ) {
            if ( null !== $element->parentNode ) {
                $element->parentNode->removeChild($element);
                $removed = true;
            }
        }

        if ( ! $removed ) {
            return $html;
        }

        $result = '';
        foreach ( $body->childNodes as $child ) {
            $result .= $document->saveHTML($child) ?: '';
        }

        return $result;
    }

    private function isGlobalFooterShellElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( 'footer' !== $tagName && 'contentinfo' !== strtolower((string) $element->getAttribute('role')) ) {
            return false;
        }

        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            $ancestorTag = strtolower($ancestor->tagName);
            if ( in_array($ancestorTag, array( 'main', 'article', 'blockquote', 'figure' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function templatePartAreas(array $files): array
    {
        $areas = array();
        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! $this->isTemplatePartFile($file) ) {
                continue;
            }

            $areas[] = $this->templatePartArea((string) ($file['path'] ?? ''), (string) ($file['role'] ?? ''));
        }

        return array_values(array_unique($areas));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedScriptTags(string $html, string $entryPath, array $files): string
    {
        $scriptIndex = 0;
        return preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function (array $matches) use ($entryPath, $files, &$scriptIndex): string {
            ++$scriptIndex;
            $src = $this->htmlAttribute((string) $matches[1], 'src');
            if ( '' !== $src ) {
                $asset = $this->findAssetByHtmlReference($src, $entryPath, $files);
                if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) ) {
                    return (string) $matches[0];
                }

                return '';
            }

            $asset = $this->findInlineScriptAsset($entryPath, $scriptIndex, $files);
            if ( ! is_array($asset) ) {
                return (string) $matches[0];
            }

            return '';
        }, $html) ?? $html;
    }

    /**
     * Inline CSS is materialized as a stylesheet asset during normalization.
     * Remove only styles backed by that asset before block conversion so CSS is
     * not also classified as unsupported body content.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function withoutMaterializedStyleTags(string $html, string $entryPath, array $files): string
    {
        $styleIndex = 0;
        return preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function (array $matches) use ($entryPath, $files, &$styleIndex): string {
            $attributes = (string) $matches[1];
            if ( ! $this->isCssStylesheetType($this->htmlAttribute($attributes, 'type')) || '' === trim((string) $matches[2]) ) {
                return (string) $matches[0];
            }

            ++$styleIndex;
            foreach ( $files as $file ) {
                if ( 'inline-style' === ($file['source'] ?? '') && $entryPath === ($file['source_path'] ?? '') && $styleIndex === (int) ($file['stylesheet_index'] ?? 0) && 'css' === ($file['kind'] ?? '') ) {
                    return '';
                }
            }

            return (string) $matches[0];
        }, $html) ?? $html;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isMaterializedScriptAsset(array $asset): bool
    {
        return in_array($asset['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($asset['role'] ?? '')
            || in_array($asset['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findInlineScriptAsset(string $entryPath, int $scriptIndex, array $files): ?array
    {
        $selector = 'script:nth-of-type(' . $scriptIndex . ')';
        foreach ( $files as $file ) {
            if ( 'inline-script' !== ($file['source'] ?? '') || $entryPath !== ($file['source_path'] ?? '') || $selector !== ($file['selector'] ?? '') || ! $this->isMaterializedScriptAsset($file) ) {
                continue;
            }

            return $file;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function linkedStylesheetCss(string $html, string $sourcePath, array $files): string
    {
        $css = array();
        foreach ( $this->stylesheetAssetsForSource($html, $sourcePath, $files) as $stylesheet ) {
            $content = (string) ($stylesheet['content'] ?? '');
            if ( '' !== trim($content) ) {
                $css[] = $this->artifactRelativeStylesheetContent($content, (string) ($stylesheet['source_path'] ?? $sourcePath), $files);
            }
        }

        return trim(implode("\n", $css));
    }

    /**
     * Preserve authored stylesheet boundaries and document order for selector
     * projection. Inline CSS is normalized as its own asset by ArtifactNormalizer.
     *
     * @param array<int, array<string, mixed>> $files
     * @return list<array{path: string, content: string, source_hash: string}>
     */
    private function stylesheetAssetsForSource(string $html, string $sourcePath, array $files): array
    {
        $byPath = array();
        $inline = array();
        $occurrencePaths = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['path'] ?? null) || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            $byPath[$file['path']] = $file;
            if ( 'inline-style' === ($file['source'] ?? '') && $sourcePath === ($file['source_path'] ?? '') ) {
                $inline[(int) ($file['stylesheet_index'] ?? 0)] = $file;
            }
            if ( is_string($file['stylesheet_source_path'] ?? null) && isset($file['stylesheet_occurrence']) ) {
                $occurrencePaths[$file['stylesheet_source_path']][(int) $file['stylesheet_occurrence']] = $file['path'];
            }
        }
        $assets = array();
        $seenPaths = array();
        $inlineIndex = 0;
        $linkOccurrences = array();
        if ( preg_match_all('/<style\b[^>]*>.*?<\/style>|<link\b[^>]*>/is', $html, $matches) ) {
            foreach ( $matches[0] as $tag ) {
                if ( preg_match('/^<style\b/i', $tag) ) {
                    $attributes = '';
                    preg_match('/^<style\b([^>]*)>/i', $tag, $styleMatch);
                    $attributes = (string) ($styleMatch[1] ?? '');
                    if ( ! $this->isCssStylesheetType($this->htmlAttribute($attributes, 'type')) ) {
                        continue;
                    }
                    if ( '' === trim((string) preg_replace('@^<style\b[^>]*>|</style>$@is', '', $tag)) ) {
                        continue;
                    }
                    ++$inlineIndex;
                    $file = $inline[$inlineIndex] ?? null;
                    if ( is_array($file) && ! isset($seenPaths[$file['path']]) ) {
                        $assets[] = array( 'path' => $file['path'], 'source_path' => $file['source_path'] ?? $file['path'], 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => (string) ($file['media'] ?? ''), 'type' => (string) ($file['type'] ?? '') );
                        $seenPaths[$file['path']] = true;
                    }
                    continue;
                }
                if ( ! preg_match('/^<link\b/i', $tag) || ! preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $this->htmlAttribute((string) $tag, 'rel') ) || ! $this->isCssStylesheetType($this->htmlAttribute((string) $tag, 'type')) ) {
                    continue;
                }
                $sourcePathForLink = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath, $files);
                $linkOccurrences[$sourcePathForLink] = ($linkOccurrences[$sourcePathForLink] ?? 0) + 1;
                $path = $occurrencePaths[$sourcePathForLink][$linkOccurrences[$sourcePathForLink]] ?? '';
                $file = $byPath[$path] ?? null;
                if ( is_array($file) && ! isset($seenPaths[$path]) ) {
                    $assets[] = array( 'path' => $path, 'source_path' => $file['stylesheet_source_path'] ?? $sourcePathForLink, 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content']) ), 'media' => $this->htmlAttribute((string) $tag, 'media'), 'type' => $this->htmlAttribute((string) $tag, 'type') );
                    $seenPaths[$path] = true;
                }
            }
        }
        return $assets;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function artifactRelativeStylesheetContent(string $content, string $stylesheetPath, array $files): string
    {
        $paths = array() !== $this->filesByPath ? $this->filesByPath : array_fill_keys(array_column($files, 'path'), true);
        return CssUrlRewriter::rewrite($content, static function (string $reference) use ($stylesheetPath, $paths): string {
            if ('' === $reference || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference)) return $reference;
            preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
            $path = str_starts_with($parts[1] ?? '', '/')
                ? ArtifactPath::safeRelativePath(ltrim((string) ($parts[1] ?? ''), '/'))
                : ArtifactPath::resolveRelativePath((string) ($parts[1] ?? ''), $stylesheetPath);
            return '' !== $path && isset($paths[$path]) ? $path . ($parts[2] ?? '') : $reference;
        });
    }

    /** @param array<int, array<string, mixed>> $files @return array<int, array<string, mixed>> */
    private function withStylesheetOccurrenceAssets(string $html, string $sourcePath, array $files): array
    {
        $byPath = array();
        $reserved = array();
        foreach ( $files as $index => $file ) {
            $path = (string) ($file['path'] ?? '');
            $byPath[$path] = $index;
            $reserved[$path] = true;
        }
        $occurrences = array();
        if ( ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return $files;
        }
        foreach ( $matches[0] as $tag ) {
            if ( ! preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $this->htmlAttribute((string) $tag, 'rel')) || ! $this->isCssStylesheetType($this->htmlAttribute((string) $tag, 'type')) ) {
                continue;
            }
            $originalPath = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath, $files);
            if ( '' === $originalPath || ! isset($byPath[$originalPath]) || 'css' !== ($files[$byPath[$originalPath]]['kind'] ?? '') ) {
                continue;
            }
            $occurrences[$originalPath] = ($occurrences[$originalPath] ?? 0) + 1;
            $occurrence = $occurrences[$originalPath];
            $media = $this->htmlAttribute((string) $tag, 'media');
            $type = $this->htmlAttribute((string) $tag, 'type');
            if ( 1 === $occurrence ) {
                $files[$byPath[$originalPath]]['media'] = $media;
                $files[$byPath[$originalPath]]['type'] = $type;
                $files[$byPath[$originalPath]]['stylesheet_source_path'] = $originalPath;
                $files[$byPath[$originalPath]]['stylesheet_occurrence'] = 1;
                continue;
            }
            $alias = $this->allocateStylesheetOccurrencePath($this->stylesheetOccurrencePath($originalPath, $occurrence), $reserved);
            $aliasFile = $files[$byPath[$originalPath]];
            $aliasFile['path'] = $alias;
            $aliasFile['source'] = 'stylesheet-occurrence';
            $aliasFile['source_path'] = $originalPath;
            $aliasFile['stylesheet_source_path'] = $originalPath;
            $aliasFile['stylesheet_occurrence'] = $occurrence;
            $aliasFile['media'] = $media;
            $aliasFile['type'] = $type;
            $aliasFile['provenance']['source_path'] = $originalPath;
            $files[] = $aliasFile;
            $byPath[$alias] = count($files) - 1;
        }
        return $files;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function stylesheetPathFromHref(string $href, string $sourcePath, array $files = array()): string
    {
        $href = (string) preg_replace('/[?#].*$/', '', $href);
        if ( ! str_starts_with($href, '/') ) {
            return ArtifactPath::resolveRelativePath($href, $sourcePath);
        }

        return $this->artifactRootRelativePath($href, $sourcePath, array_fill_keys(array_column($files, 'path'), true));
    }

    /** @param array<string, true> $paths */
    private function artifactRootRelativePath(string $reference, string $sourcePath, array $paths): string
    {
        $relative = ArtifactPath::safeRelativePath(ltrim($reference, '/'));
        if ( '' === $relative || isset($paths[$relative]) ) {
            return $relative;
        }

        $sourceSegments = explode('/', dirname($sourcePath));
        $matches = array();
        foreach ( array_keys($paths) as $path ) {
            if ( ! str_ends_with($path, '/' . $relative) ) {
                continue;
            }
            $candidateSegments = explode('/', dirname($path));
            $common = 0;
            while ( isset($sourceSegments[$common], $candidateSegments[$common]) && $sourceSegments[$common] === $candidateSegments[$common] ) {
                ++$common;
            }
            $matches[] = array( 'path' => $path, 'common' => $common );
        }
        usort($matches, static fn (array $left, array $right): int => $right['common'] <=> $left['common'] ?: strcmp($left['path'], $right['path']));

        return isset($matches[0]) && $matches[0]['common'] > 0 ? (string) $matches[0]['path'] : $relative;
    }

    private function stylesheetOccurrencePath(string $path, int $occurrence): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -strlen($extension) - 1);
        return $base . '.occurrence-' . $occurrence . ('' === $extension ? '' : '.' . $extension);
    }

    /** @param array<string, true> $reserved */
    private function allocateStylesheetOccurrencePath(string $candidate, array &$reserved): string
    {
        $path = $candidate;
        $index = 1;
        while ( isset($reserved[$path]) ) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $base = '' === $extension ? $candidate : substr($candidate, 0, -strlen($extension) - 1);
            $path = $base . '-generated-' . $index++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reserved[$path] = true;
        return $path;
    }

    private function isCssStylesheetType(string $type): bool
    {
        $type = strtolower(trim($type));
        return '' === $type || 1 === preg_match("/^text\\/css(?:\\s*;\\s*[!#$%&'*+\\-.^_`|~0-9a-z]+(?:\\s*=\\s*(?:[!#$%&'*+\\-.^_`|~0-9a-z]+|\"(?:[^\"\\\\]|\\\\.)*\"))?)*\\s*$/i", $type);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $projections
     * @param array<int, array<string, mixed>> $primaryProjections
     * @return array<int, array<string, mixed>>
     */
    private function applyAuthorStylesheetProjections(array $files, array $projections, array $primaryProjections = array()): array
    {
        $byPath = array();
        $primaryByPath = array();
        foreach ( $primaryProjections as $projection ) {
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) ) {
                $primaryByPath[$projection['path']][$projection['content']] = true;
            }
        }
        foreach ( $projections as $projection ) {
            if ( is_string($projection['path'] ?? null) && is_string($projection['content'] ?? null) ) {
                $path = $projection['path'];
                $byPath[$path] ??= array();
                $byPath[$path][$projection['content']] = true;
            }
        }
        foreach ( $files as &$file ) {
            $pathProjections = $byPath[$file['path'] ?? ''] ?? null;
            if ( ! is_array($pathProjections) || 'css' !== ($file['kind'] ?? '') ) {
                continue;
            }
            foreach ( array_keys($primaryByPath[$file['path'] ?? ''] ?? array()) as $primaryContent ) {
                unset($pathProjections[$primaryContent]);
            }
            $authoritativeContent = array_keys($primaryByPath[$file['path'] ?? ''] ?? array());
            if ( array() === $authoritativeContent ) {
                $authoritativeContent[] = (string) ($file['content'] ?? '');
            }
            $preambles = array();
            $stylesheets = array();
            foreach ( $authoritativeContent as $stylesheet ) {
                $split = ( new CssStylesheetTransformer() )->splitLeadingAtRulePreamble($stylesheet);
                if ( '' !== trim($split['preamble']) ) {
                    $preambles[] = $split['preamble'];
                }
                if ( '' !== trim($split['stylesheet']) ) {
                    $stylesheets[] = $split['stylesheet'];
                }
            }
            $content = implode("\n", array_merge($preambles, array_keys($pathProjections), $stylesheets));
            $file['content'] = $content;
            // Projection rewrites the CSS text, so any base64 twin from the
            // source payload is stale. Drop it and let the rewritten text be the
            // sole representation rather than shipping an inconsistent encoding.
            unset($file['content_base64']);
            $file['bytes'] = strlen($content);
            $file['encoding'] = 'text';
            $file['binary'] = false;
            $file['provenance']['projected_from_hash'] = $file['provenance']['hash'] ?? '';
            $file['provenance']['hash'] = hash('sha256', $content);
        }
        unset($file);
        return $files;
    }

    /**
     * Compile non-entry HTML documents once so their stylesheet projections are
     * available before theme assets are materialized.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function compileHtmlSourceDocuments(array $files, string $entryPath, string $generatedBlockNamespace = ''): array
    {
        $documents = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || $this->isTemplatePartFile($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' === $path || $entryPath === $path ) {
                continue;
            }
            $documents[$path] = $this->compileHtmlDocumentBlocks((string) ($file['content'] ?? ''), $path, $files, 'artifact-document', $generatedBlockNamespace, true);
        }
        return $documents;
    }

    /**
     * Collect the `<link>` tags declared across the artifact's HTML sources so
     * downstream font materialization can detect linked web-font stylesheets
     * (e.g. Google Fonts) without re-parsing every document. Deduplicated to
     * stay bounded for multi-page sites that repeat a shared `<head>`.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function themeFontLinkHtml(array $files): string
    {
        $tags = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            if ( ! preg_match_all('/<link\b[^>]*>/i', (string) $file['content'], $matches) ) {
                continue;
            }
            foreach ( $matches[0] as $tag ) {
                $tags[trim((string) $tag)] = true;
            }
        }

        return implode("\n", array_keys($tags));
    }

    /**
     * Aggregate the artifact's authored CSS (linked stylesheet files plus inline
     * `<style>` blocks) so downstream font materialization can read generic
     * `font-family` declarations. Deduplicated and order-preserving.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function themeStaticCss(array $files, bool $includeNavigationCompat = true): string
    {
		$cacheKey = $includeNavigationCompat ? 'with-compat' : 'without-compat';
		if ( array_key_exists($cacheKey, $this->themeStaticCssCache) ) {
			return $this->themeStaticCssCache[$cacheKey];
		}
		if ( $includeNavigationCompat ) {
			$css = $this->themeStaticCss($files, false);
			return $this->themeStaticCssCache[$cacheKey] = $css . $this->wordpressCompatCss($css, $files);
		}
        $blocks = array();
        foreach ( $files as $file ) {
            $content = is_string($file['content'] ?? null) ? (string) $file['content'] : '';
            if ( '' === trim($content) ) {
                continue;
            }

            if ( 'css' === ($file['kind'] ?? '') ) {
                $blocks[trim($content)] = true;
                continue;
            }

            if ( 'html' === ($file['kind'] ?? '') && preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $content, $matches) ) {
                foreach ( $matches[1] as $style ) {
                    $style = trim((string) $style);
                    if ( '' !== $style ) {
                        $blocks[$style] = true;
                    }
                }
            }
        }

        $css = implode("\n", array_keys($blocks));

		return $this->themeStaticCssCache[$cacheKey] = $css;
    }

    /** @return array<int,array{path:string,content:string,source_hash:string}> */
    private function themeFontCssSources(array $files): array
    {
        $sources = array();
        foreach ( $files as $file ) {
            if ( 'css' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) || strlen($file['content']) === strspn($file['content'], " \t\n\r\0\x0B") ) continue;
            $sources[] = array('path' => (string) ($file['path'] ?? 'css:input'), 'content' => $file['content'], 'source_hash' => (string) ($file['provenance']['hash'] ?? hash('sha256', $file['content'])));
        }
        return self::sortedByPath($sources);
    }

    private function navigationAnchorCompatCss(string $css): string
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css) as $rule ) {
            $body = $rule['body'];
            if ( '' === $body || str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                foreach ( $this->mapNavigationAnchorSelector($selector) as $mappedSelector ) {
                    $mappedSelectors[$mappedSelector] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: replay source nav anchor selectors against core/navigation wrapper markup */\n" . implode("\n", $rules);
    }

    private function navigationStructureCompatCss(string $css): string
    {
        $rules = $this->navigationStructureCompatRules($css);
        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: project source list navigation structure onto core/navigation markup */\n" . implode("\n", $rules);
    }

    /**
     * @return array<int, string>
     */
    private function navigationStructureCompatRules(string $css): array
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            $selectorList = trim($rule['selector']);
            $body = $rule['body'];
            if ( '' === $body ) {
                continue;
            }

            if ( str_starts_with($selectorList, '@') ) {
                if ( ! preg_match('/^@(media|supports|container|layer)\b/i', $selectorList) ) {
                    continue;
                }
                $nestedRules = $this->navigationStructureCompatRules($body);
                if ( array() !== $nestedRules ) {
                    $rules[] = $selectorList . ' {' . implode('', $nestedRules) . '}';
                }
                continue;
            }
            if ( str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($selectorList) as $selector ) {
                foreach ( $this->mapNavigationStructureSelector($selector, $body) as $mappedSelector ) {
                    $mappedSelectors[$mappedSelector] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        return $rules;
    }

    private function navigationContainerCompatCss(string $css): string
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css) as $rule ) {
            $body = $rule['body'];
            if ( '' === $body || str_contains(strtolower($body), 'url(') || ! preg_match('/(?:^|;)\s*display\s*:/i', $body) ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                $mapped = $this->mapNavigationContainerSelector($selector);
                if ( null !== $mapped ) {
                    $mappedSelectors[$mapped] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: preserve source navigation container cascade against core/navigation */\n" . implode("\n", $rules);
    }

    private function mapNavigationContainerSelector(string $selector): ?string
    {
        if ( str_contains($selector, '.wp-block-navigation') || ! preg_match('/([^\s>+~]+)\s*$/', trim($selector), $match, PREG_OFFSET_CAPTURE) ) {
            return null;
        }

        $compound = (string) ($match[1][0] ?? '');
        if ( ! preg_match('/(?:^|[.#_-])(?:nav|navbar|navigation|menu)(?:$|[.#_:-])/i', $compound)
            || ! preg_match('/(?:^|[.#_-])(?:collapsed|mobile|drawer|overlay|offcanvas|responsive)(?:$|[.#_:-])/i', $compound) ) {
            return null;
        }

        $pseudoOffset = false;
        if ( preg_match('/:{1,2}/', $compound, $pseudoMatch, PREG_OFFSET_CAPTURE) ) {
            $pseudoOffset = (int) $pseudoMatch[0][1];
        }
        $mappedCompound = false === $pseudoOffset
            ? $compound . '.wp-block-navigation'
            : substr($compound, 0, $pseudoOffset) . '.wp-block-navigation' . substr($compound, $pseudoOffset);

        return substr($selector, 0, (int) $match[1][1]) . $mappedCompound;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function rootStartupClassCompatCss(string $css, array $files): string
    {
        $classes = $this->rootStartupClassNames($files);
        if ( array() === $classes ) {
            return '';
        }

        $rules = array();
        foreach ( $this->topLevelCssRules($css) as $rule ) {
            $body = $rule['body'];
            if ( '' === $body || str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                foreach ( $classes as $class ) {
                    $mapped = preg_replace('/\b(body|html)\.' . preg_quote($class, '/') . '\b/', '$1', $selector, 1, $count);
                    if ( 1 === $count && is_string($mapped) ) {
                        $mappedSelectors[trim($mapped)] = true;
                    }
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: materialize stable source startup root classes */\n" . implode("\n", $rules);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function rootStartupClassNames(array $files): array
    {
        $added = array();
        $removed = array();
        foreach ( $this->allScriptContents($files) as $script ) {
            if ( preg_match_all('/\$\(\s*(["\'])(?:body|html)\1\s*\)\s*\.\s*addClass\s*\(\s*(["\'])([^"\']+)\2\s*\)/', $script, $matches) ) {
                foreach ( $matches[3] as $classList ) {
                    foreach ( preg_split('/\s+/', trim((string) $classList)) ?: array() as $class ) {
                        if ( preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $class) ) {
                            $added[$class] = true;
                        }
                    }
                }
            }
            if ( preg_match_all('/document\s*\.\s*(?:body|documentElement)\s*\.\s*classList\s*\.\s*add\s*\(\s*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
                foreach ( $matches[2] as $class ) {
                    $added[(string) $class] = true;
                }
            }
            if ( preg_match_all('/(?:removeClass|toggleClass|classList\s*\.\s*(?:remove|toggle))\s*\([^)]*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                foreach ( $matches[2] as $class ) {
                    $removed[(string) $class] = true;
                }
            }
        }

        return array_values(array_diff(array_keys($added), array_keys($removed)));
    }

    /** @param array<int, array<string, mixed>> $files */
    private function wordpressCompatCss(string $css, array $files): string
    {
		$cacheKey = hash('sha256', $css);
		if ( array_key_exists($cacheKey, $this->wordpressCompatCssCache) ) {
			return $this->wordpressCompatCssCache[$cacheKey];
		}
		return $this->wordpressCompatCssCache[$cacheKey] = $this->navigationContainerCompatCss($css)
            . $this->navigationStructureCompatCss($css)
            . $this->navigationAnchorCompatCss($css)
            . $this->rootStartupClassCompatCss($css, $files)
            . $this->coreRuntimeCompatCss($css, $files);
    }

    /** @param array<int, array<string, mixed>> $files */
    private function coreRuntimeCompatCss(string $css, array $files): string
    {
        $rules = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            if ( preg_match('/\baria-current\s*=|\b(?:id|class)\s*=\s*(?:"[^"]*(?:active|current|selected)[^"]*"|\'[^\']*(?:active|current|selected)[^\']*\'|[^\s>]*(?:active|current|selected)[^\s>]*)/i', $file['content']) ) {
                $rules['current-navigation'] = '.blocks-engine-current-navigation-underline>.wp-block-navigation-item__content { text-decoration:underline }';
                break;
            }
        }

        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            if ( str_starts_with(trim($rule['selector']), '@') ) {
                if ( '' !== $this->coreRuntimeCompatCss($rule['body'], array()) ) {
                    $rules['search-icon'] = '.wp-block-search.wp-block-search__icon-button .wp-block-search__button.has-icon>.search-icon { display:block!important;height:1.25em!important }';
                    break;
                }
                continue;
            }
            if ( str_contains($rule['selector'], '.search-icon')
                && ! str_contains($rule['selector'], '.wp-block-search')
                && preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $rule['body']) ) {
                $rules['search-icon'] = '.wp-block-search.wp-block-search__icon-button .wp-block-search__button.has-icon>.search-icon { display:block!important;height:1.25em!important }';
                break;
            }
        }

        return array() === $rules
            ? ''
            : "\n\n/* wp-compat: protect core block runtime semantics from source selector collisions */\n" . implode("\n", $rules);
    }

    /** @param array<int, array<string, mixed>> $files @return array<string, mixed>|null */
    private function wordpressCompatAsset(array $files): ?array
    {
        $css = trim($this->wordpressCompatCss($this->themeStaticCss($files, false), $files));
        if ( '' === $css ) {
            return null;
        }

        $hash = hash('sha256', $css);
        $path = 'assets/css/wordpress-compat-' . substr($hash, 0, 16) . '.css';
        return array(
            'source'      => 'wordpress-compat',
            'path'        => $path,
            'target_path' => $path,
            'kind'        => 'css',
            'role'        => 'stylesheet',
            'intent'      => 'style',
            'media_type'  => 'text/css',
            'mime_type'   => 'text/css',
            'bytes'       => strlen($css),
            'binary'      => false,
            'content'     => $css,
            'hash'        => $hash,
        );
    }

    /** @return array<int, array{selector:string,body:string}> */
    private function topLevelCssRules(string $css, bool $includeConditionalRules = false): array
    {
        $rules = array();
        $length = strlen($css);
        $start = 0;
        for ( $index = 0; $index < $length; $index++ ) {
            if ( '/' === $css[$index] && '*' === ($css[$index + 1] ?? '') ) {
                $end = strpos($css, '*/', $index + 2);
                $index = false === $end ? $length : $end + 1;
                continue;
            }
            if ( in_array($css[$index], array('"', "'"), true) ) {
                $quote = $css[$index];
                while ( ++$index < $length ) {
                    if ( '\\' === $css[$index] ) {
                        $index++;
                    } elseif ( $quote === $css[$index] ) {
                        break;
                    }
                }
                continue;
            }
            if ( ';' === $css[$index] ) {
                $start = $index + 1;
                continue;
            }
            if ( '{' !== $css[$index] ) {
                continue;
            }

            $selector = trim(substr($css, $start, $index - $start));
            $bodyStart = $index + 1;
            $depth = 1;
            while ( ++$index < $length && $depth > 0 ) {
                if ( '/' === $css[$index] && '*' === ($css[$index + 1] ?? '') ) {
                    $end = strpos($css, '*/', $index + 2);
                    $index = false === $end ? $length : $end + 1;
                    continue;
                }
                if ( in_array($css[$index], array('"', "'"), true) ) {
                    $quote = $css[$index];
                    while ( ++$index < $length ) {
                        if ( '\\' === $css[$index] ) {
                            $index++;
                        } elseif ( $quote === $css[$index] ) {
                            break;
                        }
                    }
                    continue;
                }
                if ( '{' === $css[$index] ) {
                    $depth++;
                } elseif ( '}' === $css[$index] ) {
                    $depth--;
                }
            }
            if ( '' !== $selector && ( $includeConditionalRules || ! str_starts_with($selector, '@') ) && 0 === $depth ) {
                $closingBrace = $index - 1;
                $rules[] = array(
                    'selector' => $selector,
                    'body'     => trim(substr($css, $bodyStart, $closingBrace - $bodyStart)),
                );
            }
            $start = $index;
            $index--;
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    private function splitSelectorList(string $selectorList): array
    {
        $selectors = array();
        $current = '';
        $depth = 0;
        $length = strlen($selectorList);
        for ( $i = 0; $i < $length; $i++ ) {
            $char = $selectorList[$i];
            if ( '\\' === $char && $i + 1 < $length ) {
                $current .= $char . $selectorList[++$i];
                continue;
            }
            if ( '/' === $char && '*' === ($selectorList[$i + 1] ?? '') ) {
                $end = strpos($selectorList, '*/', $i + 2);
                if ( false === $end ) {
                    $current .= substr($selectorList, $i);
                    break;
                }
                $current .= substr($selectorList, $i, $end + 2 - $i);
                $i = $end + 1;
                continue;
            }
            if ( in_array($char, array( '"', "'" ), true) ) {
                $quote = $char;
                $current .= $char;
                while ( ++$i < $length ) {
                    $current .= $selectorList[$i];
                    if ( '\\' === $selectorList[$i] && $i + 1 < $length ) {
                        $current .= $selectorList[++$i];
                        continue;
                    }
                    if ( $quote === $selectorList[$i] ) {
                        break;
                    }
                }
                continue;
            }
            if ( '(' === $char || '[' === $char ) {
                $depth++;
            } elseif ( ')' === $char || ']' === $char ) {
                $depth = max(0, $depth - 1);
            }

            if ( ',' === $char && 0 === $depth ) {
                $selector = trim($current);
                if ( '' !== $selector ) {
                    $selectors[] = $selector;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $selector = trim($current);
        if ( '' !== $selector ) {
            $selectors[] = $selector;
        }

        return $selectors;
    }

    /**
     * @return array<int, string>
     */
    private function mapNavigationAnchorSelector(string $selector): array
    {
        if ( ! preg_match('/(^|[\s>+~])a(?=$|[\s:.#\[])/', $selector, $anchorMatch, PREG_OFFSET_CAPTURE) ) {
            return array();
        }

        $separator = (string) ($anchorMatch[1][0] ?? '');
        $anchorStart = (int) $anchorMatch[0][1] + strlen($separator);
        $prefix = substr($selector, 0, $anchorStart);
        if ( 1 !== preg_match('/[.#\[]/', $prefix) ) {
            return array();
        }

        $mapped = preg_replace('/(\s*[>+~]?\s*)a:first-child\b/', '$1.wp-block-navigation-item:first-child > .wp-block-navigation-item__content', $selector);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a:last-child\b/', '$1.wp-block-navigation-item:last-child > .wp-block-navigation-item__content', (string) $mapped);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a:nth-child\(([^)]*)\)/', '$1.wp-block-navigation-item:nth-child($2) > .wp-block-navigation-item__content', (string) $mapped);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a(?![A-Za-z0-9_-])/', '$1.wp-block-navigation-item__content', (string) $mapped);
        $mapped = (string) $mapped;

        $selectors = array();
        $directWrapper = $this->addNavigationClassToLastPrefixCompound($mapped, $anchorStart);
        if ( null !== $directWrapper ) {
            $selectors[$directWrapper] = true;
        }
        $descendantWrapper = $this->insertNavigationDescendantWrapper($mapped, $prefix);
        if ( null !== $descendantWrapper ) {
            $selectors[$descendantWrapper] = true;
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function mapNavigationStructureSelector(string $selector, string $body): array
    {
        $selector = $this->selectorWithoutComments($selector);
        if ( str_contains($selector, '.wp-block-navigation') ) {
            return array();
        }

        $hasListMatch = preg_match('/(^|\s*[>+~]?\s*)(?:ul|ol)((?:[.#][A-Za-z_][A-Za-z0-9_-]*)+)(?=$|[\s>+~:])/', $selector, $listMatch, PREG_OFFSET_CAPTURE);
        if ( 1 !== $hasListMatch ) {
            $hasListMatch = preg_match('/(^|\s*[>+~]?\s*)((?:[.#][A-Za-z_][A-Za-z0-9_-]*)+)(?=\s+[^,{]*blocks-engine-source-li-)/', $selector, $listMatch, PREG_OFFSET_CAPTURE);
        }
        if ( 1 !== $hasListMatch ) {
            return array();
        }

        $listClasses = (string) ($listMatch[2][0] ?? '');
        if ( ! preg_match('/(?:nav|menu)/i', $listClasses) ) {
            return array();
        }

        $matchStart = (int) ($listMatch[0][1] ?? 0);
        $matchLength = strlen((string) ($listMatch[0][0] ?? ''));
        $prefix = rtrim(substr($selector, 0, $matchStart));
        $tail = substr($selector, $matchStart + $matchLength);
        if ( '' === trim($tail) ) {
            if ( ! preg_match('/(?:^|;)\s*(?:visibility\s*:\s*visible\b|opacity\s*:\s*1(?:\.0+)?\b|display\s*:\s*(?!none\b)[^;}]+)/i', $body)
                || ! preg_match('/\.(?:is-)?(?:visible|shown|open|opened|active|ready|loaded|expanded)\b/i', $listClasses) ) {
                return array();
            }
            $stableListClasses = preg_replace('/\.(?:is-)?(?:visible|shown|open|opened|active|ready|loaded|expanded)\b/i', '', $listClasses);
            if ( ! is_string($stableListClasses) || $stableListClasses === $listClasses || ! preg_match('/(?:nav|menu)/i', $stableListClasses) ) {
                return array();
            }
            $listClasses = $stableListClasses;
        }
        $tail = preg_replace(
            '/:where\(\.blocks-engine-source-li-[A-Za-z0-9_-]+\):not\(blocks-engine-specificity-[A-Za-z0-9_-]+\)/',
            '.wp-block-navigation-item',
            $tail
        ) ?? $tail;
        $tail = preg_replace('/(^|[\s>+~])li(?=$|[\s>+~:.#\[])/', '$1.wp-block-navigation-item', $tail) ?? $tail;
        $tail = preg_replace('/(^|[\s>+~])a(?=$|[\s>+~:.#\[])/', '$1.wp-block-navigation-item__content', $tail) ?? $tail;
        $runtimeTail = ' .wp-block-navigation__container' . $tail;
        $scope = $listClasses . '.wp-block-navigation';

        $selectors = array();
        if ( '' === $prefix ) {
            $selectors[$scope . $runtimeTail] = true;
            return array_keys($selectors);
        }

        $selectors[$prefix . ' ' . $scope . $runtimeTail] = true;
        if ( preg_match('/([^\s>+~]+)$/', $prefix, $prefixMatch, PREG_OFFSET_CAPTURE) ) {
            $compound = (string) ($prefixMatch[1][0] ?? '');
            $offset = (int) ($prefixMatch[1][1] ?? 0);
            $pseudoOffset = strpos($compound, ':');
            $fused = false === $pseudoOffset
                ? $compound . $listClasses . '.wp-block-navigation'
                : substr($compound, 0, $pseudoOffset) . $listClasses . '.wp-block-navigation' . substr($compound, $pseudoOffset);
            $selectors[substr($prefix, 0, $offset) . $fused . $runtimeTail] = true;
        }

        return array_keys($selectors);
    }

    private function selectorWithoutComments(string $selector): string
    {
        $result = '';
        $length = strlen($selector);
        for ( $index = 0; $index < $length; $index++ ) {
            $char = $selector[$index];
            if ( '\\' === $char && $index + 1 < $length ) {
                $result .= $char . $selector[++$index];
                continue;
            }
            if ( in_array($char, array( '"', "'" ), true) ) {
                $quote = $char;
                $result .= $char;
                while ( ++$index < $length ) {
                    $result .= $selector[$index];
                    if ( '\\' === $selector[$index] && $index + 1 < $length ) {
                        $result .= $selector[++$index];
                        continue;
                    }
                    if ( $quote === $selector[$index] ) {
                        break;
                    }
                }
                continue;
            }
            if ( '/' === $char && '*' === ($selector[$index + 1] ?? '') ) {
                $end = strpos($selector, '*/', $index + 2);
                if ( false === $end ) {
                    break;
                }
                $index = $end + 1;
                continue;
            }
            $result .= $char;
        }

        return trim($result);
    }

    private function addNavigationClassToLastPrefixCompound(string $selector, int $anchorStart): ?string
    {
        $prefix = substr($selector, 0, $anchorStart);
        if ( ! preg_match('/([^\s>+~]+)(\s*[>+~]?\s*)$/', $prefix, $match, PREG_OFFSET_CAPTURE) ) {
            return null;
        }

        $compound = (string) ($match[1][0] ?? '');
        if ( str_contains($compound, '.wp-block-navigation') ) {
            return $selector;
        }

        $pseudoOffset = false;
        if ( preg_match('/:{1,2}/', $compound, $pseudoMatch, PREG_OFFSET_CAPTURE) ) {
            $pseudoOffset = (int) $pseudoMatch[0][1];
        }

        $mappedCompound = false === $pseudoOffset
            ? $compound . '.wp-block-navigation'
            : substr($compound, 0, $pseudoOffset) . '.wp-block-navigation' . substr($compound, $pseudoOffset);
        $mappedPrefix = substr($prefix, 0, (int) $match[1][1]) . $mappedCompound . (string) ($match[2][0] ?? '');

        return $mappedPrefix . substr($selector, $anchorStart);
    }

    private function insertNavigationDescendantWrapper(string $selector, string $prefix): ?string
    {
        $parentPrefix = rtrim((string) preg_replace('/[\s>+~]+$/', '', $prefix));
        if ( '' === $parentPrefix || str_contains($parentPrefix, '.wp-block-navigation') ) {
            return null;
        }

        $tail = ltrim((string) preg_replace('/^[\s>+~]+/', '', substr($selector, strlen($prefix))));
        if ( '' === $tail ) {
            return null;
        }

        return $parentPrefix . ' .wp-block-navigation ' . $tail;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeDomSelectors(string $html, string $sourcePath, array $files): array
    {
        $selectors = array();
        $controlSelectors = $this->formControlSelectors($html);
        $statusFeedbackSelectors = $this->formStatusFeedbackSelectors($html);
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            $runtimeControlSelectors = $this->scriptControlRuntimeSelectors($script);
            foreach ( $this->scriptDomSelectors($script) as $selector ) {
                if ( $this->isPresentationOnlyScriptSelector($script, $selector) ) {
                    continue;
                }
                if ( isset($controlSelectors[$selector]) && ! isset($runtimeControlSelectors[$selector]) ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }

        foreach ( $this->allScriptContents($files) as $script ) {
            foreach ( $this->scriptDomSelectors($script) as $selector ) {
                if ( $this->isPresentationOnlyScriptSelector($script, $selector) ) {
                    continue;
                }
                if ( isset($statusFeedbackSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function formStatusFeedbackSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! $this->isFormStatusFeedbackElement($element) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class && ! $this->isBehaviorHookClassName($class) ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    private function isFormStatusFeedbackElement(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea', 'form', 'script', 'style'), true) ) {
            return false;
        }

        $tokens = strtolower(trim(implode(' ', array(
            $element->hasAttribute('id') ? $element->getAttribute('id') : '',
            $element->hasAttribute('class') ? $element->getAttribute('class') : '',
            $element->hasAttribute('role') ? $element->getAttribute('role') : '',
            $element->hasAttribute('aria-live') ? 'aria-live' : '',
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:form|contact|newsletter|signup|subscribe|submission|submit|message|status|feedback|alert|notice|response|success|error|warning|confirmation|thanks?)(?:[^a-z0-9]|$)/', $tokens)
            && (bool) preg_match('/(?:^|[^a-z0-9])(?:success|error|message|status|feedback|alert|notice|response|warning|confirmation|thanks?|aria-live)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function runtimeCanvasSelectors(string $html, string $sourcePath, array $files): array
    {
        $canvasSelectors = $this->canvasSelectors($html);
        if ( array() === $canvasSelectors ) {
            return array();
        }

        $selectors = array();
        $scripts = $this->documentScriptContents($html, $sourcePath, $files);
        foreach ( $scripts as $script ) {
            foreach ( $this->scriptCanvasSelectors($script) as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        $combinedScripts = implode("\n", $scripts);
        if ( 1 === preg_match('/\.\s*getContext\s*\(/', $combinedScripts) ) {
            foreach ( $this->scriptCanvasArgumentSelectors($combinedScripts) as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasArgumentSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                $variable = (string) $assignment[1];
                if ( ! preg_match('/(?:\bnew\s+)?\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*\b' . preg_quote($variable, '/') . '\b/', $script) ) {
                    continue;
                }
                $selectors['' !== (string) ($assignment[3] ?? '') ? '#' . (string) $assignment[3] : (string) $assignment[5]] = true;
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasSelectors(string $script): array
    {
        $selectors = array();
        $getContextPattern = '\.\s*getContext\s*\(';

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/document\s*\.\s*querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)\s*' . $getContextPattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors['#' . (string) $assignment[3]] = true;
                }
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $getContextPattern . '/', $script) ) {
                    $selectors[(string) $assignment[3]] = true;
                }
            }
        }

        foreach ( $this->scriptScopedElementSelectors($script, 'canvas', $getContextPattern) as $selector ) {
            $selectors[$selector] = true;
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function canvasSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('canvas') as $canvas ) {
            if ( ! $canvas instanceof DOMElement ) {
                continue;
            }
            $id = trim($canvas->hasAttribute('id') ? $canvas->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($canvas->hasAttribute('class') ? $canvas->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                    $selectors['canvas.' . $class] = true;
                }
            }
            $selectors['canvas'] = true;
        }

        return $selectors;
    }

    /**
     * @return array<string, bool>
     */
    private function formControlSelectors(string $html): array
    {
        $selectors = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea'), true) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function allScriptContents(array $files): array
    {
        if ( array() !== $this->filesByPath ) {
            return $this->scriptContents;
        }

        $scripts = array();
        foreach ( $files as $file ) {
            if ( $this->isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $scripts[] = (string) $file['content'];
            }
        }

        return $scripts;
    }

    private function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function documentScriptContents(string $html, string $sourcePath, array $files): array
    {
        $scripts = array();
        if ( ! preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $src = $this->htmlAttribute((string) $match[1], 'src');
            if ( '' === $src ) {
                $scripts[] = (string) $match[2];
                continue;
            }

            $asset = $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( is_array($asset) && $this->isMaterializedScriptAsset($asset) && is_string($asset['content'] ?? null) ) {
                $scripts[] = (string) $asset['content'];
            }
        }

        return $scripts;
    }

    /**
     * @return array<int, string>
     */
    private function scriptDomSelectors(string $script): array
    {
        $cacheKey = hash('sha256', $script);
        if ( isset($this->scriptDomSelectorCache[$cacheKey]) ) {
            return $this->scriptDomSelectorCache[$cacheKey];
        }

        $selectors = array();
        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }
        if ( preg_match_all('/\b(?!document\b)[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }
        foreach ( $this->scriptDataAttributeSelectors($script) as $selector ) {
            if ( $this->isPresentationalRuntimeSelector($selector) ) {
                continue;
            }
            $selectors[$selector] = true;
        }
        foreach ( $this->scriptScopedElementSelectors($script, 'canvas') as $selector ) {
            $selectors[$selector] = true;
        }
        foreach ( $this->scriptScopedElementSelectors($script, 'svg') as $selector ) {
            $selectors[$selector] = true;
        }
        foreach ( $this->scriptAppendedRootSelectors($script) as $selector ) {
            $selectors[$selector] = true;
        }
        if ( preg_match_all('/\.\s*closest\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }

        return $this->scriptDomSelectorCache[$cacheKey] = array_keys($selectors);
    }

    private function isPresentationOnlyScriptSelector(string $script, string $selector): bool
    {
        if ( $this->isBehavioralRuntimeSelector($selector) ) {
            return false;
        }

        $selectorPattern = preg_quote($selector, '/');
        if ( ! preg_match_all('/querySelector(?:All)?\s*\(\s*(["\'])' . $selectorPattern . '\1\s*\)(.{0,700})/s', $script, $matches) ) {
            return false;
        }

        foreach ( $matches[2] as $tail ) {
            $window = (string) $tail;
            if ( ! str_contains($window, 'classList.') ) {
                return false;
            }
            if ( preg_match('/\b(?:addEventListener|appendChild|removeChild|replaceChildren|insertAdjacentHTML|innerHTML|outerHTML|textContent|value|checked|selectedIndex|setAttribute|removeAttribute|toggleAttribute|getContext|submit|fetch)\b|\.\s*(?:hidden|disabled|style|dataset)\b/', $window) ) {
                return false;
            }
        }

        return true;
    }

    private function isBehavioralRuntimeSelector(string $selector): bool
    {
        if ( $this->isPresentationalRuntimeSelector($selector) ) {
            return false;
        }

        if ( str_contains($selector, '[') || in_array($selector, array('button', 'input', 'select', 'textarea', 'canvas', 'svg'), true) ) {
            return true;
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:form|modal|drawer|cart|checkout|search|filter|tab|accordion|slider|carousel|canvas|stage|player|map|app|editor|playground|demo)(?:[^a-z0-9]|$)/i', $selector);
    }

    /**
     * @return array<int, string>
     */
    private function scriptDataAttributeSelectors(string $script): array
    {
        $selectors = array();
        if ( ! preg_match_all('/(?:querySelector(?:All)?|closest)\s*\(\s*(["\'`])(.{1,240}?)\1\s*\)/s', $script, $calls, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $calls as $call ) {
            foreach ( $this->dataAttributeSelectorsFromCssSelector((string) $call[2]) as $selector ) {
                $selectors[$selector] = true;
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function dataAttributeSelectorsFromCssSelector(string $selector): array
    {
        $selectors = array();
        if ( preg_match_all('/(?:^|[\s>+~,])([a-z][a-z0-9-]*)?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*[*^$|~]?=\s*(?:"[^"]{0,120}"|\'[^\']{0,120}\'|[^\]\s"\']{1,120}))?\]/', $selector, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $selector = strtolower((string) ($match[1] ?? '')) . '[' . strtolower((string) $match[2]) . ']';
                if ( ! $this->isPresentationalRuntimeSelector($selector) ) {
                    $selectors[$selector] = true;
                }
            }
        }
        if ( preg_match_all('/\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*[*^$|~]?=\s*(?:"[^"]{0,120}"|\'[^\']{0,120}\'|[^\]\s"\']{1,120}))?\]/', $selector, $matches) ) {
            foreach ( $matches[1] as $attribute ) {
                $selector = '[' . strtolower((string) $attribute) . ']';
                if ( ! $this->isPresentationalRuntimeSelector($selector) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    private function isPresentationalRuntimeSelector(string $selector): bool
    {
        $name = '';
        if ( preg_match('/\[(data-[A-Za-z][A-Za-z0-9_-]*)/', $selector, $match) ) {
            $name = substr(strtolower((string) $match[1]), 5);
        } elseif ( preg_match('/^(?:[a-z][a-z0-9-]*\.|\.)([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        } elseif ( preg_match('/^#([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $match) ) {
            $name = strtolower((string) $match[1]);
        }

        if ( '' === $name ) {
            return false;
        }

        foreach ( preg_split('/[^a-z0-9]+/', $name) ?: array() as $token ) {
            if ( in_array($token, array( 'animate', 'animation', 'appear', 'count', 'counter', 'delay', 'fade', 'motion', 'parallax', 'reveal', 'scroll', 'stagger', 'transition' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private function scriptControlRuntimeSelectors(string $script): array
    {
        $cacheKey = hash('sha256', $script);
        if ( isset($this->scriptControlSelectorCache[$cacheKey]) ) {
            return $this->scriptControlSelectorCache[$cacheKey];
        }

        $selectors = array();
        $runtimeUsePattern = '\.\s*(?:addEventListener|value|checked|selectedIndex|selectedOptions|options|files|validity|setCustomValidity|focus|select|click|dispatchEvent)\b';

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $runtimeUsePattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)\s*(?:\.\s*[^;\n]*)?' . $runtimeUsePattern . '/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selectors[(string) $selector] = true;
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelectorAll\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)\s*\.\s*forEach\s*\(\s*(?:\(\s*)?([A-Za-z_$][A-Za-z0-9_$]*)(?:\s*\))?\s*=>\s*\{([\s\S]{0,2000}?)\n\s*\}\s*\)/', $script, $callbacks, PREG_SET_ORDER) ) {
            foreach ( $callbacks as $callback ) {
                if ( preg_match('/\b' . preg_quote((string) $callback[3], '/') . '\s*' . $runtimeUsePattern . '/', (string) $callback[4]) ) {
                    $selectors[(string) $callback[2]] = true;
                }
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $runtimeUsePattern . '/', $script) ) {
                    $selectors['#' . (string) $assignment[3]] = true;
                }
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match('/\b' . preg_quote((string) $assignment[1], '/') . '\s*' . $runtimeUsePattern . '/', $script) ) {
                    $selectors[(string) $assignment[3]] = true;
                }
            }
        }

        return $this->scriptControlSelectorCache[$cacheKey] = $selectors;
    }

    private function scriptSelectorPattern(): string
    {
        $name = '[A-Za-z][A-Za-z0-9_-]*';
        return '(?:[#.]' . $name . '|' . $name . '\\.' . $name . '|\\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\\]|' . $name . '\\[data-' . $name . '(?:=["\'][^"\']{1,80}["\'])?\\]|canvas|svg|' . implode('|', self::RUNTIME_TAG_SELECTORS) . ')';
    }

    private function canonicalRuntimeSelector(string $selector): string
    {
        $selector = trim($selector);
        if ( preg_match('/^(?:([a-z][a-z0-9-]*))?\[(data-[A-Za-z][A-Za-z0-9_-]*)(?:=["\'][^"\']{1,80}["\'])?\]$/', $selector, $match) ) {
            return strtolower((string) ($match[1] ?? '')) . '[' . strtolower((string) $match[2]) . ']';
        }

        return $selector;
    }

    /**
     * @return array<int, string>
     */
    private function scriptScopedElementSelectors(string $script, string $tag, string $usePattern = ''): array
    {
        $selectors = array();
        if ( ! preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $roots, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $roots as $root ) {
            $rootVar = (string) $root[1];
            $childPattern = '/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\b' . preg_quote($rootVar, '/') . '\s*\.\s*querySelector\s*\(\s*(["\'])' . preg_quote($tag, '/') . '\2\s*\)/';
            if ( ! preg_match_all($childPattern, $script, $children, PREG_SET_ORDER) ) {
                continue;
            }

            foreach ( $children as $child ) {
                $childVar = (string) $child[1];
                if ( '' === $usePattern || preg_match('/\b' . preg_quote($childVar, '/') . '\s*' . $usePattern . '/', $script) ) {
                    $selectors[] = $tag;
                }
            }
        }

        return array_values(array_unique($selectors));
    }

    /**
     * @return array<int, string>
     */
    private function scriptAppendedRootSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $roots, PREG_SET_ORDER) ) {
            foreach ( $roots as $root ) {
                if ( preg_match('/\b' . preg_quote((string) $root[1], '/') . '\s*\.\s*appendChild\s*\(/', $script) ) {
                    $selectors[] = '' !== (string) ($root[3] ?? '') ? '#' . (string) $root[3] : (string) $root[5];
                }
            }
        }

        return array_values(array_unique($selectors));
    }

    private function htmlAttribute(string $tag, string $name): string
    {
        return $this->htmlAttributes($tag)[strtolower($name)] ?? '';
    }

    private function hasHtmlAttribute(string $tag, string $name): bool
    {
        return array_key_exists(strtolower($name), $this->htmlAttributes($tag));
    }

    /** @return array<string,string> */
    private function htmlAttributes(string $tag): array
    {
        $length = strlen($tag);
        $offset = strpos($tag, '<');
        if (false === $offset) {
            $offset = 0;
        } else {
            ++$offset;
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset < $length && '/' === $tag[$offset]) ++$offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('>', '/'), true)) ++$offset;
        }
        $attributes = array();
        while ($offset < $length) {
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            if ($offset >= $length || '>' === $tag[$offset] || '/' === $tag[$offset]) break;
            $start = $offset;
            while ($offset < $length && !ctype_space($tag[$offset]) && !in_array($tag[$offset], array('=', '>', '/', '"', "'", '<'), true)) ++$offset;
            if ($start === $offset) break;
            $name = strtolower(substr($tag, $start, $offset - $start));
            while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
            $value = '';
            if ($offset < $length && '=' === $tag[$offset]) {
                ++$offset;
                while ($offset < $length && ctype_space($tag[$offset])) ++$offset;
                if ($offset >= $length) break;
                if (in_array($tag[$offset], array('"', "'"), true)) {
                    $quote = $tag[$offset++]; $start = $offset;
                    while ($offset < $length && $tag[$offset] !== $quote) ++$offset;
                    if ($offset >= $length) break;
                    $value = substr($tag, $start, $offset - $start); ++$offset;
                } else {
                    $start = $offset;
                    while ($offset < $length && !ctype_space($tag[$offset]) && '>' !== $tag[$offset]) {
                        if (in_array($tag[$offset], array('"', "'", '<'), true)) break 2;
                        ++$offset;
                    }
                    $value = substr($tag, $start, $offset - $start);
                }
            }
            if (!isset($attributes[$name])) $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{internal_links: array<int, array<string, mixed>>, asset_references: array<int, array<string, mixed>>, image_references: array<int, array<string, mixed>>}
     */
    private function referenceReports(array $files): array
    {
        return ( new ReferenceAnalyzer() )->referenceReports(
            $files,
            fn (array $file): bool => $this->isLinkableDocument($file),
            fn (array $asset): bool => $this->isSafeImageAsset($asset)
        );
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isLinkableDocument(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('html', 'blocks'), true) && ! $this->isTemplatePartFile($file);
    }

    /**
     * @param array{files: array<int, array<string, mixed>>, bytes: int, source_hash: string} $artifact
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, array<string, mixed>> $assets
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string, mixed>
     */
    private function compiledSiteReport(array $artifact, string $entryPath, array $documents, array &$assets, array $blockTypes, string $serializedBlocks, array $entryShellArtifacts = array(), array $compiledHtmlDocuments = array()): array
    {
        $pages = array();
        $assetPayloadsByPath = array();
        foreach ( $assets as $asset ) {
            $path = (string) ($asset['path'] ?? '');
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? '');
            $assetPayloadsByPath[$path][hash('sha256', $payload)] = true;
        }
        foreach ( $artifact['files'] as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || $this->isTemplatePartFile($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $title = $this->titleFromHtml((string) ($file['content'] ?? ''), $path);
            $slug = $this->slugFromPath($path);
            $content = (string) ($file['content'] ?? '');
            $compiledBlocks = $path === $entryPath
                ? array('serialized_blocks' => $serializedBlocks, 'assets' => array(), 'shell_artifacts' => $entryShellArtifacts)
                : ($compiledHtmlDocuments[$path] ?? $this->compileHtmlDocumentBlocks($content, $path, $artifact['files'], 'artifact-document', '', true));
            foreach ( $compiledBlocks['assets'] ?? array() as $generatedAsset ) {
                if ( is_array($generatedAsset) ) {
                    if ( 'css' === ($generatedAsset['kind'] ?? null) ) {
                        $generatedAsset['compilation'] = array('scope' => 'page', 'id' => $path);
                    }
                    $generatedAssetPath = (string) ($generatedAsset['path'] ?? '');
                    $payload = is_string($generatedAsset['content_base64'] ?? null) ? $generatedAsset['content_base64'] : (string) ($generatedAsset['content'] ?? '');
                    $payloadHash = hash('sha256', $payload);
                    if ( isset($assetPayloadsByPath[$generatedAssetPath][$payloadHash]) ) {
                        continue;
                    }
                    $assets[] = $generatedAsset;
                    $assetPayloadsByPath[$generatedAssetPath][$payloadHash] = true;
                }
            }
            $blockMarkup = (string) ($compiledBlocks['serialized_blocks'] ?? '');
            if ( '' === $blockMarkup && '' !== trim($content) ) {
                $blockMarkup = $this->htmlDocumentBlockMarkup($content);
            }
            $bodyFormat = '' !== trim($blockMarkup) ? 'blocks' : 'html';
            $pages[] = array_filter(
                array(
                    'source_path'    => $path,
                    'kind'           => 'html',
                    'role'           => $file['role'] ?? 'document',
                    'entrypoint'     => $path === $entryPath || ! empty($file['entrypoint']),
                    'slug'           => $slug,
                    'title'          => $title,
                    'metadata'       => array_merge($this->documentMetadata($path, 'html', (string) ($file['role'] ?? 'document'), $slug, $title, $bodyFormat), is_string($file['metadata']['route_path'] ?? null) ? array('route_path' => $file['metadata']['route_path']) : array(), is_string($file['metadata']['post_type'] ?? null) ? array('post_type' => $file['metadata']['post_type'], 'post_type_declaration' => 'metadata:post_type') : array()),
                    'document_metadata' => $this->fullDocumentMetadata($content, $path, $artifact['files'], $path === $entryPath ? $assets : ($compiledBlocks['assets'] ?? array())),
                    'html'           => $file['content'] ?? '',
                    'body_format'    => $bodyFormat,
                    'block_markup'   => $blockMarkup,
                    'shell_artifacts' => is_array($compiledBlocks['shell_artifacts'] ?? null) ? $compiledBlocks['shell_artifacts'] : array(),
                    'runtime_islands' => is_array($compiledBlocks['runtime_islands'] ?? null) ? $compiledBlocks['runtime_islands'] : array(),
                    'bytes'          => $file['bytes'] ?? 0,
                    'mime_type'      => $file['mime_type'] ?? 'text/html',
                    'asset_references' => $this->assetReferencePaths($assets),
                    'provenance'     => $file['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        foreach ( $documents as $document ) {
            $pages[] = array_filter(
                array(
                    'source_path'  => $document['source_path'] ?? '',
                    'kind'         => $document['kind'] ?? 'document',
                    'role'         => 'document',
                    'entrypoint'   => false,
                    'slug'         => $document['slug'] ?? '',
                    'title'        => $document['title'] ?? '',
                    'metadata'     => $this->documentMetadata(
                        (string) ($document['source_path'] ?? ''),
                        (string) ($document['kind'] ?? 'document'),
                        'document',
                        (string) ($document['slug'] ?? ''),
                        (string) ($document['title'] ?? ''),
                        (string) ($document['body_format'] ?? ''),
                        $document
                    ),
                    'body_format'  => $document['body_format'] ?? '',
                    'block_markup' => $document['block_markup'] ?? '',
                    'provenance'   => $document['provenance'] ?? array(),
                ),
                static fn (mixed $value): bool => array() !== $value
            );
        }

        $templateParts = $this->compiledSiteTemplateParts($artifact['files']);
        // Preserve the v1 report's established entry-shell shape while the v2
        // plan uses complete shell candidates for cross-page comparison.
        $partSlugs = array_fill_keys(array_column($templateParts, 'slug'), true);
        foreach ( $entryShellArtifacts as $shellArtifact ) {
            if ( ! is_array($shellArtifact) ) {
                continue;
            }
            $slug = (string) ($shellArtifact['slug'] ?? '');
            if ( isset($partSlugs[$slug]) ) {
                $shellArtifact['slug'] = 'entry-' . $slug;
            }
            $partSlugs[(string) $shellArtifact['slug']] = true;
            if ( is_string($shellArtifact['template_part_block_markup'] ?? null) ) {
                $shellArtifact['block_markup'] = $shellArtifact['template_part_block_markup'];
                unset($shellArtifact['template_part_block_markup'], $shellArtifact['inner_block_markup']);
            } elseif ( is_string($shellArtifact['inner_block_markup'] ?? null) ) {
                $shellArtifact['block_markup'] = $shellArtifact['inner_block_markup'];
                unset($shellArtifact['inner_block_markup']);
            }
            $templateParts[] = $shellArtifact;
        }

        return array(
            'schema'      => 'blocks-engine/php-transformer/compiled-site/v1',
            'source_hash' => $artifact['source_hash'],
            'entry_path'  => $entryPath,
            'pages'       => $pages,
            'assets'      => $this->compiledSiteAssets($assets),
            'template_parts' => $templateParts,
            'visual_repair' => $this->compiledSiteVisualRepair($assets, $artifact['files']),
            'runtime_declarations' => $artifact['runtime_declarations'],
            'theme'       => array_filter(
                array(
                    'stylesheets' => $this->assetPathsByIntentOrRole($assets, 'style', 'stylesheet'),
                    'scripts'     => $this->assetPathsByIntentOrRole($assets, 'behavior', 'script'),
                    'fonts'       => $this->assetPathsByRole($assets, 'font'),
                    'images'      => $this->assetPathsByRole($assets, 'image'),
                    'font_link_html' => $this->themeFontLinkHtml($artifact['files']),
                    'static_css'  => $this->themeStaticCss($artifact['files']),
                    'font_css_sources' => $this->themeFontCssSources($artifact['files']),
                    'template_parts' => array_values(array_map(
                        static fn (array $part): string => (string) ($part['source_path'] ?? ''),
                        $templateParts
                    )),
                    'block_types' => array_values(array_map(
                        static fn (array $blockType): string => (string) ($blockType['name'] ?? ''),
                        $blockTypes
                    )),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            ),
            'totals'      => array(
                'pages'       => count($pages),
                'assets'      => count($assets),
                'input_bytes' => $artifact['bytes'],
            ),
        );
    }

    private function htmlDocumentBlockMarkup(string $html): string
    {
        if ( '' === trim($html) ) {
            return '';
        }
        if ( $this->containsBlockMarkup($html) ) {
            return $html;
        }

        $result = ( new HtmlTransformer() )->transform($html, array(
            'source'       => 'html-document',
            'source_scope' => 'artifact-document',
        ))->toArray();

        return isset($result['serialized_blocks']) && is_scalar($result['serialized_blocks']) ? trim((string) $result['serialized_blocks']) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataForSource(string $sourcePath, array $files): array
    {
        $metadata = array();
        $candidates = array() !== $this->filesByPath ? $this->imageFiles : $files;
        foreach ( $candidates as $file ) {
            if ( $this->isMaterializedHtmlDocument($file) ) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');
            $mimeType = (string) ($file['mime_type'] ?? '');
            if ( ! str_starts_with($mimeType, 'image/') ) {
                continue;
            }
            if ( '' === $path ) {
                continue;
            }

            $asset = array(
                'url'       => $path,
                'path'      => $path,
                'mime_type' => $mimeType,
            );

            foreach ( $this->assetLookupKeysForSource($path, $sourcePath) as $key ) {
                $metadata[$key] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataForSource(string $html, string $sourcePath, array $files): array
    {
        if ( ! preg_match_all('/<script\b[^>]*>/i', $html, $matches) ) {
            return array();
        }

        $metadata = array();
        foreach ( $matches[0] as $tag ) {
            $src = $this->htmlAttribute((string) $tag, 'src');
            if ( '' === $src ) {
                continue;
            }

            $asset = $this->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( ! is_array($asset) || ! $this->isMaterializedScriptAsset($asset) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => (string) ($asset['path'] ?? ''),
                'selector'           => 'script[src="' . $src . '"]',
                'attributes'         => array_filter(array(
                    'src'   => $src,
                    'type'  => $this->htmlAttribute((string) $tag, 'type'),
                    'async' => $this->htmlAttribute((string) $tag, 'async'),
                    'defer' => $this->htmlAttribute((string) $tag, 'defer'),
                ), static fn (string $value): bool => '' !== $value),
                'script_role'        => 'runtime',
                'script_source_kind' => 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeRows($metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }

    /**
     * @return array<int, string>
     */
    private function assetLookupKeysForSource(string $assetPath, string $sourcePath): array
    {
        $keys = array($assetPath, '/' . $assetPath);
        $relativePath = $this->relativePathFromSource($assetPath, $sourcePath);
        if ( '' !== $relativePath ) {
            $keys[] = $relativePath;
            if ( ! str_starts_with($relativePath, '../') ) {
                $keys[] = './' . $relativePath;
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => '' !== $key)));
    }

    private function relativePathFromSource(string $assetPath, string $sourcePath): string
    {
        $sourceDir = '' === $sourcePath || ! str_contains($sourcePath, '/') ? '' : dirname($sourcePath);
        if ( '' === $sourceDir ) {
            return $assetPath;
        }

        $sourceParts = explode('/', $sourceDir);
        $assetParts = explode('/', $assetPath);
        while ( array() !== $sourceParts && array() !== $assetParts && $sourceParts[0] === $assetParts[0] ) {
            array_shift($sourceParts);
            array_shift($assetParts);
        }

        return implode('/', array_merge(array_fill(0, count($sourceParts), '..'), $assetParts));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentMetadata(string $sourcePath, string $kind, string $role, string $slug, string $title, string $bodyFormat, array $document = array()): array
    {
        return array_filter(
            array(
                'source_path' => $sourcePath,
                'kind'        => $kind,
                'role'        => $role,
                'post_type'   => $document['post_type'] ?? ('document' === $role ? 'page' : ''),
                'slug'        => $slug,
                'title'       => $title,
                'excerpt'     => $document['excerpt'] ?? '',
                'date'        => $document['date'] ?? '',
                'template'    => $document['template'] ?? '',
                'taxonomies'  => $document['taxonomies'] ?? array(),
                'frontmatter' => $document['frontmatter'] ?? array(),
                'body_format' => $bodyFormat,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /** @param array<int, array<string, mixed>> $files @param array<int, array<string, mixed>> $generatedAssets @return array<string, mixed> */
    private function fullDocumentMetadata(string $html, string $sourcePath, array $files, array $generatedAssets = array()): array
    {
        $headEnd = preg_match('/<head\b[^>]*>.*?<\/head\s*>/is', $html, $head) ? (int) strpos($html, $head[0]) + strlen($head[0]) : 0;
        $reference = static fn(string $value): array => array('url' => $value);
        $attributes = function (string $tag, array $names): array {
            $values = array();
            foreach ($names as $name) {
                if (!$this->hasHtmlAttribute($tag, $name)) continue;
                $value = $this->htmlAttribute($tag, $name);
                // HTML's empty and invalid-value CORS states both select anonymous.
                $values[str_replace('-', '_', $name)] = 'crossorigin' === $name && '' === $value ? 'anonymous' : $value;
            }
            return $values;
        };
        $placement = static fn(int $offset): string => $offset < $headEnd ? 'head' : 'body';
        $inlineScripts = array();
        foreach ($generatedAssets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['selector'] ?? null) && is_string($asset['path'] ?? null)) $inlineScripts[$asset['selector']] = $asset['path'];
        $meta = array(); $links = array(); $scripts = array();
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) foreach ($matches[0] as $match) {
            $tag = (string) $match[0];
            $row = $attributes($tag, array('charset', 'name', 'property', 'http-equiv', 'content'));
            if (array() !== $row) { $row = array_merge(array('order' => count($meta), 'placement' => $placement((int) $match[1])), $row); $meta[] = $row; }
        }
        if (preg_match_all('/<link\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) foreach ($matches[0] as $match) {
            $tag = (string) $match[0]; $href = $this->htmlAttribute($tag, 'href');
            if ('' === $href) continue;
            $links[] = array_merge(array('order' => count($links), 'placement' => $placement((int) $match[1])), $attributes($tag, array('rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes')), $reference($href));
        }
        if (preg_match_all('/<script\b[^>]*>(?:.*?)<\/script\s*>/is', $html, $matches, PREG_OFFSET_CAPTURE)) foreach ($matches[0] as $match) {
            $tag = (string) $match[0]; $open = strstr($tag, '>', true) . '>'; $src = $this->htmlAttribute($open, 'src');
            $async = $this->hasHtmlAttribute($open, 'async'); $defer = $this->hasHtmlAttribute($open, 'defer'); $module = 'module' === strtolower($this->htmlAttribute($open, 'type'));
            $selector = 'script:nth-of-type(' . (count($scripts) + 1) . ')';
            $supersededBy = $this->htmlAttribute($open, 'data-blocks-engine-superseded-by');
            $inlineBodyHash = hash('sha256', trim((string) preg_replace('/^.*?>|<\/script\s*>$/is', '', $tag)));
            $inline = isset($inlineScripts[$selector]) ? $reference($inlineScripts[$selector]) : array('source_kind' => 'inline', 'body_hash' => $inlineBodyHash);
            if ( '' !== $supersededBy ) $inline = array_merge($inline, array('selector' => $selector, 'superseded_by' => $supersededBy, 'body_hash' => $inlineBodyHash));
            $scripts[] = array_merge(array('order' => count($scripts), 'placement' => $placement((int) $match[1]), 'async' => $async, 'defer' => $defer, 'module' => $module, 'nomodule' => $this->hasHtmlAttribute($open, 'nomodule'), 'effective_loading' => $async ? 'async' : (($defer || $module) ? 'defer' : 'blocking')), $attributes($open, array('type', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority')), '' !== $src ? $reference($src) : $inline);
        }
        $title = preg_match('/<title\b[^>]*>(.*?)<\/title\s*>/is', $html, $match) ? trim(html_entity_decode(strip_tags((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $this->titleFromHtml($html, $sourcePath);
        return array('source_context' => array('source_path' => $sourcePath, 'kind' => 'html'), 'title' => $title, 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => $meta, 'links' => $links, 'scripts' => $scripts);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteTemplateParts(array $files): array
    {
        $parts = array();
        foreach ( $files as $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( ! $this->isTemplatePartFile($file) ) {
                continue;
            }

            $slug = $this->slugFromPath($path);
            $parts[] = array_filter(
                array(
                    'source_path'  => $path,
                    'slug'         => $slug,
                    'title'        => $this->titleFromPath($path),
                    'area'         => $this->templatePartArea($path, (string) ($file['role'] ?? '')),
                    'body_format'  => (string) ($file['kind'] ?? ''),
                    'block_markup' => $this->htmlDocumentBlockMarkup((string) ($file['content'] ?? '')),
                    'document_metadata' => $this->fullDocumentMetadata((string) ($file['content'] ?? ''), $path, $files),
                    'runtime_islands' => array(),
                    'bytes'        => $file['bytes'] ?? 0,
                    'provenance'   => $file['provenance'] ?? array(),
                    'placement'    => array('kind' => 'unbound'),
                ),
                static fn (mixed $value): bool => '' !== $value && array() !== $value
            );
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isTemplatePartFile(array $file): bool
    {
        $path = (string) ($file['path'] ?? '');
        $role = (string) ($file['role'] ?? '');
        return 'html' === ($file['kind'] ?? '') && ('template-part' === $role || preg_match('#(^|/)(parts|template-parts)/[^/]+\.html?$#i', $path));
    }

    private function templatePartArea(string $path, string $role): string
    {
        return ShellLandmarkPolicy::templatePartArea($path, $role);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<string, mixed>
     */
    private function compiledSiteVisualRepair(array $assets, array $files): array
    {
        $stylesheets = array_values(array_filter($assets, fn (array $asset): bool => $this->isVisualRepairStylesheet($asset)));
        $css = '';
        foreach ( $stylesheets as $asset ) {
            if ( isset($asset['content']) && is_string($asset['content']) ) {
                $css .= ('' === $css ? '' : "\n") . $asset['content'];
            }
        }
        $staticCss = $this->themeStaticCss($files, false);
        $navigationCompatCss = $this->wordpressCompatCss($staticCss, $files);
        if ( '' !== $navigationCompatCss ) {
            $css .= ('' === $css ? '' : "\n") . $navigationCompatCss;
        }

        return array_filter(
            array(
                'stylesheets' => array_values(array_map(
                    static fn (array $asset): array => array_filter(
                        array(
                            'path'      => $asset['path'] ?? '',
                            'role'      => $asset['role'] ?? '',
                            'intent'    => $asset['intent'] ?? '',
                            'mime_type' => $asset['mime_type'] ?? '',
                            'bytes'     => $asset['bytes'] ?? 0,
                        ),
                        static fn (mixed $value): bool => '' !== $value
                    ),
                    $stylesheets
                )),
                'css'         => $css,
                'compat_css'  => $navigationCompatCss,
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isVisualRepairStylesheet(array $asset): bool
    {
        $path = (string) ($asset['path'] ?? '');
        $role = (string) ($asset['role'] ?? '');
        $intent = (string) ($asset['intent'] ?? '');
        return 'css' === ($asset['kind'] ?? '') && ('visual-repair' === $role || 'visual-repair' === $intent || preg_match('/(?:^|[-_\/])visual[-_]repair(?:[-_\/]|\.)/i', $path));
    }

    private function titleFromHtml(string $html, string $path): string
    {
        if ( preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $match) || preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) ) {
            $titleHtml = preg_replace('/<\s*(?:br|\/\s*(?:div|h[1-6]|p))\b[^>]*>/i', ' ', $match[1]) ?? $match[1];
            $title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5)) ?? '');
            if ( '' !== $title ) {
                return $title;
            }
        }

        return $this->titleFromPath($path);
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, array<string, mixed>>
     */
    private function compiledSiteAssets(array $assets): array
    {
        return array_values(array_map(
            static fn (array $asset): array => array_filter(
                array(
                    'source'           => $asset['source'] ?? '',
                    'path'             => $asset['path'] ?? '',
                    'target_path'      => $asset['target_path'] ?? $asset['path'] ?? '',
                    'kind'             => $asset['kind'] ?? '',
                    'role'             => $asset['role'] ?? '',
                    'stylesheet_placement' => $asset['stylesheet_placement'] ?? '',
                    'intent'           => $asset['intent'] ?? '',
                    'media_type'       => $asset['media_type'] ?? $asset['mime_type'] ?? '',
                    'media'            => $asset['media'] ?? '',
                    'mime_type'        => $asset['mime_type'] ?? '',
                    'bytes'            => $asset['bytes'] ?? 0,
                    'binary'           => $asset['binary'] ?? false,
                    'content_encoding' => $asset['content_encoding'] ?? $asset['encoding'] ?? '',
                    'content'          => $asset['content'] ?? null,
                    'content_base64'   => $asset['content_base64'] ?? null,
                    'payload_reference' => $asset['payload_reference'] ?? null,
                    'raw_sha256'       => $asset['raw_sha256'] ?? null,
                    'transport_sha256' => $asset['transport_sha256'] ?? null,
                    'hash'             => $asset['hash'] ?? $asset['provenance']['hash'] ?? '',
                    'source_hash'      => $asset['source_hash'] ?? '',
                    'source_role'      => $asset['source_role'] ?? '',
                    'keep_source'      => $asset['keep_source'] ?? null,
                    'pipeline_sanitized' => $asset['pipeline_sanitized'] ?? null,
                    'placement'        => $asset['placement'] ?? '',
                    'type'             => $asset['type'] ?? '',
                    'defer'            => $asset['defer'] ?? false,
                    'async'            => $asset['async'] ?? false,
                    'source_path'      => $asset['source_path'] ?? '',
                    'selector'         => $asset['selector'] ?? '',
                    'references'       => $asset['references'] ?? array(),
                    'compilation'      => 'css' === ($asset['kind'] ?? null) ? ($asset['compilation'] ?? null) : null,
                ),
                static fn (mixed $value, string $key): bool => ('content' === $key && is_string($value)) || (null !== $value && '' !== $value),
                ARRAY_FILTER_USE_BOTH
            ),
            $assets
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetReferencePaths(array $assets): array
    {
        return array_values(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByIntentOrRole(array $assets, string $intent, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $intent === ($asset['intent'] ?? '') || $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function assetPathsByRole(array $assets, string $role): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) ($asset['path'] ?? ''),
            array_filter($assets, static fn (array $asset): bool => $role === ($asset['role'] ?? ''))
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function entryTransformDiagnostics(array $diagnostics, string $sourcePath = ''): array
    {
        $diagnostics = array_values(array_filter(
            $diagnostics,
            static fn (array $diagnostic): bool => 'html_to_blocks_core_slice' !== ($diagnostic['code'] ?? '')
        ));
        if ( '' !== $sourcePath ) foreach ( $diagnostics as &$diagnostic ) if ( !isset($diagnostic['source_path']) ) $diagnostic['source_path'] = $sourcePath;
        unset($diagnostic);
        return $diagnostics;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{documents: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function compileSourceDocuments(array $artifact): array
    {
        $documents = array();
        $components = array();
        $diagnostics = array();

        foreach ( $artifact['files'] as $file ) {
            if ( ! in_array($file['kind'], array('markdown', 'mdx'), true) || ! empty($file['binary']) ) {
                continue;
            }

            $parsed = $this->parseFrontmatter((string) $file['content']);
            $body = $parsed['body'];
            $frontmatter = $parsed['frontmatter'];
            $documentDiagnostics = array();

            if ( 'mdx' === $file['kind'] ) {
                $mdx = $this->extractMdxSemantics($body, $file, $artifact);
                $body = $mdx['markdown_body'];
                $components = array_merge($components, $mdx['components']);
                $documentDiagnostics = array_merge($documentDiagnostics, $mdx['diagnostics']);
            }

            $conversion = $this->convertMarkdownToBlocks($body);
            $documentDiagnostics = array_merge($documentDiagnostics, $conversion['diagnostics']);
            $diagnostics = array_merge($diagnostics, $documentDiagnostics);

            $documents[] = array(
                'source_path'  => $file['path'],
                'kind'         => $file['kind'],
                'post_type'    => $this->frontmatterString($frontmatter, array('post_type', 'type'), 'page'),
                'slug'         => $this->frontmatterString($frontmatter, array('slug'), $this->slugFromPath((string) $file['path'])),
                'title'        => $this->frontmatterString($frontmatter, array('title'), $this->titleFromPath((string) $file['path'])),
                'excerpt'      => $this->frontmatterString($frontmatter, array('excerpt', 'description'), ''),
                'date'         => $this->frontmatterString($frontmatter, array('date', 'published', 'published_at'), ''),
                'template'     => $this->frontmatterString($frontmatter, array('template', 'layout'), ''),
                'taxonomies'   => $this->frontmatterTaxonomies($frontmatter),
                'frontmatter'  => $frontmatter,
                'body'         => $body,
                'body_format'  => 'mdx' === $file['kind'] ? 'mdx' : 'markdown',
                'block_markup' => $conversion['serialized_blocks'],
                'diagnostics'  => $documentDiagnostics,
                'provenance'   => $file['provenance'],
            );
        }

        return array(
            'documents'   => $documents,
            'components'  => $components,
            'diagnostics' => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array{serialized_blocks: string, diagnostics: array<int, array<string, mixed>>}
     */
    private function convertMarkdownToBlocks(string $markdown): array
    {
        $result = ( new FormatBridge() )->convertResult(
            $markdown,
            'markdown',
            'blocks',
            array(
                'source'  => 'artifact_compiler',
                'context' => array(
                    'source_format' => 'markdown',
                    'target_format' => 'blocks',
                ),
            )
        )->toArray();

        if ( 'failed' !== (string) ( $result['status'] ?? '' ) ) {
            return array(
                'serialized_blocks' => (string) ( $result['serialized_blocks'] ?? '' ),
                'diagnostics'       => array_values(array_filter(
                    is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array(),
                    static fn (array $diagnostic): bool => 'format_bridge_conversion_completed' !== (string) ($diagnostic['code'] ?? '')
                )),
            );
        }

        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : array();
        $diagnostics[] = $this->diagnostic('markdown_adapter_unavailable', 'warning', 'A Markdown adapter is unavailable; preserved source Markdown as a core/html fallback.');

        return array(
            'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
            'diagnostics'       => $diagnostics,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $entrypoints
     * @return array<string, mixed>|null
     */
    private function entryFile(array $files, array $entrypoints): ?array
    {
        foreach ( $entrypoints as $entrypoint ) {
            foreach ( $files as $file ) {
                if ( $entrypoint === $file['path'] && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( array('index.html', 'index.htm', 'static-site/index.html', 'public/index.html') as $preferred ) {
            foreach ( $files as $file ) {
                if ( $preferred === strtolower((string) $file['path']) && $this->isEntryFile($file) ) {
                    return $file;
                }
            }
        }
        foreach ( $files as $file ) {
            if ( $this->isEntryFile($file) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isEntryFile(array $file): bool
    {
        if ( ! empty($file['binary']) ) {
            return false;
        }

        return 'html' === ($file['kind'] ?? '') || 'blocks' === ($file['kind'] ?? '') || $this->containsBlockMarkup((string) ($file['content'] ?? ''));
    }

    private function containsBlockMarkup(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function fileHashPayload(array $files): string
    {
        $payload = '';
        foreach ( $files as $file ) {
            $content = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
            $payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ($file['mime_type'] ?? '') . "\0" . $content . "\0";
        }

        return $payload;
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($key))) ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function assetManifest(array $files, string $entryPath, array $assetReferences = array(), string $entryHtml = ''): array
    {
        $assets = array();
        $unsupportedStylesheets = $this->unsupportedStylesheetPaths($entryHtml, $entryPath);
        foreach ( $files as $file ) {
            if ( $entryPath === $file['path'] || $this->isMaterializedHtmlDocument($file) || isset($unsupportedStylesheets[$file['path'] ?? '']) ) {
                continue;
            }
            $asset = array(
                'source'           => $file['source'] ?? 'artifact',
                'path'             => $file['path'],
                'target_path'      => $file['path'],
                'kind'             => $file['kind'],
                'bytes'            => $file['bytes'],
                'media_type'       => $file['mime_type'],
                'mime_type'        => $file['mime_type'],
                'role'             => $file['role'],
                'encoding'         => $file['encoding'],
                'content_encoding' => $file['encoding'],
                'binary'           => $file['binary'],
                'hash'             => $file['provenance']['hash'] ?? '',
                'source_hash'      => $file['provenance']['projected_from_hash'] ?? ($file['provenance']['hash'] ?? ''),
                'provenance'       => $file['provenance'],
            );
            if ( ! empty($file['content_base64']) ) {
                $asset['content_base64'] = $file['content_base64'];
            }
            if ( is_string($file['raw_sha256'] ?? null) ) {
                $asset['raw_sha256'] = $file['raw_sha256'];
            }
            if ( is_array($file['payload_reference'] ?? null) ) {
                $asset['payload_reference'] = $file['payload_reference'];
                $asset['raw_sha256'] = $file['raw_sha256'] ?? $file['payload_reference']['sha256'];
            }
            if ( is_string($file['transport_sha256'] ?? null) ) {
                $asset['transport_sha256'] = $file['transport_sha256'];
            }
            if ( empty($file['binary']) && ! $this->isUnsafeSvgAsset($file) ) {
                $asset['content'] = $file['content'];
            }
            if ( ! empty($file['intent']) ) {
                $asset['intent'] = $file['intent'];
            }
            foreach ( array('placement', 'type', 'source_path', 'selector') as $field ) {
                if ( isset($file[$field]) && is_scalar($file[$field]) && '' !== trim((string) $file[$field]) ) {
                    $asset[$field] = (string) $file[$field];
                }
            }
            if ( isset($file['media']) && is_scalar($file['media']) && '' !== trim((string) $file['media']) ) {
                $asset['media'] = (string) $file['media'];
            }
            if ( 'css' === ($file['kind'] ?? null) ) $asset['compilation'] = $this->fileOwnership($file);
            foreach ( array('defer', 'async') as $field ) {
                if ( isset($file[$field]) ) {
                    $asset[$field] = (bool) $file[$field];
                }
            }
            $references = $this->referencesForAsset((string) $file['path'], $assetReferences);
            if ( array() !== $references ) {
                $asset['references'] = $references;
            }
            $assets[] = $asset;
        }
        if ( '' === $entryHtml ) {
            return $assets;
        }
        $orderedPaths = array_column($this->stylesheetAssetsForSource($entryHtml, $entryPath, $files), 'path');
        $ordered = array();
        $consumed = array();
        foreach ( $orderedPaths as $path ) {
            if ( isset($consumed[$path]) ) {
                continue;
            }
            foreach ( $assets as $asset ) {
                if ( $path === ($asset['path'] ?? '') ) {
                    $ordered[] = $asset;
                    $consumed[$path] = true;
                    break;
                }
            }
        }
        foreach ( $assets as $asset ) {
            if ( isset($consumed[$asset['path'] ?? '']) ) {
                continue;
            }
            $ordered[] = $asset;
        }
        return $ordered;
    }

    /** @return array<string, true> */
    private function unsupportedStylesheetPaths(string $html, string $sourcePath): array
    {
        $unsupported = array();
        $supported = array();
        if ( ! preg_match_all('/<link\b[^>]*>/i', $html, $matches) ) {
            return $unsupported;
        }
        foreach ( $matches[0] as $tag ) {
            if ( ! preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $this->htmlAttribute((string) $tag, 'rel')) ) {
                continue;
            }
            $path = $this->stylesheetPathFromHref($this->htmlAttribute((string) $tag, 'href'), $sourcePath);
            if ( '' === $path ) {
                continue;
            }
            if ( $this->isCssStylesheetType($this->htmlAttribute((string) $tag, 'type')) ) {
                $supported[$path] = true;
            } else {
                $unsupported[$path] = true;
            }
        }
        foreach ( $supported as $path => $_true ) {
            unset($unsupported[$path]);
        }
        return $unsupported;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isMaterializedHtmlDocument(array $file): bool
    {
        return 'html' === ($file['kind'] ?? '') && ($this->isLinkableDocument($file) || $this->isTemplatePartFile($file));
    }

    /**
     * @param array<int, array<string, mixed>> $assetReferences
     * @return array<int, array<string, mixed>>
     */
    private function referencesForAsset(string $path, array $assetReferences): array
    {
        $references = array();
        foreach ( $assetReferences as $reference ) {
            if ( $path !== ($reference['asset_path'] ?? '') ) {
                continue;
            }

            $references[] = array_filter(
                array(
                    'source_path' => $reference['source_path'] ?? '',
                    'selector'    => $reference['selector'] ?? '',
                    'element'     => $reference['element'] ?? '',
                    'attribute'   => $reference['attribute'] ?? '',
                    'value'       => $reference['value'] ?? '',
                    'url'         => $reference['url'] ?? '',
                    'context'     => $reference['context'] ?? '',
                ),
                static fn (mixed $value): bool => '' !== $value
            );
        }

        return $references;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function svgAssetDiagnostics(array $files): array
    {
        $diagnostics = array();
        foreach ( $files as $file ) {
            if ( 'image/svg+xml' !== ($file['mime_type'] ?? '') || empty($file['content']) || $this->isSafeSvgContent((string) $file['content']) ) {
                continue;
            }

            $diagnostics[] = $this->diagnostic('unsafe_svg_asset', 'warning', 'An SVG image asset contains scriptable markup and its inline content was not exposed.', array('path' => $file['path']));
        }

        return $diagnostics;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function isSafeImageAsset(array $asset): bool
    {
        if (isset($asset['payload_reference'])) return true;
        if ( 'image/svg+xml' !== ($asset['mime_type'] ?? '') ) {
            return true;
        }

        return ! empty($asset['content']) && $this->isSafeSvgContent((string) $asset['content']);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isUnsafeSvgAsset(array $file): bool
    {
        return 'image/svg+xml' === ($file['mime_type'] ?? '') && ! $this->isSafeSvgContent((string) ($file['content'] ?? ''));
    }

    private function isSafeSvgContent(string $content): bool
    {
        if ( '' === trim($content) ) {
            return false;
        }

        if ( ! preg_match('/<svg(?:\s|>)/i', $content) ) {
            return false;
        }

        return ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findAssetByHtmlReference(string $reference, string $entryPath, array $files): ?array
    {
        if ( '' === trim($reference) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference) ) {
            return null;
        }

        $path = $this->resolveHtmlReferencePath($reference, $entryPath);
        if ( '' === $path ) {
            return null;
        }

        if ( isset($this->filesByPath[$path]) ) {
            return $this->filesByPath[$path];
        }

        foreach ( $files as $file ) {
            if ( $path === ($file['path'] ?? '') ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Build immutable lookup state for one normalized artifact compilation.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function indexFiles(array $files): void
    {
        $this->filesByPath = array();
        $this->imageFiles = array();
        $this->scriptContents = array();
        $this->scriptDomSelectorCache = array();
        $this->scriptControlSelectorCache = array();

        foreach ( $files as $file ) {
            if ( ! is_array($file) ) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ( '' !== $path ) {
                $this->filesByPath[$path] = $file;
            }
            if ( str_starts_with((string) ($file['mime_type'] ?? ''), 'image/') && ! $this->isMaterializedHtmlDocument($file) ) {
                $this->imageFiles[] = $file;
            }
            if ( $this->isMaterializedScriptAsset($file) && is_string($file['content'] ?? null) ) {
                $this->scriptContents[] = (string) $file['content'];
            }
        }
    }
    private function resolveHtmlReferencePath(string $reference, string $entryPath): string
    {
        return ArtifactPath::resolveRelativePath($reference, $entryPath);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceDocumentComponents
     * @return array<int, array<string, mixed>>
     */
    private function detectComponents(array $files, string $entryPath, array $sourceDocumentComponents = array()): array
    {
        $components = array();
        $classes = array();
        foreach ( $sourceDocumentComponents as $component ) {
            $key = 'mdx:' . (string) ($component['source'] ?? '') . ':' . (string) ($component['name'] ?? '');
            $components[$key] = $component;
        }

        foreach ( $files as $file ) {
            if ( in_array($file['kind'], array('jsx', 'tsx'), true) && empty($file['binary']) ) {
                foreach ( $this->detectJsxFileComponents($file) as $component ) {
                    $components['jsx-file:' . (string) $component['source'] . ':' . (string) $component['name']] = $component;
                }
            }

            if ( 'html' !== $file['kind'] || ! empty($file['binary']) ) {
                continue;
            }

            $content = (string) $file['content'];
            if ( preg_match_all('/data-component\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $name ) {
                    $key = $this->sanitizeKey($name);
                    if ( '' === $key ) {
                        continue;
                    }
                    $components['explicit:' . $key] = array(
                        'name'        => $key,
                        'source'      => $file['path'],
                        'signal'      => 'data-component',
                        'occurrences' => ($components['explicit:' . $key]['occurrences'] ?? 0) + 1,
                        'provenance'  => array('source_path' => $file['path']),
                    );
                }
            }

            if ( preg_match_all('/class\s*=\s*(["\'])([^"\']+)\1/i', $content, $matches) ) {
                foreach ( $matches[2] as $classList ) {
                    $classTokens = preg_split('/\s+/', trim($classList));
                    foreach ( false === $classTokens ? array() : $classTokens as $class ) {
                        $class = $this->sanitizeKey($class);
                        if ( '' === $class || strlen($class) < 3 ) {
                            continue;
                        }
                        $classes[$class] = ($classes[$class] ?? 0) + 1;
                    }
                }
            }
        }

        foreach ( $classes as $class => $count ) {
            if ( $count < 2 && ! preg_match('/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class) ) {
                continue;
            }

            $components['class:' . $class] = array(
                'name'        => $class,
                'source'      => $entryPath,
                'signal'      => 'class-token',
                'occurrences' => $count,
                'provenance'  => array('source_path' => $entryPath),
            );
        }

        usort(
            $components,
            static function (array $left, array $right): int {
                $occurrenceComparison = ($right['occurrences'] ?? 1) <=> ($left['occurrences'] ?? 1);
                return 0 !== $occurrenceComparison ? $occurrenceComparison : strcmp((string) $left['name'], (string) $right['name']);
            }
        );

        return array_slice($components, 0, 25);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<int, array<string, mixed>>
     */
    private function detectJsxFileComponents(array $file): array
    {
        $components = array();
        $content = (string) ($file['content'] ?? '');

        if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $components[$name] = true;
            }
        }

        return array_map(
            fn (string $name): array => array(
                'name'        => $name,
                'source'      => (string) ($file['path'] ?? ''),
                'signal'      => 'jsx-component-file',
                'occurrences' => 1,
                'provenance'  => array('source_path' => (string) ($file['path'] ?? '')),
            ),
            array_keys($components)
        );
    }

    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    private function parseFrontmatter(string $content): array
    {
        if ( ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) ) {
            return array(
                'frontmatter' => array(),
                'body'        => $content,
            );
        }

        $frontmatter = array();
        $lines = preg_split('/\R/', trim($matches[1]));
        foreach ( false === $lines ? array() : $lines as $line ) {
            if ( ! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair) ) {
                continue;
            }

            $value = trim($pair[2], " \t\n\r\0\x0B\"'");
            if ( preg_match('/^\[(.*)\]$/', $value, $list) ) {
                $value = array_values(array_filter(array_map(static fn (string $item): string => trim($item, " \t\n\r\0\x0B\"'"), explode(',', $list[1])), static fn (string $item): bool => '' !== $item));
            }

            $frontmatter[$this->sanitizeKey($pair[1])] = $value;
        }

        return array(
            'frontmatter' => $frontmatter,
            'body'        => substr($content, strlen($matches[0])),
        );
    }

    /**
     * @param array<string, mixed> $file
     * @param array{files: array<int, array<string, mixed>>} $artifact
     * @return array{markdown_body: string, components: array<int, array<string, mixed>>, diagnostics: array<int, array<string, mixed>>}
     */
    private function extractMdxSemantics(string $body, array $file, array $artifact): array
    {
        $imports = $this->extractMdxImports($body);
        $components = array();
        $diagnostics = array();
        $sourcePath = (string) $file['path'];

        if ( preg_match_all('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches) ) {
            foreach ( $matches[1] as $name ) {
                $import = $imports[$name] ?? null;
                $resolved = is_array($import) ? $this->resolveComponentImport((string) $import['path'], $sourcePath, $artifact) : '';
                $component = array(
                    'name'        => $name,
                    'source'      => $sourcePath,
                    'signal'      => 'mdx-jsx',
                    'occurrences' => ($components[$name]['occurrences'] ?? 0) + 1,
                    'provenance'  => array('source_path' => $sourcePath),
                );

                if ( is_array($import) ) {
                    $component['import_path'] = $import['path'];
                }
                if ( '' !== $resolved ) {
                    $component['resolved_path'] = $resolved;
                }

                $components[$name] = $component;

                if ( ! is_array($import) ) {
                    $diagnostics[] = $this->diagnostic('mdx_component_unresolved', 'warning', 'MDX component reference has no matching import.', array('path' => $sourcePath, 'component' => $name));
                } elseif ( '' === $resolved && str_starts_with((string) $import['path'], '.') ) {
                    $diagnostics[] = $this->diagnostic('mdx_import_unresolved', 'warning', 'MDX component import could not be linked to a generated source file.', array('path' => $sourcePath, 'component' => $name, 'import_path' => $import['path']));
                }
            }
        }

        $markdownBody = preg_replace('/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body) ?? $body;
        $markdownBody = preg_replace('/^\s*export\s+[^\r\n]+\s*$/m', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdownBody) ?? $markdownBody;
        $markdownBody = preg_replace('/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdownBody) ?? $markdownBody;

        return array(
            'markdown_body' => trim($markdownBody),
            'components'    => array_values($components),
            'diagnostics'   => $this->dedupeDiagnostics($diagnostics),
        );
    }

    /**
     * @return array<string, array{path: string}>
     */
    private function extractMdxImports(string $body): array
    {
        $imports = array();
        if ( ! preg_match_all('/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER) ) {
            return $imports;
        }

        foreach ( $matches as $match ) {
            $clause = trim($match[1]);
            $path = $match[2];
            if ( preg_match('/^([A-Z][A-Za-z0-9_]*)/', $clause, $default) ) {
                $imports[$default[1]] = array('path' => $path);
            }
            if ( preg_match('/\{([^}]+)\}/', $clause, $named) ) {
                foreach ( explode(',', $named[1]) as $name ) {
                    $parts = preg_split('/\s+as\s+/i', trim($name));
                    $alias = trim((string) end($parts));
                    if ( preg_match('/^[A-Z][A-Za-z0-9_]*$/', $alias) ) {
                        $imports[$alias] = array('path' => $path);
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>} $artifact
     */
    private function resolveComponentImport(string $importPath, string $sourcePath, array $artifact): string
    {
        if ( ! str_starts_with($importPath, '.') ) {
            return '';
        }

        $path = ArtifactPath::resolveRelativePath($importPath, $sourcePath, true);
        if ( '' === $path ) {
            return '';
        }

        $candidates = array($path);
        foreach ( array('js', 'jsx', 'ts', 'tsx', 'mdx') as $extension ) {
            $candidates[] = $path . '.' . $extension;
            $candidates[] = $path . '/index.' . $extension;
        }

        foreach ( $artifact['files'] as $file ) {
            if ( in_array($file['path'], $candidates, true) ) {
                return (string) $file['path'];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @param array<int, string> $keys
     */
    private function frontmatterString(array $frontmatter, array $keys, string $fallback): string
    {
        foreach ( $keys as $key ) {
            if ( isset($frontmatter[$key]) && is_scalar($frontmatter[$key]) && '' !== trim((string) $frontmatter[$key]) ) {
                return (string) $frontmatter[$key];
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    private function frontmatterTaxonomies(array $frontmatter): array
    {
        $taxonomies = array();
        foreach ( array('category', 'categories', 'tag', 'tags') as $key ) {
            if ( isset($frontmatter[$key]) ) {
                $taxonomies[$key] = $frontmatter[$key];
            }
        }

        return $taxonomies;
    }

    private function slugFromPath(string $path): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
        $base = '' === $base || null === $base ? 'document' : $base;
        return $this->sanitizeKey(str_replace(array('_', '.'), '-', $base));
    }

    private function titleFromPath(string $path): string
    {
        return ucwords(str_replace('-', ' ', $this->slugFromPath($path)));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function detectBlockTypes(array $files, array &$diagnostics): array
    {
        $blockTypes = array();
        $blockRoots = array();

        foreach ( $files as $file ) {
            if ( 'block.json' !== basename((string) $file['path']) ) {
                continue;
            }
            $directory = dirname((string) $file['path']);
            $directory = '.' === $directory ? '' : $directory;
            $blockRoots[$directory] = $file;
        }

        foreach ( $blockRoots as $directory => $blockJsonFile ) {
            $blockJson = json_decode((string) $blockJsonFile['content'], true);
            if ( ! is_array($blockJson) ) {
                $blockJson = array();
                $diagnostics[] = $this->diagnostic('invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array('path' => $blockJsonFile['path']));
            }

            $name = isset($blockJson['name']) && is_string($blockJson['name']) ? trim($blockJson['name']) : '';
            if ( '' === $name ) {
                $name = 'generated/' . ('' === $directory ? 'block' : $this->sanitizeKey(basename($directory)));
                $diagnostics[] = $this->diagnostic('block_json_missing_name', 'warning', 'A generated block.json file did not declare a name; a stable generated name was assigned.', array('path' => $blockJsonFile['path'], 'name' => $name));
            }

            $blockFiles = $this->filesUnderDirectory($files, $directory);
            $blockTypes[] = array(
                'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
                'name'            => $name,
                'slug'            => $this->sanitizeKey(basename($name)),
                'directory'       => $directory,
                'block_json_path' => $blockJsonFile['path'],
                'block_json'      => $blockJson,
                'metadata'        => $this->blockMetadataContract($blockJson),
                'assets'          => $this->blockAssetContract($blockJson, $blockFiles),
                'dependencies'    => $this->blockDependencyContract($blockJson, $blockFiles),
                'provenance'      => array(
                    'source'      => $blockJsonFile['source'] ?? 'artifact',
                    'source_hash' => hash('sha256', $this->fileHashPayload($blockFiles)),
                    'files'       => array_values(array_map(static fn (array $file): string => (string) $file['path'], $blockFiles)),
                ),
                'files'           => array_values(
                    array_map(
                        static fn (array $file): array => array(
                            'path'  => $file['path'],
                            'kind'  => $file['kind'],
                            'bytes' => $file['bytes'],
                        ),
                        $blockFiles
                    )
                ),
            );
        }

        usort(
            $blockTypes,
            static fn (array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name'])
        );

        return $blockTypes;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function filesUnderDirectory(array $files, string $directory): array
    {
        $matched = array();
        $prefix = '' === $directory ? '' : $directory . '/';
        foreach ( $files as $file ) {
            if ( '' === $prefix || str_starts_with((string) $file['path'], $prefix) ) {
                $matched[] = $file;
            }
        }

        return $matched;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @return array<string, mixed>
     */
    private function blockMetadataContract(array $blockJson): array
    {
        $metadata = array();
        foreach ( array('apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks') as $key ) {
            if ( array_key_exists($key, $blockJson) ) {
                $metadata[$key] = $blockJson[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function blockAssetContract(array $blockJson, array $files): array
    {
        $assets = array(
            'render'        => array(),
            'editor_script' => array(),
            'script'        => array(),
            'view_script'   => array(),
            'editor_style'  => array(),
            'style'         => array(),
            'view_style'    => array(),
        );

        foreach ( array(
            'render'       => 'render',
            'editorScript' => 'editor_script',
            'script'       => 'script',
            'viewScript'   => 'view_script',
            'editorStyle'  => 'editor_style',
            'style'        => 'style',
            'viewStyle'    => 'view_style',
        ) as $sourceField => $targetField ) {
            foreach ( $this->normalizeAssetReferences($blockJson[$sourceField] ?? null, $files, $sourceField) as $reference ) {
                $assets[$targetField][] = $reference;
            }
        }

        return $assets;
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetReferences(mixed $value, array $files, string $sourceField): array
    {
        $references = array();
        $values = is_array($value) ? array_values($value) : array($value);
        foreach ( $values as $item ) {
            if ( ! is_string($item) || '' === trim($item) ) {
                continue;
            }

            $item = trim($item);
            $isFileRef = str_starts_with($item, 'file:');
            $file = $isFileRef ? $this->findBlockFileByRelativePath($files, substr($item, 5)) : null;

            $reference = array(
                'reference'    => $item,
                'source_field' => $sourceField,
                'type'         => $isFileRef ? 'file' : 'handle',
            );
            if ( is_array($file) ) {
                $reference['path'] = $file['path'];
                $reference['kind'] = $file['kind'];
                $reference['bytes'] = $file['bytes'];
            }

            $references[] = $reference;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $blockJson
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function blockDependencyContract(array $blockJson, array $files): array
    {
        $declared = array();
        foreach ( array('editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle') as $field ) {
            if ( array_key_exists($field, $blockJson) ) {
                $declared[$field] = $blockJson[$field];
            }
        }

        $assetFiles = array();
        foreach ( $files as $file ) {
            if ( ! str_ends_with((string) $file['path'], '.asset.php') ) {
                continue;
            }

            $assetFile = array(
                'path'  => $file['path'],
                'kind'  => $file['kind'],
                'bytes' => $file['bytes'],
            );
            $parsed = $this->parseAssetPhpManifest((string) ($file['content'] ?? ''));
            if ( array() !== $parsed ) {
                $assetFile['manifest'] = $parsed;
            }
            $assetFiles[] = $assetFile;
        }

        return array(
            'declared'    => $declared,
            'asset_files' => $assetFiles,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAssetPhpManifest(string $content): array
    {
        $manifest = array();
        if ( preg_match('/["\']version["\']\s*=>\s*["\']([^"\']+)["\']/', $content, $version) ) {
            $manifest['version'] = $version[1];
        }
        if ( preg_match('/["\']dependencies["\']\s*=>\s*array\s*\((.*?)\)/s', $content, $dependencies) && preg_match_all('/["\']([^"\']+)["\']/', $dependencies[1], $matches) ) {
            $manifest['dependencies'] = array_values($matches[1]);
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>|null
     */
    private function findBlockFileByRelativePath(array $files, string $relativePath): ?array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), './');
        foreach ( $files as $file ) {
            if ( basename((string) $file['path']) === $relativePath || str_ends_with((string) $file['path'], '/' . $relativePath) ) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<string, int>
     */
    private function countBy(array $files, string $field): array
    {
        $counts = array();
        foreach ( $files as $file ) {
            $value = (string) ($file[$field] ?? '');
            if ( '' === $value ) {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function dedupeDiagnostics(array $diagnostics): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $diagnostics as $diagnostic ) {
            $key = json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: serialize($diagnostic);
            if ( isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $diagnostic;
        }

        return $deduped;
    }

    /**
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function statusFromDiagnostics(array $diagnostics): string
    {
        $warningDiagnostics = array();
        foreach ( $diagnostics as $diagnostic ) {
            if ( 'error' === ($diagnostic['severity'] ?? '') ) {
                return 'failed';
            }

            if ( 'preserved_runtime_island' === ($diagnostic['code'] ?? '') ) {
                continue;
            }

            $warningDiagnostics[] = $diagnostic;
        }
        return array() === $warningDiagnostics ? 'success' : 'success_with_warnings';
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $severity, string $message, array $context = array()): array
    {
        return array_filter(
            array(
                'code'     => $code,
                'severity' => $severity,
                'message'  => $message,
                'source'   => self::class,
                'context'  => $context,
            ),
            static fn (mixed $value): bool => array() !== $value
        );
    }
}
