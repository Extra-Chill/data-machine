/**
 * Account Details Component
 *
 * Display connected account information.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Account Details Component
 *
 * @param {Object} props         - Component props
 * @param {Object} props.account - Account data object
 * @return {React.ReactElement|null} Account details display
 */
export default function AccountDetails( { account } ) {
	if ( ! account || Object.keys( account ).length === 0 ) {
		return null;
	}

	// Extract common account fields
	const username =
		account.username || account.name || account.screen_name || null;
	const email = account.email || null;
	const accountId = account.id || account.user_id || null;

	return (
		<div className="datamachine-account-details-box">
			<h4 className="datamachine-account-details-title">
				{ __( 'Connected Account', 'data-machine' ) }
			</h4>

			<div className="datamachine-account-details-content">
				{ username && (
					<div className="datamachine-account-details-field">
						<strong>{ __( 'Username:', 'data-machine' ) }</strong>{ ' ' }
						{ username }
					</div>
				) }

				{ email && (
					<div className="datamachine-account-details-field">
						<strong>{ __( 'Email:', 'data-machine' ) }</strong>{ ' ' }
						{ email }
					</div>
				) }

				{ accountId && (
					<div className="datamachine-account-details-field">
						<strong>{ __( 'Account ID:', 'data-machine' ) }</strong>{ ' ' }
						{ accountId }
					</div>
				) }

				{ /* Display any additional account data */ }
				{ Object.entries( account ).map( ( [ key, value ] ) => {
					// Skip fields we've already displayed
					if (
						[
							'username',
							'name',
							'screen_name',
							'email',
							'id',
							'user_id',
						].includes( key )
					) {
						return null;
					}

					// Skip complex objects and arrays
					if (
						typeof value === 'object' ||
						typeof value === 'function'
					) {
						return null;
					}

					return (
						<div
							key={ key }
							className="datamachine-account-details-field"
						>
							<strong>{ key }:</strong> { String( value ) }
						</div>
					);
				} ) }
			</div>
		</div>
	);
}
