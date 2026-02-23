/**
 * External dependencies
 */

export function serializeGradientColor({
  type,
  value
}) {
  if (type === 'literal') {
    return value;
  }
  if (type === 'hex') {
    return `#${value}`;
  }
  return `${type}(${value.join(',')})`;
}
export function serializeGradientPosition(position) {
  if (!position) {
    return '';
  }
  const {
    value,
    type
  } = position;
  return `${value}${type}`;
}
export function serializeGradientColorStop({
  type,
  value,
  length
}) {
  return `${serializeGradientColor({
    type,
    value
  })} ${serializeGradientPosition(length)}`;
}
export function serializeGradientOrientation(orientation) {
  if (Array.isArray(orientation) || !orientation || orientation.type !== 'angular') {
    return;
  }
  return `${orientation.value}deg`;
}
export function serializeGradient({
  type,
  orientation,
  colorStops
}) {
  const serializedOrientation = serializeGradientOrientation(orientation);
  const serializedColorStops = colorStops.sort((colorStop1, colorStop2) => {
    const getNumericStopValue = colorStop => {
      return colorStop?.length?.value === undefined ? 0 : parseInt(colorStop.length.value);
    };
    return getNumericStopValue(colorStop1) - getNumericStopValue(colorStop2);
  }).map(serializeGradientColorStop);
  return `${type}(${[serializedOrientation, ...serializedColorStops].filter(Boolean).join(',')})`;
}
//# sourceMappingURL=serializer.js.map