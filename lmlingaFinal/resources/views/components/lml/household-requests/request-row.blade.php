{{--
    Household request table row — Admin Household Requests (read-only).
--}}
@props([
    'id' => '',
    'name' => '',
    'firstName' => '',
    'middleName' => '',
    'lastName' => '',
    'householdNo' => '',
    'zone' => '',
    'submittedAt' => '',
    'status' => '',
    'isCurrent' => false,
    'mobile' => '',
    'email' => '',
])

@php
    $requestId = $id !== '' ? $id : '';
    $isApproved = strtolower((string) $status) === 'approved';
    $isCurrent = (bool) $isCurrent;
@endphp

<tr
    {{ $attributes->class(['lml-hr-table__row']) }}
    data-hr-row
    data-hr-id="{{ $requestId }}"
    data-hr-name="{{ $name }}"
    data-hr-first="{{ $firstName }}"
    data-hr-middle="{{ $middleName }}"
    data-hr-last="{{ $lastName }}"
    data-hr-household="{{ $householdNo }}"
    data-hr-zone="{{ $zone }}"
    data-hr-status="{{ $status }}"
    data-hr-current="{{ $isCurrent ? '1' : '0' }}"
    data-hr-mobile="{{ $mobile }}"
    data-hr-email="{{ $email }}"
>
    <td class="lml-hr-table__cell lml-hr-table__cell--name" data-label="Name">
        <span class="lml-hr-table__name">{{ $name }}</span>
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--household" data-label="Household No.">
        {{ $householdNo !== '' ? $householdNo : '—' }}
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--zone" data-label="Zone">
        {{ $zone }}
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--date" data-label="Date Submitted">
        {{ $submittedAt !== '' ? $submittedAt : '—' }}
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--status" data-label="Status">
        <span class="lml-hr-table__status-stack">
            <span
                @class([
                    'lml-hr-table__scope',
                    'lml-hr-table__scope--current' => $isCurrent,
                    'lml-hr-table__scope--historical' => ! $isCurrent,
                ])
            >
                {{ $isCurrent ? 'Current' : 'Historical' }}
            </span>
            <span
                @class([
                    'lml-hr-table__status',
                    'lml-hr-table__status--approved' => $isApproved,
                    'lml-hr-table__status--rejected' => ! $isApproved,
                ])
            >
                {{ $status }}
            </span>
        </span>
    </td>
    <td class="lml-hr-table__cell lml-hr-table__cell--actions" data-label="View">
        <a
            href="{{ route('household-requests.view', ['id' => $requestId]) }}"
            class="lml-hr-table__view-btn lml-focus-ring"
            aria-label="View household request for {{ $name }}"
        >
            <i class="bi bi-eye" aria-hidden="true"></i>
            <span>View</span>
        </a>
    </td>
</tr>
