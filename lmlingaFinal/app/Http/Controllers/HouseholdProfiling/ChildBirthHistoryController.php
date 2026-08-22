<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChildBirthHistoryRequest;
use App\Support\ChildBirthHistoryService;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;

class ChildBirthHistoryController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly ChildBirthHistoryService $service,
    ) {}

    public function store(
        StoreChildBirthHistoryRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        $this->service->saveForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.child-immunization', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Birth history saved.');
    }
}
