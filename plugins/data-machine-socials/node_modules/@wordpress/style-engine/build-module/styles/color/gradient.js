/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const gradient = {
  name: 'gradient',
  generate: (style, options) => {
    return generateRule(style, options, ['color', 'gradient'], 'background');
  }
};
export default gradient;
//# sourceMappingURL=gradient.js.map