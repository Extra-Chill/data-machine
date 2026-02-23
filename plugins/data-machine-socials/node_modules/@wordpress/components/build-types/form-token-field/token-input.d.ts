import type { ForwardedRef } from 'react';
/**
 * Internal dependencies
 */
import type { WordPressComponentProps } from '../context';
import type { TokenInputProps } from './types';
export declare function UnForwardedTokenInput(props: WordPressComponentProps<TokenInputProps, 'input', false>, ref: ForwardedRef<HTMLInputElement>): import("react").JSX.Element;
export declare const TokenInput: import("react").ForwardRefExoticComponent<TokenInputProps & Omit<Omit<import("react").DetailedHTMLProps<import("react").InputHTMLAttributes<HTMLInputElement>, HTMLInputElement>, "ref">, "children" | "as" | keyof TokenInputProps> & import("react").RefAttributes<HTMLInputElement>>;
export default TokenInput;
//# sourceMappingURL=token-input.d.ts.map