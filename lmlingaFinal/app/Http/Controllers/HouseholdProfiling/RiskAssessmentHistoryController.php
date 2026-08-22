<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRiskAssessmentSectionRequest;
use App\Support\DemoCatalog;
use App\Support\DemoRiskAssessment;
use App\Support\HealthMemberIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RiskAssessmentHistoryController extends Controller
{
    public function show(string $householdNo, string $memberId, string $assessmentId): View
    {
        $context = $this->resolveContext($householdNo, $memberId, $assessmentId);

        return view('pages.household-profiling.risk-assessment-show', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $context['member']
                ? 'View risk assessment for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'Demo member was not found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'assessmentId' => $context['assessmentId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'assessment' => $context['assessment'] ?? [],
            'historySections' => DemoRiskAssessment::historySections(),
        ]);
    }

    public function section(
        Request $request,
        string $householdNo,
        string $memberId,
        string $assessmentId,
        string $section
    ): View|RedirectResponse {
        $sectionKey = DemoRiskAssessment::normalizeSection($section);
        if ($sectionKey === null) {
            return redirect()->route('household-profiling.members.risk-assessment.show', [
                'householdNo' => DemoCatalog::normalizeHouseholdNo($householdNo),
                'memberId' => DemoCatalog::normalizeMemberId($memberId),
                'assessmentId' => strtoupper(trim($assessmentId)),
            ]);
        }

        $context = $this->resolveContext($householdNo, $memberId, $assessmentId);
        $editing = $request->routeIs('household-profiling.members.risk-assessment.section.edit');

        return view('pages.household-profiling.risk-assessment-section', [
            'active' => 'household-profiling',
            'pageTitle' => 'Risk Assessment',
            'pageSubtitle' => $context['member']
                ? 'View risk assessment for '.$context['member']['name'].' in '.$context['householdNo'].'.'
                : 'Demo member was not found.',
            'householdNo' => $context['householdNo'],
            'memberId' => $context['memberId'],
            'assessmentId' => $context['assessmentId'],
            'demoHousehold' => $context['household'],
            'demoMember' => $context['member'],
            'assessment' => $context['assessment'] ?? [],
            'section' => $sectionKey,
            'sectionMeta' => DemoRiskAssessment::historySections()[$sectionKey],
            'isEditing' => $editing,
            'fields' => DemoRiskAssessment::fieldDefinitions(),
        ]);
    }

    public function updateSection(
        UpdateRiskAssessmentSectionRequest $request,
        string $householdNo,
        string $memberId,
        string $assessmentId,
        string $section
    ): RedirectResponse {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $hh = $ctx['householdNo'];
        $mb = $ctx['memberId'];
        $id = strtoupper(trim($assessmentId));
        $sectionKey = DemoRiskAssessment::normalizeSection($section);

        $member = $ctx['member'];

        if (! $member || $sectionKey === null) {
            return redirect()
                ->route('household-profiling.members.risk-assessment', [
                    'householdNo' => $hh,
                    'memberId' => $mb,
                ])
                ->withErrors(['assessment' => 'Unable to update this risk assessment.']);
        }

        // Ownership: assessment must already belong to this household + member.
        if (! DemoRiskAssessment::existsInCatalog($hh, $mb, $id)) {
            return redirect()
                ->route('household-profiling.members.risk-assessment', [
                    'householdNo' => $hh,
                    'memberId' => $mb,
                ])
                ->withErrors(['assessment' => 'Assessment not found for this member. Update did not create a new record.']);
        }

        $updated = DemoRiskAssessment::updateSection(
            $hh,
            $mb,
            $id,
            $sectionKey,
            $request->sectionPayload()
        );

        if ($updated === null) {
            return redirect()
                ->route('household-profiling.members.risk-assessment.section', [
                    'householdNo' => $hh,
                    'memberId' => $mb,
                    'assessmentId' => $id,
                    'section' => $sectionKey,
                ])
                ->withErrors(['assessment' => 'Unable to update this risk assessment.']);
        }

        return redirect()
            ->route('household-profiling.members.risk-assessment.section', [
                'householdNo' => $hh,
                'memberId' => $mb,
                'assessmentId' => $id,
                'section' => $sectionKey,
            ])
            ->with('status', 'Risk assessment section saved.');
    }

    /**
     * @return array{
     *     householdNo: string,
     *     memberId: string,
     *     assessmentId: string,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     assessment: array<string, mixed>|null
     * }
     */
    private function resolveContext(string $householdNo, string $memberId, string $assessmentId): array
    {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);
        $hh = $ctx['householdNo'];
        $mb = $ctx['memberId'];
        $id = strtoupper(trim($assessmentId));
        $household = $ctx['household'];
        $member = $ctx['member'];
        $assessment = $member ? DemoRiskAssessment::find($hh, $mb, $id) : null;

        return [
            'householdNo' => $hh,
            'memberId' => $mb,
            'assessmentId' => $id,
            'household' => $household,
            'member' => $member,
            'assessment' => $assessment,
        ];
    }
}
