// packages/theme/src/private-apis.ts
import { lock } from "./lock-unlock.mjs";
import { ThemeProvider } from "./theme-provider.mjs";
import { useThemeProviderStyles } from "./use-theme-provider-styles.mjs";
var privateApis = {};
lock(privateApis, {
  ThemeProvider,
  useThemeProviderStyles
});
export {
  privateApis
};
//# sourceMappingURL=private-apis.mjs.map
