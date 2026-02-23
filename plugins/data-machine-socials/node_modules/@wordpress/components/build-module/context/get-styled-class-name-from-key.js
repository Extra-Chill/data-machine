/**
 * External dependencies
 */
import { paramCase as kebabCase } from 'change-case';
import memoize from 'memize';

/**
 * Generates the connected component CSS className based on the namespace.
 *
 * @param namespace The name of the connected component.
 * @return The generated CSS className.
 */
function getStyledClassName(namespace) {
  const kebab = kebabCase(namespace);
  return `components-${kebab}`;
}
export const getStyledClassNameFromKey = memoize(getStyledClassName);
//# sourceMappingURL=get-styled-class-name-from-key.js.map