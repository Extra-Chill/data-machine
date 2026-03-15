/**
 * Type definitions for Data Machine Socials
 */

export type MediaKind = 'image' | 'carousel' | 'reel' | 'story';

export interface PlatformConfig {
	slug: string;
	label: string;
	icon: string;
	maxImages: number;
	aspectRatios: string[];
	defaultAspectRatio: string;
	charLimit: number;
	supportsCarousel: boolean;
	supportsVideo: boolean;
	supportedMediaKinds?: MediaKind[];
	requiresAuth: boolean;
	requiresBoard?: boolean;
	bestPractices: {
		optimalLength: number;
		lineBreaksOk: boolean;
		hashtagPlacement: 'end' | 'inline';
		maxHashtags: number | null;
	};
	formatting: {
		supportsEmojis: boolean;
		supportsMentions: boolean;
		supportsLinks: boolean;
		supportsFormatting: boolean;
	};
}

export interface SelectedImage {
	id: number;
	url: string;
	width: number;
	height: number;
	alt?: string;
	croppedBlob?: Blob;
	cropData?: CropData;
}

export interface CropData {
	x: number;
	y: number;
	width: number;
	height: number;
}

export interface PostStatus {
	platform: string;
	status: 'pending' | 'processing' | 'completed' | 'failed';
	mediaId?: string;
	permalink?: string;
	error?: string;
}

export interface PlatformAuthStatus {
	platform: string;
	authenticated: boolean;
	username?: string;
	expiresAt?: number;
}

export interface CrossPostPayload {
	postId: number;
	platforms: string[];
	images: SelectedImage[];
	caption: string;
	aspectRatio: string;
	mediaKind?: MediaKind;
	videoUrl?: string;
	coverUrl?: string;
	shareToFeed?: boolean;
}

export interface CrossPostResponse {
	success: boolean;
	results: PostStatus[];
	errors?: string[];
}

export interface AuthState {
	isLoading: boolean;
	isConfigured: boolean;
	isAuthenticated: boolean;
	username?: string;
	authUrl?: string;
}

export interface PlatformState {
	selected: string[];
	authStatus: Record<string, AuthState>;
}
