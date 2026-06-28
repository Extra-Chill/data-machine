/**
 * HandlerDefaultsForm Component
 *
 * Form for editing default values of a specific handler.
 * Renders fields based on the handler's field schema.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';

const HandlerDefaultsForm = ( {
	handlerSlug,
	handlerData,
	onSave,
	isSaving,
} ) => {
	const { defaults, fields, description } = handlerData;
	const [ formValues, setFormValues ] = useState( {} );
	const [ isDirty, setIsDirty ] = useState( false );

	// Initialize form values from current defaults
	useEffect( () => {
		const initialValues = {};
		Object.keys( fields || {} ).forEach( ( fieldKey ) => {
			initialValues[ fieldKey ] =
				defaults?.[ fieldKey ] ?? fields[ fieldKey ]?.default ?? '';
		} );
		setFormValues( initialValues );
		setIsDirty( false );
	}, [ handlerSlug, defaults, fields ] );

	const handleFieldChange = ( fieldKey, value ) => {
		setFormValues( ( prev ) => ( {
			...prev,
			[ fieldKey ]: value,
		} ) );
		setIsDirty( true );
	};

	const handleSubmit = ( e ) => {
		e.preventDefault();
		onSave( handlerSlug, formValues );
		setIsDirty( false );
	};

	const renderField = ( fieldKey, fieldConfig ) => {
		const value = formValues[ fieldKey ] ?? '';
		const fieldType = fieldConfig.type || 'text';
		const fieldId = `handler-${ handlerSlug }-${ fieldKey }`;

		switch ( fieldType ) {
			case 'checkbox':
				return (
					<input
						type="checkbox"
						id={ fieldId }
						checked={ !! value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.checked )
						}
					/>
				);

			case 'select':
				return (
					<select
						id={ fieldId }
						value={ value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.value )
						}
						className="regular-text"
					>
						<option value="">— Select —</option>
						{ Object.entries( fieldConfig.options || {} ).map(
							( [ optValue, optLabel ] ) => (
								<option key={ optValue } value={ optValue }>
									{ optLabel }
								</option>
							)
						) }
					</select>
				);

			case 'textarea':
				return (
					<textarea
						id={ fieldId }
						value={ value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.value )
						}
						className="large-text"
						rows={ 4 }
						placeholder={ fieldConfig.placeholder || '' }
					/>
				);

			case 'number':
				return (
					<input
						type="number"
						id={ fieldId }
						value={ value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.value )
						}
						className="small-text"
						min={ fieldConfig.min }
						max={ fieldConfig.max }
						placeholder={ fieldConfig.placeholder || '' }
					/>
				);

			case 'url':
				return (
					<input
						type="url"
						id={ fieldId }
						value={ value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.value )
						}
						className="regular-text"
						placeholder={ fieldConfig.placeholder || '' }
					/>
				);

			default: // text
				return (
					<input
						type="text"
						id={ fieldId }
						value={ value }
						onChange={ ( e ) =>
							handleFieldChange( fieldKey, e.target.value )
						}
						className="regular-text"
						placeholder={ fieldConfig.placeholder || '' }
					/>
				);
		}
	};

	if ( ! fields || Object.keys( fields ).length === 0 ) {
		return (
			<div className="datamachine-handler-form datamachine-handler-form-empty">
				<p className="description">
					This handler has no configurable fields.
				</p>
			</div>
		);
	}

	return (
		<div className="datamachine-handler-form">
			{ description && (
				<p className="datamachine-handler-description">
					{ description }
				</p>
			) }

			<form onSubmit={ handleSubmit }>
				<table className="form-table">
					<tbody>
						{ Object.entries( fields ).map(
							( [ fieldKey, fieldConfig ] ) => (
								<tr key={ fieldKey }>
									<th scope="row">
										<label
											htmlFor={ `handler-${ handlerSlug }-${ fieldKey }` }
										>
											{ fieldConfig.label || fieldKey }
											{ fieldConfig.required && (
												<span className="required">
													{ ' ' }
													*
												</span>
											) }
										</label>
									</th>
									<td>
										{ renderField( fieldKey, fieldConfig ) }
										{ fieldConfig.description && (
											<p className="description">
												{ fieldConfig.description }
											</p>
										) }
									</td>
								</tr>
							)
						) }
					</tbody>
				</table>

				<p className="submit">
					<button
						type="submit"
						className="button button-primary"
						disabled={ isSaving || ! isDirty }
					>
						{ isSaving ? 'Saving...' : 'Save Defaults' }
					</button>
					{ isDirty && (
						<span className="datamachine-unsaved-indicator">
							Unsaved changes
						</span>
					) }
				</p>
			</form>
		</div>
	);
};

export default HandlerDefaultsForm;
