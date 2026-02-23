/**
 * WordPress dependencies
 */
import { createUndoManager } from '@wordpress/undo-manager';
import { useCallback, useReducer } from '@wordpress/element';
function undoRedoReducer(state, action) {
  switch (action.type) {
    case 'UNDO':
      {
        const undoRecord = state.manager.undo();
        if (undoRecord) {
          return {
            ...state,
            value: undoRecord[0].changes.prop.from
          };
        }
        return state;
      }
    case 'REDO':
      {
        const redoRecord = state.manager.redo();
        if (redoRecord) {
          return {
            ...state,
            value: redoRecord[0].changes.prop.to
          };
        }
        return state;
      }
    case 'RECORD':
      {
        state.manager.addRecord([{
          id: 'object',
          changes: {
            prop: {
              from: state.value,
              to: action.value
            }
          }
        }], action.isStaged);
        return {
          ...state,
          value: action.value
        };
      }
  }
  return state;
}
function initReducer(value) {
  return {
    manager: createUndoManager(),
    value
  };
}

/**
 * useState with undo/redo history.
 *
 * @param initialValue Initial value.
 * @return Value, setValue, hasUndo, hasRedo, undo, redo.
 */
export default function useStateWithHistory(initialValue) {
  const [state, dispatch] = useReducer(undoRedoReducer, initialValue, initReducer);
  return {
    value: state.value,
    setValue: useCallback((newValue, isStaged) => {
      dispatch({
        type: 'RECORD',
        value: newValue,
        isStaged
      });
    }, []),
    hasUndo: state.manager.hasUndo(),
    hasRedo: state.manager.hasRedo(),
    undo: useCallback(() => {
      dispatch({
        type: 'UNDO'
      });
    }, []),
    redo: useCallback(() => {
      dispatch({
        type: 'REDO'
      });
    }, [])
  };
}
//# sourceMappingURL=index.js.map