#!/usr/bin/env bash
# Gate 2: packs the built engine, installs it into a throwaway consumer, reports
# consumer footprint, checks that no @wordpress/* packages are installed, and
# runs a real HTML→blocks conversion smoke test to prove the bundle resolves.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "=== building ==="
pnpm build

# Pack into a temp dir so no .tgz lands in the source tree.
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
echo "WORKDIR: $WORK"

echo "=== packing ==="
TARBALL="$(pnpm pack --pack-destination "$WORK" | tail -1)"
echo "packed: $TARBALL"

cd "$WORK"
npm init -y >/dev/null

echo "=== installing tarball ==="
npm install "$TARBALL" --no-audit --no-fund

echo ""
echo "=== consumer node_modules ==="
du -sh node_modules

echo ""
echo "=== any @wordpress/* installed? (expect none) ==="
WP_DIRS="$(find node_modules -maxdepth 2 -type d -path '*/@wordpress/*' 2>/dev/null | head -20 || true)"
if [ -z "$WP_DIRS" ]; then
  echo "PASS: no @wordpress/* directories found"
else
  echo "FAIL: found @wordpress/* directories:"
  echo "$WP_DIRS"
  exit 1
fi

echo ""
echo "=== conversion smoke test (bundle mode, 0 wp:html residue) ==="
# Write a small CJS consumer that imports the /wp entry and converts a snippet.
cat > smoke.cjs <<'SMOKE'
'use strict';
// Use dynamic import so we can pull from the ESM /wp entry.
(async () => {
  // The installed package's /wp entry auto-detects dist/wp-runtime.cjs
  // when it exists alongside dist/wp/index.* — no env var needed.
  const { rawConvert, bootstrap } = await import('@automattic/blocks-engine/wp');

  bootstrap();

  // Use bare block-level elements that WordPress rawHandler maps cleanly to core blocks.
  // A <section> wrapper has no rawHandler transform and produces wp:html residue.
  const html = '<h2>Hello World</h2><p>This is a paragraph of <strong>bold</strong> content.</p><ul><li>item one</li><li>item two</li></ul>';
  const result = rawConvert(html);

  if (result.html === null) {
    console.error('FAIL: rawConvert returned null html');
    process.exitCode = 1;
    return;
  }

  if (!result.html.includes('<!-- wp:')) {
    console.error('FAIL: output contains no wp: block markup');
    console.error('output:', result.html.slice(0, 500));
    process.exitCode = 1;
    return;
  }

  if (result.wpHtmlResidue !== 0) {
    console.error(`FAIL: wpHtmlResidue is ${result.wpHtmlResidue} (expected 0)`);
    console.error('output:', result.html.slice(0, 500));
    process.exitCode = 1;
    return;
  }

  console.log(`PASS: converted ${html.length} bytes → ${result.html.length} bytes of block markup`);
  console.log(`      wpHtmlResidue=${result.wpHtmlResidue}`);
  console.log('      first 200 chars:', result.html.slice(0, 200));
})();
SMOKE

node smoke.cjs

echo ""
echo "=== done — WORKDIR: $WORK ==="
