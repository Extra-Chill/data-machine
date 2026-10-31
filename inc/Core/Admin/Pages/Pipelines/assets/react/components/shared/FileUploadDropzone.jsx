/**
 * File Upload Dropzone Component
 *
 * Reusable drag-drop zone for file uploads with configurable file types and size limits.
 */

/**
 * WordPress dependencies
 */
import { useState, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

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
	 * @param {File} file Uploaded file.
	 */
	const processFile = ( file ) => {
		// Get file extension
		const extension = file.name.split( '.' ).pop().toLowerCase();

		// Validate file type
		if ( ! allowedTypes.includes( extension ) ) {
			setError(
				sprintf(
					/* translators: %s: Comma-separated allowed file extensions. */
					__( 'Please select a valid file. Allowed types: %s', 'data-machine' ),
					formatAllowedTypes()
				)
			);
			return;
		}

		// Validate file size
		const maxSizeBytes = maxSizeMB * 1024 * 1024;
		if ( file.size > maxSizeBytes ) {
			setError(
				sprintf(
					/* translators: %d: Maximum upload size in megabytes. */
					__( 'File size exceeds %dMB limit.', 'data-machine' ),
					maxSizeMB
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
	 * @param {Event} e Drag event.
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
	 * @param {Event} e Input event.
	 */
	const handleFileInputChange = ( e ) => {
		const files = e.target.files;
		if ( files.length > 0 ) {
			processFile( files[ 0 ] );
		}
	};

	/** Trigger the hidden file input from the sole activation control. */
	const handleBrowseClick = () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	};

	const defaultUploadText = __( 'Drag and drop file here', 'data-machine' );

	return (
		<div>
			<button
				type="button"
				onDragEnter={ handleDragEnter }
				onDragLeave={ handleDragLeave }
				onDragOver={ handleDragOver }
				onDrop={ handleDrop }
				onClick={ handleBrowseClick }
				className={ `datamachine-dropzone ${
					isDragging ? 'datamachine-dropzone--dragging' : ''
				} ${ disabled ? 'datamachine-dropzone--disabled' : '' }` }
				disabled={ disabled }
			>
				<span className="datamachine-dropzone-icon"></span>

				<span className="datamachine-dropzone-title">
					{ uploadText || defaultUploadText }
				</span>

				<span className="datamachine-dropzone-helper">
					{
						sprintf(
							/* translators: 1: Allowed file extensions, 2: Maximum size in megabytes. */
							__( 'Allowed: %1$s (max %2$dMB)', 'data-machine' ),
							formatAllowedTypes(),
							maxSizeMB
						)
					}
				</span>

				<span className="datamachine-dropzone-or">
					{ __( 'or', 'data-machine' ) }
				</span>

				<span className="datamachine-dropzone-browse">
					{ __( 'Browse Files', 'data-machine' ) }
				</span>
			</button>

			<input
				ref={ fileInputRef }
				type="file"
				accept={ getAcceptAttribute() }
				onChange={ handleFileInputChange }
				className="datamachine-hidden"
				disabled={ disabled }
			/>

			{ error && (
				<div className="datamachine-dropzone-error">{ error }</div>
			) }
		</div>
	);
}
