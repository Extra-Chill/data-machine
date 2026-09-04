import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import * as theme from '../theme/index.js';
import {
  collectHtmlImages,
  collectSourceAssets,
  buildNavigationAnchorCompatCss,
  localizeCssImages,
  REVEAL_NEUTRALIZER_CSS,
  rewriteHtmlImageSrcs,
  WP_COMPAT_CSS,
} from '../theme/index.js';

const DLA_WP_COMPAT_CSS = `/* wp-compat: neutralize WP wrapper interference for carried source CSS */
/* NOTE deliberately NO .wp-block-template-part{display:contents} here: our
   parts use tagName header/footer, so the wrapper IS the semantic element —
   display:contents would destroy the box that source header{}/footer{} rules
   lay out (class specificity beats the element selector regardless of order).
   NOTE also deliberately NO blanket child-margin zeroing: the source relies on
   browser-default element margins (p, h1-h6, ul) — zeroing layout children
   collapsed the source's vertical rhythm. Blocks render as the same semantic
   elements, so the defaults already match. */
:where(body) { margin: 0; }
/* WP renders site-title as a <p> (default margins the source brand <a> never
   had) and wraps tables in a margined <figure>. Zero-spec so source rules win.
   The table figure is emitted CLASSLESS (block-library's .wp-block-table
   td/th rules would out-rank source element rules), so target it via :has. */
:where(.wp-block-site-title) { margin: 0; }
:where(figure:has(> table)) { margin: 0; }
/* Structural transparency for core/navigation: the source styles nav > a
   directly, while WP renders nav > ul > li > a. Collapsing the list boxes
   makes the anchors direct flex items of <nav>, so the source nav rules
   (display/gap/wrap/justify) drive the exact same geometry. Class-level
   specificity is required — block-library sets display:flex on these at
   (0,1,0)+ and a zero-spec :where loses (probe: anchors stayed inside the
   ul, justify-content flex-start left-packed the rows). Safe: source
   stylesheets never target wp-* classes. */
nav.wp-block-navigation ul, nav.wp-block-navigation li { display: contents; }
/* WP sets .wp-block-post-content{display:flow-root}, which BLOCKS the
   margin collapse the source layout relies on (last section margin-bottom
   collapsing with the footer margin-top — walrus probe: footer sat 88px
   lower). Class-level specificity is required to beat WP's own class rule;
   safe because no source stylesheet targets a wp-* class. */
.wp-block-post-content { display: block; }
/* core/button renders TWO boxes: the source button class lands on the
   .wp-block-button WRAPPER (core/button stores className there; the fixer
   strips it off the inner link), so the carried .btn/.btn-* rules style the
   wrapper pill — while the inner .wp-block-button__link still carries BOTH WP's
   default button chrome (fill bg + padding + radius) AND the carried source
   button class the emitter writes onto the link (a .btn box-shadow of 6px 6px 0
   ink casts a hard offset shadow behind the label — the double-border bug). Strip the
   inner link's whole box (incl. box-shadow) so the carried wrapper style renders
   once. The lib-cta marker (emit-blocks) scopes this to carried
   buttons so genuine native core/button blocks keep their chrome; class-level
   specificity beats WP's :where()/global-styles button defaults, and source
   stylesheets never target wp-* classes. */
.wp-block-button.lib-cta > .wp-block-button__link {
  background: transparent;
  border: 0;
  box-shadow: none;
  padding: 0;
  border-radius: inherit;
  color: inherit;
  text-decoration: none;
}
${REVEAL_NEUTRALIZER_CSS}`;

