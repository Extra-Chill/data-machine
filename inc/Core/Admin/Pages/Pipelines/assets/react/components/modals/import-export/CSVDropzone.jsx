/**
 * CSV Dropzone Component
 *
 * Drag-drop zone for CSV file uploads with browse button fallback.
 */

/**
 * WordPress dependencies
 */
import { useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import { useDragDrop } from '../../../hooks/useFormState';

/**
 * CSV Dropzone Component
 *
 * @param {Object}   props                - Component props
 * @param {Function} props.onFileSelected - File selection callback (content, fileName)
 * @param {string}   props.fileName       - Currently selected file name
 * @param {boolean}  props.disabled       - Disabled state
 * @return {React.ReactElement} CSV dropzone
 */
export default function CSVDropzone( {
	onFileSelected,
	fileName,
	disabled = false,
} ) {
	const fileInputRef = useRef( null );

	const dragDrop = useDragDrop();

	/**
	 * Validate and read CSV file
	 * @param file
	 */
	const processFile = ( file ) => {
		// Validate file type
		if ( ! file.name.endsWith( '.csv' ) && file.type !== 'text/csv' ) {
			dragDrop.setError(
				__( 'Please select a valid CSV file.', 'data-machine' )
			);
			return;
		}

		// Validate file size (dynamic limit)
		const maxSize = window.dataMachineConfig?.maxUploadSize || 10485760; // fallback to 10MB
		if ( file.size > maxSize ) {
			const maxSizeMB = Math.round( maxSize / ( 1024 * 1024 ) );
			dragDrop.setError(
				__(
					`File size exceeds ${ maxSizeMB }MB limit.`,
					'data-machine'
				)
			);
			return;
		}

		// Read file content
		const reader = new FileReader();
		reader.onload = ( e ) => {
			const content = e.target.result;
			if ( onFileSelected ) {
				onFileSelected( content, file.name );
				dragDrop.setError( null );
			}
		};
		reader.onerror = () => {
			dragDrop.setError( __( 'Failed to read file.', 'data-machine' ) );
		};
		reader.readAsText( file );
	};

	/**
	 * Handle file drop
	 * @param files
	 */
	const handleDrop = ( files ) => {
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

	const dropzoneClass = [
		'datamachine-csv-dropzone',
		dragDrop.isDragging && 'datamachine-csv-dropzone--dragging',
		disabled && 'datamachine-csv-dropzone--disabled',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div>
			<div
				className={ dropzoneClass }
				onDragEnter={ dragDrop.handleDragEnter }
				onDragLeave={ dragDrop.handleDragLeave }
				onDragOver={ dragDrop.handleDragOver }
				onDrop={ dragDrop.handleDrop.bind( null, handleDrop ) }
				onClick={ ! disabled ? handleBrowseClick : undefined }
			>
				<div className="datamachine-csv-dropzone__icon"></div>

				<p className="datamachine-csv-dropzone__title">
					{ fileName
						? __( 'File selected', 'data-machine' )
						: __( 'Drag and drop CSV file here', 'data-machine' ) }
				</p>

				<p className="datamachine-csv-dropzone__divider">
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
					accept=".csv,text/csv"
					onChange={ handleFileInputChange }
					className="datamachine-hidden"
					disabled={ disabled }
				/>
			</div>

			{ dragDrop.error && (
				<div className="datamachine-csv-dropzone__error">
					{ dragDrop.error }
				</div>
			) }
		</div>
	);
}
