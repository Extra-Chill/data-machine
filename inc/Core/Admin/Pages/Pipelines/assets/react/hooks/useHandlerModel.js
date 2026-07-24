/**
 * External dependencies
 */
import { useMemo } from 'react';
/**
 * Internal dependencies
 */
import { useHandlerContext } from '../context/HandlerProvider';
import { useHandlerDetails } from '../queries/handlers';

export default function useHandlerModel( handlerSlug ) {
	const { getModel } = useHandlerContext() || {};
	const { data: handlerDetails } = useHandlerDetails( handlerSlug );

	const model = useMemo( () => {
		if ( ! handlerSlug || ! getModel ) {
			return null;
		}

		// Use the details if available
		const detailData = handlerDetails || {};
		return getModel( handlerSlug, detailData );
	}, [ handlerSlug, getModel, handlerDetails ] );

	return model;
}
