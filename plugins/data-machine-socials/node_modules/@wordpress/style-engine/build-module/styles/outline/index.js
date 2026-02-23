/**
 * Internal dependencies
 */

import { generateRule } from '../utils';
const color = {
  name: 'color',
  generate: (style, options, path = ['outline', 'color'], ruleKey = 'outlineColor') => {
    return generateRule(style, options, path, ruleKey);
  }
};
const offset = {
  name: 'offset',
  generate: (style, options, path = ['outline', 'offset'], ruleKey = 'outlineOffset') => {
    return generateRule(style, options, path, ruleKey);
  }
};
const outlineStyle = {
  name: 'style',
  generate: (style, options, path = ['outline', 'style'], ruleKey = 'outlineStyle') => {
    return generateRule(style, options, path, ruleKey);
  }
};
const width = {
  name: 'width',
  generate: (style, options, path = ['outline', 'width'], ruleKey = 'outlineWidth') => {
    return generateRule(style, options, path, ruleKey);
  }
};
export default [color, outlineStyle, offset, width];
//# sourceMappingURL=index.js.map