@extends('layouts.app')

@section('title', 'Household Record Access Request - LMLinga')

@section('body')
    @php
        $previewState = (string) request()->query('state', '');
        $isDailyLimit = $previewState === 'daily-limit';
    @endphp
    <div
        class="lml-chatbot-household-request"
        data-status-url="{{ route('chatbot.household.verification.status', ['state' => 'verifying']) }}"
        data-hh-preview-state="{{ $previewState }}"
    >
        <div class="lml-chatbot-household-request__inner">
            <header class="lml-chatbot-household-request__header">
                <a
                    href="{{ route('chatbot.main') }}"
                    class="lml-chatbot-household-request__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to chatbot</span>
                </a>
            </header>

            <main class="lml-chatbot-household-request__main" id="main-content">
                <section
                    class="lml-chatbot-household-request__card lml-surface lml-surface--elevated"
                    aria-labelledby="household-request-heading"
                >
                    <div class="lml-chatbot-household-request__hero">
                        <span class="lml-chatbot-household-request__hero-icon" aria-hidden="true">
                            <i class="bi bi-file-earmark-text"></i>
                        </span>
                        <h1 id="household-request-heading" class="lml-chatbot-household-request__title">
                            Request Household Record
                        </h1>
                        <p class="lml-chatbot-household-request__intro">
                            Complete this form so the system can automatically compare your details with barangay household records.
                            Matching is automatic. This is not a manual Admin approval step.
                        </p>
                    </div>

                    @if ($isDailyLimit)
                        <div class="lml-hh-status lml-hh-status--inline" role="alert">
                            <p class="lml-hh-status__badge lml-hh-status__badge--daily-limit">
                                Daily request limit reached
                            </p>
                            <p class="lml-chatbot-household-request__intro">
                                You have used 3 failed Household Request attempts today. Submit Request is unavailable until the daily limit resets.
                            </p>
                            <p class="lml-hh-status__note">
                                The attempt counter and daily reset are enforced by the backend. This UI only shows the blocked state.
                            </p>
                        </div>
                    @else
                    {{--
                        UI-phase form: method stays POST with CSRF for secure markup.
                        Submission is intercepted by client-side validation until backend persistence is wired.
                        Do not use method="get" (would expose request data in the URL).
                    --}}
                    <form
                        action="{{ route('chatbot.household.verification') }}"
                        method="post"
                        novalidate
                        data-lml-household-request-form
                    >
                        @csrf
                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-house-door-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Household Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--two">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-household-no">Household No.</label>
                                    <input
                                        id="hh-household-no"
                                        name="householdNo"
                                        type="text"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="Enter household number"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-household-no-error" hidden></p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-relationship">
                                        Relationship to Household
                                    </label>
                                    <select
                                        id="hh-relationship"
                                        name="relationship"
                                        class="form-select lml-form-control lml-focus-ring"
                                        required
                                        aria-required="true"
                                    >
                                        <option value="">Select your relationship</option>
                                        <option>Household Head</option>
                                        <option>Spouse</option>
                                        <option>Son</option>
                                        <option>Daughter</option>
                                        <option>Parent</option>
                                        <option>Sibling</option>
                                        <option>Grandparent</option>
                                        <option>Grandchild</option>
                                        <option>Relative</option>
                                        <option>Non-relative household member</option>
                                        <option>Other</option>
                                    </select>
                                    <p class="lml-form-error" id="hh-relationship-error" hidden></p>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-person-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Requester Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--three">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-first-name">First Name</label>
                                    <input
                                        id="hh-first-name"
                                        name="firstName"
                                        type="text"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="First name"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-first-name-error" hidden></p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-middle-name">
                                        Middle Name
                                    </label>
                                    <input
                                        id="hh-middle-name"
                                        name="middleName"
                                        type="text"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="Middle name"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-middle-name-error" hidden></p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-last-name">Last Name</label>
                                    <input
                                        id="hh-last-name"
                                        name="lastName"
                                        type="text"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="Last name"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-last-name-error" hidden></p>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="lml-chatbot-household-request__fieldset">
                            <legend class="lml-chatbot-household-request__legend">
                                <span class="lml-chatbot-household-request__legend-heading">
                                    <span class="lml-chatbot-household-request__legend-icon" aria-hidden="true">
                                        <i class="bi bi-telephone-fill"></i>
                                    </span>
                                    <span class="lml-chatbot-household-request__legend-text">Contact Information</span>
                                </span>
                                <span class="lml-chatbot-household-request__legend-line" aria-hidden="true"></span>
                            </legend>
                            <div class="lml-chatbot-household-request__grid lml-chatbot-household-request__grid--two">
                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-mobile-number">Mobile Number</label>
                                    <input
                                        id="hh-mobile-number"
                                        name="mobileNumber"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="09XXXXXXXXX"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-mobile-number-error" hidden></p>
                                </div>

                                <div class="lml-chatbot-household-request__field">
                                    <label class="lml-form-label lml-form-label--required" for="hh-email-address">Email Address</label>
                                    <input
                                        id="hh-email-address"
                                        name="emailAddress"
                                        type="email"
                                        autocomplete="email"
                                        class="form-control lml-form-control lml-focus-ring"
                                        placeholder="name@example.com"
                                        required
                                        aria-required="true"
                                    >
                                    <p class="lml-form-error" id="hh-email-address-error" hidden></p>
                                </div>
                            </div>
                        </fieldset>

                        <div class="lml-chatbot-household-request__actions">
                            <a
                                href="{{ route('chatbot.main') }}"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--cancel lml-focus-ring"
                            >
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <span>Cancel</span>
                            </a>
                            <button
                                type="submit"
                                class="lml-chatbot-household-request__btn lml-chatbot-household-request__btn--submit lml-focus-ring"
                            >
                                <i class="bi bi-send-fill" aria-hidden="true"></i>
                                <span>Submit Request</span>
                            </button>
                        </div>
                    </form>
                    @endif
                </section>
            </main>
        </div>
    </div>
@endsection
