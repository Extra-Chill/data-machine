// packages/theme/src/stylelint-plugins/no-setting-wpds-custom-properties.mjs
import stylelint from "stylelint";
var {
  createPlugin,
  utils: { report, ruleMessages, validateOptions }
} = stylelint;
var ruleName = "plugin-wpds/no-setting-wpds-custom-properties";
var messages = ruleMessages(ruleName, {
  rejected: () => `Do not set CSS custom properties using the Design System tokens namespace (i.e. beginning with --wpds-*).`
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
      const { prop } = ruleNode;
      if (prop.startsWith("--wpds-")) {
        report({
          message: messages.rejected(),
          node: ruleNode,
          result,
          ruleName
        });
      }
    });
  };
};
ruleFunction.ruleName = ruleName;
ruleFunction.messages = messages;
var no_setting_wpds_custom_properties_default = createPlugin(ruleName, ruleFunction);
export {
  no_setting_wpds_custom_properties_default as default
};
//# sourceMappingURL=no-setting-wpds-custom-properties.mjs.map
