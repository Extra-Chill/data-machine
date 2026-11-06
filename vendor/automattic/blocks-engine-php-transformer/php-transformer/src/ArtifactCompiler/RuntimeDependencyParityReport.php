<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\DeterministicRowDeduplicator;
use DOMDocument;
use DOMElement;

final class RuntimeDependencyParityReport
{
    public const SCHEMA = 'blocks-engine/php-transformer/runtime-dependency-parity/v1';

    /**
     * Tag-only script selectors whose native DOM shape can be behavior-bearing.
     *
     * @var array<int, string>
     */
    private const RUNTIME_TAG_SELECTORS = array( 'button', 'input', 'select', 'textarea', 'ul', 'ol', 'li' );

    /**
     * Acceptable disposition for a missing-DOM-target finding whose selector the
     * transformer intentionally removed because a native block now provides the
     * behavior — the parity loss is expected and editable, not a bug.
     */
    public const DISPOSITION_SUPERSEDED = 'superseded_by_native_interactivity';

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $assetReferences
     * @param array<int, array<string, mixed>> $interactionCandidates
     * @param array<int, string> $supersededSelectors Source id/class selectors
     *        the transformer intentionally removed because a native block now
     *        provides the behavior (e.g. a hamburger menu-toggle and the overlay
     *        it controlled, dropped in favor of core/navigation's own responsive
     *        overlay). A missing-target finding for one of these is reclassified
     *        as an acceptable, superseded loss rather than a materialization bug.
     * @return array<string, mixed>
     */
    public function fromArtifact(array $files, string $sourceHtml, string $generatedHtml, string $sourcePath = '', array $runtimeIslands = array(), array $assetReferences = array(), array $interactionCandidates = array(), array $supersededSelectors = array()): array
    {
        $sourceTargets = $this->sourceTargets($sourceHtml, $sourcePath);
        $generatedTargets = $this->withBlockCommentAnchorTargets(
            $this->withRuntimeIslandTargets($this->htmlTargets($generatedHtml), $runtimeIslands),
            $generatedHtml
        );
        $superseded = $this->normalizeSupersededSelectors($supersededSelectors);
        $dependencies = array();
        $findings = array();
        $flaggedSelectors = array();
        $bundleCanvasSelectors = $this->bundleCanvasSelectors($files, $sourceTargets);

        foreach ( $files as $file ) {
            if ( ! $this->isScriptFile($file) ) {
                continue;
            }

            $scriptPath = (string) ($file['path'] ?? '');
            $script = (string) ($file['content'] ?? '');
            if ( '' === trim($script) ) {
                continue;
            }

            $scriptKind = $this->scriptKind($scriptPath, $script);
            foreach ( $this->scriptDependencies($script, $bundleCanvasSelectors) as $dependency ) {
                $selector = (string) $dependency['selector'];
                $target = $sourceTargets[$selector] ?? array();
                $exists = $this->targetExists($dependency, $generatedTargets);
                $canvasApi = true === $dependency['canvas_api'] && 'canvas' === ($target['tag'] ?? '');
                $dependencyRow = array_filter(array(
                    'source_path'       => $target['source_path'] ?? $sourcePath,
                    'script_path'       => $scriptPath,
                    'script_kind'       => $scriptKind,
                    'selector'          => $selector,
                    'target_id'         => $target['id'] ?? '',
                    'target_class'      => $target['class'] ?? '',
                    'target_kind'       => $target['tag'] ?? '',
                    'dependency_kind'   => $dependency['kind'],
                    'events'            => $dependency['events'],
                    'canvas_api'        => $canvasApi,
                    'source_present'    => array() !== $target,
                    'generated_present' => $exists,
                    'disposition'       => $this->isSupersededSelector($selector, $superseded) ? self::DISPOSITION_SUPERSEDED : '',
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
                $dependencies[] = $dependencyRow;

                if ( $exists ) {
                    continue;
                }

                if ( array() === $target && 'telemetry' !== $scriptKind ) {
                    continue;
                }

                if ( $this->isFormControlTarget($target) && true !== ( $dependency['control_runtime'] ?? false ) ) {
                    continue;
                }

                if ( $this->isTelemetryScriptSelfTarget($scriptKind, $scriptPath, $target) ) {
                    continue;
                }

                if ( $this->isSupersededSelector($selector, $superseded) ) {
                    continue;
                }

                $flaggedSelectors[$selector] = true;

                $severity = 'telemetry' === $scriptKind ? 'info' : 'warning';
                $repairBucket = $canvasApi ? 'runtime_canvas_target_preservation' : 'runtime_dom_target_preservation';
                $findings[] = array_filter(array(
                    'code'              => 'runtime_dependency_target_missing',
                    'severity'          => $severity,
                    'source_path'       => $target['source_path'] ?? $sourcePath,
                    'script_path'       => $scriptPath,
                    'script_kind'       => $scriptKind,
                    'selector'          => $selector,
                    'target_id'         => $target['id'] ?? '',
                    'target_class'      => $target['class'] ?? '',
                    'target_kind'       => $target['tag'] ?? '',
                    'dependency_kind'   => $dependency['kind'],
                    'events'            => $dependency['events'],
                    'canvas_api'        => $canvasApi,
                    'repair_bucket'     => $repairBucket,
                    'suggested_primitive' => $canvasApi ? 'runtime_canvas' : 'runtime_dom_target',
                    'actionability'     => $canvasApi ? 'preserve_canvas_markup_with_matching_script_runtime_or_rebuild_canvas_behavior' : 'preserve_or_recreate_the_referenced_dom_target_for_script_runtime',
                    'materialization_hint' => $canvasApi ? 'preserve_canvas_id_class_and_markup_for_runtime_mapping' : 'preserve_id_class_or_wrapper_markup_required_by_first_party_script',
                    'message'           => sprintf('Script %s references %s, but the generated block markup does not expose that DOM target.', $scriptPath, $selector),
                ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
                $findings[count($findings) - 1] = $this->withSupersededDisposition($findings[count($findings) - 1], $superseded);
            }
        }

        foreach ( $this->scriptMaterializationFindings($files, $runtimeIslands, $assetReferences, $generatedHtml, $sourcePath) as $finding ) {
            $findings[] = $finding;
        }

        foreach ( $this->scriptTargetParityFindings($files, $interactionCandidates, $sourceTargets, $generatedTargets, $sourcePath, $flaggedSelectors, $superseded) as $finding ) {
            $findings[] = $this->withSupersededDisposition($finding, $superseded);
        }

        // Stamp the canonical classification triplet so each runtime-dependency
        // finding carries a reason_code and pattern_family alongside the
        // repair_bucket it already sets, clustering by root cause downstream. The
        // contract honors the producer's specific repair_bucket values.
        $findings = array_map(
            static fn (array $finding): array => ConversionFindingContract::withClassification($finding),
            $this->dedupeRows($findings)
        );

        $report = array_filter(array(
            'schema'         => self::SCHEMA,
            'finding_schema' => ConversionFindingContract::SCHEMA,
            'status'         => array() === $findings ? 'pass' : 'warning',
            'dependencies'   => $this->dedupeRows($dependencies),
        ), static fn (mixed $value): bool => array() !== $value);

        $report['findings'] = $findings;

        return $report;
    }

    /**
     * Detect source-declared client-script execution dependencies (referenced
     * external `<script src>` islands flagged `client_script_execution`) whose
     * backing script was never materialized as an artifact file nor carried into
     * the generated markup. When that happens, every interaction that depended on
     * that script is silently non-functional — a feature-parity loss the existing
     * DOM-target comparison cannot see because it only inspects scripts present in
     * the artifact.
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @param array<int, array<string, mixed>> $assetReferences
     * @return array<int, array<string, mixed>>
     */
    private function scriptMaterializationFindings(array $files, array $runtimeIslands, array $assetReferences, string $generatedHtml, string $sourcePath): array
    {
        $materialized = $this->materializedScriptPaths($files, $assetReferences, $sourcePath);
        $findings = array();

        foreach ( $runtimeIslands as $island ) {
            if ( ! is_array($island) ) {
                continue;
            }
            if ( 'script' !== ($island['kind'] ?? '') ) {
                continue;
            }
            if ( ! str_contains((string) ($island['runtime_requirement'] ?? ''), 'client_script_execution') ) {
                continue;
            }
            if ( 'external' !== ($island['script_source_kind'] ?? '') ) {
                continue;
            }

            $attributes = is_array($island['attributes'] ?? null) ? $island['attributes'] : array();
            $src = trim((string) ($attributes['src'] ?? ''));
            if ( '' === $src ) {
                continue;
            }

            $islandSourcePath = (string) ($island['source_path'] ?? $sourcePath);
            $resolved = ArtifactPath::resolveRelativePath($src, $islandSourcePath);
            $normalizedSrc = $this->normalizeScriptPath($src);

            if ( isset($materialized[$resolved]) || ('' !== $normalizedSrc && isset($materialized[$normalizedSrc])) ) {
                continue;
            }
            if ( '' !== trim($generatedHtml) && str_contains($generatedHtml, $src) ) {
                continue;
            }

            $scriptKind = $this->scriptKind($src, '');
            $severity = 'telemetry' === $scriptKind ? 'info' : 'warning';
            $findings[] = array_filter(array(
                'code'              => 'runtime_script_not_materialized',
                'severity'          => $severity,
                'source_path'       => $islandSourcePath,
                'script_path'       => '' !== $resolved ? $resolved : $src,
                'script_src'        => $src,
                'script_kind'       => $scriptKind,
                'script_source_kind' => 'external',
                'selector'          => (string) ($island['selector'] ?? ''),
                'target_kind'       => (string) ($island['tag'] ?? 'script'),
                'runtime_requirement' => 'client_script_execution',
                'repair_bucket'     => 'runtime_script_materialization',
                'suggested_primitive' => 'runtime_script',
                'actionability'     => 'materialize_or_enqueue_the_referenced_client_script_so_dependent_interactions_execute',
                'materialization_hint' => 'carry_or_enqueue_the_referenced_script_source_so_client_script_execution_is_restored',
                'message'           => sprintf('Source references client script %s requiring client_script_execution, but it was not materialized or carried into the artifact, so the dependent interactions are non-functional.', $src),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
        }

        return $findings;
    }

    /**
     * Detect carried-but-inert client scripts: a first-party client script IS
     * materialized in the artifact (so #218's `runtime_script_not_materialized`
     * stays silent — the script loads/enqueues), yet an interactive DOM target
     * the transformer authoritatively recorded for the source was transformed
     * away (deduped, folded into a core block, or dropped) and is no longer
     * present — verbatim — in the generated block markup or any preserved runtime
     * island. The script therefore executes against a target that no longer
     * exists and silently no-ops: "script enqueued" is a false parity signal.
     *
     * Targets come from `interaction_candidates` — the transformer's own record
     * of interactive elements and the DOM they drive (authoritative), rather than
     * re-deriving selectors from script source (the heuristic the main DOM-target
     * loop already covers). Selectors already flagged by that loop are skipped to
     * avoid duplicate findings, and native-behavior candidates (native `details`
     * toggles, form submissions) plus form-control targets are excluded because
     * they do not depend on a carried client script.
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $interactionCandidates
     * @param array<string, array<string, mixed>> $sourceTargets
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $generatedTargets
     * @param array<string, bool> $flaggedSelectors
     * @return array<int, array<string, mixed>>
     */
    private function scriptTargetParityFindings(array $files, array $interactionCandidates, array $sourceTargets, array $generatedTargets, string $sourcePath, array $flaggedSelectors, array $superseded): array
    {
        if ( array() === $interactionCandidates || ! $this->hasCarriedFirstPartyClientScript($files) ) {
            return array();
        }

        // Native behaviors (the native <details> toggle, native form submission)
        // are rebuilt by core blocks / covered by the form fallback and do not
        // depend on a carried client script, so a missing target there is not an
        // inert-JS parity loss this diagnostic should own.
        $nativeRuntimeRequirements = array('native_toggle', 'form_submission');
        $findings = array();
        $seenSelectors = array();

        foreach ( $interactionCandidates as $candidate ) {
            if ( ! is_array($candidate) ) {
                continue;
            }

            $runtimeRequirement = (string) ($candidate['runtime_requirement'] ?? '');
            if ( in_array($runtimeRequirement, $nativeRuntimeRequirements, true) ) {
                continue;
            }

            $selector = $this->normalizedTargetSelector((string) ($candidate['target'] ?? ''));
            if ( '' === $selector ) {
                continue;
            }
            if ( isset($flaggedSelectors[$selector]) || isset($seenSelectors[$selector]) ) {
                continue;
            }
            if ( $this->isSupersededSelector($selector, $superseded) ) {
                continue;
            }

            $target = $sourceTargets[$selector] ?? array();
            if ( $this->isFormControlTarget($target) ) {
                continue;
            }
            if ( $this->targetExists(array('selector' => $selector), $generatedTargets) ) {
                continue;
            }

            $seenSelectors[$selector] = true;
            $interactionKind = (string) ($candidate['kind'] ?? '');
            $materializationHint = (string) ($candidate['materialization_hint'] ?? '');
            $findings[] = array_filter(array(
                'code'                 => 'runtime_script_target_missing',
                'severity'             => 'warning',
                'source_path'          => $target['source_path'] ?? $sourcePath,
                'selector'             => $selector,
                'target_id'            => $target['id'] ?? ( str_starts_with($selector, '#') ? substr($selector, 1) : '' ),
                'target_class'         => $target['class'] ?? ( str_starts_with($selector, '.') ? substr($selector, 1) : '' ),
                'target_kind'          => $target['tag'] ?? '',
                'interaction_kind'     => $interactionKind,
                'interaction_selector' => (string) ($candidate['selector'] ?? ''),
                'trigger'              => (string) ($candidate['trigger'] ?? ''),
                'runtime_requirement'  => $runtimeRequirement,
                'client_script_present' => true,
                'repair_bucket'        => 'runtime_interactive_behavior_restoration',
                'suggested_primitive'  => 'runtime_dom_target',
                'actionability'        => 'restore_or_rebuild_the_interactive_dom_target_so_the_carried_client_script_remains_functional',
                'materialization_hint' => '' !== $materializationHint ? $materializationHint : 'preserve_interactive_target_markup_required_by_carried_client_script',
                'message'              => sprintf('A client script is carried for the %s interaction, but its DOM target %s was transformed away and is no longer present in the generated block markup, so the carried script no-ops.', '' !== $interactionKind ? $interactionKind : 'interactive', $selector),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value);
        }

        return $findings;
    }

    /**
     * Whether the artifact carries a materialized first-party (non-telemetry)
     * client script. Used to gate the carried-but-inert target diagnostic: only
     * when a client script actually loads is a missing interactive target an
     * inert-JS parity loss.
     *
     * @param array<int, array<string, mixed>> $files
     */
    private function hasCarriedFirstPartyClientScript(array $files): bool
    {
        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! $this->isScriptFile($file) ) {
                continue;
            }
            $content = (string) ($file['content'] ?? '');
            if ( '' === trim($content) ) {
                continue;
            }
            if ( 'telemetry' !== $this->scriptKind((string) ($file['path'] ?? ''), $content) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize an interaction-candidate target to a single clean id/class
     * selector (`#id` / `.class`). Structural nth-of-type paths and other
     * non-id/class targets return '' so they are ignored — presence is only
     * checkable for ids/classes.
     */
    private function normalizedTargetSelector(string $target): string
    {
        $target = trim($target);
        if ( 1 === preg_match('/^[#.][A-Za-z][A-Za-z0-9_-]*$/', $target) ) {
            return $target;
        }

        return '';
    }

    /**
     * Normalize the transformer-recorded superseded selectors into a lookup set
     * keyed by clean `#id` / `.class` selector. Other selector shapes are
     * ignored because the parity report only checks id/class presence.
     *
     * @param array<int, string> $selectors
     * @return array<string, bool>
     */
    private function normalizeSupersededSelectors(array $selectors): array
    {
        $normalized = array();
        foreach ( $selectors as $selector ) {
            if ( ! is_string($selector) ) {
                continue;
            }
            $selector = $this->normalizedTargetSelector($selector);
            if ( '' !== $selector ) {
                $normalized[$selector] = true;
            }
        }

        return $normalized;
    }

    /**
     * Reclassify a missing-DOM-target finding as an acceptable, superseded loss
     * when its selector is one the transformer intentionally removed in favor of
     * a native block's behavior (e.g. a hamburger menu-toggle and the overlay it
     * controlled, dropped because the navigation became a core/navigation with
     * its own responsive overlay). The finding is kept for transparency but its
     * severity is lowered and an acceptable disposition is attached, so a
     * preserved site script still referencing the removed selector is recorded
     * as an expected, editable approximation rather than a materialization bug.
     * Findings for selectors NOT in the superseded set are returned unchanged,
     * so genuinely-broken targets stay flagged.
     *
     * @param array<string, mixed> $finding
     * @param array<string, bool> $superseded
     * @return array<string, mixed>
     */
    private function withSupersededDisposition(array $finding, array $superseded): array
    {
        $selector = $this->normalizedTargetSelector((string) ($finding['selector'] ?? ''));
        if ( '' === $selector || ! isset($superseded[$selector]) ) {
            return $finding;
        }

        return array_merge($finding, array(
            'severity'             => 'info',
            'disposition'          => self::DISPOSITION_SUPERSEDED,
            'loss_class'           => 'editable_approximation',
            'recoverability'       => 'acceptable_loss',
            'repair_bucket'        => 'runtime_behavior_superseded_by_native_block',
            'suggested_primitive'  => 'native_navigation_overlay',
            'actionability'        => 'no_action_native_navigation_overlay_supersedes_the_removed_menu_toggle',
            'materialization_hint' => 'none_native_block_provides_the_responsive_navigation_overlay',
            'message'              => sprintf('Script references %s, which the transformer intentionally removed because the navigation became a core/navigation with its own responsive overlay; the missing target is an expected, editable approximation, not a materialization bug.', $selector),
        ));
    }

    /**
     * @param array<string, bool> $superseded
     */
    private function isSupersededSelector(string $selector, array $superseded): bool
    {
        $selector = $this->normalizedTargetSelector($selector);
        return '' !== $selector && isset($superseded[$selector]);
    }

    /**
     * Set of artifact-materialized script paths, keyed by normalized path. A
     * script is considered materialized when its content survives as a file in
     * the artifact bundle, or when it surfaces as a resolved asset reference
     * (which is only recorded when the referenced file was found).
     *
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $assetReferences
     * @return array<string, bool>
     */
    private function materializedScriptPaths(array $files, array $assetReferences, string $sourcePath): array
    {
        $paths = array();

        foreach ( $files as $file ) {
            if ( ! is_array($file) || ! $this->isScriptFile($file) ) {
                continue;
            }
            $path = $this->normalizeScriptPath((string) ($file['path'] ?? ''));
            if ( '' !== $path ) {
                $paths[$path] = true;
            }
        }

        foreach ( $assetReferences as $reference ) {
            if ( ! is_array($reference) ) {
                continue;
            }
            if ( 'script' !== ($reference['element'] ?? '') ) {
                continue;
            }
            foreach ( array('asset_path', 'resolved_path') as $key ) {
                $path = $this->normalizeScriptPath((string) ($reference[$key] ?? ''));
                if ( '' !== $path ) {
                    $paths[$path] = true;
                }
            }
            $url = (string) ($reference['url'] ?? '');
            if ( '' !== $url ) {
                $resolved = ArtifactPath::resolveRelativePath($url, (string) ($reference['source_path'] ?? $sourcePath));
                if ( '' !== $resolved ) {
                    $paths[$this->normalizeScriptPath($resolved)] = true;
                }
            }
        }

        return $paths;
    }

    private function normalizeScriptPath(string $path): string
    {
        return ltrim(trim($path), '/');
    }

    /**
     * @param array<string, mixed> $file
     */
    private function isScriptFile(array $file): bool
    {
        return in_array($file['kind'] ?? '', array('js', 'mjs'), true)
            || 'script' === ($file['role'] ?? '')
            || in_array($file['mime_type'] ?? '', array('application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript'), true);
    }

    /**
     * @return array<string, array{tag: string, source_path: string, id?: string, class?: string, src?: string}>
     */
    private function sourceTargets(string $html, string $sourcePath): array
    {
        $targets = array();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }
            $tag = strtolower($element->tagName);
            $src = 'script' === $tag && $element->hasAttribute('src') ? trim($element->getAttribute('src')) : '';
            if ( '' !== $src ) {
                $src = ArtifactPath::resolveRelativePath($src, $sourcePath);
            }
            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $targets['#' . $id] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'id' => $id, 'src' => $src), static fn (string $value): bool => '' !== $value);
            }
            foreach ( preg_split('/\s+/', trim($element->hasAttribute('class') ? $element->getAttribute('class') : '')) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $targets['.' . $class] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'class' => $class, 'src' => $src), static fn (string $value): bool => '' !== $value);
                    $targets[$tag . '.' . $class] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'class' => $class, 'src' => $src), static fn (string $value): bool => '' !== $value);
                }
            }
            foreach ( $this->dataAttributeSelectors($element) as $selector => $attribute ) {
                $targets[$selector] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'attribute' => $attribute, 'src' => $src), static fn (string $value): bool => '' !== $value);
                $targets[$tag . $selector] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'attribute' => $attribute, 'src' => $src), static fn (string $value): bool => '' !== $value);
            }
            if ( in_array($tag, array_merge(array('canvas', 'svg'), self::RUNTIME_TAG_SELECTORS), true) ) {
                $targets[$tag] = array_filter(array('tag' => $tag, 'source_path' => $sourcePath, 'src' => $src), static fn (string $value): bool => '' !== $value);
            }
        }

        return $targets;
    }

    /**
     * Harvest `anchor` ids and `className` classes declared in block-comment
     * attributes as preserved DOM targets. Dynamic, server-rendered blocks (the
     * `core/navigation` family) store no static wrapper markup — their `save()`
     * returns null — so an `anchor` or `className` the source carried lives in the
     * block comment (`{"anchor":"x","className":"y"}`) rather than as `id="x"` /
     * `class="y"` in the serialized HTML. WordPress' anchor and className supports
     * still render those onto the wrapper at runtime, so a first-party script
     * targeting `#x` / `.y` keeps working. Recognizing the comment-declared
     * anchor/className keeps the runtime-dependency parity check honest now that
     * the navigation family emits canonical (empty) save markup.
     *
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $targets
     * @return array{ids: array<string, bool>, classes: array<string, bool>}
     */
    private function withBlockCommentAnchorTargets(array $targets, string $generatedHtml): array
    {
        if ( ! preg_match_all('/<!--\s*wp:[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?\s+(\{.*?\})\s*\/?-->/s', $generatedHtml, $matches) ) {
            return $targets;
        }

        foreach ( $matches[1] as $json ) {
            $attrs = json_decode((string) $json, true);
            if ( ! is_array($attrs) ) {
                continue;
            }
            $anchor = is_string($attrs['anchor'] ?? null) ? trim($attrs['anchor']) : '';
            if ( '' !== $anchor ) {
                $targets['ids'][$anchor] = true;
            }
            $className = is_string($attrs['className'] ?? null) ? trim($attrs['className']) : '';
            foreach ( preg_split('/\s+/', $className) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $targets['classes'][$class] = true;
                }
            }
        }

        return $targets;
    }

    /**
     * @return array{ids: array<string, bool>, classes: array<string, bool>}
     */
    private function htmlTargets(string $html): array
    {
        $targets = array('ids' => array(), 'classes' => array(), 'selectors' => array());
        if ( preg_match_all('/\sid\s*=\s*(["\'])(.*?)\1/is', $html, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $id = trim(html_entity_decode((string) $id, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ( '' !== $id ) {
                    $targets['ids'][$id] = true;
                }
            }
        }
        if ( preg_match_all('/\sclass\s*=\s*(["\'])(.*?)\1/is', $html, $matches) ) {
            foreach ( $matches[2] as $classList ) {
                foreach ( preg_split('/\s+/', trim(html_entity_decode((string) $classList, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: array() as $class ) {
                    if ( '' !== $class ) {
                        $targets['classes'][$class] = true;
                    }
                }
            }
        }
        if ( preg_match_all('/<([a-z][a-z0-9-]*)\b([^>]*)>/is', $html, $elements, PREG_SET_ORDER) ) {
            foreach ( $elements as $element ) {
                $tag = strtolower((string) $element[1]);
                $attrs = (string) $element[2];
                if ( preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/is', $attrs, $classMatch) ) {
                    foreach ( preg_split('/\s+/', trim(html_entity_decode((string) $classMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: array() as $class ) {
                        if ( '' !== $class ) {
                            $targets['selectors'][$tag . '.' . $class] = true;
                        }
                    }
                }
                if ( preg_match_all('/\s(data-[A-Za-z][A-Za-z0-9_-]*)(?:\s*=\s*(["\'])(.*?)\2)?/is', $attrs, $dataMatches, PREG_SET_ORDER) ) {
                    foreach ( $dataMatches as $dataMatch ) {
                        $selector = '[' . strtolower((string) $dataMatch[1]) . ']';
                        $targets['selectors'][$selector] = true;
                        $targets['selectors'][$tag . $selector] = true;
                    }
                }
                if ( in_array($tag, array_merge(array('canvas', 'svg'), self::RUNTIME_TAG_SELECTORS), true) ) {
                    $targets['selectors'][$tag] = true;
                }
            }
        }

        return $targets;
    }

    /**
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $targets
     * @param array<int, array<string, mixed>> $runtimeIslands
     * @return array{ids: array<string, bool>, classes: array<string, bool>, selectors?: array<string, bool>}
     */
    private function withRuntimeIslandTargets(array $targets, array $runtimeIslands): array
    {
        foreach ( $runtimeIslands as $island ) {
            if ( ! is_array($island) ) {
                continue;
            }

            $selector = is_string($island['selector'] ?? null) ? trim($island['selector']) : '';
            if ( str_starts_with($selector, '#') ) {
                $targets['ids'][substr($selector, 1)] = true;
            }
            if ( str_starts_with($selector, '.') ) {
                $targets['classes'][substr($selector, 1)] = true;
            }

            $attributes = is_array($island['attributes'] ?? null) ? $island['attributes'] : array();
            $id = is_string($attributes['id'] ?? null) ? trim($attributes['id']) : '';
            if ( '' !== $id ) {
                $targets['ids'][$id] = true;
            }
            $classList = is_string($attributes['class'] ?? null) ? trim($attributes['class']) : '';
            foreach ( preg_split('/\s+/', $classList) ?: array() as $class ) {
                if ( '' !== $class ) {
                    $targets['classes'][$class] = true;
                }
            }

            $tag = is_string($island['tag'] ?? null) ? strtolower(trim($island['tag'])) : '';
            foreach ( $attributes as $name => $value ) {
                $attributeName = strtolower((string) $name);
                if ( ! str_starts_with($attributeName, 'data-') || ! preg_match('/^data-[a-z][a-z0-9_-]*$/', $attributeName) ) {
                    continue;
                }

                $attributeSelector = '[' . $attributeName . ']';
                $targets['selectors'][$attributeSelector] = true;
                if ( '' !== $tag ) {
                    $targets['selectors'][$tag . $attributeSelector] = true;
                }
            }
        }

        return $targets;
    }

    /**
     * @return array<string, string>
     */
    private function dataAttributeSelectors(DOMElement $element): array
    {
        $selectors = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->nodeName ?? '');
            if ( str_starts_with($name, 'data-') && preg_match('/^data-[a-z][a-z0-9_-]*$/', $name) ) {
                $selectors['[' . $name . ']'] = $name;
            }
        }

        return $selectors;
    }

    /**
     * @return array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool}>
     */
    private function scriptDependencies(string $script, array $bundleCanvasSelectors = array()): array
    {
        $dependencies = array();
        $eventsBySelector = $this->eventsBySelector($script);
        $canvasSelectors = $this->scriptCanvasSelectors($script) + $bundleCanvasSelectors;
        $controlRuntimeSelectors = $this->scriptControlRuntimeSelectors($script);

        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selector = '#' . (string) $id;
                $dependencies[] = array(
                    'kind'       => 'id',
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $dependencies[] = array(
                    'kind'       => $this->selectorKind($selector),
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        if ( preg_match_all('/\b(?!document\b)[A-Za-z_$][A-Za-z0-9_$]*\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $dependencies[] = array(
                    'kind'       => $this->selectorKind($selector),
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        foreach ( $this->scriptScopedElementSelectors($script, 'canvas') as $selector ) {
            $dependencies[] = array(
                'kind'       => 'element',
                'selector'   => $selector,
                'events'     => $eventsBySelector[$selector] ?? array(),
                'canvas_api' => isset($canvasSelectors[$selector]),
                'control_runtime' => isset($controlRuntimeSelectors[$selector]),
            );
        }
        foreach ( $this->scriptScopedElementSelectors($script, 'svg') as $selector ) {
            $dependencies[] = array(
                'kind'       => 'element',
                'selector'   => $selector,
                'events'     => $eventsBySelector[$selector] ?? array(),
                'canvas_api' => false,
                'control_runtime' => isset($controlRuntimeSelectors[$selector]),
            );
        }
        foreach ( $this->scriptAppendedRootSelectors($script) as $selector ) {
            $selector = $this->canonicalRuntimeSelector($selector);
            $dependencies[] = array(
                'kind'       => $this->selectorKind($selector),
                'selector'   => $selector,
                'events'     => $eventsBySelector[$selector] ?? array(),
                'canvas_api' => false,
                'control_runtime' => isset($controlRuntimeSelectors[$selector]),
            );
        }

        if ( preg_match_all('/\.\s*closest\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $selector ) {
                $selector = $this->canonicalRuntimeSelector((string) $selector);
                if ( $this->isPresentationalRuntimeSelector($selector) ) {
                    continue;
                }
                $dependencies[] = array(
                    'kind'       => $this->selectorKind($selector),
                    'selector'   => $selector,
                    'events'     => $eventsBySelector[$selector] ?? array(),
                    'canvas_api' => isset($canvasSelectors[$selector]),
                    'control_runtime' => isset($controlRuntimeSelectors[$selector]),
                );
            }
        }

        foreach ( $this->scriptDataAttributeSelectors($script) as $selector ) {
            if ( $this->isPresentationalRuntimeSelector($selector) ) {
                continue;
            }
            $dependencies[] = array(
                'kind'       => 'attribute',
                'selector'   => $selector,
                'events'     => $eventsBySelector[$selector] ?? array(),
                'canvas_api' => isset($canvasSelectors[$selector]),
                'control_runtime' => isset($controlRuntimeSelectors[$selector]),
            );
        }

        return $this->dedupeDependencies($dependencies);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, array<string, mixed>> $sourceTargets
     * @return array<string, bool>
     */
    private function bundleCanvasSelectors(array $files, array $sourceTargets): array
    {
        $scripts = array();
        foreach ( $files as $file ) {
            if ( $this->isScriptFile($file) && is_string($file['content'] ?? null) ) {
                $scripts[] = (string) $file['content'];
            }
        }

        $combinedScripts = implode("\n", $scripts);
        if ( 1 !== preg_match('/\.\s*getContext\s*\(/', $combinedScripts) ) {
            return array();
        }

        $selectors = array();
        foreach ( $this->scriptCanvasArgumentSelectors($combinedScripts) as $selector ) {
            if ( 'canvas' === ( $sourceTargets[$selector]['tag'] ?? '' ) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasArgumentSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^)]*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                $variable = (string) $assignment[1];
                if ( ! preg_match('/(?:\bnew\s+)?\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^)]*\b' . preg_quote($variable, '/') . '\b/', $script) ) {
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
    private function scriptDomSelectorsFromBundle(string $script): array
    {
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

        return array_keys($selectors);
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
     * Telemetry bundles often read configuration from their own source <script>
     * tag. SSI materializes/enqueues scripts outside block markup, so that tag id
     * is not a visible page DOM target that block serialization must preserve.
     *
     * @param array<string, mixed> $target
     */
    private function isTelemetryScriptSelfTarget(string $scriptKind, string $scriptPath, array $target): bool
    {
        if ( 'telemetry' !== $scriptKind || 'script' !== ($target['tag'] ?? '') ) {
            return false;
        }

        $src = $this->normalizeScriptPath((string) ($target['src'] ?? ''));
        return '' !== $src && $src === $this->normalizeScriptPath($scriptPath);
    }

    /**
     * @return array<string, bool>
     */
    private function scriptControlRuntimeSelectors(string $script): array
    {
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

        return $selectors;
    }

    /**
     * @return array<string, bool>
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

        return $selectors;
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

    private function selectorKind(string $selector): string
    {
        if ( str_starts_with($selector, '#') ) {
            return 'id';
        }
        if ( str_starts_with($selector, '.') ) {
            return 'class';
        }
        if ( str_contains($selector, '[') ) {
            return 'attribute';
        }

        return 'element';
    }

    /**
     * @return array<int, string>
     */
    private function scriptScopedElementSelectors(string $script, string $tag): array
    {
        $selectors = array();
        if ( ! preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . $this->scriptSelectorPattern() . ')\4\s*\))/', $script, $roots, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $roots as $root ) {
            if ( preg_match('/\b' . preg_quote((string) $root[1], '/') . '\s*\.\s*querySelector\s*\(\s*(["\'])' . preg_quote($tag, '/') . '\1\s*\)\s*\.\s*(?:addEventListener|setAttribute|appendChild|classList|style|getContext)\b/', $script) ) {
                $selectors[] = $tag;
                continue;
            }
            if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\b' . preg_quote((string) $root[1], '/') . '\s*\.\s*querySelector\s*\(\s*(["\'])' . preg_quote($tag, '/') . '\2\s*\)/', $script, $children, PREG_SET_ORDER) ) {
                foreach ( $children as $child ) {
                    if ( preg_match('/\b' . preg_quote((string) $child[1], '/') . '\s*\.\s*(?:addEventListener|setAttribute|appendChild|classList|style|getContext)\b/', $script) ) {
                        $selectors[] = $tag;
                    }
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

    /**
     * @param array<string, mixed> $target
     */
    private function isFormControlTarget(array $target): bool
    {
        return in_array((string) ($target['tag'] ?? ''), array('button', 'input', 'select', 'textarea'), true);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function eventsBySelector(string $script): array
    {
        $events = array();
        if ( preg_match_all('/document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches) ) {
            foreach ( $matches[2] as $index => $id ) {
                $events['#' . (string) $id][] = (string) $matches[4][$index];
            }
        }
        if ( preg_match_all('/document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\1\s*\)\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\3/', $script, $matches) ) {
            foreach ( $matches[2] as $index => $selector ) {
                $events[(string) $selector][] = (string) $matches[4][$index];
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match_all('/\b' . preg_quote((string) $assignment[1], '/') . '\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                    foreach ( $matches[2] as $event ) {
                        $events['#' . (string) $assignment[3]][] = (string) $event;
                    }
                }
            }
        }
        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*querySelector(?:All)?\s*\(\s*(["\'])([#.][A-Za-z][A-Za-z0-9_-]*)\2\s*\)/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                if ( preg_match_all('/\b' . preg_quote((string) $assignment[1], '/') . '\s*\.\s*addEventListener\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                    foreach ( $matches[2] as $event ) {
                        $events[(string) $assignment[3]][] = (string) $event;
                    }
                }
            }
        }

        foreach ( $events as $selector => $selectorEvents ) {
            $events[$selector] = array_values(array_unique($selectorEvents));
        }

        return $events;
    }

    /**
     * @param array{kind: string, selector: string, events: array<int, string>, canvas_api: bool} $dependency
     * @param array{ids: array<string, bool>, classes: array<string, bool>} $targets
     */
    private function targetExists(array $dependency, array $targets): bool
    {
        $selector = (string) $dependency['selector'];
        if ( str_starts_with($selector, '#') ) {
            return isset($targets['ids'][substr($selector, 1)]);
        }
        if ( str_starts_with($selector, '.') ) {
            return isset($targets['classes'][substr($selector, 1)]);
        }

        if ( isset($targets['selectors'][$selector]) ) {
            return true;
        }

        return false;
    }

    private function scriptKind(string $path, string $script): string
    {
        $haystack = strtolower($path . "\n" . substr($script, 0, 2000));
        if ( preg_match('#(?:netlify|analytics|gtag|\brum\b|[-_./]rum[-_./])#', $haystack) ) {
            return 'telemetry';
        }

        return 'first_party';
    }

    /**
     * @param array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool, control_runtime?: bool}> $dependencies
     * @return array<int, array{kind: string, selector: string, events: array<int, string>, canvas_api: bool, control_runtime?: bool}>
     */
    private function dedupeDependencies(array $dependencies): array
    {
        $deduped = array();
        foreach ( $dependencies as $dependency ) {
            $selector = $dependency['selector'];
            if ( isset($deduped[$selector]) ) {
                $deduped[$selector]['events'] = array_values(array_unique(array_merge($deduped[$selector]['events'], $dependency['events'])));
                $deduped[$selector]['canvas_api'] = $deduped[$selector]['canvas_api'] || $dependency['canvas_api'];
                $deduped[$selector]['control_runtime'] = ( $deduped[$selector]['control_runtime'] ?? false ) || ( $dependency['control_runtime'] ?? false );
                continue;
            }
            $deduped[$selector] = $dependency;
        }

        return array_values($deduped);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        return DeterministicRowDeduplicator::dedupe($rows);
    }
}
