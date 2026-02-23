/**
 * A constructor (factory) for `ObservableMap`, a map-like key/value data structure
 * where the individual entries are observable: using the `subscribe` method, you can
 * subscribe to updates for a particular keys. Each subscriber always observes one
 * specific key and is not notified about any unrelated changes (for different keys)
 * in the `ObservableMap`.
 *
 * @template K The type of the keys in the map.
 * @template V The type of the values in the map.
 * @return   A new instance of the `ObservableMap` type.
 */
export function observableMap() {
  const map = new Map();
  const listeners = new Map();
  function callListeners(name) {
    const list = listeners.get(name);
    if (!list) {
      return;
    }
    for (const listener of list) {
      listener();
    }
  }
  return {
    get(name) {
      return map.get(name);
    },
    set(name, value) {
      map.set(name, value);
      callListeners(name);
    },
    delete(name) {
      map.delete(name);
      callListeners(name);
    },
    subscribe(name, listener) {
      let list = listeners.get(name);
      if (!list) {
        list = new Set();
        listeners.set(name, list);
      }
      list.add(listener);
      return () => {
        list.delete(listener);
        if (list.size === 0) {
          listeners.delete(name);
        }
      };
    }
  };
}
//# sourceMappingURL=index.js.map