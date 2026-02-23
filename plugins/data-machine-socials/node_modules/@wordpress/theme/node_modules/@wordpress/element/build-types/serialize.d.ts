/**
 * Parts of this source were derived and modified from fast-react-render,
 * released under the MIT license.
 *
 * https://github.com/alt-j/fast-react-render
 *
 * Copyright (c) 2016 Andrey Morozov
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
interface StyleObject {
    [property: string]: string | number | null | undefined;
}
interface HTMLProps {
    dangerouslySetInnerHTML?: {
        __html: string;
    };
    children?: React.ReactNode;
    value?: React.ReactNode;
    style?: StyleObject | string;
    className?: string;
    htmlFor?: string;
    [key: string]: any;
}
/**
 * Returns true if the specified string is prefixed by one of an array of
 * possible prefixes.
 * @param string
 * @param prefixes
 */
export declare function hasPrefix(string: string, prefixes: string[]): boolean;
/**
 * Serializes a React element to string.
 * @param element
 * @param context
 * @param legacyContext
 */
export declare function renderElement(element: React.ReactNode, context?: any, legacyContext?: Record<string, any>): string;
/**
 * Serializes a native component type to string.
 * @param type
 * @param props
 * @param context
 * @param legacyContext
 */
export declare function renderNativeComponent(type: string | null, props: HTMLProps, context?: any, legacyContext?: Record<string, any>): string;
/**
 * Serializes a non-native component type to string.
 * @param Component
 * @param props
 * @param context
 * @param legacyContext
 */
export declare function renderComponent(Component: React.ComponentClass, props: Record<string, any>, context?: any, legacyContext?: Record<string, any>): string;
/**
 * Renders a props object as a string of HTML attributes.
 * @param props
 */
export declare function renderAttributes(props: Record<string, any>): string;
/**
 * Renders a style object as a string attribute value.
 * @param style
 */
export declare function renderStyle(style: StyleObject | string): string | undefined;
export default renderElement;
//# sourceMappingURL=serialize.d.ts.map