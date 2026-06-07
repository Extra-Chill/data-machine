/**
 * API Config Form Component
 *
 * Form for simple authentication with API key/secret fields.
 */

/**
 * WordPress dependencies
 */
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * API Config Form Component
 *
 * @param {Object}        props          - Component props
 * @param {Object}        props.config   - Current API configuration
 * @param {Function}      props.onChange - Configuration change handler
 * @param {Array<Object>} props.fields   - Field definitions from handler
 * @return {React.ReactElement} API config form
 */
export default function APIConfigForm( {
	config = {},
	onChange,
	fields = [],
} ) {
	/**
	 * Handle field change
	 * @param fieldKey
	 * @param value
	 */
	const handleFieldChange = ( fieldKey, value ) => {
		if ( onChange ) {
			onChange( {
				...config,
				[ fieldKey ]: value,
			} );
		}
	};

	// Default fields if none provided
	const defaultFields = [
		{
			key: 'api_key',
			label: __( 'API Key', 'data-machine' ),
			type: 'text',
			required: true,
		},
		{
			key: 'api_secret',
			label: __( 'API Secret', 'data-machine' ),
			type: 'password',
			required: true,
		},
	];

	// Convert object to array if needed (API returns object keyed by field name)
	const fieldsArray = Array.isArray( fields )
		? fields
		: Object.entries( fields ).map( ( [ key, field ] ) => ( {
				...field,
				key,
		  } ) );

	const fieldsToRender = fieldsArray.length > 0 ? fieldsArray : defaultFields;

	return (
		<div className="datamachine-api-config-form">
			<p className="datamachine-api-config-description">
				{ __( 'Enter your API credentials:', 'data-machine' ) }
			</p>

			{ fieldsToRender.map( ( field ) => (
				<TextControl
					key={ field.key }
					label={ field.label }
					type={ field.type || 'text' }
					value={ config[ field.key ] || '' }
					onChange={ ( value ) =>
						handleFieldChange( field.key, value )
					}
					placeholder={ field.placeholder || '' }
					help={ field.help || '' }
					required={ field.required || false }
				/>
			) ) }
		</div>
	);
}
