import type { SiteModel } from './types.js';

export type TemplatePlan = {
  templatesByPage: Record<string, 'front-page' | 'page'>;
};

export function planTemplates(site: SiteModel): TemplatePlan {
  const homeSlug = homeSlugForSite(site);
  const templatesByPage: TemplatePlan['templatesByPage'] = {};

  for (const page of site.pages) {
    templatesByPage[page.slug] = page.slug === homeSlug ? 'front-page' : 'page';
  }

  return { templatesByPage };
}

function homeSlugForSite(site: SiteModel): string {
  return (
    site.pages.find((page) => page.slug === 'home')?.slug ??
    site.pages.find((page) => /(^|[\\/])index\.html?$/i.test(page.relPath))?.slug ??
    site.pages[0]?.slug ??
    'home'
  );
}
