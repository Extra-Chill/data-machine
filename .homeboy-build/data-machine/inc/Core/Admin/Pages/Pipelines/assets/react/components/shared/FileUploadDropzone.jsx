/**
 * File Upload Dropzone Component
 *
 * Reusable drag-drop zone for file uploads with configurable file types and size limits.
 */

/**
 * WordPress dependencies
 */
import { useState, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * File Upload Dropzone Component
 *
 * @param {Object}        props                - Component props
 * @param {Function}      props.onFileSelected - File selection callback (file)
 * @param {Array<string>} props.allowedTypes   - Allowed file extensions (e.g., ['pdf', 'csv', 'txt', 'json'])
 * @param {number}        props.maxSizeMB      - Maximum file size in MB (default: 10)
 * @param {boolean}       props.disabled       - Disabled state
 * @param {string}        props.uploadText     - Custom upload text
 * @return {React.ReactElement} File upload dropzone
 */
export default function FileUploadDropzone( {
	onFileSelected,
	allowedTypes = [ 'pdf', 'csv', 'txt', 'json' ],
	maxSizeMB = 10,
	disabled = false,
	uploadText = null,
} ) {
	const [ isDragging, setIsDragging ] = useState( false );
	const [ error, setError ] = useState( null );
	const fileInputRef = useRef( null );

	/**
	 * Get accept attribute for file input
	 */
	const getAcceptAttribute = () => {
		return allowedTypes.map( ( ext ) => `.${ ext }` ).join( ',' );
	};

	/**
	 * Format file types for display
	 */
	const formatAllowedTypes = () => {
		return allowedTypes.map( ( ext ) => ext.toUpperCase() ).join( ', ' );
	};

	/**
	 * Validate and process file
	 * @param file
	 */
	const processFile = ( file ) => {
		// Get file extension
		const extension = file.name.split( '.' ).pop().toLowerCase();

		// Validate file type
		if ( ! allowedTypes.includes( extension ) ) {
			setError(
				__(
					`Please select a valid file. Allowed types: ${ formatAllowedTypes() }`,
					'data-machine'
				)
			);
			return;
		}

		// Validate file size
		const maxSizeBytes = maxSizeMB * 1024 * 1024;
		if ( file.size > maxSizeBytes ) {
			setError(
				__(
					`File size exceeds ${ maxSizeMB }MB limit.`,
					'data-machine'
				)
			);
			return;
		}

		// Pass file to callback
		if ( onFileSelected ) {
			onFileSelected( file );
			setError( null );
		}
	};

	/**
	 * Handle drag events
	 * @param e
	 */
	const handleDragEnter = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		if ( ! disabled ) {
			setIsDragging( true );
		}
	};

	const handleDragLeave = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging( false );
	};

	const handleDragOver = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
	};

	const handleDrop = ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging( false );

		if ( disabled ) {
			return;
		}

		const files = e.dataTransfer.files;
		if ( files.length > 0 ) {
			processFile( files[ 0 ] );
		}
	};

	/**
	 * Handle file input change
	 * @param e
	 */
	const handleFileInputChange = ( e ) => {
		const files = e.target.files;
		if ( files.length > 0 ) {
			processFile( files[ 0 ] );
		}
	};

	/**
	 * Trigger file input click
	 */
	const handleBrowseClick = () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	};

	const defaultUploadText = __( 'Drag and drop file here', 'data-machine' );

	return (
		<div>
			<div
				onDragEnter={ handleDragEnter }
				onDragLeave={ handleDragLeave }
				onDragOver={ handleDragOver }
				onDrop={ handleDrop }
				className={ `datamachine-dropzone ${
					isDragging ? 'datamachine-dropzone--dragging' : ''
				}` }
				onClick={ ! disabled ? handleBrowseClick : undefined }
			>
				<div className="datamachine-dropzone-icon"></div>

				<p className="datamachine-dropzone-title">
					{ uploadText || defaultUploadText }
				</p>

				<p className="datamachine-dropzone-helper">
					{ __(
						`Allowed: ${ formatAllowedTypes() } (max ${ maxSizeMB }MB)`,
						'data-machine'
					) }
				</p>

				<p className="datamachine-dropzone-or">
					{ __( 'or', 'data-machine' ) }
				</p>

				<Button
					variant="secondary"
					onClick={ handleBrowseClick }
					disabled={ disabled }
				>
					{ __( 'Browse Files', 'data-machine' ) }
				</Button>

				<input
					ref={ fileInputRef }
					type="file"
					accept={ getAcceptAttribute() }
					onChange={ handleFileInputChange }
					className="datamachine-hidden"
					disabled={ disabled }
				/>
			</div>

			{ error && (
				<div className="datamachine-dropzone-error">{ error }</div>
			) }
		</div>
	);
}
