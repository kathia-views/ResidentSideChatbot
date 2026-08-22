<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDewormingRecordRequest;
use App\Support\DewormingRecordService;
use App\Support\HealthMemberIdentity;
use App\Support\HealthRecordsDeworming;
use Illuminate\Http\RedirectResponse;

class DewormingRecordController extends Controller
{
    public function __construct(
        private readonly HealthMemberIdentity $identity,
        private readonly DewormingRecordService $service,
    ) {}

    public function store(
        StoreDewormingRecordRequest $request,
        string $householdNo,
        string $memberId,
    ): RedirectResponse {
        $ctx = $this->identity->resolvePersistedOrFail($householdNo, $memberId);

        if (! HealthRecordsDeworming::memberCanManageRecords($ctx['member'])) {
            abort(403, 'Deworming is not available for this member.');
        }

        $this->service->createForResident($ctx['resident'], $request->validated());

        return redirect()
            ->route('household-profiling.members.deworming', [
                'householdNo' => $ctx['householdNo'],
                'memberId' => $ctx['memberId'],
            ])
            ->with('status', 'Deworming record saved.');
    }
}
