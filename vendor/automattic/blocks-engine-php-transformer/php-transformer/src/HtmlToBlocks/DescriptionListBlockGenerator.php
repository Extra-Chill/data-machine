<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Builds the static companion block that fills Gutenberg's description-list gap.
 *
 * Gutenberg issue #4880 remains unresolved; the proposed core implementation in
 * stalled PR #20760 is not available to generated sites.
 */
final class DescriptionListBlockGenerator
{
    public const NAME = 'blocks-engine/description-list';

    /** @return array<string, mixed> */
    public function blockJson(): array
    {
        return array(
            'apiVersion' => 3,
            'name' => self::NAME,
            'title' => 'Description List',
            'category' => 'text',
            'description' => 'A semantic description list with terms and descriptions.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'groups' => array( 'type' => 'array', 'default' => array() ),
            ),
            'supports' => array( 'html' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RawHTML = element.RawHTML;
    var RichText = blockEditor.RichText;
    var useEffect = element.useEffect;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function safeCssText( value ) {
        var probe = document.createElement( 'span' );
        probe.setAttribute( 'style', value || '' );
        return probe.style.cssText;
    }
    function markupAttributes( item ) {
        var output = '';
        if ( item.className ) { output += ' class="' + escapeAttribute( item.className ) + '"'; }
        if ( item.style ) { output += ' style="' + escapeAttribute( item.style ) + '"'; }
        Object.keys( item.attributes || {} ).forEach( function( name ) { output += ' ' + name + '="' + escapeAttribute( item.attributes[ name ] ) + '"'; } );
        return output;
    }
    function groupItems( group ) { return group.items || [].concat( ( group.terms || [] ).map( function( item ) { return Object.assign( { tagName: 'dt' }, item ); } ), ( group.descriptions || [] ).map( function( item ) { return Object.assign( { tagName: 'dd' }, item ); } ) ); }
    function markup( blockAttributes ) {
        var output = '<dl' + markupAttributes( blockAttributes ) + '>';
        ( blockAttributes.groups || [] ).forEach( function( group ) {
            var wrapper = group.wrapper;
            if ( wrapper ) { output += '<div' + markupAttributes( wrapper ) + '>'; }
            groupItems( group ).forEach( function( item ) { output += '<' + item.tagName + markupAttributes( item ) + '>' + ( item.content || '' ) + '</' + item.tagName + '>'; } );
            if ( wrapper ) { output += '</div>'; }
        } );
        return output + '</dl>';
    }
    function updateItem( props, groupIndex, collection, itemIndex, content ) {
        var groups = ( props.attributes.groups || [] ).map( function( group ) {
            var clone = {
                terms: ( group.terms || [] ).map( function( item ) { return Object.assign( {}, item ); } ),
                descriptions: ( group.descriptions || [] ).map( function( item ) { return Object.assign( {}, item ); } )
            };
            if ( group.items ) { clone.items = group.items.map( function( item ) { return Object.assign( {}, item ); } ); }
            if ( group.wrapper ) { clone.wrapper = Object.assign( {}, group.wrapper ); }
            return clone;
        } );
        groups[ groupIndex ][ collection ][ itemIndex ].content = content;
        props.setAttributes( { groups: groups } );
    }
    function updateOrderedItem( props, groupIndex, itemIndex, content ) {
        var groups = ( props.attributes.groups || [] ).map( function( group ) { return Object.assign( {}, group, { items: ( group.items || [] ).map( function( item ) { return Object.assign( {}, item ); } ) } ); } );
        groups[ groupIndex ].items[ itemIndex ].content = content;
        props.setAttributes( { groups: groups } );
    }
    function editorAttributes( item ) { return Object.assign( {}, item.attributes || {}, item.className ? { className: item.className } : {} ); }
    function edit( props ) {
        var children = [];
        var scope = 'be-description-list-' + String( props.clientId || 'block' ).replace( /[^a-zA-Z0-9_-]/g, '' );
        var rules = safeCssText( props.attributes.style ) ? '.' + scope + '{' + safeCssText( props.attributes.style ) + '}' : '';
        ( props.attributes.groups || [] ).forEach( function( group, groupIndex ) {
            var groupChildren = [];
            var wrapper = group.wrapper;
            if ( wrapper && safeCssText( wrapper.style ) ) { rules += '.' + scope + ' [data-be-description-list-group="' + groupIndex + '"]{' + safeCssText( wrapper.style ) + '}'; }
            groupItems( group ).forEach( function( item, itemIndex ) {
                var key = item.tagName + '-' + groupIndex + '-' + itemIndex;
                var css = safeCssText( item.style );
                if ( css ) { rules += '.' + scope + ' [data-be-description-list-item="' + key + '"]{' + css + '}'; }
                groupChildren.push( createElement( RichText, Object.assign( editorAttributes( item ), { tagName: item.tagName, value: item.content || '', 'data-be-description-list-item': key, key: key, onChange: function( content ) { if ( group.items ) { updateOrderedItem( props, groupIndex, itemIndex, content ); } else { updateItem( props, groupIndex, 'dt' === item.tagName ? 'terms' : 'descriptions', 'dt' === item.tagName ? itemIndex : itemIndex - ( group.terms || [] ).length, content ); } } } ) ) );
            } );
            if ( wrapper ) { children.push( createElement( 'div', Object.assign( editorAttributes( wrapper ), { 'data-be-description-list-group': groupIndex, key: 'group-' + groupIndex } ), groupChildren ) ); } else { children = children.concat( groupChildren ); }
        } );
        useEffect( function() {
            if ( ! rules ) { return undefined; }
            var sheet = document.createElement( 'style' );
            sheet.textContent = rules;
            document.head.appendChild( sheet );
            return function() { sheet.remove(); };
        }, [ rules ] );
        return createElement( 'dl', { className: [ props.attributes.className, scope ].filter( Boolean ).join( ' ' ) }, children );
    }
    function save( props ) { return createElement( RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( 'blocks-engine/description-list', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(
                '__BLOCK_ATTRIBUTES__',
                json_encode($this->blockJson()['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $script
            ),
        );
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array(
            'name' => 'description-list',
            'block_json' => $this->blockJson(),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ),
            'assets' => $this->assets(),
        );
    }
}
