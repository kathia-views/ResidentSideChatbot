@extends('layouts.app')

@section('title', 'Smart Health Support Chatbot - LMLinga')

@section('body')
    @php
        /*
         * Household CTA / verified access are database-driven for the session ResidentAccount.
         * Query-string ?verification= must never control verified household state or access.
         */
        $householdRequestState = (string) ($householdRequestState ?? 'none');
        if (! in_array($householdRequestState, ['none', 'pending', 'awaiting_otp', 'approved', 'denied'], true)) {
            $householdRequestState = 'none';
        }
        $householdDecisionReason = trim((string) ($householdDecisionReason ?? ''));
        $residentDisplayName = trim((string) ($residentDisplayName ?? 'Resident'));
        if ($residentDisplayName === '') {
            $residentDisplayName = 'Resident';
        }
        $householdDisplayNo = trim((string) ($householdDisplayNo ?? '-'));
        if ($householdDisplayNo === '') {
            $householdDisplayNo = '-';
        }

        /*
         * Verified presentation for optional demo notifications only.
         * Driven by Approved + verified OTP evidence (householdRequestState === approved),
         * never by ?verification= query string.
         */
        $isHouseholdVerified = $householdRequestState === 'approved';
        $verificationState = $isHouseholdVerified ? 'verified' : 'unverified';
        $chatbotNotifications = $isHouseholdVerified
            ? require resource_path('demo/chatbot-notifications.php')
            : [];
        $chatbotUnreadCount = collect($chatbotNotifications)->where('is_read', false)->count();
    @endphp

    <div
        class="lml-chatbot-main"
        data-lml-chatbot-main
        data-lml-verification-state="{{ $verificationState }}"
    >
        <div class="lml-chatbot-main__shell">
            <div
                class="lml-chatbot-main__overlay"
                data-lml-sidebar-overlay
                hidden
            ></div>

            <aside
                id="chatbot-sidebar"
                class="lml-chatbot-main__sidebar"
                data-lml-sidebar
                aria-label="Chatbot navigation"
            >
                <div class="lml-chatbot-main__sidebar-top">
                    <div class="lml-chatbot-main__profile">
                        <div class="lml-chatbot-main__avatar" aria-hidden="true">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div class="lml-chatbot-main__profile-text">
                            <p class="lml-chatbot-main__resident-name">{{ $residentDisplayName }}</p>
                            <p class="lml-chatbot-main__household">
                                <i class="bi bi-house-door" aria-hidden="true"></i>
                                <span>{{ $householdDisplayNo }}</span>
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="lml-chatbot-main__sidebar-toggle lml-focus-ring"
                        data-lml-sidebar-toggle
                        aria-controls="chatbot-sidebar"
                        aria-expanded="true"
                        aria-label="Collapse sidebar"
                        title="Collapse sidebar"
                    >
                        <i class="bi bi-chevron-bar-left" aria-hidden="true"></i>
                    </button>
                </div>

                @if ($householdRequestState === 'pending')
                    <span
                        class="lml-chatbot-main__household-btn lml-chatbot-main__household-btn--pending"
                        data-lml-sidebar-tab="household"
                        data-lml-household-btn
                        role="status"
                        aria-label="Request Sent"
                        title="Request Sent"
                    >
                        <span
                            class="lml-chatbot-main__household-btn-compact"
                            aria-hidden="true"
                        >HH</span>
                        <span class="lml-chatbot-main__nav-label">
                            Request Sent
                        </span>
                    </span>
                @elseif ($householdRequestState === 'awaiting_otp')
                    <a
                        href="{{ route('chatbot.household.verification.sms') }}"
                        class="lml-chatbot-main__household-btn lml-focus-ring"
                        data-lml-sidebar-tab="household"
                        data-lml-household-btn
                        aria-label="Continue Verification"
                        title="Continue Verification"
                    >
                        <span
                            class="lml-chatbot-main__household-btn-compact"
                            aria-hidden="true"
                        >HH</span>
                        <span class="lml-chatbot-main__nav-label">
                            Continue Verification
                        </span>
                    </a>
                @elseif ($householdRequestState === 'approved')
                    {{-- Verified: Access CTA only — never show stored OTP/match decision_reason here. --}}
                    <a
                        href="{{ route('chatbot.household.information') }}"
                        class="lml-chatbot-main__household-btn lml-chatbot-main__household-btn--approved lml-focus-ring"
                        data-lml-sidebar-tab="household"
                        data-lml-household-btn
                        aria-label="Access Household Record"
                        title="Access Household Record"
                    >
                        <span
                            class="lml-chatbot-main__household-btn-compact"
                            aria-hidden="true"
                        >HH</span>
                        <span class="lml-chatbot-main__nav-label">
                            Access Household Record
                        </span>
                        <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
                        <span class="visually-hidden">Verified</span>
                    </a>
                @elseif ($householdRequestState === 'denied')
                    <div class="lml-chatbot-main__household-result">
                        <span
                            class="lml-chatbot-main__household-btn lml-chatbot-main__household-btn--denied"
                            data-lml-sidebar-tab="household"
                            data-lml-household-btn
                            role="status"
                            aria-label="Request Could Not Be Verified"
                            title="Request Could Not Be Verified"
                        >
                            <span
                                class="lml-chatbot-main__household-btn-compact"
                                aria-hidden="true"
                            >HH</span>
                            <span class="lml-chatbot-main__nav-label">
                                Request Could Not Be Verified
                            </span>
                        </span>
                        @if ($householdDecisionReason !== '')
                            <p class="lml-chatbot-main__household-reason">{{ $householdDecisionReason }}</p>
                        @endif
                        <a
                            href="{{ route('chatbot.household.verification') }}"
                            class="lml-chatbot-main__household-retry lml-focus-ring"
                            aria-label="Request Household Record"
                        >
                            Request Household Record
                        </a>
                    </div>
                @else
                    <a
                        href="{{ route('chatbot.household.verification') }}"
                        class="lml-chatbot-main__household-btn lml-focus-ring"
                        data-lml-sidebar-tab="household"
                        data-lml-household-btn
                        aria-label="Request Household Record"
                        title="Request Household Record"
                    >
                        <span
                            class="lml-chatbot-main__household-btn-compact"
                            aria-hidden="true"
                        >HH</span>
                        <span class="lml-chatbot-main__nav-label">
                            Request Household Record
                        </span>
                    </a>
                @endif

                <div class="lml-chatbot-main__nav">
                    <button
                        type="button"
                        class="lml-chatbot-main__nav-row lml-focus-ring"
                        data-lml-sidebar-tab="new-chat"
                        data-lml-new-chat
                        aria-label="Start a new chat"
                        title="New Chat"
                    >
                        <span class="lml-chatbot-main__nav-label">New Chat</span>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    </button>

                    <button
                        type="button"
                        class="lml-chatbot-main__nav-row is-active lml-focus-ring"
                        data-lml-sidebar-tab="history"
                        data-lml-history-toggle
                        aria-controls="chatbot-history-panel"
                        aria-expanded="true"
                        aria-label="Chat History"
                        title="Chat History"
                    >
                        <span class="lml-chatbot-main__nav-label">Chat History</span>
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                    </button>

                    <div
                        id="chatbot-history-panel"
                        class="lml-chatbot-main__history"
                        data-lml-history-panel
                    >
                        <div class="lml-chatbot-main__search">
                            <label class="visually-hidden" for="chatbot-chat-search">Search chats</label>
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input
                                id="chatbot-chat-search"
                                type="search"
                                class="lml-chatbot-main__search-input lml-focus-ring"
                                data-lml-chat-search
                                placeholder="Search chats…"
                                autocomplete="off"
                            >
                            <button
                                type="button"
                                class="lml-chatbot-main__search-clear lml-focus-ring"
                                data-lml-chat-search-clear
                                aria-label="Clear search"
                                hidden
                            >
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </div>

                        <section class="lml-chatbot-main__history-section" aria-labelledby="pinned-chats-heading">
                            <p id="pinned-chats-heading" class="lml-chatbot-main__history-heading">Pinned</p>
                            <ul class="lml-chatbot-main__chat-list" data-lml-chat-list="pinned">
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row lml-chatbot-main__chat-row--active"
                                        data-lml-chat-item
                                        data-chat-title="Fever"
                                        data-pinned="true"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            aria-current="page"
                                            title="Fever"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Fever</span>
                                            <span class="lml-chatbot-main__chat-meta">Yesterday</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="true"
                                            aria-label="Unpin Fever"
                                            title="Unpin"
                                        >
                                            <i class="bi bi-pin-angle-fill" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row"
                                        data-lml-chat-item
                                        data-chat-title="Child Nutrition"
                                        data-pinned="true"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            title="Child Nutrition"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Child Nutrition</span>
                                            <span class="lml-chatbot-main__chat-meta">July 15</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="true"
                                            aria-label="Unpin Child Nutrition"
                                            title="Unpin"
                                        >
                                            <i class="bi bi-pin-angle-fill" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                            </ul>
                            <p class="lml-chatbot-main__history-empty" data-lml-pinned-empty hidden>
                                No pinned chats
                            </p>
                        </section>

                        <section class="lml-chatbot-main__history-section" aria-labelledby="recent-chats-heading">
                            <p id="recent-chats-heading" class="lml-chatbot-main__history-heading">Recent</p>
                            <ul class="lml-chatbot-main__chat-list" data-lml-chat-list="recent">
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row"
                                        data-lml-chat-item
                                        data-chat-title="Vitamins for Children"
                                        data-pinned="false"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            title="Vitamins for Children"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Vitamins for Children</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="false"
                                            aria-label="Pin Vitamins for Children"
                                            title="Pin"
                                        >
                                            <i class="bi bi-pin-angle" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row"
                                        data-lml-chat-item
                                        data-chat-title="Cough and Cold"
                                        data-pinned="false"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            title="Cough and Cold"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Cough and Cold</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="false"
                                            aria-label="Pin Cough and Cold"
                                            title="Pin"
                                        >
                                            <i class="bi bi-pin-angle" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row"
                                        data-lml-chat-item
                                        data-chat-title="Healthy Pregnancy"
                                        data-pinned="false"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            title="Healthy Pregnancy"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Healthy Pregnancy</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="false"
                                            aria-label="Pin Healthy Pregnancy"
                                            title="Pin"
                                        >
                                            <i class="bi bi-pin-angle" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                                <li>
                                    <div
                                        class="lml-chatbot-main__chat-row"
                                        data-lml-chat-item
                                        data-chat-title="Water Safety"
                                        data-pinned="false"
                                    >
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-select lml-focus-ring"
                                            data-lml-chat-select
                                            title="Water Safety"
                                        >
                                            <i class="bi bi-chat" aria-hidden="true"></i>
                                            <span class="lml-chatbot-main__chat-title">Water Safety</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="lml-chatbot-main__chat-pin lml-focus-ring"
                                            data-lml-pin
                                            aria-pressed="false"
                                            aria-label="Pin Water Safety"
                                            title="Pin"
                                        >
                                            <i class="bi bi-pin-angle" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </li>
                            </ul>
                            <p class="lml-chatbot-main__history-empty" data-lml-recent-empty hidden>
                                No recent chats yet
                            </p>
                        </section>
                    </div>

                    @if ($verificationState === 'verified' && count($chatbotNotifications) > 0)
                        <div class="lml-chatbot-notifications" data-lml-notifications>
                            <button
                                type="button"
                                class="lml-chatbot-main__nav-row lml-chatbot-notifications__toggle lml-focus-ring"
                                data-lml-sidebar-tab="notifications"
                                data-lml-notifications-toggle
                                aria-controls="chatbot-notifications-panel"
                                aria-expanded="false"
                                aria-label="Notifications{{ $chatbotUnreadCount > 0 ? ", {$chatbotUnreadCount} unread" : '' }}"
                                title="Notifications"
                            >
                                <span class="lml-chatbot-notifications__toggle-start">
                                    <i class="bi bi-bell" aria-hidden="true"></i>
                                    <span class="lml-chatbot-main__nav-label">Notifications</span>
                                    @if ($chatbotUnreadCount > 0)
                                        <span
                                            class="lml-chatbot-notifications__badge"
                                            data-lml-notifications-badge
                                            aria-label="{{ $chatbotUnreadCount }} unread"
                                        >{{ $chatbotUnreadCount }}</span>
                                    @endif
                                </span>
                                <i
                                    class="bi bi-chevron-down lml-chatbot-notifications__chevron"
                                    aria-hidden="true"
                                ></i>
                            </button>

                            <div
                                id="chatbot-notifications-panel"
                                class="lml-chatbot-notifications__panel"
                                data-lml-notifications-panel
                                hidden
                            >
                                <ul
                                    class="lml-chatbot-notifications__list"
                                    data-lml-notifications-list
                                    aria-label="Health schedule notifications"
                                >
                                    @foreach ($chatbotNotifications as $notification)
                                        <li class="lml-chatbot-notifications__item-wrap">
                                            <button
                                                type="button"
                                                @class([
                                                    'lml-chatbot-notifications__item lml-focus-ring',
                                                    'is-unread' => ! $notification['is_read'],
                                                    'is-read' => $notification['is_read'],
                                                ])
                                                data-lml-notification-item
                                                data-notification-id="{{ $notification['id'] }}"
                                                data-notification-service="{{ $notification['service'] }}"
                                                data-notification-member="{{ $notification['member_name'] }}"
                                                data-notification-relationship="{{ $notification['relationship'] }}"
                                                data-notification-date="{{ $notification['date'] }}"
                                                data-notification-time="{{ $notification['time'] }}"
                                                data-notification-place="{{ $notification['place'] }}"
                                                data-notification-status="{{ $notification['status'] }}"
                                                data-notification-reminder-html="{!! e($notification['reminder_html']) !!}"
                                                data-notification-read="{{ $notification['is_read'] ? 'true' : 'false' }}"
                                                aria-describedby="notification-{{ $notification['id'] }}-meta"
                                                aria-label="{{ $notification['service_short'] }}, {{ $notification['member_name'] }}{{ ! $notification['is_read'] ? ', unread' : '' }}"
                                            >
                                                <span class="lml-chatbot-notifications__item-icon" aria-hidden="true">
                                                    <i class="bi {{ $notification['icon'] }}"></i>
                                                </span>
                                                <span class="lml-chatbot-notifications__item-body">
                                                    <span class="lml-chatbot-notifications__item-title-row">
                                                        <span class="lml-chatbot-notifications__item-title">
                                                            {{ $notification['service_short'] }}
                                                        </span>
                                                        @if (! $notification['is_read'])
                                                            <span
                                                                class="lml-chatbot-notifications__unread-dot"
                                                                aria-hidden="true"
                                                            ></span>
                                                        @endif
                                                    </span>
                                                    <span class="lml-chatbot-notifications__item-member">
                                                        {{ $notification['member_name'] }}
                                                        <span class="lml-chatbot-notifications__item-relationship">
                                                            {{ $notification['relationship'] }}
                                                        </span>
                                                    </span>
                                                    <span
                                                        id="notification-{{ $notification['id'] }}-meta"
                                                        class="lml-chatbot-notifications__item-meta"
                                                    >
                                                        <span class="lml-chatbot-notifications__item-date">
                                                            {{ $notification['date'] }}
                                                        </span>
                                                        <span
                                                            @class([
                                                                'lml-chatbot-notifications__status',
                                                                'lml-chatbot-notifications__status--' . $notification['status'],
                                                            ])
                                                        >
                                                            @if ($notification['status'] === 'completed')
                                                                <i class="bi bi-check2" aria-hidden="true"></i>
                                                            @elseif ($notification['status'] === 'cancelled')
                                                                <i class="bi bi-x-circle" aria-hidden="true"></i>
                                                            @elseif ($notification['status'] === 'rescheduled')
                                                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                                            @endif
                                                            <span>{{ ucfirst($notification['status']) }}</span>
                                                        </span>
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="lml-chatbot-main__sidebar-footer">
                    <form method="post" action="{{ route('chatbot.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="lml-chatbot-main__logout lml-focus-ring"
                            aria-label="Log out and return to chatbot landing"
                            title="Logout"
                        >
                            <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                            <span class="lml-chatbot-main__nav-label">Logout</span>
                        </button>
                    </form>
                </div>
            </aside>

            <main class="lml-chatbot-main__workspace" id="main-content">
                <header class="lml-chatbot-main__workspace-bar">
                    <button
                        type="button"
                        class="lml-chatbot-main__mobile-toggle lml-focus-ring"
                        data-lml-mobile-toggle
                        aria-controls="chatbot-sidebar"
                        aria-expanded="false"
                        aria-label="Open sidebar"
                    >
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="lml-chatbot-main__welcome" data-lml-welcome>
                    <div class="lml-chatbot-main__brand">
                        <img
                            class="lml-chatbot-main__brand-mark"
                            src="{{ asset('assets/images/logo/logo.png') }}"
                            alt=""
                            width="44"
                            height="44"
                            decoding="async"
                        >
                        <span class="lml-chatbot-main__brand-name">LMLinga</span>
                    </div>

                    <h1 class="lml-chatbot-main__greeting">Hi, {{ $residentDisplayName }}!</h1>
                    <p class="lml-chatbot-main__welcome-text">
                        This is the Smart Health Support Chatbot.<br>
                        You can ask me in your preferred language.
                    </p>

                    <div
                        class="lml-chatbot-main__languages"
                        role="group"
                        aria-label="Preferred language"
                        data-lml-languages
                    >
                        <button
                            type="button"
                            class="lml-chatbot-main__lang-btn lml-chatbot-main__lang-btn--active lml-focus-ring"
                            data-lml-lang="English"
                            aria-pressed="true"
                        >
                            English
                        </button>
                        <button
                            type="button"
                            class="lml-chatbot-main__lang-btn lml-focus-ring"
                            data-lml-lang="Tagalog"
                            aria-pressed="false"
                        >
                            Tagalog
                        </button>
                        <button
                            type="button"
                            class="lml-chatbot-main__lang-btn lml-focus-ring"
                            data-lml-lang="Bikol – Iriga"
                            aria-pressed="false"
                        >
                            Bikol – Iriga
                        </button>
                    </div>
                    <p class="visually-hidden" data-lml-lang-live aria-live="polite"></p>
                </div>

                <div
                    class="lml-chatbot-main__messages"
                    data-lml-messages
                    role="log"
                    aria-live="polite"
                    aria-relevant="additions text"
                    aria-label="Conversation"
                >
                    <div class="lml-chatbot-main__message lml-chatbot-main__message--assistant">
                        <span class="lml-chatbot-main__message-dot" aria-hidden="true"></span>
                        <div class="lml-chatbot-main__bubble">
                            <p class="lml-chatbot-main__bubble-text">
                                This is health chatbot for health center. How can I help you today?
                            </p>
                            <time class="lml-chatbot-main__bubble-time" datetime="11:50">11:50 AM</time>
                        </div>
                    </div>

                    {{-- Demo resident message for UI contrast review only. --}}
                    <div class="lml-chatbot-main__message lml-chatbot-main__message--user">
                        <div class="lml-chatbot-main__bubble">
                            <p class="lml-chatbot-main__bubble-text">
                                My child has a fever. What should I do?
                            </p>
                            <time class="lml-chatbot-main__bubble-time" datetime="11:51">11:51 AM</time>
                        </div>
                    </div>
                </div>

                <form
                    class="lml-chatbot-main__composer"
                    data-lml-composer
                    action="#"
                    method="post"
                    novalidate
                >
                    @csrf
                    <label class="visually-hidden" for="chatbot-message-input">Type a message</label>
                    <div class="lml-chatbot-main__composer-field">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <textarea
                            id="chatbot-message-input"
                            class="lml-chatbot-main__composer-input lml-focus-ring"
                            data-lml-composer-input
                            rows="1"
                            placeholder="Type a message..."
                            autocomplete="off"
                        ></textarea>
                        <button
                            type="submit"
                            class="lml-chatbot-main__send lml-focus-ring"
                            data-lml-send
                            aria-label="Send message"
                        >
                            <i class="bi bi-send-fill" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>
            </main>
        </div>

        <x-lml.chatbot.notification-modal />
    </div>
@endsection
