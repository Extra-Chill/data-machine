import { describe, it, expect } from 'vitest';
import { buildAdminBarAccommodationCss } from '../theme/admin-bar-accommodation.js';

describe('buildAdminBarAccommodationCss', () => {
  it('always emits the responsive bar-height var and document-bump re-assert', () => {
    const out = buildAdminBarAccommodationCss('');
    expect(out).toContain('body.admin-bar { --wp-admin-bar-h: 32px; }');
    expect(out).toContain('@media screen and (max-width: 782px) { body.admin-bar { --wp-admin-bar-h: 46px; } }');
    expect(out).toContain('html:has(body.admin-bar) { margin-top: 32px !important; }');
    expect(out).toContain('@media screen and (max-width: 782px) { html:has(body.admin-bar) { margin-top: 46px !important; } }');
    expect(out.endsWith('\n')).toBe(true);
  });

  it('shifts a fixed top:0 element by the bar height (bare 0 normalized to 0px)', () => {
    const out = buildAdminBarAccommodationCss('.sidebar { position: fixed; top: 0; width: 268px; }');
    expect(out).toContain('body.admin-bar .sidebar { top: calc((0px) + var(--wp-admin-bar-h, 0px)) !important; }');
  });

  it('shifts a sticky topbar and preserves a calc() / unit top verbatim', () => {
    const css = [
      '.topbar { position: sticky; top: 0; }',
      '.toc { position: sticky; top: calc(var(--topbar-h) + 1rem); }',
      '.toggle { position: fixed; top: 0.75rem; }',
    ].join('\n');
    const out = buildAdminBarAccommodationCss(css);
    expect(out).toContain('body.admin-bar .topbar { top: calc((0px) + var(--wp-admin-bar-h, 0px)) !important; }');
    expect(out).toContain('body.admin-bar .toc { top: calc((calc(var(--topbar-h) + 1rem)) + var(--wp-admin-bar-h, 0px)) !important; }');
    expect(out).toContain('body.admin-bar .toggle { top: calc((0.75rem) + var(--wp-admin-bar-h, 0px)) !important; }');
  });

  it('drops !important and trailing decls from the source top value', () => {
    const out = buildAdminBarAccommodationCss('.h { position: fixed; top: 12px !important; z-index: 9; }');
    expect(out).toContain('body.admin-bar .h { top: calc((12px) + var(--wp-admin-bar-h, 0px)) !important; }');
    expect(out).not.toContain('!important; z-index');
  });

  it('ignores fixed/sticky elements with no top anchor or top:auto', () => {
    const css = [
      '.grain { position: fixed; inset: 0; }',
      '.foot { position: fixed; bottom: 0; }',
      '.x { position: fixed; top: auto; }',
    ].join('\n');
    const out = buildAdminBarAccommodationCss(css);
    expect(out).not.toContain('.grain');
    expect(out).not.toContain('.foot');
    expect(out).not.toContain('.x {');
  });

  it('ignores static/relative/absolute positioned elements', () => {
    const out = buildAdminBarAccommodationCss('.rel { position: relative; top: 10px; } .abs { position: absolute; top: 0; }');
    expect(out).not.toContain('.rel');
    expect(out).not.toContain('.abs');
  });

  it('scopes an override emitted inside @media to that media block', () => {
    const css = '@media screen and (max-width: 600px) { .mhdr { position: fixed; top: 0; } }';
    const out = buildAdminBarAccommodationCss(css);
    expect(out).toContain('@media screen and (max-width: 600px) {');
    expect(out).toContain('  body.admin-bar .mhdr { top: calc((0px) + var(--wp-admin-bar-h, 0px)) !important; }');
  });

  it('splits a comma selector list and skips keyframe steps', () => {
    const css = [
      '.a, .b { position: sticky; top: 0; }',
      '@keyframes slide { from { position: fixed; top: 0; } 50% { top: 10px; } }',
    ].join('\n');
    const out = buildAdminBarAccommodationCss(css);
    expect(out).toContain('body.admin-bar .a { top: calc((0px) + var(--wp-admin-bar-h, 0px)) !important; }');
    expect(out).toContain('body.admin-bar .b { top: calc((0px) + var(--wp-admin-bar-h, 0px)) !important; }');
    expect(out).not.toContain('body.admin-bar from');
    expect(out).not.toContain('body.admin-bar 50%');
  });
});
