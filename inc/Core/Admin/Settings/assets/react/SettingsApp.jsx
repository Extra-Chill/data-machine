/**
 * SettingsApp Component
 *
 * Root container for the Settings admin page with tabbed navigation.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
/**
 * Internal dependencies
 */
import GeneralTab from './components/tabs/GeneralTab';
import ApiKeysTab from './components/tabs/ApiKeysTab';
import HandlerDefaultsTab from './components/tabs/HandlerDefaultsTab';

const TABS = [
	{ id: 'general', label: 'General' },
	{ id: 'api-keys', label: 'API Keys' },
	{ id: 'handler-defaults', label: 'Handler Defaults' },
];

const STORAGE_KEY = 'datamachine_settings_active_tab';

const SettingsApp = () => {
	const [ activeTab, setActiveTab ] = useState( () => {
		// Restore from localStorage or default to first tab
		const stored = localStorage.getItem( STORAGE_KEY );
		return stored && TABS.some( ( t ) => t.id === stored )
			? stored
			: 'general';
	} );

	// Persist active tab to localStorage
	useEffect( () => {
		localStorage.setItem( STORAGE_KEY, activeTab );
	}, [ activeTab ] );

	const renderTabContent = () => {
		switch ( activeTab ) {
			case 'general':
				return <GeneralTab />;
			case 'api-keys':
				return <ApiKeysTab />;
			case 'handler-defaults':
				return <HandlerDefaultsTab />;
			default:
				return <GeneralTab />;
		}
	};

	return (
		<div className="datamachine-settings-app">
			<h2 className="nav-tab-wrapper datamachine-nav-tab-wrapper">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.id }
						type="button"
						className={ `nav-tab ${
							activeTab === tab.id ? 'nav-tab-active' : ''
						}` }
						onClick={ () => setActiveTab( tab.id ) }
					>
						{ tab.label }
					</button>
				) ) }
			</h2>

			<div className="datamachine-settings-content">
				{ renderTabContent() }
			</div>
		</div>
	);
};

export default SettingsApp;
