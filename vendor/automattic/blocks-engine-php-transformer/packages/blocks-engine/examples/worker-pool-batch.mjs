import { createWorker } from '@automattic/blocks-engine';

const pages = [
  '<h2>First page</h2><p>Converted in a worker.</p>',
  '<h2>Second page</h2><p>Canonicalized as a batch.</p>',
];

function handlePoolEvent(event) {
  const child = event.childId === undefined ? '' : ` child=${event.childId}`;
  console.error(`[pool:${event.type}] count=${event.count}${child}`);
}

const pool = createWorker({
  size: 2,
  recycleAfter: 25,
  itemTimeoutMs: 5_000,
  onEvent: handlePoolEvent,
});

try {
  const rawResults = await pool.rawConvert(pages);
  const blockInputs = rawResults.map((result, index) => {
    if (result.html !== null && result.wpHtmlResidue === 0) {
      return result.html;
    }

    return `<!-- wp:html -->\n${pages[index]}\n<!-- /wp:html -->`;
  });

  const fixedResults = await pool.canonicalize(blockInputs);

  console.log(fixedResults.map((result) => result.html).join('\n\n'));
} finally {
  await pool.stop();
}
