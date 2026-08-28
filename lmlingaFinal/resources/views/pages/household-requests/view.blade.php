{{--
    Household Request Details — Admin monitoring / audit of automatic verification (UI only).
    No manual approve/reject controls.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Request Details - LMLinga')

@php
    $request = $demoRequest ?? null;
    $isApproved = strtolower((string) ($request['status'] ?? '')) === 'approved';
    $isCurrent = (bool) ($request['is_current'] ?? false);
    $rejectionReasons = is_array($request['rejection_reasons'] ?? null)
        ? array_values(array_filter($request['rejection_reasons']))
        : [];
@endphp

@section('content')
    <div class="lml-hr-view">
        <div class="lml-hr-view__toolbar">
            <a
                href="{{ route('household-requests.index') }}"
                class="lml-hr-view__back lml-focus-ring"
            >
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                <span>Back to Household Requests</span>
            </a>
        </div>

        <article class="lml-hr-view__card" aria-labelledby="lml-hr-view-title">
            @if (! $request)
                <h1 id="lml-hr-view-title" class="lml-hr-view__title">Household request not found</h1>
                <p class="lml-hr-view__text">
                    The selected household request could not be loaded.
                </p>
            @else
                <header class="lml-hr-view__header">
                    <span class="lml-hr-view__header-icon" aria-hidden="true">
                        <i class="bi bi-house-check"></i>
                    </span>
                    <div>
                        <h1 id="lml-hr-view-title" class="lml-hr-view__title">
                            Household Request Details
                        </h1>
                        <p class="lml-hr-view__subtitle">
                            Verification result for {{ $request['name'] }}.
                        </p>
                    </div>
                </header>

                <section class="lml-hr-view__section" aria-labelledby="lml-hr-view-summary-heading">
                    <h2 id="lml-hr-view-summary-heading" class="lml-hr-view__section-title">
                        Request Summary
                    </h2>
                    <dl class="lml-hr-view__summary">
                        <div class="lml-hr-view__field">
                            <dt>Resident name</dt>
                            <dd>{{ $request['name'] }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Household number</dt>
                            <dd>{{ $request['household_no'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Zone</dt>
                            <dd>{{ $request['zone'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Relationship</dt>
                            <dd>{{ $request['relationship'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Request scope</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hr-table__scope',
                                        'lml-hr-table__scope--current' => $isCurrent,
                                        'lml-hr-table__scope--historical' => ! $isCurrent,
                                    ])
                                    data-hr-current="{{ $isCurrent ? '1' : '0' }}"
                                >
                                    {{ $isCurrent ? 'Current' : 'Historical' }}
                                </span>
                            </dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Request status</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hr-table__status',
                                        'lml-hr-table__status--approved' => $isApproved,
                                        'lml-hr-table__status--rejected' => ! $isApproved,
                                    ])
                                >
                                    {{ $request['status'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Submitted</dt>
                            <dd>{{ $request['submitted_at'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Evaluated</dt>
                            <dd>{{ $request['evaluated_at'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Approved</dt>
                            <dd>{{ $request['approved_at'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Mobile</dt>
                            <dd>{{ $request['mobile'] ?? '—' }}</dd>
                        </div>
                        <div class="lml-hr-view__field">
                            <dt>Email</dt>
                            <dd>{{ $request['email'] ?? '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="lml-hr-view__section" aria-labelledby="lml-hr-view-decision-heading">
                    <h2 id="lml-hr-view-decision-heading" class="lml-hr-view__section-title">
                        Automatic verification result
                    </h2>
                    <dl class="lml-hr-view__decision">
                        <div class="lml-hr-view__field lml-hr-view__field--decision">
                            <dt>Verification Result</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hr-table__status',
                                        'lml-hr-table__status--approved' => $isApproved,
                                        'lml-hr-table__status--rejected' => ! $isApproved,
                                    ])
                                >
                                    {{ $request['status'] }}
                                </span>
                            </dd>
                        </div>
                        <div class="lml-hr-view__field lml-hr-view__field--reason">
                            <dt>Reason</dt>
                            <dd>
                                @if (! $isApproved && count($rejectionReasons) > 1)
                                    <ul class="lml-hr-view__reasons">
                                        @foreach ($rejectionReasons as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @elseif (! $isApproved && count($rejectionReasons) === 1)
                                    {{ $rejectionReasons[0] }}
                                @else
                                    {{ $request['decision_reason'] ?? '—' }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>
            @endif

            <div class="lml-hr-view__actions">
                <a
                    href="{{ route('household-requests.index') }}"
                    class="lml-hr-view__btn lml-hr-view__btn--back lml-focus-ring"
                >
                    Back to Household Requests
                </a>
                <a
                    href="{{ route('dashboard') }}"
                    class="lml-hr-view__btn lml-hr-view__btn--exit"
                >
                    Exit
                </a>
            </div>
        </article>
    </div>
@endsection
