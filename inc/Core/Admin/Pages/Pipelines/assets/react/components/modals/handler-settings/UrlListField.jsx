/**
 * WordPress dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { plus, closeSmall } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * URL List field component - renders multiple URL inputs with add/remove buttons.
 *
 * @param {Object}   props             Component props
 * @param {string}   props.fieldKey    Schema field key
 * @param {Object}   props.fieldConfig Resolved field configuration from API
 * @param {Array}    props.value       Array of URL strings
 * @param {Function} props.onChange    Change handler (fieldKey, newValue)
 * @return {React.ReactElement} Field control
 */
export default function UrlListField( {
	fieldKey,
	fieldConfig = {},
	value,
	onChange,
} ) {
	const label = fieldConfig.label || fieldKey;
	const help = fieldConfig.description || '';

	// Normalize value to array
	let urls = [ '' ];
	if ( Array.isArray( value ) ) {
		urls = value;
	} else if ( typeof value === 'string' && value.trim() ) {
		urls = value
			.split( /[\r\n]+/ )
			.map( ( url ) => url.trim() )
			.filter( Boolean );
	}

	const handleUrlChange = ( index, newUrl ) => {
		const newUrls = [ ...urls ];
		newUrls[ index ] = newUrl;
		onChange( fieldKey, newUrls );
	};

	const handleAdd = () => {
		onChange( fieldKey, [ ...urls, '' ] );
	};

	const handleRemove = ( index ) => {
		const newUrls = urls.filter( ( _, i ) => i !== index );
		// Keep at least one empty field
		onChange( fieldKey, newUrls.length ? newUrls : [ '' ] );
	};

	return (
		<div className="datamachine-handler-field datamachine-url-list-field">
			<span className="components-base-control__label">{ label }</span>

			{ urls.map( ( url, index ) => (
				<div key={ index } className="datamachine-url-list-field__row">
					<TextControl
						label={ sprintf(
							/* translators: 1: Field label, 2: URL number. */
							__( '%1$s URL %2$d', 'data-machine' ),
							label,
							index + 1
						) }
						hideLabelFromVision
						value={ url }
						onChange={ ( newUrl ) =>
							handleUrlChange( index, newUrl )
						}
						placeholder="https://..."
						__nextHasNoMarginBottom
					/>
					{ urls.length > 1 && (
						<Button
							icon={ closeSmall }
							label={ __( 'Remove URL', 'data-machine' ) }
							onClick={ () => handleRemove( index ) }
							isDestructive
							size="small"
						/>
					) }
				</div>
			) ) }

			<Button
				icon={ plus }
				onClick={ handleAdd }
				variant="secondary"
				size="small"
			>
				{ __( 'Add URL', 'data-machine' ) }
			</Button>

			{ help && (
				<p className="components-base-control__help datamachine-url-list-field__help">
					{ help }
				</p>
			) }
		</div>
	);
}
