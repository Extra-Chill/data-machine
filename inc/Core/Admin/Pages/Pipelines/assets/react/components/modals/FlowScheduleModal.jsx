/**
 * Flow Schedule Modal Component
 *
 * Modal for configuring flow scheduling interval.
 */

/**
 * WordPress dependencies
 */
import { Modal, Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useUpdateFlowSchedule } from '../../queries/flows';
import { useSchedulingIntervals } from '../../queries/config';
import { useFormState } from '../../hooks/useFormState';

/**
 * Flow Schedule Modal Component
 *
 * @param {Object}   props                 - Component props
 * @param {Function} props.onClose         - Close handler
 * @param {number}   props.flowId          - Flow ID
 * @param {string}   props.flowName        - Flow name
 * @param {string}   props.currentInterval - Current schedule interval
 * @param {Function} props.onSuccess       - Success callback
 * @return {React.ReactElement|null} Flow schedule modal
 */
export default function FlowScheduleModal( {
	onClose,
	flowId,
	flowName,
	currentInterval,
	onSuccess,
} ) {
	const updateScheduleMutation = useUpdateFlowSchedule();
	const {
		data: intervals = [],
		isLoading: intervalsLoading,
		error: intervalsError,
	} = useSchedulingIntervals();

	// Form state for interval selection
	const formState = useFormState( {
		initialData: { selectedInterval: currentInterval || 'manual' },
		onSubmit: async ( data ) => {
			try {
				await updateScheduleMutation.mutateAsync( {
					flowId,
					schedulingConfig: {
						interval: data.selectedInterval,
					},
				} );

				if ( onSuccess ) {
					onSuccess();
				}
				onClose();
			} catch ( error ) {
				throw new Error( error.message || 'Failed to update schedule' );
			}
		},
	} );

	/**
	 * Handle schedule save
	 */
	const handleSave = () => {
		formState.submit();
	};

	return (
		<Modal
			title={ __( 'Schedule Flow', 'data-machine' ) }
			onRequestClose={ onClose }
			className="datamachine-flow-schedule-modal"
		>
			<div className="datamachine-modal-content">
				{ ( formState.error || intervalsError ) && (
					<div className="datamachine-modal-error notice notice-error">
						<p>
							{ formState.error ||
								__(
									'Failed to load scheduling intervals. Please refresh the page and try again.',
									'data-machine'
								) }
						</p>
					</div>
				) }

				<div className="datamachine-modal-spacing--mb-20">
					<strong>{ __( 'Flow:', 'data-machine' ) }</strong>{ ' ' }
					{ flowName }
				</div>

				<SelectControl
					label={ __( 'Schedule Interval', 'data-machine' ) }
					value={ formState.data.selectedInterval }
					options={ intervals }
					onChange={ ( value ) =>
						formState.updateField( 'selectedInterval', value )
					}
					disabled={ intervalsLoading || intervals.length === 0 }
					help={ __(
						'Choose how often this flow should run automatically.',
						'data-machine'
					) }
				/>

				{ formState.data.selectedInterval === 'manual' && (
					<div className="datamachine-modal-info-box datamachine-modal-info-box--highlight">
						<p>
							<strong>
								{ __( 'Manual Mode:', 'data-machine' ) }
							</strong>{ ' ' }
							{ __(
								'Flow will only run when triggered manually via the "Run Now" button.',
								'data-machine'
							) }
						</p>
					</div>
				) }

				{ formState.data.selectedInterval !== 'manual' && (
					<div className="datamachine-modal-info-box datamachine-modal-info-box--note">
						<p>
							<strong>
								{ __(
									'Automatic Scheduling:',
									'data-machine'
								) }
							</strong>{ ' ' }
							{ __(
								'Flow will run automatically based on the selected interval. You can still trigger it manually anytime.',
								'data-machine'
							) }
						</p>
					</div>
				) }

				<div className="datamachine-modal-actions">
					<Button
						variant="secondary"
						onClick={ onClose }
						disabled={ formState.isSubmitting }
					>
						{ __( 'Cancel', 'data-machine' ) }
					</Button>

					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={
							formState.isSubmitting || intervals.length === 0
						}
						isBusy={ formState.isSubmitting }
					>
						{ formState.isSubmitting
							? __( 'Saving…', 'data-machine' )
							: __( 'Save Schedule', 'data-machine' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
