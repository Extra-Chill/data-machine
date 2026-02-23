/**
 * Internal dependencies
 */
import { kebabCase } from './utils/strings';
import { lock } from './lock-unlock';

/**
 * Private @wordpress/components APIs.
 */
export const privateApis = {};
lock(privateApis, {
  kebabCase
});
//# sourceMappingURL=private-apis.native.js.map