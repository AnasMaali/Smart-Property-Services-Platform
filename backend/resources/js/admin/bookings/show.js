/**
 * Admin Booking detail (BLUE V1 Phase B2). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/bookings/{booking} endpoint
 * (App\Actions\Admin\Booking\AdminGetBookingAction / App\Support\Admin\
 * AdminBookingPresenter) - every field rendered below comes directly from
 * that response; nothing is invented or recomputed client-side.
 *
 * Booking Item technician-assignment state is displayed read-only here -
 * assign/reassign/start/complete actions are wired up in the Technicians
 * module (BLUE V1 Phase B3), reusing the same existing technician APIs;
 * this page never fakes those actions.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-booking-detail-page]');

if (page) {
    const bookingUuid = page.dataset.bookingUuid;
    const loadingEl = page.querySelector('[data-booking-loading]');
    const errorEl = page.querySelector('[data-booking-error]');
    const contentEl = page.querySelector('[data-booking-content]');
    const itemsContainer = page.querySelector('[data-booking-items]');
    const itemTemplate = document.querySelector('[data-booking-item-template]');

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

    function renderBooking(booking) {
        setText('booking_number', booking.booking_number);
        setText('created_at', formatDateTime(booking.created_at));
        setText('source', statusLabel(booking.source));
        renderBadge('status', booking.status);

        const refundBox = page.querySelector('[data-refund-due-box]');

        if (booking.refund_due) {
            setText('refund_percentage', String(booking.refund_due.percentage));
            setText('refund_amount', formatMoney(booking.refund_due.amount, booking.currency));
            setText('refund_execution', booking.refund_due.execution);
            refundBox.style.display = 'block';
        } else {
            refundBox.style.display = 'none';
        }

        setText('customer_name', booking.customer?.full_name);
        setText('customer_phone', booking.customer?.phone_number);
        setText('customer_email', booking.customer?.email);

        setText('appointment_window', booking.appointment?.slot?.time_window?.name);
        setText('appointment_starts_at', formatDateTime(booking.appointment?.slot?.starts_at));
        setText('appointment_ends_at', formatDateTime(booking.appointment?.slot?.ends_at));

        setText('payment_status', statusLabel(booking.payment?.status));
        setText('payment_amount', booking.payment ? formatMoney(booking.payment.amount, booking.currency) : '—');
        setText('payment_provider', booking.payment?.provider);

        setText('location_summary', renderLocation(booking.location));

        setText('items_count', String(booking.items.length));
        setText('total', formatMoney(booking.total, booking.currency));

        itemsContainer.replaceChildren(...booking.items.map((item) => renderItem(item, booking.currency)));
    }

    loadBooking();
}
