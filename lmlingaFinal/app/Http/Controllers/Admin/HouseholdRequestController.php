<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HouseholdRecordRequestUiCatalog;
use Illuminate\View\View;

class HouseholdRequestController extends Controller
{
    public function index(): View
    {
        return view('pages.household-requests.index', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Requests',
            'pageSubtitle' => 'Monitor automatic household record verification history and results.',
            'requests' => HouseholdRecordRequestUiCatalog::all(),
        ]);
    }

    public function show(string $id): View
    {
        $request = HouseholdRecordRequestUiCatalog::find($id);

        return view('pages.household-requests.view', [
            'active' => 'household-requests',
            'pageTitle' => 'Household Request Details',
            'pageSubtitle' => 'Automatic verification result for this household record access request.',
            'requestId' => $id,
            'demoRequest' => $request,
        ]);
    }
}
