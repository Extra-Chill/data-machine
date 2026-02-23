/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
export default function useDimensionHandler(customHeight, customWidth, defaultHeight, defaultWidth, onChange) {
  var _ref, _ref2;
  const [currentWidth, setCurrentWidth] = useState((_ref = customWidth !== null && customWidth !== void 0 ? customWidth : defaultWidth) !== null && _ref !== void 0 ? _ref : '');
  const [currentHeight, setCurrentHeight] = useState((_ref2 = customHeight !== null && customHeight !== void 0 ? customHeight : defaultHeight) !== null && _ref2 !== void 0 ? _ref2 : '');

  // When an image is first inserted, the default dimensions are initially
  // undefined. This effect updates the dimensions when the default values
  // come through.
  useEffect(() => {
    if (customWidth === undefined && defaultWidth !== undefined) {
      setCurrentWidth(defaultWidth);
    }
    if (customHeight === undefined && defaultHeight !== undefined) {
      setCurrentHeight(defaultHeight);
    }
  }, [defaultWidth, defaultHeight]);

  // If custom values change, it means an outsider has resized the image using some other method (eg resize box)
  // this keeps track of these values too. We need to parse before comparing; custom values can be strings.
  useEffect(() => {
    if (customWidth !== undefined && Number.parseInt(customWidth) !== Number.parseInt(currentWidth)) {
      setCurrentWidth(customWidth);
    }
    if (customHeight !== undefined && Number.parseInt(customHeight) !== Number.parseInt(currentHeight)) {
      setCurrentHeight(customHeight);
    }
  }, [customWidth, customHeight]);
  const updateDimension = (dimension, value) => {
    const parsedValue = value === '' ? undefined : parseInt(value, 10);
    if (dimension === 'width') {
      setCurrentWidth(parsedValue);
    } else {
      setCurrentHeight(parsedValue);
    }
    onChange({
      [dimension]: parsedValue
    });
  };
  const updateDimensions = (nextHeight, nextWidth) => {
    setCurrentHeight(nextHeight !== null && nextHeight !== void 0 ? nextHeight : defaultHeight);
    setCurrentWidth(nextWidth !== null && nextWidth !== void 0 ? nextWidth : defaultWidth);
    onChange({
      height: nextHeight,
      width: nextWidth
    });
  };
  return {
    currentHeight,
    currentWidth,
    updateDimension,
    updateDimensions
  };
}
//# sourceMappingURL=use-dimension-handler.js.map