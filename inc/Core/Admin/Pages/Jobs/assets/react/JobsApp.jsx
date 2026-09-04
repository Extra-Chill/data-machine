/**
 * JobsApp Component
 *
 * Root container for the Jobs admin page.
 */

/**
 * WordPress dependencies
 */
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import JobsHeader from './components/JobsHeader';
import JobsTable from './components/JobsTable';
/**
 * External dependencies
 */
import Pagination from '@shared/components/Pagination';
import JobsAdminModal from './components/modals/JobsAdminModal';
import { useJobs } from './queries/jobs';
import { useSettings } from '@shared/queries/settings';

const JobsApp = () => {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ page, setPage ] = useState( 1 );

	const { data: settingsData } = useSettings();
	const perPage = settingsData?.settings?.jobs_per_page ?? 50;

	const { data, isLoading, isError, error } = useJobs( { page, perPage } );

	const jobs = data?.jobs || [];
	const total = data?.total || 0;

	const openModal = useCallback( () => setIsModalOpen( true ), [] );
	const closeModal = useCallback( () => setIsModalOpen( false ), [] );

	const handlePageChange = useCallback( ( newPage ) => {
		setPage( newPage );
	}, [] );

	return (
		<div className="datamachine-jobs-app">
			<JobsHeader onOpenModal={ openModal } />

			<JobsTable
				jobs={ jobs }
				isLoading={ isLoading }
				isError={ isError }
				error={ error }
			/>

			{ ! isLoading && ! isError && (
				<Pagination
					page={ page }
					perPage={ perPage }
					total={ total }
					onPageChange={ handlePageChange }
					itemLabel={ __( 'jobs', 'data-machine' ) }
				/>
			) }

			{ isModalOpen && <JobsAdminModal onClose={ closeModal } /> }
		</div>
	);
};

export default JobsApp;
