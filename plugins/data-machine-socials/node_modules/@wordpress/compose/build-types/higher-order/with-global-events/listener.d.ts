export default Listener;
/**
 * Class responsible for orchestrating event handling on the global window,
 * binding a single event to be shared across all handling instances, and
 * removing the handler when no instances are listening for the event.
 */
declare class Listener {
    /** @type {any} */
    listeners: any;
    handleEvent(event: any): void;
    add(eventType: any, instance: any): void;
    remove(eventType: any, instance: any): void;
}
//# sourceMappingURL=listener.d.ts.map