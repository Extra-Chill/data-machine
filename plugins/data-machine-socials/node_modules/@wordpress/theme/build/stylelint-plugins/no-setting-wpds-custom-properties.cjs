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

// packages/theme/src/stylelint-plugins/no-setting-wpds-custom-properties.mjs
var no_setting_wpds_custom_properties_exports = {};
__export(no_setting_wpds_custom_properties_exports, {
  default: () => no_setting_wpds_custom_properties_default
});
module.exports = __toCommonJS(no_setting_wpds_custom_properties_exports);
var import_stylelint = __toESM(require("stylelint"), 1);
var {
  createPlugin,
  utils: { report, ruleMessages, validateOptions }
} = import_stylelint.default;
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
//# sourceMappingURL=no-setting-wpds-custom-properties.cjs.map
