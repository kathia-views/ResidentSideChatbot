<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateResidentAccountRequest;
use App\Support\ResidentAccountDeleter;
use App\Support\ResidentAccountUiCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ResidentAccountController extends Controller
{
    public function show(string $id): View|RedirectResponse
    {
        if (preg_match('/^res-\d+$/', $id) === 1) {
            return redirect()->route('household-requests.view', ['id' => $id], 301);
        }

        $resident = ResidentAccountUiCatalog::find($id);
        abort_if($resident === null, 404, 'Resident account not found.');

        return view('pages.user-management.residents.view', [
            'active' => 'user-management',
            'pageTitle' => 'Resident Information',
            'pageSubtitle' => 'Manage user accounts and access permissions.',
            'residentId' => $id,
            'demoResident' => $resident,
        ]);
    }

    public function edit(string $id): View
    {
        $resident = ResidentAccountUiCatalog::find($id);
        abort_if($resident === null, 404, 'Resident account not found.');

        return view('pages.user-management.residents.edit', [
            'active' => 'user-management',
            'pageTitle' => 'Edit Resident Information',
            'pageSubtitle' => 'Manage user accounts and access permissions.',
            'residentId' => $id,
            'demoResident' => $resident,
        ]);
    }

    public function update(UpdateResidentAccountRequest $request, string $id): RedirectResponse
    {
        $account = ResidentAccountUiCatalog::findModel($id);
        abort_if($account === null, 404, 'Resident account not found.');

        $validated = $request->validated();

        $account->fill([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'zone_purok' => ResidentAccountUiCatalog::persistZone($validated['zone']),
            'email' => $validated['email'],
        ]);
        $account->save();

        return redirect()
            ->route('user-management.residents.view', ['id' => $id])
            ->with('status', 'Resident account updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $account = ResidentAccountUiCatalog::findModel($id);
        abort_if($account === null, 404, 'Resident account not found.');

        app(ResidentAccountDeleter::class)->delete($account);

        return redirect()
            ->route('user-management.index', ['tab' => 'residents'])
            ->with('status', 'Resident account deleted successfully.');
    }
}
