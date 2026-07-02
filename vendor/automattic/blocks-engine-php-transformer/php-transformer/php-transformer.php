<?php
/**
 * Plugin Name: Blocks Engine PHP Transformer
 * Plugin URI: https://github.com/Automattic/blocks-engine/tree/trunk/php-transformer
 * Description: Canonical PHP primitives for transforming HTML, Markdown, and website artifacts into WordPress block outputs.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Author: Automattic
 * License: GPL-3.0-or-later
 * Text Domain: blocks-engine-php-transformer
 *
 * @package BlocksEnginePhpTransformer
 */

declare(strict_types=1);

if ( ! defined('BLOCKS_ENGINE_PHP_TRANSFORMER_VERSION') ) {
    define('BLOCKS_ENGINE_PHP_TRANSFORMER_VERSION', '0.1.0');
}

if ( ! defined('BLOCKS_ENGINE_PHP_TRANSFORMER_FILE') ) {
    define('BLOCKS_ENGINE_PHP_TRANSFORMER_FILE', __FILE__);
}

if ( ! defined('BLOCKS_ENGINE_PHP_TRANSFORMER_DIR') ) {
    define('BLOCKS_ENGINE_PHP_TRANSFORMER_DIR', __DIR__);
}

blocks_engine_php_transformer_load_autoloader();

if ( function_exists('do_action') ) {
    do_action('blocks_engine_php_transformer_loaded');
}

/**
 * Load Composer when available, otherwise register a local source autoloader.
 */
function blocks_engine_php_transformer_load_autoloader(): void
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
            $prefix = 'Automattic\\BlocksEngine\\PhpTransformer\\';
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
function blocks_engine_php_transformer_version(): string
{
    return BLOCKS_ENGINE_PHP_TRANSFORMER_VERSION;
}

/**
 * Return the plugin/package directory.
 */
function blocks_engine_php_transformer_path(): string
{
    return BLOCKS_ENGINE_PHP_TRANSFORMER_DIR;
}

/**
 * Transform HTML into the canonical result envelope.
 *
 * @param array<string, mixed> $options Transformation options.
 * @return array<string, mixed>
 */
function blocks_engine_php_transformer_transform_html(string $html, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer() )
        ->transform($html, $options)
        ->toArray();
}

/**
 * Convert content between supported formats through the canonical result envelope.
 *
 * @param array<string, mixed> $options Transformation options.
 * @return array<string, mixed>
 */
function blocks_engine_php_transformer_convert_format(string $content, string $fromFormat, string $toFormat, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\PhpTransformer\FormatBridge\FormatBridge() )
        ->convertResult($content, $fromFormat, $toFormat, $options)
        ->toArray();
}

/**
 * Compile a generated website artifact into the canonical result envelope.
 *
 * @param array<string, mixed> $artifact Generated artifact input.
 * @param array<string, mixed> $options Transformation options.
 * @return array<string, mixed>
 */
function blocks_engine_php_transformer_compile_artifact(array $artifact, array $options = array()): array
{
    return ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )
        ->compile($artifact, $options)
        ->toArray();
}
