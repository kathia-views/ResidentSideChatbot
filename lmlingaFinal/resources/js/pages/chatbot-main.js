/**
 * Resident AI Chatbot main interface — UI-only interactions.
 * No AI API, persistence, auth, or backend calls.
 */

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isMobileViewport() {
    return window.matchMedia('(max-width: 767.98px)').matches;
}

function formatTime(date = new Date()) {
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function getFocusableElements(container) {
    if (!container) {
        return [];
    }

    const selector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'textarea:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    return Array.from(container.querySelectorAll(selector)).filter((el) => {
        if (el.hidden || el.getAttribute('aria-hidden') === 'true') {
            return false;
        }
        if (el.closest('[hidden]')) {
            return false;
        }
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') {
            return false;
        }
        return el.getClientRects().length > 0;
    });
}

function trapFocus(event, container) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusableElements(container);
    if (focusables.length === 0) {
        event.preventDefault();
        container.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (event.shiftKey) {
        if (active === first || !container.contains(active)) {
            event.preventDefault();
            last.focus();
        }
    } else if (active === last) {
        event.preventDefault();
        first.focus();
    }
}

const NOTIFICATION_STATUS_LABELS = {
    upcoming: 'Upcoming',
    today: 'Today',
    completed: 'Completed',
    cancelled: 'Cancelled',
    rescheduled: 'Rescheduled',
};

