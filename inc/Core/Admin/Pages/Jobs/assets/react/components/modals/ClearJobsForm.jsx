/**
 * ClearJobsForm Component
 *
 * Form for clearing jobs (failed or all) with optional processed items cleanup.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import {
	Button,
	RadioControl,
	CheckboxControl,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
/**
 * Internal dependencies
 */
import { useClearJobs } from '../../queries/jobs';
import ConfirmationModal from '@shared/components/ConfirmationModal';

const ClearJobsForm = () => {
	const [ clearType, setClearType ] = useState( '' );
	const [ cleanupProcessed, setCleanupProcessed ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ pendingClear, setPendingClear ] = useState( null );

	const clearMutation = useClearJobs();

	const handleSubmit = useCallback(
		( e ) => {
			e.preventDefault();
			setNotice( null );

			if ( ! clearType ) {
				setNotice( {
					type: 'warning',
					message: __(
						'Please select which jobs to clear',
						'data-machine'
					),
				} );
				return;
			}

			let confirmMessage = '';
			if ( clearType === 'all' ) {
				confirmMessage = __(
					'Are you sure you want to delete ALL jobs? This will remove all execution history and cannot be undone.',
					'data-machine'
				);
				if ( cleanupProcessed ) {
					confirmMessage +=
						'\n\n' +
						__(
							'This will also clear ALL processed items, allowing complete reprocessing of all content.',
							'data-machine'
						);
				}
			} else {
				confirmMessage = __(
					'Are you sure you want to delete all FAILED jobs?',
					'data-machine'
				);
				if ( cleanupProcessed ) {
					confirmMessage +=
						'\n\n' +
						__(
							'This will also clear processed items for the failed jobs, allowing them to be reprocessed.',
							'data-machine'
						);
				}
			}

			setPendingClear( {
				message: confirmMessage,
				payload: { type: clearType, cleanupProcessed },
			} );
		},
		[ clearType, cleanupProcessed ]
	);

	const handleConfirmClear = useCallback( () => {
		if ( ! pendingClear ) {
			return;
		}
		const { payload } = pendingClear;
		setPendingClear( null );
		clearMutation.mutate(
				payload,
				{
					onSuccess: ( response ) => {
						setNotice( {
							type: 'success',
							message: response.message,
						} );
						setClearType( '' );
						setCleanupProcessed( false );
					},
					onError: ( error ) => {
						setNotice( {
							type: 'error',
							message:
								error.message ||
								__( 'An error occurred', 'data-machine' ),
						} );
					},
				}
			);
	}, [ pendingClear, clearMutation ]
	);

	const radioOptions = [
		{
			label: __( 'Failed jobs only (recommended)', 'data-machine' ),
			value: 'failed',
		},
		{
			label: __(
				'All jobs (removes all execution history)',
				'data-machine'
			),
			value: 'all',
		},
	];

	return (
		<div className="datamachine-admin-section">
			<h3>{ __( 'Clear Jobs', 'data-machine' ) }</h3>
			<p className="description">
				{ __(
					'Delete job records from the database. Failed jobs can be cleared safely, while clearing all jobs removes historical execution data.',
					'data-machine'
				) }
			</p>

			<form onSubmit={ handleSubmit } className="datamachine-admin-form">
				<div className="datamachine-form-field">
					<RadioControl
						label={ __( 'Jobs to Clear', 'data-machine' ) }
						selected={ clearType }
						options={ radioOptions }
						onChange={ setClearType }
					/>
				</div>

				<div className="datamachine-form-field">
					<CheckboxControl
						label={ __(
							'Also clear processed items for deleted jobs',
							'data-machine'
						) }
						checked={ cleanupProcessed }
						onChange={ setCleanupProcessed }
					/>
					<p className="description">
						{ __(
							'When enabled, this will also remove processed item records for all deleted jobs, allowing full reprocessing.',
							'data-machine'
						) }
					</p>
				</div>

				<div className="datamachine-form-actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ clearMutation.isPending }
						disabled={ clearMutation.isPending }
					>
						{ __( 'Clear Jobs', 'data-machine' ) }
					</Button>
				</div>

				{ notice && (
					<Notice
						status={ notice.type }
						isDismissible={ false }
						className="datamachine-form-notice"
					>
						{ notice.message }
					</Notice>
				) }
			</form>
			{ pendingClear && (
				<ConfirmationModal
					onConfirm={ handleConfirmClear }
					onCancel={ () => setPendingClear( null ) }
				>
					{ pendingClear.message }
				</ConfirmationModal>
			) }
		</div>
	);
};

export default ClearJobsForm;
