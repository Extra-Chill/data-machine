/**
 * Internal dependencies
 */
import HandlerModel from './HandlerModel';

const registry = new Map();
const modelCache = new Map();

export const registerHandlerModel = ( slug, HandlerClass ) => {
	registry.set( slug, HandlerClass );
};

export const createModel = ( slug, descriptor = {}, details = {} ) => {
	const cacheKey = `${ slug }:${ JSON.stringify( details || {} ) }`;

	if ( modelCache.has( cacheKey ) ) {
		return modelCache.get( cacheKey );
	}

	const HandlerClass =
		registry.get( slug ) ||
		registry.get( descriptor?.type ) ||
		HandlerModel;
	const model = new HandlerClass( slug, descriptor, details );
	modelCache.set( cacheKey, model );
	return model;
};

export const clearModelCache = () => {
	modelCache.clear();
};

export default createModel;
