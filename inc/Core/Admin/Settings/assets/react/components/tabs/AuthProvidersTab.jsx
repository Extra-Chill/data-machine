/**
 * AuthProvidersTab Component
 *
 * Card-based display of all registered auth providers with connection
 * status, config fields, and connect/disconnect actions. Provides a
 * single view to manage all authentication outside the pipeline page.
 *
 * @since 0.44.1
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	useAuthProviders,
	useSaveAuthConfig,
	useDisconnectAuth,
} from '../../queries/authProviders';

const AUTH_TYPE_LABELS = {
	oauth2: 'OAuth 2.0',
	oauth1: 'OAuth 1.0a',
	simple: 'Credentials',
};

/**
 * Inline connection status badge.
 */
const StatusBadge = ( { connected, configured } ) => {
	if ( connected ) {
		return (
			<span className="datamachine-auth-badge datamachine-auth-badge--connected">
				{ '\u2713' } { __( 'Connected', 'data-machine' ) }
			</span>
		);
	}
	if ( configured ) {
		return (
			<span className="datamachine-auth-badge datamachine-auth-badge--configured">
				{ __( 'Configured', 'data-machine' ) }
			</span>
		);
	}
	return (
		<span className="datamachine-auth-badge datamachine-auth-badge--disconnected">
			{ __( 'Not connected', 'data-machine' ) }
		</span>
	);
};

/**
 * Display connected account details inline.
 */
const InlineAccountDetails = ( { account } ) => {
	if ( ! account || Object.keys( account ).length === 0 ) {
		return null;
	}

	const username =
		account.username || account.name || account.screen_name || null;
	const email = account.email || null;

	const details = [];
	if ( username ) {
		details.push( username );
	}
	if ( email && email !== username ) {
		details.push( email );
	}

	// Include any other simple string/number fields not already shown.
	const skipKeys = [
		'username',
		'name',
		'screen_name',
		'email',
		'id',
		'user_id',
	];
	Object.entries( account ).forEach( ( [ key, value ] ) => {
		if (
			skipKeys.includes( key ) ||
			typeof value === 'object' ||
			typeof value === 'function'
		) {
			return;
		}
		details.push( `${ key }: ${ value }` );
	} );

	if ( details.length === 0 ) {
		return null;
	}

	return (
		<div className="datamachine-auth-account">
			{ details.join( ' \u00b7 ' ) }
		</div>
	);
};

/**
 * Config form for a single provider (API keys, credentials).
 */
const ConfigForm = ( { provider, onSave, isSaving } ) => {
	const fields = provider.auth_fields || {};
	const fieldEntries = Object.entries( fields );

	const [ values, setValues ] = useState( () => {
		const initial = {};
		fieldEntries.forEach( ( [ key, field ] ) => {
			initial[ key ] = field.default || '';
		} );
		return initial;
	} );

	if ( fieldEntries.length === 0 ) {
		return (
			<p className="description">
				{ __(
					'No configuration fields available for this provider.',
					'data-machine'
				) }
			</p>
		);
	}

	const handleChange = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const handleSubmit = ( e ) => {
		e.preventDefault();
		onSave( provider.provider_key, values );
	};

	return (
		<form onSubmit={ handleSubmit } className="datamachine-auth-config-form">
			{ fieldEntries.map( ( [ key, field ] ) => (
				<div key={ key } className="datamachine-auth-config-field">
					<label htmlFor={ `auth-${ provider.provider_key }-${ key }` }>
						{ field.label }
						{ field.required && (
							<span className="required"> *</span>
						) }
					</label>
					{ field.type === 'select' ? (
						<select
							id={ `auth-${ provider.provider_key }-${ key }` }
							value={ values[ key ] || '' }
							onChange={ ( e ) =>
								handleChange( key, e.target.value )
							}
						>
							{ Object.entries( field.options || {} ).map(
								( [ optVal, optLabel ] ) => (
									<option key={ optVal } value={ optVal }>
										{ optLabel }
									</option>
								)
							) }
						</select>
					) : (
						<input
							id={ `auth-${ provider.provider_key }-${ key }` }
							type={ field.type === 'password' ? 'password' : 'text' }
							value={ values[ key ] || '' }
							onChange={ ( e ) =>
								handleChange( key, e.target.value )
							}
							placeholder={ field.placeholder || '' }
							className="regular-text"
						/>
					) }
					{ field.description && (
						<p className="description">{ field.description }</p>
					) }
				</div>
			) ) }

			<div className="datamachine-auth-config-actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ isSaving }
					disabled={ isSaving }
				>
					{ isSaving
						? __( 'Saving\u2026', 'data-machine' )
						: __( 'Save Configuration', 'data-machine' ) }
				</Button>
			</div>
		</form>
	);
};

/**
 * Callback URL display for OAuth providers.
 */
