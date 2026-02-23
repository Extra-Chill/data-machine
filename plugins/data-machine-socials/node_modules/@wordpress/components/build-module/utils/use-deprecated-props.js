export function useDeprecated36pxDefaultSizeProp(props) {
  const {
    __next36pxDefaultSize,
    __next40pxDefaultSize,
    ...otherProps
  } = props;
  return {
    ...otherProps,
    __next40pxDefaultSize: __next40pxDefaultSize !== null && __next40pxDefaultSize !== void 0 ? __next40pxDefaultSize : __next36pxDefaultSize
  };
}
//# sourceMappingURL=use-deprecated-props.js.map