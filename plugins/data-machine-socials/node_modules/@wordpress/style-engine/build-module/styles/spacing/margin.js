/**
 * Internal dependencies
 */

import { generateBoxRules } from '../utils';
const margin = {
  name: 'margin',
  generate: (style, options) => {
    return generateBoxRules(style, options, ['spacing', 'margin'], {
      default: 'margin',
      individual: 'margin%s'
    });
  }
};
export default margin;
//# sourceMappingURL=margin.js.map