/**
 * Internal dependencies
 */

import { generateBoxRules } from '../utils';
const padding = {
  name: 'padding',
  generate: (style, options) => {
    return generateBoxRules(style, options, ['spacing', 'padding'], {
      default: 'padding',
      individual: 'padding%s'
    });
  }
};
export default padding;
//# sourceMappingURL=padding.js.map