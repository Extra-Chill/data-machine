/**
 * Redirect URL Display Component
 *
 * Displays the OAuth callback URL that users must configure in external app settings.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Redirect URL Display Component
 *
 * @param {Object} props     - Component props
 * @param {string} props.url - The redirect/callback URL to display
 * @return {React.ReactElement|null} Redirect URL display or null if no URL
 */
export default function RedirectUrlDisplay( { url } ) {
	if ( ! url ) {
		return null;
	}

	return (
		<div className="datamachine-redirect-url-display">
			<p className="datamachine-redirect-url-label">
				{ __(
					'Redirect URL (configure in your app settings):',
					'data-machine'
				) }
			</p>
			<code className="datamachine-redirect-url-value">{ url }</code>
		</div>
	);
}
