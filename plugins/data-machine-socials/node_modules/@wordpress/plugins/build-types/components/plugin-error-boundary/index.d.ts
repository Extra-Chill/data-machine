export class PluginErrorBoundary extends Component<any, any, any> {
    static getDerivedStateFromError(): {
        hasError: boolean;
    };
    /**
     * @param {Object} props
     */
    constructor(props: Object);
    state: {
        hasError: boolean;
    };
    /**
     * @param {Error} error Error object passed by React.
     */
    componentDidCatch(error: Error): void;
    render(): any;
}
import { Component } from '@wordpress/element';
//# sourceMappingURL=index.d.ts.map