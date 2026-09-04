export const DLA_VARIATION_HOIST_COMMIT = '1e393c535850ee1a9482f83459f779d0e225b027';
export const DLA_VARIATION_HOIST_PATH = 'src/lib/replicate/variation-hoist.ts';
export const DLA_VARIATION_HOIST_BLOB = '62b9cf4c87b62dfc03bffe2e25ffbe4e6109438b';
export const VARIATION_HOIST_DERIVATION =
  `Mechanically generated from DLA ${DLA_VARIATION_HOIST_PATH} at commit ${DLA_VARIATION_HOIST_COMMIT} with blob ${DLA_VARIATION_HOIST_BLOB}.`;

const commentEscapeName =
  'Alpha \\u002d\\u002d \\u003cunsafe\\u003e \\u0026 \\u0022quoted\\u0022 value';

function paragraph(attrs, body) {
  return `<!-- wp:paragraph ${attrs} -->\n<p>${body}</p>\n<!-- /wp:paragraph -->`;
}

function group(attrs, body) {
  return `<!-- wp:group ${attrs} -->\n<div class="wp-block-group">${body}</div>\n<!-- /wp:group -->`;
}

function jetpack(attrs, body) {
  return `<!-- wp:jetpack/contact-form ${attrs} -->\n<div class="wp-block-jetpack-contact-form">${body}</div>\n<!-- /wp:jetpack/contact-form -->`;
}

export function variationHoistCases() {
  const paragraphStyleA = '{"style":{"color":{"text":"#123456"},"spacing":{"padding":{"top":"16px","bottom":"16px"}}}}';
  const paragraphStyleB = '{"style":{"spacing":{"padding":{"bottom":"16px","top":"16px"}},"color":{"text":"#123456"}}}';
  const groupEscapeAttrs =
    `{"metadata":{"name":"${commentEscapeName}"},"style":{"border":{"color":"#333333","width":"2px"}}}`;
  const jetpackAttrs = '{"style":{"color":{"background":"#f0f0f0"}}}';

  return [
    {
      id: 'core-paragraph-hoists-three-matches',
      pages: [
        { slug: 'home', markup: paragraph(paragraphStyleA, 'Alpha') },
        { slug: 'about', markup: paragraph(paragraphStyleB, 'Beta') },
        { slug: 'services', markup: paragraph(paragraphStyleA, 'Gamma') },
      ],
      swapMarkup: paragraph(paragraphStyleB, 'Pattern copy'),
    },
    {
      id: 'applies-existing-class-swap',
      pages: [
        {
          slug: 'one',
          markup: paragraph('{"className":"lead","style":{"typography":{"fontSize":"18px"}}}', 'One'),
        },
        {
          slug: 'two',
          markup: paragraph('{"className":"lead","style":{"typography":{"fontSize":"18px"}}}', 'Two'),
        },
      ],
      options: { minInstances: 2 },
      swapMarkup: paragraph('{"className":"pattern","style":{"typography":{"fontSize":"18px"}}}', 'Reusable pattern'),
    },
    {
      id: 'jetpack-styled-blocks-are-excluded',
      pages: [
        { slug: 'contact-a', markup: jetpack(jetpackAttrs, 'A') },
        { slug: 'contact-b', markup: jetpack(jetpackAttrs, 'B') },
        { slug: 'contact-c', markup: jetpack(jetpackAttrs, 'C') },
      ],
      swapMarkup: jetpack(jetpackAttrs, 'Pattern contact'),
    },
    {
      id: 'serialize-block-attrs-escapes-comment-delimiters',
      pages: [
        { slug: 'safe-a', markup: group(groupEscapeAttrs, '<p>A</p>') },
        { slug: 'safe-b', markup: group(groupEscapeAttrs, '<p>B</p>') },
        { slug: 'safe-c', markup: group(groupEscapeAttrs, '<p>C</p>') },
      ],
      swapMarkup: group(groupEscapeAttrs, '<p>Pattern</p>'),
    },
  ];
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

export function runVariationHoistParity(impl) {
  return {
    version: 1,
    derivation: VARIATION_HOIST_DERIVATION,
    oracle: {
      commit: DLA_VARIATION_HOIST_COMMIT,
      path: DLA_VARIATION_HOIST_PATH,
      blob: DLA_VARIATION_HOIST_BLOB,
    },
    cases: variationHoistCases().map((entry) => {
      const result = impl.hoistVariations(clone(entry.pages), entry.options ?? {});
      return {
        id: entry.id,
        options: entry.options ?? {},
        variations: result.variations,
        pages: result.pages,
        swappedMarkup: impl.applyHoistSwaps(entry.swapMarkup, result.variations),
      };
    }),
  };
}
