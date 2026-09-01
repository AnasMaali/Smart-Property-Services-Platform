/**
 * Admin Appointment Schedule Management (BLUE V1 Phase B27). One workspace
 * covering dated slots (day view, generated from active Time Window
 * templates) and the templates themselves - reads/writes
 * /api/v1/admin/appointment-schedule* and /api/v1/admin/appointment-time-windows*
 * exclusively; never touches /api/v1/admin/appointment-slots (that route
 * remains the unrelated Booking Reschedule picker).
 */

import { request, ApiError } from '../lib/api-client.js';
import { formatDateTime, statusLabel, statusBadgeClasses } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';

const page = document.querySelector('[data-schedule-page]');

if (page) {
    const pageErrorEl = page.querySelector('[data-schedule-error]');
    const loadingEl = page.querySelector('[data-schedule-loading]');
    const emptyEl = page.querySelector('[data-schedule-empty]');
    const gridEl = page.querySelector('[data-schedule-grid]');
    const dayInput = page.querySelector('[data-day-input]');

    const tabButtons = page.querySelectorAll('[data-tab-button]');
    const tabPanels = page.querySelectorAll('[data-tab-panel]');

    const generateModal = document.querySelector('[data-generate-modal]');
    const generateForm = generateModal.querySelector('[data-generate-form]');
    const generateError = generateModal.querySelector('[data-generate-error]');
    const generateResult = generateModal.querySelector('[data-generate-result]');
    const generateSubmit = generateModal.querySelector('[data-generate-submit]');

    const windowsTableBody = page.querySelector('[data-windows-table-body]');
    const windowModal = document.querySelector('[data-window-modal]');
    const windowForm = windowModal.querySelector('[data-window-form]');
    const windowError = windowModal.querySelector('[data-window-error]');
    const windowModalTitle = windowModal.querySelector('[data-window-modal-title]');
    const windowCodeField = windowModal.querySelector('[data-window-code-field]');
    const windowSubmit = windowModal.querySelector('[data-window-submit]');

    const slotModal = document.querySelector('[data-slot-modal]');
    const slotModalTitle = slotModal.querySelector('[data-slot-modal-title]');
    const slotModalStatus = slotModal.querySelector('[data-slot-modal-status]');
    const slotError = slotModal.querySelector('[data-slot-error]');
    const slotWarning = slotModal.querySelector('[data-slot-warning]');
    const slotCapacityForm = slotModal.querySelector('[data-slot-capacity-form]');
    const slotCapacitySubmit = slotModal.querySelector('[data-slot-capacity-submit]');
    const slotCloseToggle = slotModal.querySelector('[data-slot-close-toggle]');
    const slotHoldsEmpty = slotModal.querySelector('[data-slot-holds-empty]');
    const slotHoldsList = slotModal.querySelector('[data-slot-holds-list]');
    const slotActiveHoldCount = slotModal.querySelector('[data-slot-active-hold-count]');
    const slotBookingsEmpty = slotModal.querySelector('[data-slot-bookings-empty]');
    const slotBookingsList = slotModal.querySelector('[data-slot-bookings-list]');

    let editingWindowId = null;
    let currentSlotUuid = null;

    function modalErrorMessage(error, fallback) {
        return error instanceof ApiError ? error.message : fallback;
    }

    function showModal(modal) {
        modal.style.display = 'flex';
    }

    function hideModal(modal) {
        modal.style.display = 'none';
    }

    function hideError(el) {
        el.textContent = '';
        el.classList.add('hidden');
    }

    function showError(el, message) {
        el.textContent = message;
        el.classList.remove('hidden');
    }

    // --- Day navigation --------------------------------------------------

    function todayIso() {
        return new Date().toISOString().slice(0, 10);
    }

    function shiftDay(iso, delta) {
        const date = new Date(`${iso}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() + delta);

        return date.toISOString().slice(0, 10);
    }

    function currentDate() {
        return dayInput.value || todayIso();
    }

    // --- Schedule (day view) ----------------------------------------------

    function setScheduleState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        emptyEl.style.display = state === 'empty' ? 'block' : 'none';
        gridEl.style.display = state === 'ready' ? 'grid' : 'none';
    }

    function badgeClassesForStatus(status) {
        if (status === 'AVAILABLE') return 'bg-emerald-50 text-emerald-700';
        if (status === 'FULL') return 'bg-amber-50 text-amber-700';

        return 'bg-slate-100 text-slate-600';
    }

    function renderSlotCard(slot) {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'rounded-2xl border border-slate-200 bg-white p-5 text-left hover:border-slate-300 hover:shadow-sm transition';

        const timeLine = document.createElement('p');
        timeLine.className = 'text-sm font-semibold text-slate-950';
        timeLine.textContent = `${formatDateTime(slot.starts_at)} – ${formatDateTime(slot.ends_at)}`;

        const windowLine = document.createElement('p');
        windowLine.className = 'mt-0.5 text-xs text-slate-500';
        windowLine.textContent = slot.time_window?.name || '';

        const badge = document.createElement('span');
        badge.className = `mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClassesForStatus(slot.availability_status)}`;
        badge.textContent = statusLabel(slot.availability_status);

        const stats = document.createElement('p');
        stats.className = 'mt-3 text-xs text-slate-500';
        stats.textContent = `Capacity ${slot.booking_capacity} · Booked/Held ${slot.occupied_capacity} · Remaining ${slot.remaining_capacity}`;

        card.append(timeLine, windowLine, badge, stats);
        card.addEventListener('click', () => openSlotModal(slot.uuid));

        return card;
    }

    async function loadSchedule() {
        setScheduleState('loading');
        hideError(pageErrorEl);

        try {
            const response = await request(`/api/v1/admin/appointment-schedule?date=${currentDate()}`);
            const slots = response.data.appointment_slots || [];

            gridEl.replaceChildren(...slots.map(renderSlotCard));
            setScheduleState(slots.length === 0 ? 'empty' : 'ready');
        } catch (error) {
            setScheduleState('empty');
            showError(pageErrorEl, modalErrorMessage(error, 'Unable to load the appointment schedule.'));
        }
    }

    page.querySelector('[data-day-prev]').addEventListener('click', () => {
        dayInput.value = shiftDay(currentDate(), -1);
        loadSchedule();
    });

    page.querySelector('[data-day-next]').addEventListener('click', () => {
        dayInput.value = shiftDay(currentDate(), 1);
        loadSchedule();
    });

    page.querySelector('[data-day-today]').addEventListener('click', () => {
        dayInput.value = todayIso();
        loadSchedule();
    });

    dayInput.addEventListener('change', loadSchedule);

    // --- Tabs --------------------------------------------------------------

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.dataset.tabButton;

            tabButtons.forEach((btn) => {
                const active = btn.dataset.tabButton === target;
                btn.classList.toggle('border-slate-950', active);
                btn.classList.toggle('text-slate-950', active);
                btn.classList.toggle('border-transparent', !active);
                btn.classList.toggle('text-slate-500', !active);
            });

            tabPanels.forEach((panel) => {
                panel.style.display = panel.dataset.tabPanel === target ? 'block' : 'none';
            });

            if (target === 'time-windows') {
                loadWindows();
            }
        });
    });

    // --- Generate Schedule modal --------------------------------------------

    page.querySelector('[data-open-generate-modal]').addEventListener('click', () => {
        generateForm.reset();
        generateForm.elements.from.value = currentDate();
        generateForm.elements.to.value = currentDate();
        generateForm.elements.booking_capacity.value = 3;
        hideError(generateError);
        generateResult.style.display = 'none';
        showModal(generateModal);
    });

    generateModal.querySelector('[data-generate-cancel]').addEventListener('click', () => hideModal(generateModal));

    generateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideError(generateError);
        generateResult.style.display = 'none';
        generateSubmit.disabled = true;

        try {
            const formData = new FormData(generateForm);
            const response = await request('/api/v1/admin/appointment-schedule/generate', {
                method: 'POST',
                body: {
                    from: formData.get('from'),
                    to: formData.get('to'),
                    booking_capacity: Number(formData.get('booking_capacity')) || undefined,
                },
            });

            const summary = response.data;
            generateResult.textContent = `${summary.created} created, ${summary.already_existed} already existed, over ${summary.days} day(s) × ${summary.active_time_windows} active window(s).`;
            generateResult.style.display = 'block';
            loadSchedule();
        } catch (error) {
            showError(generateError, modalErrorMessage(error, 'Unable to generate the schedule.'));
        } finally {
            generateSubmit.disabled = false;
        }
    });

    // --- Time Windows tab ----------------------------------------------------

    function renderWindowRow(window) {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50';

        const nameTd = document.createElement('td');
        nameTd.className = 'px-5 py-3.5 font-medium text-slate-900';
        nameTd.textContent = window.name;

        const codeTd = document.createElement('td');
        codeTd.className = 'px-5 py-3.5 text-slate-500';
        codeTd.textContent = window.code;

        const orderTd = document.createElement('td');
        orderTd.className = 'px-5 py-3.5 text-slate-500';
        orderTd.textContent = String(window.display_order);

        const statusTd = document.createElement('td');
        const statusBadge = document.createElement('span');
        statusBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${window.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;
        statusBadge.textContent = window.is_active ? 'Active' : 'Inactive';
        statusTd.className = 'px-5 py-3.5';
        statusTd.appendChild(statusBadge);

        const actionsTd = document.createElement('td');
        actionsTd.className = 'px-5 py-3.5 text-right space-x-3';

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        editButton.textContent = 'Edit';
        editButton.addEventListener('click', () => openWindowModal(window));

        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = 'text-sm font-medium text-slate-600 hover:text-slate-900';
        toggleButton.textContent = window.is_active ? 'Deactivate' : 'Activate';
        toggleButton.addEventListener('click', () => toggleWindow(window));

        actionsTd.append(editButton, toggleButton);
        tr.append(nameTd, codeTd, orderTd, statusTd, actionsTd);

        return tr;
    }

    async function loadWindows() {
        try {
            const response = await request('/api/v1/admin/appointment-time-windows');
            const windows = response.data.appointment_time_windows || [];
            windowsTableBody.replaceChildren(...windows.map(renderWindowRow));
        } catch (error) {
            showError(pageErrorEl, modalErrorMessage(error, 'Unable to load time windows.'));
        }
    }

    async function toggleWindow(window) {
        const path = `/api/v1/admin/appointment-time-windows/${window.id}/${window.is_active ? 'deactivate' : 'activate'}`;

        try {
            await request(path, { method: 'POST' });
            loadWindows();
        } catch (error) {
            showError(pageErrorEl, modalErrorMessage(error, 'Unable to update the time window.'));
        }
    }

    function openWindowModal(window) {
        windowForm.reset();
        hideError(windowError);
        editingWindowId = window ? window.id : null;
        windowModalTitle.textContent = window ? 'Edit Time Window' : 'Add Time Window';
        windowCodeField.style.display = window ? 'none' : 'block';
        windowForm.elements.code.required = !window;

        if (window) {
            windowForm.elements.name.value = window.name;
            windowForm.elements.start_time.value = window.start_time;
            windowForm.elements.end_time.value = window.end_time;
            windowForm.elements.display_order.value = window.display_order;
        }

        showModal(windowModal);
    }

    page.querySelector('[data-open-window-modal]').addEventListener('click', () => openWindowModal(null));
    windowModal.querySelector('[data-window-cancel]').addEventListener('click', () => hideModal(windowModal));

    windowForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideError(windowError);
        windowSubmit.disabled = true;

        const formData = new FormData(windowForm);
        const payload = {
            name: formData.get('name'),
            description: null,
            start_time: formData.get('start_time'),
            end_time: formData.get('end_time'),
            display_order: Number(formData.get('display_order')) || 0,
        };

        try {
            if (editingWindowId === null) {
                await request('/api/v1/admin/appointment-time-windows', {
                    method: 'POST',
                    body: { ...payload, code: formData.get('code'), is_active: true },
                });
            } else {
                await request(`/api/v1/admin/appointment-time-windows/${editingWindowId}`, { method: 'PATCH', body: payload });
            }

            hideModal(windowModal);
            loadWindows();
        } catch (error) {
            showError(windowError, modalErrorMessage(error, 'Unable to save the time window.'));
        } finally {
            windowSubmit.disabled = false;
        }
    });

    // --- Slot detail modal -----------------------------------------------

    function renderHoldRow(hold) {
        const li = document.createElement('li');
        li.className = 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2';
        li.textContent = `Held ${formatDateTime(hold.held_at)} · expires ${formatDateTime(hold.expires_at)}`;

        return li;
    }

    function renderBookingRow(booking) {
        const li = document.createElement('li');
        li.className = 'rounded-lg border border-slate-200 px-3 py-2';

        const link = document.createElement('a');
        link.href = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;
        link.className = 'font-medium text-blue-600 hover:text-blue-800';
        link.textContent = booking.booking_number;

        const badge = document.createElement('span');
        badge.className = `ml-2 rounded-full px-2 py-0.5 text-xs font-semibold ${statusBadgeClasses(booking.status)}`;
        badge.textContent = statusLabel(booking.status);

        const customer = document.createElement('span');
        customer.className = 'ml-2 text-slate-500';
        customer.textContent = booking.customer?.full_name ? `· ${booking.customer.full_name}` : '';

        li.append(link, badge, customer);

        return li;
    }

    function renderSlotDetail(slot) {
        slotModalTitle.textContent = `${formatDateTime(slot.starts_at)} – ${formatDateTime(slot.ends_at)}`;
        slotModalStatus.textContent = `${slot.time_window?.name || ''} · ${statusLabel(slot.availability_status)}`;

        slotModal.querySelector('[data-slot-field="booking_capacity"]').textContent = slot.booking_capacity;
        slotModal.querySelector('[data-slot-field="occupied_capacity"]').textContent = slot.occupied_capacity;
        slotModal.querySelector('[data-slot-field="remaining_capacity"]').textContent = slot.remaining_capacity;

        slotCapacityForm.elements.booking_capacity.value = slot.booking_capacity;
        slotCapacityForm.elements.internal_note.value = slot.internal_note || '';

        slotCloseToggle.textContent = slot.is_active ? 'Close Slot' : 'Reopen Slot';
        slotCloseToggle.dataset.active = slot.is_active ? '1' : '0';

        const holds = slot.active_holds || [];
        slotActiveHoldCount.textContent = String(slot.active_hold_count ?? holds.length);
        slotHoldsEmpty.style.display = holds.length === 0 ? 'block' : 'none';
        slotHoldsList.replaceChildren(...holds.map(renderHoldRow));

        const bookings = slot.bookings || [];
        slotBookingsEmpty.style.display = bookings.length === 0 ? 'block' : 'none';
        slotBookingsList.replaceChildren(...bookings.map(renderBookingRow));
    }

    async function openSlotModal(slotUuid) {
        currentSlotUuid = slotUuid;
        hideError(slotError);
        slotWarning.style.display = 'none';
        showModal(slotModal);

        try {
            const response = await request(`/api/v1/admin/appointment-schedule/${slotUuid}`);
            renderSlotDetail(response.data.appointment_slot);
        } catch (error) {
            showError(slotError, modalErrorMessage(error, 'Unable to load this appointment slot.'));
        }
    }

    slotModal.querySelector('[data-slot-close]').addEventListener('click', () => {
        hideModal(slotModal);
        loadSchedule();
    });

    slotCapacityForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideError(slotError);
        slotWarning.style.display = 'none';
        slotCapacitySubmit.disabled = true;

        const formData = new FormData(slotCapacityForm);

        try {
            await request(`/api/v1/admin/appointment-schedule/${currentSlotUuid}`, {
                method: 'PATCH',
                body: {
                    booking_capacity: Number(formData.get('booking_capacity')),
                    internal_note: formData.get('internal_note') || null,
                },
            });

            const slotDetailResponse = await request(`/api/v1/admin/appointment-schedule/${currentSlotUuid}`);
            renderSlotDetail(slotDetailResponse.data.appointment_slot);
        } catch (error) {
            showError(slotError, modalErrorMessage(error, 'Unable to update this slot.'));
        } finally {
            slotCapacitySubmit.disabled = false;
        }
    });

    slotCloseToggle.addEventListener('click', async () => {
        const isActive = slotCloseToggle.dataset.active === '1';
        const path = `/api/v1/admin/appointment-schedule/${currentSlotUuid}/${isActive ? 'deactivate' : 'activate'}`;

        hideError(slotError);
        slotWarning.style.display = 'none';

        try {
            const response = await request(path, { method: 'POST' });

            if (response.data.warning) {
                slotWarning.textContent = response.data.warning;
                slotWarning.style.display = 'block';
            }

            const slotDetailResponse = await request(`/api/v1/admin/appointment-schedule/${currentSlotUuid}`);
            renderSlotDetail(slotDetailResponse.data.appointment_slot);
        } catch (error) {
            showError(slotError, modalErrorMessage(error, 'Unable to update this slot.'));
        }
    });

    // --- Init ----------------------------------------------------------------

    dayInput.value = todayIso();

    adminAuthReady().then((ready) => {
        if (ready) {
            loadSchedule();
        }
    });
}
