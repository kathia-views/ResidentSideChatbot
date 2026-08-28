{{--
    Household Requests — Admin monitoring / history (read-only).
    Automatic verification. No manual approve/reject workflow.
--}}
@extends('layouts.dashboard')

@section('title', 'Household Requests - LMLinga')

@php
    $requests = $requests ?? [];
    $hasRequests = count($requests) > 0;
    $zoneOptions = [
        'all' => 'All Zones',
        'Zone 1' => 'Zone 1',
        'Zone 2' => 'Zone 2',
        'Zone 3' => 'Zone 3',
        'Zone 4' => 'Zone 4',
        'Zone 5' => 'Zone 5',
    ];
    $statusOptions = [
        'all' => 'All Statuses',
        \App\Models\RecordRequest::STATUS_PENDING => \App\Models\RecordRequest::STATUS_PENDING,
        \App\Models\RecordRequest::STATUS_APPROVED => \App\Models\RecordRequest::STATUS_APPROVED,
        \App\Models\RecordRequest::STATUS_DENIED => \App\Models\RecordRequest::STATUS_DENIED,
        \App\Models\RecordRequest::STATUS_NO_MATCH => \App\Models\RecordRequest::STATUS_NO_MATCH,
        \App\Models\RecordRequest::STATUS_AWAITING_OTP => \App\Models\RecordRequest::STATUS_AWAITING_OTP,
    ];
@endphp

@section('content')
    <div class="lml-hr" data-lml-household-requests>
        <div class="lml-hr__toolbar" role="search" aria-label="Filter household requests">
            <div class="lml-hr__search">
                <i class="bi bi-search lml-hr__search-icon" aria-hidden="true"></i>
                <label class="visually-hidden" for="lml-hr-search">Search Requester</label>
                <input
                    type="search"
                    id="lml-hr-search"
                    class="lml-hr__search-input"
                    placeholder="Search Requester"
                    autocomplete="off"
                    data-hr-search
                >
            </div>

            <div class="lml-hr__toolbar-end">
                <div class="lml-hr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-zone">Zone</label>
                    <select
                        id="lml-hr-zone"
                        class="lml-hr__select"
                        data-hr-zone
                        aria-label="Zone"
                    >
                        @foreach ($zoneOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr__select-icon" aria-hidden="true"></i>
                </div>

                <div class="lml-hr__select-wrap">
                    <label class="visually-hidden" for="lml-hr-status">Status</label>
                    <select
                        id="lml-hr-status"
                        class="lml-hr__select"
                        data-hr-status
                        aria-label="Status"
                    >
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === 'all')>{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-hr__select-icon" aria-hidden="true"></i>
                </div>
            </div>
        </div>

        <div class="lml-hr-table-wrap" data-hr-table-wrap @unless ($hasRequests) hidden @endunless>
            <table class="lml-hr-table" data-hr-table>
                <caption class="visually-hidden">Household record access requests by name, household number, zone, date, and status</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Household No.</th>
                        <th scope="col">Zone</th>
                        <th scope="col">Date Submitted</th>
                        <th scope="col">Status</th>
                        <th scope="col">View</th>
                    </tr>
                </thead>
                <tbody data-hr-tbody>
                    @foreach ($requests as $request)
                        <x-lml.household-requests.request-row
                            :id="$request['id']"
                            :name="$request['name']"
                            :first-name="$request['first_name'] ?? ''"
                            :middle-name="$request['middle_name'] ?? ''"
                            :last-name="$request['last_name'] ?? ''"
                            :household-no="$request['household_no'] ?? ''"
                            :zone="$request['zone']"
                            :submitted-at="$request['submitted_at'] ?? ''"
                            :status="$request['status']"
                            :is-current="(bool) ($request['is_current'] ?? false)"
                            :mobile="$request['mobile'] ?? ''"
                            :email="$request['email'] ?? ''"
                        />
                    @endforeach
                </tbody>
            </table>
        </div>

        <p
            class="lml-hr__empty"
            role="status"
            aria-live="polite"
            @if ($hasRequests) hidden @endif
            data-hr-empty
        >
            No household requests match your search, zone, or status filters.
        </p>
    </div>
@endsection
