<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHealthWorkerRequest;
use App\Http\Requests\Admin\UpdateHealthWorkerRequest;
use App\Services\HealthWorkerAccountService;
use App\Support\HealthWorkerUiCatalog;
use App\Support\ResidentAccountUiCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HealthWorkerAccountController extends Controller
{
    public function __construct(
        private readonly HealthWorkerAccountService $accounts,
    ) {}

    public function index(): View
    {
        $workers = HealthWorkerUiCatalog::all();
        $isResidents = request()->query('tab') === 'residents';

        return view('pages.user-management.index', [
            'active' => 'user-management',
            'pageTitle' => 'User Management',
            'pageSubtitle' => $isResidents
                ? 'Manage user accounts and access permissions.'
                : 'Manage accounts of the Barangay Health Workers',
            'healthWorkers' => $workers,
            'residentAccounts' => ResidentAccountUiCatalog::all(),
        ]);
    }

    public function create(): View
    {
        return view('pages.user-management.health-worker-create', [
            'active' => 'user-management',
            'pageTitle' => 'Create Account',
            'pageSubtitle' => 'Add a Barangay Health Worker account with a temporary password.',
        ]);
    }

    /**
     * Slim Create Account — persists authentication account + role only.
     * Does not manufacture personal/employment profile demographics.
     */
    public function store(StoreHealthWorkerRequest $request): RedirectResponse
    {
        $user = $this->accounts->createFromSlimForm($request->validated());

        return redirect()
            ->route('user-management.health-workers.view', ['id' => (string) $user->id])
            ->with('status', 'Health Worker account created successfully.');
    }

    public function edit(string $id): View
    {
        $worker = HealthWorkerUiCatalog::find($id);

        return view('pages.user-management.health-worker-edit', [
            'active' => 'user-management',
            'pageTitle' => 'Edit Account Details',
            'pageSubtitle' => "Update the selected health worker's profile and account information.",
            'workerId' => $id,
            'demoWorker' => $worker,
            'workerIsMutable' => HealthWorkerUiCatalog::findMutableUser($id) !== null,
        ]);
    }

    public function update(UpdateHealthWorkerRequest $request, string $id): RedirectResponse
    {
        $user = HealthWorkerUiCatalog::findMutableUser($id);

        if ($user === null) {
            return redirect()
                ->route('user-management.health-workers.edit', ['id' => $id])
                ->withErrors([
                    'hw_email' => 'This demo health worker cannot be updated in the database. Persistable accounts use numeric user IDs.',
                ]);
        }

        $updated = $this->accounts->update($user, $request->validated());

        return redirect()
            ->route('user-management.health-workers.view', ['id' => (string) $updated->id])
            ->with('status', 'Health Worker information updated successfully.');
    }

    public function show(string $id): View
    {
        $worker = HealthWorkerUiCatalog::find($id);

        return view('pages.user-management.health-worker-view', [
            'active' => 'user-management',
            'pageTitle' => 'View Health Worker Information',
            'pageSubtitle' => "Review the selected health worker's personal, contact, address, employment, and account information.",
            'workerId' => $id,
            'demoWorker' => $worker,
        ]);
    }
}
