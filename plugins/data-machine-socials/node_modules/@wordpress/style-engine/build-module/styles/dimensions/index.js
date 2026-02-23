/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const minHeight = {
  name: 'minHeight',
  generate: (style, options) => {
    return generateRule(style, options, ['dimensions', 'minHeight'], 'minHeight');
  }
};
const aspectRatio = {
  name: 'aspectRatio',
  generate: (style, options) => {
    return generateRule(style, options, ['dimensions', 'aspectRatio'], 'aspectRatio');
  }
};
export default [minHeight, aspectRatio];
//# sourceMappingURL=index.js.map