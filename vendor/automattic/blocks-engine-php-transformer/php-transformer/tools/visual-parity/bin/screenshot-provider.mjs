#!/usr/bin/env node

import { mkdir, stat } from 'node:fs/promises';
import path from 'node:path';

const DEFAULT_VIEWPORT = { width: 1440, height: 900, device_scale_factor: 1 };
const SCREENSHOT_SCHEMA = 'blocks-engine.visual-parity.screenshots.v1';
const PLAYWRIGHT_SETUP_HELP = 'Install screenshot dependencies with: npm ci --prefix php-transformer/tools/visual-parity && npm --prefix php-transformer/tools/visual-parity run install:browsers';

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
});

async function main() {
  if (process.argv.includes('--help') || process.argv.includes('-h')) {
    printHelp();
    return;
  }

  const cli = parseCliArgs(process.argv.slice(2));
  const baseUrl = requiredSetting(cli, 'base-url', 'HOMEBOY_SCREENSHOT_BASE_URL').replace(/\/+$/, '');
  const pagePaths = resolvePagePaths(cli);
  const outputDir = requiredSetting(cli, 'output-dir', 'HOMEBOY_SCREENSHOT_OUTPUT_DIR');
  const viewport = parseViewport(cli.viewport ?? process.env.HOMEBOY_SCREENSHOT_VIEWPORT ?? `${DEFAULT_VIEWPORT.width}x${DEFAULT_VIEWPORT.height}`);
  const fullPage = parseBoolean(cli['full-page'] ?? process.env.HOMEBOY_SCREENSHOT_FULL_PAGE ?? 'true');
  const waitUntil = cli['wait-until'] ?? process.env.HOMEBOY_SCREENSHOT_WAIT_UNTIL ?? 'load';
  const { chromium } = await loadPlaywright();

  await mkdir(outputDir, { recursive: true });

  let browser;
  try {
    browser = await chromium.launch();
  } catch (error) {
    throw withPlaywrightSetupHelp(error);
  }

  try {
    const context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      deviceScaleFactor: viewport.device_scale_factor,
    });
    const screenshots = [];

    for (const pagePath of pagePaths) {
      const page = await context.newPage();
      const pageUrl = `${baseUrl}${String(pagePath).startsWith('/') ? pagePath : `/${pagePath}`}`;
      const filename = `${safeScreenshotName(pagePath)}.png`;
      const outputPath = path.join(outputDir, filename);
      await page.goto(pageUrl, { waitUntil });
      await page.screenshot({ path: outputPath, fullPage });
      await page.close();

      screenshots.push({
        page_path: pagePath,
        page_url: pageUrl,
        path: outputPath,
        filename,
        exists: await fileExists(outputPath),
      });
    }

    process.stdout.write(`${JSON.stringify({
      schema: SCREENSHOT_SCHEMA,
      base_url: baseUrl,
      output_dir: outputDir,
      viewport,
      full_page: fullPage,
      wait_until: waitUntil,
      screenshots,
    }, null, 2)}\n`);
  } finally {
    await browser.close();
  }
}

async function loadPlaywright() {
  try {
    return await import('playwright');
  } catch (error) {
    if (isMissingPlaywrightModule(error)) {
      throw new Error(`Playwright is required for screenshot capture but is not installed. ${PLAYWRIGHT_SETUP_HELP}`);
    }
    throw error;
  }
}

function isMissingPlaywrightModule(error) {
  return error?.code === 'ERR_MODULE_NOT_FOUND' && String(error?.message ?? '').includes("'playwright'");
}

function withPlaywrightSetupHelp(error) {
  const message = error instanceof Error ? error.message : String(error);
  if (message.includes('Executable doesn\'t exist') || message.includes('playwright install')) {
    return new Error(`${message}\n${PLAYWRIGHT_SETUP_HELP}`);
  }
  return error;
}

function parseCliArgs(argv) {
  const parsed = { 'page-path': [] };
  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (!arg.startsWith('--')) {
      continue;
    }

    const [rawKey, inlineValue] = arg.slice(2).split('=', 2);
    const next = argv[index + 1];
    const value = inlineValue ?? (next !== undefined && !next.startsWith('--') ? next : 'true');
    if (inlineValue === undefined && value !== 'true') {
      index += 1;
    }

    if (rawKey === 'page-path') {
      parsed['page-path'].push(value);
      continue;
    }

    parsed[rawKey] = value;
  }
  return parsed;
}

function requiredSetting(cli, flag, envVar) {
  const value = cli[flag] ?? process.env[envVar];
  if (!value) {
    throw new Error(`Missing required setting: --${flag} / ${envVar}`);
  }
  return String(value);
}

function resolvePagePaths(cli) {
  if (cli['page-path'].length > 0) {
    return cli['page-path'];
  }
  const raw = process.env.HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON;
  if (!raw) {
    throw new Error('Missing required setting: --page-path / HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON');
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    throw new Error(`HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON must be valid JSON: ${error.message}`);
  }
  if (!Array.isArray(parsed) || parsed.some((item) => typeof item !== 'string' || item.trim() === '')) {
    throw new Error('HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON must be a JSON array of paths.');
  }
  return parsed;
}

function parseViewport(value) {
  const [width, height] = String(value).toLowerCase().split('x').map((part) => Number(part));
  if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
    throw new Error(`Invalid viewport: ${value}`);
  }
  return { width, height, device_scale_factor: 1 };
}

function parseBoolean(value) {
  if (['1', 'true', 'yes'].includes(String(value).toLowerCase())) {
    return true;
  }
  if (['0', 'false', 'no'].includes(String(value).toLowerCase())) {
    return false;
  }
  throw new Error(`Invalid boolean value: ${value}`);
}

function safeScreenshotName(pagePath) {
  const normalized = String(pagePath).replace(/^\/+/, '').replace(/\/+$/, '') || 'index';
  return normalized.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'index';
}

async function fileExists(filePath) {
  try {
    return (await stat(filePath)).isFile();
  } catch {
    return false;
  }
}

function printHelp() {
  process.stdout.write(`Capture PNG screenshots for static artifact-origin HTML pages.\n\nEnvironment:\n  HOMEBOY_SCREENSHOT_BASE_URL          Static artifact origin base URL.\n  HOMEBOY_SCREENSHOT_PAGE_PATHS_JSON   JSON array of page paths to capture.\n  HOMEBOY_SCREENSHOT_OUTPUT_DIR        Directory where PNG screenshots are written.\n  HOMEBOY_SCREENSHOT_VIEWPORT          Optional viewport, default ${DEFAULT_VIEWPORT.width}x${DEFAULT_VIEWPORT.height}.\n  HOMEBOY_SCREENSHOT_FULL_PAGE         Optional boolean, default true.\n  HOMEBOY_SCREENSHOT_WAIT_UNTIL        Optional Playwright waitUntil value, default load.\n\nFlags override matching environment values:\n  --base-url=<url>                     Static artifact origin base URL.\n  --page-path=<path>                   Page path to capture; repeat for multiple pages.\n  --output-dir=<dir>                   Directory where PNG screenshots are written.\n  --viewport=<width>x<height>          Browser viewport.\n  --full-page=<true|false>             Capture the full scrollable page.\n  --wait-until=<state>                 Playwright navigation waitUntil value.\n\nOutput:\n  JSON screenshot manifest on stdout. PNG files are written to --output-dir.\n`);
}
