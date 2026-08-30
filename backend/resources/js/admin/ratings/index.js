/**
 * Admin Ratings list (BLUE V1 Phase B11). Reuses the centralized Admin API
 * client against GET /v1/admin/ratings (App\Actions\Admin\Rating\
 * AdminListRatingsAction / App\Support\Admin\AdminRatingPresenter).
 * Read-only - no mutation exists for this module (no rating-creation flow
 * exists yet, and editing/deleting a submitted rating has no defined
 * policy - see docs/api-contracts/admin-operations-v1.md "Ratings").
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-ratings-page]');

if (page) {
    const filterForm = page.querySelector('[data-ratings-filter-form]');
    const clearButton = page.querySelector('[data-ratings-clear-filters]');
    const loadingEl = page.querySelector('[data-ratings-loading]');
    const errorEl = page.querySelector('[data-ratings-error]');
    const emptyEl = page.querySelector('[data-ratings-empty]');
    const tableWrapper = page.querySelector('[data-ratings-table-wrapper]');
    const tableBody = page.querySelector('[data-ratings-body]');
    const pagination = page.querySelector('[data-ratings-pagination]');
    const paginationSummary = page.querySelector('[data-ratings-pagination-summary]');
    const prevPageButton = page.querySelector('[data-ratings-prev-page]');
    const nextPageButton = page.querySelector('[data-ratings-next-page]');

    const FILTER_FIELDS = ['rating_value', 'max_rating', 'customer_uuid'];

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function applyParamsToForm(params) {
        FILTER_FIELDS.forEach((field) => {
            const input = filterForm.elements.namedItem(field);

            if (input) {
                input.value = params.get(field) || '';
            }
        });
    }

    function paramsFromForm() {
        const params = new URLSearchParams();

        FILTER_FIELDS.forEach((field) => {
            const value = filterForm.elements.namedItem(field)?.value?.trim();

            if (value) {
                params.set(field, value);
            }
        });

        return params;
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        tableWrapper.classList.toggle('hidden', state !== 'ready');
        pagination.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function ratingBadgeClasses(value) {
        if (value >= 4) {
            return 'bg-emerald-50 text-emerald-700';
        }

        if (value === 3) {
            return 'bg-amber-50 text-amber-700';
        }

        return 'bg-red-50 text-red-700';
    }

    function renderRow(rating) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const bookingCell = document.createElement('td');
        bookingCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        bookingCell.textContent = rating.booking_number;

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = rating.customer ? rating.customer.full_name : '—';

        const ratingCell = document.createElement('td');
        ratingCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${ratingBadgeClasses(rating.rating_value)}`;
        badge.textContent = `${rating.rating_value} / 5`;
        ratingCell.appendChild(badge);

        const commentCell = document.createElement('td');
        commentCell.className = 'px-5 py-3.5 text-slate-500';
        commentCell.textContent = rating.comment ?? '—';

        const createdCell = document.createElement('td');
        createdCell.className = 'px-5 py-3.5 text-slate-500';
        createdCell.textContent = formatDateTime(rating.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/ratings/${encodeURIComponent(rating.booking_uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(bookingCell, customerCell, ratingCell, commentCell, createdCell, linkCell);

        return row;
    }

    async function loadRatings(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/ratings?${params.toString()}`);
            const ratings = response.data.ratings || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...ratings.map(renderRow));

            if (ratings.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load ratings.';
            showError(message);
        }
    }

    function renderPagination(pageInfo, params) {
        const { page: currentPage, per_page: perPage, total, last_page: lastPage } = pageInfo;
        const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, total);

        paginationSummary.textContent = `Showing ${start}-${end} of ${total}`;

        prevPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = currentPage >= lastPage;

        prevPageButton.onclick = () => goToPage(params, currentPage - 1);
        nextPageButton.onclick = () => goToPage(params, currentPage + 1);
    }

    function goToPage(params, targetPage) {
        const next = new URLSearchParams(params);
        next.set('page', String(targetPage));
        navigate(next);
    }

    function navigate(params) {
        const query = params.toString();
        const url = query ? `/admin/ratings?${query}` : '/admin/ratings';
        window.history.pushState({}, '', url);
        loadRatings(params);
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const params = paramsFromForm();
        params.set('page', '1');
        navigate(params);
    });

    clearButton.addEventListener('click', () => {
        filterForm.reset();
        navigate(new URLSearchParams());
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadRatings(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadRatings(initialParams);
        }
    });
}
