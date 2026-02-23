/**
 * Hook for managing cross-platform posting
 */

import { useState, useCallback } from '@wordpress/element';
import { crossPost, schedulePost } from '../utils/api';
import { CrossPostPayload, CrossPostResponse, PostStatus } from '../types';

interface UseCrossPostReturn {
	isPosting: boolean;
	isScheduling: boolean;
	results: PostStatus[];
	errors: string[];
	post: (payload: CrossPostPayload) => Promise<boolean>;
	schedule: (payload: CrossPostPayload & { schedule: Date }) => Promise<boolean>;
	reset: () => void;
}

export function useCrossPost(): UseCrossPostReturn {
	const [isPosting, setIsPosting] = useState(false);
	const [isScheduling, setIsScheduling] = useState(false);
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

	const schedule = useCallback(async (payload: CrossPostPayload & { schedule: Date }): Promise<boolean> => {
		try {
			setIsScheduling(true);
			reset();

			const response: CrossPostResponse = await schedulePost(payload);

			if (response.success) {
				setResults(response.results);
				return true;
			} else {
				setErrors(response.errors || ['Scheduling failed']);
				return false;
			}
		} catch (error) {
			const message = error instanceof Error ? error.message : 'Unknown error';
			setErrors([message]);
			return false;
		} finally {
			setIsScheduling(false);
		}
	}, [reset]);

	return {
		isPosting,
		isScheduling,
		results,
		errors,
		post,
		schedule,
		reset,
	};
}
