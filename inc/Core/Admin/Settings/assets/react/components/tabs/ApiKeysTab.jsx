/**
 * ApiKeysTab Component
 *
 * AI provider API key configuration.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
/**
 * Internal dependencies
 */
import { useSettings, useUpdateSettings } from '@shared/queries/settings';
/**
 * External dependencies
 */
import { useProviders } from '@shared/queries/providers';

const ApiKeysTab = () => {
	const { data, isLoading, error } = useSettings();
	const { data: providersData, isLoading: providersLoading } = useProviders();
	const updateMutation = useUpdateSettings();

	const [ apiKeys, setApiKeys ] = useState( {} );
	const [ hasChanges, setHasChanges ] = useState( false );
	const [ saveStatus, setSaveStatus ] = useState( null );

	useEffect( () => {
		if ( data?.settings?.ai_provider_keys ) {
			setApiKeys( data.settings.ai_provider_keys );
			setHasChanges( false );
		}
	}, [ data ] );

	const handleKeyChange = ( providerKey, value ) => {
		setApiKeys( ( prev ) => ( {
			...prev,
			[ providerKey ]: value,
		} ) );
		setHasChanges( true );
	};

	const handleSave = async () => {
		setSaveStatus( 'saving' );
		try {
			await updateMutation.mutateAsync( { ai_provider_keys: apiKeys } );
			setSaveStatus( 'saved' );
			setHasChanges( false );
			setTimeout( () => setSaveStatus( null ), 2000 );
		} catch ( err ) {
			setSaveStatus( 'error' );
			setTimeout( () => setSaveStatus( null ), 3000 );
		}
	};

	if ( isLoading || providersLoading ) {
		return (
			<div className="datamachine-api-keys-tab-loading">
				<span className="spinner is-active"></span>
				<span>Loading API key settings...</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>Error loading settings: { error.message }</p>
			</div>
		);
	}

	const providers = providersData?.providers || {};
	const providerEntries = Object.entries( providers );

	if ( providerEntries.length === 0 ) {
		return (
			<div className="datamachine-api-keys-tab">
				<div className="notice notice-info">
					<p>No AI providers are currently available.</p>
				</div>
			</div>
		);
	}

	return (
		<div className="datamachine-api-keys-tab">
			<p className="description">
				Configure API keys for AI providers. Keys are stored securely
				and used for AI operations.
			</p>

			<table className="form-table">
				<tbody>
					{ providerEntries.map( ( [ key, provider ] ) => {
						const providerName =
							provider.label ||
							key.charAt( 0 ).toUpperCase() + key.slice( 1 );
						const currentValue = apiKeys[ key ] || '';

						return (
							<tr key={ key }>
								<th scope="row">
									<label
										htmlFor={ `ai_provider_keys_${ key }` }
									>
										{ providerName }
									</label>
								</th>
								<td>
									<input
										type="text"
										id={ `ai_provider_keys_${ key }` }
										value={ currentValue }
										onChange={ ( e ) =>
											handleKeyChange(
												key,
												e.target.value
											)
										}
										className="regular-text"
										placeholder="Enter API key..."
										autoComplete="off"
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			<div className="datamachine-settings-submit">
				<button
					type="button"
					className="button button-primary"
					onClick={ handleSave }
					disabled={ ! hasChanges || saveStatus === 'saving' }
				>
					{ saveStatus === 'saving' ? 'Saving...' : 'Save Changes' }
				</button>

				{ hasChanges && saveStatus !== 'saving' && (
					<span className="datamachine-unsaved-indicator">
						Unsaved changes
					</span>
				) }

				{ saveStatus === 'saved' && (
					<span className="datamachine-saved-indicator">
						Settings saved!
					</span>
				) }

				{ saveStatus === 'error' && (
					<span className="datamachine-error-indicator">
						Error saving settings
					</span>
				) }
			</div>
		</div>
	);
};

export default ApiKeysTab;
