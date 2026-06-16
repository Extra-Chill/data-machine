/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef } from '@wordpress/element';

/**
 * Auto-save delay in milliseconds.
 */
export const AUTO_SAVE_DELAY = 500;

/**
 * Return a debounced callback and clear its pending save on unmount.
 *
 * @param {Function} callback Callback to run after the debounce delay.
 * @param {number}   delay    Delay in milliseconds.
 * @return {Function} Debounced callback.
 */
export default function useDebouncedAutosave(
	callback,
	delay = AUTO_SAVE_DELAY
) {
	const saveTimeout = useRef( null );

	useEffect( () => {
		return () => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}
		};
	}, [] );

	return useCallback(
		( ...args ) => {
			if ( saveTimeout.current ) {
				clearTimeout( saveTimeout.current );
			}

			saveTimeout.current = setTimeout( () => {
				callback( ...args );
			}, delay );
		},
		[ callback, delay ]
	);
}
