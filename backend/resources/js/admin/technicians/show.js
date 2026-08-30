/**
 * Admin Technician detail page (BLUE V1 Technician Admin Management).
 * Reuses the centralized Admin API client against GET/PATCH
 * /v1/admin/technicians/{technician}, POST .../status, POST
 * .../specializations, and the paginated .../jobs and .../ratings
 * endpoints. Never duplicates Booking-level operational mutations (start/
 * complete/reassign work) - "Open Booking" links go to the existing Admin
 * Booking page, which remains the only place those actions live (section
 * 15/38 of the BLUE V1 Technician Admin Management spec).
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-technician-page]');

if (page) {
    const technicianUuid = page.dataset.technicianUuid;

    const loadingEl = page.querySelector('[data-technician-loading]');
    const errorEl = page.querySelector('[data-technician-error]');
    const contentEl = page.querySelector('[data-technician-content]');

    let currentTechnician = null;
    let jobsPage = 1;
    let ratingsPage = 1;

    function setPageState(state, message) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        contentEl.classList.toggle('hidden', state !== 'ready');

        if (state === 'error') {
            errorEl.textContent = message;
        }
    }

    function text(selector, value) {
        const el = page.querySelector(selector);

        if (el) {
            el.textContent = value;
        }
    }

    function renderOverview(technician) {
        text('[data-field-full_name]', technician.full_name);
        text('[data-field-employee_code]', technician.employee_code);
        text('[data-field-phone_number]', technician.phone_number);
        text('[data-field-email]', technician.email || '—');
        text('[data-field-visible]', technician.is_phone_visible_to_customer ? 'Yes' : 'No');
        text('[data-field-internal_note]', technician.internal_note || '—');

        const badge = page.querySelector('[data-status-badge]');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(technician.status)}`;
        badge.textContent = statusLabel(technician.status);

        text('[data-assignable-badge]', technician.is_assignable ? 'Assignable' : 'Not assignable');

        page.querySelector('[data-status-select]').value = technician.status;
    }

    function renderPerformance(performance) {
        text('[data-perf-rating]', performance.average_rating === null ? 'No ratings yet' : `${performance.average_rating.toFixed(1)} / 5`);
        text('[data-perf-rating_count]', String(performance.rating_count));
        text('[data-perf-completed_jobs]', String(performance.completed_jobs));
        text('[data-perf-active_jobs]', String(performance.active_jobs));
        text('[data-perf-in_progress_jobs]', String(performance.in_progress_jobs));
    }

    function renderSpecializations(technician) {
        const list = page.querySelector('[data-specializations-list]');

        if (technician.specializations.length === 0) {
            list.innerHTML = '<span class="text-sm text-slate-500">No specializations yet.</span>';
            return;
        }

        list.replaceChildren(...technician.specializations.map((specialization) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700';
            chip.textContent = specialization.is_primary ? `${specialization.name} (Primary)` : specialization.name;

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'text-slate-400 hover:text-red-600';
            removeButton.textContent = '×';
            removeButton.title = 'Deactivate this specialization';
            removeButton.addEventListener('click', () => deactivateSpecialization(specialization.id));

            chip.appendChild(removeButton);
            return chip;
        }));
    }

    function renderCurrentWork(assignments) {
        const empty = page.querySelector('[data-current-work-empty]');
        const wrapper = page.querySelector('[data-current-work-wrapper]');
        const body = page.querySelector('[data-current-work-body]');

        if (assignments.length === 0) {
            empty.classList.remove('hidden');
            wrapper.classList.add('hidden');
            return;
        }

        empty.classList.add('hidden');
        wrapper.classList.remove('hidden');

        body.replaceChildren(...assignments.map((assignment) => {
            const row = document.createElement('tr');

            const bookingCell = document.createElement('td');
            bookingCell.className = 'px-3 py-2';
            const link = document.createElement('a');
            link.href = `/admin/bookings/${assignment.booking_uuid}`;
            link.className = 'font-medium text-blue-700 hover:underline';
            link.textContent = assignment.booking_number;
            bookingCell.appendChild(link);

            const serviceCell = document.createElement('td');
            serviceCell.className = 'px-3 py-2 text-slate-700';
            serviceCell.textContent = assignment.service_name;

            const statusCell = document.createElement('td');
            statusCell.className = 'px-3 py-2';
            const badge = document.createElement('span');
            badge.className = `rounded-full px-2 py-0.5 text-xs font-semibold ${statusBadgeClasses(assignment.item_status)}`;
            badge.textContent = statusLabel(assignment.item_status);
            statusCell.appendChild(badge);

            const apptCell = document.createElement('td');
            apptCell.className = 'px-3 py-2 text-slate-500';
            apptCell.textContent = formatDateTime(assignment.appointment_starts_at);

            row.append(bookingCell, serviceCell, statusCell, apptCell);
            return row;
        }));
    }

    function renderJobs(jobs) {
        const empty = page.querySelector('[data-jobs-empty]');
        const wrapper = page.querySelector('[data-jobs-wrapper]');
        const body = page.querySelector('[data-jobs-body]');

        empty.classList.toggle('hidden', jobs.length !== 0);
        wrapper.classList.toggle('hidden', jobs.length === 0);

        body.replaceChildren(...jobs.map((job) => {
            const row = document.createElement('tr');

            const bookingCell = document.createElement('td');
            bookingCell.className = 'px-3 py-2';
            const link = document.createElement('a');
            link.href = `/admin/bookings/${job.booking_uuid}`;
            link.className = 'font-medium text-blue-700 hover:underline';
            link.textContent = job.booking_number;
            bookingCell.appendChild(link);

            const serviceCell = document.createElement('td');
            serviceCell.className = 'px-3 py-2 text-slate-700';
            serviceCell.textContent = job.service_name;

            const statusCell = document.createElement('td');
            statusCell.className = 'px-3 py-2';
            const badge = document.createElement('span');
            badge.className = `rounded-full px-2 py-0.5 text-xs font-semibold ${statusBadgeClasses(job.item_status)}`;
            badge.textContent = statusLabel(job.item_status);
            statusCell.appendChild(badge);

            if (job.credited_as_completed) {
                const creditNote = document.createElement('div');
                creditNote.className = 'mt-0.5 text-xs text-emerald-600';
                creditNote.textContent = 'Credited';
                statusCell.appendChild(creditNote);
            }

            const assignedCell = document.createElement('td');
            assignedCell.className = 'px-3 py-2 text-slate-500';
            assignedCell.textContent = formatDateTime(job.assigned_at);

            const releasedCell = document.createElement('td');
            releasedCell.className = 'px-3 py-2 text-slate-500';
            releasedCell.textContent = job.released_at ? formatDateTime(job.released_at) : '—';

            const reasonCell = document.createElement('td');
            reasonCell.className = 'px-3 py-2 text-slate-500';
            reasonCell.textContent = job.release_reason || '—';

            row.append(bookingCell, serviceCell, statusCell, assignedCell, releasedCell, reasonCell);
            return row;
        }));
    }

    function renderRatings(ratings) {
        const empty = page.querySelector('[data-ratings-empty]');
        const list = page.querySelector('[data-ratings-list]');

        empty.classList.toggle('hidden', ratings.length !== 0);

        list.replaceChildren(...ratings.map((rating) => {
            const card = document.createElement('div');
            card.className = 'rounded-xl border border-slate-200 p-4';

            const header = document.createElement('div');
            header.className = 'flex items-center justify-between';

            const stars = document.createElement('div');
            stars.className = 'font-semibold text-slate-900';
            stars.textContent = `${rating.rating_value} / 5`;

            const link = document.createElement('a');
            link.href = `/admin/bookings/${rating.booking_uuid}`;
            link.className = 'text-xs font-medium text-blue-700 hover:underline';
            link.textContent = rating.booking_number;

            header.append(stars, link);
            card.appendChild(header);

            if (!rating.is_exclusive) {
                const shared = document.createElement('div');
                shared.className = 'mt-1 text-xs text-amber-600';
                shared.textContent = 'Shared booking - other technicians also worked on this job. Not counted in the average.';
                card.appendChild(shared);
            }

            if (rating.comment) {
                const comment = document.createElement('p');
                comment.className = 'mt-2 text-sm text-slate-600';
                comment.textContent = rating.comment;
                card.appendChild(comment);
            }

            const date = document.createElement('div');
            date.className = 'mt-2 text-xs text-slate-400';
            date.textContent = formatDateTime(rating.submitted_at);
            card.appendChild(date);

            return card;
        }));
    }

    function renderSubPagination(prefix, pageInfo, onPage) {
        const { page: currentPage, per_page: perPage, total, last_page: lastPage } = pageInfo;
        const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, total);

        page.querySelector(`[data-${prefix}-pagination-summary]`).textContent = `Showing ${start}-${end} of ${total}`;

        const prevButton = page.querySelector(`[data-${prefix}-prev]`);
        const nextButton = page.querySelector(`[data-${prefix}-next]`);

        prevButton.disabled = currentPage <= 1;
        nextButton.disabled = currentPage >= lastPage;

        prevButton.onclick = () => onPage(currentPage - 1);
        nextButton.onclick = () => onPage(currentPage + 1);
    }

    async function loadJobs() {
        const response = await request(`/api/v1/admin/technicians/${technicianUuid}/jobs?page=${jobsPage}&per_page=10`);
        renderJobs(response.data.jobs);
        renderSubPagination('jobs', response.data.pagination, (targetPage) => {
            jobsPage = targetPage;
            loadJobs();
        });
    }

    async function loadRatings() {
        const response = await request(`/api/v1/admin/technicians/${technicianUuid}/ratings?page=${ratingsPage}&per_page=10`);
        renderRatings(response.data.ratings);
        renderSubPagination('ratings', response.data.pagination, (targetPage) => {
            ratingsPage = targetPage;
            loadRatings();
        });
    }

    async function loadSpecializationOptions() {
        const select = page.querySelector('[data-specialization-select]');

        try {
            const response = await request('/api/v1/admin/specializations');
            select.replaceChildren(...response.data.specializations
                .filter((specialization) => specialization.is_active)
                .map((specialization) => {
                    const option = document.createElement('option');
                    option.value = String(specialization.id);
                    option.textContent = specialization.name;
                    return option;
                }));
        } catch {
            select.innerHTML = '<option value="">Unable to load specializations</option>';
        }
    }

    async function loadTechnician() {
        setPageState('loading');

        try {
            const response = await request(`/api/v1/admin/technicians/${technicianUuid}`);
            currentTechnician = response.data.technician;

            renderOverview(currentTechnician);
            renderPerformance(currentTechnician.performance);
            renderSpecializations(currentTechnician);
            renderCurrentWork(currentTechnician.current_assignments);

            setPageState('ready');

            await Promise.all([loadJobs(), loadRatings()]);
        } catch (error) {
            setPageState('error', error instanceof ApiError ? error.message : 'Unable to load this technician.');
        }
    }

    // -- Status change --------------------------------------------------

    page.querySelector('[data-apply-status]').addEventListener('click', async () => {
        const statusError = page.querySelector('[data-status-error]');
        statusError.classList.add('hidden');

        const status = page.querySelector('[data-status-select]').value;

        try {
            const response = await request(`/api/v1/admin/technicians/${technicianUuid}/status`, {
                method: 'POST',
                body: { status },
            });

            currentTechnician = response.data.technician;
            renderOverview(currentTechnician);
            renderPerformance(currentTechnician.performance);
        } catch (error) {
            statusError.textContent = error instanceof ApiError ? error.message : 'Unable to change status.';
            statusError.classList.remove('hidden');
        }
    });

    // -- Edit Technician modal -------------------------------------------

    const editModal = document.querySelector('[data-edit-technician-modal]');
    const editForm = editModal.querySelector('[data-edit-technician-form]');
    const editError = editModal.querySelector('[data-edit-technician-error]');

    page.querySelector('[data-open-edit]').addEventListener('click', () => {
        editForm.elements.namedItem('full_name').value = currentTechnician.full_name;
        editForm.elements.namedItem('phone_number').value = currentTechnician.phone_number;
        editForm.elements.namedItem('email').value = currentTechnician.email || '';
        editForm.elements.namedItem('is_phone_visible_to_customer').checked = currentTechnician.is_phone_visible_to_customer;
        editForm.elements.namedItem('internal_note').value = currentTechnician.internal_note || '';
        editError.classList.add('hidden');
        editModal.style.display = 'flex';
    });

    editModal.querySelector('[data-edit-technician-cancel]').addEventListener('click', () => {
        editModal.style.display = 'none';
    });

    editForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        editError.classList.add('hidden');

        try {
            const response = await request(`/api/v1/admin/technicians/${technicianUuid}`, {
                method: 'PATCH',
                body: {
                    full_name: editForm.elements.namedItem('full_name').value.trim(),
                    phone_number: editForm.elements.namedItem('phone_number').value.trim(),
                    email: editForm.elements.namedItem('email').value.trim() || null,
                    is_phone_visible_to_customer: editForm.elements.namedItem('is_phone_visible_to_customer').checked,
                    internal_note: editForm.elements.namedItem('internal_note').value.trim() || null,
                },
            });

            currentTechnician = response.data.technician;
            renderOverview(currentTechnician);
            editModal.style.display = 'none';
        } catch (error) {
            editError.textContent = error instanceof ApiError ? error.message : 'Unable to save changes.';
            editError.classList.remove('hidden');
        }
    });

    // -- Specializations ---------------------------------------------------

    async function deactivateSpecialization(specializationId) {
        try {
            const response = await request(`/api/v1/admin/technicians/${technicianUuid}/specializations`, {
                method: 'POST',
                body: { specialization_id: specializationId, is_primary: false, is_active: false },
            });

            currentTechnician = response.data.technician;
            renderSpecializations(currentTechnician);
        } catch (error) {
            window.alert(error instanceof ApiError ? error.message : 'Unable to update this specialization.');
        }
    }

    page.querySelector('[data-add-specialization-form]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const specializationError = page.querySelector('[data-specialization-error]');
        specializationError.classList.add('hidden');

        const specializationId = Number(form.elements.namedItem('specialization_id').value);
        const isPrimary = form.elements.namedItem('is_primary').checked;

        try {
            const response = await request(`/api/v1/admin/technicians/${technicianUuid}/specializations`, {
                method: 'POST',
                body: { specialization_id: specializationId, is_primary: isPrimary, is_active: true },
            });

            currentTechnician = response.data.technician;
            renderSpecializations(currentTechnician);
            form.reset();
        } catch (error) {
            specializationError.textContent = error instanceof ApiError ? error.message : 'Unable to add this specialization.';
            specializationError.classList.remove('hidden');
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadSpecializationOptions();
            loadTechnician();
        }
    });
}
