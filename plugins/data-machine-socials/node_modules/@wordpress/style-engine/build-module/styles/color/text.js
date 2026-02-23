/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const text = {
  name: 'text',
  generate: (style, options) => {
    return generateRule(style, options, ['color', 'text'], 'color');
  }
};
export default text;
//# sourceMappingURL=text.js.map