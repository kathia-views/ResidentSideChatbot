{{--
    View Health Worker Information — read-only profile.
    Binds to HealthWorkerUiCatalog (database user or demo hw-* fallback).
    Empty/missing profile fields render as "—" — never invent demo values here.
--}}
@extends('layouts.dashboard')

@section('title', 'View Health Worker Information - LMLinga')

@php
    $worker = $demoWorker ?? null;

    $roleTitles = [
        'BHW' => 'Barangay Health Worker (BHW)',
        'BNS' => 'Barangay Nutrition Scholar (BNS)',
        'BSPO' => 'Barangay Service Point Officer (BSPO)',
        'Admin' => 'Administrator (Admin)',
    ];

    $formatDate = static function (?string $value): string {
        if (! filled($value)) {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('m/d/Y');
        } catch (\Throwable) {
            return $value;
        }
    };

    $computeAge = static function (?string $dob): string {
        if (! filled($dob)) {
            return '—';
        }

        try {
            $age = \Illuminate\Support\Carbon::parse($dob)->age;

            return $age >= 0 ? (string) $age : '—';
        } catch (\Throwable) {
            return '—';
        }
    };

    $display = static function (?string $value): string {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    };
@endphp

@section('content')
    @if (! $worker)
        <div class="lml-hw-view">
            <div class="lml-hw-view__card">
                <h2 class="lml-hw-view__missing-title">Health worker not found</h2>
                <p class="lml-hw-view__missing-text">
                    The selected health worker could not be loaded.
                </p>
                <a href="{{ route('user-management.index') }}" class="lml-hw-view__back-link lml-focus-ring">
                    Back to Manage Health Workers
                </a>
            </div>
        </div>
    @else
        @php
            $roleCode = $worker['role'] ?? '';
            $roleLabel = $roleTitles[$roleCode] ?? ($roleCode !== '' ? $roleCode.' (Role)' : '—');
            $isActive = strtolower((string) ($worker['status'] ?? '')) === 'active';
            $statusLabel = $isActive ? 'Active' : 'Inactive';
            $fullName = trim(implode(' ', array_filter([
                $worker['first_name'] ?? null,
                $worker['middle_name'] ?? null,
                $worker['last_name'] ?? null,
            ])));
            $photoAlt = $fullName !== ''
                ? 'Profile photo of '.$fullName
                : 'Health worker profile photo';
            $age = $computeAge($worker['date_of_birth'] ?? null);
        @endphp

        <div class="lml-hw-view">
            <div class="lml-hw-view__toolbar">
                <a
                    href="{{ route('user-management.index') }}"
                    class="lml-hw-view__back lml-focus-ring"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    <span>Back to Manage Health Workers</span>
                </a>
            </div>

            <article class="lml-hw-view__card" aria-labelledby="lml-hw-view-page-title">
                @if (session('status'))
                    <p class="lml-hw-wizard__toast" role="status">
                        {{ session('status') }}
                    </p>
                @endif
                <header class="lml-hw-view__header">
                    <span class="lml-hw-view__header-icon" aria-hidden="true">
                        <i class="bi bi-person-vcard"></i>
                    </span>
                    <div>
                        <h1 class="lml-hw-view__title" id="lml-hw-view-page-title">
                            Health Worker Information
                        </h1>
                        <p class="lml-hw-view__subtitle">
                            Complete profile and employment details of the selected health worker.
                        </p>
                    </div>
                </header>

                {{-- 1. Personal Information --}}
                <section class="lml-hw-view__section" aria-labelledby="lml-hw-view-heading-personal">
                    <h2 id="lml-hw-view-heading-personal" class="lml-hw-view__section-title">
                        <i class="bi bi-person-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                        <span>Personal Information</span>
                    </h2>

                    <div class="lml-hw-view__personal">
                        <div class="lml-hw-view__profile">
                            <div class="lml-hw-view__avatar">
                                @if (! empty($worker['photo']))
                                    <img
                                        src="{{ $worker['photo'] }}"
                                        alt="{{ $photoAlt }}"
                                        class="lml-hw-view__avatar-img"
                                    >
                                @else
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                    <span class="visually-hidden">{{ $photoAlt }} (default avatar)</span>
                                @endif
                            </div>
                            <p class="lml-hw-view__role-label">{{ $roleLabel }}</p>
                        </div>

                        <dl class="lml-hw-view__fields lml-hw-view__fields--personal">
                            <div class="lml-hw-view__field">
                                <dt>First Name</dt>
                                <dd>{{ $display($worker['first_name'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Last Name</dt>
                                <dd>{{ $display($worker['last_name'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Middle Name</dt>
                                <dd>{{ $display($worker['middle_name'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Suffix</dt>
                                <dd>{{ $display($worker['suffix'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Sex</dt>
                                <dd>{{ $display($worker['sex'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Date of Birth</dt>
                                <dd>{{ $formatDate($worker['date_of_birth'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Age <span class="lml-hw-view__hint">(Auto-Computed)</span></dt>
                                <dd>{{ $age }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Civil Status</dt>
                                <dd>{{ $display($worker['civil_status'] ?? null) }}</dd>
                            </div>
                            <div class="lml-hw-view__field">
                                <dt>Nationality</dt>
                                <dd>{{ $display($worker['nationality'] ?? null) }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                {{-- 2. Contact Information --}}
                <section class="lml-hw-view__section" aria-labelledby="lml-hw-view-heading-contact">
                    <h2 id="lml-hw-view-heading-contact" class="lml-hw-view__section-title">
                        <i class="bi bi-telephone-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                        <span>Contact Information</span>
                    </h2>

                    <dl class="lml-hw-view__fields lml-hw-view__fields--2">
                        <div class="lml-hw-view__field">
                            <dt>Mobile Number</dt>
                            <dd>{{ $display($worker['mobile'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Email Address</dt>
                            <dd>{{ $display($worker['email'] ?? null) }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- 3. Residential Address --}}
                <section class="lml-hw-view__section" aria-labelledby="lml-hw-view-heading-address">
                    <h2 id="lml-hw-view-heading-address" class="lml-hw-view__section-title">
                        <i class="bi bi-house-door-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                        <span>Residential Address</span>
                    </h2>

                    <dl class="lml-hw-view__fields lml-hw-view__fields--address">
                        <div class="lml-hw-view__field">
                            <dt>House No.</dt>
                            <dd>{{ $display($worker['house_no'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Street</dt>
                            <dd>{{ $display($worker['street'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Purok / Zone</dt>
                            <dd>{{ $display($worker['purok_zone'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Barangay</dt>
                            <dd>{{ $display($worker['barangay'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Municipality / City</dt>
                            <dd>{{ $display($worker['municipality'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Province</dt>
                            <dd>{{ $display($worker['province'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Zip Code</dt>
                            <dd>{{ $display($worker['zip_code'] ?? null) }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- 4. Employment Information --}}
                <section class="lml-hw-view__section" aria-labelledby="lml-hw-view-heading-employment">
                    <h2 id="lml-hw-view-heading-employment" class="lml-hw-view__section-title">
                        <i class="bi bi-briefcase-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                        <span>Employment Information</span>
                    </h2>

                    <dl class="lml-hw-view__fields lml-hw-view__fields--employment">
                        <div class="lml-hw-view__field lml-hw-view__field--full">
                            <dt>Role</dt>
                            <dd>{{ $display($roleCode) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Assigned Barangay</dt>
                            <dd>{{ $display($worker['assigned_barangay'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Assigned Zone</dt>
                            <dd>{{ $display($worker['assigned_zone'] ?? ($worker['zone'] ?? null)) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Date Appointed</dt>
                            <dd>{{ $formatDate($worker['date_appointed'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>End of Appointment</dt>
                            <dd>{{ $formatDate($worker['end_of_appointment'] ?? null) }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- 5. Account Information --}}
                <section class="lml-hw-view__section" aria-labelledby="lml-hw-view-heading-account">
                    <h2 id="lml-hw-view-heading-account" class="lml-hw-view__section-title">
                        <i class="bi bi-shield-lock-fill lml-hw-view__section-icon" aria-hidden="true"></i>
                        <span>Account Information</span>
                    </h2>

                    <dl class="lml-hw-view__fields lml-hw-view__fields--2">
                        <div class="lml-hw-view__field">
                            <dt>Username</dt>
                            <dd>{{ $display($worker['username'] ?? null) }}</dd>
                        </div>
                        <div class="lml-hw-view__field">
                            <dt>Status</dt>
                            <dd>
                                <span
                                    @class([
                                        'lml-hw-view__status',
                                        'lml-hw-view__status--active' => $isActive,
                                        'lml-hw-view__status--inactive' => ! $isActive,
                                    ])
                                >
                                    {{ $statusLabel }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <div class="lml-hw-view__actions">
                    <a
                        href="{{ route('user-management.health-workers.edit', ['id' => $worker['id']]) }}"
                        class="lml-hw-view__btn lml-hw-view__btn--edit lml-focus-ring"
                    >
                        Edit Account Details
                    </a>
                    <a
                        href="{{ route('user-management.index') }}"
                        class="lml-hw-view__btn lml-hw-view__btn--exit lml-focus-ring"
                    >
                        Exit
                    </a>
                </div>
            </article>
        </div>
    @endif
@endsection
