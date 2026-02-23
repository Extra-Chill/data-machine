/**
 * @augments Observable<string>
 */ export class HttpSignalingConn extends Observable<string> {
    /**
     * @param {string} url
     */
    constructor(url: string);
    url: string;
    /**
     * @type {WebSocket?}
     */
    ws: WebSocket | null;
    binaryType: any;
    connected: boolean;
    connecting: boolean;
    unsuccessfulReconnects: number;
    lastMessageReceived: number;
    /**
     * Whether to connect to other peers or not
     *
     * @type {boolean}
     */
    shouldConnect: boolean;
    _checkInterval: NodeJS.Timeout;
    /**
     * @type {Set<WebrtcProvider>}
     */
    providers: Set<WebrtcProvider>;
    /**
     * @param {any} message
     */
    send(message: any): void;
    disconnect(): void;
    connect(): void;
}
export class WebrtcProviderWithHttpSignaling extends WebrtcProvider {
}
import { Observable } from 'lib0/observable';
import { WebrtcProvider } from './y-webrtc/y-webrtc';
//# sourceMappingURL=webrtc-http-stream-signaling.d.ts.map