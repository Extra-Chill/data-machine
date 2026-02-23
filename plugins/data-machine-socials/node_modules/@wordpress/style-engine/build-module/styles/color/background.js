/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const background = {
  name: 'background',
  generate: (style, options) => {
    return generateRule(style, options, ['color', 'background'], 'backgroundColor');
  }
};
export default background;
//# sourceMappingURL=background.js.map