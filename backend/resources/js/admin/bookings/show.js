/**
 * Admin Booking detail (BLUE V1 Phase B2, redesigned as a full Booking
 * Operations workspace in Phase B14). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/bookings/{booking} endpoint
 * (App\Actions\Admin\Booking\AdminGetBookingAction / App\Support\Admin\
 * AdminBookingPresenter) - every field rendered below comes directly from
 * that response; nothing is invented or recomputed client-side.
 *
 * Booking Item technician assign/reassign/start/complete actions (BLUE V1
 * Phase B3) are delegated to technicians/booking-item-actions.js, which
 * reuses the same existing technician APIs - this page only decides when to
 * reload authoritative state after a mutation succeeds (loadBooking()
 * again), it never patches local state to fake an outcome.
 *
 * Selected options/choices and status history render exactly the structured
 * fields the presenter already returns - no raw JSON is ever dumped, and no
 * entitlement/eligibility/status-machine logic is recomputed here.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';
import { attachTechnicianActions, openConfirmAction } from '../technicians/booking-item-actions.js';

function formatDateOnly(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatOptionSelection(option) {
    if (option.option_type === 'BOOLEAN') {
        return `${option.option_name}: ${option.boolean_value ? 'Yes' : 'No'}`;
    }

    const unit = option.measurement_unit_symbol ? ` ${option.measurement_unit_symbol}` : '';

    return `${option.option_name}: ${option.numeric_value}${unit}`;
}

function formatChoiceSelection(choice) {
    return `${choice.option_name}: ${choice.choice_name}`;
}

function formatEntitlement(entitlement) {
    if (!entitlement) {
        return '';
    }

    if (entitlement.entitlement_mode === 'UNLIMITED') {
        return 'Unlimited visits under this contract.';
    }

    return `${entitlement.used_visits} of ${entitlement.included_visits} included visits used `
        + `(${entitlement.remaining_visits} remaining).`;
}

function transitionLabel(entry) {
    const from = entry.from_status ? statusLabel(entry.from_status) : 'Created';

    return `${from} → ${statusLabel(entry.to_status)}`;
}

const EDIT_BOOKING_FIELDS = [
    'street_name',
    'address_line',
    'building_name_or_number',
    'floor_number',
    'unit_number',
    'nearby_landmark',
    'additional_location_notes',
    'visit_contact_phone',
];

const TERMINAL_BOOKING_STATUSES = ['COMPLETED', 'CANCELLED'];

const page = document.querySelector('[data-booking-detail-page]');

if (page) {
    const bookingUuid = page.dataset.bookingUuid;
    const loadingEl = page.querySelector('[data-booking-loading]');
    const errorEl = page.querySelector('[data-booking-error]');
    const contentEl = page.querySelector('[data-booking-content]');
    const itemsContainer = page.querySelector('[data-booking-items]');
    const itemTemplate = document.querySelector('[data-booking-item-template]');
    const selectionChipTemplate = document.querySelector('[data-selection-chip-template]');
    const historyRowTemplate = document.querySelector('[data-history-row-template]');
    const itemHistoryRowTemplate = document.querySelector('[data-item-history-row-template]');
    const statusHistoryContainer = page.querySelector('[data-status-history]');
    const editBookingButton = page.querySelector('[data-edit-booking-open]');
    const editBookingModal = document.querySelector('[data-edit-booking-modal]');
    const cancelBookingButton = page.querySelector('[data-cancel-booking-open]');
    const forceCompleteBox = page.querySelector('[data-force-complete-box]');
    const forceCompleteButton = page.querySelector('[data-force-complete-open]');
    const collectOnSitePaymentButton = page.querySelector('[data-collect-on-site-payment-open]');
    const rescheduleButton = page.querySelector('[data-reschedule-booking-open]');
    const rescheduleModal = document.querySelector('[data-reschedule-booking-modal]');

    let currentBooking = null;

    function showModalError(errorEl, message) {
        errorEl.textContent = message;
        errorEl.classList.remove('hidden');
    }

    function hideModalError(errorEl) {
        errorEl.textContent = '';
        errorEl.classList.add('hidden');
    }

    function modalErrorMessage(error, fallback) {
        return error instanceof ApiError ? error.message : fallback;
    }

    function field(name) {
        return page.querySelector(`[data-field="${name}"]`);
    }

    function setText(name, value) {
        const el = field(name);

        if (el) {
            el.textContent = value ?? '—';
        }
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        contentEl.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function renderBadge(name, code) {
        const el = field(name);

        if (!el) {
            return;
        }

        el.textContent = statusLabel(code);
        el.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${statusBadgeClasses(code)}`;
    }

    function renderAssignmentSummary(item) {
        if (item.active_assignment) {
            const technician = item.active_assignment.technician;
            return `Assigned to ${technician.full_name} (${technician.phone_number}) since ${formatDateTime(item.active_assignment.assigned_at)}.`;
        }

        if (item.assignment_history.length > 0) {
            return 'Previously assigned - no technician currently active on this item.';
        }

        return 'No technician has been assigned to this item yet.';
    }

    const WHATSAPP_ICON_SVG = '<svg viewBox="0 0 24 24" class="h-3.5 w-3.5 fill-current" aria-hidden="true">'
        + '<path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.87 9.87 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 '
        + '0-2.64-1.03-5.12-2.9-6.99A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.11 0 4.1.82 5.59 2.31a7.9 7.9 0 0 1 2.32 5.92c0 4.53-3.68 8.22-8.21 8.22a8.2 8.2 0 '
        + '0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.14 8.14 0 0 1-1.26-4.36c0-4.53 3.69-8.23 8.24-8.23Zm-4.72 4.4c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 '
        + '2.02 0 1.19.87 2.34.99 2.5.12.16 1.7 2.6 4.13 3.63.58.25 1.03.4 1.38.51.58.18 1.11.16 1.53.1.47-.07 1.44-.59 1.64-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.44-.71-1.66-.79-.22-.08-.38-.12-.55.12-.16.24-.63.79-.77.95-.14.16-.28.18-.52.06-.24-.12-1.01-.37-1.92-1.18-.71-.63-1.19-1.4-1.33-1.64-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.34-.76-1.83-.2-.48-.4-.42-.55-.42h-.46Z"/>'
        + '</svg>';

    /**
     * BLUE V1 Simple WhatsApp Handoff - a plain `<a target="_blank">` to
     * the server-generated wa.me URL (App\Support\Admin\
     * AdminBookingPresenter / App\Support\WhatsApp\WhatsAppLinkBuilder) -
     * this is the ONLY thing the button does. The message text and
     * recipient are entirely server-computed; this file never composes or
     * edits them. A real anchor (not window.open()) is used so the
     * browser's native new-tab/handler-routing behavior applies on both
     * desktop (WhatsApp Web) and mobile (the WhatsApp app).
     */
    function whatsappActionLink(label, whatsapp) {
        const a = document.createElement('a');
        a.href = whatsapp.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'inline-flex items-center gap-1.5 rounded-full border border-emerald-200 '
            + 'bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100';
        a.innerHTML = `${WHATSAPP_ICON_SVG}<span>${label}</span>`;

        return a;
    }

    /**
     * "Message technician" / "Message customer" / "Message previous
     * technician" - never a delivery-status indicator (BLUE V1 does not
     * track whether the Admin actually pressed Send inside WhatsApp).
     * Hidden entirely when the backend could not build a safe wa.me link
     * (missing/invalid phone, or an unresolved appointment) - never a
     * broken button.
     */
    function renderWhatsappActions(root, item) {
        const container = root.querySelector('[data-whatsapp-actions]');

        if (!container) {
            return;
        }

        const links = [];

        if (item.active_assignment && item.active_assignment.whatsapp) {
            links.push(whatsappActionLink('Message technician', item.active_assignment.whatsapp));
        }

        if (item.customer_whatsapp) {
            links.push(whatsappActionLink('Message customer', item.customer_whatsapp));
        }

        item.assignment_history
            .filter((entry) => entry.released_at && entry.whatsapp)
            .forEach((entry) => {
                links.push(whatsappActionLink(`Message previous technician (${entry.technician.full_name})`, entry.whatsapp));
            });

        container.replaceChildren(...links);
    }

    function renderHistoryList(container, rowTemplate, entries, labelFieldSetter) {
        container.replaceChildren(...entries.map((entry) => {
            const fragment = rowTemplate.content.cloneNode(true);
            labelFieldSetter(fragment, entry);
            return fragment;
        }));
    }

    function renderItem(item, currency) {
        const fragment = itemTemplate.content.cloneNode(true);
        const root = fragment.querySelector('div');

        root.querySelector('[data-field="service_name"]').textContent = item.service.name;
        root.querySelector('[data-field="service_code"]').textContent = item.service.code;
        root.querySelector('[data-field="quantity"]').textContent = String(item.quantity);
        root.querySelector('[data-field="line_total"]').textContent = formatMoney(item.pricing.line_total, currency);
        root.querySelector('[data-field="assignment_summary"]').textContent = renderAssignmentSummary(item);

        const statusBadge = root.querySelector('[data-field="item_status"]');
        statusBadge.textContent = statusLabel(item.status);
        statusBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(item.status)}`;

        const selectionsContainer = root.querySelector('[data-item-selections]');
        const chips = [
            ...item.selected_options.map(formatOptionSelection),
            ...item.selected_choices.map(formatChoiceSelection),
        ];

        if (chips.length > 0) {
            selectionsContainer.classList.remove('hidden');
            selectionsContainer.classList.add('flex');
            selectionsContainer.replaceChildren(...chips.map((label) => {
                const chip = selectionChipTemplate.content.cloneNode(true);
                chip.querySelector('span').textContent = label;
                return chip;
            }));
        }

        const historyBox = root.querySelector('[data-item-history-box]');
        if (item.status_history.length > 0) {
            historyBox.classList.remove('hidden');
            renderHistoryList(
                root.querySelector('[data-item-history]'),
                itemHistoryRowTemplate,
                item.status_history,
                (fragment, entry) => {
                    fragment.querySelector('[data-field="transition"]').textContent = transitionLabel(entry);
                    fragment.querySelector('[data-field="changed_at"]').textContent = formatDateTime(entry.changed_at);
                },
            );
        }

        const actionsContainer = root.querySelector('[data-technician-actions]');

        if (actionsContainer) {
            attachTechnicianActions(actionsContainer, item, loadBooking);
        }

        renderWhatsappActions(root, item);

        return fragment;
    }

    function renderLocation(location) {
        if (!location) {
            return 'No location recorded.';
        }

        return [
            location.building_name_or_number,
            location.street_name,
            location.area_name,
            location.city_name,
            location.country_name,
        ]
            .filter(Boolean)
            .join(', ');
    }

    async function loadBooking() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}`);
            renderBooking(response.data.booking);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this booking.';
            showError(message);
        }
    }

    /**
     * BLUE V1 Phase B20 - the post-cancellation refund state, read
     * verbatim from booking.refund_due (App\Support\Admin\
     * AdminBookingPresenter::refundDuePayload()) - status/provider/
     * reference/timestamps/failure reason are never invented here, only
     * formatted. A refund is a consequence of Cancel booking, never a
     * separate action, so this box has no button of its own.
     */
    function renderRefundDue(refundDue, currency) {
        const box = page.querySelector('[data-refund-due-box]');

        if (!refundDue) {
            box.style.display = 'none';
            return;
        }

        const needsAttention = refundDue.status === 'RECONCILIATION_REQUIRED';

        setText('refund_percentage', String(refundDue.percentage));
        setText('refund_amount', formatMoney(refundDue.amount, currency));
        setText('refund_status', refundDue.status ? statusLabel(refundDue.status) : 'Pending');
        setText('refund_execution', refundDue.execution === 'STRIPE_AUTOMATIC' ? 'Automatic via Stripe' : refundDue.execution);
        setText('refund_provider', refundDue.provider);

        // BLUE V1 Phase B20 fix 2 - RECONCILIATION_REQUIRED must read as
        // clearly distinct from ordinary PENDING/processing, never merely
        // another status word in the same list.
        const attentionBanner = page.querySelector('[data-refund-attention-banner]');
        attentionBanner.style.display = needsAttention ? 'block' : 'none';

        const referenceRow = page.querySelector('[data-refund-reference-row]');
        if (refundDue.provider_refund_reference) {
            setText('refund_provider_reference', refundDue.provider_refund_reference);
            referenceRow.style.display = 'flex';
        } else {
            referenceRow.style.display = 'none';
        }

        setText('refund_requested_at', formatDateTime(refundDue.requested_at));

        const succeededRow = page.querySelector('[data-refund-succeeded-row]');
        if (refundDue.succeeded_at) {
            setText('refund_succeeded_at', formatDateTime(refundDue.succeeded_at));
            succeededRow.style.display = 'flex';
        } else {
            succeededRow.style.display = 'none';
        }

        const failedRow = page.querySelector('[data-refund-failed-row]');
        if (refundDue.failed_at) {
            setText('refund_failed_at_label', needsAttention ? 'Flagged at' : 'Failed at');
            setText('refund_failed_at', formatDateTime(refundDue.failed_at));
            failedRow.style.display = 'flex';
        } else {
            failedRow.style.display = 'none';
        }

        const failureReasonRow = page.querySelector('[data-refund-failure-reason-row]');
        if (refundDue.failure_code || refundDue.failure_message) {
            setText('refund_failure_reason', [refundDue.failure_code, refundDue.failure_message].filter(Boolean).join(' — '));
            failureReasonRow.style.display = 'flex';
        } else {
            failureReasonRow.style.display = 'none';
        }

        box.style.display = 'block';
    }

    /**
     * BLUE V1 Phase B20 - builds the read-only refund preview shown inside
     * the shared confirm-action modal BEFORE an Admin confirms Cancel
     * booking. Every number/label rendered here comes directly from GET
     * /v1/admin/bookings/{booking}/cancellation-preview (App\Actions\
     * Booking\PreviewBookingCancellationAction, reused verbatim from the
     * customer endpoint) - this function only formats fields the backend
     * already computed, it never calculates a percentage or amount itself.
     */
    function renderCancellationPreviewDetails(preview) {
        const wrap = document.createElement('dl');
        wrap.className = 'space-y-1.5';

        const row = (label, value) => {
            const div = document.createElement('div');
            div.className = 'flex justify-between gap-4';

            const dt = document.createElement('dt');
            dt.className = 'text-slate-500';
            dt.textContent = label;

            const dd = document.createElement('dd');
            dd.className = 'font-medium text-slate-900';
            dd.textContent = value;

            div.append(dt, dd);
            return div;
        };

        if (preview.appointment?.starts_at) {
            wrap.appendChild(row('Appointment', formatDateTime(preview.appointment.starts_at)));
        }

        if (!preview.refund) {
            wrap.appendChild(row(
                'Refund',
                preview.reason_code === 'CONTRACT_ENTITLEMENT'
                    ? 'None - covered by a Service Contract'
                    : 'None',
            ));

            if (!preview.cancellable) {
                wrap.appendChild(row('Note', 'This booking can no longer be cancelled.'));
            }

            return wrap;
        }

        wrap.appendChild(row('Captured amount', formatMoney(preview.paid_amount, preview.currency)));
        wrap.appendChild(row(
            'Cancellation refund',
            `${preview.refund.percentage}% · ${formatMoney(preview.refund.amount, preview.currency)}`,
        ));
        wrap.appendChild(row('Execution', 'Automatic via Stripe'));
        wrap.appendChild(row('Destination', 'Original payment method'));

        if (!preview.cancellable) {
            wrap.appendChild(row('Note', 'This booking can no longer be cancelled.'));
        }

        return wrap;
    }

    function renderBooking(booking) {
        currentBooking = booking;

        setText('booking_number', booking.booking_number);
        setText('created_at', formatDateTime(booking.created_at));
        setText('source', statusLabel(booking.source));
        renderBadge('status', booking.status);

        renderRefundDue(booking.refund_due, booking.currency);

        page.querySelectorAll('[data-customer-link]').forEach((link) => {
            if (booking.customer) {
                link.textContent = booking.customer.full_name || 'Customer';
                link.href = `/admin/customers/${encodeURIComponent(booking.customer.uuid)}`;
            } else {
                link.textContent = 'Unknown customer';
                link.removeAttribute('href');
            }
        });

        setText('customer_phone', booking.customer?.phone_number);
        setText('customer_email', booking.customer?.email);

        const slot = booking.appointment?.slot;
        setText('appointment_window', slot?.time_window?.name);
        setText('appointment_starts_at', formatDateTime(slot?.starts_at));
        setText('appointment_ends_at', formatDateTime(slot?.ends_at));
        setText('appointment_summary', slot ? `${formatDateOnly(slot.starts_at)} · ${slot.time_window?.name || ''}`.trim() : '—');

        const paymentBox = page.querySelector('[data-payment-box]');
        const onSitePaymentBox = page.querySelector('[data-on-site-payment-box]');
        const paymentEmpty = page.querySelector('[data-payment-empty]');

        if (booking.payment) {
            paymentBox.style.display = 'block';
            onSitePaymentBox.style.display = 'none';
            paymentEmpty.style.display = 'none';
            setText('payment_status', statusLabel(booking.payment.status));
            setText('payment_amount', formatMoney(booking.payment.amount, booking.currency));
            setText('payment_provider', booking.payment.provider);
            page.querySelector('[data-payment-link]').href = `/admin/payments/${encodeURIComponent(booking.payment.uuid)}`;
        } else if (booking.payment_method === 'PAY_ON_SITE' && booking.on_site_settlement) {
            paymentBox.style.display = 'none';
            onSitePaymentBox.style.display = 'block';
            paymentEmpty.style.display = 'none';

            const settlement = booking.on_site_settlement;
            const isCollected = settlement.collection_status === 'COLLECTED';

            setText('on_site_amount_due', formatMoney(settlement.amount_due, booking.currency));
            setText('on_site_collection_status', statusLabel(settlement.collection_status));

            const collectedRow = page.querySelector('[data-on-site-collected-row]');
            collectedRow.style.display = isCollected ? 'flex' : 'none';
            setText('on_site_collected_at', formatDateTime(settlement.collected_at));

            if (collectOnSitePaymentButton) {
                const isBookingNonTerminal = !TERMINAL_BOOKING_STATUSES.includes(booking.status);
                collectOnSitePaymentButton.style.display = !isCollected && isBookingNonTerminal ? 'inline-flex' : 'none';
            }
        } else {
            paymentBox.style.display = 'none';
            onSitePaymentBox.style.display = 'none';
            paymentEmpty.style.display = 'block';
        }

        const contractBox = page.querySelector('[data-contract-box]');

        if (booking.contract) {
            contractBox.style.display = 'block';
            const contractLink = page.querySelector('[data-contract-link]');
            contractLink.textContent = booking.contract.contract_number;
            contractLink.href = `/admin/contracts/${encodeURIComponent(booking.contract.contract_uuid)}`;
            renderBadge('contract_status', booking.contract.status);
            setText('entitlement_summary', formatEntitlement(booking.contract.entitlement));
        } else {
            contractBox.style.display = 'none';
        }

        setText('location_summary', renderLocation(booking.location));
        setText('location_contact', booking.location?.visit_contact_phone ? `Visit contact: ${booking.location.visit_contact_phone}` : '');

        const isNonTerminal = !TERMINAL_BOOKING_STATUSES.includes(booking.status);

        if (editBookingButton) {
            editBookingButton.style.display = Boolean(booking.location) && isNonTerminal ? 'inline-flex' : 'none';
        }

        if (cancelBookingButton) {
            cancelBookingButton.style.display = isNonTerminal ? 'inline-flex' : 'none';
        }

        if (forceCompleteBox) {
            const hasIneligibleItem = booking.items.some((item) => ['PENDING_ASSIGNMENT', 'ASSIGNED', 'CANCELLED'].includes(item.status));
            forceCompleteBox.style.display = isNonTerminal && booking.items.length > 0 && !hasIneligibleItem ? 'flex' : 'none';
        }

        if (rescheduleButton) {
            rescheduleButton.style.display = isNonTerminal && booking.status !== 'IN_PROGRESS' ? 'inline-flex' : 'none';
        }

        setText('items_count', String(booking.items.length));
        setText('total', formatMoney(booking.total, booking.currency));

        itemsContainer.replaceChildren(...booking.items.map((item) => renderItem(item, booking.currency)));

        const ratingBox = page.querySelector('[data-rating-box]');

        if (booking.rating) {
            ratingBox.style.display = 'block';
            setText('rating_stars', '★'.repeat(booking.rating.rating_value) + '☆'.repeat(5 - booking.rating.rating_value));
            setText('rating_value', `${booking.rating.rating_value} / 5`);
            setText('rating_comment', booking.rating.comment);
            setText('rating_created_at', formatDateTime(booking.rating.created_at));
        } else {
            ratingBox.style.display = 'none';
        }

        renderHistoryList(statusHistoryContainer, historyRowTemplate, booking.status_history, (fragment, entry) => {
            fragment.querySelector('[data-field="transition"]').textContent = transitionLabel(entry);
            fragment.querySelector('[data-field="reason"]').textContent = entry.reason || '';
            fragment.querySelector('[data-field="changed_at"]').textContent = formatDateTime(entry.changed_at);
        });
    }

    // -----------------------------------------------------------------
    // Edit booking (BLUE V1 Phase B15) - operational visit/location
    // fields only. Prefills strictly from the currently loaded
    // authoritative Booking response; on success it never patches local
    // state, it reloads GET /v1/admin/bookings/{booking} exactly like the
    // technician operations above.
    // -----------------------------------------------------------------

    function editBookingModalElements() {
        if (!editBookingModal) {
            return null;
        }

        return {
            modal: editBookingModal,
            error: editBookingModal.querySelector('[data-edit-booking-error]'),
            form: editBookingModal.querySelector('[data-edit-booking-form]'),
            cancelButton: editBookingModal.querySelector('[data-edit-booking-cancel]'),
            submitButton: editBookingModal.querySelector('[data-edit-booking-submit]'),
        };
    }

    function openEditBookingModal() {
        const els = editBookingModalElements();

        if (!els || !currentBooking?.location) {
            return;
        }

        hideModalError(els.error);
        els.form.reset();

        EDIT_BOOKING_FIELDS.forEach((fieldName) => {
            const input = els.form.elements.namedItem(fieldName);

            if (input) {
                input.value = currentBooking.location[fieldName] ?? '';
            }
        });

        els.modal.style.display = 'flex';

        const onCancel = () => close();

        const close = () => {
            els.modal.style.display = 'none';
            els.cancelButton.removeEventListener('click', onCancel);
            els.form.removeEventListener('submit', onSubmit);
        };

        async function onSubmit(event) {
            event.preventDefault();
            hideModalError(els.error);

            const formData = new FormData(els.form);
            const payload = {};

            EDIT_BOOKING_FIELDS.forEach((fieldName) => {
                payload[fieldName] = formData.get(fieldName) ?? '';
            });

            els.submitButton.disabled = true;

            try {
                await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}`, {
                    method: 'PATCH',
                    body: payload,
                });

                close();
                await loadBooking();
            } catch (error) {
                showModalError(els.error, modalErrorMessage(error, 'Unable to update this booking.'));
            } finally {
                els.submitButton.disabled = false;
            }
        }

        els.cancelButton.addEventListener('click', onCancel);
        els.form.addEventListener('submit', onSubmit);
    }

    if (editBookingButton) {
        editBookingButton.addEventListener('click', openEditBookingModal);
    }

    // -----------------------------------------------------------------
    // Cancel booking (BLUE V1 Phase B16) - the ONLY Admin-initiated status
    // change this Workspace exposes. Reuses the same generic confirm-action
    // modal as Start work / Complete work, but a reason is mandatory here
    // (server-enforced too - this is only a UX pre-check).
    // -----------------------------------------------------------------

    if (cancelBookingButton) {
        cancelBookingButton.addEventListener('click', async () => {
            cancelBookingButton.disabled = true;

            let detailsNode;

            try {
                const preview = await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}/cancellation-preview`);
                detailsNode = renderCancellationPreviewDetails(preview.data.preview);
            } catch (error) {
                // Degrade gracefully rather than blocking Cancel entirely -
                // the real policy/refund enforcement always happens
                // server-side on the POST .../cancel call below regardless
                // of whether this preview loaded.
                const note = document.createElement('p');
                note.textContent = modalErrorMessage(error, 'Unable to load the refund preview.');
                detailsNode = note;
            } finally {
                cancelBookingButton.disabled = false;
            }

            openConfirmAction({
                title: 'Cancel booking',
                message: 'This permanently cancels the booking, its remaining service items, and releases any assigned technicians. A reason is required.',
                confirmLabel: 'Cancel booking',
                detailsNode,
                onConfirm: async (reason) => {
                    if (!reason) {
                        throw new ApiError('A reason is required to cancel this booking.');
                    }

                    await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}/cancel`, {
                        method: 'POST',
                        body: { reason },
                    });

                    await loadBooking();
                },
            });
        });
    }

    // -----------------------------------------------------------------
    // Force complete (BLUE V1 Phase B17) - break-glass operational
    // recovery only, visually and behaviorally separate from the normal
    // per-item Start/Complete work actions above. A reason is mandatory;
    // the server also requires a fresh Step-Up re-proof (handled
    // transparently by lib/api-client.js's 428 retry).
    // -----------------------------------------------------------------

    if (forceCompleteButton) {
        forceCompleteButton.addEventListener('click', () => {
            openConfirmAction({
                title: 'Force complete booking',
                message: 'This bypasses the normal technician workflow and marks every remaining item as completed. This cannot be undone. A reason is required.',
                confirmLabel: 'Force complete',
                onConfirm: async (reason) => {
                    if (!reason) {
                        throw new ApiError('A reason is required to force-complete this booking.');
                    }

                    await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}/force-complete`, {
                        method: 'POST',
                        body: { reason },
                    });

                    await loadBooking();
                },
            });
        });
    }

    // -----------------------------------------------------------------
    // Collect on-site payment (BLUE V1 Phase B24) - marks the cash/manual
    // amount owed on a PAY_ON_SITE Booking as collected. This is the only
    // client-side entry point into POST /v1/admin/bookings/{booking}/
    // collect-on-site-payment; authorization (bookings.manage), the fresh
    // Step-Up re-proof (handled transparently by lib/api-client.js's 428
    // retry), idempotency, and the audit entry are all enforced server-side
    // by App\Actions\Admin\Booking\AdminCollectOnSitePaymentAction - this
    // handler only re-fetches authoritative state afterwards.
    // -----------------------------------------------------------------

    if (collectOnSitePaymentButton) {
        collectOnSitePaymentButton.addEventListener('click', () => {
            openConfirmAction({
                title: 'Mark on-site payment as collected',
                message: 'Confirm that the customer paid the amount due in cash or by card on site. This cannot be undone.',
                confirmLabel: 'Mark as collected',
                onConfirm: async () => {
                    await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}/collect-on-site-payment`, {
                        method: 'POST',
                    });

                    await loadBooking();
                },
            });
        });
    }

    // -----------------------------------------------------------------
    // Reschedule booking (BLUE V1 Phase B19) - moves this booking to a
    // different appointment slot. The slot list comes from the backend's
    // own availability computation (GET /v1/admin/appointment-slots) -
    // never trusted/computed client-side. Backend re-validates capacity,
    // Technician overlap, and lifecycle eligibility at submit time
    // regardless of what this list showed a moment earlier.
    // -----------------------------------------------------------------

    function rescheduleModalElements() {
        if (!rescheduleModal) {
            return null;
        }

        return {
            modal: rescheduleModal,
            error: rescheduleModal.querySelector('[data-reschedule-booking-error]'),
            current: rescheduleModal.querySelector('[data-reschedule-current]'),
            form: rescheduleModal.querySelector('[data-reschedule-booking-form]'),
            loading: rescheduleModal.querySelector('[data-reschedule-slots-loading]'),
            empty: rescheduleModal.querySelector('[data-reschedule-slots-empty]'),
            select: rescheduleModal.querySelector('[data-reschedule-slot-select]'),
            preview: rescheduleModal.querySelector('[data-reschedule-preview]'),
            previewText: rescheduleModal.querySelector('[data-reschedule-preview-text]'),
            cancelButton: rescheduleModal.querySelector('[data-reschedule-booking-cancel]'),
            submitButton: rescheduleModal.querySelector('[data-reschedule-booking-submit]'),
        };
    }

    function formatSlotLabel(slot) {
        return `${formatDateOnly(slot.starts_at)} · ${formatDateTime(slot.starts_at)}–${formatDateTime(slot.ends_at)} (${slot.time_window.name})`;
    }

    function openRescheduleModal() {
        const els = rescheduleModalElements();

        if (!els || !currentBooking?.appointment?.slot) {
            return;
        }

        els.current.textContent = formatSlotLabel(currentBooking.appointment.slot);

        hideModalError(els.error);
        els.form.reset();
        els.preview.style.display = 'none';
        els.select.style.display = 'none';
        els.empty.style.display = 'none';
        els.loading.style.display = 'block';
        els.modal.style.display = 'flex';

        let slotsByUuid = {};

        const updatePreview = () => {
            const slot = slotsByUuid[els.select.value];
            if (slot) {
                els.previewText.textContent = formatSlotLabel(slot);
                els.preview.style.display = 'block';
            } else {
                els.preview.style.display = 'none';
            }
        };

        const onCancel = () => close();

        const close = () => {
            els.modal.style.display = 'none';
            els.cancelButton.removeEventListener('click', onCancel);
            els.form.removeEventListener('submit', onSubmit);
            els.select.removeEventListener('change', updatePreview);
        };

        async function onSubmit(event) {
            event.preventDefault();
            hideModalError(els.error);

            const formData = new FormData(els.form);
            const slotUuid = formData.get('appointment_slot_uuid');
            const reason = formData.get('reason')?.trim();

            if (!slotUuid) {
                showModalError(els.error, 'Choose a new appointment slot to continue.');
                return;
            }

            if (!reason) {
                showModalError(els.error, 'A reason is required to reschedule this booking.');
                return;
            }

            els.submitButton.disabled = true;

            try {
                await request(`/api/v1/admin/bookings/${encodeURIComponent(bookingUuid)}/reschedule`, {
                    method: 'POST',
                    body: { appointment_slot_uuid: slotUuid, reason },
                });

                close();
                await loadBooking();
            } catch (error) {
                showModalError(els.error, modalErrorMessage(error, 'Unable to reschedule this booking.'));
            } finally {
                els.submitButton.disabled = false;
            }
        }

        els.cancelButton.addEventListener('click', onCancel);
        els.form.addEventListener('submit', onSubmit);
        els.select.addEventListener('change', updatePreview);

        request('/api/v1/admin/appointment-slots')
            .then((response) => {
                els.loading.style.display = 'none';
                const slots = response.data.appointment_slots || [];

                if (slots.length === 0) {
                    els.empty.style.display = 'block';
                    return;
                }

                slotsByUuid = Object.fromEntries(slots.map((slot) => [slot.uuid, slot]));
                els.select.replaceChildren(...slots.map((slot) => {
                    const option = document.createElement('option');
                    option.value = slot.uuid;
                    option.textContent = formatSlotLabel(slot);
                    return option;
                }));
                els.select.style.display = 'block';
                updatePreview();
            })
            .catch((error) => {
                els.loading.style.display = 'none';
                showModalError(els.error, modalErrorMessage(error, 'Unable to load available appointment slots.'));
            });
    }

    if (rescheduleButton) {
        rescheduleButton.addEventListener('click', openRescheduleModal);
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadBooking();
        }
    });
}
