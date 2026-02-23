// packages/theme/src/stylelint-plugins/no-unknown-ds-tokens.mjs
import stylelint from "stylelint";
import tokenList from "../prebuilt/js/design-tokens.mjs";
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
var knownTokens = new Set(tokenList);
var wpdsTokensRegex = new RegExp(`[^\\w]--${DS_TOKEN_PREFIX}`, "i");
var {
  createPlugin,
  utils: { report, ruleMessages, validateOptions }
} = stylelint;
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
export {
  no_unknown_ds_tokens_default as default
};
//# sourceMappingURL=no-unknown-ds-tokens.mjs.map