function withTempDir<T>(fn: (dir: string) => T): T {
  const dir = mkdtempSync(join(tmpdir(), 'blocks-engine-source-assets-'));
  try {
    return fn(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

function writeFixture(dir: string, relPath: string, contents = ''): void {
  const abs = join(dir, relPath);
  mkdirSync(join(abs, '..'), { recursive: true });
  writeFileSync(abs, contents, 'utf8');
}

describe('source assets DLA parity', () => {
  it('exposes the additive source-assets public surface from theme/index', () => {
    expect(theme).toEqual(
      expect.objectContaining({
        WP_COMPAT_CSS: expect.any(String),
        collectHtmlImages: expect.any(Function),
        collectSourceAssets: expect.any(Function),
        extractGoogleFontCssUrls: expect.any(Function),
        absolutizeFontUrl: expect.any(Function),
        buildFontFaceCss: expect.any(Function),
        consolidateFontFaces: expect.any(Function),
        fontFilename: expect.any(Function),
        localizeCssImages: expect.any(Function),
        matchCapturedFamily: expect.any(Function),
        parseCapturedFontFaces: expect.any(Function),
        rewriteHtmlImageSrcs: expect.any(Function),
        stripCssSourceMaps: expect.any(Function),
        stripUnusedCarryFontFaces: expect.any(Function),
      })
    );
  });

  it('keeps the WP compat CSS byte-for-byte equal to the DLA golden string', () => {
    expect(WP_COMPAT_CSS).toBe(DLA_WP_COMPAT_CSS);
  });

  it('replays source nav anchor rules against core/navigation wrapper markup', () => {
    const css = buildNavigationAnchorCompatCss(`
.jumpnav a { padding: 0.35rem 0.85rem; color: var(--muted); text-decoration: none; }
.jumpnav a:first-child { padding-left: 0; }
.contact a { text-decoration: underline; }
`);

    expect(css).toContain(
      '.jumpnav.wp-block-navigation .wp-block-navigation-item__content, .jumpnav .wp-block-navigation .wp-block-navigation-item__content { padding: 0.35rem 0.85rem; color: var(--muted); text-decoration: none; }'
    );
    expect(css).toContain(
      '.jumpnav.wp-block-navigation .wp-block-navigation-item:first-child > .wp-block-navigation-item__content, .jumpnav .wp-block-navigation .wp-block-navigation-item:first-child > .wp-block-navigation-item__content { padding-left: 0; }'
    );
    expect(css).toContain(
      '.contact.wp-block-navigation .wp-block-navigation-item__content, .contact .wp-block-navigation .wp-block-navigation-item__content { text-decoration: underline; }'
    );
  });

  it('replays nested source nav anchor color, decoration, and border rules through core/navigation wrappers', () => {
    const css = buildNavigationAnchorCompatCss(`
.site-header .subnav a { color: #31251c; text-decoration: none; border-color: #31251c; }
.site-header .subnav a:hover { color: #8f5031; border-color: #8f5031; }
`);

    expect(css).toContain(
      '.site-header .subnav.wp-block-navigation .wp-block-navigation-item__content, .site-header .subnav .wp-block-navigation .wp-block-navigation-item__content { color: #31251c; text-decoration: none; border-color: #31251c; }'
    );
    expect(css).toContain(
      '.site-header .subnav.wp-block-navigation .wp-block-navigation-item__content:hover, .site-header .subnav .wp-block-navigation .wp-block-navigation-item__content:hover { color: #8f5031; border-color: #8f5031; }'
    );
    expect(css).not.toContain('.site-header.wp-block-navigation .subnav');
  });

  it('rewrites exact quoted HTML image refs globally using the theme asset URL', () => {
    const html =
      '<main><img src="img/logo.png"><a href="img/logo.png">Logo</a><img src=\'img/logo.png\'><span data-copy=\'img/logo.png\' data-other="icons/logo.png"></span></main>';

    expect(
      rewriteHtmlImageSrcs(
        html,
        [{ ref: 'img/logo.png', themeRel: 'assets/img/logo.png' }],
        'golden-theme'
      )
    ).toBe(
      '<main><img src="/wp-content/themes/golden-theme/assets/img/logo.png"><a href="/wp-content/themes/golden-theme/assets/img/logo.png">Logo</a><img src=\'/wp-content/themes/golden-theme/assets/img/logo.png\'><span data-copy=\'/wp-content/themes/golden-theme/assets/img/logo.png\' data-other="icons/logo.png"></span></main>'
    );
  });

  it('localizes CSS image url refs and leaves data, remote, font, missing, and escaping refs untouched', () => {
    withTempDir((dir) => {
      writeFixture(dir, 'assets/img/hero.png');
      writeFixture(dir, 'assets/img/icon.svg');
      writeFixture(dir, 'assets/fonts/inter.woff2');
      writeFixture(dir, 'root.png');

      const css = [
        '.hero { background: url("../img/hero.png"); }',
        '.root { background: url("/root.png?v=1#hash"); }',
        '.data { background: url(data:image/png;base64,abc123); }',
        '.remote { background: url(https://cdn.example.com/photo.png); }',
        '.font { src: url("../fonts/inter.woff2"); }',
        '.missing { background: url("../img/missing.png"); }',
        '.escape { background: url("../../../escape.png"); }',
        '.icon { background: url("../img/icon.svg?cache=1"); }',
      ].join('\n');

      const result = localizeCssImages([{ css, baseDir: 'assets/css' }], dir);

      expect(result.parts).toEqual([
        [
          '.hero { background: url(media/hero.png); }',
          '.root { background: url(media/root.png); }',
          '.data { background: url(data:image/png;base64,abc123); }',
          '.remote { background: url(https://cdn.example.com/photo.png); }',
          '.font { src: url("../fonts/inter.woff2"); }',
          '.missing { background: url("../img/missing.png"); }',
          '.escape { background: url("../../../escape.png"); }',
          '.icon { background: url(media/icon.svg); }',
        ].join('\n'),
      ]);
      expect(result.mediaAssets).toEqual([
        {
          srcAbs: join(dir, 'assets/img/hero.png'),
          themeRel: 'assets/css/media/hero.png',
        },
        {
          srcAbs: join(dir, 'root.png'),
          themeRel: 'assets/css/media/root.png',
        },
        {
          srcAbs: join(dir, 'assets/img/icon.svg'),
          themeRel: 'assets/css/media/icon.svg',
        },
      ]);
    });
  });

  it('collects local HTML img src refs with DLA regex and path resolution semantics', () => {
    withTempDir((dir) => {
      writeFixture(dir, 'pages/deep/img/double.png');
      writeFixture(dir, 'pages/deep/img/single.jpg');
      writeFixture(dir, 'pages/deep/img/unquoted.webp');
      writeFixture(dir, 'root.png');

      const html = [
        '<img src="img/double.png">',
        '<img alt="duplicate" src="img/double.png">',
        "<img src='img/single.jpg'>",
        '<img src=img/unquoted.webp>',
        '<img src=/root.png>',
        '<img data-src="img/data-src.png">',
        '<img xlink:href="img/xlink.png">',
        '<img src="https://cdn.example.com/remote.png">',
        '<img src="//cdn.example.com/protocol.png">',
        '<img src="data:image/png;base64,abc123">',
        '<img src="#fragment">',
        '<img src="img/missing.png">',
        '<img src="../../../escape.png">',
      ].join('');

      const result = collectHtmlImages(
        [{ html, relPath: 'pages/deep/index.html' }],
        dir
      );

      expect(result.imgAssets).toEqual([
        {
          srcAbs: join(dir, 'pages/deep/img/double.png'),
          themeRel: 'assets/img/double.png',
        },
        {
          srcAbs: join(dir, 'pages/deep/img/single.jpg'),
          themeRel: 'assets/img/single.jpg',
        },
        {
          srcAbs: join(dir, 'pages/deep/img/unquoted.webp'),
          themeRel: 'assets/img/unquoted.webp',
        },
        {
          srcAbs: join(dir, 'root.png'),
          themeRel: 'assets/img/root.png',
        },
      ]);
      expect(result.rewritesByPage).toEqual({
        'pages/deep/index.html': [
          { ref: 'img/double.png', themeRel: 'assets/img/double.png' },
          { ref: 'img/single.jpg', themeRel: 'assets/img/single.jpg' },
          { ref: 'img/unquoted.webp', themeRel: 'assets/img/unquoted.webp' },
          { ref: '/root.png', themeRel: 'assets/img/root.png' },
        ],
      });
    });
  });

  it('collects source CSS, JS, inline code, localized images, skipped stale roots, and HTML img assets from fixtures', () => {
    withTempDir((dir) => {
      writeFixture(
        dir,
        'assets/css/base.css',
        [
          '@import url("https://fonts.googleapis.com/css2?family=Inter");',
          '.base { background: url("../img/bg.png"); color: black; }',
        ].join('\n')
      );
      writeFixture(dir, 'assets/css/override.css', '.base { color: white; }');
      writeFixture(dir, 'assets/img/bg.png');
      writeFixture(dir, 'assets/js/lib.js', 'window.libLoaded = true;');
      writeFixture(dir, 'assets/js/app.js', 'window.appLoaded = true;');
      writeFixture(dir, 'content/hero.png');
      writeFixture(dir, 'root-inline.png');
      writeFixture(dir, 'stale.js', 'window.stale = true;');
      writeFixture(dir, 'style.css', '.stale { color: red; }');

      const result = collectSourceAssets(dir, [
        {
          relPath: 'index.html',
          html: [
            '<link rel="stylesheet" href="assets/css/base.css">',
            '<script src="assets/js/lib.js"></script>',
            '<style>.inline { background: url("/root-inline.png"); }</style>',
            '<script>window.inlineClassic = true;</script>',
            '<script type="application/json">{"not":"javascript"}</script>',
            '<img src="content/hero.png">',
          ].join(''),
        },
        {
          relPath: 'about.html',
          html: [
            '<link rel="stylesheet" href="assets/css/override.css">',
            '<script src="assets/js/app.js"></script>',
            '<script type="module">window.inlineModule = true;</script>',
            "<img src='content/hero.png'>",
          ].join(''),
        },
      ]);

      expect(result.cssFiles).toEqual(['assets/css/base.css', 'assets/css/override.css']);
      expect(result.jsFiles).toEqual(['assets/js/lib.js', 'assets/js/app.js']);
      expect(result.skippedUnlinked).toEqual(['stale.js', 'style.css']);
      const expectedBase =
        DLA_WP_COMPAT_CSS +
        [
          '\n.base { background: url(media/bg.png); color: black; }',
          '.base { color: white; }',
          '.inline { background: url(media/root-inline.png); }',
        ].join('\n\n');
      // Carried CSS is preserved verbatim as the prefix; the admin-bar
      // accommodation layer is appended last (no fixed/sticky rules here, so
      // only the var defs + document-bump re-assert).
      expect(result.css.startsWith(expectedBase)).toBe(true);
      expect(result.css).toContain('body.admin-bar { --wp-admin-bar-h: 32px; }');
      expect(result.css).toContain('html:has(body.admin-bar) { margin-top: 32px !important; }');
      expect(result.css).not.toContain('fonts.googleapis.com');
      expect(result.js).toBe(
        [
          'window.libLoaded = true;',
          'window.appLoaded = true;',
          '(function () { try {\nwindow.inlineClassic = true;\n} catch (e) { /* page-scoped inline chunk */ } })();',
          '(function () { try {\nwindow.inlineModule = true;\n} catch (e) { /* page-scoped inline chunk */ } })();',
        ].join('\n\n')
      );
      expect(result.js).not.toContain('{"not":"javascript"}');
      expect(result.mediaAssets).toEqual([
        {
          srcAbs: join(dir, 'assets/img/bg.png'),
          themeRel: 'assets/css/media/bg.png',
        },
        {
          srcAbs: join(dir, 'root-inline.png'),
          themeRel: 'assets/css/media/root-inline.png',
        },
      ]);
      expect(result.imgAssets).toEqual([
        {
          srcAbs: join(dir, 'content/hero.png'),
          themeRel: 'assets/img/hero.png',
        },
      ]);
      expect(result.imgRewritesByPage).toEqual({
        'about.html': [{ ref: 'content/hero.png', themeRel: 'assets/img/hero.png' }],
        'index.html': [{ ref: 'content/hero.png', themeRel: 'assets/img/hero.png' }],
      });
    });
  });
});
