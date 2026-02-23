/** @typedef {import("./types.js").RGBOptions} RGBOptions */
/** Convenience class for RGB color spaces */
export default class RGBColorSpace extends ColorSpace {
    /**
     * Creates a new RGB ColorSpace.
     * If coords are not specified, they will use the default RGB coords.
     * Instead of `fromBase()` and `toBase()` functions,
     * you can specify to/from XYZ matrices and have `toBase()` and `fromBase()` automatically generated.
     * @param {RGBOptions} options
     */
    constructor(options: RGBOptions);
}
export type RGBOptions = import("./types.js").RGBOptions;
import ColorSpace from "./ColorSpace.js";
//# sourceMappingURL=RGBColorSpace.d.ts.map