/**
 * Internal dependencies
 */
import HandlerModel from '../HandlerModel';
import FilesHandlerSettings from '../../components/modals/handler-settings/files/FilesHandlerSettings';
/**
 * External dependencies
 */
import React from 'react';

export default class FilesHandlerModel extends HandlerModel {
	constructor( slug, descriptor = {}, details = {} ) {
		super( slug, descriptor, details );
	}

	sanitizeForAPI( data = {} ) {
		// Files handler may accept array of file ids or file meta; preserve as-is but ensure arrays
		const sanitized = { ...data };
		if ( Array.isArray( sanitized.files ) ) {
			sanitized.files = sanitized.files.map( ( f ) =>
				typeof f === 'string' ? f : f.id || f.file_id || f.name
			);
		}
		return super.sanitizeForAPI( sanitized );
	}

	renderSettingsEditor( props = {} ) {
		if ( ! FilesHandlerSettings ) {
			return null;
		}
		return <FilesHandlerSettings { ...props } />;
	}
}
