/**
 * WordPress dependencies
 */
import { useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import * as styles from '../styles';
import { getBorderDiff, getCommonBorder, getSplitBorders, hasMixedBorders, hasSplitBorders, isCompleteBorder, isEmptyBorder } from '../utils';
import { useContextSystem } from '../../context';
import { useCx } from '../../utils/hooks/use-cx';
export function useBorderBoxControl(props) {
  const {
    className,
    colors = [],
    onChange,
    enableAlpha = false,
    enableStyle = true,
    size = 'default',
    value,
    __experimentalIsRenderedInSidebar = false,
    __next40pxDefaultSize,
    ...otherProps
  } = useContextSystem(props, 'BorderBoxControl');
  const computedSize = size === 'default' && __next40pxDefaultSize ? '__unstable-large' : size;
  const mixedBorders = hasMixedBorders(value);
  const splitBorders = hasSplitBorders(value);
  const linkedValue = splitBorders ? getCommonBorder(value) : value;
  const splitValue = splitBorders ? value : getSplitBorders(value);

  // If no numeric width value is set, the unit select will be disabled.
  const hasWidthValue = !isNaN(parseFloat(`${linkedValue?.width}`));
  const [isLinked, setIsLinked] = useState(!mixedBorders);
  const toggleLinked = () => setIsLinked(!isLinked);
  const onLinkedChange = newBorder => {
    if (!newBorder) {
      return onChange(undefined);
    }

    // If we have all props defined on the new border apply it.
    if (!mixedBorders || isCompleteBorder(newBorder)) {
      return onChange(isEmptyBorder(newBorder) ? undefined : newBorder);
    }

    // If we had mixed borders we might have had some shared border props
    // that we need to maintain. For example; we could have mixed borders
    // with all the same color but different widths. Then from the linked
    // control we change the color. We should keep the separate  widths.
    const changes = getBorderDiff(linkedValue, newBorder);
    const updatedBorders = {
      top: {
        ...value?.top,
        ...changes
      },
      right: {
        ...value?.right,
        ...changes
      },
      bottom: {
        ...value?.bottom,
        ...changes
      },
      left: {
        ...value?.left,
        ...changes
      }
    };
    if (hasMixedBorders(updatedBorders)) {
      return onChange(updatedBorders);
    }
    const filteredResult = isEmptyBorder(updatedBorders.top) ? undefined : updatedBorders.top;
    onChange(filteredResult);
  };
  const onSplitChange = (newBorder, side) => {
    const updatedBorders = {
      ...splitValue,
      [side]: newBorder
    };
    if (hasMixedBorders(updatedBorders)) {
      onChange(updatedBorders);
    } else {
      onChange(newBorder);
    }
  };
  const cx = useCx();
  const classes = useMemo(() => {
    return cx(styles.borderBoxControl, className);
  }, [cx, className]);
  const linkedControlClassName = useMemo(() => {
    return cx(styles.linkedBorderControl());
  }, [cx]);
  const wrapperClassName = useMemo(() => {
    return cx(styles.wrapper);
  }, [cx]);
  return {
    ...otherProps,
    className: classes,
    colors,
    disableUnits: mixedBorders && !hasWidthValue,
    enableAlpha,
    enableStyle,
    hasMixedBorders: mixedBorders,
    isLinked,
    linkedControlClassName,
    onLinkedChange,
    onSplitChange,
    toggleLinked,
    linkedValue,
    size: computedSize,
    splitValue,
    wrapperClassName,
    __experimentalIsRenderedInSidebar
  };
}
//# sourceMappingURL=hook.js.map