<?php

namespace App\Http\Controllers\HealthRecords;

use App\Http\Controllers\Controller;
use App\Models\DeathRequest;
use App\Support\DeathRecordsPdf;
use App\Support\HealthRecordsDeath;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DeathSummaryController extends Controller
{
    public function index(Request $request): View
    {
        $allRows = HealthRecordsDeath::listingRows();
        $approvedRows = array_values(array_filter(
            $allRows,
            static fn (array $row): bool => ($row['status'] ?? '') === DeathRequest::STATUS_APPROVED
        ));
        $summary = HealthRecordsDeath::summaryCounts($approvedRows);
        $filters = HealthRecordsDeath::listingFiltersFromRequest($request);
        $records = HealthRecordsDeath::paginatedListing($request);

        return view('pages.health-records.death', [
            'active' => 'death',
            'pageTitle' => 'Death',
            'pageSubtitle' => 'Submit death records for Admin verification and monitor approved mortality status.',
            'records' => $records,
            'filters' => $filters,
            'summary' => $summary,
            'zones' => HealthRecordsDeath::zones(),
            'causes' => HealthRecordsDeath::causes($allRows),
            'years' => HealthRecordsDeath::years($allRows),
            'totalUnfiltered' => count($allRows),
        ]);
    }

    public function residents(): View
    {
        return view('pages.health-records.death-residents', [
            'active' => 'death',
            'pageTitle' => 'Select a resident',
            'pageSubtitle' => 'Choose a resident to open or submit a death record for Admin verification.',
            'residents' => HealthRecordsDeath::residentCandidates(),
            'zones' => HealthRecordsDeath::zones(),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = HealthRecordsDeath::listingFiltersFromRequest($request);
        $rows = HealthRecordsDeath::filteredListingRows($request);
        $generatedAt = now()->timezone((string) config('app.timezone'));

        return DeathRecordsPdf::response(
            $rows,
            HealthRecordsDeath::filterLabels($filters),
            $generatedAt
        );
    }
}
