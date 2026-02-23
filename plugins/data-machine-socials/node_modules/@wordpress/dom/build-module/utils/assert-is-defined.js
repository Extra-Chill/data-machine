export function assertIsDefined(val, name) {
  if (process.env.NODE_ENV !== 'production' && (val === undefined || val === null)) {
    throw new Error(`Expected '${name}' to be defined, but received ${val}`);
  }
}
//# sourceMappingURL=assert-is-defined.js.map