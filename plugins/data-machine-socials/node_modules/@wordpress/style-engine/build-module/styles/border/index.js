/**
 * Internal dependencies
 */

import { generateRule, generateBoxRules, camelCaseJoin } from '../utils';

/**
 * Creates a function for generating CSS rules when the style path is the same as the camelCase CSS property used in React.
 *
 * @param path An array of strings representing the path to the style value in the style object.
 *
 * @return A function that generates CSS rules.
 */
function createBorderGenerateFunction(path) {
  return (style, options) => generateRule(style, options, path, camelCaseJoin(path));
}

/**
 * Creates a function for generating border-{top,bottom,left,right}-{color,style,width} CSS rules.
 *
 * @param edge The edge to create CSS rules for.
 *
 * @return A function that generates CSS rules.
 */
function createBorderEdgeGenerateFunction(edge) {
  return (style, options) => {
    return ['color', 'style', 'width'].flatMap(key => {
      const path = ['border', edge, key];
      return createBorderGenerateFunction(path)(style, options);
    });
  };
}
const color = {
  name: 'color',
  generate: createBorderGenerateFunction(['border', 'color'])
};
const radius = {
  name: 'radius',
  generate: (style, options) => {
    return generateBoxRules(style, options, ['border', 'radius'], {
      default: 'borderRadius',
      individual: 'border%sRadius'
    }, ['topLeft', 'topRight', 'bottomLeft', 'bottomRight']);
  }
};
const borderStyle = {
  name: 'style',
  generate: createBorderGenerateFunction(['border', 'style'])
};
const width = {
  name: 'width',
  generate: createBorderGenerateFunction(['border', 'width'])
};
const borderTop = {
  name: 'borderTop',
  generate: createBorderEdgeGenerateFunction('top')
};
const borderRight = {
  name: 'borderRight',
  generate: createBorderEdgeGenerateFunction('right')
};
const borderBottom = {
  name: 'borderBottom',
  generate: createBorderEdgeGenerateFunction('bottom')
};
const borderLeft = {
  name: 'borderLeft',
  generate: createBorderEdgeGenerateFunction('left')
};
export default [color, borderStyle, width, radius, borderTop, borderRight, borderBottom, borderLeft];
//# sourceMappingURL=index.js.map