function initChatbotNotifications(root) {
    const notificationsRoot = root.querySelector('[data-lml-notifications]');
    const toggle = root.querySelector('[data-lml-notifications-toggle]');
    const panel = root.querySelector('[data-lml-notifications-panel]');
    const badge = root.querySelector('[data-lml-notifications-badge]');
    const modalRoot = root.querySelector('[data-lml-notification-modal]');
    const modalPanel = root.querySelector('[data-lml-notification-modal-panel]');
    const modalBackdrop = root.querySelector('[data-lml-notification-modal-backdrop]');
    const modalCloseBtn = root.querySelector('[data-lml-notification-modal-close]');
    const modalDismissBtn = root.querySelector('[data-lml-notification-modal-dismiss]');

    if (!notificationsRoot || !toggle || !panel || !modalRoot || !modalPanel) {
        return;
    }

    let activeNotificationItem = null;
    let returnFocusEl = null;

    function getNotificationItems() {
        return Array.from(root.querySelectorAll('[data-lml-notification-item]'));
    }

    function getUnreadCount() {
        return getNotificationItems().filter(
            (item) => item.dataset.notificationRead !== 'true'
        ).length;
    }

    function syncUnreadBadge() {
        const unread = getUnreadCount();

        if (!badge) {
            if (unread > 0) {
                toggle.setAttribute('aria-label', `Notifications, ${unread} unread`);
            } else {
                toggle.setAttribute('aria-label', 'Notifications');
            }
            return;
        }

        if (unread <= 0) {
            badge.hidden = true;
            badge.textContent = '0';
            badge.setAttribute('aria-label', '0 unread');
            toggle.setAttribute('aria-label', 'Notifications');
            return;
        }

        badge.hidden = false;
        badge.textContent = String(unread);
        badge.setAttribute('aria-label', `${unread} unread`);
        toggle.setAttribute('aria-label', `Notifications, ${unread} unread`);
    }

    function setNotificationsOpen(open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            root.querySelectorAll('[data-lml-sidebar-tab]').forEach((el) => {
                el.classList.toggle('is-active', el.dataset.lmlSidebarTab === 'notifications');
            });
        }

        if (open && root.classList.contains('is-sidebar-collapsed') && !isMobileViewport()) {
            root.classList.remove('is-sidebar-collapsed');
            const desktopToggle = root.querySelector('[data-lml-sidebar-toggle]');
            if (desktopToggle) {
                desktopToggle.setAttribute('aria-expanded', 'true');
                desktopToggle.setAttribute('aria-label', 'Collapse sidebar');
                desktopToggle.setAttribute('title', 'Collapse sidebar');
            }
        }
    }

    function clearSelectedNotificationItems() {
        getNotificationItems().forEach((item) => {
            item.classList.remove('is-selected');
        });
    }

    function renderStatusBadge(statusEl, status) {
        if (!statusEl) {
            return;
        }

        statusEl.className = 'lml-chatbot-notifications__status';
        statusEl.classList.add(`lml-chatbot-notifications__status--${status}`);

        const label = NOTIFICATION_STATUS_LABELS[status] || status;
        let icon = '';

        if (status === 'completed') {
            icon = '<i class="bi bi-check2" aria-hidden="true"></i>';
        } else if (status === 'cancelled') {
            icon = '<i class="bi bi-x-circle" aria-hidden="true"></i>';
        } else if (status === 'rescheduled') {
            icon = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i>';
        }

        statusEl.innerHTML = `${icon}<span>${label}</span>`;
    }

    function populateModal(data) {
        const serviceEl = root.querySelector('[data-lml-notification-modal-service]');
        const reminderEl = root.querySelector('[data-lml-notification-modal-reminder]');
        const detailMap = {
            service: data.service,
            member: data.member,
            relationship: data.relationship,
            date: data.date,
            time: data.time,
            place: data.place,
        };

        if (serviceEl) {
            serviceEl.textContent = data.service;
        }

        Object.entries(detailMap).forEach(([key, value]) => {
            const el = root.querySelector(`[data-lml-notification-modal-detail="${key}"]`);
            if (!el) {
                return;
            }
            el.textContent = value || '—';
        });

        renderStatusBadge(
            root.querySelector('[data-lml-notification-modal-detail="status"]'),
            data.status
        );

        if (reminderEl) {
            reminderEl.innerHTML = data.reminderHtml || '';
        }
    }

    function readNotificationData(item) {
        return {
            id: item.dataset.notificationId,
            service: item.dataset.notificationService,
            member: item.dataset.notificationMember,
            relationship: item.dataset.notificationRelationship,
            date: item.dataset.notificationDate,
            time: item.dataset.notificationTime,
            place: item.dataset.notificationPlace,
            status: item.dataset.notificationStatus,
            reminderHtml: item.dataset.notificationReminderHtml,
            read: item.dataset.notificationRead === 'true',
        };
    }

    function updateNotificationItemLabel(item) {
        const serviceShort =
            item.querySelector('.lml-chatbot-notifications__item-title')?.textContent?.trim() ||
            item.dataset.notificationService ||
            'Notification';
        const member = item.dataset.notificationMember || '';
        const isUnread = item.dataset.notificationRead !== 'true';

        let label = `${serviceShort}, ${member}`;
        if (isUnread) {
            label += ', unread';
        }
        item.setAttribute('aria-label', label);
    }

    function markNotificationRead(item) {
        if (!item || item.dataset.notificationRead === 'true') {
            return;
        }

        item.dataset.notificationRead = 'true';
        item.classList.remove('is-unread');
        item.classList.add('is-read');

        const dot = item.querySelector('.lml-chatbot-notifications__unread-dot');
        if (dot) {
            dot.remove();
        }

        const title = item.querySelector('.lml-chatbot-notifications__item-title');
        if (title) {
            title.style.fontWeight = '';
        }

        updateNotificationItemLabel(item);
        syncUnreadBadge();
    }

    function openNotificationModal(item) {
        markNotificationRead(item);

        activeNotificationItem = item;
        returnFocusEl = item;

        clearSelectedNotificationItems();
        item.classList.add('is-selected');
        populateModal(readNotificationData(item));

        modalRoot.hidden = false;
        document.body.style.overflow = 'hidden';

        window.requestAnimationFrame(() => {
            modalCloseBtn?.focus();
        });
    }

    function closeNotificationModal() {
        modalRoot.hidden = true;
        document.body.style.overflow =
            root.classList.contains('is-mobile-open') && isMobileViewport() ? 'hidden' : '';

        if (returnFocusEl) {
            returnFocusEl.focus();
        }

        activeNotificationItem = null;
    }

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        setNotificationsOpen(open);
    });

    root.addEventListener('click', (event) => {
        const item = event.target.closest('[data-lml-notification-item]');
        if (item && notificationsRoot.contains(item)) {
            event.preventDefault();
            openNotificationModal(item);
        }
    });

    [modalCloseBtn, modalDismissBtn, modalBackdrop].forEach((el) => {
        el?.addEventListener('click', () => {
            closeNotificationModal();
        });
    });

    document.addEventListener('keydown', (event) => {
        if (modalRoot.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeNotificationModal();
            return;
        }

        trapFocus(event, modalPanel);
    });

    getNotificationItems().forEach(updateNotificationItemLabel);
    syncUnreadBadge();
}

