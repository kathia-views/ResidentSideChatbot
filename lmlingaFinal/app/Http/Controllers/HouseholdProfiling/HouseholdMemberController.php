<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResidentRequest;
use App\Http\Requests\UpdateResidentRequest;
use App\Services\ResidentService;
use App\Support\DemoCatalog;
use App\Support\HouseholdMemberResolver;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HouseholdMemberController extends Controller
{
    public function __construct(
        private readonly HouseholdMemberResolver $resolver,
        private readonly ResidentService $residents,
    ) {}

    public function create(string $householdNo): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $resolved = $this->resolver->resolveHousehold($key);

        if ($resolved === null) {
            return view('pages.household-profiling.member-create', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Household was not found.',
                'householdNo' => $key,
                'demoHousehold' => null,
                'householdSource' => null,
                'persistable' => false,
                'formMode' => 'create',
                'memberValues' => [],
            ]);
        }

        if ($resolved['source'] === 'demo') {
            return view('pages.household-profiling.member-create', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Demo preview only — members cannot be saved for '.$key.'.',
                'householdNo' => $key,
                'demoHousehold' => $resolved['presentation'],
                'householdSource' => 'demo',
                'persistable' => false,
                'formMode' => 'create',
                'memberValues' => [],
            ]);
        }

        $household = $resolved['household']->load('residents');
        $presentation = HouseholdProfilingPresenter::fromModel($household);

        return view('pages.household-profiling.member-create', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Add a new member to '.$key.'.',
            'householdNo' => $key,
            'demoHousehold' => $presentation,
            'householdSource' => 'db',
            'persistable' => true,
            'formMode' => 'create',
            'memberValues' => [],
        ]);
    }

    public function store(StoreResidentRequest $request, string $householdNo): RedirectResponse
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $household = $this->resolver->resolveDbHouseholdOrFail($key);

        $resident = $this->residents->create($household, $request->validated());

        return redirect()
            ->route('household-profiling.members.show', [
                'householdNo' => $key,
                'memberId' => $resident->member_no,
            ])
            ->with('status', 'Household member added successfully.');
    }

    public function show(string $householdNo, string $memberId): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $resolved = $this->resolver->resolveMember($key, $memberKey);

        return view('pages.household-profiling.member-view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $resolved
                ? 'View member information for '.$key.'.'
                : 'Member was not found.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => $resolved['householdPresentation'] ?? null,
            'demoMember' => $resolved['memberPresentation'] ?? null,
            'householdSource' => $resolved['source'] ?? null,
        ]);
    }

    public function edit(string $householdNo, string $memberId): View
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);

        try {
            $ctx = $this->resolver->resolveDbMemberOrFail($key, $memberKey);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return view('pages.household-profiling.member-edit', [
                'active' => 'household-profiling',
                'pageTitle' => 'Household Profiling',
                'pageSubtitle' => 'Member was not found.',
                'householdNo' => $key,
                'memberId' => $memberKey,
                'demoHousehold' => null,
                'demoMember' => null,
                'householdSource' => null,
                'persistable' => false,
                'formMode' => 'edit',
                'memberValues' => [],
            ]);
        }

        $household = $ctx['household']->load('residents');
        $resident = $ctx['resident'];
        $memberPresentation = HouseholdProfilingPresenter::memberFromModel($resident);

        return view('pages.household-profiling.member-edit', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Edit member '.$memberKey.' in '.$key.'.',
            'householdNo' => $key,
            'memberId' => $memberKey,
            'demoHousehold' => HouseholdProfilingPresenter::fromModel($household),
            'demoMember' => $memberPresentation,
            'householdSource' => 'db',
            'persistable' => true,
            'formMode' => 'edit',
            'memberValues' => $memberPresentation,
        ]);
    }

    public function update(
        UpdateResidentRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);
        $memberKey = DemoCatalog::normalizeMemberId($memberId);
        $ctx = $this->resolver->resolveDbMemberOrFail($key, $memberKey);

        $this->residents->update($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.show', [
                'householdNo' => $key,
                'memberId' => $memberKey,
            ])
            ->with('status', 'Household member updated successfully.');
    }
}
