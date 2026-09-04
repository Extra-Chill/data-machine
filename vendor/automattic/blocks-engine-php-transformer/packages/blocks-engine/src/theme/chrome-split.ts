import * as cheerio from 'cheerio';

export interface RegionSplit {
  headerHtml: string;
  mainHtml: string;
  footerHtml: string;
}

interface DeepRegionSplit {
  found: boolean;
  openWrap: string;
  headerHtml: string;
  midBefore: string;
  sectionsHtml: string[];
  midAfter: string;
  footerHtml: string;
  closeWrap: string;
}

function splitRegions(bodyHtml: string): RegionSplit {
  const $ = cheerio.load(bodyHtml, null, false);
  const root = $.root();

  const header = root.children('header, [role="banner"]').first();
  const footer = root.children('footer, [role="contentinfo"]').last();

  const headerHtml = header.length ? $.html(header) : '';
  const footerHtml = footer.length ? $.html(footer) : '';

  if (header.length) header.remove();
  if (footer.length) footer.remove();

  const mainHtml = $.html(root).trim();
  return { headerHtml, mainHtml, footerHtml };
}

function balancedSpan(html: string, tag: string, which: 'first' | 'last'): [number, number] | null {
  const open = new RegExp(`<${tag}(?![\\w-])[^>]*>`, 'gi');
  const close = new RegExp(`</${tag}\\s*>`, 'gi');
  const toks: Array<{ i: number; end: number; open: boolean }> = [];
  let match: RegExpExecArray | null;

  while ((match = open.exec(html))) {
    toks.push({ i: match.index, end: match.index + match[0].length, open: true });
  }
  while ((match = close.exec(html))) {
    toks.push({ i: match.index, end: match.index + match[0].length, open: false });
  }

  toks.sort((a, b) => a.i - b.i);

  const spans: Array<[number, number]> = [];
  let depth = 0;
  let start = -1;

  for (const tok of toks) {
    if (tok.open) {
      if (depth === 0) start = tok.i;
      depth++;
    } else if (depth > 0) {
      depth--;
      if (depth === 0 && start >= 0) spans.push([start, tok.end]);
    }
  }

  if (spans.length === 0) return null;
  return which === 'first' ? spans[0] : spans[spans.length - 1];
}

function idStart(html: string, id: string): number {
  const match = new RegExp(`<[a-zA-Z][^>]*\\bid="${id}"`, 'i').exec(html);
  return match ? match.index : -1;
}

function findChrome(
  html: string,
  opts: { tag: 'header' | 'footer'; id: string; which: 'first' | 'last' }
): [number, number] | null {
  const idx = idStart(html, opts.id);
  if (idx >= 0) {
    const span = balancedSpan(html.slice(idx), opts.tag, 'first');
    if (span) return [idx + span[0], idx + span[1]];
  }

  return balancedSpan(html, opts.tag, opts.which);
}

function divSpanEnd(html: string, start: number): number {
  const re = /<div(?![\w-])|<\/div\s*>/gi;
  re.lastIndex = start;
  let depth = 0;
  let match: RegExpExecArray | null;

  while ((match = re.exec(html))) {
    if (match[0][1] === '/') {
      depth--;
      if (depth === 0) return match.index + match[0].length;
    } else {
      depth++;
    }
  }

  return html.length;
}

function extendToHeaderGroup(html: string, header: [number, number]): [number, number] {
  const marker = 'shopify-section-group-header-group';
  let firstStart = -1;
  let lastEnd = -1;
  let from = 0;

  for (;;) {
    const markerIndex = html.indexOf(marker, from);
    if (markerIndex < 0) break;

    const lt = html.lastIndexOf('<', markerIndex);
    if (lt < 0) break;

    const end = divSpanEnd(html, lt);
    if (firstStart < 0) firstStart = lt;
    else if (html.slice(lastEnd, lt).trim() !== '') break;

    lastEnd = end;
    from = end;
  }

  if (firstStart >= 0 && firstStart <= header[0] && lastEnd >= header[1]) {
    return [firstStart, lastEnd];
  }

  return header;
}

