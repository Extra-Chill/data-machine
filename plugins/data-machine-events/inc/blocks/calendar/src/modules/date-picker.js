/**
 * Flatpickr date range picker integration.
 */

import flatpickr from 'flatpickr';

const datePickers = new Map();

export function initDatePicker(calendar, onChange) {
    const dateRangeInput = calendar.querySelector('.datamachine-events-date-range-input') 
        || calendar.querySelector('[id^="datamachine-events-date-range-"]');
    
    if (!dateRangeInput) return null;

    const clearBtn = calendar.querySelector('.datamachine-events-date-clear-btn');

    const initialStart = dateRangeInput.getAttribute('data-date-start');
    const initialEnd = dateRangeInput.getAttribute('data-date-end');
    let defaultDate = undefined;
    
    if (initialStart) {
        defaultDate = initialEnd ? [initialStart, initialEnd] : initialStart;
    }

    const picker = flatpickr(dateRangeInput, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        placeholder: 'Select date range...',
        allowInput: false,
        clickOpens: true,
        defaultDate: defaultDate,
        onChange: function(selectedDates) {
            if (onChange) onChange(selectedDates);

            if (clearBtn) {
                if (selectedDates && selectedDates.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }
            }
        },
        onClear: function() {
            if (onChange) onChange([]);
            if (clearBtn) clearBtn.classList.remove('visible');
        }
    });

    const clearHandler = function() {
        picker.clear();
    };

    datePickers.set(calendar, { picker, clearBtn, clearHandler });

    if (picker.selectedDates && picker.selectedDates.length > 0 && clearBtn) {
        clearBtn.classList.add('visible');
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearHandler);
    }

    return picker;
}

export function destroyDatePicker(calendar) {
    const data = datePickers.get(calendar);
    if (data) {
        const { picker, clearBtn, clearHandler } = data;
        
        if (clearBtn && clearHandler) {
            clearBtn.removeEventListener('click', clearHandler);
        }

        if (picker) {
            try {
                picker.destroy();
            } catch (e) {
                // Ignore destruction errors
            }
        }
        datePickers.delete(calendar);
    }
}

export function getDatePicker(calendar) {
    const data = datePickers.get(calendar);
    return data ? data.picker : null;
}
