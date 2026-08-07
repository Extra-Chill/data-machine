/**
 * Files Handler Settings Component
 *
 * Specialized settings UI for the Files handler with upload and status display.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import FileUploadInterface from './FileUploadInterface';
import FileStatusTable from './FileStatusTable';
import AutoCleanupOption from './AutoCleanupOption';

/**
 * Files Handler Settings Component
 *
 * @param {Object}   props                  - Component props
 * @param {Object}   props.currentSettings  - Current handler settings
 * @param {Function} props.onSettingsChange - Settings change callback
 * @return {React.ReactElement} Files handler settings
 */
export default function FilesHandlerSettings( {
	currentSettings = {},
	onSettingsChange,
} ) {
	const [ files, setFiles ] = useState( currentSettings.files || [] );
	const [ autoCleanup, setAutoCleanup ] = useState(
		currentSettings.auto_cleanup !== false
	);

	/**
	 * Update parent when settings change
	 */
	useEffect( () => {
		if ( onSettingsChange ) {
			onSettingsChange( {
				files,
				auto_cleanup: autoCleanup,
			} );
		}
	}, [ files, autoCleanup, onSettingsChange ] );

	/**
	 * Handle file upload
	 * @param fileData
	 */
	const handleFileUploaded = ( fileData ) => {
		setFiles( ( prevFiles ) => [ ...prevFiles, fileData ] );
	};

	/**
	 * Handle auto cleanup toggle
	 * @param checked
	 */
	const handleAutoCleanupChange = ( checked ) => {
		setAutoCleanup( checked );
	};

	return (
		<div className="datamachine-files-handler-settings">
			<div className="datamachine-files-section">
				<h4 className="datamachine-files-section-title">
					{ __( 'Upload Files', 'data-machine' ) }
				</h4>
				<p className="datamachine-files-section-description">
					{ __(
						'Upload files to be processed by this flow. Each file will be processed individually.',
						'data-machine'
					) }
				</p>
			</div>

			<FileUploadInterface onFileUploaded={ handleFileUploaded } />

			<div className="datamachine-files-section datamachine-files-section-spacing--mt-24">
				<h4 className="datamachine-files-section-title">
					{ __( 'Uploaded Files', 'data-machine' ) }
				</h4>
				<p className="datamachine-files-section-description">
					{ __(
						'Files will be processed in order when the flow runs.',
						'data-machine'
					) }
				</p>
			</div>

			<FileStatusTable files={ files } />

			<AutoCleanupOption
				checked={ autoCleanup }
				onChange={ handleAutoCleanupChange }
			/>

			<div className="datamachine-modal-info-box datamachine-modal-info-box--highlight datamachine-modal-spacing--mt-16">
				<p>
					<strong>{ __( 'Note:', 'data-machine' ) }</strong>{ ' ' }
					{ __(
						'Files are stored specifically for this flow. Each uploaded file will create a separate processing job when the flow runs.',
						'data-machine'
					) }
				</p>
			</div>
		</div>
	);
}
