var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  // If the importer is in node compatibility mode or this is not an ESM
  // file that has been converted to a CommonJS file using a Babel-
  // compatible transform (i.e. "__esModule" has not been set), then set
  // "default" to the CommonJS "module.exports" for node compatibility.
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// packages/theme/src/stylelint-plugins/no-unknown-ds-tokens.mjs
var no_unknown_ds_tokens_exports = {};
__export(no_unknown_ds_tokens_exports, {
  default: () => no_unknown_ds_tokens_default
});
module.exports = __toCommonJS(no_unknown_ds_tokens_exports);
var import_stylelint = __toESM(require("stylelint"), 1);
var import_design_tokens = __toESM(require("../prebuilt/js/design-tokens.mjs"), 1);
var DS_TOKEN_PREFIX = "wpds-";
function extractCSSVariables(value, prefix = "") {
  const regex = /--[\w-]+/g;
  const variables = /* @__PURE__ */ new Set();
  let match;
  while ((match = regex.exec(value)) !== null) {
    const variableName = match[0];
    if (variableName.startsWith(`--${prefix}`)) {
      variables.add(variableName);
    }
  }
  return variables;
}
var knownTokens = new Set(import_design_tokens.default);
var wpdsTokensRegex = new RegExp(`[^\\w]--${DS_TOKEN_PREFIX}`, "i");
var {
  createPlugin,
  utils: { report, ruleMessages, validateOptions }
} = import_stylelint.default;
var ruleName = "plugin-wpds/no-unknown-ds-tokens";
var messages = ruleMessages(ruleName, {
  rejected: (tokenNames) => `The following CSS variables are not valid Design System tokens: ${tokenNames}`
});
var ruleFunction = (primary) => {
  return (root, result) => {
    const validOptions = validateOptions(result, ruleName, {
      actual: primary,
      possible: [true]
    });
    if (!validOptions) {
      return;
    }
    root.walkDecls((ruleNode) => {
      const { value } = ruleNode;
      if (wpdsTokensRegex.test(value)) {
        const usedTokens = extractCSSVariables(
          value,
          DS_TOKEN_PREFIX
        );
        const unknownTokens = new Set(
          [...usedTokens].filter(
            (token) => !knownTokens.has(token)
          )
        );
        if (unknownTokens.size > 0) {
          report({
            message: messages.rejected(
              Array.from(unknownTokens).map((token) => `'${token}'`).join(", ")
            ),
            node: ruleNode,
            result,
            ruleName
          });
        }
      }
    });
  };
};
ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
var no_unknown_ds_tokens_default = createPlugin(ruleName, ruleFunction);
//# sourceMappingURL=no-unknown-ds-tokens.cjs.map
