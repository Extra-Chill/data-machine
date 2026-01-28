/**
 * Flatpickr date range picker integration.
 */

/**
 * External dependencies
 */
import flatpickr from 'flatpickr';

const datePickers = new Map();

export function initDatePicker(calendar, onChange) {
    const dateRangeInput = calendar.querySelector('.datamachine-events-date-range-input')
        || calendar.querySelector('[id^="datamachine-events-date-range-"]');

    if (!dateRangeInput) {return null;}

    const dateBtn = calendar.querySelector('.datamachine-events-date-btn');
    const clearBtn = calendar.querySelector('.datamachine-events-date-clear-btn');

    const initialStart = dateRangeInput.getAttribute('data-date-start');
    const initialEnd = dateRangeInput.getAttribute('data-date-end');
    let defaultDate;

    if (initialStart) {
        defaultDate = initialEnd ? [initialStart, initialEnd] : initialStart;
    }

    const updateDateState = (selectedDates) => {
        const hasDates = selectedDates && selectedDates.length > 0;
        if (dateBtn) {
            dateBtn.classList.toggle('has-dates', hasDates);
        }
        if (clearBtn) {
            clearBtn.classList.toggle('visible', hasDates);
        }
    };

    const picker = flatpickr(dateRangeInput, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        allowInput: false,
        clickOpens: false,
        defaultDate,
        onChange(selectedDates) {
            if (onChange) {onChange(selectedDates);}
            updateDateState(selectedDates);
        },
        onClear() {
            if (onChange) {onChange([]);}
            updateDateState([]);
        }
    });

    const dateBtnHandler = function() {
        picker.open();
    };

    const clearHandler = function() {
        picker.clear();
    };

    datePickers.set(calendar, { picker, dateBtn, dateBtnHandler, clearBtn, clearHandler });

    updateDateState(picker.selectedDates);

    if (dateBtn) {
        dateBtn.addEventListener('click', dateBtnHandler);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', clearHandler);
    }

    return picker;
}

export function destroyDatePicker(calendar) {
    const data = datePickers.get(calendar);
    if (data) {
        const { picker, dateBtn, dateBtnHandler, clearBtn, clearHandler } = data;

        if (dateBtn && dateBtnHandler) {
            dateBtn.removeEventListener('click', dateBtnHandler);
        }

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
