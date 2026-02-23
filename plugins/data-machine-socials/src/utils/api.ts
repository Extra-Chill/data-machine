/**
 * API utilities for social media posting
 */

import apiFetch from '@wordpress/api-fetch';
import { CrossPostPayload, CrossPostResponse, PlatformAuthStatus } from '../types';

const REST_BASE = '/wp-json/datamachine-socials/v1';

export async function getAuthStatus(): Promise<PlatformAuthStatus[]> {
	return apiFetch({ path: `${REST_BASE}/auth/status` });
}

export async function getPlatforms(): Promise<Record<string, unknown>> {
	return apiFetch({ path: `${REST_BASE}/platforms` });
}

export async function crossPost(payload: CrossPostPayload): Promise<CrossPostResponse> {
	return apiFetch({
		path: `${REST_BASE}/post`,
		method: 'POST',
		data: payload,
	});
}

export async function schedulePost(payload: CrossPostPayload & { schedule: Date }): Promise<CrossPostResponse> {
	return apiFetch({
		path: `${REST_BASE}/schedule`,
		method: 'POST',
		data: payload,
	});
}

export async function uploadCroppedImage(
	blob: Blob,
	filename: string
): Promise<{ url: string; error?: string }> {
	const formData = new FormData();
	formData.append('file', blob, filename);

	const response = await fetch(`${REST_BASE}/media/crop`, {
		method: 'POST',
		body: formData,
		headers: {
			'X-WP-Nonce': (window as any).dmsData?.restNonce || '',
		},
	});

	return response.json();
}

export async function getPostStatus(postId: number): Promise<unknown> {
	return apiFetch({ path: `${REST_BASE}/status/${postId}` });
}

export async function disconnectPlatform(platform: string): Promise<{ success: boolean }> {
	return apiFetch({
		path: `${REST_BASE}/auth/${platform}/disconnect`,
		method: 'POST',
	});
}