function initChatbotMain(root) {
    const overlay = root.querySelector('[data-lml-sidebar-overlay]');
    const desktopToggle = root.querySelector('[data-lml-sidebar-toggle]');
    const mobileToggle = root.querySelector('[data-lml-mobile-toggle]');
    const historyToggle = root.querySelector('[data-lml-history-toggle]');
    const historyPanel = root.querySelector('[data-lml-history-panel]');
    const searchInput = root.querySelector('[data-lml-chat-search]');
    const searchClear = root.querySelector('[data-lml-chat-search-clear]');
    const newChatBtn = root.querySelector('[data-lml-new-chat]');
    const messages = root.querySelector('[data-lml-messages]');
    const composer = root.querySelector('[data-lml-composer]');
    const composerInput = root.querySelector('[data-lml-composer-input]');
    const langLive = root.querySelector('[data-lml-lang-live]');
    const pinnedList = root.querySelector('[data-lml-chat-list="pinned"]');
    const recentList = root.querySelector('[data-lml-chat-list="recent"]');
    const pinnedEmpty = root.querySelector('[data-lml-pinned-empty]');
    const recentEmpty = root.querySelector('[data-lml-recent-empty]');
    const householdBtn = root.querySelector('[data-lml-household-btn]');
    const sidebar = root.querySelector('[data-lml-sidebar]');
    const sidebarTabs = Array.from(root.querySelectorAll('[data-lml-sidebar-tab]'));

    let lastMobileToggle = mobileToggle;
    let typingIndicator = null;
    let demoReplyTimer = null;

    function getChatItems() {
        return Array.from(root.querySelectorAll('[data-lml-chat-item]'));
    }

    function clearActiveConversationSelection() {
        getChatItems().forEach((item) => {
            item.classList.remove('lml-chatbot-main__chat-row--active');
            const selectBtn = item.querySelector('[data-lml-chat-select]');
            if (selectBtn) {
                selectBtn.removeAttribute('aria-current');
            }
        });
    }

    function getSidebarFocusableElements() {
        if (!sidebar) {
            return [];
        }

        const selector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'textarea:not([disabled])',
            'select:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        return Array.from(sidebar.querySelectorAll(selector)).filter((el) => {
            if (el.hidden || el.getAttribute('aria-hidden') === 'true') {
                return false;
            }
            if (el.closest('[hidden]')) {
                return false;
            }
            const style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return false;
            }
            return el.getClientRects().length > 0;
        });
    }

    function onMobileSidebarKeydown(event) {
        if (!root.classList.contains('is-mobile-open') || !isMobileViewport()) {
            return;
        }

        if (event.key === 'Escape') {
            closeMobileSidebar();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusables = getSidebarFocusableElements();
        if (focusables.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        const active = document.activeElement;
        const focusOutside = !sidebar || !sidebar.contains(active);

        if (event.shiftKey) {
            if (focusOutside || active === first) {
                event.preventDefault();
                last.focus();
            }
        } else if (focusOutside || active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function setActiveSidebarTab(tabName) {
        sidebarTabs.forEach((el) => {
            const active = el.dataset.lmlSidebarTab === tabName;
            el.classList.toggle('is-active', active);

            /*
             * aria-current="page" is reserved for the conversation destination:
             * New Chat (blank chat) OR a selected conversation row.
             * History / Household use visual .is-active + aria-expanded only.
             */
            if (el.dataset.lmlSidebarTab === 'new-chat') {
                if (active) {
                    el.setAttribute('aria-current', 'page');
                } else {
                    el.removeAttribute('aria-current');
                }
            } else if (el.dataset.lmlSidebarTab === 'notifications') {
                el.removeAttribute('aria-current');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    }

    function setDesktopCollapsed(collapsed) {
        root.classList.toggle('is-sidebar-collapsed', collapsed);
        if (collapsed) {
            setHistoryOpen(false);
            const notificationsPanel = root.querySelector('[data-lml-notifications-panel]');
            const notificationsToggle = root.querySelector('[data-lml-notifications-toggle]');
            if (notificationsPanel && notificationsToggle) {
                notificationsPanel.hidden = true;
                notificationsToggle.setAttribute('aria-expanded', 'false');
            }
        }
        if (!desktopToggle) {
            return;
        }
        desktopToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        desktopToggle.setAttribute(
            'aria-label',
            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
        );
        desktopToggle.setAttribute(
            'title',
            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
        );
    }

    function setMobileOpen(open) {
        root.classList.toggle('is-mobile-open', open);
        if (overlay) {
            overlay.hidden = !open;
        }
        if (mobileToggle) {
            mobileToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            mobileToggle.setAttribute('aria-label', open ? 'Close sidebar' : 'Open sidebar');
        }
        document.body.style.overflow = open && isMobileViewport() ? 'hidden' : '';

        document.removeEventListener('keydown', onMobileSidebarKeydown);

        if (open && isMobileViewport()) {
            document.addEventListener('keydown', onMobileSidebarKeydown);
            window.requestAnimationFrame(() => {
                const focusables = getSidebarFocusableElements();
                if (focusables.length > 0) {
                    focusables[0].focus();
                }
            });
            return;
        }

        if (!open && lastMobileToggle) {
            lastMobileToggle.focus();
        }
    }

    function closeMobileSidebar() {
        if (root.classList.contains('is-mobile-open')) {
            setMobileOpen(false);
        }
    }

    function setHistoryOpen(open) {
        if (!historyPanel || !historyToggle) {
            return;
        }
        historyPanel.hidden = !open;
        historyToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function updateEmptyStates(query = '') {
        const q = query.trim().toLowerCase();

        function syncSectionEmpty(listEl, emptyEl, emptyLabel) {
            if (!emptyEl) {
                return;
            }

            const items = listEl
                ? Array.from(listEl.querySelectorAll('[data-lml-chat-item]'))
                : [];

            const visible = items.filter((item) => {
                const title = (item.dataset.chatTitle || '').toLowerCase();
                const match = q === '' || title.includes(q);
                const row = item.closest('li');
                return match && row && !row.hidden;
            }).length;

            if (q !== '' && visible === 0) {
                emptyEl.hidden = false;
                emptyEl.textContent = 'No matching chats';
            } else if (items.length === 0) {
                emptyEl.hidden = false;
                emptyEl.textContent = emptyLabel;
            } else {
                emptyEl.hidden = visible > 0;
                emptyEl.textContent = emptyLabel;
            }
        }

        syncSectionEmpty(pinnedList, pinnedEmpty, 'No pinned chats');
        syncSectionEmpty(recentList, recentEmpty, 'No recent chats yet');
    }

    function filterChats(query) {
        const q = query.trim().toLowerCase();

        getChatItems().forEach((item) => {
            const title = (item.dataset.chatTitle || '').toLowerCase();
            const match = q === '' || title.includes(q);
            const row = item.closest('li');
            if (row) {
                row.hidden = !match;
            }
        });

        updateEmptyStates(q);
        syncSearchClearVisibility();
    }

    function syncSearchClearVisibility() {
        if (!searchClear || !searchInput) {
            return;
        }
        searchClear.hidden = searchInput.value.trim() === '';
    }

    function clearSearch() {
        if (!searchInput) {
            return;
        }
        searchInput.value = '';
        filterChats('');
        searchInput.focus();
    }

    function selectChatItem(selected) {
        getChatItems().forEach((item) => {
            const active = item === selected;
            item.classList.toggle('lml-chatbot-main__chat-row--active', active);
            const selectBtn = item.querySelector('[data-lml-chat-select]');
            if (!selectBtn) {
                return;
            }
            if (active) {
                selectBtn.setAttribute('aria-current', 'page');
            } else {
                selectBtn.removeAttribute('aria-current');
            }
        });
        setActiveSidebarTab('history');
        setHistoryOpen(true);
    }

    function setPinnedState(item, pinned) {
        const title = item.dataset.chatTitle || 'chat';
        const pinBtn = item.querySelector('[data-lml-pin]');
        const icon = pinBtn?.querySelector('i');

        item.dataset.pinned = pinned ? 'true' : 'false';

        if (pinBtn) {
            pinBtn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
            pinBtn.setAttribute('aria-label', pinned ? `Unpin ${title}` : `Pin ${title}`);
            pinBtn.setAttribute('title', pinned ? 'Unpin' : 'Pin');
        }

        if (icon) {
            icon.className = pinned ? 'bi bi-pin-angle-fill' : 'bi bi-pin-angle';
        }
    }

    function flashPinFeedback(item) {
        item.classList.remove('is-pin-flash');
        void item.offsetWidth;
        item.classList.add('is-pin-flash');

        const onEnd = () => {
            item.classList.remove('is-pin-flash');
            item.removeEventListener('animationend', onEnd);
        };
        item.addEventListener('animationend', onEnd);

        if (prefersReducedMotion()) {
            window.setTimeout(() => item.classList.remove('is-pin-flash'), 180);
        }
    }

    function togglePin(item) {
        const currentlyPinned = item.dataset.pinned === 'true';
        const targetList = currentlyPinned ? recentList : pinnedList;
        const listItem = item.closest('li');

        if (!targetList || !listItem) {
            return;
        }

        setPinnedState(item, !currentlyPinned);
        targetList.appendChild(listItem);
        filterChats(searchInput?.value || '');
        flashPinFeedback(item);
    }

    function appendMessage(role, text) {
        if (!messages) {
            return null;
        }

        const wrapper = document.createElement('div');
        wrapper.className = `lml-chatbot-main__message lml-chatbot-main__message--${role}`;

        if (role === 'assistant') {
            const dot = document.createElement('span');
            dot.className = 'lml-chatbot-main__message-dot';
            dot.setAttribute('aria-hidden', 'true');
            wrapper.appendChild(dot);
        }

        const bubble = document.createElement('div');
        bubble.className = 'lml-chatbot-main__bubble';

        const body = document.createElement('p');
        body.className = 'lml-chatbot-main__bubble-text';
        body.textContent = text;

        const time = document.createElement('time');
        time.className = 'lml-chatbot-main__bubble-time';
        time.textContent = formatTime();

        bubble.appendChild(body);
        bubble.appendChild(time);
        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;

        return wrapper;
    }

    /**
     * Temporary assistant typing bubble.
     * Reusable later when wiring a real AI backend stream/response.
     */
    function showTypingIndicator() {
        if (!messages) {
            return null;
        }

        removeTypingIndicator();

        const wrapper = document.createElement('div');
        wrapper.className =
            'lml-chatbot-main__message lml-chatbot-main__message--assistant lml-chatbot-main__message--typing';
        wrapper.setAttribute('data-lml-typing-indicator', '');

        const statusDot = document.createElement('span');
        statusDot.className = 'lml-chatbot-main__message-dot';
        statusDot.setAttribute('aria-hidden', 'true');

        const bubble = document.createElement('div');
        bubble.className = 'lml-chatbot-main__bubble';

        const statusText = document.createElement('span');
        statusText.className = 'visually-hidden';
        statusText.textContent = 'Assistant is typing…';

        const dots = document.createElement('div');
        dots.className = 'lml-chatbot-main__typing-dots';
        dots.setAttribute('aria-hidden', 'true');
        dots.innerHTML = '<span></span><span></span><span></span>';

        bubble.appendChild(statusText);
        bubble.appendChild(dots);
        wrapper.appendChild(statusDot);
        wrapper.appendChild(bubble);
        messages.appendChild(wrapper);
        messages.scrollTop = messages.scrollHeight;

        typingIndicator = wrapper;
        return wrapper;
    }

    function removeTypingIndicator() {
        if (typingIndicator && typingIndicator.parentNode) {
            typingIndicator.parentNode.removeChild(typingIndicator);
        }
        typingIndicator = null;
        if (messages) {
            const leftover = messages.querySelector('[data-lml-typing-indicator]');
            if (leftover) {
                leftover.remove();
            }
        }
    }

    function resetConversation() {
        if (!messages) {
            return;
        }
        if (demoReplyTimer) {
            window.clearTimeout(demoReplyTimer);
            demoReplyTimer = null;
        }
        removeTypingIndicator();
        clearActiveConversationSelection();
        messages.innerHTML = '';
        appendMessage(
            'assistant',
            'This is health chatbot for health center. How can I help you today?'
        );
        if (composerInput) {
            composerInput.value = '';
            composerInput.style.height = 'auto';
            composerInput.focus();
        }
    }

    function autoGrowTextarea() {
        if (!composerInput) {
            return;
        }
        composerInput.style.height = 'auto';
        composerInput.style.height = `${Math.min(composerInput.scrollHeight, 120)}px`;
    }

    if (desktopToggle) {
        desktopToggle.addEventListener('click', () => {
            if (isMobileViewport()) {
                return;
            }
            setDesktopCollapsed(!root.classList.contains('is-sidebar-collapsed'));
        });
    }

    if (mobileToggle) {
        mobileToggle.addEventListener('click', () => {
            lastMobileToggle = mobileToggle;
            setMobileOpen(!root.classList.contains('is-mobile-open'));
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    window.addEventListener('resize', () => {
        if (!isMobileViewport()) {
            if (root.classList.contains('is-mobile-open')) {
                setMobileOpen(false);
            }
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onMobileSidebarKeydown);
        }
    });

    if (historyToggle) {
        historyToggle.addEventListener('click', () => {
            const collapsed =
                !isMobileViewport() && root.classList.contains('is-sidebar-collapsed');

            setActiveSidebarTab('history');

            if (collapsed) {
                setDesktopCollapsed(false);
                setHistoryOpen(true);
                return;
            }

            const open = historyToggle.getAttribute('aria-expanded') !== 'true';
            setHistoryOpen(open);
        });
    }

    if (householdBtn) {
        householdBtn.addEventListener('click', () => {
            setActiveSidebarTab('household');
            /* Request Household Record uses a real href; Request Sent is status-only. */
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            filterChats(searchInput.value);
        });
    }

    if (searchClear) {
        searchClear.addEventListener('click', () => {
            clearSearch();
        });
    }

    root.addEventListener('click', (event) => {
        const pinBtn = event.target.closest('[data-lml-pin]');
        if (pinBtn && root.contains(pinBtn)) {
            event.preventDefault();
            event.stopPropagation();
            const item = pinBtn.closest('[data-lml-chat-item]');
            if (item) {
                togglePin(item);
            }
            return;
        }

        const selectBtn = event.target.closest('[data-lml-chat-select]');
        if (selectBtn && root.contains(selectBtn)) {
            const item = selectBtn.closest('[data-lml-chat-item]');
            if (item) {
                selectChatItem(item);
            }
        }
    });

    if (newChatBtn) {
        newChatBtn.addEventListener('click', () => {
            setActiveSidebarTab('new-chat');
            resetConversation();
            closeMobileSidebar();
        });
    }

    root.querySelectorAll('[data-lml-lang]').forEach((btn) => {
        btn.addEventListener('click', () => {
            root.querySelectorAll('[data-lml-lang]').forEach((other) => {
                const active = other === btn;
                other.classList.toggle('lml-chatbot-main__lang-btn--active', active);
                other.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (langLive) {
                langLive.textContent = `Language changed to ${btn.dataset.lmlLang}`;
            }
        });
    });

    if (composer && composerInput) {
        composer.addEventListener('submit', (event) => {
            event.preventDefault();
            const text = composerInput.value.trim();
            if (!text) {
                return;
            }

            appendMessage('user', text);
            composerInput.value = '';
            autoGrowTextarea();
            showTypingIndicator();

            if (demoReplyTimer) {
                window.clearTimeout(demoReplyTimer);
            }

            demoReplyTimer = window.setTimeout(
                () => {
                    removeTypingIndicator();
                    appendMessage(
                        'assistant',
                        'Thanks for your message. This is a demo response for UI review only.'
                    );
                    demoReplyTimer = null;
                },
                prefersReducedMotion() ? 200 : 900
            );
        });

        composerInput.addEventListener('input', autoGrowTextarea);

        composerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                composer.requestSubmit();
            }
        });
    }

    syncSearchClearVisibility();
    updateEmptyStates();

    initChatbotNotifications(root);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-chatbot-main]').forEach((root) => {
        initChatbotMain(root);
    });
});
