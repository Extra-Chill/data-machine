<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Builds the static companion block for compact, editable native select controls.
 */
final class AuthoredSelectBlockGenerator
{
    public const NAME = 'blocks-engine/authored-select';

    /** @return array<string, mixed> */
    public function blockJson(): array
    {
        return array(
            'apiVersion' => 3,
            'name' => self::NAME,
            'title' => 'Select Field',
            'category' => 'widgets',
            'description' => 'An editable native select field.',
            'editorScript' => 'file:./index.js',
            'style' => 'file:./style.css',
            'attributes' => array(
                'id' => array( 'type' => 'string', 'default' => '' ),
                'name' => array( 'type' => 'string', 'default' => '' ),
                'ariaLabel' => array( 'type' => 'string', 'default' => '' ),
                'placeholder' => array( 'type' => 'string', 'default' => '' ),
                'className' => array( 'type' => 'string', 'default' => '' ),
                'style' => array( 'type' => 'string', 'default' => '' ),
                'options' => array( 'type' => 'array', 'default' => array() ),
                // Compatibility metadata for consumers of the former readable
                // approximation. It has no rendered geometry.
                'selectedSummary' => array( 'type' => 'string', 'default' => '' ),
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
    var RichText = blockEditor.RichText;
    var attributes = __BLOCK_ATTRIBUTES__;
    function escapeAttribute( value ) { return String( value || '' ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function selectAttributes( attrs ) { var output = ''; [ 'id', 'name', 'ariaLabel', 'placeholder', 'className', 'style' ].forEach( function( key ) { if ( attrs[ key ] ) { output += ' ' + ( 'className' === key ? 'class' : ( 'ariaLabel' === key ? 'aria-label' : key ) ) + '="' + escapeAttribute( attrs[ key ] ) + '"'; } } ); return output; }
    function markup( attrs ) { var output = '<select' + selectAttributes( attrs ) + '>'; ( attrs.options || [] ).forEach( function( option ) { var value = Object.prototype.hasOwnProperty.call( option, 'value' ) ? option.value : option.label; output += '<option value="' + escapeAttribute( value ) + '"' + ( option.selected ? ' selected' : '' ) + ( option.disabled ? ' disabled' : '' ) + '>' + ( option.label || '' ) + '</option>'; } ); return output + '</select>'; }
    function edit( props ) { var attrs = props.attributes; var options = ( attrs.options || [] ).map( function( option ) { return createElement( 'option', { value: option.value || option.label, disabled: option.disabled, selected: option.selected, key: option.value || option.label }, option.label ); } ); return createElement( 'select', { id: attrs.id || undefined, name: attrs.name || undefined, className: attrs.className || undefined, style: attrs.style || undefined, onChange: function( event ) { props.setAttributes( { options: ( attrs.options || [] ).map( function( option ) { return Object.assign( {}, option, { selected: option.value === event.target.value } ); } ) } ); } }, options ); }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( 'blocks-engine/authored-select', { attributes: attributes, supports: { html: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        return array(
            'index.js' => str_replace('__BLOCK_ATTRIBUTES__', json_encode($this->blockJson()['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $script),
            // The legacy core/group boundary is retained for compatibility without
            // introducing a layout box around the authored native control.
            'style.css' => '.wp-block-group.blocks-engine-authored-select-wrapper{display:contents}',
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $select = '';
        foreach ( array( 'id', 'name', 'ariaLabel', 'placeholder', 'className', 'style' ) as $key ) {
            $value = trim((string) ($attrs[$key] ?? ''));
            if ( '' !== $value ) {
                $select .= ' ' . ( 'className' === $key ? 'class' : ( 'ariaLabel' === $key ? 'aria-label' : $key ) ) . '="' . $escape($value) . '"';
            }
        }
        $markup = '<select' . $select . '>';
        foreach ( $attrs['options'] ?? array() as $option ) {
            if ( ! is_array($option) || '' === trim((string) ($option['label'] ?? '')) ) {
                continue;
            }
            $value = array_key_exists('value', $option) ? (string) $option['value'] : (string) $option['label'];
            $markup .= '<option value="' . $escape($value) . '"'
                . ( ! empty($option['selected']) ? ' selected' : '' )
                . ( ! empty($option['disabled']) ? ' disabled' : '' )
                . '>' . $escape($option['label']) . '</option>';
        }

        return $markup . '</select>';
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return array( 'name' => 'authored-select', 'block_json' => $this->blockJson(), 'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ), 'assets' => $this->assets() );
    }
}
