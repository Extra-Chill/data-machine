import { convert } from '@automattic/blocks-engine';

const html = '<h2>Hi</h2><p>Body</p>';
const blockMarkup = await convert(html, { url: 'https://example.com/page.html' });

console.log(blockMarkup);
