/**
 * SettingsApp Component
 *
 * Root container for the Settings admin page with tabbed navigation.
 */

/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';

/**
 * Internal dependencies
 */
import GeneralTab from './components/tabs/GeneralTab';
import ApiKeysTab from './components/tabs/ApiKeysTab';
import HandlerDefaultsTab from './components/tabs/HandlerDefaultsTab';

const STORAGE_KEY = 'datamachine_settings_active_tab';

const TABS = [
	{ name: 'general', title: 'General' },
	{ name: 'api-keys', title: 'API Keys' },
	{ name: 'handler-defaults', title: 'Handler Defaults' },
];

const getInitialTab = () => {
	const stored = localStorage.getItem( STORAGE_KEY );
	return stored && TABS.some( ( t ) => t.name === stored )
		? stored
		: 'general';
};

const SettingsApp = () => {
	const handleSelect = useCallback( ( tabName ) => {
		localStorage.setItem( STORAGE_KEY, tabName );
	}, [] );

	return (
		<div className="datamachine-settings-app">
			<TabPanel
				className="datamachine-tabs"
				tabs={ TABS }
				initialTabName={ getInitialTab() }
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
						default:
							return <GeneralTab />;
					}
				} }
			</TabPanel>
		</div>
	);
};

export default SettingsApp;
