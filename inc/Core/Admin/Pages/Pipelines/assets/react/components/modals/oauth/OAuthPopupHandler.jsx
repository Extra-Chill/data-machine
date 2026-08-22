/**
 * OAuth Popup Handler Component
 *
 * Manages OAuth popup window with message listener for callback communication.
 * Fetches OAuth authorization URL from REST API before opening popup to ensure
 * state parameter is properly created server-side.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * OAuth Popup Handler Component
 *
 * @param {Object}   props             - Component props
 * @param {string}   props.handlerSlug - Handler slug for fetching OAuth URL
 * @param {Function} props.onSuccess   - Success callback with account data
 * @param {Function} props.onError     - Error callback with error message
 * @param {boolean}  props.disabled    - Disabled state
 * @return {React.ReactElement} OAuth popup button
 */
export default function OAuthPopupHandler( {
	handlerSlug,
	onSuccess,
	onError,
	disabled = false,
} ) {
	const popupRef = useRef( null );
	const messageListenerRef = useRef( null );
	const [ isLoading, setIsLoading ] = useState( false );

	/**
	 * Clean up popup and listener on unmount
	 */
	useEffect( () => {
		return () => {
			// Clean up message listener
			if ( messageListenerRef.current ) {
				window.removeEventListener(
					'message',
					messageListenerRef.current
				);
			}

			// Close popup if still open
			if ( popupRef.current && ! popupRef.current.closed ) {
				popupRef.current.close();
			}
		};
	}, [] );

	/**
	 * Handle OAuth popup
	 */
	const handleOAuthClick = async () => {
		// Close existing popup if open
		if ( popupRef.current && ! popupRef.current.closed ) {
			popupRef.current.close();
		}

		setIsLoading( true );

		try {
			// Fetch OAuth URL from REST API - this creates the state server-side
			const response = await wp.apiFetch( {
				path: `/datamachine/v1/auth/${ handlerSlug }/status`,
			} );

			if ( ! response?.success || ! response?.data?.oauth_url ) {
				throw new Error(
					response?.message ||
						__( 'Failed to get authorization URL.', 'data-machine' )
				);
			}

			const oauthUrl = response.data.oauth_url;

			// Open OAuth popup
			const width = 600;
			const height = 700;
			const left = window.screen.width / 2 - width / 2;
			const top = window.screen.height / 2 - height / 2;

			popupRef.current = window.open(
				oauthUrl,
				'oauth-window',
				`width=${ width },height=${ height },left=${ left },top=${ top },toolbar=no,menubar=no,scrollbars=yes,resizable=yes`
			);
		} catch ( error ) {
			if ( onError ) {
				onError(
					error?.message ||
						__( 'Failed to initiate OAuth flow.', 'data-machine' )
				);
			}
			setIsLoading( false );
			return;
		}

		setIsLoading( false );

		// Set up message listener
		messageListenerRef.current = ( event ) => {
			// Verify message origin for security
			const allowedOrigins = [ window.location.origin ];
			if ( ! allowedOrigins.includes( event.origin ) ) {
				return;
			}

			// Check if message is from OAuth callback
			if ( event.data && event.data.type === 'oauth_callback' ) {
				// Close popup
				if ( popupRef.current && ! popupRef.current.closed ) {
					popupRef.current.close();
				}

				// Handle success or error
				if ( event.data.success ) {
					if ( onSuccess ) {
						onSuccess( event.data.account );
					}
				} else if ( onError ) {
					onError(
						event.data.error ||
							__( 'OAuth authentication failed', 'data-machine' )
					);
				}

				// Clean up listener
				window.removeEventListener(
					'message',
					messageListenerRef.current
				);
			}
		};

		window.addEventListener( 'message', messageListenerRef.current );

		// Check if popup was blocked
		if ( ! popupRef.current || popupRef.current.closed ) {
			if ( onError ) {
				onError(
					__(
						'Popup was blocked. Please allow popups for this site.',
						'data-machine'
					)
				);
			}
		}
	};

	return (
		<Button
			variant="primary"
			onClick={ handleOAuthClick }
			disabled={ disabled || isLoading }
			isBusy={ isLoading }
		>
			{ isLoading
				? __( 'Connecting…', 'data-machine' )
				: __( 'Connect Account', 'data-machine' ) }
		</Button>
	);
}
