<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Builds static-render custom block definitions for source subtrees that
 * the {@see Classification\SubtreeClassifier} identifies as cohesive custom-block
 * content units which map to nothing native/Automattic.
 *
 * This is the producer link of the classify -> route -> generate chain
 * (epic #497, keystone #491): the classifier decides a `core/html`-fallback
 * subtree IS a `custom_block`, and this generator turns it into an installable
 * custom block. The output shape (`name`, `block_json`, `render`) exactly
 * matches what the SSI companion-plugin scaffolder consumes
 * (Static_Site_Importer_Companion_Plugin::scaffold()) and what
 * {@see \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload}
 * packages into `companion_plugin_payload.blocks[]`.
 *
 * First-slice design (conservative):
 *  - Static render: the editable content remains on the block reference, while
 *    each content-sensitive block type carries the same sanitized HTML as its
 *    render payload. This satisfies the companion payload's static-HTML contract
 *    without allowing different instances to share the wrong render.
 *  - GENERIC only: names derive deterministically from structure and sanitized
 *    content; titles derive from generic structure, never fixture/site strings.
 *  - Pure: no I/O, no global state; the same inputs always yield the same
 *    definition.
 */
final class CustomBlockGenerator
{
    /**
     * Block-editor category for generated blocks. `widgets` is the generic
     * catch-all category that always exists in a stock editor.
     */
    public const CATEGORY = 'widgets';

    /**
     * Build the block.json descriptor (as an array) for a generated block type.
     *
     * @param string $blockName Fully-qualified block name (`namespace/local`).
     * @param string $title     Human-readable, generically-derived title.
     * @return array<string, mixed>
     */
    public function blockJson(string $blockName, string $title): array
    {
        return array(
            'apiVersion' => 3,
            'name'       => $blockName,
            'title'      => $title,
            'category'   => self::CATEGORY,
            'attributes' => array(
                // The captured, sanitized subtree markup. Editable, so the block
                // is a real content unit rather than frozen raw HTML.
                'content' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
            'supports'   => array(
                'html' => false,
            ),
            'render'     => 'file:./render.php',
        );
    }

    /**
     * Static render HTML for a generated block definition. The caller supplies
     * the already-sanitized content carried by that definition's references.
     */
    public function render(string $content): string
    {
        return $content;
    }

    /**
     * Per-instance attributes for the self-closing block reference emitted in
     * the converted output. Carries only the captured content; no innerHTML.
     *
     * @return array<string, mixed>
     */
    public function referenceAttributes(string $content): array
    {
        return array(
            'content' => $content,
        );
    }
}
