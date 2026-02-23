/**
 * Social Editor - Gutenberg Sidebar Plugin Entry Point
 * 
 * Provides a unified interface for cross-platform social media posting
 * from the WordPress block editor.
 */

import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { PanelBody } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { SocialEditor } from './components/SocialEditor';
import { share as SocialIcon } from '@wordpress/icons';

// Import styles
import './style.scss';

declare global {
	interface Window {
		dmsData?: {
			postId?: number;
			restNonce?: string;
		};
		wp?: {
			data?: {
				select?: (store: string) => {
					getCurrentPostId?: () => number;
				};
			};
		};
	}
}

// Configure API fetch with nonce
if (typeof window !== 'undefined' && window.dmsData?.restNonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(window.dmsData.restNonce));
}

function SocialSidebarPlugin() {
	const [postId, setPostId] = useState<number | undefined>(window.dmsData?.postId);

	useEffect(() => {
		// Try to get post ID from WP data store if not available
		if (!postId && window.wp?.data?.select) {
			const editor = window.wp.data.select('core/editor');
			if (editor?.getCurrentPostId) {
				const id = editor.getCurrentPostId();
				if (id) {
					setPostId(id);
				}
			}
		}
	}, [postId]);

	if (!postId) {
		return (
			<PluginSidebar
				name="data-machine-socials-sidebar"
				title={__('Social Post', 'data-machine-socials')}
				icon={SocialIcon}
			>
				<PanelBody>
					<p>{__('Loading post data...', 'data-machine-socials')}</p>
				</PanelBody>
			</PluginSidebar>
		);
	}

	return (
		<>
			<PluginSidebarMoreMenuItem
				target="data-machine-socials-sidebar"
				icon={SocialIcon}
			>
				{__('Social Post', 'data-machine-socials')}
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="data-machine-socials-sidebar"
				title={__('Social Post', 'data-machine-socials')}
				icon={SocialIcon}
			>
				<SocialEditor postId={postId} />
			</PluginSidebar>
		</>
	);
}

// Register the plugin
registerPlugin('data-machine-socials', {
	render: SocialSidebarPlugin,
	icon: SocialIcon,
});
