import { createWorker } from '../dist/index.js';

const input = '<section><h2>x</h2><p>y</p></section>';
const worker = createWorker();

function stringifyResult(result) {
  return JSON.stringify(result, (_key, value) => (value === Infinity ? 'Infinity' : value));
}

try {
  const out = await worker.rawConvert([input]);
  const first = out[0];

  if (!first || typeof first.html !== 'string' || !first.html.includes('<!-- wp:')) {
    console.error(`FAIL ${stringifyResult(first)}`);
    process.exitCode = 1;
  } else {
    console.log('OK');
  }
} finally {
  await worker.stop();
}
