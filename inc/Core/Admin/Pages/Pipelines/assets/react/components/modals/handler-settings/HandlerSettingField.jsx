/**
 * WordPress dependencies
 */
import {
	TextControl,
	TextareaControl,
	SelectControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import UrlListField from './UrlListField';

const formatJsonValue = ( value ) => {
	if ( value === undefined || value === null || value === '' ) {
		return '';
	}

	if ( typeof value === 'string' ) {
		return value;
	}

	return JSON.stringify( value, null, 2 );
};

/**
 * Shared renderer for handler schema-driven fields.
 *
 * Uses resolved field state from API (backend single source of truth).
 * Supports custom field components via 'datamachine.handlerSettings.fieldComponent' filter.
 *
 * @param {Object}   props               Component props
 * @param {string}   props.fieldKey      Schema field key
 * @param {Object}   props.fieldConfig   Resolved field configuration from API
 * @param {Function} props.onChange      Change handler for single field
 * @param {Function} props.onBatchChange Change handler for multiple fields at once
 * @param {string}   props.handlerSlug   Current handler slug
 * @param            props.value
 * @return {React.ReactElement} Field control
 */
export default function HandlerSettingField( {
	fieldKey,
	fieldConfig = {},
	value,
	onChange,
	onBatchChange,
	handlerSlug,
} ) {
	const label = fieldConfig.label || fieldKey;
	const help = fieldConfig.description || '';
	const resolvedValue =
		value !== undefined
			? value
			: fieldConfig.current_value ?? fieldConfig.default ?? '';

	const handleChange = ( nextValue ) => {
		if ( typeof onChange === 'function' ) {
			onChange( fieldKey, nextValue );
		}
	};

	const wrapperClassName = 'datamachine-handler-field';

	// Allow plugins to provide custom field components
	const CustomComponent = applyFilters(
		'datamachine.handlerSettings.fieldComponent',
		null,
		fieldConfig.type,
		fieldKey,
		handlerSlug
	);

	if ( CustomComponent ) {
		return (
			<CustomComponent
				fieldKey={ fieldKey }
				fieldConfig={ fieldConfig }
				value={ resolvedValue }
				onChange={ onChange }
				onBatchChange={ onBatchChange }
				handlerSlug={ handlerSlug }
			/>
		);
	}

	switch ( fieldConfig.type ) {
		case 'info':
			return (
				<div className={ wrapperClassName }>
					<Notice status="info" isDismissible={ false }>
						<strong>{ label }</strong>
						<p dangerouslySetInnerHTML={ { __html: help } } />
					</Notice>
				</div>
			);

		case 'textarea':
			return (
				<div className={ wrapperClassName }>
					<TextareaControl
						label={ label }
						value={ resolvedValue }
						onChange={ handleChange }
						rows={ fieldConfig.rows || 4 }
						help={ help }
						placeholder={ fieldConfig.placeholder }
					/>
				</div>
			);

		case 'json':
			return (
				<div className={ wrapperClassName }>
					<TextareaControl
						label={ label }
						value={ formatJsonValue( resolvedValue ) }
						onChange={ handleChange }
						rows={ fieldConfig.rows || 6 }
						help={ help }
						placeholder={ fieldConfig.placeholder || '{}' }
					/>
				</div>
			);

		case 'select':
			return (
				<div className={ wrapperClassName }>
					<SelectControl
						label={ label }
						value={ resolvedValue }
						options={ ( fieldConfig.options || [] ).map(
							( option ) =>
								option.value === 'separator'
									? { ...option, disabled: true }
									: option
						) }
						onChange={ handleChange }
						help={ help }
					/>
				</div>
			);

		case 'checkbox':
			return (
				<div className={ wrapperClassName }>
					<CheckboxControl
						label={ label }
						checked={ !! resolvedValue }
						onChange={ handleChange }
						help={ help }
					/>
				</div>
			);

		case 'url_list':
			return (
				<UrlListField
					fieldKey={ fieldKey }
					fieldConfig={ fieldConfig }
					value={ resolvedValue }
					onChange={ onChange }
				/>
			);

		case 'text':
		default:
			return (
				<div className={ wrapperClassName }>
					<TextControl
						label={ label }
						value={ resolvedValue }
						onChange={ handleChange }
						help={ help }
						placeholder={ fieldConfig.placeholder }
					/>
				</div>
			);
	}
}
