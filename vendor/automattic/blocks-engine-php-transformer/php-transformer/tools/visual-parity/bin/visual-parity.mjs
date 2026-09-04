#!/usr/bin/env node
import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const DEFAULT_VIEWPORTS = [
  { name: 'desktop', width: 1280, height: 720 },
];

const DEFAULT_SELECTORS = [
  { name: 'buttons', selector: 'button, a[role="button"], input[type="button"], input[type="submit"], .button, .btn, [class*="button"], [class*="btn"]' },
  { name: 'links', selector: 'a[href]' },
  { name: 'nav', selector: 'nav, [role="navigation"], header nav, .nav, [class*="nav"]' },
  { name: 'menu', selector: 'menu, [role="menu"], .menu, [class*="menu"]' },
  { name: 'cards', selector: 'article, [class*="card"], [class*="tile"], [class*="panel"], [data-card]' },
];

const STYLE_PROPERTIES = [
  'display',
  'color',
  'background-color',
  'border-top-color',
  'border-top-style',
  'border-top-width',
  'border-right-color',
  'border-right-style',
  'border-right-width',
  'border-bottom-color',
  'border-bottom-style',
  'border-bottom-width',
  'border-left-color',
  'border-left-style',
  'border-left-width',
  'border-radius',
  'padding-top',
  'padding-right',
  'padding-bottom',
  'padding-left',
  'font-size',
  'font-weight',
];

const BORDER_KEYS = [
  'border-top-color',
  'border-top-style',
  'border-top-width',
  'border-right-color',
  'border-right-style',
  'border-right-width',
  'border-bottom-color',
  'border-bottom-style',
  'border-bottom-width',
  'border-left-color',
  'border-left-style',
  'border-left-width',
];

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});

async function main() {
  const config = await loadConfig(process.argv.slice(2));
  validateConfig(config);

  const browser = await chromium.launch();
  try {
    const source = await captureSubject(browser, 'source', config.source, config);
    const target = await captureSubject(browser, 'target', config.target, config);
    const report = {
      schema: 'blocks-engine.visual-parity.probes.v1',
      config: publicConfig(config),
      source,
      target,
      comparison: compareSubjects(source, target),
    };

    await writeJson(config.output, report);
    console.log(`Visual parity report written to ${config.output}`);
  } finally {
    await browser.close();
  }
}

async function loadConfig(args) {
  const cli = parseArgs(args);
  let config = {};

  if (cli.config) {
    const raw = await readFile(cli.config, 'utf8');
    config = JSON.parse(raw);
  }

  return normalizeConfig({ ...config, ...cli });
}

function parseArgs(args) {
  const parsed = {};
  const viewports = [];
  const selectors = [];

  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    const next = args[index + 1];

    if (!arg.startsWith('--')) {
      throw new Error(`Unexpected argument: ${arg}`);
    }

    const [rawKey, inlineValue] = arg.slice(2).split('=', 2);
    const value = inlineValue ?? next;
    if (inlineValue === undefined) {
      index += 1;
    }

    if (!value) {
      throw new Error(`Missing value for --${rawKey}`);
    }

    if (rawKey === 'viewport') {
      viewports.push(value);
      continue;
    }

    if (rawKey === 'selector' || rawKey === 'probe') {
      selectors.push(value);
      continue;
    }

    parsed[toCamelCase(rawKey)] = value;
  }

  if (viewports.length > 0) {
    parsed.viewports = viewports;
  }
  if (selectors.length > 0) {
    parsed.selectors = selectors;
  }

  return parsed;
}

function normalizeConfig(config) {
  return {
    source: config.source,
    target: config.target,
    output: config.output ?? 'visual-parity-report.json',
    viewports: normalizeViewports(config.viewports ?? config.viewport ?? DEFAULT_VIEWPORTS),
    selectors: normalizeSelectors(config.selectors ?? config.probes ?? DEFAULT_SELECTORS),
    maxMatchesPerSelector: Number(config.maxMatchesPerSelector ?? 50),
    waitUntil: config.waitUntil ?? 'load',
  };
}

function normalizeViewports(viewports) {
  const list = Array.isArray(viewports) ? viewports : [viewports];
  return list.map((viewport, index) => {
    if (typeof viewport === 'object' && viewport !== null) {
      return {
        name: String(viewport.name ?? `viewport-${index + 1}`),
        width: Number(viewport.width),
        height: Number(viewport.height),
      };
    }

    const value = String(viewport);
    const [maybeName, size] = value.includes('=') ? value.split('=', 2) : [`viewport-${index + 1}`, value];
    const [width, height] = size.toLowerCase().split('x').map((part) => Number(part));
    return { name: maybeName, width, height };
  });
}

