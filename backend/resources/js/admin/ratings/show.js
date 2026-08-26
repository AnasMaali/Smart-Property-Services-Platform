/**
 * Admin Rating detail (BLUE V1 Phase B11). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/ratings/{booking} endpoint
 * (App\Actions\Admin\Rating\AdminGetRatingAction / App\Support\Admin\
 * AdminRatingPresenter) - every field rendered below comes directly from
 * that response. Read-only - no mutation exists for this module. The
 * customer-authored comment is rendered exclusively via textContent, never
 * innerHTML.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { formatDateTime, statusLabel } from '../lib/format.js';

const page = document.querySelector('[data-rating-detail-page]');

if (page) {
    const bookingUuid = page.dataset.bookingUuid;
    const loadingEl = page.querySelector('[data-rating-loading]');
    const errorEl = page.querySelector('[data-rating-error]');
    const contentEl = page.querySelector('[data-rating-content]');

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

    async function loadRating() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/ratings/${encodeURIComponent(bookingUuid)}`);
            renderRating(response.data.rating);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this rating.';
            showError(message);
        }
    }

    function renderRating(rating) {
        const bookingLink = page.querySelector('[data-booking-link]');
        bookingLink.textContent = rating.booking_number;
        bookingLink.href = `/admin/bookings/${encodeURIComponent(rating.booking_uuid)}`;

        setText('rating_value', String(rating.rating_value));
        setText('created_at', formatDateTime(rating.created_at));
        field('booking_status').textContent = statusLabel(rating.booking_status);

        setText('customer_name', rating.customer?.full_name);
        setText('customer_phone', rating.customer?.phone_number);

        const customerLink = page.querySelector('[data-customer-link]');
        if (rating.customer) {
            customerLink.href = `/admin/customers/${encodeURIComponent(rating.customer.uuid)}`;
            customerLink.style.display = 'inline-block';
        } else {
            customerLink.style.display = 'none';
        }

        const servicesEl = page.querySelector('[data-services]');
        servicesEl.replaceChildren(...rating.services.map((name) => {
            const item = document.createElement('li');
            item.textContent = name;
            return item;
        }));

        setText('comment', rating.comment ?? 'No comment left.');
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadRating();
        }
    });
}
