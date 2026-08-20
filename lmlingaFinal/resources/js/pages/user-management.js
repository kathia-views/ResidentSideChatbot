/**
 * User Management — Health Workers + Residents tabs (UI only).
 * Health Worker filters/menus remain here. Resident filters live in
 * user-management-residents.js.
 */

const toastTimers = new WeakMap();

function showToast(root, message, toastSelector = '[data-um-toast]') {
    const toast = root.querySelector(toastSelector);
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const previousTimer = toastTimers.get(toast);
    if (previousTimer) {
        window.clearTimeout(previousTimer);
    }

    const timerId = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
        toastTimers.delete(toast);
    }, 3600);

    toastTimers.set(toast, timerId);
}

function getMenuItems(menuList) {
    return Array.from(menuList.querySelectorAll('[role="menuitem"]'));
}

function closeMenu(menuRoot, { restoreFocus = false } = {}) {
    const toggle = menuRoot.querySelector('[data-hw-menu-toggle]');
    const list = menuRoot.querySelector('[data-hw-menu-list]');

    if (!toggle || !list || list.hidden) {
        return;
    }

    list.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');

    if (restoreFocus) {
        toggle.focus();
    }
}

function closeAllMenus(root, except = null) {
    root.querySelectorAll('[data-hw-menu]').forEach((menuRoot) => {
        if (except && menuRoot === except) {
            return;
        }
        closeMenu(menuRoot);
    });
}

function openMenu(menuRoot) {
    const toggle = menuRoot.querySelector('[data-hw-menu-toggle]');
    const list = menuRoot.querySelector('[data-hw-menu-list]');

    if (!toggle || !list) {
        return;
    }

    list.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');

    const items = getMenuItems(list);
    if (items[0]) {
        items[0].focus();
    }
}

