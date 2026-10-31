/**
 * External dependencies
 */
import React, { createContext, useContext, useMemo } from 'react';
/**
 * Internal dependencies
 */
import { useHandlers } from '../queries/handlers';
import createModel, { registerHandlerModel } from '../models/HandlerFactory';
import FilesHandlerModel from '../models/handlers/FilesHandlerModel';

// Register known special handlers
registerHandlerModel( 'files', FilesHandlerModel );

const HandlerContext = createContext( null );

export const HandlerProvider = ( { children } ) => {
	const { data: handlers = {} } = useHandlers();

	const handlerModels = useMemo( () => {
		const map = new Map();
		Object.entries( handlers ).forEach( ( [ slug, descriptor ] ) => {
			// Note: we do not eagerly fetch handlerDetails here - we'll resolve it when needed
			const model = createModel( slug, descriptor, {} );
			map.set( slug, { model, descriptor } );
		} );
		return map;
	}, [ handlers ] );

	const getModel = ( slug, details = {} ) => {
		const descriptor = handlers[ slug ] || {};
		const model = createModel( slug, descriptor, details );
		return model;
	};

	return (
		<HandlerContext.Provider
			value={ { handlers, handlerModels, getModel } }
		>
			{ children }
		</HandlerContext.Provider>
	);
};

export const useHandlerContext = () => {
	return useContext( HandlerContext );
};

export default HandlerProvider;
