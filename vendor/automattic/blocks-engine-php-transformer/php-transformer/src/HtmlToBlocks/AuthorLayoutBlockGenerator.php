<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Builds the editable companion block for source CSS-owned layout islands. */
final class AuthorLayoutBlockGenerator
{
    public const NAME = 'blocks-engine/author-layout';

    /** @return array<string, mixed> */
    public function blockJson(): array
    {
        return array(
            'apiVersion' => 3,
            'name' => self::NAME,
            'title' => 'Author Layout',
            'category' => 'design',
            'description' => 'An editable semantic container whose layout remains owned by author CSS.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'anchor' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'content' => array( 'type' => 'string', 'default' => '' ),
                'contentMode' => array( 'type' => 'string', 'default' => 'inner-blocks' ),
                'sourceAttributes' => array( 'type' => 'object', 'default' => array() ),
                'tagName' => array( 'type' => 'string', 'default' => 'div' ),
                'url' => array( 'type' => 'string', 'default' => '' ),
            ),
            'supports' => array(
                'html' => false,
                'layout' => false,
                'spacing' => array( 'blockGap' => false ),
            ),
        );
    }

    /** @return array<string, string> */
    public function assets(): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var InnerBlocks = blockEditor.InnerBlocks;
    var RichText = blockEditor.RichText;
    var useBlockProps = blockEditor.useBlockProps;
    var attributes = __BLOCK_ATTRIBUTES__;
    function wrapperProps( attributes ) {
        var props = Object.assign( {}, attributes.sourceAttributes || {} );
        if ( attributes.anchor ) { props.id = attributes.anchor; }
        if ( attributes.className ) { props.className = attributes.className; }
        if ( attributes.url && attributes.tagName === 'a' ) { props.href = attributes.url; }
        return props;
    }
    function tagName( attributes ) { return attributes.tagName || 'div'; }
    function isLeaf( attributes ) { return attributes.contentMode === 'rich-text'; }
    function edit( props ) {
        if ( isLeaf( props.attributes ) ) {
            return createElement( RichText, Object.assign( { tagName: tagName( props.attributes ), value: props.attributes.content || '', onChange: function( content ) { props.setAttributes( { content: content } ); } }, useBlockProps( wrapperProps( props.attributes ) ) ) );
        }
        return createElement( tagName( props.attributes ), useBlockProps( wrapperProps( props.attributes ) ), createElement( InnerBlocks ) );
    }
    function save( props ) {
        if ( isLeaf( props.attributes ) ) {
            return createElement( RichText.Content, Object.assign( { tagName: tagName( props.attributes ), value: props.attributes.content || '' }, useBlockProps.save( wrapperProps( props.attributes ) ) ) );
        }
        return createElement( tagName( props.attributes ), useBlockProps.save( wrapperProps( props.attributes ) ), createElement( InnerBlocks.Content ) );
    }
    blocks.registerBlockType( 'blocks-engine/author-layout', { attributes: attributes, supports: { html: false, layout: false, spacing: { blockGap: false } }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        return array(
            'index.js' => str_replace('__BLOCK_ATTRIBUTES__', json_encode($this->blockJson()['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $script),
        );
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array( 'name' => 'author-layout', 'block_json' => $this->blockJson(), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ), 'assets' => $this->assets() );
    }
}
