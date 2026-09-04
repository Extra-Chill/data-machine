import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { BlocksEngineError } from '../errors.js';
import { ingest, slugFromRelPath } from '../theme/ingest.js';

const fixtureRoot = join(import.meta.dirname, 'fixtures/site');

function withTempDir<T>(fn: (dir: string) => T): T {
  const dir = mkdtempSync(join(tmpdir(), 'blocks-engine-theme-ingest-'));
  try {
    return fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function writeHtml(path: string, title: string): void {
  writeFileSync(
    path,
    `<!doctype html><html><head><title>${title}</title></head><body data-page="${title.toLowerCase()}"></body></html>`,
    'utf8'
  );
}

describe('theme ingest', () => {
  it('ingests fixture pages with stable slugs and body data', () => {
    const site = ingest(fixtureRoot);

    expect(site.root).toBe(fixtureRoot);
    expect(site.pages.map((page) => page.slug)).toEqual(['about', 'home', 'services']);

    const home = site.pages.find((page) => page.slug === 'home');
    const about = site.pages.find((page) => page.slug === 'about');
    const services = site.pages.find((page) => page.slug === 'services');

    expect(home).toMatchObject({
      relPath: 'index.html',
      title: 'Home',
      bodyData: {
        page: 'home',
        template: 'front',
      },
    });
    expect(home?.html).toContain('Build calmer block themes');
    expect(home?.html).toContain('<header>');
    expect(home?.html).toContain('<footer>');

    expect(about).toMatchObject({
      relPath: 'about.html',
      title: 'About',
      bodyData: {
        page: 'about',
      },
    });
    expect(about?.html).toContain('About the assembler');
    expect(about?.html).toContain('<header>');
    expect(about?.html).toContain('<footer>');

    expect(services).toMatchObject({
      relPath: 'services.html',
      title: 'Services',
      bodyData: {
        page: 'services',
        template: 'services',
      },
    });
    expect(services?.html).toContain('Service design for block themes');
    expect(services?.html).toContain('Blocks Engine Services');
  });

  it('maps nested paths to DLA-compatible slugs', () => {
    expect(slugFromRelPath(join('index.html'))).toBe('home');
    expect(slugFromRelPath(join('blog', 'index.html'))).toBe('blog');
    expect(slugFromRelPath(join('blog', 'Launch Notes.html'))).toBe('blog-launch-notes');
    expect(slugFromRelPath(join('shop', '2026', 'index.htm'))).toBe('shop-2026');
    expect(slugFromRelPath(join('###.html'))).toBe('home');
  });

  it('throws BlocksEngineError on duplicate slug collisions', () => {
    withTempDir((dir) => {
      mkdirSync(join(dir, 'blog'));
      writeHtml(join(dir, 'blog', 'p.html'), 'Nested');
      writeHtml(join(dir, 'blog-p.html'), 'Flat');

      let error: unknown;
      try {
        ingest(dir);
      } catch (caught) {
        error = caught;
      }

      expect(error).toBeInstanceOf(BlocksEngineError);
      expect(error).toMatchObject({
        code: 'THEME_INGEST_DUPLICATE_SLUG',
      });
      expect((error as Error).message).toContain('slug collision: "blog-p"');
    });
  });

  it('throws BlocksEngineError when no eligible html pages are found', () => {
    withTempDir((dir) => {
      writeFileSync(join(dir, 'readme.txt'), 'not html', 'utf8');
      mkdirSync(join(dir, '.hidden'));
      writeHtml(join(dir, '.hidden', 'index.html'), 'Hidden');
      mkdirSync(join(dir, 'node_modules'));
      writeHtml(join(dir, 'node_modules', 'index.html'), 'Dependency');

      let error: unknown;
      try {
        ingest(dir);
      } catch (caught) {
        error = caught;
      }

      expect(error).toBeInstanceOf(BlocksEngineError);
      expect(error).toMatchObject({
        code: 'THEME_INGEST_NO_HTML',
      });
      expect((error as Error).message).toContain(`no html pages found under ${dir}`);
    });
  });
});
