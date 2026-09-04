import { defineCollection, z } from 'astro:content';

/**
 * Halo UI documentation content collection.
 *
 * Every .mdx file under src/content/docs/ is validated against this schema
 * at build time. Frontmatter that doesn't match will fail `astro check`.
 */
const docs = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    description: z.string(),
    // Grouping shown in the sidebar nav.
    group: z.enum(['Overview', 'Foundations', 'Components']),
    // Sort order within the group.
    order: z.number().default(99),
    // Lifecycle status surfaced as a badge in the page header.
    status: z.enum(['stable', 'beta', 'experimental', 'deprecated']).default('stable'),
    // Component-only metadata (ignored for foundations pages).
    since: z.string().optional(),
    a11y: z.string().optional(),
    figma: z.string().url().optional(),
    source: z.string().optional(),
    tags: z.array(z.string()).default([]),
  }),
});

export const collections = { docs };
