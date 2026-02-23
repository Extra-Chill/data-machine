/**
 * Hook for managing platform authentication state
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { getAuthStatus } from '../utils/api';
import { PlatformAuthStatus, AuthState } from '../types';

type AuthStatusMap = Record<string, AuthState>;

export function usePlatformAuth() {
	const [authStatus, setAuthStatus] = useState<AuthStatusMap>({});
	const [isLoading, setIsLoading] = useState(true);

	const fetchAuthStatus = useCallback(async () => {
		try {
			setIsLoading(true);
			const statuses = await getAuthStatus();
			
			const mapped: AuthStatusMap = {};
			statuses.forEach((status: PlatformAuthStatus) => {
				mapped[status.platform] = {
					isLoading: false,
					isConfigured: true, // API only returns configured platforms
					isAuthenticated: status.authenticated,
					username: status.username,
				};
			});

			setAuthStatus(mapped);
		} catch (error) {
			console.error('Failed to fetch auth status:', error);
		} finally {
			setIsLoading(false);
		}
	}, []);

	useEffect(() => {
		fetchAuthStatus();
	}, [fetchAuthStatus]);

	const isPlatformAuthenticated = useCallback((platform: string): boolean => {
		return authStatus[platform]?.isAuthenticated ?? false;
	}, [authStatus]);

	const getPlatformUsername = useCallback((platform: string): string | undefined => {
		return authStatus[platform]?.username;
	}, [authStatus]);

	const refreshAuth = useCallback(() => {
		fetchAuthStatus();
	}, [fetchAuthStatus]);

	return {
		authStatus,
		isLoading,
		isPlatformAuthenticated,
		getPlatformUsername,
		refreshAuth,
	};
}
