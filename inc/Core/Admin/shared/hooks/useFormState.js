/**
 * Form State Hook
 *
 * Eliminates repetitive form state management boilerplate.
 * Provides consistent loading, error, and success state handling.
 * Uses useReducer for stable callback references.
 */
/**
 * WordPress dependencies
 */
import { useReducer, useCallback, useRef, useState } from '@wordpress/element';

/**
 * Form state reducer
 * @param state
 * @param action
 */
const formReducer = ( state, action ) => {
	switch ( action.type ) {
		case 'UPDATE_FIELD':
			return {
				...state,
				data: {
					...state.data,
					[ action.field ]: action.value,
				},
				error: null,
			};

		case 'UPDATE_DATA':
			return {
				...state,
				data: {
					...state.data,
					...action.payload,
				},
				error: null,
			};

		case 'RESET_DATA':
			return {
				...state,
				data: action.payload,
				error: null,
				success: null,
			};

		case 'SET_SUBMITTING':
			return {
				...state,
				isSubmitting: action.payload,
			};

		case 'SET_ERROR':
			return {
				...state,
				error: action.payload,
			};

		case 'SET_SUCCESS':
			return {
				...state,
				success: action.payload,
			};

		case 'RESET_ALL':
			return {
				data: action.payload || {},
				isSubmitting: false,
				error: null,
				success: null,
			};

		default:
			return state;
	}
};

/**
 * Generic form state management hook
 *
 * @param {Object}   options             Configuration options
 * @param {any}      options.initialData Initial form data
 * @param {Function} options.validate    Validation function
 * @param {Function} options.onSubmit    Submit handler
 * @return {Object} Form state and handlers
 */
export const useFormState = ( {
	initialData = {},
	validate,
	onSubmit,
} = {} ) => {
	const [ state, dispatch ] = useReducer( formReducer, {
		data: initialData,
		isSubmitting: false,
		error: null,
		success: null,
	} );

	const validateRef = useRef( validate );
	const onSubmitRef = useRef( onSubmit );
	validateRef.current = validate;
	onSubmitRef.current = onSubmit;

	const updateField = useCallback( ( field, value ) => {
		dispatch( { type: 'UPDATE_FIELD', field, value } );
	}, [] );

	const updateData = useCallback( ( newData ) => {
		dispatch( { type: 'UPDATE_DATA', payload: newData } );
	}, [] );

	const reset = useCallback( ( newData ) => {
		dispatch( { type: 'RESET_DATA', payload: newData } );
	}, [] );

	const resetAll = useCallback( ( newData ) => {
		dispatch( { type: 'RESET_ALL', payload: newData } );
	}, [] );

	const setError = useCallback( ( error ) => {
		dispatch( { type: 'SET_ERROR', payload: error } );
	}, [] );

	const setSuccess = useCallback( ( success ) => {
		dispatch( { type: 'SET_SUCCESS', payload: success } );
	}, [] );

	const submit = useCallback( async () => {
		if ( state.isSubmitting ) {
			return;
		}

		dispatch( { type: 'SET_ERROR', payload: null } );
		dispatch( { type: 'SET_SUCCESS', payload: null } );

		if ( validateRef.current ) {
			const validationError = validateRef.current( state.data );
			if ( validationError ) {
				dispatch( { type: 'SET_ERROR', payload: validationError } );
				return;
			}
		}

		dispatch( { type: 'SET_SUBMITTING', payload: true } );

		try {
			const result = await onSubmitRef.current( state.data );
			dispatch( { type: 'SET_SUCCESS', payload: result || true } );
			return result;
		} catch ( err ) {
			dispatch( {
				type: 'SET_ERROR',
				payload: err.message || 'An error occurred',
			} );
			throw err;
		} finally {
			dispatch( { type: 'SET_SUBMITTING', payload: false } );
		}
	}, [ state.isSubmitting, state.data ] );

	return {
		data: state.data,
		isSubmitting: state.isSubmitting,
		error: state.error,
		success: state.success,
		updateField,
		updateData,
		reset,
		resetAll,
		submit,
		setError,
		setSuccess,
	};
};

/**
 * Async Operation Hook
 *
 * For non-form async operations (API calls, file uploads, etc.)
 */
export const useAsyncOperation = () => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( null );

	const execute = useCallback(
		async ( operation ) => {
			if ( isLoading ) {
				return;
			}

			setError( null );
			setSuccess( null );
			setIsLoading( true );

			try {
				const result = await operation();
				setSuccess( result || true );
				return result;
			} catch ( err ) {
				setError( err.message || 'An error occurred' );
				throw err;
			} finally {
				setIsLoading( false );
			}
		},
		[ isLoading ]
	);

	const reset = useCallback( () => {
		setIsLoading( false );
		setError( null );
		setSuccess( null );
	}, [] );

	return {
		isLoading,
		error,
		success,
		execute,
		reset,
		setError,
		setSuccess,
	};
};

/**
 * File Upload Hook
 *
 * Specialized hook for file upload operations
 */
export const useFileUpload = () => {
	const [ isUploading, setIsUploading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ success, setSuccess ] = useState( null );

	const upload = useCallback(
		async ( uploadFn ) => {
			if ( isUploading ) {
				return;
			}

			setError( null );
			setSuccess( null );
			setIsUploading( true );

			try {
				const result = await uploadFn();
				setSuccess( result || true );
				return result;
			} catch ( err ) {
				setError( err.message || 'Upload failed' );
				throw err;
			} finally {
				setIsUploading( false );
			}
		},
		[ isUploading ]
	);

	const reset = useCallback( () => {
		setIsUploading( false );
		setError( null );
		setSuccess( null );
	}, [] );

	return {
		isUploading,
		error,
		success,
		upload,
		reset,
		setError,
		setSuccess,
	};
};

/**
 * Drag and Drop Hook
 *
 * For drag and drop file operations
 */
export const useDragDrop = () => {
	const [ isDragging, setIsDragging ] = useState( false );
	const [ error, setError ] = useState( null );

	const handleDragEnter = useCallback( ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging( true );
	}, [] );

	const handleDragLeave = useCallback( ( e ) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging( false );
	}, [] );

	const handleDragOver = useCallback( ( e ) => {
		e.preventDefault();
		e.stopPropagation();
	}, [] );

	const handleDrop = useCallback( ( e, onFilesDropped ) => {
		e.preventDefault();
		e.stopPropagation();
		setIsDragging( false );
		setError( null );

		const files = Array.from( e.dataTransfer.files );
		if ( files.length === 0 ) {
			return;
		}

		try {
			onFilesDropped( files );
		} catch ( err ) {
			setError( err.message || 'File processing failed' );
		}
	}, [] );

	const reset = useCallback( () => {
		setIsDragging( false );
		setError( null );
	}, [] );

	return {
		isDragging,
		error,
		handleDragEnter,
		handleDragLeave,
		handleDragOver,
		handleDrop,
		reset,
		setError,
	};
};
