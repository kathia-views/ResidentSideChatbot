<?php

namespace App\Http\Controllers\HouseholdProfiling;

use App\Http\Controllers\Controller;
use App\Services\HouseholdService;
use App\Support\HouseholdMemberResolver;
use Illuminate\View\View;

class HouseholdProfilingController extends Controller
{
    public function __construct(
        private readonly HouseholdService $households,
        private readonly HouseholdMemberResolver $resolver,
    ) {}

    public function index(): View
    {
        $demoHouseholds = $this->households->profilingListRows();

        return view('pages.household-profiling.index', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => 'Manage and View All Registered Household in the Barangay',
            'demoHouseholds' => $demoHouseholds,
            'demoTotal' => count($demoHouseholds),
        ]);
    }

    public function show(string $householdNo): View
    {
        $resolved = $this->resolver->resolveHousehold($householdNo);
        $key = \App\Support\DemoCatalog::normalizeHouseholdNo($householdNo);

        return view('pages.household-profiling.view', [
            'active' => 'household-profiling',
            'pageTitle' => 'Household Profiling',
            'pageSubtitle' => $resolved
                ? 'View household details and members in Barangay La Medalla.'
                : 'Household was not found.',
            'householdNo' => $key,
            'demoHousehold' => $resolved['presentation'] ?? null,
        ]);
    }
}
