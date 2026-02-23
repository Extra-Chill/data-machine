import type { SerializedStyles } from '@emotion/serialize';
import type { ClassNamesArg } from '@emotion/css';
/**
 * Retrieve a `cx` function that knows how to handle `SerializedStyles`
 * returned by the `@emotion/react` `css` function in addition to what
 * `cx` normally knows how to handle. It also hooks into the Emotion
 * Cache, allowing `css` calls to work inside iframes.
 *
 * ```jsx
 * import { css } from '@emotion/react';
 *
 * const styles = css`
 * 	color: red
 * `;
 *
 * function RedText( { className, ...props } ) {
 * 	const cx = useCx();
 *
 * 	const classes = cx(styles, className);
 *
 * 	return <span className={classes} {...props} />;
 * }
 * ```
 */
export declare const useCx: () => (...classNames: (ClassNamesArg | SerializedStyles)[]) => string;
//# sourceMappingURL=use-cx.d.ts.map