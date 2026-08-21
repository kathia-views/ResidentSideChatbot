<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\StaffAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff login. Prefers database users; falls back to demo/config accounts.
 * Frozen login UI (email + password) is unchanged.
 */
class DemoLoginController extends Controller
{
    public function show(): View
    {
        return view('pages.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identity = trim((string) $request->input('email', $request->input('full_name', '')));
        $password = (string) $request->input('password', '');

        $result = StaffAuthenticator::attempt($identity, $password);

        if ($result['via'] === null) {
            return redirect()
                ->route('login')
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'Invalid email or password. Please try again.',
                ]);
        }

        if ($result['via'] === 'database' && $result['must_change_password']) {
            return redirect()->route('password.change.required');
        }

        return redirect()->route('dashboard');
    }
}
