export function chromeSignature(headerHtml: string, footerHtml: string): string {
  const neutral = [stripActiveNavState(headerHtml), stripActiveNavState(footerHtml)].join('\u0001');
  return canonicalizeInstanceIds(neutral);
}

export interface ChromeSlugs {
  header: string;
  footer: string;
}

export function assignChromeVariants(
  pages: Array<{ slug: string; headerHtml: string; footerHtml: string }>
): {
  slugsByPage: Record<string, ChromeSlugs>;
  canonical: Record<string, { headerHtml: string; footerHtml: string }>;
} {
  const slugsByPage: Record<string, ChromeSlugs> = {};
  const canonical: Record<string, { headerHtml: string; footerHtml: string }> = {};
  const slugBySignature = new Map<string, ChromeSlugs>();

  for (const page of pages) {
    const signature = chromeSignature(page.headerHtml, page.footerHtml);
    let slugs = slugBySignature.get(signature);

    if (!slugs) {
      slugs = chromeSlugsForIndex(slugBySignature.size);
      slugBySignature.set(signature, slugs);
      canonical[slugs.header] = {
        headerHtml: page.headerHtml,
        footerHtml: page.footerHtml,
      };
    }

    slugsByPage[page.slug] = slugs;
  }

  return { slugsByPage, canonical };
}

export function stripActiveNavState(html: string): string {
  return html
    .replace(/ ?data-selected="true"/g, '')
    .replace(/ ?aria-current="page"/g, '')
    .replace(/data-interactive="false"/g, 'data-interactive="true"');
}

export function canonicalizeInstanceIds(s: string): string {
  const order: string[] = [];
  const seen = new Set<string>();

  for (const match of s.matchAll(/(?<!_r_)comp-([a-z0-9]+)/g)) {
    const instanceId = match[1];
    if (!seen.has(instanceId)) {
      seen.add(instanceId);
      order.push(instanceId);
    }
  }

  let out = s;
  order.forEach((instanceId, index) => {
    out = out.replace(
      new RegExp(`(?<!_r_)comp-${instanceId}(?![a-z0-9])`, 'g'),
      `comp-INSTANCE${index}`
    );
  });

  return out;
}

function chromeSlugsForIndex(index: number): ChromeSlugs {
  const suffix = index === 0 ? '' : `-${index + 1}`;
  return { header: `header${suffix}`, footer: `footer${suffix}` };
}
