/**
 * API utilities for social media posting
 */

import apiFetch from '@wordpress/api-fetch';
import { CrossPostPayload, CrossPostResponse, PlatformAuthStatus } from '../types';

// REST route relative to the WordPress REST root. Do NOT include the
// "/wp-json/" prefix here: apiFetch (and apiFetch-derived helpers) already
// prepend the REST root, so a leading "/wp-json/" double-prefixes the request
// into "/wp-json/wp-json/datamachine/..." and 404s.
const REST_BASE = '/datamachine/v1/socials';

export async function getAuthStatus(): Promise<PlatformAuthStatus[]> {
	return apiFetch({ path: `${REST_BASE}/auth/status` });
}

export interface PlatformCapability {
	slug: string;
	label: string;
}

export interface PlatformConfig {
	slug: string;
	label: string;
	type: string;
	authenticated: boolean;
	username: string | null;
	capabilities: PlatformCapability[];
	[key: string]: unknown;
}

export interface PlatformsResponse {
	platforms: PlatformConfig[];
}

export async function getPlatforms(): Promise<PlatformsResponse> {
	return apiFetch({ path: `${REST_BASE}/platforms` });
}

export async function crossPost(payload: CrossPostPayload): Promise<CrossPostResponse> {
	return apiFetch({
		path: `${REST_BASE}/post`,
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

	// Use apiFetch (not raw fetch) so the REST root, nonce, and credentials are
	// resolved consistently instead of hardcoding "/wp-json/" and the nonce.
	// apiFetch passes a FormData `body` through untouched (no JSON serialization).
	return apiFetch({
		path: `${REST_BASE}/media/crop`,
		method: 'POST',
		body: formData,
	});
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
