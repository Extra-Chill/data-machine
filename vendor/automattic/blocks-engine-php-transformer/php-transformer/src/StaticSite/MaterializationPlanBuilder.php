<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class MaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/materialization-plan/v1';

    /**
     * @return array<string,mixed>
     */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);

        $materializationPlan = $data['source_reports']['materialization_plan'] ?? array();
        if ( is_array($materializationPlan) && array() !== $materializationPlan ) {
            return $materializationPlan;
        }

        $compiledSite = $data['source_reports']['compiled_site'] ?? array();
        return is_array($compiledSite) ? $this->fromCompiledSite($compiledSite) : $this->emptyPlan();
    }

    /**
     * @param array<string,mixed> $compiledSite
     * @return array<string,mixed>
     */
    public function fromCompiledSite(array $compiledSite): array
    {
        $pages = $this->pages((array) ($compiledSite['pages'] ?? array()));
        $templateParts = $this->templateParts((array) ($compiledSite['template_parts'] ?? array()));
        $assets = $this->assets((array) ($compiledSite['assets'] ?? array()));
        $visualRepair = is_array($compiledSite['visual_repair'] ?? null) ? $compiledSite['visual_repair'] : array();
        $routes = $this->routes($pages);
        $navigationLinks = $this->navigationLinks($pages, $templateParts, $routes);
        $menus = $this->menus($navigationLinks);
        $assetRewriteCandidates = $this->assetRewriteCandidates($pages, $templateParts, $assets);

        $plan = array(
            'schema'         => self::SCHEMA,
            'source_schema'  => (string) ($compiledSite['schema'] ?? ''),
            'source_hash'    => (string) ($compiledSite['source_hash'] ?? ''),
            'entry_path'     => (string) ($compiledSite['entry_path'] ?? ''),
            'pages'          => $pages,
            'routes'         => $routes,
            'navigation_links' => $navigationLinks,
            'menus'          => $menus,
            'template_parts' => $templateParts,
            'template_part_writes' => $this->templatePartWrites($templateParts),
            'assets'         => $assets,
            'theme'          => $this->theme((array) ($compiledSite['theme'] ?? array()), $templateParts, $assets, $visualRepair),
            'visual_repair_css' => (string) ($visualRepair['css'] ?? ''),
            'asset_rewrite_candidates' => $assetRewriteCandidates,
            'rewrite_candidates' => $assetRewriteCandidates,
            'totals'         => array(
                'pages'          => count($pages),
                'routes'         => count($routes),
                'navigation_links' => count($navigationLinks),
                'menus'          => count($menus),
                'template_parts' => count($templateParts),
                'assets'         => count($assets),
            ),
        );

        return $plan;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPlan(): array
    {
        return array(
            'schema' => self::SCHEMA,
            'pages' => array(),
            'routes' => array(),
            'navigation_links' => array(),
            'menus' => array(),
            'template_parts' => array(),
            'template_part_writes' => array(),
            'assets' => array(),
            'theme' => array(),
            'asset_rewrite_candidates' => array(),
            'rewrite_candidates' => array(),
            'totals' => array('pages' => 0, 'routes' => 0, 'navigation_links' => 0, 'menus' => 0, 'template_parts' => 0, 'assets' => 0),
        );
    }

    /**
     * @param array<int,mixed> $pages
     * @return array<int,array<string,mixed>>
     */
    private function pages(array $pages): array
    {
        $planned = array();
        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'source_path' => (string) ($page['source_path'] ?? ''),
                'slug'        => (string) ($page['slug'] ?? ''),
                'title'       => (string) ($page['title'] ?? ''),
                'post_type'   => (string) (($page['metadata']['post_type'] ?? '') ?: 'page'),
                'body_format' => (string) ($page['body_format'] ?? ''),
                'block_markup' => (string) ($page['block_markup'] ?? ''),
                'entrypoint'  => ! empty($page['entrypoint']),
                'metadata'    => is_array($page['metadata'] ?? null) ? $page['metadata'] : array(),
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }
        return $planned;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<int,array<string,mixed>>
     */
    private function routes(array $pages): array
    {
        $routes = array();
        foreach ( $pages as $index => $page ) {
            $sourcePath = (string) ($page['source_path'] ?? '');
            $targetSlug = (string) ($page['slug'] ?? '');
            if ( '' === $targetSlug && '' !== $sourcePath ) {
                $targetSlug = $this->slugFromPath($sourcePath);
            }

            $routes[] = array_filter(array(
                'kind'        => 'route',
                'source_path' => $sourcePath,
                'target_path' => $this->routePath($page),
                'target_slug' => $targetSlug,
                'title'       => (string) ($page['title'] ?? ''),
                'parent_source_path' => (string) (($page['metadata']['parent_source_path'] ?? '') ?: ''),
                'source_relation' => ! empty($page['entrypoint']) ? 'entrypoint' : 'document',
                'order'       => $index,
            ), static fn (mixed $value): bool => '' !== $value);
        }

        return $routes;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $templateParts
     * @param array<int,array<string,mixed>> $routes
     * @return array<int,array<string,mixed>>
     */
    private function navigationLinks(array $pages, array $templateParts, array $routes): array
    {
        $links = array();
        $targetSlugsByPath = array();
        foreach ( $routes as $route ) {
            $targetPath = (string) ($route['target_path'] ?? '');
            if ( '' !== $targetPath ) {
                $targetSlugsByPath[$targetPath] = (string) ($route['target_slug'] ?? '');
            }
        }

        foreach ( array('template_part' => $templateParts, 'page' => $pages) as $kind => $documents ) {
            foreach ( $documents as $document ) {
                $sourcePath = (string) ($document['source_path'] ?? '');
                $sourceRelation = 'template_part' === $kind ? 'template_part_navigation' : 'page_navigation';
                foreach ( $this->anchorLinks((string) ($document['block_markup'] ?? '')) as $index => $anchor ) {
                    $targetPath = $this->targetPathFromHref($anchor['href']);
                    $links[] = array_filter(array(
                        'kind'        => 'navigation_link',
                        'source_path' => $sourcePath,
                        'menu_source_path' => $sourcePath,
                        'target_path' => $targetPath,
                        'target_slug' => $targetSlugsByPath[$targetPath] ?? $this->slugFromHref($anchor['href']),
                        'label'       => $anchor['label'],
                        'title'       => $anchor['label'],
                        'parent_source_path' => '',
                        'source_relation' => $sourceRelation,
                        'order'       => $index,
                    ), static fn (mixed $value): bool => '' !== $value);
                }
            }
        }

        return $this->dedupeRows($links);
    }

    /**
     * @param array<int,array<string,mixed>> $navigationLinks
     * @return array<int,array<string,mixed>>
     */
    private function menus(array $navigationLinks): array
    {
        $menus = array();
        $orders = array();
        foreach ( $navigationLinks as $link ) {
            $sourcePath = (string) ($link['menu_source_path'] ?? $link['source_path'] ?? '');
            if ( '' === $sourcePath ) {
                continue;
            }
            if ( ! isset($menus[$sourcePath]) ) {
                $orders[$sourcePath] = count($orders);
                $menus[$sourcePath] = array(
                    'kind'        => 'menu',
                    'source_path' => $sourcePath,
                    'target_slug' => $this->slugFromPath($sourcePath),
                    'title'       => $this->titleFromPath($sourcePath),
                    'source_relation' => 'navigation_links',
                    'order'       => $orders[$sourcePath],
                    'items'       => 0,
                );
            }
            ++$menus[$sourcePath]['items'];
        }

        return array_values($menus);
    }

    /**
     * @param array<int,mixed> $templateParts
     * @return array<int,array<string,mixed>>
     */
    private function templateParts(array $templateParts): array
    {
        $planned = array();
        foreach ( $templateParts as $part ) {
            if ( ! is_array($part) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'source_path' => (string) ($part['source_path'] ?? ''),
                'slug'        => (string) ($part['slug'] ?? ''),
                'title'       => (string) ($part['title'] ?? ''),
                'area'        => (string) ($part['area'] ?? 'uncategorized'),
                'body_format' => (string) ($part['body_format'] ?? ''),
                'block_markup' => (string) ($part['block_markup'] ?? ''),
                'metadata'    => is_array($part['metadata'] ?? null) ? $part['metadata'] : array(),
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }
        return $planned;
    }

    /**
     * @param array<int,array<string,mixed>> $templateParts
     * @return array<int,array<string,mixed>>
     */
    private function templatePartWrites(array $templateParts): array
    {
        $writes = array();
        foreach ( $templateParts as $part ) {
            $writes[] = array_filter(array(
                'type'        => 'wp_template_part',
                'source_path' => (string) ($part['source_path'] ?? ''),
                'slug'        => (string) ($part['slug'] ?? ''),
                'title'       => (string) ($part['title'] ?? ''),
                'area'        => (string) ($part['area'] ?? 'uncategorized'),
                'content'     => (string) ($part['block_markup'] ?? ''),
            ), static fn (mixed $value): bool => '' !== $value);
        }
        return $writes;
    }

    /**
     * @param array<int,mixed> $assets
     * @return array<int,array<string,mixed>>
     */
    private function assets(array $assets): array
    {
        $planned = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'source'           => (string) ($asset['source'] ?? ''),
                'path'             => (string) ($asset['path'] ?? ''),
                'target_path'      => (string) ($asset['target_path'] ?? $asset['path'] ?? ''),
                'kind'             => (string) ($asset['kind'] ?? ''),
                'role'             => (string) ($asset['role'] ?? ''),
                'intent'           => (string) ($asset['intent'] ?? ''),
                'media_type'       => (string) ($asset['media_type'] ?? $asset['mime_type'] ?? ''),
                'mime_type'        => (string) ($asset['mime_type'] ?? ''),
                'bytes'            => (int) ($asset['bytes'] ?? 0),
                'binary'           => ! empty($asset['binary']),
                'content_encoding' => (string) ($asset['content_encoding'] ?? $asset['encoding'] ?? ''),
                'content'          => $asset['content'] ?? null,
                'content_base64'   => $asset['content_base64'] ?? null,
                'hash'             => (string) ($asset['hash'] ?? $asset['provenance']['hash'] ?? ''),
            ), static fn (mixed $value): bool => null !== $value && '' !== $value && 0 !== $value && false !== $value);
        }
        return $planned;
    }

    /**
     * @param array<string,mixed> $theme
     * @param array<int,array<string,mixed>> $templateParts
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,mixed> $visualRepair
     * @return array<string,mixed>
     */
    private function theme(array $theme, array $templateParts, array $assets, array $visualRepair): array
    {
        return array_filter(array(
            'stylesheets' => $theme['stylesheets'] ?? $this->assetPathsByRole($assets, 'stylesheet'),
            'scripts' => $theme['scripts'] ?? $this->assetPathsByRole($assets, 'script'),
            'fonts' => $theme['fonts'] ?? $this->assetPathsByRole($assets, 'font'),
            'images' => $theme['images'] ?? $this->assetPathsByRole($assets, 'image'),
            'template_parts' => array_values(array_map(static fn (array $part): string => (string) ($part['source_path'] ?? ''), $templateParts)),
            'visual_repair_css' => (string) ($visualRepair['css'] ?? ''),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /**
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,string>
     */
    private function assetPathsByRole(array $assets, string $role): array
    {
        $paths = array();
        foreach ( $assets as $asset ) {
            if ( $role === ($asset['role'] ?? '') && '' !== ($asset['path'] ?? '') ) {
                $paths[] = (string) $asset['path'];
            }
        }
        return $paths;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $templateParts
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function assetRewriteCandidates(array $pages, array $templateParts, array $assets): array
    {
        $assetPaths = array_values(array_filter(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)));
        if ( array() === $assetPaths ) {
            return array();
        }

        $candidates = array();
        foreach ( array('page' => $pages, 'template_part' => $templateParts) as $scope => $documents ) {
            foreach ( $documents as $document ) {
                $markup = (string) ($document['block_markup'] ?? '');
                foreach ( $assetPaths as $assetPath ) {
                    if ( '' === $markup || ! str_contains($markup, $assetPath) ) {
                        continue;
                    }

                    $candidates[] = array_filter(array(
                        'scope'       => $scope,
                        'source_path' => (string) ($document['source_path'] ?? ''),
                        'slug'        => (string) ($document['slug'] ?? ''),
                        'asset_path'  => $assetPath,
                    ), static fn (mixed $value): bool => '' !== $value);
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<string,mixed> $page
     */
    private function routePath(array $page): string
    {
        $sourcePath = (string) ($page['source_path'] ?? '');
        $slug = (string) ($page['slug'] ?? '');
        if ( ! empty($page['entrypoint']) || preg_match('#(^|/)index\.[A-Za-z0-9]+$#', $sourcePath) ) {
            return '/';
        }

        return '/' . trim('' !== $slug ? $slug : $this->slugFromPath($sourcePath), '/');
    }

    /**
     * @return array<int,array{href:string,label:string}>
     */
    private function anchorLinks(string $markup): array
    {
        if ( '' === trim($markup) || ! preg_match_all('/<nav\b[^>]*>(.*?)<\/nav>/is', $markup, $navMatches) ) {
            return array();
        }

        $links = array();
        foreach ( $navMatches[1] as $navHtml ) {
            if ( ! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', (string) $navHtml, $anchorMatches, PREG_SET_ORDER) ) {
                continue;
            }
            foreach ( $anchorMatches as $anchorMatch ) {
                $href = $this->attributeValue((string) $anchorMatch[1], 'href');
                $label = trim(html_entity_decode(strip_tags((string) $anchorMatch[2]), ENT_QUOTES | ENT_HTML5));
                if ( '' === $href || '' === $label ) {
                    continue;
                }
                $links[] = array(
                    'href'  => $href,
                    'label' => $label,
                );
            }
        }

        return $links;
    }

    private function attributeValue(string $attributes, string $name): string
    {
        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $attributes, $match) ) {
            return html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5);
        }

        if ( preg_match('/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*([^\s"\'>]+)/is', $attributes, $match) ) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    private function targetPathFromHref(string $href): string
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?: '');
        if ( '' === $path ) {
            return '';
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/index\.[A-Za-z0-9]+$#', '/', $path) ?? $path;
        $path = preg_replace('/\.[A-Za-z0-9]+$/', '', $path) ?? $path;
        if ( '/' !== $path ) {
            $path = rtrim($path, '/');
        }

        return '' === $path ? '/' : $path;
    }

    private function slugFromHref(string $href): string
    {
        $targetPath = $this->targetPathFromHref($href);
        if ( '/' === $targetPath ) {
            return 'index';
        }

        return $this->slugFromPath($targetPath);
    }

    private function slugFromPath(string $path): string
    {
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
        $base = '' === $base || null === $base ? 'document' : $base;
        return strtolower((string) preg_replace('/[^a-z0-9-]+/', '-', str_replace(array('_', '.'), '-', $base)));
    }

    private function titleFromPath(string $path): string
    {
        return ucwords(str_replace('-', ' ', $this->slugFromPath($path)));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function dedupeRows(array $rows): array
    {
        $deduped = array();
        $seen = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }
}
