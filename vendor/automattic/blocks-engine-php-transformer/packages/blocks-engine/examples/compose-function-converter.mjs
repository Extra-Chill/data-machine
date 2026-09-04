import { compose } from '@automattic/blocks-engine';

const html = '<aside data-block="source-callout"><p>Ship smaller examples.</p></aside>';

const calloutConverter = (sourceHtml, ctx) => {
  if (!sourceHtml.includes('data-block="source-callout"')) {
    return null;
  }

  const sourceHost = new URL(ctx.url).hostname;

  return [
    '<!-- wp:quote {"className":"source-callout"} -->',
    '<blockquote class="wp-block-quote source-callout">',
    '<p>Ship smaller examples.</p>',
    `<cite>Imported from ${sourceHost}</cite>`,
    '</blockquote>',
    '<!-- /wp:quote -->',
  ].join('\n');
};

const blockMarkup = compose(
  html,
  { url: 'https://example.com/source.html' },
  { converters: [calloutConverter] },
);

console.log(blockMarkup);
