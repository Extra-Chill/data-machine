<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Holds route maps and link-coverage counters for static HTML emission.
 */
final class StaticHtmlLinkState
{
    /** @var array<string, string> */
    private array $linkTargetPaths = array();

    /** @var array<string, string> */
    private array $implicitRoutePaths = array();

    /** @var array<string, array{label:string,path:string,confidence:string,evidence:string}> */
    private array $implicitRouteTargets = array();

    /** @var array<string, mixed> */
    private array $coverage = array();

    private string $entrypointPath = 'index.html';

    public function __construct()
    {
        $this->coverage = $this->newCoverage();
    }

    /** @param array<string, string> $linkTargetPaths */
    public function resetForSinglePage(array $linkTargetPaths): void
    {
        $this->linkTargetPaths = $linkTargetPaths;
        $this->implicitRoutePaths = array();
        $this->implicitRouteTargets = array();
        $this->entrypointPath = 'index.html';
        $this->coverage = $this->newCoverage();
    }

    /**
     * @param array<string, string> $linkTargetPaths
     * @param array<string, string> $implicitRoutePaths
     * @param array<string, array{label:string,path:string,confidence:string,evidence:string}> $implicitRouteTargets
     */
    public function resetForSite(array $linkTargetPaths, string $entrypointPath, array $implicitRoutePaths, array $implicitRouteTargets): void
    {
        $this->linkTargetPaths = $linkTargetPaths;
        $this->entrypointPath = $entrypointPath;
        $this->implicitRoutePaths = $implicitRoutePaths;
        $this->implicitRouteTargets = $implicitRouteTargets;
        $this->coverage = $this->newCoverage();
    }

    public function entrypointPath(): string
    {
        return $this->entrypointPath;
    }

    public function linkTargetPath(string $nodeId): ?string
    {
        return $this->linkTargetPaths[$nodeId] ?? null;
    }

    public function hasImplicitRoute(string $key): bool
    {
        return '' !== $key && isset($this->implicitRoutePaths[$key]);
    }

    public function implicitRoutePath(string $key): ?string
    {
        return $this->implicitRoutePaths[$key] ?? null;
    }

    /** @return array{label:string,path:string,confidence:string,evidence:string}|array{} */
    public function implicitRouteTarget(string $key): array
    {
        return $this->implicitRouteTargets[$key] ?? array();
    }

    public function increment(string $counter): void
    {
        $this->coverage[$counter] = (int) ($this->coverage[$counter] ?? 0) + 1;
    }

    /** @param array<string, string> $target */
    public function appendTarget(string $list, array $target, int $limit = 50): void
    {
        $targets = is_array($this->coverage[$list] ?? null) ? $this->coverage[$list] : array();
        if ( count($targets) >= $limit ) {
            return;
        }

        $targets[] = $target;
        $this->coverage[$list] = $targets;
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        return array(
            'schema'             => 'blocks-engine/figma-transformer/link-coverage/v1',
            'sources_found'      => (int) ($this->coverage['sources_found'] ?? 0),
            'anchors_emitted'    => (int) ($this->coverage['anchors_emitted'] ?? 0),
            'url_links'          => (int) ($this->coverage['url_links'] ?? 0),
            'node_links'         => (int) ($this->coverage['node_links'] ?? 0),
            'toc_links'          => (int) ($this->coverage['toc_links'] ?? 0),
            'implicit_route_links' => (int) ($this->coverage['implicit_route_links'] ?? 0),
            'implicit_route_self_suppressed' => (int) ($this->coverage['implicit_route_self_suppressed'] ?? 0),
            'implicit_route_unresolved' => (int) ($this->coverage['implicit_route_unresolved'] ?? 0),
            'route_targets'      => array_values($this->implicitRouteTargets),
            'implicit_route_unresolved_targets' => array_values(is_array($this->coverage['implicit_route_unresolved_targets'] ?? null) ? $this->coverage['implicit_route_unresolved_targets'] : array()),
            'implicit_route_self_suppressed_targets' => array_values(is_array($this->coverage['implicit_route_self_suppressed_targets'] ?? null) ? $this->coverage['implicit_route_self_suppressed_targets'] : array()),
            'unresolved'         => (int) ($this->coverage['unresolved'] ?? 0),
            'unresolved_targets' => array_values(is_array($this->coverage['unresolved_targets'] ?? null) ? $this->coverage['unresolved_targets'] : array()),
        );
    }

    /** @return array<string, mixed> */
    private function newCoverage(): array
    {
        return array(
            'sources_found'      => 0,
            'anchors_emitted'    => 0,
            'url_links'          => 0,
            'node_links'         => 0,
            'toc_links'          => 0,
            'implicit_route_links' => 0,
            'implicit_route_self_suppressed' => 0,
            'implicit_route_unresolved' => 0,
            'unresolved'         => 0,
            'implicit_route_unresolved_targets' => array(),
            'implicit_route_self_suppressed_targets' => array(),
            'unresolved_targets' => array(),
        );
    }
}