function topLevelSections(html: string): Array<[number, number]> {
  const re = /<\/?section\b[^>]*>/gi;
  const out: Array<[number, number]> = [];
  let depth = 0;
  let start = -1;
  let match: RegExpExecArray | null;

  while ((match = re.exec(html))) {
    const isClose = match[0].startsWith('</');
    if (!isClose) {
      if (depth === 0) start = match.index;
      depth++;
    } else if (depth > 0) {
      depth--;
      if (depth === 0 && start >= 0) out.push([start, match.index + match[0].length]);
    }
  }

  return out;
}

function splitRegionsDeep(bodyHtml: string): DeepRegionSplit {
  const empty: DeepRegionSplit = {
    found: false,
    openWrap: '',
    headerHtml: '',
    midBefore: '',
    sectionsHtml: [],
    midAfter: '',
    footerHtml: '',
    closeWrap: '',
  };

  const headerEl = findChrome(bodyHtml, { tag: 'header', id: 'SITE_HEADER', which: 'first' });
  // A site header precedes the content sections. A <header> that appears after the first
  // top-level <section> is a section-internal header (e.g. <header class="section-header">
  // inside a content section), not site chrome — leave it in the body so it renders in place
  // instead of hijacking the WP header template part. An explicit id="SITE_HEADER" overrides.
  const hasHeaderId = idStart(bodyHtml, 'SITE_HEADER') >= 0;
  const firstSection = topLevelSections(bodyHtml)[0];
  const headerIsSiteLevel =
    headerEl !== null && (hasHeaderId || firstSection === undefined || headerEl[0] < firstSection[0]);
  let header = headerIsSiteLevel ? extendToHeaderGroup(bodyHtml, headerEl as [number, number]) : null;
  // No <header> banner? A leading top-level <nav> before the first content section is
  // the site navigation (e.g. <nav class="nav"> at the top of the hero) — route it to the
  // header template part instead of letting it be stripped and discarded as a body section.
  if (header === null) {
    const navEl = balancedSpan(bodyHtml, 'nav', 'first');
    if (navEl && (firstSection === undefined || navEl[0] < firstSection[0])) {
      header = navEl;
    }
  }
  const footer = findChrome(bodyHtml, { tag: 'footer', id: 'SITE_FOOTER', which: 'last' });

  if (!header && !footer) return empty;

  const headerStart = header ? header[0] : 0;
  const headerEnd = header ? header[1] : 0;
  const footerStart = footer ? footer[0] : bodyHtml.length;
  const footerEnd = footer ? footer[1] : bodyHtml.length;

  if (footerStart < headerEnd) return empty;

  const openWrap = bodyHtml.slice(0, headerStart);
  const headerHtml = header ? bodyHtml.slice(headerStart, headerEnd) : '';
  const middle = bodyHtml.slice(headerEnd, footerStart);
  const footerHtml = footer ? bodyHtml.slice(footerStart, footerEnd) : '';
  const closeWrap = bodyHtml.slice(footerEnd);

  const sections = topLevelSections(middle);
  if (sections.length === 0) {
    return {
      found: true,
      openWrap,
      headerHtml,
      midBefore: '',
      sectionsHtml: middle ? [middle] : [],
      midAfter: '',
      footerHtml,
      closeWrap,
    };
  }

  const midBefore = middle.slice(0, sections[0][0]);
  const midAfter = middle.slice(sections[sections.length - 1][1]);
  const sectionsHtml: string[] = [];

  for (let idx = 0; idx < sections.length; idx++) {
    const end = idx + 1 < sections.length ? sections[idx + 1][0] : sections[idx][1];
    sectionsHtml.push(middle.slice(sections[idx][0], end));
  }

  return {
    found: true,
    openWrap,
    headerHtml,
    midBefore,
    sectionsHtml,
    midAfter,
    footerHtml,
    closeWrap,
  };
}

export function splitPageChrome(bodyHtml: string): RegionSplit {
  const deep = splitRegionsDeep(bodyHtml);
  if (deep.found) {
    return {
      headerHtml: deep.headerHtml,
      mainHtml:
        deep.openWrap + deep.midBefore + deep.sectionsHtml.join('') + deep.midAfter + deep.closeWrap,
      footerHtml: deep.footerHtml,
    };
  }

  const shallow = splitRegions(bodyHtml);
  if (shallow.headerHtml || shallow.footerHtml) return shallow;

  return { headerHtml: '', mainHtml: bodyHtml, footerHtml: '' };
}
