import { defineConfig } from 'astro/config';
import mdx from '@astrojs/mdx';
import react from '@astrojs/react';
import sitemap from '@astrojs/sitemap';

import rehypeSlug from 'rehype-slug';
import rehypeAutolinkHeadings from 'rehype-autolink-headings';
import remarkGfm from 'remark-gfm';

// https://astro.build/config
// Halo UI documentation site — MDX content collections compiled to static HTML.
export default defineConfig({
  site: 'https://halo.lumenlabs.dev',
  trailingSlash: 'always',
  integrations: [
    mdx({
      // Components are auto-imported into every .mdx file in src/content/docs.
      // Individual pages can still `import { X } from '...'` for one-off needs.
      extendMarkdownConfig: true,
      remarkPlugins: [remarkGfm],
      rehypePlugins: [
        rehypeSlug,
        [
          rehypeAutolinkHeadings,
          { behavior: 'wrap', properties: { className: ['heading-anchor'] } },
        ],
      ],
      optimize: true,
    }),
    react(),
    sitemap(),
  ],
  markdown: {
    shikiConfig: {
      theme: 'css-variables',
      wrap: true,
    },
  },
  vite: {
    resolve: {
      alias: {
        '@components': '/src/components',
        '@tokens': '/src/tokens',
      },
    },
  },
});
