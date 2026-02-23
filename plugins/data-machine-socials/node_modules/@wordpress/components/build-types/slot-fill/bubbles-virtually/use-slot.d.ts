import type { SlotFillBubblesVirtuallyFillRef, SlotFillBubblesVirtuallySlotRef, FillProps, SlotKey } from '../types';
export default function useSlot(name: SlotKey): {
    updateSlot: (fillProps: FillProps) => void;
    unregisterSlot: (ref: SlotFillBubblesVirtuallySlotRef) => void;
    registerFill: (ref: SlotFillBubblesVirtuallyFillRef) => void;
    unregisterFill: (ref: SlotFillBubblesVirtuallyFillRef) => void;
    ref?: SlotFillBubblesVirtuallySlotRef | undefined;
    fillProps?: FillProps | undefined;
};
//# sourceMappingURL=use-slot.d.ts.map