/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const shadow = {
  name: 'shadow',
  generate: (style, options) => {
    return generateRule(style, options, ['shadow'], 'boxShadow');
  }
};
export default [shadow];
//# sourceMappingURL=index.js.map