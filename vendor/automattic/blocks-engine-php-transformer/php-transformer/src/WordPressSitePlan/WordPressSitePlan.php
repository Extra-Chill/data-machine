<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use InvalidArgumentException;

/** A complete, destination-independent block-theme materialization contract. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
    public const TOKEN_PREFIX = '{{wordpress-site-plan:asset:';

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        $compiled = $data['source_reports']['compiled_site'] ?? null;
        $materialization = $data['source_reports']['materialization_plan'] ?? null;
        if ( ! is_array($compiled) || ! is_array($materialization) ) {
            throw new InvalidArgumentException('WordPress site plan requires compiled-site and materialization-plan reports.');
        }

        $assets = $this->assets($compiled['assets'] ?? null);
        $runtimeDeclarations = $compiled['runtime_declarations'] ?? array();
        $assets = $this->applyDeclaredAssetTransformations($assets, $runtimeDeclarations);
        $tokens = $this->tokens($assets);
        $documents = $this->decideDocuments($compiled['pages'] ?? null);
        $routeMap = $this->canonicalRoutes($documents, is_array($materialization['routes'] ?? null) ? $materialization['routes'] : array());
        $references = new AssetReferenceCanonicalizer($tokens, self::entryRootFromDocuments($documents));
        $pages = $this->documents($documents, false, $tokens, $references, $routeMap);
        // Restore the semantic shell candidates before deriving binding positions.
        // Extracted parts intentionally contain only their inner markup; the page
        // representation owns the landmark wrapper until extraction is accepted.
        foreach ($pages as &$page) {
            foreach ($page['shell_candidates'] ?? array() as $candidate) {
                $restored = $this->replaceTopLevelShell($page['canonical_block_markup'], (string) ($candidate['area'] ?? ''), (string) ($candidate['markup'] ?? ''));
                if (null !== $restored) $page['canonical_block_markup'] = $restored;
            }
            $page['content_hash'] = self::contentHash($page['canonical_block_markup']);
        }
        unset($page);
        // Bindings anchor on the final canonical page markup before any shell
        // extraction. Asset and route projection can make source anchors equal,
        // so assign occurrences only after that shared projection is complete.
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $references, $routeMap, $pages);
        $pages = $this->pageHierarchy($pages, $routeMap);
        $assets = $this->scopeAssets($assets, $pages);
        $routes = $this->routesForPages($pages);
        // Entry shells remain in compiled-site/v1 for existing consumers; the
        // canonical plan rebuilds them from full page shell candidates.
        $compiledParts = is_array($compiled['template_parts'] ?? null) ? array_values(array_filter($compiled['template_parts'], static fn(mixed $part): bool => !is_array($part) || 'entry_shell' !== ($part['placement']['kind'] ?? null))) : null;
        $existingParts = $this->documents($compiledParts, true, $tokens, $references, $routeMap);
        $shells = $this->sharedShells($pages, array_fill_keys(array_column($existingParts, 'slug'), true), $runtimeDeclarations);
        $pages = $shells['pages'];
        $parts = array_merge($existingParts, $shells['parts']);
        $runtimeDeclarations = $shells['runtime_declarations'];
        $runtimeDeclarations = $this->canonicalEntityBindings($runtimeDeclarations, $references, $routeMap, $pages);
        foreach ($pages as &$page) unset($page['_projected_source_block_markup']); unset($page);
        self::assertEntityBindingsRemainPageOwned($runtimeDeclarations, $pages, $assets);
        $templates = $this->templates($pages, $parts);
        $operations = $this->operations($pages);
        $scriptLoading = $this->scriptLoading($pages, $parts, $assets, $tokens, $operations, $runtimeDeclarations);
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts, $scriptLoading['scripts']), $this->assetWrites($assets, $references));
        $plan = array(
            'schema' => self::SCHEMA,
            'source' => array('schema' => $compiled['schema'] ?? null, 'source_hash' => $compiled['source_hash'] ?? null, 'entry_path' => $compiled['entry_path'] ?? null, 'provenance' => $data['provenance']),
            'pages' => $pages,
            'templates' => $templates,
            'template_parts' => $parts,
            'assets' => $assets,
            'reference_tokens' => $tokens,
            'reference_semantics' => array('static_browser_references' => 'declared_tokens_only', 'dynamic_script_references' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'dynamic_client_assets' => array('status' => array() === $scriptLoading['diagnostics'] ? 'proven' : 'not_proven', 'materializer_may_reject' => array() !== $scriptLoading['diagnostics'])),
            'writes' => $writes,
            'operations' => $operations,
            'routes' => $routes,
            'navigation_links' => $materialization['navigation_links'] ?? null,
            'menus' => $materialization['menus'] ?? null,
            'theme' => array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => self::needsBootstrap($assets, $scriptLoading['scripts']) ? 'functions.php' : null),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'runtime_declarations' => $runtimeDeclarations,
            'diagnostics' => array_merge($data['diagnostics'], $shells['diagnostics'], $scriptLoading['diagnostics']),
            'quality' => array('status' => $data['status'], 'pass' => 'failed' !== $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks'], 'core_html_fallback_evidence' => $data['source_reports']['conversion_report']['core_html_fallback_evidence'] ?? array()),
            'reporting' => $this->reporting($pages, $data, array_merge($shells['diagnostics'], $scriptLoading['diagnostics'])),
        );
        self::assertValid($plan);
        return $plan;
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $assets */
    private static function assertEntityBindingsRemainPageOwned(array $declarations, array $pages, array $assets): void
    {
        $markupBySource = array();
        foreach ($pages as $page) if (is_string($page['source_path'] ?? null) && is_string($page['canonical_block_markup'] ?? null)) $markupBySource[$page['source_path']] = is_string($page['resolved_block_markup'] ?? null) ? $page['resolved_block_markup'] : $page['canonical_block_markup'];
        $assetsBySource = array_column($assets, null, 'source_path');
        $scriptsBySource = array();
        foreach ( $pages as $page ) foreach ( $page['document_metadata']['scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['selector'] ?? null) ) $scriptsBySource[$page['source_path'] . "\n" . $script['selector']] = $script;
        foreach ( $declarations as $declaration ) {
            foreach ( $declaration['payload']['entities'] ?? array() as $entity ) {
                $bindings = is_array($entity) && is_array($entity['bindings'] ?? null) ? $entity['bindings'] : array();
                $bindingSources = array_fill_keys(array_filter(array_column($bindings, 'source_path'), 'is_string'), true);
                foreach ( $bindings as $binding ) {
                    $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $occurrence = $binding['occurrence'] ?? null;
                    $ownedMarkup = $markupBySource[$source] ?? null;
                    $position = $binding['position'] ?? null;
                    $offset = is_string($ownedMarkup) && is_string($search) && is_int($occurrence) ? self::occurrenceOffset($ownedMarkup, $search, $occurrence) : null;
                    if ( !is_string($source) || !is_string($search) || '' === $search || !is_int($occurrence) || $occurrence < 1 || !is_string($ownedMarkup) || null === $offset || (null !== $position && (!self::bindingPosition($position, $ownedMarkup, $search) || $position['offset'] !== $offset)) ) throw new InvalidArgumentException('A runtime entity binding no longer has its declared source-page block anchor after shell extraction: ' . (is_string($source) ? $source : 'unknown') . ' (' . (is_string($binding['role'] ?? null) ? $binding['role'] : 'unknown') . ').');
                }
                $formId = is_array($entity) && is_array($entity['form'] ?? null) && is_string($entity['form']['id'] ?? null) ? $entity['form']['id'] : '';
                foreach ( is_array($entity) && is_array($entity['superseded_scripts'] ?? null) ? $entity['superseded_scripts'] : array() as $supersession ) {
                    if ( !is_array($supersession) || array('asset_source_path','body_hash','reason','schema','selector','source_path','target_selector') !== array_keys($supersession) || 'blocks-engine/provider-script-supersession/v1' !== $supersession['schema'] || !isset($bindingSources[$supersession['source_path']]) || !preg_match('/^script:nth-of-type\([1-9][0-9]*\)$/', $supersession['selector']) || !self::safePath($supersession['asset_source_path']) || !self::hash($supersession['body_hash']) || '#' . $formId !== $supersession['target_selector'] || 'provider_binding_replaces_form_behavior' !== $supersession['reason'] ) throw new InvalidArgumentException('A provider script supersession proof is malformed or detached from its bound form.');
                    $script = $scriptsBySource[$supersession['source_path'] . "\n" . $supersession['selector']] ?? null;
                    $asset = $assetsBySource[$supersession['asset_source_path']] ?? null;
                    $assetReference = is_array($asset) && is_string($asset['token'] ?? null) ? '{{wordpress-site-plan:asset:' . $asset['token'] . '}}' : null;
                    if ( !is_array($script) || ('inline' !== ($script['source_kind'] ?? null) && $assetReference !== ($script['asset_reference'] ?? null)) || $supersession['body_hash'] !== ($script['body_hash'] ?? null) || $supersession['target_selector'] !== ($script['superseded_by'] ?? null) || !is_array($asset) || 'inline-script' !== ($asset['source'] ?? null) || !is_string($asset['content'] ?? null) || $supersession['body_hash'] !== hash('sha256', trim($asset['content'])) || $supersession['body_hash'] !== ($asset['hash'] ?? null) ) throw new InvalidArgumentException('A provider script supersession proof does not match its source inline-script asset and document metadata.');
                }
            }
        }
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'reference_semantics', 'writes', 'operations', 'routes', 'navigation_links', 'menus', 'theme', 'visual_repair', 'runtime_declarations', 'diagnostics', 'quality', 'reporting') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        self::assertSource($plan['source']);
        RuntimeDeclarations::assertNormalized($plan['runtime_declarations']);
        self::assertEntityBindingsRemainPageOwned($plan['runtime_declarations'], $plan['pages'], $plan['assets']);
        if ('declared_tokens_only' !== ($plan['reference_semantics']['static_browser_references'] ?? null) || !in_array($plan['reference_semantics']['dynamic_script_references'] ?? null, array('proven', 'not_proven'), true) || !is_array($plan['reference_semantics']['dynamic_client_assets'] ?? null) || !in_array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, array('proven', 'not_proven'), true) || !is_bool($plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'] ?? null) || ($plan['reference_semantics']['dynamic_script_references'] ?? null) !== ($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null) || ('proven' === $plan['reference_semantics']['dynamic_client_assets']['status'] && true === $plan['reference_semantics']['dynamic_client_assets']['materializer_may_reject'])) throw new InvalidArgumentException('WordPress site plan reference capability semantics are invalid.');
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        $assetTargets = array();
        $assetTokens = array();
        $assetIdentities = array();
        $assetMimeTypes = array();
        foreach ( $plan['assets'] as $asset ) {
            $assetContent = is_array($asset) ? (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : ($asset['content'] ?? null)) : null;
            $reference = is_array($asset['payload_reference'] ?? null) ? $asset['payload_reference'] : null;
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || !is_string($asset['source'] ?? null) || !is_string($asset['role'] ?? null) || !is_string($asset['mime_type'] ?? null) || !is_int($asset['bytes'] ?? null) || $asset['bytes'] < 0 || !is_string($asset['token'] ?? null) || !self::hash($asset['reconciliation_identity'] ?? null) || !self::hash($asset['content_hash'] ?? null) || (!is_string($assetContent) && !self::payloadReference($reference)) || $asset['reconciliation_identity'] !== self::identity('asset', $asset['source_path'], $asset['target_path']) || (is_string($assetContent) && $asset['content_hash'] !== self::contentHash($assetContent)) || (is_string($asset['content_base64'] ?? null) && ($asset['transport_sha256'] ?? null) !== $asset['content_hash']) || (is_array($reference) && (!self::referenceBackedBinaryAsset($asset) || isset($asset['content'], $asset['content_base64'], $asset['transport_sha256']) || $asset['content_hash'] !== $reference['sha256'] || ($asset['raw_sha256'] ?? null) !== $reference['sha256']) ) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            if ('css' === $asset['kind']) self::assertAssetScopes($asset['scopes'] ?? null);
            elseif (isset($asset['scopes'])) throw new InvalidArgumentException('Only stylesheet assets may declare runtime scopes.');
            self::unique($assetTargets, $asset['target_path'], 'asset target');
            self::unique($assetIdentities, $asset['reconciliation_identity'], 'asset reconciliation identity');
            $assetTokens[strtolower($asset['target_path'])] = $asset['token'];
            $assetMimeTypes[$asset['target_path']] = $asset['mime_type'];
        }
        $tokens = array();
        foreach ( $plan['reference_tokens'] as $reference ) {
            if ( ! is_array($reference) || ! is_string($reference['token'] ?? null) || ! self::safePath($reference['source_path'] ?? null) || ! self::safePath($reference['target_path'] ?? null) || ! isset($assetTargets[strtolower($reference['target_path'])]) || $assetTokens[strtolower($reference['target_path'])] !== $reference['token'] || ! preg_match('/^asset-[a-f0-9]{16}$/', $reference['token']) ) {
                throw new InvalidArgumentException('WordPress site plan has an invalid reference token declaration.');
            }
            self::unique($tokens, $reference['token'], 'reference token');
        }
        if ( count($tokens) !== count($assetTargets) ) {
            throw new InvalidArgumentException('WordPress site plan must declare exactly one token for each asset.');
        }
        $partSlugs = array();
        $overrideTemplateSlugs = array();
        foreach ($plan['template_parts'] as $part) foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (is_string($slug)) $overrideTemplateSlugs[$slug] = true;
        foreach ( $plan['template_parts'] as $part ) {
            self::assertDocument($part, 'template part', true, $tokens);
            if ($part['content_hash'] !== self::contentHash($part['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan template part has a stale content hash.');
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array(); $pagesBySource = array(); $documentIdentities = array();
        $entryRoot = self::entryRootFromDocuments($plan['pages']);
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false, $tokens);
            if ($page['content_hash'] !== self::contentHash($page['canonical_block_markup'])) throw new InvalidArgumentException('WordPress site plan page has a stale content hash.');
            self::assertRoute($page, $entryRoot);
            self::unique($pagePaths, $page['source_path'], 'page source');
            self::unique($documentIdentities, $page['reconciliation_identity'], 'page reconciliation identity');
            $pagesBySource[$page['source_path']] = $page;
        }
        foreach ($plan['assets'] as $asset) foreach ($asset['scopes'] ?? array() as $scope) if ('global' !== $scope['kind']) {
            $page = $pagesBySource[$scope['source_path']] ?? null;
            if (!is_array($page) || $scope['kind'] !== ('post' === $page['post_type'] ? 'post' : 'page') || $scope['route_path'] !== trim($page['route']['path'], '/') || $scope['reconciliation_identity'] !== $page['reconciliation_identity'] || $scope['front_page'] !== ('/' === $page['route']['path'])) throw new InvalidArgumentException('A page asset scope does not match its canonical page.');
        }
        $routeSources = array(); foreach ($plan['routes'] as $route) { self::unique($routeSources, $route['source_path'], 'route source'); $page = $pagesBySource[$route['source_path']] ?? null; if (!is_array($page) || $route['target_path'] !== $page['route']['path'] || $route['target_slug'] !== $page['slug']) throw new InvalidArgumentException('WordPress site plan routes do not match canonical page routes.'); }
        if (count($routeSources) !== count($pagePaths)) throw new InvalidArgumentException('WordPress site plan must export every canonical page route.');
        self::assertReporting($plan['reporting'], $pagePaths, $tokens, $plan['diagnostics']);
        self::assertOperations($plan['operations'], $plan['pages']);
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) || !self::hash($template['reconciliation_identity'] ?? null) || !self::hash($template['content_hash'] ?? null) || $template['reconciliation_identity'] !== self::identity('template', 'wordpress-site-plan/' . $template['target_path'], $template['target_path']) || $template['content_hash'] !== self::contentHash($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
            self::assertNoLocalBrowserReferences($template['canonical_block_markup']);
        }
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            $mimeType = is_array($write) ? ($assetMimeTypes[$write['target_path'] ?? ''] ?? null) : null;
            self::assertWrite($write, $tokens, null === $mimeType || in_array($mimeType, array('text/css', 'text/html', 'image/svg+xml'), true));
            self::unique($writeTargets, $write['target_path'], 'write target');
            $writesByTarget[$write['target_path']] = $write;
        }
        self::assertResolution($plan, $tokens, $writesByTarget);
        self::assertScaffold($plan, $writesByTarget);
        foreach ( $plan['templates'] as $template ) {
            $write = $writesByTarget[$template['target_path']] ?? null;
            $expected = isset($plan['resolution']) ? $template['resolved_block_markup'] : $template['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template lacks its canonical write.');
            }
        }
        foreach ( $plan['template_parts'] as $part ) {
            $target = 'parts/' . $part['slug'] . '.html';
            $write = $writesByTarget[$target] ?? null;
            $expected = isset($plan['resolution']) ? $part['resolved_block_markup'] : $part['canonical_block_markup'];
            if ( ! is_array($write) || 'theme_template_part' !== ($write['kind'] ?? null) || $write['payload']['data'] !== $expected ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks its canonical write.');
            }
            $boundTemplates = in_array($part['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true) ? $part['placement']['template_slugs'] : array();
            foreach (array_keys($overrideTemplateSlugs) as $slug) if (!in_array($slug, $part['placement']['excluded_template_slugs'] ?? array(), true)) $boundTemplates[] = $slug;
            foreach ( $plan['templates'] as $template ) {
                $references = substr_count($template['canonical_block_markup'], '"slug":"' . $part['slug'] . '"');
                if (in_array($template['slug'], $boundTemplates, true) && 1 !== $references) throw new InvalidArgumentException('WordPress site plan template part binding is invalid.');
                if (!in_array($template['slug'], $boundTemplates, true) && 0 !== $references) throw new InvalidArgumentException('WordPress site plan has an unproven template part binding.');
            }
        }
        foreach ( $plan['assets'] as $asset ) {
            $target = $asset['target_path'];
            if ( ! isset($writesByTarget[$target]) || 'theme_asset' !== ($writesByTarget[$target]['kind'] ?? null) || $writesByTarget[$target]['source_path'] !== $asset['source_path'] ) {
                throw new InvalidArgumentException('WordPress site plan asset lacks a write.');
            }
            $write = $writesByTarget[$target];
            $assetReference = self::payloadReference($asset['payload_reference'] ?? null);
            $writeReference = self::payloadReference($write['payload']['reference'] ?? null);
            if ((null !== $assetReference) !== ('reference' === ($write['payload']['encoding'] ?? null)) || (null !== $assetReference && (null === $writeReference || RuntimeDeclarations::canonicalJson($assetReference) !== RuntimeDeclarations::canonicalJson($writeReference) || ($write['raw_sha256'] ?? null) !== $asset['raw_sha256']))) {
                throw new InvalidArgumentException('WordPress site plan reference asset and write do not match.');
            }
        }
        self::assertAssetPublicationDeclarations($plan['runtime_declarations'], $plan['assets'], $writesByTarget);
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        if ( !in_array($plan['quality']['status'] ?? null, array('success', 'success_with_warnings', 'failed'), true) || !is_bool($plan['quality']['pass'] ?? null) || ('failed' !== $plan['quality']['status']) !== $plan['quality']['pass'] || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) || !is_array($plan['quality']['core_html_fallback_evidence'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
    }

    /** @param mixed $documents @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, bool $part, array $tokens, AssetReferenceCanonicalizer $references, array $routes): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException('Compiled site documents must be an array.');
        }
        $rows = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) || ! self::safePath($document['source_path'] ?? null) || ! is_string($document['block_markup'] ?? null) || '' === trim($document['block_markup']) ) {
                throw new InvalidArgumentException('Compiled site document lacks a safe identity or block markup.');
            }
            $markup = $references->content($document['block_markup'], $document['source_path']);
            $canonical = $this->routeLinks($markup, $document['source_path'], $routes);
            $target = $part ? 'parts/' . self::value($document, 'slug') . '.html' : self::value($document, 'source_path');
            $row = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $part ? self::value($document, 'area', 'uncategorized') : null, 'placement' => $part && is_array($document['placement'] ?? null) ? $document['placement'] : ($part ? array('kind' => 'unbound') : null), 'canonical_block_markup' => $canonical, '_projected_source_block_markup' => $document['block_markup'], 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'document_metadata' => $this->documentMetadata($document, $references, $routes), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => self::identity($part ? 'template-part' : 'page', $document['source_path'], $target), 'content_hash' => self::contentHash($canonical));
            if (!$part && is_array($document['content_decision'] ?? null)) {
                $row['content_decision'] = $document['content_decision'];
                if (is_string($document['publication_timestamp'] ?? null)) $row['publication_timestamp'] = $document['publication_timestamp'];
            }
            if ( ! $part ) $row['shell_candidates'] = $this->shellCandidates($document, $references, $routes, $canonical);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $document @return array<int,array<string,mixed>> */
    private function shellCandidates(array $document, AssetReferenceCanonicalizer $references, array $routes, string $canonical): array
    {
        $candidates = array();
        foreach ($document['shell_artifacts'] ?? array() as $candidate) {
            if (!is_array($candidate) || !in_array($candidate['area'] ?? null, array('header', 'footer'), true) || !is_string($candidate['block_markup'] ?? null) || '' === trim($candidate['block_markup'])) continue;
            $markup = $this->routeLinks($references->content($candidate['block_markup'], self::value($document, 'source_path')), self::value($document, 'source_path'), $routes);
            $classes = array_values(array_filter($candidate['source_classes'] ?? array(), 'is_string'));
            sort($classes, SORT_STRING);
            $innerMarkup = is_string($candidate['inner_block_markup'] ?? null) ? $this->routeLinks($references->content($candidate['inner_block_markup'], self::value($document, 'source_path')), self::value($document, 'source_path'), $routes) : $markup;
            $templatePartMarkup = is_string($candidate['template_part_block_markup'] ?? null) ? $this->routeLinks($references->content($candidate['template_part_block_markup'], self::value($document, 'source_path')), self::value($document, 'source_path'), $routes) : $innerMarkup;
            $candidates[] = array('area' => $candidate['area'], 'markup' => $markup, 'inner_markup' => $innerMarkup, 'template_part_markup' => $templatePartMarkup, 'classes' => $classes, 'source_path' => self::value($document, 'source_path'), 'source_hash' => is_string($candidate['source_hash'] ?? null) ? $candidate['source_hash'] : '');
        }
        return array_merge($candidates, $this->nestedChromeCandidates($canonical, self::value($document, 'source_path')));
    }

    /** @return array<int,array<string,mixed>> */
    private function nestedChromeCandidates(string $markup, string $sourcePath): array
    {
        $topLevel = self::topLevelBlockRanges($markup);
        foreach ($topLevel as $index => $wrapperRange) {
            $wrapper = substr($markup, $wrapperRange['offset'], $wrapperRange['length']);
            $preceding = $topLevel[$index - 1] ?? null;
            $toggle = is_array($preceding) ? substr($markup, $preceding['offset'], $preceding['length']) : '';
            // The responsive core/navigation overlay supersedes the only allowed
            // sibling: its legacy authored checkbox toggle.
            if (count($topLevel) !== $index + 1 || (0 < $index && (1 !== $index || !self::isCheckboxBlock($toggle)))) continue;
            $children = self::directChildBlockRanges($wrapper);
            if (2 !== count($children) || !self::isGroupBlock($wrapper)) continue;
            $navigationChildren = array_values(array_filter($children, static fn(array $range): bool => str_contains(substr($wrapper, $range['offset'], $range['length']), '<!-- wp:navigation ')));
            if (1 !== count($navigationChildren)) continue;
            $chromeRange = $navigationChildren[0];
            $contentRange = current(array_filter($children, static fn(array $range): bool => $range !== $chromeRange));
            if (!is_array($contentRange) || !self::isGroupBlock(substr($wrapper, $contentRange['offset'], $contentRange['length']))) continue;
            $chrome = substr($wrapper, $chromeRange['offset'], $chromeRange['length']);
            $content = substr($wrapper, $contentRange['offset'], $contentRange['length']);
            $opening = self::blockOpeningMarkup($wrapper);
            if (null === $opening) continue;
            $contentOffset = $wrapperRange['offset'] + $contentRange['offset'];
            $identity = self::normalizeNestedChromeMarkup($chrome);
            if ('' === $identity) continue;
            return array(array('area' => 'header', 'markup' => $chrome, 'inner_markup' => $chrome, 'template_part_markup' => self::withoutCurrentNavigationState($chrome), 'identity_markup' => $identity, 'classes' => array(), 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $chrome), 'legacy_container_opening' => $opening, 'legacy_container_closing' => '<!-- /wp:group -->', 'legacy_content_markup' => $content, 'legacy_content_range' => array('offset' => $contentOffset, 'length' => $contentRange['length']), 'legacy_page_markup' => $markup));
        }
        return array();
    }

    /** @param array<int,array<string,mixed>> $pages @param array<string,true> $reservedSlugs @param array<int,array<string,mixed>> $runtimeDeclarations @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,runtime_declarations:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function sharedShells(array $pages, array $reservedSlugs = array(), array $runtimeDeclarations = array()): array
    {
        $parts = array(); $diagnostics = array();
        foreach (array('footer', 'header') as $area) {
            $candidates = array(); $clusters = array(); $excluded = array(); $overrides = array();
            $templateSlugs = array(); $excludedTemplateSlugs = array();
            if (isset($reservedSlugs[$area])) {
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell conflicts with an existing template part.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'existing_template_part'));
                continue;
            }
            $applicable = array_filter($pages, static fn(array $page): bool => empty($page['synthetic']));
            if (array() === $applicable) continue;
            foreach ($applicable as $index => $page) foreach ($page['shell_candidates'] ?? array() as $candidate) if ($area === ($candidate['area'] ?? null)) $candidates[$index][] = $candidate;
            foreach ($applicable as $index => $page) {
                $rows = $candidates[$index] ?? array();
                if (1 !== count($rows)) { $excluded[$index] = count($rows) > 1 ? 'multiple' : 'missing'; continue; }
                $candidate = $rows[0];
                $candidateIdentity = hash('sha256', $area . "\0" . json_encode($candidate['classes']) . "\0" . ($candidate['identity_markup'] ?? $candidate['markup']));
                $clusters[$candidateIdentity]['candidate'] = $candidate;
                $clusters[$candidateIdentity]['indexes'][] = $index;
            }
            uasort($clusters, static fn(array $left, array $right): int => count($right['indexes']) <=> count($left['indexes']) ?: strcmp($left['candidate']['source_path'], $right['candidate']['source_path']));
            $identity = array_key_first($clusters);
            $cluster = null === $identity ? null : $clusters[$identity];
            $runnerUp = array_values($clusters)[1] ?? null;
            if (!is_array($cluster) || (count($cluster['indexes']) < count($applicable) && (count($cluster['indexes']) < 2 || (is_array($runnerUp) && count($cluster['indexes']) === count($runnerUp['indexes']))))) {
                $reason = array() === $clusters ? 'incomplete' : 'non_equivalent';
                $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_' . ('incomplete' === $reason ? 'incomplete' : 'ambiguous'), 'severity' => 'info', 'message' => "{$area} shell candidates do not establish a dominant semantic cluster.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', $reason, $candidates));
                continue;
            }
            $first = $cluster['candidate'];
            foreach ($applicable as $index => $page) if (!in_array($index, $cluster['indexes'], true)) $excluded[$index] = isset($candidates[$index]) ? 'non_equivalent' : 'missing';
            $templateSlugs = count($cluster['indexes']) === count($applicable) ? array('index', 'page', 'front-page') : array('index');
            if (count($cluster['indexes']) !== count($applicable)) foreach ($applicable as $index => $page) {
                $selected = in_array($index, $cluster['indexes'], true);
                if (!empty($page['entrypoint'])) { if ($selected) $templateSlugs[] = 'front-page'; continue; }
                if ('post' === ($page['post_type'] ?? null)) { if ($selected) $templateSlugs[] = 'index'; } elseif ($selected) $templateSlugs[] = 'page';
                if (!$selected) {
                    $slug = 'page' === ($page['post_type'] ?? null) ? 'page-' . $page['slug'] : 'single-' . $page['post_type'] . '-' . $page['slug'];
                    if (isset($overrides[$slug])) {
                        $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'info', 'message' => "{$area} shell exclusions cannot be assigned distinct route templates.", 'area' => $area, 'provenance' => $this->shellProvenance($area, 'retained', 'route_template_ambiguous', $candidates));
                        continue 2;
                    }
                    $overrides[$slug] = $index;
                }
            }
            $templateSlugs = array_values(array_unique($templateSlugs));
            $excludedTemplateSlugs = array_keys($overrides ?? array());
            $withoutShells = array();
            $retainedForRuntimeBinding = false;
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $withoutShell = isset($candidate['legacy_content_markup'])
                    ? (($candidate['legacy_page_markup'] ?? null) === $page['canonical_block_markup'] ? $candidate['legacy_content_markup'] : null)
                    : $this->withoutTopLevelShell($page['canonical_block_markup'], $area, $candidate['markup']);
                if (null === $withoutShell) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_ambiguous', 'severity' => 'warning', 'message' => "{$area} shell candidate cannot be removed unambiguously from {$page['source_path']}.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'removal_ambiguous', $candidates));
                    continue 2;
                }
                if ( '' === trim($withoutShell) ) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_only_content', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because removing it would leave the page empty.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'only_content', $candidates));
                    continue 2;
                }
                $withoutShells[$index] = $withoutShell;
            }
            foreach ($cluster['indexes'] as $index) {
                $page = $pages[$index];
                $candidate = $candidates[$index][0];
                $legacyContentRange = $candidate['legacy_content_range'] ?? null;
                $range = $this->topLevelShellRange($page['canonical_block_markup'], $area, $candidate['markup']);
                $containsBinding = is_array($legacyContentRange)
                    ? $this->shellContainsRuntimeBindingOutsideRange($runtimeDeclarations, $page, $legacyContentRange['offset'], $legacyContentRange['length'])
                    : (is_array($range) && $this->shellContainsRuntimeBinding($runtimeDeclarations, $page, $range['offset'], $range['length']));
                if ($containsBinding) {
                    $diagnostics[] = array('code' => 'wordpress_site_plan_shell_retained_runtime_binding', 'severity' => 'info', 'message' => "{$area} shell remains page-owned because it contains a runtime entity binding anchor.", 'area' => $area, 'source_path' => $page['source_path'], 'provenance' => $this->shellProvenance($area, 'retained', 'runtime_binding', $candidates));
                    $retainedForRuntimeBinding = true;
                    break;
                }
            }
            if ($retainedForRuntimeBinding) continue;
            foreach ($withoutShells as $index => $withoutShell) {
                $pages[$index]['canonical_block_markup'] = $withoutShell;
                $pages[$index]['content_hash'] = self::contentHash($withoutShell);
            }
            foreach ($runtimeDeclarations as &$declaration) unset($declaration['reconciliation_identity'], $declaration['payload_hash'], $declaration['content_hash']); unset($declaration);
            $runtimeDeclarations = RuntimeDeclarations::normalizeList($runtimeDeclarations);
            $singlePage = 1 === count($applicable) && 1 === count($cluster['indexes']);
            $sourcePath = $singlePage ? $pages[array_key_first($applicable)]['source_path'] : 'wordpress-site-plan/shared/' . $area;
            $placement = $singlePage ? 'entry_shell' : 'shared_shell';
            if ($singlePage) $templateSlugs = array('front-page');
            $partMarkup = $first['template_part_markup'];
            $container = isset($first['legacy_container_opening']) ? array('opening' => $first['legacy_container_opening'], 'closing' => $first['legacy_container_closing']) : null;
            $parts[] = array('source_path' => $sourcePath . '#' . $area, 'slug' => $area, 'title' => ucfirst($area), 'post_type' => 'wp_template_part', 'parent_source_path' => '', 'entrypoint' => false, 'area' => $area, 'placement' => array_filter(array('kind' => $placement, 'source_path' => $sourcePath, 'template_slugs' => $templateSlugs, 'excluded_template_slugs' => $excludedTemplateSlugs, 'container' => $container), static fn(mixed $value): bool => array() !== $value && null !== $value), 'canonical_block_markup' => $partMarkup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $sourcePath . '#' . $area, 'kind' => 'template_part'), 'title' => ucfirst($area), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => $this->shellProvenance($area, 'extracted', 'canonical', $candidates, $identity), 'reconciliation_identity' => self::identity('template-part', $sourcePath . '#' . $area, 'parts/' . $area . '.html'), 'content_hash' => self::contentHash($partMarkup));
            $diagnostics[] = array('code' => $singlePage ? 'wordpress_site_plan_shell_entry_extracted' : 'wordpress_site_plan_shell_extracted', 'severity' => 'info', 'message' => $singlePage ? "Extracted the entry {$area} shell for the front-page template." : "Extracted the dominant semantically equivalent {$area} shell cluster.", 'area' => $area, 'page_count' => count($cluster['indexes']), 'applicable_page_count' => count($applicable), 'exclusions' => array_map(static fn(int $index, string $reason): array => array('source_path' => $pages[$index]['source_path'], 'reason' => $reason), array_keys($excluded), $excluded));
        }
        foreach ($pages as &$page) unset($page['shell_candidates']); unset($page);
        return array('pages' => $pages, 'parts' => $parts, 'runtime_declarations' => $runtimeDeclarations, 'diagnostics' => $diagnostics);
    }

    /** @param array<int,array<string,mixed>> $declarations @param array<string,mixed> $page */
    private function shellContainsRuntimeBinding(array $declarations, array $page, int $offset, int $length): bool
    {
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || !self::bindingPosition($position, $page['canonical_block_markup'], $search)) continue;
            $indexedRange = self::blockRanges($page['canonical_block_markup'])[$position['block_index']] ?? null;
            if (is_array($indexedRange) && $indexedRange['offset'] >= $offset && $indexedRange['offset'] + $indexedRange['length'] <= $offset + $length) return true;
        }
        return false;
    }

    private function shellContainsRuntimeBindingOutsideRange(array $declarations, array $page, int $offset, int $length): bool
    {
        foreach ($declarations as $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $binding) {
            $position = $binding['position'] ?? null;
            if (($binding['source_path'] ?? null) !== ($page['source_path'] ?? null)) continue;
            $search = $binding['search_block_markup'] ?? null;
            if (!is_string($search) || !self::bindingPosition($position, $page['canonical_block_markup'], $search)) continue;
            $range = self::blockRanges($page['canonical_block_markup'])[$position['block_index']] ?? null;
            if (is_array($range) && ($range['offset'] < $offset || $range['offset'] + $range['length'] > $offset + $length)) return true;
        }
        return false;
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function blockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $match) {
            $token = $match[0]; $offset = $match[1];
            if (str_starts_with($token, '<!-- /wp:')) { $open = array_pop($stack); if (is_array($open)) $ranges[$open['index']]['length'] = $offset + strlen($token) - $open['offset']; }
            elseif (str_ends_with(rtrim($token), '/-->')) $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            else { $index = count($ranges); $ranges[] = array('offset' => $offset, 'length' => 0); $stack[] = array('index' => $index, 'offset' => $offset); }
        }
        return array_values(array_filter($ranges, static fn(array $range): bool => 0 < $range['length']));
    }

    /** @param array<string,mixed>|mixed $position */
    private static function bindingPosition(mixed $position, string $markup, string $search): bool
    {
        if (!is_array($position) || 'blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1) return false;
        foreach (self::blockRanges($markup) as $index => $range) if ($index === $position['block_index'] && $range['offset'] === $position['offset'] && $range['length'] === $position['length'] && $search === substr($markup, $range['offset'], $range['length'])) return true;
        return false;
    }

    private static function occurrenceAtOffset(string $markup, string $search, int $offset): int
    {
        $occurrence = 0; $cursor = 0;
        while (false !== ($found = strpos($markup, $search, $cursor))) { ++$occurrence; if ($found === $offset) return $occurrence; $cursor = $found + strlen($search); }
        return 0;
    }

    private static function occurrenceOffset(string $markup, string $search, int $occurrence): ?int
    {
        if ('' === $search || $occurrence < 1) return null;
        $cursor = 0;
        for ($index = 0; $index < $occurrence; ++$index) { $cursor = strpos($markup, $search, $cursor); if (false === $cursor) return null; if ($index + 1 < $occurrence) $cursor += strlen($search); }
        return $cursor;
    }

    /** @param array<int,array<int,array<string,mixed>>> $candidates @return array<string,mixed> */
    private function shellProvenance(string $area, string $decision, string $reason, array $candidates = array(), ?string $identity = null): array
    {
        $sources = array();
        foreach ($candidates as $rows) foreach ($rows as $candidate) if (is_array($candidate) && is_string($candidate['source_path'] ?? null)) $sources[$candidate['source_path']] = is_string($candidate['source_hash'] ?? null) ? $candidate['source_hash'] : '';
        ksort($sources, SORT_STRING);
        return array_filter(array('schema' => 'blocks-engine/shell-extraction/v1', 'area' => $area, 'decision' => $decision, 'reason' => $reason, 'sources' => $sources, 'shell_identity' => $identity), static fn(mixed $value): bool => null !== $value);
    }

    private function withoutTopLevelShell(string $markup, string $area, string $candidateMarkup = ''): ?string
    {
        return $this->replaceTopLevelShell($markup, $area, '', $candidateMarkup);
    }

    private function replaceTopLevelShell(string $markup, string $area, string $replacement, string $candidateMarkup = ''): ?string
    {
        $range = $this->topLevelShellRange($markup, $area, $candidateMarkup);
        return is_array($range) ? substr($markup, 0, $range['offset']) . $replacement . substr($markup, $range['offset'] + $range['length']) : null;
    }

    /** @return array{offset:int,length:int}|null */
    private function topLevelShellRange(string $markup, string $area, string $candidateMarkup = ''): ?array
    {
        if ('' !== $candidateMarkup) {
            $matches = array();
            foreach (self::topLevelBlockRanges($markup) as $range) if ($candidateMarkup === substr($markup, $range['offset'], $range['length'])) $matches[] = $range;
            if (1 === count($matches)) return $matches[0];
            if (1 < count($matches)) return null;
        }
        if (!preg_match_all('/<!--\s*(\/?)wp:([^\s]+)(?:\s+([^>]*?))?\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return null;
        $depth = 0; $candidate = null;
        foreach ($matches[0] as $index => $comment) {
            $full = $comment[0]; $offset = $comment[1]; $closing = '' !== $matches[1][$index][0];
            if ($closing) { --$depth; if (is_array($candidate) && null === $candidate['end'] && $depth === $candidate['depth']) $candidate['end'] = $offset + strlen($full); continue; }
            $selfClosing = str_ends_with(trim($full), '/-->');
            $name = $matches[2][$index][0]; $attributes = trim($matches[3][$index][0] ?? '');
            if (0 === $depth && 'group' === $name) {
                $decoded = json_decode($attributes, true);
                if (is_array($decoded) && $area === ($decoded['tagName'] ?? null)) {
                    if (null !== $candidate) return null;
                    $candidate = array('start' => $offset, 'depth' => $depth, 'end' => $selfClosing ? $offset + strlen($full) : null);
                }
            }
            if (!$selfClosing) ++$depth;
        }
        if (!is_array($candidate) || !is_int($candidate['end'])) return null;
        return array('offset' => $candidate['start'], 'length' => $candidate['end'] - $candidate['start']);
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function topLevelBlockRanges(string $markup): array
    {
        $ranges = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $ranges;
        foreach ($matches[0] as $index => $match) {
            $token = $match[0]; $offset = $match[1]; $closing = '' !== $matches[1][$index][0]; $selfClosing = str_ends_with(rtrim($token), '/-->');
            if ($closing) {
                $open = array_pop($stack);
                if (is_array($open) && 0 === count($stack)) $ranges[] = array('offset' => $open['offset'], 'length' => $offset + strlen($token) - $open['offset']);
            } elseif ($selfClosing) {
                if (array() === $stack) $ranges[] = array('offset' => $offset, 'length' => strlen($token));
            } else {
                $stack[] = array('offset' => $offset);
            }
        }
        return $ranges;
    }

    /** @return array<int,array{offset:int,length:int}> */
    private static function directChildBlockRanges(string $markup): array
    {
        $ranges = self::topLevelBlockRanges($markup);
        if (1 !== count($ranges)) return array();
        $children = array(); $stack = array();
        if (!preg_match_all('/<!--\s*(\/?)wp:[^>]*?(\/?)\s*-->/s', $markup, $matches, PREG_OFFSET_CAPTURE)) return $children;
        foreach ($matches[0] as $index => $match) {
            $token = $match[0]; $offset = $match[1]; $closing = '' !== $matches[1][$index][0]; $selfClosing = str_ends_with(rtrim($token), '/-->');
            if ($closing) {
                $open = array_pop($stack);
                if (is_array($open) && 1 === count($stack)) $children[] = array('offset' => $open['offset'], 'length' => $offset + strlen($token) - $open['offset']);
            } elseif (!$selfClosing) {
                $stack[] = array('offset' => $offset);
            } elseif (1 === count($stack)) {
                $children[] = array('offset' => $offset, 'length' => strlen($token));
            }
        }
        return $children;
    }

    private static function isGroupBlock(string $markup): bool { return preg_match('/^<!--\s*wp:group(?:\s|\{)/', $markup) === 1; }

    private static function isCheckboxBlock(string $markup): bool { return preg_match('/^<!--\s*wp:blocks-engine\/authored-input\s+\{[^}]*"type":"checkbox"/', $markup) === 1; }

    private static function blockOpeningMarkup(string $markup): ?string
    {
        if (!preg_match('/^(<!--\s*wp:group(?:\s+[^>]*?)?-->)(<div\b[^>]*>)/s', $markup, $match)) return null;
        return $match[1] . $match[2];
    }

    private static function normalizeNestedChromeMarkup(string $markup): string
    {
        $markup = self::withoutCurrentNavigationState($markup);
        return preg_replace('/\s*blocks-engine-source-[a-z0-9_-]+-[a-f0-9]{6,}-[0-9]+/', '', $markup) ?? $markup;
    }

    private static function withoutCurrentNavigationState(string $markup): string
    {
        return preg_replace_callback('/<!--\s*wp:(navigation(?:-link|-submenu)?)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $class = (string) ($attrs['className'] ?? '');
            if (!str_contains($class, 'blocks-engine-current-navigation-item')) return $match[0];
            $attrs['className'] = trim(str_replace(array('blocks-engine-current-navigation-item', 'blocks-engine-current-navigation-underline', 'current', 'active', 'selected'), '', $class));
            if ('' === $attrs['className']) unset($attrs['className']);
            unset($attrs['anchor'], $attrs['anchorClassName'], $attrs['color'], $attrs['style'], $attrs['typography']);
            return '<!-- wp:' . $match[1] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' ' . ($match[3] ? '/' : '') . '-->';
        }, $markup) ?? $markup;
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function assets(mixed $assets): array
    {
        if ( ! is_array($assets) ) throw new InvalidArgumentException('Compiled site assets must be an array.');
        $rows = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['path'] ?? null) ) throw new InvalidArgumentException('Compiled site asset lacks a safe source identity.');
            // The compiler retains rejected source assets for diagnostics. They have no
            // payload and therefore are not materializable theme artifacts.
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) && !self::payloadReference($asset['payload_reference'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! self::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! self::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $payload = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (string) ($asset['content'] ?? '');
            $reference = self::payloadReference($asset['payload_reference'] ?? null);
            if (null !== $reference && !self::referenceBackedBinaryAsset($asset)) throw new InvalidArgumentException('WordPress site plan payload references are limited to non-SVG binary assets.');
            $transportHash = is_string($asset['content_base64'] ?? null) ? self::contentHash($asset['content_base64']) : null;
            $rows[] = array_filter(array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'stylesheet_placement' => self::value($asset, 'stylesheet_placement'), 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'bytes' => (int) ($asset['bytes'] ?? 0), 'hash' => self::value($asset, 'hash'), 'content' => $asset['content'] ?? null, 'content_base64' => $asset['content_base64'] ?? null, 'payload_reference' => $reference, 'raw_sha256' => $reference['sha256'] ?? ($asset['raw_sha256'] ?? null), 'transport_sha256' => $transportHash, 'binary' => ! empty($asset['binary']), 'compilation' => is_array($asset['compilation'] ?? null) ? $asset['compilation'] : null, 'reconciliation_identity' => self::identity('asset', $asset['path'], $target), 'content_hash' => $reference['sha256'] ?? self::contentHash($payload)), static fn(mixed $value): bool => null !== $value);
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function scopeAssets(array $assets, array $pages): array
    {
        $pagesBySource = array_column($pages, null, 'source_path');
        foreach ($assets as &$asset) {
            $compilation = $asset['compilation'] ?? null;
            unset($asset['compilation']);
            if ('css' !== $asset['kind']) continue;
            if (null === $compilation || 'shared' === ($compilation['scope'] ?? null)) {
                $asset['scopes'] = array(array('kind' => 'global'));
                continue;
            }
            if (!is_array($compilation) || 'page' !== ($compilation['scope'] ?? null) || !is_string($compilation['id'] ?? null) || !is_array($pagesBySource[$compilation['id']] ?? null)) {
                throw new InvalidArgumentException('A page-owned compiled asset must resolve to a canonical page.');
            }
            $page = $pagesBySource[$compilation['id']];
            $asset['scopes'] = array(array('kind' => 'post' === $page['post_type'] ? 'post' : 'page', 'source_path' => $page['source_path'], 'route_path' => trim($page['route']['path'], '/'), 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => '/' === $page['route']['path']));
        }
        unset($asset);
        return $assets;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,mixed> $declarations @return array<int,array<string,mixed>> */
    private function applyDeclaredAssetTransformations(array $assets, array $declarations): array
    {
        $bySource = array(); foreach ($assets as $index => $asset) $bySource[$asset['source_path']] = $index;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || 'asset_publication' !== ($declaration['kind'] ?? null)) continue;
            $assetIndex = $bySource[$declaration['source_path']] ?? null;
            if (!is_int($assetIndex)) throw new InvalidArgumentException('Asset publication references an undeclared asset.');
            $asset = $assets[$assetIndex];
            if ('image/svg+xml' === ($asset['mime_type'] ?? null) && (!is_string($asset['content'] ?? null) || !self::safeSvg($asset['content']))) throw new InvalidArgumentException('Asset publication requires a sanitized SVG source.');
            if (!isset($declaration['transformation'])) continue;
            $transformation = $declaration['transformation'];
            if (!is_string($asset['content'] ?? null) || 'image/svg+xml' !== ($asset['mime_type'] ?? null)) throw new InvalidArgumentException('Asset publication transformation requires a sanitized SVG source.');
            $cssInputs = array();
            foreach ($transformation['css_source_paths'] as $path) {
                $index = $bySource[$path] ?? null;
                if (!is_int($index) || 'text/css' !== ($assets[$index]['mime_type'] ?? null) || !is_string($assets[$index]['content'] ?? null)) throw new InvalidArgumentException('Asset publication transformation references an undeclared local CSS input.');
                $fontFaces = self::fontFaces($assets[$index]['content'], $path, $transformation['font_source_paths'], $assets, $bySource);
                if (array() === $fontFaces) throw new InvalidArgumentException('Asset publication transformation CSS input has no local font-face payload.');
                $cssInputs[] = array('source_path' => $path, 'content_hash' => self::contentHash($assets[$index]['content']), 'font_faces' => $fontFaces);
            }
            $fontInputs = array();
            foreach ($transformation['font_source_paths'] as $path) {
                $index = $bySource[$path] ?? null;
                if (!is_int($index) || !str_starts_with((string) ($assets[$index]['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation references an undeclared local font input.');
                $fontInputs[] = array('source_path' => $path, 'content_hash' => $assets[$index]['content_hash']);
            }
            $input = array('css' => $cssInputs, 'fonts' => $fontInputs);
            if (RuntimeDeclarations::hash($input) !== $transformation['input_hash']) throw new InvalidArgumentException('Asset publication transformation inputs do not match their declared hash.');
            $faces = array(); foreach ($cssInputs as $input) foreach ($input['font_faces'] as $face) $faces[] = $face;
            $content = preg_replace('~</svg\s*>~i', '<style>' . implode("\n", $faces) . '</style></svg>', $asset['content'], 1);
            if (!is_string($content) || $content === $asset['content'] || !self::safeSvg($content) || self::contentHash($content) !== $transformation['expected_content_hash']) throw new InvalidArgumentException('Asset publication transformation content hash does not match its declaration.');
            $assets[$assetIndex]['content'] = $content; $assets[$assetIndex]['content_hash'] = self::contentHash($content);
        }
        return $assets;
    }

    /** @return array<int,string> */
    private static function fontFaces(string $css, string $cssPath, array $fontPaths, array $assets, array $bySource): array
    {
        if (preg_match('~(?:</style|<!--|-->|/\*|\*/|\\|@import|[<>]|(?:https?:|//|file:|blob:|data:))~i', $css) || !preg_match_all('/@font-face\s*\{([^{}]+)\}\s*/i', $css, $matches) || '' !== trim((string) preg_replace('/@font-face\s*\{[^{}]+\}\s*/i', '', $css))) throw new InvalidArgumentException('Asset publication transformation rejects unsafe or non-font CSS inputs.');
        $faces = array();
        foreach ($matches[1] as $body) {
            $properties = array(); $hasSource = false;
            foreach (explode(';', trim($body)) as $declaration) {
                if ('' === trim($declaration)) continue;
                if (!preg_match('/^\s*(font-family|font-style|font-weight|font-stretch|font-display|src)\s*:\s*(.+?)\s*$/i', $declaration, $pair)) throw new InvalidArgumentException('Asset publication transformation CSS property is not allowed.');
                $name = strtolower($pair[1]); $value = trim($pair[2]); if (isset($properties[$name])) throw new InvalidArgumentException('Asset publication transformation CSS has duplicate properties.');
                if ('src' === $name) {
                    if (!preg_match('~^url\(\s*([a-zA-Z0-9._/-]+)\s*\)$~', $value, $url)) throw new InvalidArgumentException('Asset publication transformation CSS source must be a local font path.');
                    $source = ArtifactPath::resolveRelativePath($url[1], $cssPath); $assetIndex = $bySource[$source] ?? null;
                    if (!in_array($source, $fontPaths, true) || !is_int($assetIndex) || !str_starts_with((string) ($assets[$assetIndex]['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation CSS source is not a declared font asset.');
                    $value = 'url(' . self::TOKEN_PREFIX . $assets[$assetIndex]['token'] . '}})'; $hasSource = true;
                } elseif (!preg_match('/^[a-z0-9 .,_\'"-]+$/i', $value)) throw new InvalidArgumentException('Asset publication transformation CSS value is not safe.');
                $properties[$name] = $value;
            }
            if (!$hasSource || !isset($properties['font-family'])) throw new InvalidArgumentException('Asset publication transformation font-face is incomplete.');
            $face = '@font-face{'; foreach ($properties as $name => $value) $face .= $name . ':' . $value . ';'; $faces[] = $face . '}';
        }
        return $faces;
    }

    private static function safeSvg(string $svg): bool
    {
        $scan = preg_replace('~\sxmlns(?::[a-z]+)?\s*=\s*["\']http://www\.w3\.org/2000/svg["\']~i', '', $svg) ?? $svg;
        if (1 === preg_match('~(?:<!DOCTYPE|<!ENTITY|<\?xml|<\s*(?:script|foreignObject)\b|\son[a-z]+\s*=|(?:https?:|//|file:|blob:|data:|javascript:)|@import)~i', $scan)) return false;
        if (preg_match_all('~(?:href|xlink:href)\s*=\s*(["\'])(.*?)\1~i', $svg, $matches)) foreach ($matches[2] as $reference) if (!str_starts_with($reference, '#') && !str_starts_with($reference, self::TOKEN_PREFIX)) return false;
        return 1 !== preg_match('~url\((?!\s*\{\{wordpress-site-plan:asset:asset-[a-f0-9]{16}\}\})~i', $svg);
    }

    /** @param array<int,array<string,mixed>> $assets @return array<int,array<string,string>> */
    private function tokens(array $assets): array { return array_map(static fn(array $asset): array => array('token' => $asset['token'], 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path']), $assets); }
    /** @param array<string,mixed> $document @param array<int,array<string,string>> $tokens @return array<string,mixed> */
    private function documentMetadata(array $document, AssetReferenceCanonicalizer $references, array $routes): array
    {
        $metadata = is_array($document['document_metadata'] ?? null) ? $document['document_metadata'] : array('source_context' => array('source_path' => self::value($document, 'source_path'), 'kind' => 'document'), 'title' => self::value($document, 'title'), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array());
        foreach (array('links', 'scripts') as $kind) {
            if (!is_array($metadata[$kind] ?? null)) $metadata[$kind] = array();
            foreach ($metadata[$kind] as &$row) {
                if (!is_array($row) || !is_string($row['url'] ?? null)) continue;
                $reference = $this->documentAssetReference($row['url'], self::value($document, 'source_path'), $references, $routes);
                if (null !== $reference) {
                    $row['asset_reference'] = $reference;
                    unset($row['url']);
                    continue;
                }
                if ('links' !== $kind) continue;
                $route = $this->routeReference($row['url'], self::value($document, 'source_path'), $routes);
                if (null !== $route) $row['url'] = $route;
                elseif ($this->isOptionalFeedLink($row)) $row = null;
            }
            unset($row);
            $metadata[$kind] = array_values(array_filter($metadata[$kind], static fn(mixed $row): bool => is_array($row)));
            foreach ($metadata[$kind] as $index => &$row) $row['order'] = $index;
            unset($row);
        }
        return $metadata;
    }

    /** @param array<string,mixed> $link */
    private function isOptionalFeedLink(array $link): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim((string) ($link['rel'] ?? '')))) ?: array();
        return !self::explicitUrl($link['url'] ?? null) && in_array('alternate', $relations, true) && in_array(strtolower(trim((string) ($link['type'] ?? ''))), array('application/atom+xml', 'application/feed+json', 'application/rss+xml'), true);
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function documentAssetReference(string $url, string $sourcePath, AssetReferenceCanonicalizer $references, array $routes): ?string
    {
        $reference = $references->reference($url, $sourcePath);
        if (null !== $reference) return $reference;
        $entryRoot = self::entryRootFromDocuments($routes);
        if ('' === $entryRoot) return null;
        $rooted = ArtifactPath::resolveRelativePath(ltrim($url, '/'), $entryRoot . '/index.html');
        $reference = '' === $rooted ? null : $references->reference('/' . $rooted, $sourcePath);
        return $reference ?? $references->reference($url, '');
    }
    /** @param mixed $documents @return array<int,array<string,mixed>> */
    private function decideDocuments(mixed $documents): array
    {
        if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.');
        foreach ($documents as &$document) {
            if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site document is invalid.');
            $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array();
            $frontmatter = is_array($metadata['frontmatter'] ?? null) ? $metadata['frontmatter'] : array();
            $explicit = null; $provenance = null;
            foreach (array('post_type', 'type') as $key) if (is_string($frontmatter[$key] ?? null) && in_array(strtolower($frontmatter[$key]), array('page', 'post'), true)) { $explicit = strtolower($frontmatter[$key]); $provenance = 'frontmatter:' . $key; break; }
            if (null === $explicit && 'metadata:post_type' === ($metadata['post_type_declaration'] ?? null) && is_string($metadata['post_type'] ?? null) && in_array(strtolower($metadata['post_type']), array('page', 'post'), true)) { $explicit = strtolower($metadata['post_type']); $provenance = 'metadata:post_type'; }
            $evidence = $this->publicationEvidence($document);
            $postType = $explicit ?? ((!empty($document['entrypoint']) || array() === $evidence) ? 'page' : 'post');
            $metadata['post_type'] = $postType;
            $document['metadata'] = $metadata;
            $document['content_decision'] = array_filter(array('schema' => 'blocks-engine/content-decision/v1', 'state' => null !== $explicit ? 'declared' : (array() === $evidence ? 'defaulted' : 'inferred'), 'post_type' => $postType, 'provenance' => $provenance, 'evidence' => $evidence), static fn(mixed $value): bool => null !== $value);
            foreach ($evidence as $row) if (is_string($row['publication_timestamp'] ?? null)) { $document['publication_timestamp'] = $row['publication_timestamp']; break; }
        }
        unset($document);
        return $documents;
    }
    /** @param array<string,mixed> $document @return array<int,array<string,string>> */
    private function publicationEvidence(array $document): array
    {
        $html = is_string($document['html'] ?? null) ? $document['html'] : '';
        $evidence = array(); $add = static function (array &$rows, string $source, ?string $value = null): void { if (count($rows) >= 16) return; $row = array('source' => $source); if (null !== $value) $row['publication_timestamp'] = $value; $rows[] = $row; };
        $timestamp = static fn(string $value): ?string => self::normalizePublicationTimestamp($value);
        foreach (($document['document_metadata']['meta'] ?? array()) as $meta) if (is_array($meta) && is_string($meta['content'] ?? null) && in_array(strtolower((string) ($meta['property'] ?? $meta['name'] ?? '')), array('article:published_time', 'article:published', 'pubdate', 'publishdate', 'date', 'dc.date.issued', 'dc.date', 'parsely-pub-date', 'releasedate'), true)) if (null !== ($date = $timestamp($meta['content']))) $add($evidence, 'meta:' . strtolower((string) ($meta['property'] ?? $meta['name'])), $date);
        foreach (self::htmlMarkupNodes($html) as $node) if ('tag' === ($node['kind'] ?? null)) { $attributes = $node['attributes']; if ('article' === ($node['name'] ?? null)) $add($evidence, 'html:article'); if ('time' === ($node['name'] ?? null) && is_string($attributes['datetime'] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes['datetime'], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) $add($evidence, 'html:time[datetime]', $date); if (preg_match('~\b(?:Article|BlogPosting)\b~', (string) ($attributes['itemtype'] ?? ''))) $add($evidence, 'microdata:itemtype'); if (in_array($attributes['itemprop'] ?? null, array('datePublished', 'dateCreated'), true)) foreach (array('datetime', 'content') as $key) if (is_string($attributes[$key] ?? null) && null !== ($date = $timestamp(html_entity_decode($attributes[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8')))) { $add($evidence, 'microdata:datePublished', $date); break; } }
        foreach (self::htmlMarkupNodes($html) as $node) if ('rawtext' === ($node['kind'] ?? null) && 'script' === ($node['name'] ?? null) && 'application/ld+json' === strtolower(trim((string) ($node['attributes']['type'] ?? '')))) foreach ($this->jsonLdPublicationEvidence(json_decode($node['content'], true), $timestamp) as $row) $add($evidence, $row['source'], $row['publication_timestamp'] ?? null);
        $route = is_string($document['metadata']['route_path'] ?? null) ? $document['metadata']['route_path'] : self::pageRoutePath((string) $document['source_path'], self::entryRootFromDocuments(array($document)));
        if (preg_match('~/(?:[0-9]{4})/(?:0[1-9]|1[0-2])(?:/|$)~', $route)) $add($evidence, 'route:dated');
        $unique = array(); foreach ($evidence as $row) $unique[$row['source'] . "\n" . ($row['publication_timestamp'] ?? '')] = $row; return array_values($unique);
    }
    /** @return array<int,array<string,string>> */
    private function jsonLdPublicationEvidence(mixed $value, callable $timestamp): array
    {
        if (!is_array($value)) return array(); $rows = array();
        if (isset($value['@type'])) { $types = is_array($value['@type']) ? $value['@type'] : array($value['@type']); if (array_intersect(array('Article', 'BlogPosting'), $types)) { $row = array('source' => 'json-ld:' . (in_array('BlogPosting', $types, true) ? 'BlogPosting' : 'Article')); foreach (array('datePublished', 'dateCreated') as $key) if (is_string($value[$key] ?? null) && null !== ($date = $timestamp($value[$key]))) { $row['publication_timestamp'] = $date; break; } $rows[] = $row; } }
        foreach ($value as $child) if (is_array($child)) $rows = array_merge($rows, $this->jsonLdPublicationEvidence($child, $timestamp));
        return $rows;
    }
    /** @param array<string,mixed> $compiled @param array<string,mixed> $data @return array<string,mixed> */
    private function reporting(array $pages, array $data, array $scriptDiagnostics = array()): array { $documents = array(); foreach ($pages as $page) if (is_array($page)) $documents[] = array('source_path' => $page['source_path'] ?? '', 'kind' => 'page', 'body_format' => 'blocks', 'block_document' => true, 'provenance' => $page['provenance'] ?? array()); return array('source_documents' => $documents, 'metrics' => array('source_document_count' => count($documents), 'block_document_count' => count($documents), 'native_block_count' => $data['metrics']['block_count'] ?? 0, 'fallback_count' => $data['metrics']['fallback_count'] ?? 0), 'core_html_fallback_evidence' => $data['source_reports']['conversion_report']['core_html_fallback_evidence'] ?? array(), 'diagnostic_codes' => array_values(array_map(static fn(array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), array_merge($data['diagnostics'], $scriptDiagnostics)))); }

    /** @param mixed $documents @param array<int,array<string,mixed>> $legacyRoutes @return array<int,array<string,mixed>> */
    private function canonicalRoutes(mixed $documents, array $legacyRoutes): array { if (!is_array($documents)) throw new InvalidArgumentException('Compiled site documents must be an array.'); $legacy = array(); foreach ($legacyRoutes as $route) if (is_array($route) && is_string($route['source_path'] ?? null)) $legacy[$route['source_path']] = $route; $entryRoot = self::entryRootFromDocuments($documents); $routes = array(); $paths = array(); foreach ($documents as $order => $document) { if (!is_array($document) || !self::safePath($document['source_path'] ?? null)) throw new InvalidArgumentException('Compiled site route source is invalid.'); $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : array(); $explicitRoute = is_string($metadata['route_path'] ?? null) && '' !== $metadata['route_path']; if ('' !== $entryRoot && ! str_starts_with((string) $document['source_path'], $entryRoot . '/') && !$explicitRoute) throw new InvalidArgumentException('Compiled site document is outside the entrypoint content root.'); $path = $explicitRoute ? self::canonicalRoutePath($metadata['route_path']) : self::pageRoutePath($document['source_path'], $entryRoot); if (isset($paths[$path])) throw new InvalidArgumentException('WordPress site plan has colliding page routes.'); $paths[$path] = true; $previous = $legacy[$document['source_path']] ?? array(); $routes[] = array('kind' => 'route', 'source_path' => $document['source_path'], 'target_path' => $path, 'target_slug' => self::value($document, 'slug', self::routeSlug($path)), 'title' => self::value($document, 'title'), 'parent_source_path' => self::value($metadata, 'parent_source_path'), 'source_relation' => !empty($document['entrypoint']) ? 'entrypoint' : ($previous['source_relation'] ?? 'document'), 'order' => $order); } return $routes; }
    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $routes @return array<int,array<string,mixed>> */
    private function pageHierarchy(array $pages, array $routes): array
    {
        $byRoute = array(); $sources = array(); foreach ($pages as $page) $sources[$page['source_path']] = true;
        foreach ($pages as $index => &$page) {
            $route = array_values(array_filter($routes, static fn(array $route): bool => $route['source_path'] === $page['source_path']))[0] ?? null; if (!is_array($route)) throw new InvalidArgumentException('WordPress site plan page lacks a canonical route.'); $path = $route['target_path'];
            if (isset($byRoute[$path])) throw new InvalidArgumentException('WordPress site plan has colliding page routes.');
            $page['route'] = array('path' => $path, 'parent_path' => self::parentRoutePath($path), 'slug' => self::routeSlug($path));
            if ('/' !== $path) $page['slug'] = $page['route']['slug'];
            $page['reconciliation_identity'] = self::identity('page', $page['source_path'], $path);
            $byRoute[$path] = $index;
        }
        unset($page);
        foreach (array_keys($byRoute) as $path) {
            if ('post' === ($pages[$byRoute[$path]]['post_type'] ?? null)) continue;
            foreach (self::routeAncestors($path) as $ancestor) if (!isset($byRoute[$ancestor])) {
            $source = 'wordpress-site-plan/routes/' . trim($ancestor, '/') . '.html';
            if (isset($sources[$source])) throw new InvalidArgumentException('WordPress site plan synthetic route source collides with a document.');
            $markup = '<!-- wp:group {"layout":{"type":"constrained"}} --><!-- /wp:group -->' . "\n";
            $pages[] = array('source_path' => $source, 'slug' => self::routeSlug($ancestor), 'title' => ucwords(str_replace('-', ' ', self::routeSlug($ancestor))), 'post_type' => 'page', 'parent_source_path' => '', 'entrypoint' => false, 'area' => null, 'placement' => null, 'canonical_block_markup' => $markup, 'metadata' => array(), 'document_metadata' => array('source_context' => array('source_path' => $source, 'kind' => 'synthetic_route'), 'title' => self::routeSlug($ancestor), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array()), 'provenance' => array(), 'reconciliation_identity' => self::identity('page', $source, $ancestor), 'content_hash' => hash('sha256', $markup), 'route' => array('path' => $ancestor, 'parent_path' => self::parentRoutePath($ancestor), 'slug' => self::routeSlug($ancestor)), 'synthetic' => true);
            $byRoute[$ancestor] = count($pages) - 1;
            $sources[$source] = true;
            }
        }
        foreach ($pages as &$page) { if ('post' === ($page['post_type'] ?? null)) { $page['parent_source_path'] = ''; continue; } $parent = $page['route']['parent_path']; if ('/' !== $parent && 'page' !== ($pages[$byRoute[$parent]]['post_type'] ?? null)) throw new InvalidArgumentException('WordPress site plan page hierarchy cannot inherit a post route.'); $page['parent_source_path'] = '/' === $parent ? '' : $pages[$byRoute[$parent]]['source_path']; }
        unset($page);
        usort($pages, static fn(array $left, array $right): int => substr_count($left['route']['path'], '/') <=> substr_count($right['route']['path'], '/') ?: strcmp($left['route']['path'], $right['route']['path']));
        return $pages;
    }
    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function routesForPages(array $pages): array { $routes = array(); foreach ($pages as $page) $routes[] = array('kind' => 'route', 'source_path' => $page['source_path'], 'target_path' => $page['route']['path'], 'target_slug' => $page['slug'], 'title' => $page['title'], 'parent_source_path' => $page['parent_source_path'], 'source_relation' => !empty($page['synthetic']) ? 'synthetic_parent' : (!empty($page['entrypoint']) ? 'entrypoint' : 'document'), 'order' => count($routes)); return $routes; }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,string>> */
    private function templates(array $pages, array $parts): array
    {
        $bound = array_values(array_filter($parts, static fn(array $part): bool => in_array($part['placement']['kind'] ?? '', array('entry_shell', 'shared_shell'), true)));
        usort($bound, static function (array $left, array $right): int {
            $priority = array('header' => 0, 'footer' => 2);
            return (($priority[$left['area']] ?? 1) <=> ($priority[$right['area']] ?? 1)) ?: strcmp($left['slug'], $right['slug']);
        });
        $markup = static function (string $templateSlug) use ($bound): string {
            $before = ''; $after = '';
            $container = null;
            foreach ($bound as $part) if (in_array($templateSlug, $part['placement']['template_slugs'] ?? array(), true) || (preg_match('/^(?:page|single)-[a-z0-9-]+$/', $templateSlug) && !in_array($templateSlug, $part['placement']['excluded_template_slugs'] ?? array(), true))) {
                if (is_array($part['placement']['container'] ?? null)) $container = $part['placement']['container'];
                $reference = '<!-- wp:template-part {"slug":"' . $part['slug'] . '","area":"' . $part['area'] . '","tagName":"' . $part['area'] . '"} /-->' . "\n";
                if ('footer' === $part['area']) $after .= $reference; else $before .= $reference;
            }
            if (is_array($container) && is_string($container['opening'] ?? null) && is_string($container['closing'] ?? null)) return $container['opening'] . $before . '<!-- wp:post-content /-->' . "\n" . $container['closing'] . $after;
            return $before . '<!-- wp:post-content /-->' . "\n" . $after;
        };
        $make = static function (string $slug, string $target, string $content): array { return array('slug' => $slug, 'target_path' => $target, 'canonical_block_markup' => $content, 'reconciliation_identity' => self::identity('template', 'wordpress-site-plan/' . $target, $target), 'content_hash' => self::contentHash($content)); };
        $templates = array($make('index', 'templates/index.html', $markup('index')));
        if ( array() !== $pages ) $templates[] = $make('page', 'templates/page.html', $markup('page'));
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = $make('front-page', 'templates/front-page.html', $markup('front-page')); break; }
        $overrides = array();
        foreach ($bound as $part) foreach ($part['placement']['excluded_template_slugs'] ?? array() as $slug) if (preg_match('/^(?:page|single)-[a-z0-9-]+$/', $slug)) $overrides[$slug] = true;
        foreach (array_keys($overrides) as $slug) $templates[] = $make($slug, 'templates/' . $slug . '.html', $markup($slug));
        return $templates;
    }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,mixed>> */
    private function operations(array $pages): array
    {
        $operations = array();
        foreach ($pages as $page) $operations[] = array('kind' => 'create_page', 'order' => count($operations), 'source_path' => $page['source_path'], 'reconciliation_identity' => $page['reconciliation_identity'], 'post_type' => $page['post_type'], 'slug' => $page['slug'], 'route_path' => $page['route']['path'], 'parent_source_path' => $page['parent_source_path'], 'synthetic' => !empty($page['synthetic']));
        foreach ($pages as $page) if (!empty($page['entrypoint'])) { $operations[] = array('kind' => 'site_reading', 'order' => count($operations), 'show_on_front' => 'page', 'front_page_source_path' => $page['source_path'], 'front_page_reconciliation_identity' => $page['reconciliation_identity']); break; }
        return $operations;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function assetWrites(array $assets, AssetReferenceCanonicalizer $references): array
    {
        $writes = array();
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $references->content($asset['content'], $asset['source_path']) : null;
            if (is_array($asset['payload_reference'] ?? null)) { $writes[] = $this->referenceWrite('theme_asset', $asset['target_path'], $asset['source_path'], $asset['payload_reference']); continue; }
            $base64Transport = is_string($asset['content_base64'] ?? null);
            $text = is_string($content) && empty($asset['binary']) && 1 === preg_match('//u', $content) && (!$base64Transport || 'text/css' === ($asset['mime_type'] ?? null));
            $data = $text ? $content : (is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? base64_encode($content) : null));
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = $this->write('theme_asset', $asset['target_path'], $data, $asset['source_path'], $text ? 'utf8' : 'base64');
        }
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @param array<int,array<string,mixed>> $operations @return array{scripts:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>} */
    private function scriptLoading(array $pages, array $parts, array $assets, array $tokens, array $operations, array $runtimeDeclarations): array
    {
        $targets = array(); foreach ($tokens as $token) $targets[$token['token']] = $token['target_path'];
        $contents = array(); foreach ($assets as $asset) if (is_string($asset['content'] ?? null)) $contents[$asset['target_path']] = $asset['content'];
        $inlineTargets = array(); foreach ($assets as $asset) if ('inline-script' === ($asset['source'] ?? null) && is_string($asset['content'] ?? null)) $inlineTargets[self::contentHash($asset['content'])] = $asset['target_path'];
        $frontPages = array(); foreach ($operations as $operation) if ('site_reading' === ($operation['kind'] ?? null)) $frontPages[$operation['front_page_reconciliation_identity']] = true;
        $superseded = array();
        foreach ( $runtimeDeclarations as $declaration ) foreach ( $declaration['payload']['entities'] ?? array() as $entity ) foreach ( $entity['superseded_scripts'] ?? array() as $script ) if ( is_array($script) && is_string($script['source_path'] ?? null) && is_string($script['selector'] ?? null) && is_string($script['body_hash'] ?? null) && is_string($script['target_selector'] ?? null) ) $superseded[$script['source_path'] . "\n" . $script['selector'] . "\n" . $script['body_hash'] . "\n" . $script['target_selector']] = true;
        $scripts = array(); $diagnostics = array(); $instances = array();
        foreach (array_merge($pages, $parts) as $document) foreach ($document['document_metadata']['scripts'] ?? array() as $script) {
            $source = $document['source_path'] . '#' . ($script['order'] ?? '');
            $unsupported = static function (string $code, string $message) use (&$diagnostics, $source): void { $diagnostics[] = array('code' => $code, 'severity' => 'warning', 'message' => $message, 'source_path' => $source); };
            if (!is_array($script)) { $unsupported('wordpress_site_plan_script_invalid', 'Document script metadata is invalid.'); continue; }
            $supersessionKey = $document['source_path'] . "\n" . ($script['selector'] ?? '') . "\n" . ($script['body_hash'] ?? '') . "\n" . ($script['superseded_by'] ?? '');
            if ( isset($superseded[$supersessionKey]) ) continue;
            // A form-runtime script (marked with a supersession target) that a
            // provider binding did not safely supersede must not be materialized
            // as an ordinary inline asset: its retained behavior (network or
            // global side effects) is exactly what made it ineligible for
            // supersession. Keep the plan not_proven so the materializer treats
            // the residual runtime island as unresolved rather than silently
            // shipping the unsafe handler.
            if ( '' !== (string) ($script['superseded_by'] ?? '') ) { $unsupported('wordpress_site_plan_script_form_runtime_unsuperseded', 'A form-runtime script was not safely superseded by a provider binding and cannot be materialized as a static inline asset.'); continue; }
            $localTarget = null;
            if ('inline' === ($script['source_kind'] ?? null)) { $localTarget = $inlineTargets[$script['body_hash'] ?? ''] ?? null; if (null === $localTarget) { $unsupported('wordpress_site_plan_script_inline_unbound', 'Inline document script metadata has no matching canonical asset.'); continue; } }
            if (true === ($script['module'] ?? false) && true === ($script['nomodule'] ?? false)) { $unsupported('wordpress_site_plan_script_module_nomodule_conflict', 'A document script cannot combine module and nomodule semantics.'); continue; }
            if (isset($document['placement']) && !in_array($document['placement']['kind'] ?? null, array('entry_shell', 'shared_shell'), true)) { $unsupported('wordpress_site_plan_script_unbound_template_part', 'A template-part script cannot be materialized because its template placement is unbound.'); continue; }
            $suffix = ''; $url = null;
            if (null === $localTarget) {
                if (is_string($script['asset_reference'] ?? null) && preg_match('/^\{\{wordpress-site-plan:asset:([^}]+)\}\}(.*)$/', $script['asset_reference'], $match) && isset($targets[$match[1]])) { $localTarget = $targets[$match[1]]; $suffix = $match[2]; }
                elseif (is_string($script['url'] ?? null) && preg_match('~^(?:https?:)?//[^\x00-\x20]+$~i', $script['url'])) { $url = $script['url']; $unsupported('wordpress_site_plan_script_external_unproven', 'An external script URL is emitted but cannot prove its runtime references without a declared local artifact.'); }
                else { $unsupported('wordpress_site_plan_script_url_unsupported', 'A document script must reference a declared local write or an absolute HTTP(S) URL.'); continue; }
            }
            if (null !== $localTarget && $this->hasDynamicScriptReferences($contents[$localTarget] ?? '')) { $unsupported('wordpress_site_plan_script_dynamic_references', 'A local script contains dynamic imports, script injection, or runtime URL construction that cannot be proven from the canonical write.'); continue; }
            $attributes = array('placement' => $script['placement'], 'local_target' => $localTarget, 'suffix' => $suffix, 'url' => $url, 'async' => $script['async'], 'defer' => $script['defer'], 'module' => $script['module'], 'nomodule' => $script['nomodule'], 'type' => $script['type'] ?? ($script['module'] ? 'module' : null), 'integrity' => $script['integrity'] ?? null, 'crossorigin' => $script['crossorigin'] ?? null, 'referrerpolicy' => $script['referrerpolicy'] ?? null, 'fetchpriority' => $script['fetchpriority'] ?? null);
            $scope = isset($document['placement']) ? array('kind' => 'global', 'order' => $script['order']) : array('kind' => 'post' === ($document['post_type'] ?? null) ? 'post' : 'page', 'source_path' => $document['source_path'], 'route_path' => trim($document['route']['path'], '/'), 'reconciliation_identity' => $document['reconciliation_identity'], 'front_page' => isset($frontPages[$document['reconciliation_identity']]), 'order' => $script['order']);
            $scopeKey = ($scope['kind'] ?? '') . ':' . ($scope['source_path'] ?? 'global');
            $signature = hash('sha256', serialize($attributes)); $instance = $instances[$scopeKey][$signature] ?? 0; $instances[$scopeKey][$signature] = $instance + 1;
            $identity = $signature . ':' . $instance;
            if (!isset($scripts[$identity])) $scripts[$identity] = array_merge(array('identity' => $identity, 'scopes' => array()), $attributes);
            $scripts[$identity]['scopes'][] = $scope;
        }
        return array('scripts' => array_values($scripts), 'diagnostics' => $diagnostics);
    }

    private function hasDynamicScriptReferences(string $content): bool { return preg_match('/\bimport\s*\(|\b(?:document\s*\.\s*createElement\s*\(\s*["\']script|appendChild\s*\(|insertBefore\s*\(|\.\s*src\s*=|new\s+URL\s*\()/i', $content) === 1; }
    private static function pageRoutePath(string $sourcePath, string $entryRoot = ''): string { if (str_contains($sourcePath, '%')) throw new InvalidArgumentException('WordPress site plan page routes reject encoded source paths.'); $relative = self::stripEntryRoot($sourcePath, $entryRoot); $segments = explode('/', preg_replace('/\.[A-Za-z0-9]+$/', '', $relative) ?? $relative); $segments = array_map(static fn(string $segment): string => trim(strtolower((string) preg_replace('/[^a-z0-9_-]/', '', str_replace('_', '-', $segment))), '-'), $segments); return '/' . implode('/', array_values(array_filter($segments, static fn(string $segment): bool => '' !== $segment && 'index' !== $segment))); }
    // The entrypoint document's directory is the site's web root: a `website/`
    // wrapper around `website/index.html` must not become a `/website` route with
    // every other page nested beneath it. Strip that shared root so `index.html`
    // maps to `/` and its siblings map to top-level routes (`/contact`, `/music`).
    private static function stripEntryRoot(string $sourcePath, string $entryRoot): string { if ('' === $entryRoot) return $sourcePath; $prefix = rtrim($entryRoot, '/') . '/'; return str_starts_with($sourcePath, $prefix) ? substr($sourcePath, strlen($prefix)) : $sourcePath; }
    // Resolve the site root directory from the entrypoint document/page so route
    // derivation and validation agree on the same web root without shared state.
    private static function entryRootFromDocuments(array $documents): string { foreach ($documents as $document) { if (is_array($document) && (!empty($document['entrypoint']) || 'entrypoint' === ($document['source_relation'] ?? null)) && is_string($document['source_path'] ?? null)) { $dir = str_replace('\\', '/', dirname($document['source_path'])); return in_array($dir, array('.', '/', ''), true) ? '' : $dir; } } return ''; }
    private static function canonicalRoutePath(string $path): string { if (!preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $path)) throw new InvalidArgumentException('WordPress site plan has an unsafe explicit page route.'); return $path; }
    private static function parentRoutePath(string $path): string { $parent = dirname($path); return '.' === $parent || '/' === $parent ? '/' : '/' . trim($parent, '/'); }
    /** @return array<int,string> */
    private static function routeAncestors(string $path): array { $ancestors = array(); for ($parent = self::parentRoutePath($path); '/' !== $parent; $parent = self::parentRoutePath($parent)) $ancestors[] = $parent; return array_reverse($ancestors); }
    private static function routeSlug(string $path): string { return trim((string) basename($path), '/'); }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts, array $scripts): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', "{\"version\":3,\"settings\":{},\"styles\":{}}\n"));
        if ( self::needsBootstrap($assets, $scripts) ) $writes[] = $this->write('theme_bootstrap', 'functions.php', self::bootstrap($assets, $scripts));
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets */
    private static function needsBootstrap(array $assets, array $scripts = array()): bool { foreach ($assets as $asset) if (in_array($asset['kind'], array('css', 'js'), true)) return true; return array() !== $scripts; }
    /** @param array<int,array<string,mixed>> $assets */
    private static function bootstrap(array $assets, array $scripts = array()): string
    {
        $lines = array("<?php", "add_action( 'wp_enqueue_scripts', static function (): void {");
        foreach ($assets as $asset) {
            $handle = 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12);
            if ('css' === $asset['kind']) foreach ($asset['scopes'] as $scope) {
                $condition = self::bootstrapScopeCondition($scope);
                $lines[] = "    if ( {$condition} ) wp_enqueue_style( '{$handle}', get_theme_file_uri( '{$asset['target_path']}' ), array(), null );";
            }
        }
        $attributes = array();
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            $source = null !== $script['local_target'] ? "get_theme_file_uri( " . var_export($script['local_target'], true) . " ) . " . var_export($script['suffix'], true) : var_export($script['url'], true);
            $args = array('in_footer' => 'body' === $script['placement']);
            if ($script['async'] && !$script['module']) $args['strategy'] = 'async';
            if ($script['defer'] && !$script['async'] && !$script['module']) $args['strategy'] = 'defer';
            $lines[] = "    wp_register_script( " . var_export($handle, true) . ", {$source}, array(), null, " . var_export($args, true) . " );";
            $attributes[$handle] = array_filter(array('type' => $script['type'], 'nomodule' => $script['nomodule'], 'integrity' => $script['integrity'], 'crossorigin' => $script['crossorigin'], 'referrerpolicy' => $script['referrerpolicy'], 'fetchpriority' => $script['fetchpriority'], 'async' => $script['async'] && $script['module'], 'defer' => $script['defer'] && ($script['async'] || $script['module'])), static fn(mixed $value): bool => false !== $value && null !== $value);
        }
        $lines[] = "}, 1 );";
        $editorStyles = array();
        foreach ($assets as $asset) if ('css' === $asset['kind']) $editorStyles[] = array('target_path' => $asset['target_path'], 'content_hash' => $asset['content_hash'], 'scopes' => $asset['scopes']);
        if (array() !== $editorStyles) {
            $lines[] = "add_filter( 'block_editor_settings_all', static function ( array \$settings, \$context ): array {";
            $lines[] = "    \$post = \$context->post ?? null; if ( ! \$post instanceof WP_Post ) return \$settings;";
            $lines[] = '    $styles = ' . var_export($editorStyles, true) . ';';
            $lines[] = "    foreach ( \$styles as \$style ) {";
            $lines[] = "        \$matches = false; foreach ( \$style['scopes'] as \$scope ) {";
            $lines[] = "            if ( 'global' === \$scope['kind'] ) { \$matches = true; break; }";
            $lines[] = "            if ( 'post' === \$scope['kind'] && 'post' === \$post->post_type && \$scope['reconciliation_identity'] === get_post_meta( \$post->ID, '_blocks_engine_reconciliation_identity', true ) ) { \$matches = true; break; }";
            $lines[] = "            if ( 'page' === \$scope['kind'] && 'page' === \$post->post_type && ( ( \$scope['front_page'] && (int) get_option( 'page_on_front' ) === (int) \$post->ID ) || \$scope['route_path'] === trim( get_page_uri( \$post ), '/' ) ) ) { \$matches = true; break; }";
            $lines[] = "        }";
            $lines[] = "        if ( ! \$matches ) continue; \$css = file_get_contents( get_theme_file_path( \$style['target_path'] ) );";
            $lines[] = "        if ( false !== \$css ) \$settings['styles'][] = array( 'css' => ':root{--blocks-engine-presentation:' . \$style['content_hash'] . ';}' . \"\\n\" . \$css, '__unstableType' => 'theme' );";
            $lines[] = "    }";
            $lines[] = "    return \$settings;";
            $lines[] = "}, 10, 2 );";
        }
        foreach ($scripts as $script) {
            $handle = 'blocks-engine-script-' . substr(hash('sha256', $script['identity']), 0, 12);
            foreach ($script['scopes'] as $scope) {
                $condition = self::bootstrapScopeCondition($scope);
                $lines[] = "add_action( 'wp_enqueue_scripts', static function (): void { if ( {$condition} ) wp_enqueue_script( " . var_export($handle, true) . " ); }, " . (10 + $scope['order']) . " );";
            }
        }
        if (array() !== $attributes) {
            $lines[] = "add_filter( 'script_loader_tag', static function ( string \$tag, string \$handle ): string {";
            $lines[] = '    $attributes = ' . var_export($attributes, true) . ';';
            $lines[] = "    if ( ! isset( \$attributes[\$handle] ) ) return \$tag;";
            $lines[] = "    \$rendered = ''; foreach ( \$attributes[\$handle] as \$name => \$value ) \$rendered .= true === \$value ? ' ' . \$name : ' ' . \$name . '=\"' . esc_attr( (string) \$value ) . '\"';";
            $lines[] = "    return preg_replace( '/<script\\b/', '<script' . \$rendered, \$tag, 1 ) ?? \$tag;";
            $lines[] = "}, 10, 2 );";
        }
        return implode("\n", $lines) . "\n";
    }
    /** @param array<string,mixed> $scope */
    private static function bootstrapScopeCondition(array $scope): string
    {
        if ('global' === ($scope['kind'] ?? null)) return 'true';
        if (!empty($scope['front_page'])) return 'is_front_page()';
        if ('post' === ($scope['kind'] ?? null)) return "is_singular( 'post' ) && " . var_export($scope['reconciliation_identity'], true) . " === get_post_meta( get_queried_object_id(), '_blocks_engine_reconciliation_identity', true )";
        return 'is_page() && ' . var_export($scope['route_path'], true) . " === trim( get_page_uri( get_queried_object_id() ), '/' )";
    }
    private static function assertAssetScopes(mixed $scopes): void
    {
        if (!is_array($scopes) || array() === $scopes) throw new InvalidArgumentException('WordPress site plan asset scopes must be a nonempty array.');
        foreach ($scopes as $scope) {
            if (!is_array($scope) || !in_array($scope['kind'] ?? null, array('global', 'page', 'post'), true)) throw new InvalidArgumentException('WordPress site plan asset scope is invalid.');
            if ('global' === $scope['kind']) {
                if (array('kind') !== array_keys($scope)) throw new InvalidArgumentException('A global asset scope cannot declare page fields.');
                continue;
            }
            if (array('kind', 'source_path', 'route_path', 'reconciliation_identity', 'front_page') !== array_keys($scope) || !self::safePath($scope['source_path']) || !is_string($scope['route_path']) || !self::hash($scope['reconciliation_identity']) || !is_bool($scope['front_page'])) throw new InvalidArgumentException('A page asset scope is structurally invalid.');
        }
    }
    /** @return array<string,mixed> */
    private function write(string $kind, string $target, string $content, ?string $sourcePath = null, string $encoding = 'utf8'): array { $sourcePath ??= 'wordpress-site-plan/' . $target; return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash($content), 'payload' => array('encoding' => $encoding, 'data' => $content)); }
    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    private function referenceWrite(string $kind, string $target, string $sourcePath, array $reference): array { return array('kind' => $kind, 'source_path' => $sourcePath, 'target_path' => $target, 'reconciliation_identity' => self::identity('write', $sourcePath, $target), 'payload_hash' => self::contentHash(RuntimeDeclarations::canonicalJson($reference)), 'raw_sha256' => $reference['sha256'], 'payload' => array('encoding' => 'reference', 'reference' => $reference)); }
    /** @param array<string,mixed> $asset */
    private static function referenceBackedBinaryAsset(array $asset): bool { return !empty($asset['binary']) && 'image/svg+xml' !== strtolower((string) ($asset['mime_type'] ?? '')) && !str_ends_with(strtolower((string) ($asset['source_path'] ?? $asset['path'] ?? '')), '.svg'); }
    private static function relativePath(string $origin, string $target): string
    {
        $from = '' === $origin ? array() : explode('/', dirname($origin));
        if (array('.') === $from) $from = array();
        $to = explode('/', $target);
        while (array() !== $from && array() !== $to && $from[0] === $to[0]) { array_shift($from); array_shift($to); }
        return str_repeat('../', count($from)) . implode('/', $to);
    }
    /**
     * Canonicalize every entity binding's search markup through the same asset
     * and route projections used for its source page.
     *
     * @param array<int,array<string,mixed>> $declarations
     * @param array<int,array<string,mixed>> $routes
     * @return array<int,array<string,mixed>>
     */
    private function canonicalEntityBindings(array $declarations, AssetReferenceCanonicalizer $references, array $routes, array $pages): array
    {
        foreach ( $declarations as &$declaration ) {
            if ( ! is_array($declaration) || ! isset($declaration['payload']['entities']) || ! is_array($declaration['payload']['entities']) ) {
                continue;
            }
            foreach ( $declaration['payload']['entities'] as &$entity ) {
                if ( ! is_array($entity) || ! isset($entity['bindings']) || ! is_array($entity['bindings']) ) {
                    continue;
                }
                foreach ( $entity['bindings'] as &$binding ) {
                    if ( is_array($binding) && is_string($binding['search_block_markup'] ?? null) && is_string($binding['source_path'] ?? null) ) {
                        $sourceMarkup = $binding['search_block_markup'];
                        if (!is_array($binding['projected_anchor'] ?? null)) $binding['projected_anchor'] = array_filter(array('schema' => 'blocks-engine/projected-binding-anchor/v1', 'source_block_markup' => $sourceMarkup, 'source_occurrence' => $binding['occurrence'] ?? null, 'source_position' => $binding['position'] ?? null), static fn(mixed $value): bool => null !== $value);
                        $markup = $references->content($sourceMarkup, $binding['source_path']);
                        $binding['search_block_markup'] = $this->routeLinks($markup, $binding['source_path'], $routes);
                    }
                }
                unset($binding);
            }
            unset($entity);
        }
        unset($declaration);

        $markupBySource = array_column($pages, 'canonical_block_markup', 'source_path');
        $sourceMarkupBySource = array_column($pages, '_projected_source_block_markup', 'source_path');
        $groups = array();
        foreach ($declarations as $declarationIndex => $declaration) foreach ($declaration['payload']['entities'] ?? array() as $entityIndex => $entity) foreach (is_array($entity) ? ($entity['bindings'] ?? array()) : array() as $bindingIndex => $binding) {
            $source = $binding['source_path'] ?? null; $search = $binding['search_block_markup'] ?? null; $position = $binding['position'] ?? null;
            $markup = is_string($source) ? ($markupBySource[$source] ?? null) : null;
            if (!is_string($source) || !is_string($search) || '' === $search || !is_int($binding['occurrence'] ?? null) || $binding['occurrence'] < 1 || !is_string($markup)) throw new InvalidArgumentException('A runtime entity binding lacks an exact emitted block anchor.');
            if (is_array($position) && ('blocks-engine/runtime-binding-position/v1' !== ($position['schema'] ?? null) || !is_int($position['block_index'] ?? null) || $position['block_index'] < 0 || !is_int($position['offset'] ?? null) || $position['offset'] < 0 || !is_int($position['length'] ?? null) || $position['length'] < 1)) throw new InvalidArgumentException('A runtime entity binding has an invalid emitted block position.');
            $groups[$source . "\n" . $search][] = array('id' => $declarationIndex . ':' . $entityIndex . ':' . $bindingIndex, 'source' => $source, 'declaration' => $declarationIndex, 'entity' => $entityIndex, 'binding' => $bindingIndex, 'source_offset' => is_array($position) ? $position['offset'] : null, 'source_occurrence' => $binding['occurrence'], 'canonical_position' => false, 'markup' => $markup, 'source_markup' => $sourceMarkupBySource[$source] ?? null, 'search' => $search);
        }
        foreach ($groups as $bindings) {
            usort($bindings, static fn(array $left, array $right): int => array($left['source_offset'] ?? PHP_INT_MAX, $left['source_occurrence'], $left['declaration'], $left['entity'], $left['binding']) <=> array($right['source_offset'] ?? PHP_INT_MAX, $right['source_occurrence'], $right['declaration'], $right['entity'], $right['binding']));
            $identities = array();
            foreach ($bindings as $identity) {
                $key = is_int($identity['source_offset']) ? 'offset:' . $identity['source_offset'] : 'occurrence:' . $identity['source_occurrence'];
                if (isset($identities[$key])) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
                $identities[$key] = true;
            }
            $markup = $bindings[0]['markup']; $search = $bindings[0]['search']; $ranges = array_values(array_filter(self::blockRanges($markup), static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length'])));
            if (count($ranges) < count($bindings)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
            $claimedOffsets = array(); $claimedBindings = array(); $resolved = array();
            foreach ($bindings as $identity) {
                $position = $declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['position'] ?? null;
                if (isset($declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['projected_anchor']) || true !== $identity['canonical_position'] || !self::bindingPosition($position, $markup, $search)) continue;
                if (isset($claimedOffsets[$position['offset']])) throw new InvalidArgumentException('A runtime entity binding has ambiguous canonical source-page anchors.');
                $claimedOffsets[$position['offset']] = true;
                $claimedBindings[$identity['id']] = true;
                $resolved[] = array($identity, array('offset' => $position['offset'], 'length' => $position['length']));
            }
            $remainingBindings = array_values(array_filter($bindings, static fn(array $identity): bool => !isset($claimedBindings[$identity['id']])));
            $remainingRanges = array_values(array_filter($ranges, static fn(array $range): bool => !isset($claimedOffsets[$range['offset']])));
            foreach ($remainingBindings as $identity) {
                $anchor = $declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']]['projected_anchor'] ?? null;
                if (!is_array($anchor) || 'blocks-engine/projected-binding-anchor/v1' !== ($anchor['schema'] ?? null) || !is_string($anchor['source_block_markup'] ?? null)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $sourceMarkup = $sourceMarkupBySource[$identity['source']] ?? null;
                if (!is_string($sourceMarkup)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $sourceRanges = self::blockRanges($sourceMarkup); $canonicalRanges = self::blockRanges($markup);
                $sourceMatches = array_values(array_filter($sourceRanges, static fn(array $range): bool => $anchor['source_block_markup'] === substr($sourceMarkup, $range['offset'], $range['length'])));
                if (isset($anchor['source_occurrence_count']) && $anchor['source_occurrence_count'] !== count($sourceMatches)) throw new InvalidArgumentException('A runtime entity binding source anchor is ambiguous after reprojection.');
                $candidates = array(); foreach ($sourceRanges as $index => $sourceRange) { $canonicalRange = $canonicalRanges[$index] ?? null; if ($anchor['source_block_markup'] === substr($sourceMarkup, $sourceRange['offset'], $sourceRange['length']) && $anchor['source_occurrence'] === self::occurrenceAtOffset($sourceMarkup, $anchor['source_block_markup'], $sourceRange['offset']) && is_array($canonicalRange) && $search === substr($markup, $canonicalRange['offset'], $canonicalRange['length']) && !isset($claimedOffsets[$canonicalRange['offset']])) $candidates[] = array('source' => $sourceRange, 'canonical' => $canonicalRange); }
                if (array() === $candidates) { $sourceMatches = array_values(array_filter(self::blockRanges($sourceMarkup), static fn(array $range): bool => $anchor['source_block_markup'] === substr($sourceMarkup, $range['offset'], $range['length']))); $canonicalMatches = array_values(array_filter(self::blockRanges($markup), static fn(array $range): bool => $search === substr($markup, $range['offset'], $range['length']) && !isset($claimedOffsets[$range['offset']]))); if (1 === count($sourceMatches) && 1 === count($canonicalMatches)) $candidates[] = array('source' => $sourceMatches[0], 'canonical' => $canonicalMatches[0]); }
                if (1 !== count($candidates)) throw new InvalidArgumentException('A runtime entity binding no longer identifies one exact emitted canonical block.');
                $claimedOffsets[$candidates[0]['canonical']['offset']] = true; $resolved[] = array($identity, $candidates[0]['canonical']);
            }
            foreach ($resolved as $resolvedEntry) {
                [$identity, $range] = $resolvedEntry;
                if (!is_array($range)) throw new InvalidArgumentException('A runtime entity binding no longer identifies an emitted canonical block.');
                $canonical = substr($markup, $range['offset'], $range['length']);
                $blockIndex = array_search($range, self::blockRanges($markup), true);
                if (!is_string($canonical) || '' === $canonical || !is_int($blockIndex)) throw new InvalidArgumentException('A runtime entity binding resolved to empty canonical block markup.');
                $binding = &$declarations[$identity['declaration']]['payload']['entities'][$identity['entity']]['bindings'][$identity['binding']];
                $binding['search_block_markup'] = $canonical;
                $binding['occurrence'] = self::occurrenceAtOffset($markup, $canonical, $range['offset']);
                $binding['position'] = array('schema' => 'blocks-engine/runtime-binding-position/v1', 'block_index' => $blockIndex, 'offset' => $range['offset'], 'length' => $range['length']);
                unset($binding);
            }
        }

        // Rewriting binding markup changes the payload, so drop the derived
        // hashes and re-normalize to recompute canonical identity and content
        // hashes; the reconciliation identity (source path + kind) is stable.
        foreach ( $declarations as &$declaration ) {
            if ( is_array($declaration) ) {
                foreach ($declaration['payload']['entities'] ?? array() as &$entity) foreach ($entity['bindings'] ?? array() as &$binding) unset($binding['_canonical_position']);
                unset($binding, $entity);
                unset($declaration['payload_hash'], $declaration['content_hash']);
            }
        }
        unset($declaration);

        return RuntimeDeclarations::normalizeList($declarations);
    }

    /** @param array<int,array<string,mixed>> $routes */
    private function routeLinks(string $content, string $origin, array $routes): string
    {
        $replace = fn(array $match): string => $match[1] . ($this->routeReference($match[2], $origin, $routes) ?? $match[2]) . $match[3];
        $content = preg_replace_callback('/(\b(?:href|action)\s*=\s*["\'])([^"\']+)(["\'])/i', $replace, $content) ?? $content;
        $jsonPattern = '/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i';
        $offset = 0;
        while (preg_match($jsonPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            if (null !== $this->routeReference($match[2][0], $origin, $routes)) {
                return preg_replace_callback($jsonPattern, $replace, $content) ?? $content;
            }
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        return $content;
    }
    /** @param array<int,array<string,mixed>> $routes */
    private function routeReference(string $value, string $origin, array $routes): ?string
    {
        if ('' === $value || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $value)) return null;
        $suffix = ''; if (preg_match('/^([^?#]*)(.*)$/', $value, $match)) { $value = $match[1]; $suffix = $match[2]; }
        if (str_contains($value, '%') || str_contains($value, '\\')) return null;
        // A root-relative link (e.g. /contact.html) targets the site web root,
        // which is the entrypoint's packaging directory. Resolve it against that
        // root so it matches the document source path (website/contact.html)
        // rather than a bare top-level path the artifact never contains.
        $entryRoot = self::entryRootFromDocuments($routes);
        $path = str_starts_with($value, '/') ? ('' === $entryRoot ? ltrim($value, '/') : $entryRoot . '/' . ltrim($value, '/')) : self::resolveRouteSource($origin, $value);
        if (null === $path) return null;
        foreach ($routes as $route) if (is_array($route) && $path === ($route['source_path'] ?? null)) return $route['target_path'] . $suffix;
        return null;
    }
    private static function resolveRouteSource(string $origin, string $value): ?string { $segments = array_filter(explode('/', dirname($origin)), static fn(string $segment): bool => '' !== $segment && '.' !== $segment); foreach (explode('/', $value) as $segment) { if ('' === $segment || '.' === $segment) continue; if ('..' === $segment) { if (array() === $segments) return null; array_pop($segments); continue; } $segments[] = $segment; } return implode('/', $segments); }
    /** @param array<string,mixed> $plan @param array<string,array<string,mixed>> $writes */
    private static function assertScaffold(array $plan, array $writes): void
    {
        $style = $writes['style.css'] ?? null;
        $themeJson = $writes['theme.json'] ?? null;
        if (!is_array($style) || 'theme_scaffold' !== ($style['kind'] ?? null) || 'wordpress-site-plan/style.css' !== ($style['source_path'] ?? null) || !preg_match('/^\/\*\nTheme Name:\s+[^\n]+\nText Domain:\s+[a-z0-9-]+\n\*\/\n$/', (string) ($style['payload']['data'] ?? ''))) throw new InvalidArgumentException('WordPress site plan style.css scaffold is invalid.');
        if (!is_array($themeJson) || 'theme_scaffold' !== ($themeJson['kind'] ?? null) || 'wordpress-site-plan/theme.json' !== ($themeJson['source_path'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json scaffold is invalid.');
        try { $theme = json_decode((string) $themeJson['payload']['data'], true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InvalidArgumentException('WordPress site plan theme.json is not valid JSON.'); }
        if (!is_array($theme) || 3 !== ($theme['version'] ?? null) || !is_array($theme['settings'] ?? null) || !is_array($theme['styles'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json shape is unsupported.');
        $bootstrap = $writes['functions.php'] ?? null;
        $scriptLoading = (new self())->scriptLoading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        if (self::needsBootstrap($plan['assets'], $scriptLoading['scripts'])) {
            if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || self::bootstrap($plan['assets'], $scriptLoading['scripts']) !== ($bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
        } elseif (null !== ($plan['theme']['bootstrap'] ?? null) || isset($bootstrap)) throw new InvalidArgumentException('WordPress site plan declares an unnecessary bootstrap.');
    }
    /** @param array<int,mixed> $declarations @param array<int,array<string,mixed>> $assets @param array<string,array<string,mixed>> $writes */
    private static function assertAssetPublicationDeclarations(array $declarations, array $assets, array $writes): void
    {
        $assetsBySource = array(); foreach ($assets as $asset) $assetsBySource[$asset['source_path']] = $asset;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || 'asset_publication' !== ($declaration['kind'] ?? null)) continue;
            $asset = $assetsBySource[$declaration['source_path']] ?? null;
            $provenance = is_array($asset) ? array('source_path' => $asset['source_path'], 'source' => $asset['source'], 'hash' => $asset['hash'], 'mime_type' => $asset['mime_type'], 'role' => $asset['role'], 'bytes' => $asset['bytes']) : null;
            if (!is_array($asset) || !self::hash($asset['hash'] ?? null) || ($asset['role'] ?? null) !== $declaration['source_role'] || ($asset['mime_type'] ?? null) !== $declaration['mime_type'] || ($asset['hash'] ?? null) !== $declaration['source_hash'] || ($asset['content_hash'] ?? null) !== $declaration['expected_content_hash'] || !is_array($declaration['provenance'] ?? null) || RuntimeDeclarations::canonicalJson($declaration['provenance']) !== RuntimeDeclarations::canonicalJson($provenance) || ($declaration['sanitization']['input_hash'] ?? null) !== $asset['hash']) throw new InvalidArgumentException('Asset publication declaration does not match its declared source asset hashes or provenance.');
            if ('image/svg+xml' === $asset['mime_type'] && (!is_string($asset['content'] ?? null) || !self::safeSvg($asset['content']))) throw new InvalidArgumentException('Asset publication SVG payload is unsafe.');
            if (!isset($declaration['transformation']) && $asset['hash'] !== $asset['content_hash']) throw new InvalidArgumentException('Asset publication plain source hash must match its canonical payload.');
            $write = $writes[$asset['target_path']] ?? null;
            $writePayload = is_array($write) ? ($write['canonical_payload'] ?? ($write['payload']['data'] ?? null)) : null;
            if (!is_array($write) || 'theme_asset' !== ($write['kind'] ?? null) || ($write['source_path'] ?? null) !== $declaration['source_path'] || !is_string($writePayload) || self::contentHash($writePayload) !== $asset['content_hash'] || ($write['canonical_payload_hash'] ?? $write['payload_hash'] ?? null) !== $asset['content_hash']) throw new InvalidArgumentException('Asset publication declaration does not resolve to its declared asset write.');
            foreach ($declaration['reference_targets'] as $target) {
                $write = $writes[$target['target_path']] ?? null;
                $token = self::TOKEN_PREFIX . $target['token'] . '}}';
                if (!is_array($write)) throw new InvalidArgumentException('Asset publication declaration references an unbound destination token occurrence.');
                $canonical = $write['canonical_payload'] ?? ($write['payload']['data'] ?? null);
                if ($write['reconciliation_identity'] !== $target['write_reconciliation_identity'] || 'utf8' !== ($write['payload']['encoding'] ?? null) || !is_string($canonical) || $target['count'] !== substr_count($canonical, $token)) throw new InvalidArgumentException('Asset publication declaration references an unbound destination token occurrence.');
                if ('css_url' === $target['context'] && $target['count'] !== preg_match_all('~url\(\s*["\']?' . preg_quote($token, '~') . '["\']?\s*\)~i', $canonical)) throw new InvalidArgumentException('Asset publication declaration reference context does not match its CSS token occurrence.');
            }
            if (isset($declaration['transformation'])) {
                if ($declaration['transformation']['expected_content_hash'] !== $declaration['expected_content_hash']) throw new InvalidArgumentException('Asset publication transformation final hash is contradictory.');
                self::assertPublicationTransformationInputs($declaration['transformation'], $assetsBySource);
            }
        }
    }
    /** @param array<string,mixed> $transformation @param array<string,array<string,mixed>> $assetsBySource */
    private static function assertPublicationTransformationInputs(array $transformation, array $assetsBySource): void
    {
        $css = array(); foreach ($transformation['css_source_paths'] as $path) { $asset = $assetsBySource[$path] ?? null; if (!is_array($asset) || 'text/css' !== ($asset['mime_type'] ?? null) || !is_string($asset['content'] ?? null)) throw new InvalidArgumentException('Asset publication transformation has an unbound CSS input.'); $css[] = array('source_path' => $path, 'content_hash' => self::contentHash($asset['content']), 'font_faces' => self::fontFaces($asset['content'], $path, $transformation['font_source_paths'], array_values($assetsBySource), array_flip(array_keys($assetsBySource)))); }
        $fonts = array(); foreach ($transformation['font_source_paths'] as $path) { $asset = $assetsBySource[$path] ?? null; if (!is_array($asset) || !str_starts_with((string) ($asset['mime_type'] ?? ''), 'font/')) throw new InvalidArgumentException('Asset publication transformation has an unbound font input.'); $fonts[] = array('source_path' => $path, 'content_hash' => $asset['content_hash']); }
        if (RuntimeDeclarations::hash(array('css' => $css, 'fonts' => $fonts)) !== ($transformation['input_hash'] ?? null)) throw new InvalidArgumentException('Asset publication transformation inputs have stale hashes.');
    }
    /** @param array<int,array<string,mixed>> $operations @param array<int,array<string,mixed>> $pages */
    private static function assertOperations(array $operations, array $pages): void
    {
        $pagesBySource = array(); foreach ($pages as $page) $pagesBySource[$page['source_path']] = $page;
        $created = array(); $reading = 0;
        foreach ($operations as $index => $operation) {
            if (!is_array($operation) || $index !== ($operation['order'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            if ('create_page' === ($operation['kind'] ?? null)) { $page = $pagesBySource[$operation['source_path'] ?? ''] ?? null; if (!is_array($page) || $page['reconciliation_identity'] !== ($operation['reconciliation_identity'] ?? null) || (isset($operation['post_type']) && $page['post_type'] !== $operation['post_type']) || $page['route']['path'] !== ($operation['route_path'] ?? null) || $page['slug'] !== ($operation['slug'] ?? null) || $page['parent_source_path'] !== ($operation['parent_source_path'] ?? null) || !is_bool($operation['synthetic'] ?? null) || ('' !== $page['parent_source_path'] && !isset($created[$page['parent_source_path']]))) throw new InvalidArgumentException('WordPress site plan create_page operation is invalid.'); $created[$page['source_path']] = true; continue; }
            if ('site_reading' !== ($operation['kind'] ?? null) || ++$reading > 1 || 'page' !== ($operation['show_on_front'] ?? null) || !is_string($operation['front_page_source_path'] ?? null) || !is_string($operation['front_page_reconciliation_identity'] ?? null)) throw new InvalidArgumentException('WordPress site plan operation is invalid.');
            $page = $pagesBySource[$operation['front_page_source_path']] ?? null; if (!is_array($page) || empty($page['entrypoint']) || $page['reconciliation_identity'] !== $operation['front_page_reconciliation_identity'] || !isset($created[$page['source_path']])) throw new InvalidArgumentException('WordPress site plan operation references an invalid front page.');
        }
        if (count($created) !== count($pages) || $reading !== (array() === array_filter($pages, static fn(array $page): bool => !empty($page['entrypoint'])) ? 0 : 1)) throw new InvalidArgumentException('WordPress site plan operations are incomplete.');
    }
    private static function assertNoLocalBrowserReferences(string $content, string $sourcePath = '', string $context = 'markup'): void
    {
        $assertReference = static function (string $candidate, string $attribute, string $element = '') use ($sourcePath, $context): void { $url = trim(preg_split('/\s+/', trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? ''); $route = str_starts_with($url, '/') && (str_starts_with($attribute, 'json:route_') || ('href' === $attribute && in_array($element, array('a', 'area'), true)) || ('action' === $attribute && 'form' === $element)); if ('' !== $url && !str_starts_with($url, self::TOKEN_PREFIX) && !$route && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) throw new ValidationException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url), array('source_path' => $sourcePath, 'document_kind' => $context, 'declaration_kind' => 'browser_reference', 'declaration_index' => 0, 'reason' => 'unresolved_local_browser_reference', 'fields' => array('context' => $context, 'attribute' => $attribute, 'value' => $url))); };
        $assertCss = static function (string $css, string $cssContext) use ($assertReference): void { \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), static function (string $url) use ($assertReference, $cssContext): string { $assertReference($url, $cssContext . ':url'); return $url; }); if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $assertReference((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), $cssContext . ':@import'); };
        $assertJsonAttributes = null;
        $assertJsonAttributes = static function (array $attributes, bool $route) use (&$assertJsonAttributes, $assertReference): void {
            foreach ($attributes as $name => $value) {
                if (is_array($value)) { $assertJsonAttributes($value, $route); continue; }
                if (!is_string($name) || !is_string($value) || !in_array(strtolower($name), array('url', 'src', 'href', 'poster', 'action', 'srcset'), true)) continue;
                $routeField = $route && 'url' === strtolower($name) ? 'route_url' : (in_array(strtolower($name), array('href', 'action'), true) ? 'route_' . strtolower($name) : strtolower($name));
                foreach ('srcset' === strtolower($name) ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, 'json:' . $routeField);
            }
        };
        foreach (self::htmlMarkupNodes($content) as $node) {
            if ('tag' === $node['kind']) foreach ($node['attributes'] as $name => $value) {
                if (!in_array($name, array('xlink:href', 'srcset', 'src', 'href', 'poster', 'action', 'style'), true)) continue;
                if ('action' === $name && 'form' !== $node['name']) continue;
                if ('style' === $name) { $assertCss($value, 'style_attribute'); continue; }
                foreach ('srcset' === $name ? self::srcsetCandidates($value) : array($value) as $candidate) $assertReference($candidate, $name, $node['name']);
            }
            if ('style' === $node['kind']) $assertCss($node['css'], 'style_block');
            if ('comment' === $node['kind'] && preg_match('~^\s*wp:~i', $node['content'])) {
                $attributes = self::blockCommentAttributes($node['content']);
                if (is_array($attributes)) {
                    $assertJsonAttributes($attributes, self::jsonUrlIsRoute($node['content']));
                } elseif (preg_match_all('~(?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022)(.*?)(?:"|\\\\u0022)~is', $node['content'], $fields, PREG_SET_ORDER)) {
                    $route = self::jsonUrlIsRoute($node['content']);
                    foreach ($fields as $field) {
                        $name = strtolower($field[1]);
                        $routeField = $route && 'url' === $name ? 'route_url' : (in_array($name, array('href', 'action'), true) ? 'route_' . $name : $name);
                        foreach ('srcset' === $name ? self::srcsetCandidates($field[2]) : array($field[2]) as $candidate) $assertReference(str_replace('\\/', '/', (string) $candidate), 'json:' . $routeField);
                    }
                }
            }
        }
    }
    /** @return array<string,mixed>|null */
    private static function blockCommentAttributes(string $comment): ?array { if (!preg_match('~^\s*wp:[^\s{]+\s+(\{.*\})\s*/?\s*$~s', $comment, $payload)) return null; $attributes = json_decode($payload[1], true); return is_array($attributes) ? $attributes : null; }
    private static function jsonUrlIsRoute(string $comment): bool { if (!preg_match('~^\s*wp:([^\s{]+)~i', $comment, $block)) return false; return in_array(strtolower($block[1]), array('navigation-link', 'navigation-submenu', 'button', 'social-link'), true); }
    /** @return array<int,string> */
    private static function srcsetCandidates(string $srcset): array
    {
        $candidates = array(); $length = strlen($srcset); $offset = 0;
        while ($offset < $length) {
            while ($offset < $length && (ctype_space($srcset[$offset]) || ',' === $srcset[$offset])) ++$offset;
            if ($offset >= $length) break;
            $start = $offset; $data = str_starts_with(strtolower(substr($srcset, $offset)), 'data:');
            while ($offset < $length && !ctype_space($srcset[$offset]) && ($data || ',' !== $srcset[$offset])) ++$offset;
            $url = substr($srcset, $start, $offset - $start); if ('' !== $url) $candidates[] = $url;
            while ($offset < $length && ',' !== $srcset[$offset]) ++$offset;
            if ($offset < $length) ++$offset;
        }
        return $candidates;
    }
    /** @return array<int,array<string,mixed>> */
    private static function htmlMarkupNodes(string $content): array
    {
        $nodes = array(); $length = strlen($content); $offset = 0;
        while ($offset < $length) {
            $start = strpos($content, '<', $offset); if (false === $start) break;
            if (str_starts_with(substr($content, $start), '<!--')) { $end = strpos($content, '-->', $start + 4); if (false === $end) break; $nodes[] = array('kind' => 'comment', 'content' => substr($content, $start + 4, $end - $start - 4)); $offset = $end + 3; continue; }
            if ($start + 1 < $length && '!' === $content[$start + 1]) { if (str_starts_with(substr($content, $start), '<![CDATA[')) { $end = strpos($content, ']]>', $start + 9); $offset = false === $end ? $length : $end + 3; continue; } $cursor = $start + 2; $quote = ''; while ($cursor < $length) { if ('' !== $quote) { if ($quote === $content[$cursor]) $quote = ''; ++$cursor; continue; } if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; continue; } if ('>' === $content[$cursor++]) break; } $offset = $cursor; continue; }
            $cursor = $start + 1; if ($cursor >= $length || !ctype_alpha($content[$cursor])) { $offset = $cursor; continue; }
            $nameStart = $cursor; while ($cursor < $length && preg_match('/[A-Za-z0-9:-]/', $content[$cursor])) ++$cursor;
            $name = strtolower(substr($content, $nameStart, $cursor - $nameStart)); $attributes = array();
            while ($cursor < $length) {
                while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) break;
                if ('>' === $content[$cursor] || ('/' === $content[$cursor] && $cursor + 1 < $length && '>' === $content[$cursor + 1])) { $cursor += '>' === $content[$cursor] ? 1 : 2; $nodes[] = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); if ('style' === $name) { $closing = self::rawTextEnd($content, $name, $cursor); if (null !== $closing) { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor, $closing[0] - $cursor)); $offset = $closing[1]; } else { $nodes[] = array('kind' => 'style', 'css' => substr($content, $cursor)); $offset = $length; } continue 2; } if ('plaintext' === $name) { $offset = $length; continue 2; } if (in_array($name, array('script', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'), true)) { $closing = self::rawTextEnd($content, $name, $cursor); if ('script' === $name && null !== $closing) $nodes[] = array('kind' => 'rawtext', 'name' => $name, 'attributes' => $attributes, 'content' => substr($content, $cursor, $closing[0] - $cursor)); $offset = null === $closing ? $length : $closing[1]; continue 2; } $offset = $cursor; continue 2; }
                $attributeStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && !str_contains('=/>', $content[$cursor])) ++$cursor;
                if ($attributeStart === $cursor) { ++$cursor; continue; }
                $attribute = strtolower(substr($content, $attributeStart, $cursor - $attributeStart)); while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length || '=' !== $content[$cursor]) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; continue; }
                ++$cursor; while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
                if ($cursor >= $length) { if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = ''; break; }
                if ('"' === $content[$cursor] || "'" === $content[$cursor]) { $quote = $content[$cursor++]; $valueStart = $cursor; while ($cursor < $length && $quote !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart); if ($cursor < $length) ++$cursor; continue; }
                $valueStart = $cursor; while ($cursor < $length && !ctype_space($content[$cursor]) && '>' !== $content[$cursor]) ++$cursor; if (!array_key_exists($attribute, $attributes)) $attributes[$attribute] = substr($content, $valueStart, $cursor - $valueStart);
            }
            $nodes[] = array('kind' => 'tag', 'name' => $name, 'attributes' => $attributes); $offset = $cursor;
        }
        return $nodes;
    }
    /** @return array{0:int,1:int}|null */
    private static function rawTextEnd(string $content, string $name, int $offset): ?array
    {
        if (!preg_match('~</' . preg_quote($name, '~') . '(?=[\s/>])[^>]*>~i', $content, $match, PREG_OFFSET_CAPTURE, $offset)) return null;
        return array($match[0][1], $match[0][1] + strlen($match[0][0]));
    }
    /** @param array<string,bool> $tokens @param array<string,array<string,mixed>> $writes */
    private static function assertResolution(array $plan, array $tokens, array $writes): void
    {
        if (!isset($plan['resolution'])) return;
        $resolution = $plan['resolution'];
        if (!is_array($resolution) || array_keys($resolution) !== array('schema', 'theme_uri', 'runtime_capabilities', 'asset_publication_references', 'unsupported_optional_capabilities') || WordPressSitePlanResolver::RESOLUTION_SCHEMA !== ($resolution['schema'] ?? null) || !is_string($resolution['theme_uri'] ?? null) || !is_array($resolution['runtime_capabilities'] ?? null) || !is_array($resolution['asset_publication_references'] ?? null) || !is_array($resolution['unsupported_optional_capabilities'] ?? null) || WordPressSitePlanResolver::normalizeThemeUri($resolution['theme_uri']) !== $resolution['theme_uri']) throw new InvalidArgumentException('WordPress site plan resolution is malformed or fabricated.');
        $references = WordPressSitePlanResolver::references($plan['reference_tokens'], $resolution['theme_uri']);
        $expectedPublicationReferences = WordPressSitePlanResolver::publicationReferences($plan['runtime_declarations'], $references);
        try { $capabilities = WordPressSitePlanResolver::normalizeRuntimeCapabilities($resolution['runtime_capabilities']); $unsupported = WordPressSitePlanResolver::unsupportedOptionalCapabilities($plan['runtime_declarations'], $capabilities); } catch (InvalidArgumentException) { throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.'); }
        if ($resolution['runtime_capabilities'] !== $capabilities || $resolution['asset_publication_references'] !== $expectedPublicationReferences || $resolution['unsupported_optional_capabilities'] !== $unsupported) throw new InvalidArgumentException('WordPress site plan publication resolution is malformed or stale.');
        foreach (array('pages', 'template_parts', 'templates') as $kind) foreach ($plan[$kind] as $document) {
            if (!is_array($document) || !is_string($document['canonical_block_markup'] ?? null) || !is_string($document['resolved_block_markup'] ?? null) || WordPressSitePlanResolver::resolvePayload($document['canonical_block_markup'], $references) !== $document['resolved_block_markup']) throw new InvalidArgumentException("WordPress site plan resolved {$kind} payload is not canonical.");
        }
        foreach ($writes as $write) {
            if ('utf8' !== ($write['payload']['encoding'] ?? null)) { if (isset($write['canonical_payload'], $write['canonical_payload_hash'])) throw new InvalidArgumentException('WordPress site plan binary write cannot carry a resolution projection.'); continue; }
            if (!is_string($write['canonical_payload'] ?? null) || !self::hash($write['canonical_payload_hash'] ?? null) || $write['canonical_payload_hash'] !== self::contentHash($write['canonical_payload']) || WordPressSitePlanResolver::resolvePayload($write['canonical_payload'], $references) !== $write['payload']['data']) throw new InvalidArgumentException('WordPress site plan resolved write payload is not canonical.');
        }
        self::assertResolvedMetadata($plan, $references);
    }
    /** @param array<string,string> $references */
    private static function assertResolvedMetadata(array $plan, array $references): void
    {
        foreach (array('pages', 'template_parts') as $kind) foreach ($plan[$kind] as $document) foreach (array('links', 'scripts') as $declarationKind) foreach ($document['document_metadata'][$declarationKind] ?? array() as $declaration) {
            if (!is_array($declaration)) throw new InvalidArgumentException('WordPress site plan resolved metadata declaration is invalid.');
            if (is_string($declaration['asset_reference'] ?? null)) {
                if (!is_string($declaration['resolved_url'] ?? null) || WordPressSitePlanResolver::resolvePayload($declaration['asset_reference'], $references) !== $declaration['resolved_url']) throw new InvalidArgumentException('WordPress site plan resolved metadata URL is missing, stale, or tampered.');
                continue;
            }
            if (array_key_exists('resolved_url', $declaration)) throw new InvalidArgumentException('WordPress site plan external metadata URL must not carry a resolved alias.');
        }
    }
    private static function assertRoute(array $page, string $entryRoot = ''): void { $route = $page['route'] ?? null; $expected = is_string($page['metadata']['route_path'] ?? null) && '' !== $page['metadata']['route_path'] ? self::canonicalRoutePath($page['metadata']['route_path']) : self::pageRoutePath($page['source_path'], $entryRoot); if (!is_array($route) || !is_string($route['path'] ?? null) || !preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?$~', $route['path']) || !is_string($route['parent_path'] ?? null) || !is_string($route['slug'] ?? null) || self::parentRoutePath($route['path']) !== $route['parent_path'] || self::routeSlug($route['path']) !== $route['slug'] || (!isset($page['synthetic']) && $route['path'] !== $expected) || (isset($page['synthetic']) && (true !== $page['synthetic'] || !str_starts_with((string) ($page['source_path'] ?? ''), 'wordpress-site-plan/routes/')))) throw new InvalidArgumentException('WordPress site plan page route is invalid.'); }
    /** @param array<string,string> $tokens */
    private static function assertDocument(mixed $document, string $kind, bool $part, array $tokens): void { if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['slug']??null)||!is_string($document['title']??null)||!is_string($document['post_type']??null)||!is_string($document['parent_source_path']??null)||!is_bool($document['entrypoint']??null)||!is_string($document['canonical_block_markup']??null)||''===trim($document['canonical_block_markup'])||!is_array($document['metadata']??null)||!is_array($document['document_metadata']??null)||!is_array($document['provenance']??null)||!self::hash($document['reconciliation_identity']??null)||!self::hash($document['content_hash']??null)||($part&&(!is_string($document['area']??null)||''===$document['area']||!is_array($document['placement']??null)))||(!$part&&(null!==($document['area']??null)||null!==($document['placement']??null))))throw new InvalidArgumentException("WordPress site plan {$kind} is structurally invalid.");if($part&&$document['reconciliation_identity']!==self::identity('template-part',$document['source_path'],'parts/'.$document['slug'].'.html'))throw new InvalidArgumentException('WordPress site plan template part identity is invalid.');if($part&&in_array($document['placement']['kind']??null,array('entry_shell','shared_shell'),true)&&(!is_string($document['placement']['source_path']??null)||!is_array($document['placement']['template_slugs']??null)||array()=== $document['placement']['template_slugs']))throw new InvalidArgumentException('WordPress site plan template part placement is invalid.');if(!$part)self::assertContentDecision($document);self::assertDocumentMetadata($document['document_metadata'],$tokens,$document['source_path'],$kind);self::assertTokens($document['canonical_block_markup'],$tokens);self::assertNoLocalBrowserReferences($document['canonical_block_markup'],$document['source_path'],$kind); }
    /** @param array<string,mixed> $document */
    private static function assertContentDecision(array $document): void
    {
        $decision = $document['content_decision'] ?? null;
        if (null === $decision && !array_key_exists('publication_timestamp', $document)) return;
        if (!is_array($decision) || 'blocks-engine/content-decision/v1' !== ($decision['schema'] ?? null) || !in_array($decision['state'] ?? null, array('declared', 'inferred', 'defaulted'), true) || !is_string($decision['post_type'] ?? null) || $decision['post_type'] !== $document['post_type'] || !is_array($decision['evidence'] ?? null) || count($decision['evidence']) > 16) throw new InvalidArgumentException('WordPress site plan content decision is invalid.');
        $provenance = $decision['provenance'] ?? null;
        if (('declared' === $decision['state'] && (!is_string($provenance) || !preg_match('/^(?:frontmatter|metadata):[a-z_]+$/', $provenance))) || ('declared' !== $decision['state'] && null !== $provenance) || ('defaulted' === $decision['state'] && array() !== $decision['evidence']) || ('inferred' === $decision['state'] && array() === $decision['evidence'])) throw new InvalidArgumentException('WordPress site plan content decision provenance is invalid.');
        $timestamps = array(); foreach ($decision['evidence'] as $evidence) { if (!is_array($evidence) || array_diff(array_keys($evidence), array('source', 'publication_timestamp')) || !is_string($evidence['source'] ?? null) || '' === $evidence['source'] || strlen($evidence['source']) > 128) throw new InvalidArgumentException('WordPress site plan content decision evidence is invalid.'); if (isset($evidence['publication_timestamp'])) { if (!self::utcTimestamp($evidence['publication_timestamp'])) throw new InvalidArgumentException('WordPress site plan content decision timestamp is invalid.'); $timestamps[] = $evidence['publication_timestamp']; } }
        if (isset($document['publication_timestamp']) && (!self::utcTimestamp($document['publication_timestamp']) || !in_array($document['publication_timestamp'], $timestamps, true))) throw new InvalidArgumentException('WordPress site plan publication timestamp is invalid.');
    }
    private static function utcTimestamp(mixed $value): bool { if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) return false; try { return (new \DateTimeImmutable($value))->format('Y-m-d\\TH:i:s\\Z') === $value; } catch (\Exception) { return false; } }
    private static function normalizePublicationTimestamp(string $value): ?string
    {
        $format = null; $input = $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $format = '!Y-m-d';
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value, $match)) { $input = $match[1] . ('Z' === $match[2] ? '+00:00' : $match[2]); $format = '!Y-m-d\\TH:i:sP'; }
        if (null === $format) return null;
        $date = \DateTimeImmutable::createFromFormat($format, $input); $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) return null;
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
    /** @param array<string,mixed> $metadata @param array<string,bool> $tokens */
    private static function assertDocumentMetadata(array $metadata, array $tokens, string $sourcePath, string $documentKind): void
    {
        if (!is_array($metadata['source_context'] ?? null) || !self::safePath($metadata['source_context']['source_path'] ?? null) || !is_string($metadata['source_context']['kind'] ?? null) || !is_string($metadata['title'] ?? null) || !is_array($metadata['title_declaration'] ?? null) || 0 !== ($metadata['title_declaration']['order'] ?? null) || 'head' !== ($metadata['title_declaration']['placement'] ?? null) || !is_array($metadata['meta'] ?? null) || !is_array($metadata['links'] ?? null) || !is_array($metadata['scripts'] ?? null)) throw new InvalidArgumentException('WordPress site plan document metadata is structurally invalid.');
        foreach ($metadata['meta'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'charset', 'name', 'property', 'http_equiv', 'content'))) self::invalidDeclaration('meta declaration', 'meta', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
        }
        foreach ($metadata['links'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (!is_string($row['asset_reference'] ?? null) && !self::explicitUrl($row['url'] ?? null)) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'unresolved_local_url', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'rel', 'type', 'media', 'integrity', 'crossorigin', 'referrerpolicy', 'as', 'fetchpriority', 'sizes', 'asset_reference', 'url', 'resolved_url'))) self::invalidDeclaration('link declaration', 'link', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
            if (is_string($row['asset_reference'] ?? null)) self::assertTokens($row['asset_reference'], $tokens);
        }
        foreach ($metadata['scripts'] as $index => $row) {
            if (!is_array($row)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_structure', $row);
            if ($index !== ($row['order'] ?? null)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_order', $row);
            if (!in_array($row['placement'] ?? null, array('head', 'body'), true)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_placement', $row);
            if (!is_string($row['asset_reference'] ?? null) && !self::explicitUrl($row['url'] ?? null) && 'inline' !== ($row['source_kind'] ?? null)) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'unresolved_local_url', $row);
            if (array_diff(array_keys($row), array('order', 'placement', 'async', 'defer', 'module', 'nomodule', 'effective_loading', 'type', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority', 'asset_reference', 'url', 'resolved_url', 'source_kind', 'body_hash', 'selector', 'superseded_by'))) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'unsupported_field', $row);
            if (!is_bool($row['defer'] ?? null) || !is_bool($row['async'] ?? null) || !is_bool($row['module'] ?? null) || !is_bool($row['nomodule'] ?? null) || !in_array($row['effective_loading'] ?? null, array('blocking', 'defer', 'async'), true) || ($row['async'] && 'async' !== $row['effective_loading']) || (!$row['async'] && ($row['defer'] || $row['module']) && 'defer' !== $row['effective_loading']) || (!$row['async'] && !$row['defer'] && !$row['module'] && 'blocking' !== $row['effective_loading'])) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_loading_semantics', $row);
            if (isset($row['superseded_by']) && (!is_string($row['selector'] ?? null) || !preg_match('/^script:nth-of-type\([1-9][0-9]*\)$/', $row['selector']) || !is_string($row['superseded_by']) || !preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $row['superseded_by']) || !self::hash($row['body_hash'] ?? null))) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'invalid_supersession_metadata', $row);
            if (is_string($row['asset_reference'] ?? null) && preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $row['asset_reference'], $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) self::invalidDeclaration('script declaration', 'script', $index, $sourcePath, $documentKind, 'undeclared_asset_token', $row);
        }
    }
    /** @param mixed $row */
    private static function invalidDeclaration(string $label, string $declarationKind, int|string $index, string $sourcePath, string $documentKind, string $reason, mixed $row): never
    {
        $fields = array();
        $truncated = 0;
        if (is_array($row)) {
            ksort($row);
            foreach ($row as $key => $value) {
                if (!is_string($key) || (!is_scalar($value) && null !== $value) || 20 === count($fields)) { ++$truncated; continue; }
                $key = substr($key, 0, 64);
                if (isset($fields[$key])) { ++$truncated; continue; }
                $fields[$key] = is_string($value) ? substr($value, 0, 256) : $value;
            }
        }
        $context = array('source_path' => $sourcePath, 'document_kind' => $documentKind, 'declaration_kind' => $declarationKind, 'declaration_index' => $index, 'reason' => $reason, 'fields' => $fields);
        if (0 < $truncated) $context['fields_truncated'] = $truncated;
        throw new ValidationException("WordPress site plan {$label} is invalid: {$reason}.", $context);
    }
    /** @param array<string,mixed> $reporting @param array<string,bool> $pagePaths @param array<string,bool> $tokens */
    private static function assertReporting(array $reporting, array $pagePaths, array $tokens, array $diagnostics): void { if(!is_array($reporting['source_documents']??null)||!is_array($reporting['metrics']??null)||!is_array($reporting['core_html_fallback_evidence']??null)||!is_array($reporting['diagnostic_codes']??null))throw new InvalidArgumentException('WordPress site plan reporting summary is invalid.');$sources=array();foreach($reporting['source_documents'] as $document){if(!is_array($document)||!self::safePath($document['source_path']??null)||!is_string($document['kind']??null)||!is_string($document['body_format']??null)||!is_bool($document['block_document']??null)||!is_array($document['provenance']??null))throw new InvalidArgumentException('WordPress site plan source document summary is invalid.');self::unique($sources,$document['source_path'],'source document');}if(count($sources)!==count($pagePaths)||array_keys($sources)!==array_keys($pagePaths))throw new InvalidArgumentException('WordPress site plan source document summaries do not match pages.');foreach(array('source_document_count','block_document_count','native_block_count','fallback_count') as $key)if(!is_int($reporting['metrics'][$key]??null))throw new InvalidArgumentException('WordPress site plan reporting metric is invalid.');$linked=array_fill_keys($reporting['diagnostic_codes'],true);foreach($reporting['diagnostic_codes'] as $code)if(!is_string($code)||''===$code)throw new InvalidArgumentException('WordPress site plan diagnostic linkage is invalid.');foreach($diagnostics as $diagnostic)if(is_array($diagnostic)&&is_string($diagnostic['code']??null)&&!isset($linked[$diagnostic['code']]))throw new InvalidArgumentException('WordPress site plan diagnostics are not linked to reporting.');}
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens, bool $browserReferences): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !self::hash($write['reconciliation_identity'] ?? null) || !self::hash($write['payload_hash'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64','reference'), true) || $write['reconciliation_identity'] !== self::identity('write', $write['source_path'], $write['target_path'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('reference' === $write['payload']['encoding']) { $reference = self::payloadReference($write['payload']['reference'] ?? null); if (!is_array($reference) || ($write['raw_sha256'] ?? null) !== $reference['sha256'] || $write['payload_hash'] !== self::contentHash(RuntimeDeclarations::canonicalJson($reference))) throw new InvalidArgumentException('WordPress site plan write has an invalid payload reference.'); return; } if (!is_string($write['payload']['data'] ?? null) || $write['payload_hash'] !== self::contentHash($write['payload']['data'])) throw new InvalidArgumentException('WordPress site plan write has a stale payload hash or invalid structure.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) { self::assertTokens($write['payload']['data'], $tokens); if ($browserReferences) self::assertNoLocalBrowserReferences(str_ends_with(strtolower($write['target_path']), '.css') ? '<style>' . $write['payload']['data'] . '</style>' : $write['payload']['data'], $write['source_path'], 'write'); } }
    /** @return array{schema:string,id:string,bytes:int,sha256:string}|null */
    private static function payloadReference(mixed $reference): ?array { if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !self::hash($reference['sha256'] ?? null)) return null; return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']); }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,bool> $values */
    private static function unique(array &$values, string $value, string $kind): void { $key = strtolower($value); if (isset($values[$key])) throw new InvalidArgumentException("WordPress site plan has colliding {$kind}s."); $values[$key] = true; }
    private static function identity(string $kind, string $source, string $target): string { return hash('sha256', "wordpress-site-plan/{$kind}/v2\n{$source}\n{$target}"); }
    public static function contentHash(string $content): string { return hash('sha256', $content); }
    private static function hash(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    /** @param array<int,mixed> $declarations */
    private static function assertRuntimeDeclarations(array $declarations): void { $identities=array(); $keys=array(); foreach($declarations as $declaration){if(!is_array($declaration)||!is_string($declaration['kind']??null)||(!is_string($declaration['type']??null)&&!is_string($declaration['capability']??null))||(isset($declaration['type'])&&isset($declaration['capability']))||!self::safePath($declaration['source_path']??null)||!self::hash($declaration['reconciliation_identity']??null))throw new InvalidArgumentException('WordPress site plan runtime declaration is invalid.');$name=$declaration['type']??$declaration['capability'];$key=$declaration['kind'].':'.$name;if($declaration['reconciliation_identity']!==hash('sha256',"wordpress-site-plan/runtime-declaration/v1\n{$declaration['source_path']}\n{$key}"))throw new InvalidArgumentException('WordPress site plan runtime declaration identity is invalid.');self::unique($identities,$declaration['reconciliation_identity'],'runtime declaration reconciliation identity');self::unique($keys,$key,'runtime declaration key');if(isset($declaration['payload'])&&(!is_array($declaration['payload'])||!is_string($declaration['payload']['schema']??null)))throw new InvalidArgumentException('WordPress site plan runtime declaration payload is invalid.');if('entity_collection'===$declaration['kind']&&(!isset($declaration['type'],$declaration['payload']['entities'])||!is_array($declaration['payload']['entities'])))throw new InvalidArgumentException('WordPress site plan entity collection declaration is invalid.');}foreach($declarations as $declaration)foreach($declaration['required_for']??array() as $required)if(!is_string($required)||!isset($keys[strtolower($required)]))throw new InvalidArgumentException('WordPress site plan runtime declaration required_for is unresolved.'); }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null)) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }
    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optional */
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    private static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function explicitUrl(mixed $url): bool { return is_string($url) && (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url) === 1 || self::routeUrl($url)); }
    private static function routeUrl(mixed $url): bool { return is_string($url) && preg_match('~^/(?:[a-z0-9-]+(?:/[a-z0-9-]+)*)?(?:[?#].*)?$~', $url) === 1; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
