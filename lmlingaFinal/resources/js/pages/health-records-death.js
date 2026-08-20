/**
 * Health Records → Death barangay-wide listing.
 * Listing filters submit through GET and Laravel paginates 7 records per page.
 * Resident search runs on the dedicated Select a resident page.
 */

function initListingFilters(root) {
    const form = root.querySelector('[data-hr-death-filter-form]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('[data-hr-death-search]');
    const selects = form.querySelectorAll('select[data-hr-death-zone], select[data-hr-death-cause], select[data-hr-death-sex], select[data-hr-death-year]');
    let searchTimer;

    const submitFilters = () => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    selects.forEach((select) => {
        select.addEventListener('change', submitFilters);
    });

    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(submitFilters, 350);
    });

    searchInput?.addEventListener('search', submitFilters);
}

function initResidentSearch(root) {
    const input = root.querySelector('[data-hr-death-resident-search]');
    const zoneSelect = root.querySelector('[data-hr-death-resident-zone]');
    const statusSelect = root.querySelector('[data-hr-death-resident-status]');
    const rows = Array.from(root.querySelectorAll('[data-hr-death-resident-row]'));
    if (!input || rows.length === 0) {
        return;
    }

    const apply = () => {
        const query = input.value.trim().toLowerCase();
        const zone = (zoneSelect?.value || 'all').trim();
        const status = (statusSelect?.value || 'all').trim();

        rows.forEach((row) => {
            const name = row.dataset.name || '';
            const rowZone = row.dataset.zone || '';
            const rowStatus = row.dataset.statusLabel || '';
            const matchesName = !query || name.includes(query);
            const matchesZone = zone === 'all' || rowZone === zone;
            const matchesStatus = status === 'all' || rowStatus === status;
            row.hidden = !(matchesName && matchesZone && matchesStatus);
        });
    };

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    zoneSelect?.addEventListener('change', apply);
    statusSelect?.addEventListener('change', apply);
}

function initHealthRecordsDeath(root) {
    initListingFilters(root);
    initResidentSearch(root);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-death]').forEach((root) => {
        initHealthRecordsDeath(root);
    });
});
