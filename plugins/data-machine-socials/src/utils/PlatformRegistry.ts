/**
 * Platform Registry
 * 
 * Central registry for social media platform configurations
 */

import type { PlatformConfig } from '../types';

export const PLATFORMS: Record<string, PlatformConfig> = {
	instagram: {
		slug: 'instagram',
		label: 'Instagram',
		icon: 'instagram',
		maxImages: 10,
		aspectRatios: ['1:1', '4:5', '3:4', '1.91:1'],
		defaultAspectRatio: '4:5',
		charLimit: 2200,
		supportsCarousel: true,
		supportsVideo: true,
		supportedMediaKinds: ['image', 'carousel', 'reel'],
		requiresAuth: true,
		bestPractices: {
			optimalLength: 138,
			lineBreaksOk: true,
			hashtagPlacement: 'end',
			maxHashtags: 30,
		},
		formatting: {
			supportsEmojis: true,
			supportsMentions: true,
			supportsLinks: false,
			supportsFormatting: false,
		},
	},
	twitter: {
		slug: 'twitter',
		label: 'Twitter / X',
		icon: 'twitter',
		maxImages: 4,
		aspectRatios: ['any'],
		defaultAspectRatio: 'any',
		charLimit: 280,
		supportsCarousel: false,
		supportsVideo: false,
		requiresAuth: true,
		bestPractices: {
			optimalLength: 71,
			lineBreaksOk: false,
			hashtagPlacement: 'inline',
			maxHashtags: null,
		},
		formatting: {
			supportsEmojis: true,
			supportsMentions: true,
			supportsLinks: true,
			supportsFormatting: false,
		},
	},
	facebook: {
		slug: 'facebook',
		label: 'Facebook',
		icon: 'facebook',
		maxImages: 10,
		aspectRatios: ['any'],
		defaultAspectRatio: 'any',
		charLimit: 63206,
		supportsCarousel: true,
		supportsVideo: false,
		requiresAuth: true,
		bestPractices: {
			optimalLength: 80,
			lineBreaksOk: true,
			hashtagPlacement: 'end',
			maxHashtags: null,
		},
		formatting: {
			supportsEmojis: true,
			supportsMentions: true,
			supportsLinks: true,
			supportsFormatting: true,
		},
	},
	bluesky: {
		slug: 'bluesky',
		label: 'Bluesky',
		icon: 'bluesky',
		maxImages: 4,
		aspectRatios: ['any'],
		defaultAspectRatio: 'any',
		charLimit: 300,
		supportsCarousel: false,
		supportsVideo: false,
		requiresAuth: true,
		bestPractices: {
			optimalLength: 300,
			lineBreaksOk: true,
			hashtagPlacement: 'inline',
			maxHashtags: null,
		},
		formatting: {
			supportsEmojis: true,
			supportsMentions: true,
			supportsLinks: true,
			supportsFormatting: true,
		},
	},
	threads: {
		slug: 'threads',
		label: 'Threads',
		icon: 'threads',
		maxImages: 10,
		aspectRatios: ['any'],
		defaultAspectRatio: 'any',
		charLimit: 500,
		supportsCarousel: true,
		supportsVideo: false,
		requiresAuth: true,
		bestPractices: {
			optimalLength: 500,
			lineBreaksOk: true,
			hashtagPlacement: 'inline',
			maxHashtags: null,
		},
		formatting: {
			supportsEmojis: true,
			supportsMentions: true,
			supportsLinks: true,
			supportsFormatting: true,
		},
	},
	pinterest: {
		slug: 'pinterest',
		label: 'Pinterest',
		icon: 'pinterest',
		maxImages: 1,
		aspectRatios: ['2:3'],
		defaultAspectRatio: '2:3',
		charLimit: 500,
		supportsCarousel: false,
		supportsVideo: false,
		requiresAuth: true,
		requiresBoard: true,
		bestPractices: {
			optimalLength: 150,
			lineBreaksOk: false,
			hashtagPlacement: 'end',
			maxHashtags: 20,
		},
		formatting: {
			supportsEmojis: false,
			supportsMentions: false,
			supportsLinks: true,
			supportsFormatting: false,
		},
	},
};

export function getPlatform(platform: string): PlatformConfig | null {
	return PLATFORMS[platform] ?? null;
}

export function getAllPlatforms(): Record<string, PlatformConfig> {
	return PLATFORMS;
}

export function getPlatformSlugs(): string[] {
	return Object.keys(PLATFORMS);
}

export function supportsCarousel(platform: string): boolean {
	return PLATFORMS[platform]?.supportsCarousel ?? false;
}

export function getMaxImages(platform: string): number {
	return PLATFORMS[platform]?.maxImages ?? 1;
}

export function getCharLimit(platform: string): number {
	return PLATFORMS[platform]?.charLimit ?? 280;
}

export function getMinCharLimit(platforms: string[]): number {
	return platforms.reduce((min, platform) => {
		const limit = getCharLimit(platform);
		return limit < min ? limit : min;
	}, Infinity);
}

export function validateCaptionLength(caption: string, platform: string): {
	valid: boolean;
	length: number;
	limit: number;
	remaining: number;
} {
	const limit = getCharLimit(platform);
	const length = caption?.length ?? 0;
	return {
		valid: length <= limit,
		length,
		limit,
		remaining: limit - length,
	};
}

export function getCombinedConstraints(platforms: string[]): {
	charLimit: number;
	maxImages: number;
	aspectRatios: string[];
	supportsCarousel: boolean;
} | null {
	if (!platforms?.length) return null;

	let minCharLimit = Infinity;
	let minImages = Infinity;
	let commonRatios: string[] | null = null;
	let carouselSupport = false;

	for (const platform of platforms) {
		const config = PLATFORMS[platform];
		if (!config) continue;

		minCharLimit = Math.min(minCharLimit, config.charLimit);
		minImages = Math.min(minImages, config.maxImages);
		carouselSupport = carouselSupport || config.supportsCarousel;

		if (commonRatios === null) {
			commonRatios = [...config.aspectRatios];
		} else {
			commonRatios = commonRatios.filter(ratio => 
				config.aspectRatios.includes(ratio)
			);
		}
	}

	return {
		charLimit: minCharLimit === Infinity ? 280 : minCharLimit,
		maxImages: minImages === Infinity ? 1 : minImages,
		aspectRatios: commonRatios?.length ? commonRatios : ['any'],
		supportsCarousel: carouselSupport,
	};
}
