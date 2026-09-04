import { describe, expect, it } from 'vitest';

import { detectLayoutOffsetWrapper } from './layout-offset-wrapper.js';

describe('detectLayoutOffsetWrapper', () => {
  it('returns the offset-bearing main ancestor for a fixed sidebar layout', () => {
    const html = `
      <html>
        <body>
          <div class="layout">
            <aside class="sidebar">Docs</aside>
            <div class="main-area">
              <main class="content"><h1>Docs</h1></main>
            </div>
          </div>
        </body>
      </html>
    `;
    const css = `
      .layout { display: flex; }
      .sidebar { position: fixed; width: 268px; }
      .main-area { margin-left: 268px; }
    `;

    expect(detectLayoutOffsetWrapper(html, css)).toBe('main-area');
  });

  it('returns undefined for constrained layouts even when an ancestor has margin-left', () => {
    const html = `
      <body>
        <div class="page-shell">
          <main class="content"><h1>About</h1></main>
        </div>
      </body>
    `;
    const css = '.page-shell { margin-left: 160px; }';

    expect(detectLayoutOffsetWrapper(html, css)).toBeUndefined();
  });

  it('returns undefined when fixed chrome exists but no ancestor has a horizontal offset', () => {
    const html = `
      <body>
        <div class="page-shell">
          <main class="content"><h1>About</h1></main>
        </div>
      </body>
    `;
    const css = `
      .sidebar { position: sticky; top: 0; }
      .page-shell { max-width: 900px; }
    `;

    expect(detectLayoutOffsetWrapper(html, css)).toBeUndefined();
  });
});
