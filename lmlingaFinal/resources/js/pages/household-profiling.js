/**
 * Household Profiling — list UI interactions (DB-05 Phase 4).
 * Filters/search are client-side. Delete/export remain UI demonstrations.
 * Demo-only Add keeps a preview toast; DB Add uses a real create link.
 */

function showToast(root, message) {
    const toast = root.querySelector('[data-hh-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function applyFilters(root) {
    const tbody = root.querySelector('[data-hh-tbody]');
    const empty = root.querySelector('[data-hh-empty]');
    const results = root.querySelector('[data-hh-results]');
    const tableScroll = root.querySelector('.lml-hh-profiling__table-scroll');
    const searchInput = root.querySelector('[data-hh-search]');
    const zoneSelect = root.querySelector('[data-hh-zone]');
    const streetSelect = root.querySelector('[data-hh-street]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hh-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const street = streetSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const houseHead = (row.dataset.houseHead || '').toLowerCase();
        const rowZone = row.dataset.zone || '';
        const rowStreet = row.dataset.street || '';

        const matchesSearch = !query || houseHead.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesStreet = street === 'all' || rowStreet === street;
        const show = matchesSearch && matchesZone && matchesStreet;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} households`;
    }

    if (empty) {
        empty.hidden = visible > 0;
    }

    if (tableScroll) {
        tableScroll.hidden = visible === 0;
    }
}

function lockPageScroll() {
    document.body.dataset.hhScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockPageScroll() {
    if (document.body.dataset.hhScrollLocked !== 'true') {
        return;
    }

    delete document.body.dataset.hhScrollLocked;
    document.body.style.overflow = '';
}

function openDialog(root, householdNo, returnFocusEl) {
    const backdrop = root.querySelector('[data-hh-dialog]');
    const panel = root.querySelector('[data-hh-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-dialog-cancel]');
    if (!backdrop || !panel) {
        return;
    }

    root._hhDeleteTarget = householdNo;
    root._hhReturnFocus = returnFocusEl || null;

    backdrop.hidden = false;
    lockPageScroll();

    // Cancel is the default focus target when the dialog opens.
    if (cancelBtn) {
        cancelBtn.focus();
    } else {
        panel.focus();
    }
}

function closeDialog(root, { restoreFocus = true } = {}) {
    const backdrop = root.querySelector('[data-hh-dialog]');
    if (!backdrop) {
        return;
    }

    backdrop.hidden = true;
    unlockPageScroll();
    root._hhDeleteTarget = null;

    if (restoreFocus && root._hhReturnFocus instanceof HTMLElement) {
        root._hhReturnFocus.focus();
    }

    root._hhReturnFocus = null;
}

function trapFocus(event, panel) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusable(panel);
    if (!focusables.length) {
        event.preventDefault();
        panel.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function initHouseholdProfiling(root) {
    const exportBtn = root.querySelector('[data-hh-export]');
    const searchInput = root.querySelector('[data-hh-search]');
    const zoneSelect = root.querySelector('[data-hh-zone]');
    const streetSelect = root.querySelector('[data-hh-street]');
    const dialog = root.querySelector('[data-hh-dialog]');
    const dialogPanel = root.querySelector('[data-hh-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-dialog-cancel]');
    const confirmBtn = root.querySelector('[data-hh-dialog-confirm]');

    const refresh = () => applyFilters(root);

    exportBtn?.addEventListener('click', () => {
        showToast(root, 'Export is not available during the UI phase.');
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    streetSelect?.addEventListener('change', refresh);

    root.addEventListener('click', (event) => {
        const actionBtn = event.target.closest('[data-hh-action]');
        if (actionBtn && root.contains(actionBtn)) {
            const action = actionBtn.getAttribute('data-hh-action');
            const householdNo = actionBtn.getAttribute('data-household-no') || 'this household';

            if (action === 'add') {
                showToast(
                    root,
                    `Add member to ${householdNo} — demo preview only. Nothing is saved.`
                );
                return;
            }

            if (action === 'delete') {
                openDialog(root, householdNo, actionBtn);
            }

            return;
        }

        if (event.target === dialog) {
            closeDialog(root);
        }
    });

    cancelBtn?.addEventListener('click', () => {
        closeDialog(root);
    });

    confirmBtn?.addEventListener('click', () => {
        closeDialog(root);
        showToast(root, 'No household was deleted because this is the UI phase.');
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            event.preventDefault();
            closeDialog(root);
            return;
        }

        if (dialog && !dialog.hidden && dialogPanel) {
            trapFocus(event, dialogPanel);
        }
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hh-profiling]').forEach((root) => {
        initHouseholdProfiling(root);
    });
});
