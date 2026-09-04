import { describe, expect, it } from 'vitest';

import type { SiteToThemeOptions } from '../theme/index.js';
import {
  appendCarriedSourceCss,
  hasCarriedSourceCss,
  shouldCarrySourceCss,
} from '../theme/source-css-carry.js';

describe('source CSS carry contract', () => {
  it('carries non-empty source CSS by default and supports an explicit consumer opt-out', () => {
    const defaultOptions = {} satisfies SiteToThemeOptions;
    const optOutOptions = { carrySourceCss: false } satisfies SiteToThemeOptions;

    expect(shouldCarrySourceCss('.ftp-sentinel{max-width:960px}', defaultOptions)).toBe(true);
    expect(shouldCarrySourceCss('.ftp-sentinel{max-width:960px}', optOutOptions)).toBe(false);
    expect(shouldCarrySourceCss('   ', defaultOptions)).toBe(false);
  });

  it('preserves carried CSS bytes when appending to the theme stylesheet', () => {
    const sourceCss = '/* source */\n.ftp-sentinel{max-width:960px}\n';

    expect(hasCarriedSourceCss(sourceCss)).toBe(true);
    expect(appendCarriedSourceCss('/* theme */\n', sourceCss)).toBe(
      '/* theme */\n/* source */\n.ftp-sentinel{max-width:960px}\n'
    );
  });
});