function applyWorkerFilters(root) {
    const workersPanel = root.querySelector('[data-um-panel="workers"]');
    if (!workersPanel) {
        return;
    }

    const cards = Array.from(workersPanel.querySelectorAll('[data-hw-card]'));
    const empty = workersPanel.querySelector('[data-um-empty]');
    const grid = workersPanel.querySelector('[data-um-grid]');
    const searchInput = workersPanel.querySelector('[data-um-search]');
    const categorySelect = workersPanel.querySelector('[data-um-category]');

    const query = (searchInput?.value || '').trim().toLowerCase();
    const category = categorySelect?.value || 'all';
    const normalizedCategory = !category || category === 'all' ? 'all' : category;

    let visible = 0;

    cards.forEach((card) => {
        const name = (card.dataset.hwName || '').toLowerCase();
        const role = card.dataset.hwRole || '';
        const matchesSearch = !query || name.includes(query) || role.toLowerCase().includes(query);
        const matchesCategory = normalizedCategory === 'all' || role === normalizedCategory;
        const show = matchesSearch && matchesCategory;

        card.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (empty) {
        empty.hidden = visible > 0;
    }

    if (grid) {
        grid.hidden = visible === 0;
    }
}

function updatePageSubtitle(root, tabKey) {
    const subtitle = document.querySelector('.lml-topbar__subtitle');
    if (!subtitle) {
        return;
    }

    if (tabKey === 'residents') {
        subtitle.textContent = root.dataset.subtitleResidents
            || 'Manage user accounts and access permissions.';
        return;
    }

    subtitle.textContent = root.dataset.subtitleWorkers
        || 'Manage accounts of the Barangay Health Workers';
}

function activateTab(root, tabKey) {
    const tabs = Array.from(root.querySelectorAll('[data-um-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-um-panel]'));

    tabs.forEach((tab) => {
        const isActive = tab.dataset.umTab === tabKey;
        tab.classList.toggle('lml-user-mgmt__tab--active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.tabIndex = isActive ? 0 : -1;
    });

    panels.forEach((panel) => {
        panel.hidden = panel.dataset.umPanel !== tabKey;
    });

    updatePageSubtitle(root, tabKey);
    closeAllMenus(root);

    try {
        const url = new URL(window.location.href);
        if (tabKey === 'residents') {
            url.searchParams.set('tab', 'residents');
        } else {
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url);
    } catch {
        // Ignore history errors in non-browser contexts.
    }
}

function resolveInitialTab(root) {
    try {
        const params = new URLSearchParams(window.location.search);
        const tab = (params.get('tab') || '').toLowerCase();
        if (tab === 'residents' && root.querySelector('[data-um-tab="residents"]')) {
            return 'residents';
        }
    } catch {
        // Fall through.
    }

    return 'workers';
}

function handleWorkerPlaceholderAction(root, action, workerName) {
    const label = workerName ? ` for ${workerName}` : '';
    const messages = {
        photo: `Photo${label} is a UI placeholder — no image was uploaded.`,
        view: `View${label} is a UI placeholder — no details loaded.`,
        delete: `Delete${label} is a UI placeholder — nothing was removed.`,
    };

    showToast(root, messages[action] || 'Action is a UI placeholder.', '[data-um-toast]');
}

function initMenuWidget(menuRoot) {
    menuRoot.addEventListener('focusout', (event) => {
        const list = menuRoot.querySelector('[data-hw-menu-list]');
        if (!list || list.hidden) {
            return;
        }

        const nextTarget = event.relatedTarget;
        if (nextTarget && menuRoot.contains(nextTarget)) {
            return;
        }

        closeMenu(menuRoot);
    });
}

function initUserManagement(root) {
    const workersPanel = root.querySelector('[data-um-panel="workers"]');
    const searchInput = workersPanel?.querySelector('[data-um-search]');
    const categorySelect = workersPanel?.querySelector('[data-um-category]');
    const tabs = Array.from(root.querySelectorAll('[data-um-tab]'));

    root.querySelectorAll('[data-hw-menu]').forEach((menuRoot) => {
        initMenuWidget(menuRoot);
    });

    searchInput?.addEventListener('input', () => applyWorkerFilters(root));
    categorySelect?.addEventListener('change', () => applyWorkerFilters(root));

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            activateTab(root, tab.dataset.umTab);
        });

        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            event.preventDefault();

            let nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            }

            const nextTab = tabs[nextIndex];
            if (nextTab) {
                activateTab(root, nextTab.dataset.umTab);
                nextTab.focus();
            }
        });
    });

    root.addEventListener('click', (event) => {
        const hwActionBtn = event.target.closest('[data-hw-action]');
        if (hwActionBtn && root.contains(hwActionBtn)) {
            const action = hwActionBtn.dataset.hwAction;
            const workerName = hwActionBtn.dataset.hwWorker || '';

            closeAllMenus(root);

            if (action === 'edit' || action === 'view') {
                return;
            }

            event.preventDefault();
            handleWorkerPlaceholderAction(root, action, workerName);
            return;
        }

        const hwToggle = event.target.closest('[data-hw-menu-toggle]');
        if (hwToggle && root.contains(hwToggle)) {
            const menuRoot = hwToggle.closest('[data-hw-menu]');
            const list = menuRoot?.querySelector('[data-hw-menu-list]');
            if (!menuRoot || !list) {
                return;
            }

            const willOpen = list.hidden;
            closeAllMenus(root, menuRoot);

            if (willOpen) {
                openMenu(menuRoot);
            } else {
                closeMenu(menuRoot);
            }
            return;
        }

        if (!event.target.closest('[data-hw-menu]')) {
            closeAllMenus(root);
        }
    });

    root.addEventListener('keydown', (event) => {
        const menuRoot = event.target.closest('[data-hw-menu]');
        if (!menuRoot || !root.contains(menuRoot)) {
            return;
        }

        const list = menuRoot.querySelector('[data-hw-menu-list]');
        if (!list || list.hidden) {
            return;
        }

        const items = getMenuItems(list);
        const currentIndex = items.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu(menuRoot, { restoreFocus: true });
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const next = items[(currentIndex + 1) % items.length] || items[0];
            next?.focus();
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            const prev = items[(currentIndex - 1 + items.length) % items.length] || items[0];
            prev?.focus();
            return;
        }

        if (event.key === 'Home') {
            event.preventDefault();
            items[0]?.focus();
            return;
        }

        if (event.key === 'End') {
            event.preventDefault();
            items[items.length - 1]?.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            closeAllMenus(root);
        }
    });

    activateTab(root, resolveInitialTab(root));
    applyWorkerFilters(root);
}

document.querySelectorAll('[data-lml-user-mgmt]').forEach((root) => {
    initUserManagement(root);
});
