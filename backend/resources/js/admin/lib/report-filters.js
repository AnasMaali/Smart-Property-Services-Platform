/**
 * Small shared helpers for every BLUE V1 Admin Report page's filter form -
 * the Today/Last 7 Days/This Month/Custom range select toggling its
 * from/to date fields, mirroring resources/js/admin/financial/dashboard.js's
 * own (independently-written, left untouched by this feature) convention
 * for the same control so every report's date filter behaves identically.
 */

export function wireDateRangeToggle(rangeSelect, customRangeFields) {
    function toggle() {
        const isCustom = rangeSelect.value === 'CUSTOM';
        customRangeFields.forEach((field) => field.classList.toggle('hidden', !isCustom));
    }

    rangeSelect.addEventListener('change', toggle);
    toggle();
}

/**
 * Reads every named field in $fields from $form into a URLSearchParams,
 * skipping blank values - the exact convention
 * resources/js/admin/audit-log/index.js's paramsFromForm() already
 * established, reused here so query strings look identical across every
 * report and the existing Audit Log Viewer.
 */
export function paramsFromForm(form, fields) {
    const params = new URLSearchParams();

    fields.forEach((field) => {
        const input = form.elements.namedItem(field);
        const value = input?.value?.trim();

        if (value) {
            params.set(field, value);
        }
    });

    return params;
}

export function applyParamsToForm(form, fields, params) {
    fields.forEach((field) => {
        const input = form.elements.namedItem(field);

        if (input) {
            input.value = params.get(field) || '';
        }
    });
}
