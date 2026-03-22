/**
 * SettingsApp Component
 *
 * Root container for the Settings admin page with tabbed navigation.
 * Handles OAuth callback feedback by reading query params and showing notices.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useState, useEffect } from '@wordpress/element';
import { TabPanel, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import GeneralTab from './components/tabs/GeneralTab';
import ApiKeysTab from './components/tabs/ApiKeysTab';
import HandlerDefaultsTab from './components/tabs/HandlerDefaultsTab';
import AuthProvidersTab from './components/tabs/AuthProvidersTab';

const STORAGE_KEY = 'datamachine_settings_active_tab';

const TABS = [
	{ name: 'general', title: 'General' },
	{ name: 'api-keys', title: 'API Keys' },
	{ name: 'handler-defaults', title: 'Handler Defaults' },
	{ name: 'auth-providers', title: 'Auth Providers' },
];

const getInitialTab = () => {
	const stored = localStorage.getItem( STORAGE_KEY );
	return stored && TABS.some( ( t ) => t.name === stored )
		? stored
		: 'general';
};

/**
 * Get OAuth callback feedback from URL query params.
 *
 * @returns {Object|null} Feedback object or null if no OAuth params present.
 */
const getOAuthFeedbackFromUrl = () => {
	const urlParams = new URLSearchParams( window.location.search );
	const authSuccess = urlParams.get( 'auth_success' );
	const authError = urlParams.get( 'auth_error' );
	const provider = urlParams.get( 'provider' );

	if ( ! authSuccess && ! authError ) {
		return null;
	}

	if ( authSuccess ) {
		return {
			type: 'success',
			message: provider
				? /* translators: %s: provider name (e.g., Pinterest) */
				  sprintf( __( 'Successfully connected to %s.', 'data-machine' ), provider )
				: __( 'Successfully connected.', 'data-machine' ),
		};
	}

	// Map error codes to user-friendly messages.
	const errorMessages = {
		denied: __( 'Authorization was denied or cancelled.', 'data-machine' ),
		invalid_state: __( 'Invalid OAuth state. Please try again.', 'data-machine' ),
		token_exchange_failed: __( 'Failed to exchange authorization code for token.', 'data-machine' ),
		token_transform_failed: __( 'Failed to process authentication token.', 'data-machine' ),
		account_fetch_failed: __( 'Failed to retrieve account details.', 'data-machine' ),
		storage_failed: __( 'Failed to save authentication data.', 'data-machine' ),
		missing_config: __( 'OAuth credentials not configured.', 'data-machine' ),
	};

	return {
		type: 'error',
		message: errorMessages[ authError ] ||
			/* translators: %s: error code */
			sprintf( __( 'Authentication failed: %s', 'data-machine' ), authError ),
	};
};

/**
 * Clean up OAuth query params from URL without reloading.
 */
const cleanOAuthParamsFromUrl = () => {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'auth_success' );
	url.searchParams.delete( 'auth_error' );
	url.searchParams.delete( 'provider' );
	window.history.replaceState( {}, '', url.toString() );
};

const SettingsApp = () => {
	const [ activeTab, setActiveTab ] = useState( getInitialTab() );
	const [ oauthNotice, setOauthNotice ] = useState( null );

	// Handle OAuth callback feedback on mount.
	useEffect( () => {
		const feedback = getOAuthFeedbackFromUrl();
		if ( feedback ) {
			setOauthNotice( feedback );
			// Auto-switch to Auth Providers tab.
			setActiveTab( 'auth-providers' );
			localStorage.setItem( STORAGE_KEY, 'auth-providers' );
			// Clean up URL.
			cleanOAuthParamsFromUrl();
		}
	}, [] );

	const handleSelect = useCallback( ( tabName ) => {
		setActiveTab( tabName );
		localStorage.setItem( STORAGE_KEY, tabName );
	}, [] );

	return (
		<div className="datamachine-settings-app">
			{ oauthNotice && (
				<Notice
					status={ oauthNotice.type }
					isDismissible
					onRemove={ () => setOauthNotice( null ) }
				>
					{ oauthNotice.message }
				</Notice>
			) }
			<TabPanel
				className="datamachine-tabs"
				tabs={ TABS }
				initialTabName={ activeTab }
				onSelect={ handleSelect }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'general':
							return <GeneralTab />;
					case 'api-keys':
						return <ApiKeysTab />;
					case 'handler-defaults':
						return <HandlerDefaultsTab />;
					case 'auth-providers':
						return <AuthProvidersTab />;
					default:
						return <GeneralTab />;
					}
				} }
			</TabPanel>
		</div>
	);
};

export default SettingsApp;
