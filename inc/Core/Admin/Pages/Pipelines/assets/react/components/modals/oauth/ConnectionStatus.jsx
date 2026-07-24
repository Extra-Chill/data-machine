/**
 * Connection Status Component
 *
 * Status indicator showing OAuth connection state with styling.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Connection Status Component
 *
 * @param {Object}  props           - Component props
 * @param {boolean} props.connected - Connection state
 * @return {React.ReactElement} Connection status indicator
 */
export default function ConnectionStatus( { connected } ) {
	const statusConfig = connected
		? {
				label: __( 'Connected', 'data-machine' ),
				icon: '✓',
		  }
		: {
				label: __( 'Not Connected', 'data-machine' ),
				icon: '✗',
		  };

	const statusClass = connected
		? 'datamachine-connection-status datamachine-connection-status--connected'
		: 'datamachine-connection-status datamachine-connection-status--disconnected';

	const iconClass = connected
		? 'datamachine-connection-status__icon datamachine-connection-status__icon--connected'
		: 'datamachine-connection-status__icon datamachine-connection-status__icon--disconnected';

	const labelClass = connected
		? 'datamachine-connection-status__label--connected'
		: 'datamachine-connection-status__label--disconnected';

	return (
		<div className={ statusClass }>
			<span className={ iconClass }>{ statusConfig.icon }</span>
			<span className={ labelClass }>{ statusConfig.label }</span>
		</div>
	);
}