function normalizeSelectors(selectors) {
  const list = Array.isArray(selectors) ? selectors : [selectors];
  return list.map((selector, index) => {
    if (typeof selector === 'object' && selector !== null) {
      return {
        name: String(selector.name ?? `probe-${index + 1}`),
        selector: String(selector.selector ?? ''),
      };
    }

    const value = String(selector);
    const [name, cssSelector] = value.includes('=') ? value.split('=', 2) : [`probe-${index + 1}`, value];
    return { name, selector: cssSelector };
  });
}

function validateConfig(config) {
  for (const key of ['source', 'target', 'output']) {
    if (!config[key]) {
      throw new Error(`Missing required config value: ${key}`);
    }
  }

  for (const viewport of config.viewports) {
    if (!viewport.name || !Number.isFinite(viewport.width) || !Number.isFinite(viewport.height) || viewport.width <= 0 || viewport.height <= 0) {
      throw new Error(`Invalid viewport: ${JSON.stringify(viewport)}`);
    }
  }

  for (const probe of config.selectors) {
    if (!probe.name || !probe.selector) {
      throw new Error(`Invalid selector probe: ${JSON.stringify(probe)}`);
    }
  }
}

async function captureSubject(browser, role, input, config) {
  const context = await browser.newContext();
  try {
    const pages = [];
    for (const viewport of config.viewports) {
      const page = await context.newPage();
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto(toNavigableUrl(input), { waitUntil: config.waitUntil });
      pages.push({
        viewport,
        url: page.url(),
        probes: await extractProbes(page, config.selectors, config.maxMatchesPerSelector),
      });
      await page.close();
    }

    return { role, input, snapshots: pages };
  } finally {
    await context.close();
  }
}

async function extractProbes(page, selectors, maxMatchesPerSelector) {
  return page.evaluate(({ selectors: selectorConfigs, maxMatches, styleProperties, borderKeys }) => {
    return selectorConfigs.map((probe) => {
      const elements = Array.from(document.querySelectorAll(probe.selector)).slice(0, maxMatches);
      return {
        name: probe.name,
        selector: probe.selector,
        count: elements.length,
        matches: elements.map((element, index) => serializeElement(element, index, styleProperties, borderKeys)),
      };
    });

    function serializeElement(element, index, styleProperties, borderKeys) {
      const rect = element.getBoundingClientRect();
      const computed = window.getComputedStyle(element);
      const styles = Object.fromEntries(styleProperties.map((property) => [property, computed.getPropertyValue(property)]));
      const border = Object.fromEntries(borderKeys.map((property) => [property, styles[property]]));

      return {
        index,
        tag: element.tagName.toLowerCase(),
        text: normalizeText(element.textContent || ''),
        href: element instanceof HTMLAnchorElement ? element.getAttribute('href') : null,
        role: element.getAttribute('role'),
        classes: Array.from(element.classList),
        bounding_box: {
          x: round(rect.x),
          y: round(rect.y),
          width: round(rect.width),
          height: round(rect.height),
        },
        display: styles.display,
        color: styles.color,
        'background-color': styles['background-color'],
        border,
        'border-radius': styles['border-radius'],
        padding: {
          top: styles['padding-top'],
          right: styles['padding-right'],
          bottom: styles['padding-bottom'],
          left: styles['padding-left'],
        },
        'font-size': styles['font-size'],
        'font-weight': styles['font-weight'],
      };
    }

    function normalizeText(value) {
      return value.replace(/\s+/g, ' ').trim();
    }

    function round(value) {
      return Math.round(value * 100) / 100;
    }
  }, {
    selectors,
    maxMatches: maxMatchesPerSelector,
    styleProperties: STYLE_PROPERTIES,
    borderKeys: BORDER_KEYS,
  });
}

function compareSubjects(source, target) {
  return source.snapshots.map((sourceSnapshot, index) => {
    const targetSnapshot = target.snapshots[index];
    return {
      viewport: sourceSnapshot.viewport,
      probes: sourceSnapshot.probes.map((sourceProbe) => {
        const targetProbe = targetSnapshot.probes.find((probe) => probe.name === sourceProbe.name);
        return {
          name: sourceProbe.name,
          selector: sourceProbe.selector,
          source_count: sourceProbe.count,
          target_count: targetProbe?.count ?? 0,
          count_delta: (targetProbe?.count ?? 0) - sourceProbe.count,
        };
      }),
    };
  });
}

async function writeJson(outputPath, data) {
  await mkdir(path.dirname(outputPath), { recursive: true });
  await writeFile(outputPath, `${JSON.stringify(data, null, 2)}\n`, 'utf8');
}

function toNavigableUrl(value) {
  if (/^https?:\/\//.test(value) || value.startsWith('file://')) {
    return value;
  }

  return pathToFileURL(path.resolve(value)).href;
}

function publicConfig(config) {
  return {
    source: config.source,
    target: config.target,
    output: config.output,
    viewports: config.viewports,
    selectors: config.selectors,
    maxMatchesPerSelector: config.maxMatchesPerSelector,
    waitUntil: config.waitUntil,
  };
}

function toCamelCase(value) {
  return value.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}