const CallbackUrlDisplay = ( { url } ) => {
	if ( ! url ) {
		return null;
	}

	const [ copied, setCopied ] = useState( false );

	const handleCopy = () => {
		navigator.clipboard.writeText( url ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	return (
		<div className="datamachine-auth-callback">
			<span className="datamachine-auth-callback-label">
				{ __( 'Redirect URL:', 'data-machine' ) }
			</span>
			<code className="datamachine-auth-callback-url">{ url }</code>
			<Button
				variant="link"
				className="datamachine-auth-callback-copy"
				onClick={ handleCopy }
			>
				{ copied
					? __( 'Copied!', 'data-machine' )
					: __( 'Copy', 'data-machine' ) }
			</Button>
		</div>
	);
};

/**
 * Single provider card.
 */
const ProviderCard = ( {
	provider,
	onSave,
	onDisconnect,
	isSaving,
	isDisconnecting,
} ) => {
	const [ showConfig, setShowConfig ] = useState( false );
	const [ feedback, setFeedback ] = useState( null );

	const handleSave = useCallback(
		async ( providerKey, config ) => {
			try {
				await onSave( providerKey, config );
				setFeedback( {
					type: 'success',
					message: __( 'Configuration saved.', 'data-machine' ),
				} );
				setShowConfig( false );
			} catch ( err ) {
				setFeedback( {
					type: 'error',
					message:
						err.message ||
						__( 'Failed to save configuration.', 'data-machine' ),
				} );
			}
		},
		[ onSave ]
	);

	const handleDisconnect = useCallback( async () => {
		if (
			! confirm(
				__(
					'Are you sure you want to disconnect this account? This will remove the access token but keep your API configuration.',
					'data-machine'
				)
			)
		) {
			return;
		}
		try {
			await onDisconnect( provider.provider_key );
			setFeedback( {
				type: 'success',
				message: __( 'Account disconnected.', 'data-machine' ),
			} );
		} catch ( err ) {
			setFeedback( {
				type: 'error',
				message:
					err.message ||
					__( 'Failed to disconnect.', 'data-machine' ),
			} );
		}
	}, [ onDisconnect, provider.provider_key ] );

	return (
		<div
			className={ `datamachine-auth-card ${
				provider.is_authenticated
					? 'datamachine-auth-card--connected'
					: ''
			}` }
		>
			<div className="datamachine-auth-card-header">
				<div className="datamachine-auth-card-title">
					<strong>{ provider.label }</strong>
					<span className="datamachine-auth-type-badge">
						{ AUTH_TYPE_LABELS[ provider.auth_type ] ||
							provider.auth_type }
					</span>
				</div>
				<StatusBadge
					connected={ provider.is_authenticated }
					configured={ provider.is_configured }
				/>
			</div>

			{ provider.is_authenticated && (
				<InlineAccountDetails account={ provider.account_details } />
			) }

			{ feedback && (
				<Notice
					status={ feedback.type }
					isDismissible
					onRemove={ () => setFeedback( null ) }
					className="datamachine-auth-card-notice"
				>
					{ feedback.message }
				</Notice>
			) }

			<div className="datamachine-auth-card-actions">
				{ provider.is_authenticated && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleDisconnect }
						isBusy={ isDisconnecting }
						disabled={ isDisconnecting }
						className="button-small"
					>
						{ isDisconnecting
							? __( 'Disconnecting\u2026', 'data-machine' )
							: __( 'Disconnect', 'data-machine' ) }
					</Button>
				) }

				{ Object.keys( provider.auth_fields || {} ).length > 0 && (
					<Button
						variant="secondary"
						onClick={ () => setShowConfig( ! showConfig ) }
						className="button-small"
					>
						{ showConfig
							? __( 'Hide Config', 'data-machine' )
							: provider.is_authenticated
							? __( 'Edit Config', 'data-machine' )
							: __( 'Configure', 'data-machine' ) }
					</Button>
				) }
			</div>

			{ showConfig && (
				<div className="datamachine-auth-card-config">
					<CallbackUrlDisplay url={ provider.callback_url } />
					<ConfigForm
						provider={ provider }
						onSave={ handleSave }
						isSaving={ isSaving }
					/>
				</div>
			) }
		</div>
	);
};

const AuthProvidersTab = () => {
	const { data: providers, isLoading, error } = useAuthProviders();
	const saveMutation = useSaveAuthConfig();
	const disconnectMutation = useDisconnectAuth();
	const [ savingProvider, setSavingProvider ] = useState( null );
	const [ disconnectingProvider, setDisconnectingProvider ] = useState( null );

	const handleSave = async ( providerKey, config ) => {
		setSavingProvider( providerKey );
		try {
			await saveMutation.mutateAsync( { providerKey, config } );
		} finally {
			setSavingProvider( null );
		}
	};

	const handleDisconnect = async ( providerKey ) => {
		setDisconnectingProvider( providerKey );
		try {
			await disconnectMutation.mutateAsync( providerKey );
		} finally {
			setDisconnectingProvider( null );
		}
	};

	if ( isLoading ) {
		return (
			<div className="datamachine-auth-providers-loading">
				<span className="spinner is-active"></span>
				<span>{ __( 'Loading auth providers\u2026', 'data-machine' ) }</span>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>
					{ __(
						'Error loading auth providers:',
						'data-machine'
					) }{ ' ' }
					{ error.message }
				</p>
			</div>
		);
	}

	if ( ! providers || providers.length === 0 ) {
		return (
			<div className="datamachine-auth-providers-empty">
				<p className="description">
					{ __(
						'No auth providers registered. Providers are registered by handlers that require authentication (OAuth, API keys, etc.).',
						'data-machine'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="datamachine-auth-providers-tab">
			<p className="description">
				{ __(
					'Manage authentication for all registered providers. Configure API keys, connect OAuth accounts, and check connection status.',
					'data-machine'
				) }
			</p>

			<div className="datamachine-auth-cards">
				{ providers.map( ( provider ) => (
					<ProviderCard
						key={ provider.provider_key }
						provider={ provider }
						onSave={ handleSave }
						onDisconnect={ handleDisconnect }
						isSaving={ savingProvider === provider.provider_key }
						isDisconnecting={
							disconnectingProvider === provider.provider_key
						}
					/>
				) ) }
			</div>
		</div>
	);
};

export default AuthProvidersTab;
