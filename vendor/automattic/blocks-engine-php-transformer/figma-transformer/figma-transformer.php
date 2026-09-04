<?php
/**
 * Plugin Name: Blocks Engine Figma Transformer
 * Plugin URI: https://github.com/Automattic/blocks-engine/tree/trunk/figma-transformer
 * Description: PHP primitives for transforming Figma designs into static HTML website artifacts.
 * Version: 0.1.3
 * Requires PHP: 8.1
 * Author: Automattic
 * License: GPL-3.0-or-later
 * Text Domain: blocks-engine-figma-transformer
 *
 * @package BlocksEngineFigmaTransformer
 */

declare(strict_types=1);

if ( ! defined('BLOCKS_ENGINE_FIGMA_TRANSFORMER_VERSION') ) {
    define('BLOCKS_ENGINE_FIGMA_TRANSFORMER_VERSION', '0.1.3');
}

if ( ! defined('BLOCKS_ENGINE_FIGMA_TRANSFORMER_FILE') ) {
    define('BLOCKS_ENGINE_FIGMA_TRANSFORMER_FILE', __FILE__);
}

if ( ! defined('BLOCKS_ENGINE_FIGMA_TRANSFORMER_DIR') ) {
    define('BLOCKS_ENGINE_FIGMA_TRANSFORMER_DIR', __DIR__);
}

blocks_engine_figma_transformer_load_autoloader();

if ( function_exists('do_action') ) {
    do_action('blocks_engine_figma_transformer_loaded');
}

/**
 * Load Composer when available, otherwise register a local source autoloader.
 */
function blocks_engine_figma_transformer_load_autoloader(): void
{
    static $loaded = false;

    if ( $loaded ) {
        return;
    }

    $loaded = true;

    $composerAutoload = __DIR__ . '/vendor/autoload.php';
    if ( is_readable($composerAutoload) ) {
        require_once $composerAutoload;
        return;
    }

    spl_autoload_register(
        static function (string $class): void {
            $prefix = 'Automattic\\BlocksEngine\\FigmaTransformer\\';
            if ( 0 !== strncmp($class, $prefix, strlen($prefix)) ) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

            if ( is_readable($path) ) {
                require_once $path;
            }
        }
    );
}

/**
 * Return the active transformer version.
 */
function blocks_engine_figma_transformer_version(): string
{
    return BLOCKS_ENGINE_FIGMA_TRANSFORMER_VERSION;
}

/**
 * Return the plugin/package directory.
 */
function blocks_engine_figma_transformer_path(): string
{
    return BLOCKS_ENGINE_FIGMA_TRANSFORMER_DIR;
}

/**
 * Transform a Figma file into the canonical result envelope.
 *
 * @param array<string, mixed> $options Transformation options.
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_transform_file(string $path, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\FigmaTransformer\FigmaTransformer() )
        ->transformFile($path, $options)
        ->toArray();
}

/**
 * Transform a normalized scenegraph into the canonical result envelope.
 *
 * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
 * @param array<string, mixed> $options Transformation options.
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_transform_scenegraph(array $scenegraph, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\FigmaTransformer\FigmaTransformer() )
        ->transformScenegraph($scenegraph, $options)
        ->toArray();
}

/**
 * Inspect frame/page candidates in a Figma file.
 *
 * @param array<string, mixed> $options Inspection options.
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_inspect_frames_file(string $path, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\FigmaTransformer\FigmaTransformer() )
        ->inspectFramesFile($path, $options);
}

/**
 * Inspect frame/page candidates in a decoded scenegraph.
 *
 * @param array<string, mixed> $scenegraph Decoded scenegraph.
 * @param array<string, mixed> $options Inspection options.
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_inspect_frames_scenegraph(array $scenegraph, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\FigmaTransformer\FigmaTransformer() )
        ->inspectFramesScenegraph($scenegraph, $options);
}
