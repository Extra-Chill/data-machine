/**
 * Hook for managing cross-platform posting
 */

import { useState, useCallback } from '@wordpress/element';
import { crossPost } from '../utils/api';
import { CrossPostPayload, CrossPostResponse, PostStatus } from '../types';

interface UseCrossPostReturn {
	isPosting: boolean;
	results: PostStatus[];
	errors: string[];
	post: (payload: CrossPostPayload) => Promise<boolean>;
	reset: () => void;
}

export function useCrossPost(): UseCrossPostReturn {
	const [isPosting, setIsPosting] = useState(false);
	const [results, setResults] = useState<PostStatus[]>([]);
	const [errors, setErrors] = useState<string[]>([]);

	const reset = useCallback(() => {
		setResults([]);
		setErrors([]);
	}, []);

	const post = useCallback(async (payload: CrossPostPayload): Promise<boolean> => {
		try {
			setIsPosting(true);
			reset();

			const response: CrossPostResponse = await crossPost(payload);

			if (response.success) {
				setResults(response.results);
				if (response.errors) {
					setErrors(response.errors);
				}
				return true;
			} else {
				setErrors(response.errors || ['Posting failed']);
				return false;
			}
		} catch (error) {
			const message = error instanceof Error ? error.message : 'Unknown error';
			setErrors([message]);
			return false;
		} finally {
			setIsPosting(false);
		}
	}, [reset]);

	return {
		isPosting,
		results,
		errors,
		post,
		reset,
	};
}
